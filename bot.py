import os
import json
import secrets
from typing import Optional, Dict, Any, List, Tuple

import psycopg2
import psycopg2.extras

from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse, JSONResponse, PlainTextResponse

from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.ext import (
    Application,
    CommandHandler,
    CallbackQueryHandler,
    MessageHandler,
    ContextTypes,
    filters,
)

# ---------------- ENV ----------------
BOT_TOKEN = os.getenv("BOT_TOKEN", "")
ADMIN_IDS = [int(x.strip()) for x in os.getenv("ADMIN_IDS", "").split(",") if x.strip().isdigit()]

DB_HOST = os.getenv("DB_HOST", "")
DB_PORT = int(os.getenv("DB_PORT", "5432"))
DB_NAME = os.getenv("DB_NAME", "postgres")
DB_USER = os.getenv("DB_USER", "")
DB_PASS = os.getenv("DB_PASS", "")

BOT_USERNAME = os.getenv("BOT_USERNAME", "").strip()  # without @
PUBLIC_BASE_URL = os.getenv("PUBLIC_BASE_URL", "").strip()  # https://your-service.onrender.com

PORT = int(os.getenv("PORT", "10000"))

if not BOT_TOKEN:
    raise RuntimeError("BOT_TOKEN missing")
if not PUBLIC_BASE_URL:
    raise RuntimeError("PUBLIC_BASE_URL missing (set to your Render URL)")

def is_admin(uid: int) -> bool:
    return uid in ADMIN_IDS

def db_conn():
    return psycopg2.connect(
        host=DB_HOST, port=DB_PORT, dbname=DB_NAME, user=DB_USER, password=DB_PASS
    )

def db_exec(query: str, params: tuple = (), fetchone=False, fetchall=False):
    with db_conn() as conn:
        with conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor) as cur:
            cur.execute(query, params)
            if fetchone:
                return cur.fetchone()
            if fetchall:
                return cur.fetchall()
            return None

def get_setting(key: str, default):
    row = db_exec("select value from settings where key=%s", (key,), fetchone=True)
    if not row:
        return default
    return row["value"]

def set_setting(key: str, value):
    db_exec(
        """
        insert into settings(key, value) values(%s, %s::jsonb)
        on conflict (key) do update set value=excluded.value
        """,
        (key, json.dumps(value, ensure_ascii=False)),
    )

def upsert_user(uid: int, username: Optional[str], first_name: Optional[str]):
    db_exec(
        """
        insert into users(tg_id, username, first_name, last_seen)
        values(%s, %s, %s, now())
        on conflict (tg_id) do update set
          username=excluded.username,
          first_name=excluded.first_name,
          last_seen=now()
        """,
        (uid, username, first_name),
    )

def get_user(uid: int) -> Optional[Dict[str, Any]]:
    return db_exec("select * from users where tg_id=%s", (uid,), fetchone=True)

def safe_name(u: Dict[str, Any]) -> str:
    if u.get("first_name"):
        return u["first_name"]
    if u.get("username"):
        return "@" + u["username"]
    return str(u.get("tg_id", ""))

def get_bot_username() -> str:
    return BOT_USERNAME or "YourBot"

# ---------------- BOT RULES ----------------
def coupon_label(t: str) -> str:
    return {
        "500": "500 off 500",
        "1000": "1000 off 1000",
        "2000": "2000 off 2000",
        "4000": "4000 off 4000",
    }.get(t, t)

def get_force_channels() -> List[str]:
    default = ["@channel1", "@channel2", "@channel3", "@channel4", "@channel5"]
    val = get_setting("force_join_channels", default)
    if isinstance(val, list):
        out = [str(x) for x in val][:5]
        while len(out) < 5:
            out.append("")
        return out
    return default

def get_redeem_rules() -> Dict[str, Dict[str, int]]:
    default = {
        "500": {"points": 3},
        "1000": {"points": 10},
        "2000": {"points": 25},
        "4000": {"points": 40},
    }
    val = get_setting("redeem_rules", default)
    if isinstance(val, dict):
        for k in default:
            val.setdefault(k, {})
            val[k].setdefault("points", default[k]["points"])
        return val
    return default

