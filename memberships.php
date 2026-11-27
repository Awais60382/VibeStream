<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/premium-store.php';
require_once __DIR__ . '/membership-store.php';
require_once __DIR__ . '/vip-badge.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$username = trim((string)($_SESSION['username'] ?? ''));
$creator = trim((string)($_GET['creator'] ?? $username));
$message = '';
$error = '';
$tiers = [];
$membership = null;
$tierCounts = [];
$totalMembers = 0;
$hasPremium = false;
$myPoints = 0;
$pointsKnown = false;
$pendingRequests = [];
$myRequest = null;
$browse = isset($_GET['browse']);
$browseRows = [];

if (empty($_SESSION['membership_csrf'])) {
    $_SESSION['membership_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['membership_csrf'];

try {
    vs_membership_ensure($pdo);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($username !== '') {
        $hasPremium = vs_premium_is_admin($username);
        if (!$hasPremium) {
            vs_premium_ensure($pdo);
            $hasPremium = vs_premium_is_active(vs_premium_get($pdo, $username));
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($username === '') {
            throw new RuntimeException('Sign in to manage memberships.');
        }
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Your session expired. Refresh and try again.');
        }
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_tier' && strcasecmp($creator, $username) === 0) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0 && !$hasPremium) {
                throw new RuntimeException('VibeStream Premium is required to create channel membership tiers.');
            }
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
            $price = max(0, min(999999.99, (float)($_POST['price'] ?? 0)));
            $benefits = mb_substr(trim((string)($_POST['benefits'] ?? '')), 0, 1000);
            $needsApproval = !empty($_POST['requires_approval']) ? 1 : 0;   // approval tiers are free: the creator accepts or declines each person
            if ($name === '') {
                throw new RuntimeException('Tier name is required.');
            }
            if ($id > 0) {
                $existing = $pdo->prepare('SELECT active FROM channel_membership_tiers WHERE id = ? AND creator = ? LIMIT 1');
                $existing->execute([$id, $username]);
                $wasActive = $existing->fetchColumn();
                if ($wasActive === false) {
                    throw new RuntimeException('Membership tier is invalid.');
                }
                if (!(bool)$wasActive && !$hasPremium) {
                    throw new RuntimeException('VibeStream Premium is required to publish channel membership tiers.');
                }
                $pdo->prepare('UPDATE channel_membership_tiers SET name = ?, price = ?, benefits = ?, requires_approval = ?, active = 1 WHERE id = ? AND creator = ?')
                    ->execute([$name, $price, $benefits, $needsApproval, $id, $username]);
            } else {
                $pdo->prepare('INSERT INTO channel_membership_tiers (creator, name, price, benefits, requires_approval) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$username, $name, $price, $benefits, $needsApproval]);
            }
            $message = 'Membership tier saved.';
        } elseif ($action === 'delete_tier' && strcasecmp($creator, $username) === 0) {
            // "Delete" only unpublishes the tier: it disappears from the page, existing members keep their row.
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Membership tier is invalid.');
            }
            $pdo->prepare('UPDATE channel_membership_tiers SET active = 0 WHERE id = ? AND creator = ?')
                ->execute([$id, $username]);
            $message = 'Membership tier unpublished.';
        } elseif ($action === 'join' && $creator !== '' && strcasecmp($creator, $username) !== 0) {
            if (!$hasPremium) {
                throw new RuntimeException('VibeStream Premium is required to join channel memberships.');
            }
            // Spends the member's Rewards points and saves the membership in one transaction.
            $joined = vs_membership_join_with_points($pdo, $creator, $username, (int)($_POST['tier_id'] ?? 0));
            $message = $joined['cost'] > 0
                ? 'You joined ' . $joined['tier'] . ' for ' . $joined['days'] . ' days. ' . number_format($joined['cost']) . ' points spent, ' . number_format($joined['balance']) . ' left.'
                : 'You joined ' . $joined['tier'] . ' for ' . $joined['days'] . ' days.';
        } elseif ($action === 'cancel' && $creator !== '' && strcasecmp($creator, $username) !== 0) {
            $pdo->prepare('UPDATE channel_memberships SET status = "cancelled" WHERE creator = ? AND member = ?')
                ->execute([$creator, $username]);
            $message = 'Membership cancelled.';
        } elseif ($action === 'request_join' && $creator !== '' && strcasecmp($creator, $username) !== 0) {
            $req = vs_membership_request_create($pdo, $creator, $username, (int)($_POST['tier_id'] ?? 0));
            $message = 'Request sent for ' . $req['tier'] . '. You become a member once ' . $creator . ' approves it.';
        } elseif ($action === 'cancel_request' && $creator !== '' && strcasecmp($creator, $username) !== 0) {
            vs_membership_request_cancel($pdo, $creator, $username);
            $message = 'Join request cancelled.';
        } elseif (($action === 'approve_request' || $action === 'reject_request') && strcasecmp($creator, $username) === 0) {
            $res = vs_membership_request_decide($pdo, $username, (int)($_POST['request_id'] ?? 0), $action === 'approve_request');
            $message = $res['approved']
                ? $res['member'] . ' is now a member of ' . $res['tier'] . '.'
                : 'Declined the request from ' . $res['member'] . '.';
        } else {
            throw new RuntimeException('That membership action is not allowed.');
        }
    }

    $tierStmt = $pdo->prepare('SELECT id, creator, name, price, benefits, requires_approval FROM channel_membership_tiers WHERE creator = ? AND active = 1 ORDER BY price ASC, id ASC');
    $tierStmt->execute([$creator]);
    $tiers = $tierStmt->fetchAll(PDO::FETCH_ASSOC);
    try {
        // only memberships that are active AND not past their expiry date count as members
        $cnt = $pdo->prepare('SELECT tier_id, COUNT(*) AS c FROM channel_memberships WHERE creator = ? AND status = "active" AND (expires_at IS NULL OR expires_at > NOW()) GROUP BY tier_id');
        $cnt->execute([$creator]);
        foreach ($cnt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tierCounts[(int)$row['tier_id']] = (int)$row['c'];
            $totalMembers += (int)$row['c'];
        }
    } catch (Throwable $countError) {
        $tierCounts = [];
    }
    if ($browse) {
        try {
            $browseRows = $pdo->query(
                'SELECT t.creator, COUNT(*) AS tier_count,
                        (SELECT COUNT(*) FROM channel_memberships m WHERE m.creator = t.creator AND m.status = "active" AND (m.expires_at IS NULL OR m.expires_at > NOW())) AS member_count
                 FROM channel_membership_tiers t WHERE t.active = 1
                 GROUP BY t.creator ORDER BY tier_count DESC, t.creator ASC LIMIT 60'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $browseError) {
            $browseRows = [];
        }
    }
    if ($username !== '' && strcasecmp($creator, $username) === 0) {
        try { $pendingRequests = vs_membership_pending_requests($pdo, $creator); } catch (Throwable $reqError) { $pendingRequests = []; }
    } elseif ($username !== '' && $creator !== '') {
        try { $myRequest = vs_membership_my_request($pdo, $creator, $username); } catch (Throwable $reqError) { $myRequest = null; }
    }
    $membership = null;
    if ($username !== '') {
        $memberStmt = $pdo->prepare(
            'SELECT m.tier_id, m.status, m.started_at, m.expires_at, t.name
             FROM channel_memberships m JOIN channel_membership_tiers t ON t.id = m.tier_id
             WHERE m.creator = ? AND m.member = ? LIMIT 1'
        );
        $memberStmt->execute([$creator, $username]);
        $membership = $memberStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        // the viewer's points balance (read after any join, so it is up to date)
        try {
            vs_premium_ensure($pdo);
            $myPoints = vs_rewards_points($pdo, $username);
            $pointsKnown = true;
        } catch (Throwable $pointsError) {
            $pointsKnown = false;
        }
    }
} catch (Throwable $e) {
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'Memberships could not be loaded.';
    error_log('memberships.php: ' . $e->getMessage());
    $tiers = $tiers ?? [];
    $membership = $membership ?? null;
}

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$isOwner = $username !== '' && strcasecmp($creator, $username) === 0;

// Most popular = tier with the most active members (only when someone has joined).
$popularId = 0;
$bestCount = 0;
foreach ($tiers as $t) {
    $c = $tierCounts[(int)$t['id']] ?? 0;
    if ($c > $bestCount) { $bestCount = $c; $popularId = (int)$t['id']; }
}
$crownColors = ['#c9d1e6', '#f5b83d', '#4cc3ff', '#c084fc', '#34d399'];   // the most popular tier is always red
$crown = static function (string $color): string {
    return '<svg class="mb-crown" viewBox="0 0 24 24" aria-hidden="true"><path fill="' . $color . '" d="M3 8l4.5 4L12 5l4.5 7L21 8l-1.6 10H4.6L3 8z"/><path fill="' . $color . '" opacity=".75" d="M4.8 19.4h14.4V21H4.8z"/></svg>';
};
$avatarSrc = '';
if ($creator !== '' && function_exists('avatar_url')) {
    try { $avatarSrc = (string)avatar_url($creator, ''); } catch (Throwable $e) { $avatarSrc = ''; }
}

// The viewer's membership: active only while its status is "active" and it has not expired.
$myTierName = '';
$myActive = false;
$myExpired = false;
if (!empty($membership) && ($membership['status'] ?? '') === 'active') {
    $expTs = !empty($membership['expires_at']) ? strtotime((string)$membership['expires_at']) : false;
    if ($expTs !== false && $expTs <= time()) {
        $myExpired = true;      // was a member, the period ended: offer "Renew"
    } else {
        $myActive = true;
    }
    $myTierName = (string)$membership['name'];
}
$starPath = 'M12 2l3 6.5 7 .9-5.1 4.9 1.3 7-6.2-3.5L5.8 21.3l1.3-7L2 9.4l7-.9L12 2z';

$extraCss = <<<'CSS'
.memberships{--mb-text:#f5f5f7;--mb-muted:#a9a9b4;--mb-line:#2b2b32;--mb-line-soft:#222228;--mb-card:linear-gradient(165deg,#121216,#0b0b0e);--mb-red:#ff1f1f;--mb-red2:#c40c0c;--mb-gold:#f5b83d;--mb-glow:rgba(255,31,31,.45);max-width:1320px;margin:0 auto;padding:18px 22px 72px;color:var(--mb-text)}
html[data-theme="light"] .memberships{--mb-text:#17171c;--mb-muted:#5f6068;--mb-line:#dcdce4;--mb-line-soft:#e8e8ee;--mb-card:linear-gradient(165deg,#fff,#f6f6fa);--mb-glow:rgba(255,31,31,.28)}
.mb-hero{position:relative;overflow:hidden;display:flex;align-items:center;gap:28px;min-height:210px;padding:22px 34px;border-radius:22px;border:1px solid rgba(255,45,45,.4);background:radial-gradient(560px 260px at 84% 110%,rgba(255,20,20,.6),transparent 70%),radial-gradient(380px 200px at 0 0,rgba(255,70,40,.22),transparent 70%),linear-gradient(110deg,#09090b 0%,#13060a 55%,#2b0509 100%);color:#fff;box-shadow:0 0 34px rgba(255,31,31,.22)}
.mb-crown-art{position:relative;z-index:1;flex:0 0 220px;width:220px;height:172px;filter:drop-shadow(0 0 22px rgba(255,170,40,.55))}
.mb-hero-copy{position:relative;z-index:1;min-width:0}
.mb-hero-copy h1{margin:0;font-size:clamp(38px,6.2vw,78px);font-weight:800;line-height:1;letter-spacing:-.03em}
.mb-bar{width:min(100%,520px);height:3px;margin:8px 0 14px;border-radius:3px;background:linear-gradient(90deg,#ff1f1f,rgba(255,31,31,0))}
.mb-lead{margin:0;font-size:clamp(15px,1.8vw,22px);font-weight:600}
.mb-lead em{font-style:normal;color:#ff2a2a}
.mb-chip{display:inline-flex;align-items:center;gap:10px;margin-top:14px;padding:6px 14px 6px 6px;border-radius:999px;border:1px solid rgba(255,255,255,.18);background:rgba(0,0,0,.35);font-size:14px;color:#e8e8ee}
.mb-chip-av{display:grid;place-items:center;width:32px;height:32px;border-radius:50%;overflow:hidden;background:linear-gradient(135deg,#ff1f1f,#7a0a0a);font-weight:800;font-size:14px}
.mb-chip-av img{width:100%;height:100%;object-fit:cover}
.mb-hero-play{position:absolute;right:3%;top:10%;width:92px;height:92px;opacity:.35;transform:rotate(8deg);pointer-events:none}
.mb-spark{position:absolute;color:#ff2a2a;pointer-events:none}
.mb-spark svg{display:block;width:100%;height:100%;fill:currentColor}
.mb-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:26px 0 14px}
.mb-head h2{margin:0;font-size:clamp(24px,3vw,32px);font-weight:800;letter-spacing:-.02em}
.mb-tagline{display:inline-flex;align-items:center;gap:8px;color:var(--mb-muted);font-size:15px}
.mb-tagline svg{width:26px;height:22px}
.mb-back{color:var(--mb-muted);font-weight:700;text-decoration:none}
.mb-back:hover{color:var(--mb-text)}
.mb-balance{display:inline-flex;align-items:center;gap:8px;margin:0 0 16px;padding:7px 14px;border-radius:999px;border:1px solid var(--mb-line);background:rgba(255,31,31,.08);font-size:14px}
.mb-balance b{color:var(--mb-gold);font-size:16px}
.mb-balance a,.mb-need a{color:#ff4d4d;font-weight:700;text-decoration:none}
.mb-balance a:hover,.mb-need a:hover{text-decoration:underline}
.notice,.error{margin:16px 0 0;padding:12px 16px;border-radius:12px;font-weight:600}
.notice{background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.4)}
.error{background:rgba(255,77,109,.12);border:1px solid rgba(255,77,109,.4)}
.mb-layout{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:16px;align-items:start}
.mb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px;align-items:stretch;padding-top:14px}
.mb-card{--tier:#c9d1e6;position:relative;display:flex;flex-direction:column;align-items:center;text-align:center;padding:22px 20px 20px;border-radius:18px;border:1px solid var(--mb-line);background:var(--mb-card);transition:transform .18s,border-color .18s,box-shadow .18s}
.mb-card:hover{transform:translateY(-3px);border-color:var(--tier)}
.mb-card.popular{--tier:#ff2a2a;border:2px solid #ff1f1f;box-shadow:0 0 28px var(--mb-glow),inset 0 0 26px rgba(255,31,31,.12)}
.mb-card.mine{box-shadow:0 0 0 2px rgba(52,211,153,.6)}
.mb-pop{position:absolute;top:-14px;left:50%;transform:translateX(-50%);padding:5px 18px;border-radius:999px;background:linear-gradient(90deg,#ff1f1f,#d10f0f);color:#fff;font-size:13px;font-weight:700;white-space:nowrap;box-shadow:0 0 16px rgba(255,31,31,.6)}
.mb-crown{display:block;width:58px;height:50px;margin:2px 0 8px;filter:drop-shadow(0 0 10px var(--tier))}
.mb-card h3{margin:0 0 4px;font-size:24px;font-weight:800}
.mb-price{margin:10px 0 2px;font-size:32px;font-weight:850;line-height:1.1}
.mb-price small{font-size:14px;font-weight:500;color:var(--mb-muted);margin-left:6px}
.mb-usd{margin:0 0 16px;font-size:13px;color:var(--mb-muted)}
.mb-perks{list-style:none;margin:0 0 18px;padding:0;width:100%;text-align:left;display:grid;gap:9px;flex:1}
.mb-perks li{display:flex;align-items:flex-start;gap:10px;font-size:15px;line-height:1.4}
.mb-perks svg{flex:0 0 18px;width:18px;height:18px;margin-top:2px;stroke:var(--tier);fill:none;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}
.mb-members{margin:-4px 0 12px;font-size:13px;color:var(--mb-muted)}
.mb-actions{display:flex;gap:10px;width:100%}
.mb-actions form{margin:0;flex:1}
.mb-need{margin:10px 0 0;font-size:13px;color:var(--mb-muted)}
.mb-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:46px;padding:10px 16px;border-radius:999px;border:2px solid var(--mb-line);background:transparent;color:var(--mb-text);font:inherit;font-weight:700;cursor:pointer;text-decoration:none;transition:filter .15s,box-shadow .15s,background .15s}
.mb-btn svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.mb-btn:hover{background:rgba(255,255,255,.07)}
.mb-btn:disabled{opacity:.55;cursor:not-allowed}
.mb-btn:disabled:hover{background:transparent}
.mb-btn.tier{border-color:var(--tier)}
.mb-btn.tier:hover{box-shadow:0 0 16px -2px var(--tier);background:transparent}
.mb-btn.primary{border:0;background:linear-gradient(180deg,#ff2a2a,#d10f0f);color:#fff;box-shadow:0 0 18px rgba(255,31,31,.5)}
.mb-btn.primary:hover{filter:brightness(1.1);background:linear-gradient(180deg,#ff2a2a,#d10f0f)}
.mb-btn.cancel{border-color:#ff4d4d}
.mb-btn.danger{border-color:rgba(255,91,125,.45)}
.mb-btn.danger:hover{background:rgba(255,91,125,.14);border-color:rgba(255,91,125,.8)}
.mb-add{justify-content:center;border:2px dashed var(--mb-line);background:transparent}
.mb-add:hover{box-shadow:none;transform:none}
.mb-plus{display:grid;place-items:center;width:62px;height:62px;margin-bottom:14px;border-radius:50%;background:linear-gradient(180deg,#ff2a2a,#c40c0c);color:#fff;font-size:36px;line-height:1;box-shadow:0 0 20px rgba(255,31,31,.55)}
.mb-add p{margin:6px 0 20px;color:var(--mb-muted);font-size:16px}
.mb-empty{grid-column:1/-1;padding:34px;text-align:center;border-radius:16px;border:1px dashed var(--mb-line);color:var(--mb-muted)}
.mb-why{position:relative;overflow:hidden;margin-top:14px;padding:22px 22px 120px;border-radius:18px;border:1px solid var(--mb-line);background:radial-gradient(320px 170px at 100% 100%,rgba(255,31,31,.5),transparent 70%),var(--mb-card)}
.mb-why h3{display:flex;align-items:center;justify-content:space-between;margin:0 0 4px;font-size:24px;font-weight:800}
.mb-why h3 svg{width:30px;height:30px;fill:#ff2a2a;filter:drop-shadow(0 0 8px rgba(255,31,31,.7))}
.mb-why .mb-bar{width:60%;margin:2px 0 16px}
.mb-why ul{list-style:none;margin:0;padding:0;display:grid;gap:16px}
.mb-why li{display:flex;align-items:center;gap:14px;font-size:16px}
.mb-why li svg{flex:0 0 28px;width:28px;height:28px;fill:#ff2a2a;filter:drop-shadow(0 0 6px rgba(255,31,31,.6))}
.mb-why-play{position:absolute;right:18px;bottom:16px;width:96px;height:68px;filter:drop-shadow(0 0 16px rgba(255,31,31,.7))}
.mb-browse{display:flex;align-items:center;justify-content:center;gap:16px;width:min(560px,100%);margin:22px auto 0;padding:14px 22px;border:2px solid #ff1f1f;border-radius:999px;background:rgba(255,31,31,.06);color:var(--mb-text);font-size:17px;font-weight:700;text-decoration:none;box-shadow:0 0 22px rgba(255,31,31,.35),inset 0 0 18px rgba(255,31,31,.12);transition:box-shadow .15s,transform .15s}
.mb-browse:hover{transform:translateY(-1px);box-shadow:0 0 32px rgba(255,31,31,.6),inset 0 0 18px rgba(255,31,31,.18)}
.mb-browse svg{width:30px;height:30px;fill:currentColor}
.mb-browse svg.chev{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}
.mb-mine{display:flex;flex-wrap:wrap;align-items:center;gap:18px;margin-top:22px;padding:18px 26px;border-radius:18px;border:1px solid var(--mb-line);background:var(--mb-card)}
.mb-me{display:grid;place-items:center;flex:0 0 56px;width:56px;height:56px;border-radius:50%;overflow:hidden;background:linear-gradient(135deg,#6b6f80,#3b3e4b);color:#fff;font-size:24px;font-weight:800}
.mb-me img{width:100%;height:100%;object-fit:cover}
.mb-mine-copy{flex:1 1 240px;min-width:0}
.mb-mine-copy h3{margin:0 0 2px;font-size:22px;font-weight:800}
.mb-mine-copy p{margin:0;font-size:16px;color:var(--mb-muted)}
.mb-mine-copy b{color:var(--mb-gold)}
.mb-mine-copy .when{margin-top:6px;font-size:14px}
.mb-mine form{margin:0}
.mb-mine .mb-btn{width:auto;min-width:170px}
.mb-join-now{display:inline-flex;align-items:center;gap:10px}
.mb-join-now svg{width:22px;height:18px;fill:#fff;stroke:none}
.mb-req{margin:6px 0 8px;padding:20px 22px;border-radius:18px;border:1px solid var(--mb-line);background:var(--mb-card)}
.mb-req h3{display:flex;align-items:center;gap:10px;margin:0 0 12px;font-size:20px;font-weight:800}
.mb-count{display:inline-grid;place-items:center;min-width:26px;height:26px;padding:0 8px;border-radius:999px;background:linear-gradient(180deg,#ff2a2a,#c40c0c);color:#fff;font-size:13px;font-weight:800}
.mb-req-empty{margin:0;color:var(--mb-muted);font-size:14.5px}
.mb-req-list{list-style:none;margin:0;padding:0;display:grid;gap:10px}
.mb-req-list li{display:flex;align-items:center;gap:14px;padding:12px 14px;border-radius:14px;border:1px solid var(--mb-line-soft);background:rgba(255,31,31,.05)}
.mb-req-av{display:grid;place-items:center;flex:0 0 44px;width:44px;height:44px;border-radius:50%;overflow:hidden;background:linear-gradient(135deg,#ff1f1f,#7a0a0a);color:#fff;font-weight:800}
.mb-req-av img{width:100%;height:100%;object-fit:cover}
.mb-req-info{flex:1;min-width:0;display:grid;gap:2px}
.mb-req-info a{font-weight:800;color:#ff4d4d;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mb-req-info span{font-size:13px;color:var(--mb-muted)}
.mb-req-actions{display:flex;gap:8px;margin:0}
.mb-req-actions .mb-btn{width:auto;min-height:40px;padding:8px 18px}
.mb-creators{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px}
.mb-creator{display:flex;align-items:center;gap:14px;padding:16px 18px;border-radius:16px;border:1px solid var(--mb-line);background:var(--mb-card);color:var(--mb-text);text-decoration:none;transition:border-color .15s,box-shadow .15s,transform .15s}
.mb-creator:hover{transform:translateY(-2px);border-color:#ff2a2a;box-shadow:0 0 22px -4px var(--mb-glow)}
.mb-creator .mb-req-av{flex-basis:54px;width:54px;height:54px;font-size:20px}
.mb-creator strong{display:block;font-size:17px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mb-creator span{font-size:13px;color:var(--mb-muted)}
.tier-dialog{width:min(520px,calc(100% - 32px));padding:0;border:1px solid rgba(255,45,45,.5);border-radius:18px;background:#111114;color:#f5f5f7;box-shadow:0 24px 70px rgba(0,0,0,.65)}
.tier-dialog::backdrop{background:rgba(5,4,6,.75);backdrop-filter:blur(3px)}
html[data-theme="light"] .tier-dialog{background:#fff;color:#17171c}
.tier-dialog form{display:grid;gap:14px;padding:26px}
.tier-dialog h3{margin:0;font-size:22px}
.mb-label{display:grid;gap:7px;font-size:13px;font-weight:700;color:#a9a9b4}
.mb-hint{font-weight:500;font-size:12px;color:#a9a9b4}
.mb-check{display:flex;align-items:flex-start;gap:10px;font-size:14px;font-weight:600;color:inherit;cursor:pointer}
.mb-check input{margin-top:3px;width:18px;height:18px;accent-color:#ff2a2a}
.mb-input{width:100%;min-height:46px;padding:11px 13px;border-radius:11px;border:1px solid rgba(255,255,255,.18);background:rgba(0,0,0,.4);color:inherit;font:inherit}
html[data-theme="light"] .mb-input{background:#fff;color:#17171c;border-color:#d4d4dc}
.mb-input:focus{outline:none;border-color:#ff2a2a;box-shadow:0 0 0 3px rgba(255,42,42,.25)}
textarea.mb-input{min-height:120px;resize:vertical}
.mb-dialog-actions{display:flex;gap:10px;justify-content:flex-end}
.mb-dialog-actions .mb-btn{width:auto;min-width:120px}
@media(max-width:1100px){.mb-layout{grid-template-columns:1fr}.mb-why{padding-bottom:110px}}
@media(max-width:700px){
  .memberships{padding:14px 12px 60px}
  .mb-hero{flex-direction:column;align-items:flex-start;gap:8px;padding:18px;min-height:0}
  .mb-crown-art{width:130px;height:102px;flex-basis:auto}
  .mb-hero-play{width:60px;height:60px}
  .mb-req-list li{flex-wrap:wrap}.mb-req-actions{width:100%}.mb-req-actions .mb-btn{flex:1}
  .mb-mine .mb-btn{width:100%}
  .mb-browse{font-size:15px;padding:12px 16px}
}
CSS;
vs_page_start('Channel memberships', 'membership', $extraCss);
$check = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7"/></svg>';
$loginUrl = 'login.php?redirect=' . rawurlencode('memberships.php?creator=' . $creator);
$days = (int)VS_MEMBERSHIP_DAYS;
$avatarOf = static function (string $name): string {
    if ($name !== '' && function_exists('avatar_url')) {
        try { return (string)avatar_url($name, ''); } catch (Throwable $e) { return ''; }
    }
    return '';
};
$myAvatar = $username !== '' ? $avatarOf($username) : '';
$crownSvg = static function (string $color): string {
    return '<svg class="mb-crown" viewBox="0 0 64 52" aria-hidden="true"><path fill="' . $color . '" d="M5 44 1 14l15 13L32 4l16 23L63 14l-4 30z"/><path fill="' . $color . '" opacity=".7" d="M7 46h50v5H7z"/><circle cx="32" cy="9" r="3.5" fill="#fff" opacity=".85"/><path fill="#fff" opacity=".35" d="M32 18l5 7-5 7-5-7z"/></svg>';
};
?>
<div class="memberships">

  <section class="mb-hero">
    <svg class="mb-crown-art" viewBox="0 0 220 172" aria-hidden="true">
      <defs>
        <linearGradient id="mbGold" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffe9a0"/><stop offset=".55" stop-color="#f5b83d"/><stop offset="1" stop-color="#c97a00"/></linearGradient>
        <linearGradient id="mbOrbit" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#ff7a00"/><stop offset="1" stop-color="#ff1f1f"/></linearGradient>
      </defs>
      <ellipse cx="110" cy="118" rx="104" ry="26" transform="rotate(-13 110 118)" fill="none" stroke="url(#mbOrbit)" stroke-width="5" opacity=".95"/>
      <path d="M38 128 24 56l46 32 40-60 40 60 46-32-14 72z" fill="url(#mbGold)" stroke="#a85f00" stroke-width="2" stroke-linejoin="round"/>
      <rect x="38" y="126" width="144" height="20" rx="7" fill="url(#mbGold)" stroke="#a85f00" stroke-width="2"/>
      <circle cx="24" cy="50" r="10" fill="url(#mbGold)" stroke="#a85f00" stroke-width="2"/>
      <circle cx="110" cy="22" r="11" fill="url(#mbGold)" stroke="#a85f00" stroke-width="2"/>
      <circle cx="196" cy="50" r="10" fill="url(#mbGold)" stroke="#a85f00" stroke-width="2"/>
      <path d="M110 84l15 20-15 20-15-20z" fill="#e3121b" stroke="#7a0a0a" stroke-width="2"/>
      <circle cx="68" cy="106" r="6" fill="#c084fc" stroke="#6d28d9" stroke-width="1.5"/>
      <circle cx="152" cy="106" r="6" fill="#c084fc" stroke="#6d28d9" stroke-width="1.5"/>
    </svg>
    <div class="mb-hero-copy">
      <h1>Memberships</h1>
      <div class="mb-bar"></div>
      <p class="mb-lead">
        <?php if ($browse): ?>Discover creators and unlock <em>exclusive perks!</em>
        <?php elseif ($isOwner): ?>Manage the tiers your fans can join.
        <?php elseif ($creator !== ''): ?>Support <?= $h($creator) ?> and unlock <em>exclusive perks!</em>
        <?php else: ?>Support your favourite creator and unlock <em>exclusive perks!</em><?php endif; ?>
      </p>
      <?php if (!$browse && $creator !== ''): ?>
        <?php $creatorAvatar = $avatarOf($creator); ?>
        <div class="mb-chip">
          <span class="mb-chip-av"><?php if ($creatorAvatar !== ''): ?><img src="<?= $h($creatorAvatar) ?>" alt=""><?php else: ?><?= $h(mb_strtoupper(mb_substr($creator, 0, 1))) ?><?php endif; ?></span>
          <span><?= $h($creator) ?> &nbsp;&bull;&nbsp; <?= count($tiers) ?> tier<?= count($tiers) === 1 ? '' : 's' ?> &nbsp;&bull;&nbsp; <?= (int)$totalMembers ?> member<?= $totalMembers === 1 ? '' : 's' ?></span>
        </div>
      <?php endif; ?>
    </div>
    <svg class="mb-hero-play" viewBox="0 0 24 24" aria-hidden="true"><path fill="#ff1f1f" d="M7 3.5v17a1 1 0 001.5.86l14-8.5a1 1 0 000-1.72l-14-8.5A1 1 0 007 3.5z"/></svg>
    <span class="mb-spark" style="right:6%;top:42%;width:34px;height:34px"><svg viewBox="0 0 24 24"><path d="M12 0c1 7 5 11 12 12-7 1-11 5-12 12-1-7-5-11-12-12 7-1 11-5 12-12z"/></svg></span>
    <span class="mb-spark" style="right:14%;bottom:12%;width:44px;height:44px"><svg viewBox="0 0 24 24"><path d="M12 0c1 7 5 11 12 12-7 1-11 5-12 12-1-7-5-11-12-12 7-1 11-5 12-12z"/></svg></span>
  </section>

  <?php if ($message !== ''): ?><div class="notice"><?= $h($message) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="error"><?= $h($error) ?></div><?php endif; ?>

<?php if ($browse): ?>

  <div class="mb-head"><h2>Creators with memberships</h2><a class="mb-back" href="memberships.php">&larr; Back</a></div>
  <?php if (!$browseRows): ?>
    <div class="mb-empty">No creator has published a membership yet.</div>
  <?php else: ?>
    <div class="mb-creators">
      <?php foreach ($browseRows as $br):
          $bn = (string)$br['creator'];
          $bAv = $avatarOf($bn);
      ?>
        <a class="mb-creator" href="memberships.php?creator=<?= rawurlencode($bn) ?>">
          <span class="mb-req-av"><?php if ($bAv !== ''): ?><img src="<?= $h($bAv) ?>" alt=""><?php else: ?><?= $h(mb_strtoupper(mb_substr($bn, 0, 1))) ?><?php endif; ?></span>
          <div style="min-width:0">
            <strong><?= $h($bn) ?></strong>
            <span><?= (int)$br['tier_count'] ?> tier<?= (int)$br['tier_count'] === 1 ? '' : 's' ?> &bull; <?= (int)$br['member_count'] ?> member<?= (int)$br['member_count'] === 1 ? '' : 's' ?></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php else: ?>

  <?php if ($isOwner): ?>
  <section class="mb-req" style="margin-top:22px" aria-labelledby="mbReqTitle">
    <h3 id="mbReqTitle">Join requests <span class="mb-count"><?= count($pendingRequests) ?></span></h3>
    <?php if (!$pendingRequests): ?>
      <p class="mb-req-empty">No pending requests. People can ask to join any tier where you turn on &ldquo;Members need my approval&rdquo;.</p>
    <?php else: ?>
      <ul class="mb-req-list">
        <?php foreach ($pendingRequests as $rq):
            $ru = (string)$rq['member'];
            $rAv = $avatarOf($ru);
        ?>
          <li>
            <span class="mb-req-av"><?php if ($rAv !== ''): ?><img src="<?= $h($rAv) ?>" alt=""><?php else: ?><?= $h(mb_strtoupper(mb_substr($ru, 0, 1))) ?><?php endif; ?></span>
            <div class="mb-req-info">
              <a href="profile.php?u=<?= rawurlencode($ru) ?>"><?= $h($ru) ?></a>
              <span>wants to join <b><?= $h($rq['tier_name']) ?></b> &bull; <?= $h(date('M j, g:i A', strtotime((string)$rq['created_at']))) ?></span>
            </div>
            <form method="post" class="mb-req-actions">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="request_id" value="<?= (int)$rq['id'] ?>">
              <button type="submit" class="mb-btn primary" name="action" value="approve_request">Approve</button>
              <button type="submit" class="mb-btn danger" name="action" value="reject_request" onclick="return confirm('Decline this request?')">Decline</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <div class="mb-head" id="mbTiers">
    <h2><?= $isOwner ? 'Your Membership Tiers' : 'Choose a Membership Tier' ?></h2>
    <span class="mb-tagline"><svg viewBox="0 0 64 52" aria-hidden="true"><path fill="#f5b83d" d="M5 44 1 14l15 13L32 4l16 23L63 14l-4 30z"/><path fill="#c97a00" d="M7 46h50v5H7z"/></svg>Support &bull; Unlock &bull; Enjoy</span>
  </div>
  <?php if (!$isOwner && $username !== '' && $pointsKnown): ?>
    <p class="mb-balance">Your points: <b><?= $h(number_format($myPoints)) ?></b> <a href="premium-points.php">Earn more</a></p>
  <?php endif; ?>

  <?php echo vs_ads_html(); ?>

  <div class="mb-layout">
  <div class="mb-grid">
    <?php foreach ($tiers as $idx => $tier):
        $tid = (int)$tier['id'];
        $isPopular = $tid === $popularId;
        $tierColor = $isPopular ? '#ff3b3b' : $crownColors[$idx % count($crownColors)];
        $cta = $isPopular ? 'primary' : 'tier';
        $isMine = $myActive && (int)$membership['tier_id'] === $tid;
        $isExpiredTier = $myExpired && (int)$membership['tier_id'] === $tid;
        $needsApproval = !empty($tier['requires_approval']);
        $costPts = vs_membership_points_cost($tier['price']);
        $missing = max(0, $costPts - $myPoints);
        $perks = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$tier['benefits'])), static fn($l) => $l !== ''));
        if (!$perks) { $perks = ['Member-only access and recognition.']; }
        $members = $tierCounts[$tid] ?? 0;
        $confirmMsg = $costPts > 0
            ? 'Spend ' . number_format($costPts) . ' points to join ' . $tier['name'] . ' for ' . $days . ' days? Points are not refunded if you cancel.'
            : 'Join ' . $tier['name'] . ' for ' . $days . ' days?';
    ?>
      <article class="mb-card<?= $isPopular ? ' popular' : '' ?><?= $isMine ? ' mine' : '' ?>" style="--tier:<?= $h($tierColor) ?>">
        <?php if ($isPopular): ?><span class="mb-pop">Most Popular</span><?php endif; ?>
        <?= $crownSvg($tierColor) ?>
        <h3><?= $h($tier['name']) ?></h3>
        <?php if ($needsApproval): ?>
          <p class="mb-price">By request<small></small></p>
          <p class="mb-usd">Free to request; <?= $h($creator) ?> approves</p>
        <?php elseif ($costPts > 0): ?>
          <p class="mb-price"><?= $h(number_format($costPts)) ?><small>pts / <?= $days ?> days</small></p>
          <p class="mb-usd">worth $<?= $h(number_format((float)$tier['price'], 2)) ?></p>
        <?php else: ?>
          <p class="mb-price">Free<small>/ <?= $days ?> days</small></p>
          <p class="mb-usd">no points needed</p>
        <?php endif; ?>
        <ul class="mb-perks"><?php foreach ($perks as $perk): ?><li><?= $check ?><span><?= $h($perk) ?></span></li><?php endforeach; ?></ul>
        <?php if ($members > 0): ?><p class="mb-members"><?= (int)$members ?> member<?= $members === 1 ? '' : 's' ?></p><?php endif; ?>

        <?php if ($isOwner): ?>
          <div class="mb-actions">
            <button type="button" class="mb-btn <?= $cta ?> js-edit-tier"
                    data-id="<?= $tid ?>" data-name="<?= $h($tier['name']) ?>"
                    data-price="<?= $h(number_format((float)$tier['price'], 2, '.', '')) ?>" data-benefits="<?= $h($tier['benefits']) ?>" data-approval="<?= $needsApproval ? '1' : '0' ?>">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>Edit
            </button>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="id" value="<?= $tid ?>">
              <button type="submit" name="action" value="delete_tier" class="mb-btn danger" onclick="return confirm('Unpublish this membership tier? It will no longer be shown on your page.')">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2m-9 0l1 14h8l1-14"/></svg>Unpublish
              </button>
            </form>
          </div>
        <?php elseif ($username === ''): ?>
          <a class="mb-btn <?= $cta ?>" href="<?= $h($loginUrl) ?>">Sign in to join</a>
        <?php elseif ($isMine): ?>
          <form method="post" class="mb-actions">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="tier_id" value="<?= $tid ?>">
            <button type="submit" class="mb-btn cancel" name="action" value="cancel" onclick="return confirm('Cancel this membership? No points are refunded.')">Cancel membership</button>
          </form>
        <?php elseif ($needsApproval): ?>
          <?php $reqHere = ($myRequest && (int)$myRequest['tier_id'] === $tid) ? $myRequest : null; ?>
          <?php if ($reqHere && $reqHere['status'] === 'pending'): ?>
            <button type="button" class="mb-btn" disabled>Request pending</button>
            <form method="post" class="mb-actions" style="margin-top:10px">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <button type="submit" class="mb-btn cancel" name="action" value="cancel_request">Cancel request</button>
            </form>
          <?php else: ?>
            <?php if ($reqHere && $reqHere['status'] === 'rejected'): ?><p class="mb-need" style="margin:0 0 10px">Your last request was declined. You can ask again.</p><?php endif; ?>
            <form method="post" class="mb-actions">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="tier_id" value="<?= $tid ?>">
              <button type="submit" class="mb-btn <?= $cta ?>" name="action" value="request_join">Request to join</button>
            </form>
          <?php endif; ?>
        <?php elseif (!$hasPremium): ?>
          <a class="mb-btn <?= $cta ?>" href="premium.php">Get Premium to join paid tiers</a>
        <?php elseif ($pointsKnown && $missing > 0): ?>
          <button type="button" class="mb-btn" disabled>Need <?= $h(number_format($missing)) ?> more points</button>
          <p class="mb-need"><a href="premium-points.php">Earn points</a> with daily check-ins and uploads.</p>
        <?php else: ?>
          <form method="post" class="mb-actions">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="tier_id" value="<?= $tid ?>">
            <button type="submit" class="mb-btn <?= $cta ?>" name="action" value="join" onclick="return confirm(<?= $h(json_encode($confirmMsg)) ?>)"><?= $isExpiredTier ? 'Renew membership' : ($costPts > 0 ? 'Join for ' . $h(number_format($costPts)) . ' pts' : 'Join') ?></button>
          </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>

    <?php if ($isOwner): ?>
      <article class="mb-card mb-add">
        <span class="mb-plus">+</span>
        <h3>Add New Tier</h3>
        <p>Create a new membership tier for your fans.</p>
        <?php if ($hasPremium): ?>
          <button type="button" class="mb-btn primary js-new-tier">Create Tier</button>
        <?php else: ?>
          <p>VibeStream Premium is required to create channel membership tiers.</p>
          <a class="mb-btn primary" href="premium.php">Upgrade to Premium</a>
        <?php endif; ?>
      </article>
    <?php elseif (!$tiers): ?>
      <div class="mb-empty">No membership tiers have been published yet.</div>
    <?php endif; ?>
  </div>

  <aside class="mb-why" aria-labelledby="mbWhy">
    <h3 id="mbWhy">Why Join? <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-8-4.9-8-11a4.5 4.5 0 018-2.8A4.5 4.5 0 0120 10c0 6.1-8 11-8 11z"/></svg></h3>
    <div class="mb-bar"></div>
    <ul>
      <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $starPath ?>"/></svg>Get exclusive perks</li>
      <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 4 14h6l-1 8 9-12h-6z"/></svg>Support the creator</li>
      <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9h18v4H3zM5 14h14v7H5zM11 9h2v12h-2z"/><path d="M12 9C9 9 7 7.5 7.5 6S10 3 12 9c2-6 3.5-4.5 4.5-3S15 9 12 9z"/></svg>Unlock special rewards</li>
      <li><svg viewBox="0 0 64 52" aria-hidden="true"><path d="M5 44 1 14l15 13L32 4l16 23L63 14l-4 30z"/><path opacity=".7" d="M7 46h50v5H7z"/></svg>Be part of the community</li>
    </ul>
    <svg class="mb-why-play" viewBox="0 0 96 68" aria-hidden="true"><rect width="96" height="68" rx="18" fill="#ff1f1f"/><path fill="#fff" d="M38 20v28l26-14z"/></svg>
  </aside>
  </div>

  <?php echo vs_ads_html(); ?>

  <a class="mb-browse" href="memberships.php?browse=1">
    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><circle cx="17" cy="9" r="2.8"/><path d="M2 20c0-3.5 3-6 7-6s7 2.5 7 6zM15.5 14.2c3.5-.4 6.5 1.6 6.5 5.3h-4.5c0-2-.8-3.7-2-5.3z"/></svg>
    Browse Other Creators' Memberships
    <svg class="chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>
  </a>

  <?php if (!$isOwner): ?>
    <section class="mb-mine">
      <span class="mb-me"><?php if ($myAvatar !== ''): ?><img src="<?= $h($myAvatar) ?>" alt=""><?php else: ?><?= $h($username !== '' ? mb_strtoupper(mb_substr($username, 0, 1)) : '?') ?><?php endif; ?></span>
      <div class="mb-mine-copy">
        <h3>Your Membership</h3>
        <?php if ($username === ''): ?>
          <p>Sign in to join this membership.</p>
        <?php elseif ($myActive): ?>
          <p>You are currently a <b><?= $h($myTierName) ?></b>!</p>
          <p class="when">
            Started: <?= !empty($membership['started_at']) && strtotime((string)$membership['started_at']) ? $h(date('M j, Y', strtotime((string)$membership['started_at']))) : '&mdash;' ?>
            &nbsp;&bull;&nbsp; Expires: <?= !empty($membership['expires_at']) && strtotime((string)$membership['expires_at']) ? $h(date('M j, Y', strtotime((string)$membership['expires_at']))) : 'Never' ?>
          </p>
        <?php elseif ($myExpired): ?>
          <p>Your <b><?= $h($myTierName) ?></b> membership expired on <?= $h(date('M j, Y', strtotime((string)$membership['expires_at']))) ?>. Use &ldquo;Renew membership&rdquo; above to join again.</p>
        <?php else: ?>
          <p>You are not currently a member.</p>
        <?php endif; ?>
      </div>
      <?php if ($username === ''): ?>
        <a class="mb-btn primary mb-join-now" href="<?= $h($loginUrl) ?>">Sign in</a>
      <?php elseif ($myActive): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
          <button type="submit" class="mb-btn cancel" name="action" value="cancel" onclick="return confirm('Cancel your membership? No points are refunded.')">&#10005; Cancel Membership</button>
        </form>
      <?php elseif ($tiers): ?>
        <a class="mb-btn primary mb-join-now" href="#mbTiers"><svg viewBox="0 0 64 52" aria-hidden="true"><path d="M5 44 1 14l15 13L32 4l16 23L63 14l-4 30z"/></svg>Join Now</a>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($isOwner): ?>
  <dialog class="tier-dialog" id="tierDialog">
    <form method="post">
      <h3 id="tierDialogTitle">Create membership tier</h3>
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <input type="hidden" name="id" value="0">
      <label class="mb-label">Tier name
        <input class="mb-input" name="name" maxlength="60" placeholder="e.g. Gold Supporter" required>
      </label>
      <label class="mb-label">Price in dollars
        <input class="mb-input" name="price" type="number" min="0" max="999999" step="0.01" value="4.99" required>
        <span class="mb-hint">Members pay <?= (int)VS_MEMBERSHIP_POINTS_PER_DOLLAR ?> points per $1 for <?= $days ?> days (so $4.99 = <?= (int)round(4.99 * VS_MEMBERSHIP_POINTS_PER_DOLLAR) ?> points). Use 0 for a free tier.</span>
      </label>
      <label class="mb-check">
        <input type="checkbox" name="requires_approval" value="1">
        <span>Members need my approval<br><span class="mb-hint">People send a request and you accept or decline them from &ldquo;Join requests&rdquo;. It is free for them: no points are taken, and the price above is ignored.</span></span>
      </label>
      <label class="mb-label">Benefits (one per line)
        <textarea class="mb-input" name="benefits" maxlength="1000" rows="4" placeholder="Exclusive badges&#10;Custom emojis&#10;Members-only posts"></textarea>
      </label>
      <div class="mb-dialog-actions">
        <button type="button" class="mb-btn js-close-tier">Cancel</button>
        <button type="submit" class="mb-btn primary" name="action" value="save_tier">Save tier</button>
      </div>
    </form>
  </dialog>
  <script>
  (function () {
    var dlg = document.getElementById('tierDialog');
    if (!dlg || typeof dlg.showModal !== 'function') return;
    var f = dlg.querySelector('form'), title = document.getElementById('tierDialogTitle');
    var approve = f.querySelector('[name=requires_approval]'), priceInput = f.querySelector('[name=price]');
    function syncPrice() { priceInput.disabled = approve.checked; }
    approve.addEventListener('change', syncPrice);
    function fill(id, name, price, benefits, heading, approval) {
      f.querySelector('[name=id]').value = id;
      f.querySelector('[name=name]').value = name;
      f.querySelector('[name=price]').value = price;
      f.querySelector('[name=benefits]').value = benefits;
      approve.checked = approval === '1';
      syncPrice();
      title.textContent = heading;
      dlg.showModal();
    }
    document.querySelectorAll('.js-edit-tier').forEach(function (b) {
      b.addEventListener('click', function () {
        fill(b.dataset.id, b.dataset.name, b.dataset.price, b.dataset.benefits, 'Edit membership tier', b.dataset.approval);
      });
    });
    document.querySelectorAll('.js-new-tier').forEach(function (b) {
      b.addEventListener('click', function () { fill('0', '', '4.99', '', 'Create membership tier', '0'); });
    });
    dlg.querySelector('.js-close-tier').addEventListener('click', function () { dlg.close(); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
  })();
  </script>
  <?php endif; ?>

<?php endif; ?>
</div>
<?php vs_page_end(); ?>