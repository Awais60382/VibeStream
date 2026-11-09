<?php
if (defined('VS_BAN_GUARD_LOADED')) { return; }
define('VS_BAN_GUARD_LOADED', true);
/**
 * ban-guard.php  (main site folder, next to connection.php and index.php)
 *
 * connection.php loads this automatically for every database-backed site request.
 * Pages without a connection include may require this file and call vs_bg_guard().
 *
 * If the signed-in user is banned they are signed out and sent to banned.php,
 * which shows a card with the reason and a live countdown to the unban.
 */

/** First row of a query, for either a PDO or a mysqli connection (positional ? params). */
function vs_bg_query($db, string $sql, array $params): ?array {
    try {
        $params = array_values($params);
        if ($db instanceof PDO) {
            $st = $db->prepare($sql);
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }
        if ($db instanceof mysqli) {
            $st = $db->prepare($sql);
            if (!$st) return null;
            if ($params) $st->bind_param(str_repeat('s', count($params)), ...$params);
            $st->execute();
            $res = $st->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $st->close();
            return $row ?: null;
        }
    } catch (Throwable $e) {
        // table missing = nobody is banned
    }
    return null;
}

/** Active ban for this account (matched by id or username), or null. Expired temporary bans are removed. */
function vs_bg_user_ban($db, $userId, string $username = ''): ?array {
    $userId = trim((string)$userId);
    $username = trim($username);
    if ($userId === '' && $username === '') return null;
    $row = vs_bg_query(
        $db,
        "SELECT `user_id`, `is_permanent`, `expires_at`, UNIX_TIMESTAMP(`expires_at`) AS `expires_epoch`, `reason` FROM `user_bans`
         WHERE ((? <> '' AND `user_id` = ?) OR (? <> '' AND LOWER(`username`) = LOWER(?)))
           AND (`is_permanent` = 1 OR (`expires_at` IS NOT NULL AND `expires_at` > NOW()))
         ORDER BY `is_permanent` DESC, `expires_at` DESC LIMIT 1",
        [$userId, $userId, $username, $username]
    );
    vs_bg_query(
        $db,
        "DELETE FROM `user_bans`
         WHERE `is_permanent` = 0 AND `expires_at` IS NOT NULL AND `expires_at` <= NOW()
           AND ((? <> '' AND `user_id` = ?) OR (? <> '' AND LOWER(`username`) = LOWER(?)))",
        [$userId, $userId, $username, $username]
    );
    if (!$row) return null;
    $permanent = (int)$row['is_permanent'] === 1;
    $until = $permanent ? 0 : (int)($row['expires_epoch'] ?? 0);
    return ['permanent' => $permanent, 'until' => $until, 'reason' => trim((string)($row['reason'] ?? ''))];
}

function vs_bg_legacy_user_banned($db, string $userId, string $username): bool {
    try {
        if ($db instanceof PDO) {
            $columns = $db->query('SHOW COLUMNS FROM `users`')->fetchAll(PDO::FETCH_COLUMN, 0);
        } elseif ($db instanceof mysqli) {
            $result = $db->query('SHOW COLUMNS FROM `users`');
            $columns = [];
            while ($column = $result->fetch_assoc()) $columns[] = $column['Field'];
            $result->free();
        } else {
            return false;
        }
        $map = [];
        foreach ($columns as $column) $map[strtolower((string)$column)] = (string)$column;
        $pick = static function (array $names) use ($map): ?string {
            foreach ($names as $name) if (isset($map[$name])) return $map[$name];
            return null;
        };
        $idColumn = $pick(['id', 'user_id']);
        $nameColumn = $pick(['username', 'user_name']);
        $checks = [];
        $params = [];
        if ($userId !== '' && $idColumn) { $checks[] = '`' . str_replace('`', '``', $idColumn) . '` = ?'; $params[] = $userId; }
        if ($username !== '' && $nameColumn) { $checks[] = '`' . str_replace('`', '``', $nameColumn) . '` = ?'; $params[] = $username; }
        if (!$checks) return false;

        $select = [];
        foreach (['status', 'account_status', 'is_banned', 'banned', 'is_blocked'] as $candidate) {
            $column = $pick([$candidate]);
            if ($column) $select[] = '`' . str_replace('`', '``', $column) . '` AS `' . $candidate . '`';
        }
        if (!$select) return false;
        $row = vs_bg_query($db, 'SELECT ' . implode(', ', $select) . ' FROM `users` WHERE (' . implode(' OR ', $checks) . ') LIMIT 1', $params);
        if (!$row) return false;
        foreach (['status', 'account_status'] as $column) {
            if (in_array(strtolower(trim((string)($row[$column] ?? ''))), ['banned', 'blocked', 'disabled', 'suspended'], true)) return true;
        }
        foreach (['is_banned', 'banned', 'is_blocked'] as $column) {
            if ((int)($row[$column] ?? 0) === 1) return true;
        }
    } catch (Throwable $e) {
        error_log('Ban guard legacy status check failed: ' . $e->getMessage());
    }
    return false;
}

