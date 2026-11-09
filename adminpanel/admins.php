<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

include_once "../connection.php";
include_once "ui_helpers.php";
require_once __DIR__ . '/../avatar.php';
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/admin-activity-store.php';
require_once __DIR__ . '/owner-alert.php';
require_once __DIR__ . '/release-username.php';
require_once __DIR__ . '/../site-branding.php';

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$adminCsrf = $_SESSION['admin_csrf'];

$USERS_TABLE = 'users';
$ADMIN_ROLE  = 'Admin';

$myId   = $_SESSION['user_id'] ?? $_SESSION['AdminID'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? $_SESSION['uid'] ?? null;
$myName = mb_strtolower(trim((string)($_SESSION['AdminSession'] ?? $_SESSION['username'] ?? '')));
$q      = trim((string)($_GET['q'] ?? ''));

$fatal  = '';
$admins = [];
$totalAdmins = 0;
$isOwner = false;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $fatal = 'No database connection found. Check that ../connection.php creates $pdo.';
} else {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $isOwner = vs_is_owner($pdo);

    $stats = vs_uploader_stats($pdo);
    $byKey = [];
    foreach ($stats['rows'] as $r) {
        $byKey[$r['key']] = $r;
    }

    $uc = vs_cols($pdo, $USERS_TABLE);
    if (!$uc) {
        $fatal = 'The "' . $USERS_TABLE . '" table was not found.';
    } else {
        $map = [
            'id'          => vs_pick($uc, ['id', 'user_id']),
            'username'    => vs_pick($uc, ['username', 'user_name', 'login', 'uname']),
            'name'        => vs_pick($uc, ['name', 'full_name', 'fullname', 'display_name', 'first_name']),
            'email'       => vs_pick($uc, ['email', 'email_address', 'mail']),
            'role'        => vs_pick($uc, ['role', 'user_role', 'usertype', 'user_type', 'account_type']),
            'status'      => vs_pick($uc, ['status', 'account_status', 'is_active', 'active']),
            'joined'      => vs_pick($uc, ['created_at', 'joined_at', 'registered_at', 'date_joined', 'created_on', 'reg_date', 'date']),
            'avatar'      => vs_pick($uc, ['avatar', 'profile_image', 'profile_pic', 'photo', 'picture', 'image']),
            'last_active' => vs_pick($uc, ['last_active', 'last_seen', 'last_login']),
        ];

        if (!$map['role']) {
            $fatal = 'The "' . $USERS_TABLE . '" table has no role column, so admins cannot be told apart from users.';
        } else {
            // Give or remove the CEO title. ONLY the owner may do this, and only for admin accounts
            // (the account stays an Admin, so its access to the panel never changes).
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_ceo') {
                $code    = 'failed';
                $ceoId   = trim((string)($_POST['id'] ?? ''));
                $makeCeo = ($_POST['value'] ?? '') === '1';
                if (!hash_equals($adminCsrf, (string)($_POST['csrf'] ?? ''))) {
                    $code = 'expired';
                } elseif (!$isOwner) {
                    $code = 'denied_ceo';
                    vs_admin_log($pdo, 'user', 'Tried to change the CEO title of account #' . mb_substr($ceoId, 0, 20) . ' (blocked: owner only)');
                    vs_owner_alert($pdo, 'blocked', 'Blocked: someone tried to change a CEO title', 'Only the owner can give or remove the CEO title, so it was refused.');
                } elseif (!$map['id'] || $ceoId === '') {
                    $code = 'failed';
                } else {
                    try {
                        $emailSel = $map['email'] ? vs_q($map['email']) : "''";
                        $nameSel  = $map['username'] ? vs_q($map['username']) : ($map['name'] ? vs_q($map['name']) : "''");
                        $find = $pdo->prepare(
                            'SELECT ' . $emailSel . ' AS e, ' . $nameSel . ' AS n FROM ' . vs_q($USERS_TABLE)
                            . ' WHERE ' . vs_q($map['id']) . ' = :i AND LOWER(TRIM(' . vs_q($map['role']) . ')) = :role LIMIT 1'
                        );
                        $find->execute([':i' => $ceoId, ':role' => mb_strtolower($ADMIN_ROLE)]);
                        $target = $find->fetch(PDO::FETCH_ASSOC);
                        if (!$target) {
                            $code = 'missing';
                        } elseif (strtolower(trim((string)$target['e'])) === strtolower(VS_OWNER_EMAIL)) {
                            $code = 'ceo_owner';
                        } else {
                            vs_set_ceo_id($ceoId, $makeCeo);
                            $code = $makeCeo ? 'ceo_on' : 'ceo_off';
                            $who  = trim((string)($target['n'] ?? ''));
                            vs_admin_log($pdo, 'user', ($makeCeo ? 'Gave the CEO title to ' : 'Removed the CEO title from ') . ($who !== '' ? '"' . mb_substr($who, 0, 60) . '"' : 'account #' . mb_substr($ceoId, 0, 20)));
                        }
                    } catch (Throwable $e) {
                        $code = 'failed';
                    }
                }
                header('Location: admins.php?msg=' . urlencode($code));
                exit;
            }

            // Delete an admin account. ONLY the owner email (VS_OWNER_EMAIL) may do this.
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_admin') {
                $code  = 'failed';
                $delId = trim((string)($_POST['id'] ?? ''));
                if (!hash_equals($adminCsrf, (string)($_POST['csrf'] ?? ''))) {
                    $code = 'expired';
                } elseif (!$isOwner) {
                    $code = 'denied';
                    vs_admin_log($pdo, 'user', 'Tried to delete admin account #' . mb_substr($delId, 0, 20) . ' (blocked: owner only)');
                    vs_owner_alert($pdo, 'blocked', 'Blocked: someone tried to delete an admin account', 'Only the owner can delete admins, so it was refused.');
                } elseif (!$map['id'] || $delId === '') {
                    $code = 'failed';
                } else {
                    try {
                        $emailSel = $map['email'] ? vs_q($map['email']) : "''";
                        $nameSel  = $map['username'] ? vs_q($map['username']) : ($map['name'] ? vs_q($map['name']) : "''");
                        $find = $pdo->prepare(
                            'SELECT ' . $emailSel . ' AS e, ' . $nameSel . ' AS n FROM ' . vs_q($USERS_TABLE)
                            . ' WHERE ' . vs_q($map['id']) . ' = :i AND LOWER(TRIM(' . vs_q($map['role']) . ')) = :role LIMIT 1'
                        );
                        $find->execute([':i' => $delId, ':role' => mb_strtolower($ADMIN_ROLE)]);
                        $target = $find->fetch(PDO::FETCH_ASSOC);
                        if (!$target) {
                            $code = 'missing';
                        } elseif (strtolower(trim((string)$target['e'])) === strtolower(VS_OWNER_EMAIL)) {
                            $code = 'protected';
                        } elseif ($myId !== null && (string)$myId === $delId) {
                            $code = 'self';
                        } else {
                            $del = $pdo->prepare(
                                'DELETE FROM ' . vs_q($USERS_TABLE)
                                . ' WHERE ' . vs_q($map['id']) . ' = :i AND LOWER(TRIM(' . vs_q($map['role']) . ')) = :role LIMIT 1'
                            );
                            // Tell the database lock (install-owner-lock.php) this delete is from the verified owner.
                            $pdo->exec('SET @vs_owner_ok = 1');
                            try {
                                $del->execute([':i' => $delId, ':role' => mb_strtolower($ADMIN_ROLE)]);
                            } finally {
                                $pdo->exec('SET @vs_owner_ok = 0');
                            }
                            $code = $del->rowCount() > 0 ? 'deleted' : 'missing';
                            if ($code === 'deleted') {
                                try {
                                    vs_set_ceo_id($delId, false); // a deleted account keeps no CEO title
                                } catch (Throwable $ceoError) {
                                    error_log('Could not clear CEO title: ' . $ceoError->getMessage());
                                }
                                $who = trim((string)($target['n'] ?? ''));
                                if ($who !== '') {
                                    // Remove the profile picture and free the name so it can be used again.
                                    $key = md5(mb_strtolower($who));
                                    foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
                                        $p = __DIR__ . '/../uploads/avatars/' . $key . '.' . $ext;
                                        if (is_file($p)) {
                                            @unlink($p);
                                        }
                                    }
                                    try {
                                        vs_release_username($pdo, $who, 'deleted_' . preg_replace('/\W/', '', $delId));
                                    } catch (Throwable $releaseError) {
                                        error_log('Could not free user name: ' . $releaseError->getMessage());
                                    }
                                }
                                vs_admin_log($pdo, 'user', 'Deleted admin account ' . ($who !== '' ? '"' . mb_substr($who, 0, 60) . '"' : '#' . mb_substr($delId, 0, 20)));
                            }
                        }
                    } catch (Throwable $e) {
                        $code = 'failed';
                    }
                }
                header('Location: admins.php?msg=' . urlencode($code));
                exit;
            }

            $select = [];
            foreach ($map as $alias => $col) {
                if ($col) {
                    $select[] = vs_q($col) . ' AS f_' . $alias;
                }
            }
            $order = $map['joined'] ? vs_q($map['joined']) . ' ASC' : ($map['id'] ? vs_q($map['id']) . ' ASC' : '1');
            try {
                // Only rows whose role is Admin are ever selected: regular users never reach this page.
                $stmt = $pdo->prepare(
                    'SELECT ' . implode(', ', $select) . ' FROM ' . vs_q($USERS_TABLE)
                    . ' WHERE LOWER(TRIM(' . vs_q($map['role']) . ')) = :role ORDER BY ' . $order
                );
                $stmt->execute([':role' => mb_strtolower($ADMIN_ROLE)]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $rows  = [];
                $fatal = 'Could not load admins: ' . $e->getMessage();
            }

            $ceoIds = vs_ceo_ids();
            foreach ($rows as $u) {
                $uname = trim((string)($u['f_username'] ?? ''));
                $full  = trim((string)($u['f_name'] ?? ''));
                $email = trim((string)($u['f_email'] ?? ''));
                $display = $full !== '' ? $full : ($uname !== '' ? $uname : ($email !== '' ? $email : 'Admin #' . ($u['f_id'] ?? '?')));

                $up = null;
                foreach ([$uname, $full] as $cand) {
                    $k = mb_strtolower(trim($cand));
                    if ($k !== '' && isset($byKey[$k])) {
                        $up = $byKey[$k];
                        break;
                    }
                }

                $avatarName = $uname !== '' ? $uname : $display;
                $stored     = vs_avatar_src($u['f_avatar'] ?? '', '../');
                $avatar     = avatar_url($avatarName, (string)($u['f_avatar'] ?? ''));
                if ($avatar === '') {
                    $avatar = $stored;
                }

                $isOnline = false;
                if (!empty($u['f_last_active']) && ($laTs = strtotime((string)$u['f_last_active']))) {
                    $isOnline = (time() - $laTs) <= 120;
                }

                $idStr = (string)($u['f_id'] ?? '');
                $isMe  = ($myId !== null && $idStr !== '' && (string)$myId === $idStr)
                      || ($myName !== '' && ($myName === mb_strtolower($uname) || $myName === mb_strtolower($full)));

                $admins[] = [
                    'id'       => $idStr,
                    'name'     => $display,
                    'username' => $uname,
                    'email'    => $email,
                    'avatar'   => $avatar,
                    'status'   => (string)($u['f_status'] ?? ''),
                    'joined'   => !empty($u['f_joined']) && strtotime((string)$u['f_joined']) ? date('M d, Y', strtotime((string)$u['f_joined'])) : '',
                    'last'     => !empty($u['f_last_active']) ? vs_ago((string)$u['f_last_active']) : '',
                    'online'   => $isOnline,
                    'videos'   => $up ? (int)$up['videos'] : 0,
                    'shorts'   => $up ? (int)$up['shorts'] : 0,
                    'views'    => $up ? (int)$up['views'] : 0,
                    'key'      => $up ? $up['key'] : '',
                    'is_me'    => $isMe,
                    'is_owner' => strtolower($email) === strtolower(VS_OWNER_EMAIL),
                    // The owner is always the CEO; the owner can also give the title to other admins.
                    'is_ceo'   => strtolower($email) === strtolower(VS_OWNER_EMAIL) || in_array($idStr, $ceoIds, true),
                ];
            }

            // Owner first, then any other CEOs, then the rest.
            usort($admins, function ($a, $b) {
                return [(int)$b['is_owner'], (int)$b['is_ceo']] <=> [(int)$a['is_owner'], (int)$a['is_ceo']];
            });

            $totalAdmins = count($admins);   // searching / filtering is done in the browser (see the script at the bottom)
        }
    }
}

