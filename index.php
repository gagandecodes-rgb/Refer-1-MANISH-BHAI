<?php
// ============================================================
// FULL SINGLE-FILE Telegram Referral Bot (PHP) + Web Verification
// FEATURES:
// - 5 force-join channels (editable in Admin Panel)
// - Web verification (1 device = 1 Telegram ID) + Check Verification button
// - Referral points granted ONLY after referred user is verified
// - User stats, referral link, leaderboard top 10 (names)
// - Coupon system (500/1000/2000/4000): add/remove/stock/redeems log
// - Admin can change required points per coupon type (deducts exact points)
// ============================================================

header("Content-Type: text/plain; charset=utf-8");

// ---------------- ENV ----------------
$BOT_TOKEN      = trim(getenv("BOT_TOKEN") ?: "");
$ADMIN_IDS_RAW  = trim(getenv("ADMIN_IDS") ?: "");
$DB_HOST        = trim(getenv("DB_HOST") ?: "");
$DB_PORT        = trim(getenv("DB_PORT") ?: "5432");
$DB_NAME        = trim(getenv("DB_NAME") ?: "postgres");
$DB_USER        = trim(getenv("DB_USER") ?: "");
$DB_PASS        = trim(getenv("DB_PASS") ?: "");
$PUBLIC_BASE_URL= rtrim(trim(getenv("PUBLIC_BASE_URL") ?: ""), "/");
$BOT_USERNAME   = trim(getenv("BOT_USERNAME") ?: "YourBot"); // without @

$ADMIN_IDS = [];
foreach (explode(",", $ADMIN_IDS_RAW) as $x) {
  $x = trim($x);
  if ($x !== "" && ctype_digit($x)) $ADMIN_IDS[] = (int)$x;
}

if ($BOT_TOKEN === "" || $PUBLIC_BASE_URL === "") {
  http_response_code(500);
  echo "Missing BOT_TOKEN or PUBLIC_BASE_URL";
  exit;
}

// ---------------- DB ----------------
try {
  $pdo = new PDO(
    "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME}",
    $DB_USER,
    $DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
} catch (Exception $e) {
  http_response_code(500);
  echo "DB connection failed";
  exit;
}

function is_admin($uid) {
  global $ADMIN_IDS;
  return in_array((int)$uid, $ADMIN_IDS, true);
}

// ---------------- Utils ----------------
function jencode($x) { return json_encode($x, JSON_UNESCAPED_UNICODE); }

function tg($method, $data = []) {
  global $BOT_TOKEN;
  $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $data,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15,
  ]);
  $res = curl_exec($ch);
  curl_close($ch);
  $j = json_decode($res, true);
  return $j ?: ["ok"=>false,"raw"=>$res];
}

function sendMessage($chat_id, $text, $reply_markup = null) {
  $data = [
    "chat_id" => $chat_id,
    "text" => $text,
    "parse_mode" => "HTML",
    "disable_web_page_preview" => true
  ];
  if ($reply_markup) $data["reply_markup"] = jencode($reply_markup);
  return tg("sendMessage", $data);
}

function editMessage($chat_id, $message_id, $text, $reply_markup = null) {
  $data = [
    "chat_id" => $chat_id,
    "message_id" => $message_id,
    "text" => $text,
    "parse_mode" => "HTML",
    "disable_web_page_preview" => true
  ];
  if ($reply_markup) $data["reply_markup"] = jencode($reply_markup);
  return tg("editMessageText", $data);
}

function answerCb($cb_id, $text = "", $alert = false) {
  $data = [
    "callback_query_id" => $cb_id,
    "text" => $text,
    "show_alert" => $alert ? "true" : "false"
  ];
  return tg("answerCallbackQuery", $data);
}

function safe_name($u) {
  if (!empty($u["first_name"])) return htmlspecialchars($u["first_name"]);
  if (!empty($u["username"])) return "@".htmlspecialchars($u["username"]);
  return (string)($u["tg_id"] ?? "");
}