function vs_bg_brand(): array {
    $b = ['name' => 'VibeStream', 'logo' => '', 'color' => '#7c3aed', 'theme' => 'dark', 'support' => ''];
    try {
        if (!function_exists('vs_branding_app_name') && is_file(__DIR__ . '/site-branding.php')) {
            require_once __DIR__ . '/site-branding.php';
        }
        if (function_exists('vs_branding_app_name'))      $b['name']  = (string)vs_branding_app_name();
        if (function_exists('vs_branding_logo_url'))      $b['logo']  = (string)vs_branding_logo_url();
        if (function_exists('vs_branding_default_theme')) $b['theme'] = (string)vs_branding_default_theme();
        if (function_exists('vs_branding_state')) {
            $st = vs_branding_state();
            $c = strtolower(trim((string)($st['brand_color'] ?? '')));
            if (preg_match('/^#[0-9a-f]{6}$/', $c)) $b['color'] = $c;
            $m = trim((string)($st['support_email'] ?? ''));
            if ($m !== '' && filter_var($m, FILTER_VALIDATE_EMAIL)) $b['support'] = $m;
        }
    } catch (Throwable $e) {}
    return $b;
}

/** Full HTML page with the "you have been banned" card. */
function vs_bg_card_html(array $ban): string {
    $brand = vs_bg_brand();
    $siteName = $brand['name']; $logo = $brand['logo'];
    $light = $brand['theme'] === 'light';
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $permanent = !empty($ban['permanent']);
    $until = (int)($ban['until'] ?? 0);
    $ended = !$permanent && $until <= time();
    $reason = trim((string)($ban['reason'] ?? ''));
    ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Account banned · <?= $e($siteName) ?></title>
<style>
  :root{--brand:<?= $e($brand['color']) ?>;--bg:#080a16;--bgglow1:rgba(255,60,110,.22);--bgglow2:rgba(110,60,255,.28);--card1:rgba(22,26,52,.97);--card2:rgba(12,15,32,.98);--txt:#f4f5ff;--mut:#aab0d4;--dim:#8f95bb;--box:rgba(20,26,56,.55);--tile:rgba(8,10,24,.6);--line:rgba(139,111,255,.22);--danger:#ff6b88}
  <?php if ($light): ?>:root{--bg:#f3f4fb;--bgglow1:rgba(255,60,110,.12);--bgglow2:rgba(110,60,255,.14);--card1:#fff;--card2:#fff;--txt:#14172e;--mut:#555b7e;--dim:#7a80a3;--box:#f4f5fc;--tile:#fff;--line:rgba(90,70,200,.18);--danger:#e0294a}<?php endif; ?>
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;color:var(--txt);font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;
    background:radial-gradient(ellipse 60% 50% at 0% 0%,var(--bgglow1),transparent 60%),radial-gradient(ellipse 60% 50% at 100% 100%,var(--bgglow2),transparent 60%),var(--bg)}
  .card{width:min(100%,480px);padding:34px 30px;border:1px solid rgba(255,90,130,.32);border-radius:22px;background:linear-gradient(160deg,var(--card1),var(--card2));box-shadow:0 24px 70px rgba(0,0,0,.35);text-align:center}
  .brand{display:inline-flex;align-items:center;gap:9px;margin-bottom:20px;font-weight:700;font-size:17px;color:var(--txt)}
  .brand img{width:30px;height:30px;border-radius:8px;object-fit:cover}
  .icon{width:74px;height:74px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;background:rgba(255,77,109,.14);border:1px solid rgba(255,77,109,.45);color:var(--danger);box-shadow:0 0 30px rgba(255,77,109,.22)}
  .icon.ok{background:rgba(34,224,138,.12);border-color:rgba(34,224,138,.45);color:#1fbf78;box-shadow:0 0 30px rgba(34,224,138,.2)}
  .icon svg{width:38px;height:38px}
  h1{margin:0 0 6px;font-size:25px;letter-spacing:-.02em}
  .sub{margin:0 0 22px;color:var(--mut);font-size:15px}
  .box{margin:0 0 14px;padding:15px 16px;border:1px solid var(--line);border-radius:14px;background:var(--box);text-align:left}
  .label{margin:0 0 6px;color:var(--dim);font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase}
  .reason{margin:0;font-size:16px;line-height:1.5;word-break:break-word}
  .reason.none{color:var(--dim);font-style:italic}
  .count{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:4px}
  .count div{padding:11px 4px;border-radius:12px;background:var(--tile);border:1px solid var(--line);text-align:center}
  .count b{display:block;font-size:26px;font-variant-numeric:tabular-nums}
  .count span{font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.08em}
  .until{margin:12px 0 0;color:var(--mut);font-size:14px;text-align:center}
  .perm{margin:0;font-size:18px;font-weight:700;color:var(--danger)}
  .btn{display:inline-block;margin-top:8px;padding:13px 26px;border-radius:12px;background:linear-gradient(90deg,var(--brand),color-mix(in srgb,var(--brand) 60%,#4a4bff));color:#fff;font-weight:700;text-decoration:none}
  .btn:hover{filter:brightness(1.1)}
  .ghost{background:none;border:1px solid var(--brand);color:var(--txt)}
  .support{margin:16px 0 0;font-size:13px;color:var(--dim)}
  .support a{color:var(--brand);font-weight:600;text-decoration:none}
</style>
</head>
<body>
<main class="card" role="alert">
  <div class="brand"><?php if ($logo !== ''): ?><img src="<?= $e($logo) ?>" alt=""><?php endif; ?><span><?= $e($siteName) ?></span></div>

  <div class="icon<?= $ended ? ' ok' : '' ?>" id="icon">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" id="iconSvg"><?php if ($ended): ?><path d="M5 12.5l4.5 4.5L19 7"/><?php else: ?><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/><?php endif; ?></svg>
  </div>
  <h1 id="title"><?= $ended ? 'Your ban has ended' : ($permanent ? 'You have been permanently banned' : 'You have been temporarily banned') ?></h1>
  <p class="sub" id="sub"><?= $ended ? 'You can sign in again now.' : 'You cannot use your account right now.' ?></p>

  <?php if (!$ended): ?>
  <div class="box" id="reasonBox">
    <p class="label">Why you were banned</p>
    <?php if ($reason !== ''): ?><p class="reason"><?= $e($reason) ?></p><?php else: ?><p class="reason none">No reason was given by the moderators.</p><?php endif; ?>
  </div>

  <div class="box" id="timeBox">
    <p class="label"><?= $permanent ? 'Ban length' : 'Time until you are unbanned' ?></p>
    <?php if ($permanent): ?>
      <p class="perm">Permanent: this ban does not expire</p>
    <?php else: ?>
      <div class="count">
        <div><b id="d">0</b><span>Days</span></div>
        <div><b id="h">0</b><span>Hours</span></div>
        <div><b id="m">0</b><span>Min</span></div>
        <div><b id="s">0</b><span>Sec</span></div>
      </div>
      <p class="until">Unbanned on <?= $e(date('M j, Y \a\t g:i A', $until)) ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <a class="btn<?= $ended ? '' : ' ghost' ?>" id="btn" href="login.php"><?= $ended ? 'Sign in' : 'Back to sign in' ?></a>
  <?php if ($brand['support'] !== '' && !$ended): ?>
  <p class="support">Think this is a mistake? <a href="mailto:<?= $e($brand['support']) ?>?subject=<?= rawurlencode('Ban appeal') ?>">Contact support</a></p>
  <?php endif; ?>
</main>
<?php if (!$permanent && !$ended): ?>
<script>
(function () {
  var until = <?= (int)$until ?>;
  var off = <?= time() ?> - Math.floor(Date.now() / 1000);   // use the server clock, not the browser's
  function pad(n) { return n; }
  function tick() {
    var left = until - (Math.floor(Date.now() / 1000) + off);
    if (left <= 0) { done(); return; }
    document.getElementById('d').textContent = Math.floor(left / 86400);
    document.getElementById('h').textContent = Math.floor((left % 86400) / 3600);
    document.getElementById('m').textContent = Math.floor((left % 3600) / 60);
    document.getElementById('s').textContent = left % 60;
  }
  function done() {
    clearInterval(t);
    document.getElementById('title').textContent = 'Your ban has ended';
    document.getElementById('sub').textContent = 'You can sign in again now.';
    var i = document.getElementById('icon'); i.classList.add('ok');
    document.getElementById('iconSvg').innerHTML = '<path d="M5 12.5l4.5 4.5L19 7"/>';
    var a = document.getElementById('reasonBox'); if (a) a.remove();
    var b = document.getElementById('timeBox'); if (b) b.remove();
    var btn = document.getElementById('btn'); btn.textContent = 'Sign in'; btn.classList.remove('ghost');
  }
  var t = setInterval(tick, 1000);
  tick();
})();
</script>
<?php endif; ?>
</body>
</html>
<?php
    return (string)ob_get_clean();
}

/** Web path of banned.php (works from any folder of the site). */
function vs_bg_banned_url(): string {
    $root = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', (string)realpath((string)$_SERVER['DOCUMENT_ROOT'])) : '';
    $dir  = str_replace('\\', '/', __DIR__);
    if ($root !== '' && stripos($dir, $root) === 0) {
        return rtrim(substr($dir, strlen($root)), '/') . '/banned.php';
    }
    return 'banned.php';
}

/** True when the current session belongs to an admin / owner / CEO / founder (never bannable). */
function vs_bg_is_privileged_session(): bool {
    if (!empty($_SESSION['AdminSession'])) return true;
    foreach (['role', 'user_role', 'usertype', 'user_type', 'account_type', 'admin_role'] as $key) {
        if (!isset($_SESSION[$key]) || !is_scalar($_SESSION[$key])) continue;
        $normalized = preg_replace('/[^a-z]/', '', strtolower(trim((string)$_SESSION[$key])));
        if ($normalized !== '' && preg_match('/admin|owner|ceo|founder/', $normalized) === 1) return true;
    }
    return false;
}

/** True when the session holds a signed-in account (a fresh login), not just leftover ban-card data. */
function vs_bg_session_logged_in(): bool {
    foreach (['user_id', 'id', 'userid', 'uid', 'username'] as $key) {
        if (!empty($_SESSION[$key]) && is_scalar($_SESSION[$key])) return true;
    }
    return false;
}

/** Signs a banned visitor out and sends them to the ban card. */
function vs_bg_enforce($db): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $route = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $authRoutes = ['login.php', 'login-handler.php', 'google-login.php', 'signup.php', 'signup-handler.php'];
    if (in_array($route, $authRoutes, true)) return;

    // Admin / owner / CEO / founder sessions are never banned; also clear any ban card left in this browser.
    if (vs_bg_is_privileged_session()) {
        unset($_SESSION['vs_ban_card']);
        return;
    }

    $id = '';
    foreach (['user_id', 'id', 'userid', 'uid'] as $key) {
        if (!empty($_SESSION[$key]) && is_scalar($_SESSION[$key])) { $id = (string)$_SESSION[$key]; break; }
    }
    $name = trim((string)($_SESSION['username'] ?? ''));

    if (is_array($_SESSION['vs_ban_card'] ?? null)) {
        $banCard = $_SESSION['vs_ban_card'];
        if ($id !== '' || $name !== '') {
            // someone signed in after a ban card was stored in this browser session:
            // the card belongs to the earlier (banned) visitor, so drop it and re-check this account below
            unset($_SESSION['vs_ban_card']);
        } elseif (empty($banCard['permanent']) && (int)($banCard['until'] ?? 0) <= time()) {
            unset($_SESSION['vs_ban_card']);
            if (session_status() === PHP_SESSION_ACTIVE) @session_regenerate_id(true);
        } else {
            if (!headers_sent()) {
                header('Location: ' . vs_bg_banned_url());
            } else {
                http_response_code(403);
                echo vs_bg_card_html($banCard);
            }
            exit;
        }
    }

    if ($id === '' && $name === '') return;
    $ban = vs_bg_user_ban($db, $id, $name);
    if (!$ban && vs_bg_legacy_user_banned($db, $id, $name)) {
        $ban = ['permanent' => true, 'until' => 0, 'reason' => ''];
    }
    if (!$ban) return;

    $_SESSION = ['vs_ban_card' => $ban];      // signed out; only the ban card data is kept
    if (session_status() === PHP_SESSION_ACTIVE) @session_regenerate_id(true);

    if (!headers_sent()) {
        header('Location: ' . vs_bg_banned_url());
    } else {
        http_response_code(403);
        echo vs_bg_card_html($ban);
    }
    exit;
}

/** One-liner for pages: uses $pdo or $conn from connection.php. */
function vs_bg_guard(): void {
    global $pdo, $conn;
    $db = (isset($pdo) && $pdo instanceof PDO) ? $pdo : ((isset($conn) && $conn instanceof mysqli) ? $conn : null);
    if ($db) vs_bg_enforce($db);
}