$msgs = [
    'deleted'   => 'Admin account deleted.',
    'denied'    => 'Only the owner can delete admin accounts.',
    'protected' => 'The owner account cannot be deleted.',
    'self'      => 'You cannot delete your own account here.',
    'missing'   => 'That admin was not found.',
    'expired'   => 'Session expired. Try again.',
    'failed'    => 'Could not delete that admin.',
    'ceo_on'     => 'CEO title given.',
    'ceo_off'    => 'CEO title removed.',
    'denied_ceo' => 'Only the owner can give or remove the CEO title.',
    'ceo_owner'  => 'The owner is always the CEO.',
];
$flash = $msgs[$_GET['msg'] ?? ''] ?? '';
$flashOk = in_array($_GET['msg'] ?? '', ['deleted', 'ceo_on', 'ceo_off'], true);

// ---- numbers for the stat cards ----
$isActiveAdmin = static function (array $a): bool {
    $s = strtolower(trim((string)$a['status']));
    if ($s === '') {
        return true;
    }
    return !in_array($s, ['0', 'inactive', 'disabled', 'banned', 'suspended', 'blocked', 'pending', 'deleted', 'no', 'false', 'off'], true)
        && strpos($s, 'inactive') === false;
};
$cntActive = 0;
$cntCeo = 0;
$cntNew = 0;
$monthStart = strtotime(date('Y-m-01'));
foreach ($admins as $a) {
    if ($isActiveAdmin($a)) {
        $cntActive++;
    }
    if (!empty($a['is_ceo'])) {
        $cntCeo++;
    }
    if ($a['joined'] !== '' && strtotime($a['joined']) >= $monthStart) {
        $cntNew++;
    }
}
$cntInactive = max(0, $totalAdmins - $cntActive);
$pctActive = $totalAdmins > 0 ? (int)round($cntActive / $totalAdmins * 100) : 0;