// ---------------- Settings helpers ----------------
function get_setting($key, $default) {
  global $pdo;
  $st = $pdo->prepare("select value from settings where key=?");
  $st->execute([$key]);
  $row = $st->fetch();
  if (!$row) return $default;
  return $row["value"];
}
function set_setting($key, $value) {
  global $pdo;
  $st = $pdo->prepare("
    insert into settings(key,value) values(?, ?::jsonb)
    on conflict (key) do update set value=excluded.value
  ");
  $st->execute([$key, jencode($value)]);
}

function get_force_channels() {
  $default = ["@channel1","@channel2","@channel3","@channel4","@channel5"];
  $val = get_setting("force_join_channels", $default);
  if (is_array($val)) {
    $out = array_slice(array_map("strval",$val), 0, 5);
    while (count($out) < 5) $out[] = "";
    return $out;
  }
  return $default;
}

function get_redeem_rules() {
  $default = [
    "500" => ["points"=>3],
    "1000" => ["points"=>10],
    "2000" => ["points"=>25],
    "4000" => ["points"=>40],
  ];
  $val = get_setting("redeem_rules", $default);
  if (is_array($val)) {
    foreach ($default as $k=>$v) {
      if (!isset($val[$k])) $val[$k] = $v;
      if (!isset($val[$k]["points"])) $val[$k]["points"] = $v["points"];
    }
    return $val;
  }
  return $default;
}

// ---------------- DB user helpers ----------------
function upsert_user($uid, $username, $first_name) {
  global $pdo;
  $st = $pdo->prepare("
    insert into users(tg_id, username, first_name, last_seen)
    values(?, ?, ?, now())
    on conflict (tg_id) do update set
      username=excluded.username,
      first_name=excluded.first_name,
      last_seen=now()
  ");
  $st->execute([(int)$uid, $username, $first_name]);
}

function get_user($uid) {
  global $pdo;
  $st = $pdo->prepare("select * from users where tg_id=?");
  $st->execute([(int)$uid]);
  return $st->fetch();
}

function set_state($uid, $state, $dataArr = null) {
  global $pdo;
  $st = $pdo->prepare("update users set state=?, state_data=?::jsonb where tg_id=?");
  $st->execute([$state, $dataArr ? jencode($dataArr) : null, (int)$uid]);
}

function clear_state($uid) { set_state($uid, null, null); }

function ensure_referred_by($new_uid, $ref_uid) {
  global $pdo;
  if ((int)$new_uid === (int)$ref_uid) return;
  $st = $pdo->prepare("select referred_by from users where tg_id=?");
  $st->execute([(int)$new_uid]);
  $row = $st->fetch();
  if (!$row) return;
  if ($row["referred_by"] !== null) return;
  $st2 = $pdo->prepare("update users set referred_by=? where tg_id=?");
  $st2->execute([(int)$ref_uid, (int)$new_uid]);
}

function award_referral_if_needed($new_uid) {
  // Award +1 point to referrer only once, only after verified
  global $pdo;
  $u = get_user($new_uid);
  if (!$u) return null;
  if (!$u["verified"]) return null;
  if ($u["referral_awarded"]) return null;
  if ($u["referred_by"] === null) return null;

  $ref = (int)$u["referred_by"];
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("update users set referral_awarded=true where tg_id=? and referral_awarded=false");
    $st->execute([(int)$new_uid]);
    if ($st->rowCount() <= 0) { $pdo->rollBack(); return null; }

    $st2 = $pdo->prepare("update users set points=points+1, referrals=referrals+1 where tg_id=?");
    $st2->execute([$ref]);
    $pdo->commit();
    return $ref;
  } catch (Exception $e) {
    $pdo->rollBack();
    return null;
  }
}

// ---------------- Coupons ----------------
function coupon_label($t) {
  $map = [
    "500"=>"500 off 500",
    "1000"=>"1000 off 1000",
    "2000"=>"2000 off 2000",
    "4000"=>"4000 off 4000",
  ];
  return $map[$t] ?? $t;
}

function stock_counts() {
  global $pdo;
  $out = [];
  foreach (["500","1000","2000","4000"] as $t) {
    $st = $pdo->prepare("select count(*) as c from coupons where coupon_type=? and is_used=false");
    $st->execute([$t]);
    $row = $st->fetch();
    $out[$t] = (int)($row["c"] ?? 0);
  }
  return $out;
}

function add_coupons($type, $codes) {
  global $pdo;
  if (!in_array($type, ["500","1000","2000","4000"], true)) return 0;
  $n = 0;
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("insert into coupons(coupon_type, code, is_used) values(?, ?, false)");
    foreach ($codes as $c) {
      $c = trim($c);
      if ($c === "") continue;
      $st->execute([$type, $c]);
      $n++;
    }
    $pdo->commit();
    return $n;
  } catch (Exception $e) {
    $pdo->rollBack();
    return 0;
  }
}