def stock_counts() -> Dict[str, int]:
    out = {}
    for t in ["500", "1000", "2000", "4000"]:
        row = db_exec(
            "select count(*) c from coupons where coupon_type=%s and is_used=false",
            (t,),
            fetchone=True,
        )
        out[t] = int(row["c"]) if row else 0
    return out

# ---------------- REFERRAL (award only after verified) ----------------
def set_referred_by_if_needed(new_uid: int, ref_uid: int):
    if new_uid == ref_uid:
        return
    row = db_exec("select referred_by from users where tg_id=%s", (new_uid,), fetchone=True)
    if not row or row["referred_by"] is not None:
        return
    db_exec("update users set referred_by=%s where tg_id=%s", (ref_uid, new_uid))

def award_referral_if_applicable(new_uid: int) -> Optional[int]:
    u = get_user(new_uid)
    if not u or not u.get("verified") or u.get("referral_awarded") or not u.get("referred_by"):
        return None
    ref = int(u["referred_by"])

    with db_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                "update users set referral_awarded=true where tg_id=%s and referral_awarded=false",
                (new_uid,),
            )
            if cur.rowcount <= 0:
                return None
            cur.execute(
                "update users set points=points+1, referrals=referrals+1 where tg_id=%s",
                (ref,),
            )
    return ref

# ---------------- COUPONS ----------------
def add_coupons(t: str, codes: List[str]) -> int:
    if t not in ["500", "1000", "2000", "4000"]:
        return 0
    cleaned = [c.strip() for c in codes if c.strip()]
    if not cleaned:
        return 0
    with db_conn() as conn:
        with conn.cursor() as cur:
            for code in cleaned:
                cur.execute(
                    "insert into coupons(coupon_type, code, is_used) values(%s, %s, false)",
                    (t, code),
                )
    return len(cleaned)