/** Small decorative trend line for a stat card (a gentle wave, scaled by the value). */
function vs_va_spark(int $value, string $id, string $c1, string $c2): string
{
    $pts = [];
    $n = 8;
    for ($i = 0; $i < $n; $i++) {
        $wave = sin(($i + $value) * 1.15) * 0.55 + sin($i * 0.55 + $value) * 0.25;
        $trend = ($i / ($n - 1)) * 0.9;
        $pts[] = [4 + $i * (112 / ($n - 1)), 38 - max(0, min(1, 0.28 + $trend * 0.55 + $wave * 0.3)) * 32];
    }
    $line = 'M' . round($pts[0][0], 1) . ',' . round($pts[0][1], 1);
    for ($i = 0; $i < $n - 1; $i++) {
        $p0 = $pts[max(0, $i - 1)]; $p1 = $pts[$i]; $p2 = $pts[$i + 1]; $p3 = $pts[min($n - 1, $i + 2)];
        $line .= ' C' . round($p1[0] + ($p2[0] - $p0[0]) / 6, 1) . ',' . round($p1[1] + ($p2[1] - $p0[1]) / 6, 1)
              . ' ' . round($p2[0] - ($p3[0] - $p1[0]) / 6, 1) . ',' . round($p2[1] - ($p3[1] - $p1[1]) / 6, 1)
              . ' ' . round($p2[0], 1) . ',' . round($p2[1], 1);
    }
    $last = $pts[$n - 1];
    return '<svg class="va-spark" viewBox="0 0 120 44" preserveAspectRatio="none" aria-hidden="true"><defs>'
        . '<linearGradient id="' . $id . 's" x1="0" x2="1"><stop offset="0" stop-color="' . $c1 . '"/><stop offset="1" stop-color="' . $c2 . '"/></linearGradient>'
        . '<linearGradient id="' . $id . 'f" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $c2 . '" stop-opacity=".35"/><stop offset="1" stop-color="' . $c2 . '" stop-opacity="0"/></linearGradient></defs>'
        . '<path d="' . $line . ' L116,44 L4,44 Z" fill="url(#' . $id . 'f)"/>'
        . '<path d="' . $line . '" fill="none" stroke="url(#' . $id . 's)" stroke-width="2" stroke-linecap="round" vector-effect="non-scaling-stroke"/>'
        . '<circle cx="' . round($last[0], 1) . '" cy="' . round($last[1], 1) . '" r="2.6" fill="' . $c2 . '"/></svg>';
}

$crownSvg = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 8l4.5 4L12 5l4.5 7L21 8l-1.6 10H4.6L3 8zm1.8 12h14.4v1.6H4.8V20z"/></svg>';