function remove_unused_coupons($type, $count) {
  global $pdo;
  if (!in_array($type, ["500","1000","2000","4000"], true)) return 0;
  $count = max(1, (int)$count);
  $st = $pdo->prepare("
    delete from coupons
    where id in (
      select id from coupons
      where coupon_type=? and is_used=false
      order by id asc
      limit ?
    )
  ");
  $st->execute([$type, $count]);
  return $st->rowCount();
}

function redeem_coupon($uid, $type) {
  global $pdo;
  if (!in_array($type, ["500","1000","2000","4000"], true)) return [false, "Invalid option", 0];

  $u = get_user($uid);
  if (!$u) return [false, "User not found", 0];
  if (!$u["verified"]) return [false, "Please verify first", 0];

  $rules = get_redeem_rules();
  $need = (int)($rules[$type]["points"] ?? 999999);

  if ((int)$u["points"] < $need) {
    return [false, "Not enough points.\nRequired: {$need}\nYou have: {$u["points"]}", 0];
  }

  $pdo->beginTransaction();
  try {
    // lock a coupon row
    $st = $pdo->prepare("
      select id, code from coupons
      where coupon_type=? and is_used=false
      order by id asc
      limit 1
      for update
    ");
    $st->execute([$type]);
    $row = $st->fetch();
    if (!$row) { $pdo->rollBack(); return [false, "Out of stock for ".coupon_label($type), 0]; }

    $coupon_id = (int)$row["id"];
    $code = $row["code"];

    $st2 = $pdo->prepare("update coupons set is_used=true, used_by=?, used_at=now() where id=?");
    $st2->execute([(int)$uid, $coupon_id]);

    // deduct EXACT points
    $st3 = $pdo->prepare("update users set points=points-? where tg_id=?");
    $st3->execute([$need, (int)$uid]);

    $st4 = $pdo->prepare("insert into redeems(tg_id, coupon_type, coupon_code, points_spent) values(?,?,?,?)");
    $st4->execute([(int)$uid, $type, $code, $need]);

    $pdo->commit();
    return [true, $code, $need];
  } catch (Exception $e) {
    $pdo->rollBack();
    return [false, "Redeem failed", 0];
  }
}

// ---------------- Web verification ----------------
function create_verify_token($uid) {
  global $pdo;
  $token = bin2hex(random_bytes(16));
  $st = $pdo->prepare("update users set verify_token=? where tg_id=?");
  $st->execute([$token, (int)$uid]);
  return $token;
}

function verify_on_web($token, $device_id) {
  global $pdo;
  $token = trim($token);
  $device_id = trim($device_id);
  if ($token === "" || $device_id === "") return [false, "Missing token/device", null];

  $st = $pdo->prepare("select tg_id, verified from users where verify_token=?");
  $st->execute([$token]);
  $u = $st->fetch();
  if (!$u) return [false, "Invalid token", null];

  $tg_id = (int)$u["tg_id"];

  // device already used by different tg_id?
  $d = $pdo->prepare("select tg_id from device_verifications where device_id=?");
  $d->execute([$device_id]);
  $row = $d->fetch();
  if ($row && (int)$row["tg_id"] !== $tg_id) {
    return [false, "This device is already verified with another account.", $tg_id];
  }

  // tg_id already verified on different device?
  $d2 = $pdo->prepare("select device_id from device_verifications where tg_id=?");
  $d2->execute([$tg_id]);
  $row2 = $d2->fetch();
  if ($row2 && (string)$row2["device_id"] !== $device_id) {
    return [false, "This Telegram ID is already verified on a different device.", $tg_id];
  }

  $pdo->beginTransaction();
  try {
    $st1 = $pdo->prepare("update users set verified=true where tg_id=?");
    $st1->execute([$tg_id]);

    $st2 = $pdo->prepare("
      insert into device_verifications(device_id, tg_id)
      values(?, ?)
      on conflict (device_id) do update set tg_id=excluded.tg_id, verified_at=now()
    ");
    $st2->execute([$device_id, $tg_id]);

    $pdo->commit();
    return [true, "Verified successfully. Go back to Telegram and press Check Verification.", $tg_id];
  } catch (Exception $e) {
    $pdo->rollBack();
    return [false, "Verification failed", $tg_id];
  }
}

// ---------------- Force join check ----------------
function check_force_join($uid) {
  $channels = get_force_channels();
  $not_joined = [];
  foreach ($channels as $ch) {
    $ch = trim($ch);
    if ($ch === "") continue;
    $res = tg("getChatMember", ["chat_id" => $ch, "user_id" => (int)$uid]);
    if (!($res["ok"] ?? false)) {
      $not_joined[] = $ch; // treat as not joined if bot can't check
      continue;
    }
    $status = $res["result"]["status"] ?? "";
    if ($status === "left" || $status === "kicked") $not_joined[] = $ch;
  }
  return [$channels, $not_joined];
}

// ---------------- UI keyboards ----------------
function kb_main($uid) {
  $rows = [
    [
      ["text"=>"✅ Verify", "callback_data"=>"u:verify"],
      ["text"=>"📊 Stats", "callback_data"=>"u:stats"],
    ],
    [
      ["text"=>"🎟️ Redeem", "callback_data"=>"u:redeem_menu"],
      ["text"=>"🏆 Leaderboard", "callback_data"=>"u:leaderboard"],
    ],
    [
      ["text"=>"🔗 Referral Link", "callback_data"=>"u:ref_link"],
    ]
  ];
  if (is_admin($uid)) {
    $rows[] = [["text"=>"🛠 Admin Panel", "callback_data"=>"a:panel"]];
  }
  return ["inline_keyboard" => $rows];
}

function kb_join_verify($channels, $verify_url) {
  $rows = [];
  foreach ($channels as $ch) {
    $ch = trim($ch);
    if ($ch === "") continue;
    $rows[] = [[ "text"=>"Join {$ch}", "url"=>"https://t.me/".ltrim($ch,"@") ]];
  }
  $rows[] = [[ "text"=>"🔐 Verify on Web", "url"=>$verify_url ]];
  $rows[] = [[ "text"=>"✅ Check Verification", "callback_data"=>"u:check" ]];
  $rows[] = [[ "text"=>"⬅️ Back", "callback_data"=>"u:back" ]];
  return ["inline_keyboard"=>$rows];
}

function kb_redeem_menu() {
  return ["inline_keyboard"=>[
    [
      ["text"=>"500 off 500","callback_data"=>"u:redeem:500"],
      ["text"=>"1000 off 1000","callback_data"=>"u:redeem:1000"],
    ],
    [
      ["text"=>"2000 off 2000","callback_data"=>"u:redeem:2000"],
      ["text"=>"4000 off 4000","callback_data"=>"u:redeem:4000"],
    ],
    [
      ["text"=>"⬅️ Back","callback_data"=>"u:back"],
    ]
  ]];
}

function kb_admin_panel() {
  return ["inline_keyboard"=>[
    [["text"=>"📢 Change Force-Join Channels","callback_data"=>"a:channels"]],
    [["text"=>"⚙️ Change Redeem Points","callback_data"=>"a:rules"]],
    [
      ["text"=>"➕ Add Coupons","callback_data"=>"a:add_coupons"],
      ["text"=>"➖ Remove Coupons","callback_data"=>"a:remove_coupons"]
    ],
    [
      ["text"=>"📦 Coupons Stock","callback_data"=>"a:stock"],
      ["text"=>"📜 Redeems Log","callback_data"=>"a:redeems"]
    ],
    [["text"=>"⬅️ Back","callback_data"=>"u:back"]]
  ]];
}

function kb_admin_choose_type($prefix) {
  return ["inline_keyboard"=>[
    [
      ["text"=>"500","callback_data"=>"{$prefix}:500"],
      ["text"=>"1000","callback_data"=>"{$prefix}:1000"],
    ],
    [
      ["text"=>"2000","callback_data"=>"{$prefix}:2000"],
      ["text"=>"4000","callback_data"=>"{$prefix}:4000"],
    ],
    [["text"=>"⬅️ Back","callback_data"=>"a:panel"]]
  ]];
}

// ---------------- Text blocks ----------------
function text_welcome($uid) {
  global $BOT_USERNAME;
  $link = "https://t.me/{$BOT_USERNAME}?start={$uid}";
  return "🎉 <b>WELCOME!</b>\n\n"
    ."✅ Join all channels → Verify on website → Check Verification\n\n"
    ."🔗 Your Referral Link:\n<code>{$link}</code>\n\n"
    ."Use buttons below 👇";
}

function text_stats($uid) {
  global $BOT_USERNAME;
  $u = get_user($uid);
  if (!$u) return "No data.";
  $link = "https://t.me/{$BOT_USERNAME}?start={$uid}";
  $status = $u["verified"] ? "✅ Verified" : "❌ Not Verified";
  return "📊 <b>Your Stats</b>\n\n"
    ."Status: <b>{$status}</b>\n"
    ."Points: <b>".(int)$u["points"]."</b>\n"
    ."Referrals: <b>".(int)$u["referrals"]."</b>\n\n"
    ."🔗 Referral Link:\n<code>{$link}</code>";
}

function text_admin_panel() {
  $channels = get_force_channels();
  $rules = get_redeem_rules();
  $stock = stock_counts();

  $t = "🛠 <b>Admin Panel</b>\n\n📢 <b>Force-Join Channels</b>:\n";
  $i=1;
  foreach ($channels as $c) {
    $c = trim($c);
    if ($c==="") continue;
    $t .= "{$i}) <code>{$c}</code>\n";
    $i++;
  }
  $t .= "\n⚙️ <b>Redeem Points</b>:\n";
  foreach (["500","1000","2000","4000"] as $k) {
    $t .= "• ".coupon_label($k)." = <b>".(int)$rules[$k]["points"]."</b> pts\n";
  }
  $t .= "\n📦 <b>Stock</b>:\n";
  foreach (["500","1000","2000","4000"] as $k) {
    $t .= "• ".coupon_label($k)." = <b>".(int)$stock[$k]."</b>\n";
  }
  return $t;
}

// ---------------- Route handling ----------------
$path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";

// Health
if ($path === "/" || $path === "/health") {
  header("Content-Type: text/plain; charset=utf-8");
  echo "OK";
  exit;
}

// Web verify page
if ($path === "/verify") {
  header("Content-Type: text/html; charset=utf-8");
  $token = htmlspecialchars($_GET["token"] ?? "");
  ?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Verification</title>
  <style>
    body{font-family:Arial;margin:24px;}
    .card{max-width:520px;margin:auto;border:1px solid #ddd;border-radius:14px;padding:18px;}
    button{width:100%;padding:12px;font-size:16px;border-radius:10px;border:0;cursor:pointer;}
    .ok{color:green;font-weight:700;}
    .bad{color:#b00020;font-weight:700;}
  </style>
</head>
<body>
<div class="card">
  <h2>🔐 Web Verification</h2>
  <p>Rule: <b>1 device = 1 Telegram ID</b></p>
  <button id="btn">✅ Verify Now</button>
  <p id="msg"></p>
  <p id="done" style="display:none;">✅ Done. Go back to Telegram and press <b>Check Verification</b>.</p>
</div>

<script>
const token = "<?= $token ?>";

function getDeviceId(){
  let id = localStorage.getItem("device_id");
  if(!id){
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

document.getElementById("btn").onclick = async () => {
  const msg = document.getElementById("msg");
  msg.textContent = "Verifying...";
  try {
    const res = await fetch("/api/verify", {
      method:"POST",
      headers:{"Content-Type":"application/json"},
      body: JSON.stringify({ token, device_id: getDeviceId() })
    });
    const j = await res.json();
    if(j.ok){
      msg.innerHTML = '<span class="ok">✅ '+j.message+'</span>';
      document.getElementById("done").style.display = "block";
      document.getElementById("btn").disabled = true;
    } else {
      msg.innerHTML = '<span class="bad">❌ '+j.message+'</span>';
    }
  } catch(e){
    msg.innerHTML = '<span class="bad">❌ Network error</span>';
  }
};
</script>
</body>
</html>
<?php
  exit;
}

// Verify API
if ($path === "/api/verify") {
  header("Content-Type: application/json; charset=utf-8");
  $body = json_decode(file_get_contents("php://input"), true) ?: [];
  $token = $body["token"] ?? "";
  $device_id = $body["device_id"] ?? "";
  [$ok, $msg, $tgid] = verify_on_web($token, $device_id);
  echo jencode(["ok"=>$ok, "message"=>$msg, "tg_id"=>$tgid]);
  exit;
}

// Telegram webhook endpoint
if ($path === "/telegram") {
  $update = json_decode(file_get_contents("php://input"), true);
  if (!$update) { echo "OK"; exit; }

  // ---------------- MESSAGE ----------------
  if (isset($update["message"])) {
    $m = $update["message"];
    $text = $m["text"] ?? "";
    $chat_id = $m["chat"]["id"];
    $uid = $m["from"]["id"];
    $username = $m["from"]["username"] ?? null;
    $first_name = $m["from"]["first_name"] ?? null;

    upsert_user($uid, $username, $first_name);

    // State input (admin actions)
    $u = get_user($uid);
    $state = $u["state"] ?? null;
    $state_data = $u["state_data"] ?? null;

    if ($state && is_admin($uid)) {
      $dataArr = is_array($state_data) ? $state_data : [];
      // Change channels: admin sends 5 lines
      if ($state === "admin_set_channels") {
        $lines = array_values(array_filter(array_map("trim", preg_split("/\r\n|\n|\r/", $text))));
        if (count($lines) < 5) {
          sendMessage($chat_id, "Send 5 lines:\n<code>@ch1\n@ch2\n@ch3\n@ch4\n@ch5</code>");
          echo "OK"; exit;
        }
        $chs = [];
        for ($i=0; $i<5; $i++) {
          $ln = $lines[$i] ?? "";
          if ($ln !== "" && $ln[0] !== "@") $ln = "@".$ln;
          $chs[] = $ln;
        }
        set_setting("force_join_channels", $chs);
        clear_state($uid);
        sendMessage($chat_id, "✅ Channels updated!", kb_admin_panel());
        echo "OK"; exit;
      }

      // Set redeem points
      if ($state === "admin_set_points") {
        $type = $dataArr["type"] ?? "";
        $num = preg_replace("/\D+/", "", $text);
        if (!in_array($type, ["500","1000","2000","4000"], true) || $num === "") {
          sendMessage($chat_id, "Send a number only (example: <code>3</code>)");
          echo "OK"; exit;
        }
        $rules = get_redeem_rules();
        $rules[$type]["points"] = max(0, (int)$num);
        set_setting("redeem_rules", $rules);
        clear_state($uid);
        sendMessage($chat_id, "✅ Updated points for <b>".coupon_label($type)."</b>.", kb_admin_panel());
        echo "OK"; exit;
      }

      // Add coupons: admin sends codes lines
      if ($state === "admin_add_coupons") {
        $type = $dataArr["type"] ?? "";
        $lines = array_values(array_filter(array_map("trim", preg_split("/\r\n|\n|\r/", $text))));
        if (!in_array($type, ["500","1000","2000","4000"], true) || count($lines) === 0) {
          sendMessage($chat_id, "Send coupon codes (one per line).");
          echo "OK"; exit;
        }
        $n = add_coupons($type, $lines);
        clear_state($uid);
        sendMessage($chat_id, "✅ Added <b>{$n}</b> coupons to <b>".coupon_label($type)."</b>.", kb_admin_panel());
        echo "OK"; exit;
      }

      // Remove coupons: admin sends number
      if ($state === "admin_remove_coupons") {
        $type = $dataArr["type"] ?? "";
        $num = preg_replace("/\D+/", "", $text);
        if (!in_array($type, ["500","1000","2000","4000"], true) || $num === "") {
          sendMessage($chat_id, "Send how many to remove (example: <code>10</code>)");
          echo "OK"; exit;
        }
        $del = remove_unused_coupons($type, max(1, (int)$num));
        clear_state($uid);
        sendMessage($chat_id, "✅ Removed <b>{$del}</b> unused coupons from <b>".coupon_label($type)."</b>.", kb_admin_panel());
        echo "OK"; exit;
      }
    }

    // /start handling
    if (strpos($text, "/start") === 0) {
      // parse referral param
      $parts = explode(" ", trim($text));
      if (isset($parts[1]) && ctype_digit($parts[1])) {
        ensure_referred_by($uid, (int)$parts[1]);
      }
      sendMessage($chat_id, text_welcome($uid), kb_main($uid));
      echo "OK"; exit;
    }

    // default
    sendMessage($chat_id, "Choose an option 👇", kb_main($uid));
    echo "OK"; exit;
  }

  // ---------------- CALLBACK QUERY ----------------
  if (isset($update["callback_query"])) {
    $q = $update["callback_query"];
    $cb_id = $q["id"];
    $data = $q["data"] ?? "";
    $uid = $q["from"]["id"];
    $chat_id = $q["message"]["chat"]["id"];
    $mid = $q["message"]["message_id"];
    $username = $q["from"]["username"] ?? null;
    $first_name = $q["from"]["first_name"] ?? null;

    upsert_user($uid, $username, $first_name);

    // User actions
    if (strpos($data, "u:") === 0) {
      answerCb($cb_id);

      if ($data === "u:back") {
        editMessage($chat_id, $mid, text_welcome($uid), kb_main($uid));
        echo "OK"; exit;
      }

      if ($data === "u:verify") {
        // show join buttons + web verify + check verification
        [$channels, $not_joined] = check_force_join($uid);
        $token = create_verify_token($uid);
        $verify_url = "{$GLOBALS['PUBLIC_BASE_URL']}/verify?token={$token}";

        if (count($not_joined) > 0) {
          editMessage(
            $chat_id, $mid,
            "⚠️ <b>Join all channels first</b>\n\nThen verify on website and click <b>Check Verification</b>.",
            kb_join_verify($channels, $verify_url)
          );
        } else {
          editMessage(
            $chat_id, $mid,
            "✅ <b>Joined all channels!</b>\n\nNow verify on website and then click <b>Check Verification</b>.",
            kb_join_verify($channels, $verify_url)
          );
        }
        echo "OK"; exit;
      }

      if ($data === "u:check") {
        // Must be joined + verified
        [$channels, $not_joined] = check_force_join($uid);
        $u = get_user($uid);

        $token = create_verify_token($uid);
        $verify_url = "{$GLOBALS['PUBLIC_BASE_URL']}/verify?token={$token}";

        if (count($not_joined) > 0) {
          editMessage(
            $chat_id, $mid,
            "⚠️ <b>You still haven't joined all channels.</b>\n\nJoin all and try again.",
            kb_join_verify($channels, $verify_url)
          );
          echo "OK"; exit;
        }

        if (!$u || !$u["verified"]) {
          editMessage(
            $chat_id, $mid,
            "❌ <b>Not verified yet.</b>\n\nVerify on website, then click <b>Check Verification</b>.",
            kb_join_verify($channels, $verify_url)
          );
          echo "OK"; exit;
        }

        // verified -> award referral if needed
        $ref_id = award_referral_if_needed($uid);
        if ($ref_id) {
          $refUser = get_user($ref_id);
          $name = safe_name($u ?: ["tg_id"=>$uid]);
          sendMessage($ref_id, "✅ <b>Referral Added!</b>\nYou got <b>+1</b> point because <b>{$name}</b> verified.");
        }

        editMessage($chat_id, $mid, "✅ <b>Verification Successful!</b>\n\nNow you can use the bot.", kb_main($uid));
        echo "OK"; exit;
      }

      if ($data === "u:stats") {
        editMessage($chat_id, $mid, text_stats($uid), kb_main($uid));
        echo "OK"; exit;
      }

      if ($data === "u:ref_link") {
        $link = "https://t.me/{$GLOBALS['BOT_USERNAME']}?start={$uid}";
        editMessage(
          $chat_id, $mid,
          "🔗 <b>Your Referral Link</b>\n\n<code>{$link}</code>\n\nPoints are added only after user verifies ✅",
          kb_main($uid)
        );
        echo "OK"; exit;
      }

      if ($data === "u:leaderboard") {
        global $pdo;
        $rows = $pdo->query("select tg_id, username, first_name, referrals from users order by referrals desc, points desc limit 10")->fetchAll();
        $txt = "🏆 <b>Top 10 Leaderboard</b>\n\n";
        if (!$rows) $txt .= "No users yet.";
        else {
          $i=1;
          foreach ($rows as $r) {
            $name = $r["first_name"] ?: ($r["username"] ? "@".$r["username"] : (string)$r["tg_id"]);
            $txt .= "{$i}) <b>".htmlspecialchars($name)."</b> — Referrals: <b>".(int)$r["referrals"]."</b>\n";
            $i++;
          }
        }
        editMessage($chat_id, $mid, $txt, kb_main($uid));
        echo "OK"; exit;
      }

      if ($data === "u:redeem_menu") {
        // show info + menu
        $u = get_user($uid);
        if (!$u || !$u["verified"]) {
          answerCb($cb_id, "Verify first", true);
          echo "OK"; exit;
        }
        $rules = get_redeem_rules();
        $stock = stock_counts();
        $pts = (int)$u["points"];

        $txt = "🎟️ <b>Redeem Coupons</b>\n\nYour Points: <b>{$pts}</b>\n\n";
        foreach (["500","1000","2000","4000"] as $t) {
          $need = (int)$rules[$t]["points"];
          $txt .= "• <b>".coupon_label($t)."</b> — Need <b>{$need}</b> — Stock <b>".(int)$stock[$t]."</b>\n";
        }

        editMessage($chat_id, $mid, $txt, kb_redeem_menu());
        echo "OK"; exit;
      }

      if (strpos($data, "u:redeem:") === 0) {
        $type = explode(":", $data)[2] ?? "";
        [$ok,$info,$spent] = redeem_coupon($uid, $type);
        if (!$ok) {
          answerCb($cb_id, $info, true);
          echo "OK"; exit;
        }

        // notify admin with who redeemed
        $u = get_user($uid);
        $nm = safe_name($u ?: ["tg_id"=>$uid]);
        foreach ($GLOBALS["ADMIN_IDS"] as $aid) {
          sendMessage($aid,
            "🎟️ <b>Redeem Alert</b>\nUser: <b>{$nm}</b> (<code>{$uid}</code>)\nType: <b>".coupon_label($type)."</b>\nSpent: <b>{$spent}</b>\nCode: <code>{$info}</code>"
          );
        }

        editMessage(
          $chat_id, $mid,
          "🎉 <b>Congratulations!</b>\n\nYour Coupon: <code>{$info}</code>\n\nPoints spent: <b>{$spent}</b>",
          kb_main($uid)
        );
        echo "OK"; exit;
      }

      echo "OK"; exit;
    }

    // Admin actions
    if (strpos($data, "a:") === 0) {
      if (!is_admin($uid)) {
        answerCb($cb_id, "Not allowed", true);
        echo "OK"; exit;
      }
      answerCb($cb_id);

      if ($data === "a:panel") {
        editMessage($chat_id, $mid, text_admin_panel(), kb_admin_panel());
        echo "OK"; exit;
      }

      if ($data === "a:channels") {
        set_state($uid, "admin_set_channels", []);
        editMessage(
          $chat_id, $mid,
          "📢 <b>Send 5 channel usernames (5 lines)</b>\n\n<code>@ch1\n@ch2\n@ch3\n@ch4\n@ch5</code>",
          kb_admin_panel()
        );
        echo "OK"; exit;
      }

      if ($data === "a:rules") {
        editMessage($chat_id, $mid, "⚙️ <b>Select which coupon to change points for:</b>", kb_admin_choose_type("a:rule"));
        echo "OK"; exit;
      }
      if (strpos($data, "a:rule:") === 0) {
        $type = explode(":", $data)[2] ?? "";
        set_state($uid, "admin_set_points", ["type"=>$type]);
        editMessage(
          $chat_id, $mid,
          "✏️ Send new required points for <b>".coupon_label($type)."</b>\nExample: <code>3</code>\n\nBot will deduct EXACTLY this amount.",
          kb_admin_panel()
        );
        echo "OK"; exit;
      }

      if ($data === "a:add_coupons") {
        editMessage($chat_id, $mid, "➕ <b>Select coupon type to add:</b>", kb_admin_choose_type("a:add"));
        echo "OK"; exit;
      }
      if (strpos($data, "a:add:") === 0) {
        $type = explode(":", $data)[2] ?? "";
        set_state($uid, "admin_add_coupons", ["type"=>$type]);
        editMessage(
          $chat_id, $mid,
          "➕ Send coupon codes for <b>".coupon_label($type)."</b>\n\nOne code per line:\n<code>CODE1\nCODE2\nCODE3</code>",
          kb_admin_panel()
        );
        echo "OK"; exit;
      }

      if ($data === "a:remove_coupons") {
        editMessage($chat_id, $mid, "➖ <b>Select coupon type to remove:</b>", kb_admin_choose_type("a:rem"));
        echo "OK"; exit;
      }
      if (strpos($data, "a:rem:") === 0) {
        $type = explode(":", $data)[2] ?? "";
        set_state($uid, "admin_remove_coupons", ["type"=>$type]);
        editMessage(
          $chat_id, $mid,
          "➖ Send how many <b>unused</b> coupons to remove from <b>".coupon_label($type)."</b>\nExample: <code>10</code>",
          kb_admin_panel()
        );
        echo "OK"; exit;
      }

      if ($data === "a:stock") {
        $stock = stock_counts();
        $txt = "📦 <b>Coupons Stock</b>\n\n";
        foreach (["500","1000","2000","4000"] as $t) {
          $txt .= "• ".coupon_label($t)." : <b>".(int)$stock[$t]."</b>\n";
        }
        editMessage($chat_id, $mid, $txt, kb_admin_panel());
        echo "OK"; exit;
      }

      if ($data === "a:redeems") {
        global $pdo;
        $rows = $pdo->query("
          select r.created_at, r.tg_id, r.coupon_type, r.points_spent, r.coupon_code,
                 u.username, u.first_name
          from redeems r
          left join users u on u.tg_id=r.tg_id
          order by r.id desc
          limit 20
        ")->fetchAll();

        $txt = "📜 <b>Last 20 Redeems</b>\n\n";
        if (!$rows) $txt .= "No redeems yet.";
        else {
          foreach ($rows as $r) {
            $name = $r["first_name"] ?: ($r["username"] ? "@".$r["username"] : (string)$r["tg_id"]);
            $txt .= "• <b>".htmlspecialchars($name)."</b> — ".coupon_label($r["coupon_type"])." — spent <b>".(int)$r["points_spent"]."</b>\n";
          }
        }
        editMessage($chat_id, $mid, $txt, kb_admin_panel());
        echo "OK"; exit;
      }

      echo "OK"; exit;
    }

    echo "OK"; exit;
  }

  echo "OK";
  exit;
}

// Unknown route
http_response_code(404);
echo "Not Found";