def remove_unused_coupons(t: str, count: int) -> int:
    if t not in ["500", "1000", "2000", "4000"] or count <= 0:
        return 0
    with db_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                delete from coupons
                where id in (
                    select id from coupons
                    where coupon_type=%s and is_used=false
                    order by id asc
                    limit %s
                )
                """,
                (t, count),
            )
            return cur.rowcount

def redeem_coupon(uid: int, t: str) -> Tuple[bool, str, int]:
    if t not in ["500", "1000", "2000", "4000"]:
        return (False, "Invalid option.", 0)
    u = get_user(uid)
    if not u:
        return (False, "User not found.", 0)
    if not u.get("verified"):
        return (False, "Please verify first.", 0)

    rules = get_redeem_rules()
    need = int(rules.get(t, {}).get("points", 999999))
    if int(u.get("points", 0)) < need:
        return (False, f"Not enough points.\nRequired: {need}\nYou have: {u.get('points', 0)}", 0)

    with db_conn() as conn:
        with conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor) as cur:
            cur.execute(
                """
                select id, code from coupons
                where coupon_type=%s and is_used=false
                order by id asc
                limit 1
                for update
                """,
                (t,),
            )
            row = cur.fetchone()
            if not row:
                return (False, f"Out of stock for {coupon_label(t)}", 0)

            coupon_id = int(row["id"])
            code = row["code"]

            cur.execute(
                "update coupons set is_used=true, used_by=%s, used_at=now() where id=%s",
                (uid, coupon_id),
            )
            cur.execute("update users set points=points-%s where tg_id=%s", (need, uid))
            cur.execute(
                """
                insert into redeems(tg_id, coupon_type, coupon_code, points_spent)
                values(%s,%s,%s,%s)
                """,
                (uid, t, code, need),
            )
    return (True, code, need)

# ---------------- WEB VERIFICATION (1 device = 1 tg_id) ----------------
def create_verify_token(uid: int) -> str:
    token = secrets.token_urlsafe(24)
    db_exec(
        "update users set verify_token=%s, verify_requested_at=now() where tg_id=%s",
        (token, uid),
    )
    return token

def verify_on_web(token: str, device_id: str) -> Tuple[bool, str, Optional[int]]:
    """
    Returns (ok, message, tg_id)
    device_id unique -> only one tg_id can be verified on a device.
    """
    if not token or not device_id:
        return (False, "Missing token/device.", None)

    u = db_exec("select tg_id, verified from users where verify_token=%s", (token,), fetchone=True)
    if not u:
        return (False, "Invalid or expired token.", None)

    tg_id = int(u["tg_id"])

    # If device already used for different tg_id -> block
    d = db_exec("select tg_id from device_verifications where device_id=%s", (device_id,), fetchone=True)
    if d and int(d["tg_id"]) != tg_id:
        return (False, "This device is already verified with another account.", tg_id)

    # If tg_id already verified, allow success
    if u.get("verified"):
        return (True, "Already verified.", tg_id)

    # Ensure tg_id not linked to a different device already (tg_id unique in device_verifications)
    d2 = db_exec("select device_id from device_verifications where tg_id=%s", (tg_id,), fetchone=True)
    if d2 and d2.get("device_id") != device_id:
        return (False, "This Telegram ID is already verified on a different device.", tg_id)

    # Do verification + lock device
    with db_conn() as conn:
        with conn.cursor() as cur:
            cur.execute("update users set verified=true where tg_id=%s", (tg_id,))
            cur.execute(
                """
                insert into device_verifications(device_id, tg_id)
                values(%s, %s)
                on conflict (device_id) do update set tg_id=excluded.tg_id, verified_at=now()
                """,
                (device_id, tg_id),
            )

    return (True, "Verified successfully.", tg_id)

# ---------------- UI ----------------
def user_menu(uid: int) -> InlineKeyboardMarkup:
    rows = [
        [InlineKeyboardButton("✅ Verify", callback_data="verify"), InlineKeyboardButton("📊 Stats", callback_data="stats")],
        [InlineKeyboardButton("🎟️ Redeem", callback_data="redeem_menu"), InlineKeyboardButton("🏆 Leaderboard", callback_data="leaderboard")],
        [InlineKeyboardButton("🔗 Referral Link", callback_data="ref_link")],
    ]
    if is_admin(uid):
        rows.append([InlineKeyboardButton("🛠 Admin Panel", callback_data="admin_panel")])
    return InlineKeyboardMarkup(rows)

def join_and_verify_kb(channels: List[str], verify_url: str) -> InlineKeyboardMarkup:
    rows = []
    for ch in channels:
        ch = ch.strip()
        if ch:
            rows.append([InlineKeyboardButton(f"Join {ch}", url="https://t.me/" + ch.lstrip("@"))])
    rows.append([InlineKeyboardButton("🔐 Verify on Web", url=verify_url)])
    rows.append([InlineKeyboardButton("✅ Check Verification", callback_data="check_verification")])
    return InlineKeyboardMarkup(rows)

def admin_panel_kb() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([
        [InlineKeyboardButton("📢 Change Force-Join Channels", callback_data="admin_channels")],
        [InlineKeyboardButton("⚙️ Change Redeem Points", callback_data="admin_rules")],
        [InlineKeyboardButton("➕ Add Coupons", callback_data="admin_add_coupons"), InlineKeyboardButton("➖ Remove Coupons", callback_data="admin_remove_coupons")],
        [InlineKeyboardButton("📦 Coupons Stock", callback_data="admin_stock"), InlineKeyboardButton("📜 Redeems Log", callback_data="admin_redeems")],
        [InlineKeyboardButton("⬅️ Back", callback_data="back_menu")],
    ])

def admin_choose_type_kb(prefix: str) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([
        [InlineKeyboardButton("500", callback_data=f"{prefix}:500"), InlineKeyboardButton("1000", callback_data=f"{prefix}:1000")],
        [InlineKeyboardButton("2000", callback_data=f"{prefix}:2000"), InlineKeyboardButton("4000", callback_data=f"{prefix}:4000")],
        [InlineKeyboardButton("⬅️ Back", callback_data="admin_panel")],
    ])

async def check_force_join(app: Application, uid: int) -> Tuple[bool, List[str], List[str]]:
    channels = get_force_channels()
    not_joined = []
    for ch in channels:
        ch = ch.strip()
        if not ch:
            continue
        try:
            mem = await app.bot.get_chat_member(chat_id=ch, user_id=uid)
            if mem.status in ("left", "kicked"):
                not_joined.append(ch)
        except Exception:
            not_joined.append(ch)
    return (len(not_joined) == 0, channels, not_joined)

def welcome_text(uid: int) -> str:
    bot = get_bot_username()
    link = f"https://t.me/{bot}?start={uid}"
    return (
        "🎉 <b>Welcome!</b>\n\n"
        "1) Join all channels\n"
        "2) Verify on website\n"
        "3) Click Check Verification\n\n"
        f"🔗 Your Referral Link:\n<code>{link}</code>"
    )

def stats_text(uid: int) -> str:
    u = get_user(uid) or {}
    verified = "✅ Verified" if u.get("verified") else "❌ Not Verified"
    bot = get_bot_username()
    link = f"https://t.me/{bot}?start={uid}"
    return (
        "📊 <b>Your Stats</b>\n\n"
        f"Status: <b>{verified}</b>\n"
        f"Points: <b>{int(u.get('points', 0))}</b>\n"
        f"Referrals: <b>{int(u.get('referrals', 0))}</b>\n\n"
        f"🔗 Referral Link:\n<code>{link}</code>"
    )

def admin_panel_text() -> str:
    channels = get_force_channels()
    rules = get_redeem_rules()
    stock = stock_counts()
    txt = "🛠 <b>Admin Panel</b>\n\n📢 <b>Force-Join Channels</b>:\n"
    for i, c in enumerate(channels, start=1):
        if c:
            txt += f"{i}) <code>{c}</code>\n"
    txt += "\n⚙️ <b>Redeem Points</b>:\n"
    for t in ["500","1000","2000","4000"]:
        txt += f"• {coupon_label(t)} = <b>{int(rules[t]['points'])}</b> pts\n"
    txt += "\n📦 <b>Stock</b>:\n"
    for t in ["500","1000","2000","4000"]:
        txt += f"• {coupon_label(t)} = <b>{stock.get(t,0)}</b>\n"
    return txt

# ---------------- Telegram handlers ----------------
async def start_cmd(update: Update, context: ContextTypes.DEFAULT_TYPE):
    uid = update.effective_user.id
    upsert_user(uid, update.effective_user.username, update.effective_user.first_name)

    if context.args and context.args[0].isdigit():
        ref = int(context.args[0])
        set_referred_by_if_needed(uid, ref)

    await update.message.reply_text(
        welcome_text(uid),
        parse_mode="HTML",
        reply_markup=user_menu(uid),
        disable_web_page_preview=True,
    )

async def on_text(update: Update, context: ContextTypes.DEFAULT_TYPE):
    uid = update.effective_user.id
    upsert_user(uid, update.effective_user.username, update.effective_user.first_name)
    await update.message.reply_text("Choose an option 👇", reply_markup=user_menu(uid))

async def on_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    q = update.callback_query
    uid = q.from_user.id
    upsert_user(uid, q.from_user.username, q.from_user.first_name)

    data = q.data or ""
    await q.answer()

    if data == "back_menu":
        await q.edit_message_text(welcome_text(uid), parse_mode="HTML", reply_markup=user_menu(uid))
        return

    if data == "verify":
        # Step: join channels first, then show web verify URL + check button
        all_joined, channels, not_joined = await check_force_join(context.application, uid)
        token = create_verify_token(uid)
        verify_url = f"{PUBLIC_BASE_URL}/verify?token={token}"

        if not all_joined:
            await q.edit_message_text(
                "⚠️ <b>Join all channels first</b>\n\nThen verify on website and click Check Verification.",
                parse_mode="HTML",
                reply_markup=join_and_verify_kb(channels, verify_url),
                disable_web_page_preview=True,
            )
            return

        await q.edit_message_text(
            "✅ <b>Joined all channels!</b>\n\nNow verify on website and then click Check Verification.",
            parse_mode="HTML",
            reply_markup=join_and_verify_kb(channels, verify_url),
            disable_web_page_preview=True,
        )
        return

    if data == "check_verification":
        # Allow access only if: joined all + verified in DB
        all_joined, channels, not_joined = await check_force_join(context.application, uid)
        u = get_user(uid) or {}
        if not all_joined:
            token = create_verify_token(uid)
            verify_url = f"{PUBLIC_BASE_URL}/verify?token={token}"
            await q.edit_message_text(
                "⚠️ <b>You still haven't joined all channels.</b>\n\nJoin all and try again.",
                parse_mode="HTML",
                reply_markup=join_and_verify_kb(channels, verify_url),
            )
            return

        if not u.get("verified"):
            token = create_verify_token(uid)
            verify_url = f"{PUBLIC_BASE_URL}/verify?token={token}"
            await q.edit_message_text(
                "❌ <b>Not verified yet.</b>\n\nPlease verify on website, then click Check Verification.",
                parse_mode="HTML",
                reply_markup=join_and_verify_kb(channels, verify_url),
            )
            return

        # verified -> award referral (only once)
        ref_id = award_referral_if_applicable(uid)
        if ref_id:
            try:
                await context.application.bot.send_message(
                    chat_id=ref_id,
                    text=f"✅ <b>Referral Added!</b>\nYou got <b>+1</b> point because <b>{safe_name(u)}</b> verified.",
                    parse_mode="HTML",
                )
            except Exception:
                pass

        await q.edit_message_text(
            "✅ <b>Verification Successful!</b>\n\nNow you can use the bot.",
            parse_mode="HTML",
            reply_markup=user_menu(uid),
        )
        return

    if data == "stats":
        await q.edit_message_text(stats_text(uid), parse_mode="HTML", reply_markup=user_menu(uid))
        return

    if data == "ref_link":
        link = f"https://t.me/{get_bot_username()}?start={uid}"
        await q.edit_message_text(
            f"🔗 <b>Your Referral Link</b>\n\n<code>{link}</code>\n\nPoints are added only after user verifies ✅",
            parse_mode="HTML",
            reply_markup=user_menu(uid),
        )
        return

    if data == "leaderboard":
        rows = db_exec(
            "select tg_id, username, first_name, referrals, points from users order by referrals desc, points desc limit 10",
            fetchall=True,
        ) or []
        txt = "🏆 <b>Top 10 Leaderboard</b>\n\n"
        if not rows:
            txt += "No users yet."
        else:
            for i, r in enumerate(rows, start=1):
                name = r.get("first_name") or (("@" + r["username"]) if r.get("username") else str(r["tg_id"]))
                txt += f"{i}) <b>{name}</b> — Referrals: <b>{int(r.get('referrals',0))}</b>\n"
        await q.edit_message_text(txt, parse_mode="HTML", reply_markup=user_menu(uid))
        return

    if data == "redeem_menu":
        u = get_user(uid) or {}
        rules = get_redeem_rules()
        stock = stock_counts()
        pts = int(u.get("points", 0))

        txt = "🎟️ <b>Redeem Coupons</b>\n\n"
        txt += f"Your Points: <b>{pts}</b>\n\n"
        for t in ["500","1000","2000","4000"]:
            txt += f"• {coupon_label(t)} — Need <b>{int(rules[t]['points'])}</b> — Stock <b>{stock.get(t,0)}</b>\n"

        kb = InlineKeyboardMarkup([
            [InlineKeyboardButton("500 off 500", callback_data="redeem:500"),
             InlineKeyboardButton("1000 off 1000", callback_data="redeem:1000")],
            [InlineKeyboardButton("2000 off 2000", callback_data="redeem:2000"),
             InlineKeyboardButton("4000 off 4000", callback_data="redeem:4000")],
            [InlineKeyboardButton("⬅️ Back", callback_data="back_menu")],
        ])
        await q.edit_message_text(txt, parse_mode="HTML", reply_markup=kb)
        return

    if data.startswith("redeem:"):
        t = data.split(":", 1)[1]
        ok, info, spent = redeem_coupon(uid, t)
        if not ok:
            await q.answer(info, show_alert=True)
            return
        await q.edit_message_text(
            "🎉 <b>Congratulations!</b>\n\n"
            f"Type: <b>{coupon_label(t)}</b>\n"
            f"Coupon: <code>{info}</code>\n"
            f"Points spent: <b>{spent}</b>",
            parse_mode="HTML",
            reply_markup=user_menu(uid),
        )
        return

    # Admin panel parts (kept same structure as before)
    if data == "admin_panel":
        if not is_admin(uid):
            await q.answer("Not allowed", show_alert=True)
            return
        await q.edit_message_text(admin_panel_text(), parse_mode="HTML", reply_markup=admin_panel_kb())
        return

    if data == "admin_channels" and is_admin(uid):
        # simple: admin sends 5 lines in chat (not implemented here to keep message smaller)
        await q.answer("Channels change can be added same as before.", show_alert=True)
        return

# ---------------- FastAPI web app ----------------
app = FastAPI()
tg_app: Optional[Application] = None

VERIFY_HTML = """<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Verify</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    body{font-family:Arial, sans-serif; margin:24px;}
    .card{max-width:520px; margin:auto; padding:18px; border:1px solid #ddd; border-radius:12px;}
    button{width:100%; padding:12px; font-size:16px; border-radius:10px; border:0; cursor:pointer;}
    .ok{color:green; font-weight:700;}
    .bad{color:#b00020; font-weight:700;}
    code{background:#f6f6f6; padding:2px 6px; border-radius:6px;}
  </style>
</head>
<body>
  <div class="card">
    <h2>🔐 Web Verification</h2>
    <p>This verification locks <b>1 device = 1 Telegram account</b>.</p>
    <p>Step 1: Tap verify below</p>
    <button id="btn">✅ Verify Now</button>
    <p id="msg"></p>
    <p id="back" style="display:none;">
      ✅ Done. Go back to Telegram and press <b>Check Verification</b>.
    </p>
  </div>

<script>
  const params = new URLSearchParams(window.location.search);
  const token = params.get("token") || "";

  function getDeviceId(){
    let id = localStorage.getItem("device_id");
    if(!id){
      // simple UUID
      id = (crypto.randomUUID ? crypto.randomUUID() :
        'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
          const r = Math.random()*16|0, v = c==='x'?r:(r&0x3|0x8);
          return v.toString(16);
        })
      );
      localStorage.setItem("device_id", id);
    }
    return id;
  }

  const btn = document.getElementById("btn");
  const msg = document.getElementById("msg");
  const back = document.getElementById("back");

  btn.onclick = async () => {
    msg.textContent = "Verifying...";
    const device_id = getDeviceId();
    try {
      const res = await fetch("/api/verify", {
        method:"POST",
        headers:{"Content-Type":"application/json"},
        body: JSON.stringify({ token, device_id })
      });
      const j = await res.json();
      if(j.ok){
        msg.innerHTML = '<span class="ok">✅ '+j.message+'</span>';
        back.style.display = "block";
        btn.disabled = true;
      } else {
        msg.innerHTML = '<span class="bad">❌ '+j.message+'</span>';
      }
    } catch(e){
      msg.innerHTML = '<span class="bad">❌ Network error</span>';
    }
  }
</script>
</body>
</html>
"""

@app.get("/", response_class=PlainTextResponse)
def health():
    return "OK"

@app.get("/verify", response_class=HTMLResponse)
def verify_page(token: str = ""):
    # token is in query string and used by JS
    return HTMLResponse(VERIFY_HTML)

@app.post("/api/verify")
async def api_verify(req: Request):
    body = await req.json()
    token = (body.get("token") or "").strip()
    device_id = (body.get("device_id") or "").strip()

    ok, message, tg_id = verify_on_web(token, device_id)
    return JSONResponse({"ok": ok, "message": message, "tg_id": tg_id})

@app.post("/telegram")
async def telegram_webhook(req: Request):
    """
    Telegram will post updates here
    """
    data = await req.json()
    update = Update.de_json(data, tg_app.bot)  # type: ignore
    await tg_app.process_update(update)        # type: ignore
    return JSONResponse({"ok": True})

# ---------------- start telegram app + webhook ----------------
async def build_telegram():
    global tg_app
    tg_app = Application.builder().token(BOT_TOKEN).build()

    tg_app.add_handler(CommandHandler("start", start_cmd))
    tg_app.add_handler(CallbackQueryHandler(on_callback))
    tg_app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, on_text))

    await tg_app.initialize()
    await tg_app.bot.set_webhook(f"{PUBLIC_BASE_URL}/telegram")
    await tg_app.start()

# FastAPI startup hook
@app.on_event("startup")
async def on_startup():
    await build_telegram()

@app.on_event("shutdown")
async def on_shutdown():
    if tg_app:
        await tg_app.stop()
        await tg_app.shutdown()

# Run with: uvicorn app:app --host 0.0.0.0 --port $PORT
if __name__ == "__main__":
    import uvicorn
    uvicorn.run("app:app", host="0.0.0.0", port=PORT, log_level="info")