include "header.php";
vs_print_styles();
?>
<style>
  .vs-admins-page {
    --va-text: #eef0ff; --va-muted: #9aa3d0; --va-line: rgba(139,92,246,.32); --va-line-soft: rgba(139,92,246,.16);
    --va-card: linear-gradient(160deg, rgba(18,20,64,.92), rgba(9,11,40,.96)); --va-field: rgba(10,12,42,.85); --va-head: rgba(24,26,78,.6);
    --va-hover: rgba(124,58,237,.12); --va-glow: rgba(124,58,237,.35); color: var(--va-text);
  }
  .vs-admins-page .container-fluid { max-width: 1700px; }

  /* ===== banner ===== */
  .va-hero { position: relative; overflow: hidden; display: flex; flex-wrap: wrap; align-items: center; gap: 18px 28px; min-height: 148px; padding: 20px 34px; border-radius: 16px; border: 1px solid rgba(168,85,247,.75);
    background: radial-gradient(560px 220px at 82% 50%, rgba(147,51,234,.42), transparent 70%), linear-gradient(105deg, #0f0636 0%, #1a0b55 55%, #2a0c78 100%);
    box-shadow: 0 0 32px rgba(124,58,237,.3), inset 0 1px rgba(255,255,255,.08); color: #fff; }
  .va-waves { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
  .va-hero-shield, .va-hero-copy, .va-hero-right { position: relative; z-index: 1; }
  .va-hero-shield svg { display: block; width: 84px; height: 96px; filter: drop-shadow(0 0 16px rgba(168,85,247,.8)); }
  .va-hero-copy { flex: 1 1 280px; min-width: 0; }
  .va-hero h1 { margin: 0; font-size: clamp(30px, 3.6vw, 46px); line-height: 1.1; font-weight: 800; letter-spacing: -.02em; color: #fff; }
  .va-hero h1 span { background: linear-gradient(90deg, #c026d3, #e879f9); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; filter: drop-shadow(0 0 14px rgba(217,70,239,.5)); }
  .va-hero p { margin: 6px 0 0; font-size: 17px; color: #d8d3ff; }
  .va-crown-art { position: absolute; right: 190px; bottom: -26px; width: 210px; height: 190px; color: #b36bff; opacity: .9; pointer-events: none; filter: drop-shadow(0 0 22px rgba(168,85,247,.85)); }
  .va-hero-right { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 10px; margin-left: auto; }
  .va-count { display: inline-flex; align-items: center; gap: 10px; padding: 12px 20px; border-radius: 999px; border: 1px solid rgba(192,132,252,.6); background: rgba(10,6,36,.55); backdrop-filter: blur(6px); color: #fff; font-weight: 700; white-space: nowrap; }
  .va-count i { font-size: 1.15rem; }
  .va-ghost { display: inline-flex; align-items: center; gap: 7px; height: 38px; padding: 0 14px; border-radius: 999px; border: 1px solid rgba(192,132,252,.4); background: rgba(10,6,36,.4); color: #e9defd; font-size: 13px; font-weight: 600; text-decoration: none; transition: border-color .15s, box-shadow .15s; }
  .va-ghost:hover { border-color: #e879f9; box-shadow: 0 0 16px rgba(217,70,239,.45); color: #fff; }
  .va-ghost.danger:hover { border-color: #ff5a7a; box-shadow: 0 0 16px rgba(255,70,110,.45); }

  /* ===== stat cards ===== */
  .va-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin: 18px 0; }
  .va-stat { position: relative; overflow: hidden; display: flex; align-items: center; gap: 16px; padding: 18px 20px; border-radius: 14px; border: 1px solid var(--va-line); background: var(--va-card); box-shadow: inset 0 1px rgba(255,255,255,.04); transition: transform .18s, border-color .18s, box-shadow .18s; }
  .va-stat:hover { transform: translateY(-3px); border-color: rgba(192,132,252,.75); box-shadow: 0 0 24px var(--va-glow); }
  .va-stat-ico { display: grid; place-items: center; width: 54px; height: 54px; flex: 0 0 54px; font-size: 2rem; color: #a78bfa; filter: drop-shadow(0 0 10px rgba(124,58,237,.7)); }
  .va-stat.ceo .va-stat-ico { color: #c084fc; }
  .va-stat.act .va-stat-ico { color: #8b5cf6; }
  .va-stat.off .va-stat-ico { color: #5b8cff; filter: drop-shadow(0 0 10px rgba(59,130,246,.7)); }
  .va-stat-text { position: relative; z-index: 1; min-width: 0; }
  .va-stat-text small { display: block; font-size: 15px; font-weight: 600; color: var(--va-text); }
  .va-stat-text b { display: inline-block; margin-top: 2px; font-size: 34px; line-height: 1.1; font-weight: 800; color: var(--va-text); font-variant-numeric: tabular-nums; }
  .va-trend { margin-left: 8px; font-size: 15px; font-weight: 700; color: #22e58b; }
  .va-spark { position: absolute; right: 14px; bottom: 12px; width: 44%; height: 52px; opacity: .95; pointer-events: none; }

  /* ===== toolbar ===== */
  .va-toolbar { display: flex; flex-wrap: wrap; gap: 14px; margin: 0 0 16px; }
  .va-search { position: relative; flex: 1 1 320px; min-width: 0; }
  .va-search i { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: #a78bfa; font-size: 1.1rem; pointer-events: none; }
  .va-field { width: 100%; height: 54px; border-radius: 14px; border: 1px solid var(--va-line); background: var(--va-field); color: var(--va-text); font-size: 16px; outline: 0; transition: border-color .15s, box-shadow .15s; }
  .va-search .va-field { padding: 0 18px 0 50px; }
  select.va-field { width: 190px; padding: 0 40px 0 18px; appearance: none; -webkit-appearance: none; cursor: pointer;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23a78bfa' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 16px center; }
  select.va-field option { background: #0c0f2f; color: #eef0ff; }
  .va-field:focus { border-color: #c084fc; box-shadow: 0 0 0 1px #c084fc, 0 0 18px rgba(168,85,247,.45); }
  .va-field::placeholder { color: var(--va-muted); }
  .va-filter-btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; height: 54px; padding: 0 30px; border-radius: 14px; border: 1px solid #a855f7; background: rgba(124,58,237,.14); color: var(--va-text); font-size: 16px; font-weight: 700; cursor: pointer; transition: box-shadow .15s, background .15s; }
  .va-filter-btn:hover { background: rgba(124,58,237,.3); box-shadow: 0 0 20px rgba(168,85,247,.5); }

  /* ===== table ===== */
  .va-panel { border-radius: 16px; border: 1px solid var(--va-line); background: var(--va-card); box-shadow: 0 14px 42px rgba(3,5,24,.28); overflow: hidden; }
  .va-scroll { overflow-x: auto; }
  .va-table { width: 100%; min-width: 1060px; border-collapse: collapse; }
  .va-table th { padding: 18px 14px; background: var(--va-head); border-bottom: 1px solid var(--va-line); color: var(--va-text); font-size: 15px; font-weight: 700; text-align: left; white-space: nowrap; }
  .va-table td { padding: 12px 14px; border-bottom: 1px solid var(--va-line-soft); color: var(--va-text); font-size: 15px; vertical-align: middle; white-space: nowrap; }
  .va-table tbody tr { transition: background .15s; }
  .va-table tbody tr:hover { background: var(--va-hover); }
  .va-table tbody tr:last-child td { border-bottom: 0; }
  .va-table .num { font-weight: 800; }
  .va-table .muted { color: var(--va-muted); }
  .va-table .mail { color: var(--va-text); }
  .va-name { display: inline-flex; align-items: center; gap: 8px; }
  .va-you { padding: 1px 8px; border-radius: 999px; background: rgba(139,92,246,.25); color: #d8b4fe; font-size: 11px; font-weight: 700; }
  .va-av { display: inline-flex; }
  .va-av > * { width: 40px; height: 40px; border-radius: 50%; }
  .va-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 999px; font-size: 13px; font-weight: 600; line-height: 1.3; border: 1px solid transparent; }
  .va-badge svg { width: 13px; height: 13px; }
  .va-badge.role { background: rgba(124,58,237,.28); border-color: rgba(168,85,247,.55); color: #e6d6ff; }
  .va-badge.owner { background: rgba(245,158,11,.16); border-color: rgba(245,158,11,.55); color: #fcd34d; }
  .va-badge.on { background: rgba(34,229,139,.14); border-color: rgba(34,229,139,.5); color: #4ff0a4; }
  .va-badge.on::before, .va-badge.off::before { content: ""; width: 12px; height: 12px; border-radius: 50%; background: #1fd47e; box-shadow: 0 0 8px #1fd47e; }
  .va-badge.off { background: rgba(148,163,184,.14); border-color: rgba(148,163,184,.4); color: #cbd5e1; }
  .va-badge.off::before { background: #94a3b8; box-shadow: none; }
  .va-badge.yes { background: rgba(245,158,11,.16); border-color: rgba(245,158,11,.6); color: #fcd34d; }
  .va-badge.no { background: rgba(148,163,184,.12); border-color: rgba(148,163,184,.25); color: var(--va-muted); }
  .va-dot { display: inline-block; width: 8px; height: 8px; margin-right: 6px; border-radius: 50%; background: #64748b; }
  .va-dot.live { background: #2ecc71; box-shadow: 0 0 8px #2ecc71; }
  .va-actions { display: flex; align-items: center; gap: 8px; }
  .va-actions form { margin: 0; }
  .va-ib { display: grid; place-items: center; width: 38px; height: 38px; border: 0; border-radius: 10px; color: #fff; font-size: 1rem; text-decoration: none; cursor: pointer; transition: transform .15s, box-shadow .15s, filter .15s; }
  .va-ib:hover { transform: translateY(-2px); filter: brightness(1.12); color: #fff; }
  .va-ib.edit { background: linear-gradient(135deg, #7c3aed, #5b3fe8); box-shadow: 0 0 14px rgba(124,58,237,.55); }
  .va-ib.del { background: linear-gradient(135deg, #ef2b3d, #d11a2a); box-shadow: 0 0 14px rgba(239,43,61,.5); }
  .va-ib.ceo { background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 0 14px rgba(245,158,11,.45); }
  .va-ib.ceo svg { width: 17px; height: 17px; }
  .va-ib.up { background: linear-gradient(135deg, #0ea5e9, #2563eb); box-shadow: 0 0 14px rgba(14,165,233,.45); }
  .va-ib[disabled] { opacity: .3; cursor: not-allowed; box-shadow: none; transform: none; filter: none; }
  .va-empty td { padding: 40px 14px; text-align: center; color: var(--va-muted); }

  .va-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 20px; border-top: 1px solid var(--va-line); }
  .va-foot span { color: var(--va-text); font-size: 15px; }
  .va-pager { display: inline-flex; gap: 6px; }
  .va-pager button { display: grid; place-items: center; min-width: 38px; height: 38px; padding: 0 8px; border: 1px solid var(--va-line); border-radius: 8px; background: rgba(124,58,237,.1); color: var(--va-text); font: inherit; font-weight: 700; cursor: pointer; }
  .va-pager button:hover:not(:disabled) { background: rgba(124,58,237,.3); }
  .va-pager button:disabled { opacity: .35; cursor: default; }
  .va-pager button.on { border-color: transparent; background: linear-gradient(135deg, #c026d3, #7c3aed); color: #fff; box-shadow: 0 0 14px rgba(192,38,211,.55); }
  .va-flash { margin: 0 0 16px; padding: 14px 20px; border-radius: 12px; font-weight: 600; }
  .va-flash.ok { background: rgba(34,229,139,.12); border: 1px solid rgba(34,229,139,.4); color: #4ff0a4; }
  .va-flash.bad { background: rgba(255,90,122,.12); border: 1px solid rgba(255,90,122,.4); color: #ff9db1; }

  @media (max-width: 1280px) { .va-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } .va-crown-art { display: none; } }
  @media (max-width: 760px) {
    .va-hero { padding: 16px; } .va-hero-shield svg { width: 56px; height: 64px; } .va-hero-right { margin-left: 0; justify-content: flex-start; }
    .va-stats { grid-template-columns: 1fr; } select.va-field { flex: 1 1 140px; width: auto; } .va-filter-btn { flex: 1 1 100%; }
  }

  /* ===== user cards ===== */
  [hidden] { display: none !important; }
  .va-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 18px; padding: 20px; }
  .va-card { position: relative; display: flex; flex-direction: column; gap: 14px; padding: 20px; border-radius: 16px; border: 1px solid var(--va-line); background: var(--va-card); box-shadow: inset 0 1px rgba(255,255,255,.04); transition: transform .18s, border-color .18s, box-shadow .18s; }
  .va-card:hover { transform: translateY(-4px); border-color: rgba(192,132,252,.75); box-shadow: 0 0 26px var(--va-glow); }
  .va-card.is-owner { border-color: rgba(245,158,11,.6); box-shadow: 0 0 22px rgba(245,158,11,.18), inset 0 1px rgba(255,255,255,.04); }
  .va-card.is-owner:hover { border-color: rgba(251,191,36,.9); box-shadow: 0 0 28px rgba(245,158,11,.4); }
  .va-card.is-ceo { border-color: rgba(192,132,252,.6); }
  .va-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
  .va-card .va-av > * { width: 68px; height: 68px; border-radius: 50%; box-shadow: 0 0 0 2px rgba(168,85,247,.55), 0 0 18px rgba(124,58,237,.45); }
  .va-card.is-owner .va-av > * { box-shadow: 0 0 0 2px rgba(245,158,11,.7), 0 0 18px rgba(245,158,11,.4); }
  .va-card-state { display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px; border-radius: 999px; font-size: 12px; font-weight: 700; border: 1px solid transparent; }
  .va-card-state::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: currentColor; box-shadow: 0 0 8px currentColor; }
  .va-card-state.on { background: rgba(34,229,139,.14); border-color: rgba(34,229,139,.5); color: #4ff0a4; }
  .va-card-state.off { background: rgba(148,163,184,.14); border-color: rgba(148,163,184,.4); color: #cbd5e1; }
  .va-card-state.off::before { box-shadow: none; }
  .va-card-id { min-width: 0; }
  .va-card-name { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin: 0; font-size: 19px; font-weight: 800; line-height: 1.25; color: var(--va-text); word-break: break-word; }
  .va-live-dot { flex: 0 0 auto; width: 9px; height: 9px; border-radius: 50%; background: #64748b; }
  .va-live-dot.live { background: #2ecc71; box-shadow: 0 0 8px #2ecc71; }
  .va-card-user { margin: 2px 0 0; font-size: 14px; color: #c4b5fd; }
  .va-card-mail { margin: 6px 0 0; font-size: 14px; color: var(--va-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .va-card-mail i { margin-right: 4px; color: #a78bfa; }
  .va-card-badges { display: flex; flex-wrap: wrap; gap: 8px; }
  .va-card-meta { display: grid; gap: 8px; margin: 0; padding: 12px 14px; border-radius: 12px; border: 1px solid var(--va-line-soft); background: var(--va-field); }
  .va-card-meta > div { display: flex; justify-content: space-between; gap: 12px; font-size: 13.5px; }
  .va-card-meta dt { margin: 0; font-weight: 600; color: var(--va-muted); }
  .va-card-meta dd { margin: 0; text-align: right; font-weight: 600; color: var(--va-text); }
  .va-card .va-actions { margin-top: auto; padding-top: 2px; }
  .va-empty-card { grid-column: 1 / -1; padding: 40px 14px; text-align: center; color: var(--va-muted); }
  html[data-theme="light"] .va-card-state.on { color: #0a8a52; }
  html[data-theme="light"] .va-card-state.off { color: #475569; }
  html[data-theme="light"] .va-card-user { color: #6d28d9; }
  @media (max-width: 760px) { .va-grid { grid-template-columns: 1fr; padding: 14px; } }

  /* ===== light theme ===== */
  html[data-theme="light"] .vs-admins-page {
    --va-text: #1d1544; --va-muted: #5f6690; --va-line: rgba(124,77,255,.32); --va-line-soft: rgba(124,77,255,.14);
    --va-card: linear-gradient(160deg, #ffffff, #f8f5ff); --va-field: #ffffff; --va-head: rgba(124,77,255,.08); --va-hover: rgba(124,77,255,.07); --va-glow: rgba(124,77,255,.28);
  }
  html[data-theme="light"] .va-badge.role { color: #5b21b6; }
  html[data-theme="light"] .va-badge.on { color: #0a8a52; }
  html[data-theme="light"] .va-badge.owner, html[data-theme="light"] .va-badge.yes { color: #a16207; }
  html[data-theme="light"] .va-badge.off { color: #475569; }
  html[data-theme="light"] .va-trend { color: #0a8a52; }
  html[data-theme="light"] select.va-field option { background: #fff; color: #1d1544; }
  html[data-theme="light"] .va-you { color: #5b21b6; }
  html[data-theme="light"] .va-flash.ok { color: #0a8a52; }
  html[data-theme="light"] .va-flash.bad { color: #b4233f; }
</style>

<main class="dashboard-content vs-admins-page">
  <div class="container-fluid px-3 px-lg-4 py-4">

    <section class="va-hero">
      <svg class="va-waves" viewBox="0 0 1400 150" preserveAspectRatio="none" aria-hidden="true">
        <defs><linearGradient id="vaw" x1="0" x2="1"><stop offset="0" stop-color="#6d28d9" stop-opacity="0"/><stop offset=".6" stop-color="#a855f7"/><stop offset="1" stop-color="#e879f9"/></linearGradient></defs>
        <path d="M0 125 C260 70 520 150 800 100 S1150 30 1400 105" fill="none" stroke="url(#vaw)" stroke-width="1.6" opacity=".75"/>
        <path d="M0 95 C240 130 560 20 860 75 S1200 120 1400 40" fill="none" stroke="url(#vaw)" stroke-width="1" opacity=".5"/>
      </svg>
      <div class="va-hero-shield" aria-hidden="true">
        <svg viewBox="0 0 84 96" fill="none">
          <defs><linearGradient id="vaSh" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#a78bfa"/><stop offset="1" stop-color="#6d28d9"/></linearGradient></defs>
          <path d="M42 3 78 16v30c0 22-15 38-36 47C21 84 6 68 6 46V16L42 3z" fill="url(#vaSh)" stroke="#e9d5ff" stroke-width="2.5" stroke-linejoin="round"/>
          <path d="M22 36l9 8 11-14 11 14 9-8-3 20H25l-3-20zm3 24h34v5H25z" fill="#fff"/>
        </svg>
      </div>
      <div class="va-hero-copy">
        <h1>Admins <span>Management</span></h1>
        <p>View and manage all admin accounts.</p>
      </div>
      <div class="va-crown-art" aria-hidden="true"><?= $crownSvg ?></div>
      <div class="va-hero-right">
        <a class="va-ghost" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Dashboard</a>
        <a class="va-ghost" href="Users.php"><i class="bi bi-people" aria-hidden="true"></i> All Users</a>
        <?php if ($isOwner): ?><a class="va-ghost danger" href="free-name.php"><i class="bi bi-person-x" aria-hidden="true"></i> Delete names</a><?php endif; ?>
        <span class="va-count"><i class="bi bi-people-fill" aria-hidden="true"></i> Total Admins: <?php echo (int)$totalAdmins; ?></span>
      </div>
    </section>

    <?php if ($flash !== ''): ?>
      <div class="va-flash <?php echo $flashOk ? 'ok' : 'bad'; ?>" role="status" style="margin-top:16px"><?php echo vs_e($flash); ?></div>
    <?php endif; ?>

    <?php if ($fatal !== ''): ?>
      <div class="alert alert-warning mt-3"><?php echo vs_e($fatal); ?></div>
    <?php else: ?>

    <section class="va-stats" aria-label="Admin numbers">
      <article class="va-stat">
        <span class="va-stat-ico"><i class="bi bi-people-fill" aria-hidden="true"></i></span>
        <div class="va-stat-text"><small>Total Admins</small><b><?php echo (int)$totalAdmins; ?></b><?php if ($cntNew > 0): ?><span class="va-trend">&uarr; <?php echo (int)$cntNew; ?></span><?php endif; ?></div>
        <?php echo vs_va_spark($totalAdmins, 'vsa1', '#6d5bff', '#a78bfa'); ?>
      </article>
      <article class="va-stat ceo">
        <span class="va-stat-ico"><i class="bi bi-star-fill" aria-hidden="true"></i></span>
        <div class="va-stat-text"><small>CEO</small><b><?php echo (int)$cntCeo; ?></b></div>
        <?php echo vs_va_spark($cntCeo + 3, 'vsa2', '#7c3aed', '#c084fc'); ?>
      </article>
      <article class="va-stat act">
        <span class="va-stat-ico"><i class="bi bi-shield-fill-check" aria-hidden="true"></i></span>
        <div class="va-stat-text"><small>Active</small><b><?php echo (int)$cntActive; ?></b><?php if ($totalAdmins > 0): ?><span class="va-trend">&uarr; <?php echo (int)$pctActive; ?>%</span><?php endif; ?></div>
        <?php echo vs_va_spark($cntActive + 5, 'vsa3', '#6d5bff', '#c084fc'); ?>
      </article>
      <article class="va-stat off">
        <span class="va-stat-ico"><i class="bi bi-power" aria-hidden="true"></i></span>
        <div class="va-stat-text"><small>Inactive</small><b><?php echo (int)$cntInactive; ?></b></div>
        <?php echo vs_va_spark($cntInactive + 8, 'vsa4', '#3b82f6', '#a78bfa'); ?>
      </article>
    </section>

    <div class="va-toolbar">
      <label class="va-search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input class="va-field" type="search" id="vaFind" value="<?php echo vs_e($q); ?>" placeholder="Search admins by name or email..." aria-label="Search admins by name or email" autocomplete="off">
      </label>
      <select class="va-field" id="vaRole" aria-label="Filter by role">
        <option value="">All Roles</option><option value="owner">Owner</option><option value="admin">Admin</option><option value="ceo">CEO</option>
      </select>
      <select class="va-field" id="vaStatus" aria-label="Filter by status">
        <option value="">All Status</option><option value="active">Active</option><option value="inactive">Inactive</option>
      </select>
      <button type="button" class="va-filter-btn" id="vaApply"><i class="bi bi-funnel-fill" aria-hidden="true"></i> Filter</button>
    </div>

    <section class="va-panel" aria-label="Admin accounts">
      <div class="va-grid" id="vaGrid">
      <?php if (!$admins): ?>
        <div class="va-empty-card">No admin accounts found.</div>
      <?php else: foreach ($admins as $i => $a):
          $active = $isActiveAdmin($a);
          [$statusLabel, $statusClass] = vs_status($a['status']);
          $editHref = $a['is_me'] ? 'profile.php' : ($a['id'] !== '' ? 'user_profile.php?id=' . urlencode($a['id']) : '');
          $roleKey = $a['is_owner'] ? 'owner' : 'admin';
      ?>
        <article class="va-card<?php echo $a['is_owner'] ? ' is-owner' : ($a['is_ceo'] ? ' is-ceo' : ''); ?>" data-row
            data-text="<?php echo vs_e(mb_strtolower($a['name'] . ' ' . $a['username'] . ' ' . $a['email'])); ?>"
            data-role="<?php echo $roleKey; ?>" data-ceo="<?php echo $a['is_ceo'] ? '1' : '0'; ?>" data-status="<?php echo $active ? 'active' : 'inactive'; ?>">
          <div class="va-card-top">
            <span class="va-av"><?php echo vs_avatar($a['name'], $a['avatar']); ?></span>
            <span class="va-card-state <?php echo $active ? 'on' : 'off'; ?>"><?php echo $active ? 'Active' : vs_e($statusLabel !== '' ? $statusLabel : 'Inactive'); ?></span>
          </div>
          <div class="va-card-id">
            <h3 class="va-card-name"><span class="va-live-dot<?php echo $a['online'] ? ' live' : ''; ?>" title="<?php echo $a['online'] ? 'Online now' : 'Offline'; ?>"></span><?php echo vs_e($a['name']); ?><?php if ($a['is_me']): ?><span class="va-you">You</span><?php endif; ?></h3>
            <?php if ($a['username'] !== ''): ?><p class="va-card-user">@<?php echo vs_e($a['username']); ?></p><?php endif; ?>
            <?php if ($a['email'] !== ''): ?><p class="va-card-mail" title="<?php echo vs_e($a['email']); ?>"><i class="bi bi-envelope" aria-hidden="true"></i> <?php echo vs_e($a['email']); ?></p><?php endif; ?>
          </div>
          <div class="va-card-badges">
            <?php if ($a['is_owner']): ?><span class="va-badge owner"><?= $crownSvg ?>Owner</span><?php else: ?><span class="va-badge role"><i class="bi bi-gem" aria-hidden="true"></i>Admin</span><?php endif; ?>
            <?php if ($a['is_ceo']): ?><span class="va-badge yes"><?= $crownSvg ?>CEO</span><?php endif; ?>
          </div>
          <dl class="va-card-meta">
            <div><dt>Joined</dt><dd><?php echo $a['joined'] !== '' ? vs_e($a['joined']) : '&mdash;'; ?></dd></div>
            <div><dt>Last active</dt><dd><?php echo $a['last'] !== '' ? vs_e($a['last']) : '&mdash;'; ?></dd></div>
            <div><dt>Uploads</dt><dd><?php echo (int)$a['videos']; ?> videos &middot; <?php echo (int)$a['shorts']; ?> shorts</dd></div>
          </dl>
          <div class="va-actions">
            <?php if ($a['key'] !== '' && ($a['videos'] + $a['shorts']) > 0): ?>
              <a class="va-ib up" href="videos.php?uploader=<?php echo urlencode($a['key']); ?>" title="View uploads (<?php echo (int)$a['videos']; ?> videos, <?php echo (int)$a['shorts']; ?> shorts)" aria-label="View uploads"><i class="bi bi-collection-play-fill" aria-hidden="true"></i></a>
            <?php endif; ?>
            <?php if ($editHref !== ''): ?>
              <a class="va-ib edit" href="<?php echo vs_e($editHref); ?>" title="<?php echo $a['is_me'] ? 'My profile' : 'Update admin'; ?>" aria-label="<?php echo $a['is_me'] ? 'My profile' : 'Update admin'; ?>"><i class="bi bi-pencil-square" aria-hidden="true"></i></a>
            <?php endif; ?>
            <?php if ($isOwner && !$a['is_owner'] && $a['id'] !== ''): ?>
              <form method="post" action="admins.php">
                <input type="hidden" name="csrf" value="<?php echo vs_e($adminCsrf); ?>">
                <input type="hidden" name="action" value="set_ceo">
                <input type="hidden" name="id" value="<?php echo vs_e($a['id']); ?>">
                <input type="hidden" name="value" value="<?php echo $a['is_ceo'] ? '0' : '1'; ?>">
                <button type="submit" class="va-ib ceo" title="<?php echo $a['is_ceo'] ? 'Remove CEO title' : 'Make CEO'; ?>" aria-label="<?php echo $a['is_ceo'] ? 'Remove CEO title' : 'Make CEO'; ?>"><?= $crownSvg ?></button>
              </form>
            <?php endif; ?>
            <?php if ($isOwner && !$a['is_me'] && !$a['is_owner'] && $a['id'] !== ''): ?>
              <form method="post" action="admins.php" onsubmit="return confirm('Delete this admin account? This cannot be undone.');">
                <input type="hidden" name="csrf" value="<?php echo vs_e($adminCsrf); ?>">
                <input type="hidden" name="action" value="delete_admin">
                <input type="hidden" name="id" value="<?php echo vs_e($a['id']); ?>">
                <button type="submit" class="va-ib del" title="Delete admin" aria-label="Delete admin"><i class="bi bi-trash3-fill" aria-hidden="true"></i></button>
              </form>
            <?php else: ?>
              <button type="button" class="va-ib del" disabled title="<?php echo $a['is_owner'] ? 'The owner account cannot be deleted' : ($a['is_me'] ? 'You cannot delete your own account here' : 'Only the owner can delete admins'); ?>" aria-label="Delete not allowed"><i class="bi bi-trash3-fill" aria-hidden="true"></i></button>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; endif; ?>
        <div class="va-empty-card" id="vaNone" hidden>No admins match your search.</div>
      </div>
      <div class="va-foot">
        <span id="vaCount">Showing 0 of <?php echo (int)$totalAdmins; ?> admins</span>
        <div class="va-pager" id="vaPager" aria-label="Pages"></div>
      </div>
    </section>
    <?php endif; ?>
  </div>
</main>

<footer class="admin-footer">
  <div class="container-fluid px-3 px-lg-4" style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px">
    <span><strong style="background:linear-gradient(90deg,#c026d3,#a78bfa);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent">VibeStream</strong> Admin Panel</span>
    <span style="opacity:.7">Manage &bull; Monitor &bull; Grow</span>
  </div>
</footer>
</div>
</div>

<script src="./assets/js/bootstrap.bundle.min.js"></script>
<script src="./assets/js/main.js"></script>
<script>
(function () {
  var rows = Array.prototype.slice.call(document.querySelectorAll('#vaGrid [data-row]'));
  if (!rows.length) return;
  var find = document.getElementById('vaFind'), role = document.getElementById('vaRole'), status = document.getElementById('vaStatus');
  var none = document.getElementById('vaNone'), count = document.getElementById('vaCount'), pager = document.getElementById('vaPager');
  var PER = 12, page = 1;

  function matches() {
    var q = (find.value || '').toLowerCase().trim(), r = role.value, s = status.value;
    return rows.filter(function (tr) {
      if (q && tr.getAttribute('data-text').indexOf(q) === -1) return false;
      if (r === 'ceo' ? tr.getAttribute('data-ceo') !== '1' : (r && tr.getAttribute('data-role') !== r)) return false;
      if (s && tr.getAttribute('data-status') !== s) return false;
      return true;
    });
  }
  function pages(total, cur) {
    var out = [], i;
    if (total <= 7) { for (i = 1; i <= total; i++) out.push(i); return out; }
    out.push(1); if (cur > 3) out.push('...');
    for (i = Math.max(2, cur - 1); i <= Math.min(total - 1, cur + 1); i++) out.push(i);
    if (cur < total - 2) out.push('...'); out.push(total);
    return out;
  }
  function render() {
    var list = matches(), total = Math.max(1, Math.ceil(list.length / PER));
    if (page > total) page = total;
    var from = (page - 1) * PER, to = Math.min(from + PER, list.length);
    rows.forEach(function (tr) { tr.hidden = true; });
    list.slice(from, to).forEach(function (tr) { tr.hidden = false; });
    if (none) none.hidden = list.length !== 0;
    count.textContent = list.length ? 'Showing ' + (from + 1) + ' to ' + to + ' of ' + list.length + ' admins' : 'Showing 0 of ' + rows.length + ' admins';
    var html = '';
    if (total > 1) {
      html += '<button type="button" data-p="' + (page - 1) + '" aria-label="Previous page"' + (page === 1 ? ' disabled' : '') + '>&lsaquo;</button>';
      pages(total, page).forEach(function (p) {
        html += p === '...' ? '<button type="button" disabled>&hellip;</button>' : '<button type="button" data-p="' + p + '"' + (p === page ? ' class="on" aria-current="page"' : '') + '>' + p + '</button>';
      });
      html += '<button type="button" data-p="' + (page + 1) + '" aria-label="Next page"' + (page === total ? ' disabled' : '') + '>&rsaquo;</button>';
    } else {
      html = '<button type="button" class="on" aria-current="page">1</button><button type="button" disabled aria-label="Previous page">&lsaquo;</button><button type="button" disabled aria-label="Next page">&rsaquo;</button>';
    }
    pager.innerHTML = html;
  }
  pager.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-p]'); if (!b || b.disabled) return;
    page = parseInt(b.getAttribute('data-p'), 10) || 1; render();
  });
  function reset() { page = 1; render(); }
  find.addEventListener('input', reset); role.addEventListener('change', reset); status.addEventListener('change', reset);
  document.getElementById('vaApply').addEventListener('click', reset);
  render();
})();
</script>
</body>
</html>