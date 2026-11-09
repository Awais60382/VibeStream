<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/membership-store.php';
require_once __DIR__ . '/header.php';

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

if (empty($_SESSION['membership_csrf'])) {
    $_SESSION['membership_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['membership_csrf'];

try {
    vs_membership_ensure($pdo);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
            $price = max(0, min(999999.99, (float)($_POST['price'] ?? 0)));
            $benefits = mb_substr(trim((string)($_POST['benefits'] ?? '')), 0, 1000);
            if ($name === '') {
                throw new RuntimeException('Tier name is required.');
            }
            if ($id > 0) {
                $pdo->prepare('UPDATE channel_membership_tiers SET name = ?, price = ?, benefits = ?, active = 1 WHERE id = ? AND creator = ?')
                    ->execute([$name, $price, $benefits, $id, $username]);
            } else {
                $pdo->prepare('INSERT INTO channel_membership_tiers (creator, name, price, benefits) VALUES (?, ?, ?, ?)')
                    ->execute([$username, $name, $price, $benefits]);
            }
            $message = 'Membership tier saved.';
        } elseif ($action === 'delete_tier' && strcasecmp($creator, $username) === 0) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Membership tier is invalid.');
            }
            $pdo->prepare('UPDATE channel_membership_tiers SET active = 0 WHERE id = ? AND creator = ?')
                ->execute([$id, $username]);
            $message = 'Membership tier unpublished.';
        } elseif ($action === 'join' && $creator !== '' && strcasecmp($creator, $username) !== 0) {
            $tierId = (int)($_POST['tier_id'] ?? 0);
            $check = $pdo->prepare('SELECT 1 FROM channel_membership_tiers WHERE id = ? AND creator = ? AND active = 1 LIMIT 1');
            $check->execute([$tierId, $creator]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('That membership tier is not available.');
            }
            $pdo->prepare(
                'INSERT INTO channel_memberships (creator, member, tier_id, status)
                 VALUES (?, ?, ?, "active")
                 ON DUPLICATE KEY UPDATE tier_id = VALUES(tier_id), status = "active", started_at = NOW(), expires_at = NULL'
            )->execute([$creator, $username, $tierId]);
            $message = 'Membership updated.';
        } elseif ($action === 'cancel' && $creator !== '' && strcasecmp($creator, $username) !== 0) {
            $pdo->prepare('UPDATE channel_memberships SET status = "cancelled" WHERE creator = ? AND member = ?')
                ->execute([$creator, $username]);
            $message = 'Membership cancelled.';
        } else {
            throw new RuntimeException('That membership action is not allowed.');
        }
    }

    $tierStmt = $pdo->prepare('SELECT id, creator, name, price, benefits FROM channel_membership_tiers WHERE creator = ? AND active = 1 ORDER BY price ASC, id ASC');
    $tierStmt->execute([$creator]);
    $tiers = $tierStmt->fetchAll(PDO::FETCH_ASSOC);
    try {
        $cnt = $pdo->prepare('SELECT tier_id, COUNT(*) AS c FROM channel_memberships WHERE creator = ? AND status = "active" GROUP BY tier_id');
        $cnt->execute([$creator]);
        foreach ($cnt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tierCounts[(int)$row['tier_id']] = (int)$row['c'];
            $totalMembers += (int)$row['c'];
        }
    } catch (Throwable $countError) {
        $tierCounts = [];
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
$crownColors = ['#c9d1e6', '#f5b83d', '#4cc3ff', '#c084fc', '#34d399'];
$crown = static function (string $color): string {
    return '<svg class="mb-crown" viewBox="0 0 24 24" aria-hidden="true"><path fill="' . $color . '" d="M3 8l4.5 4L12 5l4.5 7L21 8l-1.6 10H4.6L3 8z"/><path fill="' . $color . '" opacity=".75" d="M4.8 19.4h14.4V21H4.8z"/></svg>';
};
$avatarSrc = '';
if ($creator !== '' && function_exists('avatar_url')) {
    try { $avatarSrc = (string)avatar_url($creator, ''); } catch (Throwable $e) { $avatarSrc = ''; }
}
$myTierName = '';
$myActive = !empty($membership) && ($membership['status'] ?? '') === 'active';
if ($myActive) { $myTierName = (string)$membership['name']; }

$extraCss = <<<'CSS'
.memberships{--mb-text:#f1f0ff;--mb-muted:#a7a9d6;--mb-line:rgba(139,92,246,.32);--mb-line-soft:rgba(139,92,246,.18);--mb-card:linear-gradient(160deg,rgba(24,22,60,.95),rgba(12,12,34,.98));--mb-field:rgba(10,10,32,.8);--mb-accent:#7c3aed;--mb-accent2:#a855f7;--mb-glow:rgba(124,58,237,.4);max-width:1240px;margin:0 auto;padding:24px 24px 80px;color:var(--mb-text)}
html[data-theme="light"] .memberships{--mb-text:#1d1544;--mb-muted:#5f6690;--mb-line:rgba(124,77,255,.35);--mb-line-soft:rgba(124,77,255,.16);--mb-card:linear-gradient(160deg,#fff,#f8f5ff);--mb-field:#fff;--mb-glow:rgba(124,77,255,.28)}
.mb-hero{position:relative;overflow:hidden;display:flex;align-items:center;gap:26px;padding:26px 32px;border-radius:20px;border:1px solid var(--mb-line);background:radial-gradient(520px 200px at 85% 40%,rgba(147,51,234,.5),transparent 70%),linear-gradient(105deg,#0f0636,#1a0b55 55%,#2a0c78);color:#fff;box-shadow:0 0 30px rgba(124,58,237,.25)}
.mb-hero-art{position:absolute;right:-30px;top:50%;width:260px;height:260px;transform:translateY(-50%) rotate(8deg);opacity:.5;pointer-events:none}
.mb-av{position:relative;z-index:1;flex:0 0 112px;width:112px;height:112px;border-radius:50%;display:grid;place-items:center;overflow:hidden;background:#0b0620;border:3px solid rgba(216,180,254,.85);box-shadow:0 0 24px rgba(168,85,247,.55);font-size:44px;font-weight:800;color:#e9d5ff}
.mb-av img{width:100%;height:100%;object-fit:cover}
.mb-hero-copy{position:relative;z-index:1;min-width:0}
.mb-hero-copy h1{margin:0;font-size:clamp(28px,4vw,44px);line-height:1.1;font-weight:800;letter-spacing:-.02em;word-break:break-word}
.mb-hero-copy .stats{margin:8px 0 0;color:#cfc8ff;font-size:16px}
.mb-hero-copy .tag{margin:10px 0 0;font-size:17px;font-weight:600;color:#fff}
.mb-hero-copy .tag svg{width:18px;height:18px;vertical-align:-3px;margin-left:4px;fill:#a78bfa}
.mb-title{display:flex;align-items:center;gap:14px;margin:30px 0 4px}
.mb-title .mb-crown{width:54px;height:54px;filter:drop-shadow(0 0 12px rgba(245,184,61,.6))}
.mb-title h2{margin:0;font-size:clamp(28px,4vw,40px);font-weight:800;letter-spacing:-.02em}
.mb-sub{margin:0 0 22px;font-size:17px;color:var(--mb-muted)}
.mb-crown{display:block}
.notice,.error{margin:16px 0 0;padding:12px 16px;border-radius:12px;font-weight:600}
.notice{background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.4)}
.error{background:rgba(255,77,109,.12);border:1px solid rgba(255,77,109,.4)}
.mb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;align-items:stretch}
.mb-card{position:relative;display:flex;flex-direction:column;align-items:center;text-align:center;padding:26px 22px 22px;border-radius:18px;border:1px solid var(--mb-line);background:var(--mb-card);transition:transform .18s,border-color .18s,box-shadow .18s}
.mb-card:hover{transform:translateY(-4px);border-color:rgba(192,132,252,.75);box-shadow:0 0 26px var(--mb-glow)}
.mb-card.popular{border:2px solid #a855f7;box-shadow:0 0 30px var(--mb-glow)}
.mb-card.mine{border-color:rgba(52,211,153,.7)}
.mb-pop{position:absolute;top:-15px;left:50%;transform:translateX(-50%);display:inline-flex;align-items:center;gap:6px;padding:6px 16px;border-radius:999px;background:linear-gradient(90deg,#7c3aed,#a855f7);color:#fff;font-size:13px;font-weight:700;white-space:nowrap;box-shadow:0 0 16px rgba(168,85,247,.6)}
.mb-pop svg{width:14px;height:14px;fill:#fff}
.mb-card .mb-crown{width:56px;height:56px;margin:4px 0 6px;filter:drop-shadow(0 0 10px rgba(168,85,247,.45))}
.mb-card h3{margin:0 0 8px;font-size:22px;font-weight:700}
.mb-price{margin:0 0 16px;font-size:36px;font-weight:850;line-height:1.1}
.mb-price small{font-size:16px;font-weight:500;color:var(--mb-muted);margin-left:6px}
.mb-perks{list-style:none;margin:0 0 20px;padding:0;width:100%;text-align:left;display:grid;gap:10px;flex:1}
.mb-perks li{display:flex;align-items:flex-start;gap:10px;font-size:15.5px;line-height:1.4}
.mb-perks svg{flex:0 0 18px;width:18px;height:18px;margin-top:2px;stroke:#a855f7;fill:none;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}
.mb-members{margin:-6px 0 14px;font-size:13px;color:var(--mb-muted)}
.mb-actions{display:flex;gap:10px;width:100%}
.mb-actions form{margin:0;flex:1}
.mb-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:46px;padding:10px 16px;border-radius:12px;border:1px solid var(--mb-line);background:transparent;color:var(--mb-text);font:inherit;font-weight:700;cursor:pointer;text-decoration:none;transition:filter .15s,box-shadow .15s,background .15s}
.mb-btn svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.mb-btn:hover{background:rgba(124,58,237,.18)}
.mb-btn.primary{border:0;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;box-shadow:0 0 16px rgba(124,58,237,.45)}
.mb-btn.primary:hover{filter:brightness(1.12);background:linear-gradient(135deg,#7c3aed,#6d28d9)}
.mb-btn.cancel{border-color:#a855f7}
.mb-btn.danger{border-color:rgba(255,91,125,.35)}
.mb-btn.danger:hover{background:rgba(255,91,125,.14);border-color:rgba(255,91,125,.7)}
.mb-add{justify-content:center;border:2px dashed var(--mb-line);background:transparent}
.mb-add:hover{box-shadow:none}
.mb-plus{display:grid;place-items:center;width:62px;height:62px;margin-bottom:14px;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;font-size:36px;line-height:1;box-shadow:0 0 20px rgba(124,58,237,.6)}
.mb-add p{margin:6px 0 20px;color:var(--mb-muted);font-size:16px}
.mb-empty{grid-column:1/-1;padding:34px;text-align:center;border-radius:16px;border:1px dashed var(--mb-line);color:var(--mb-muted)}
.mb-mine{display:flex;flex-wrap:wrap;align-items:center;gap:22px;margin-top:26px;padding:22px 28px;border-radius:18px;border:1px solid var(--mb-line);background:linear-gradient(105deg,rgba(24,18,70,.95),rgba(14,10,44,.98));color:#f1f0ff}
html[data-theme="light"] .mb-mine{background:linear-gradient(105deg,#f3ecff,#fff);color:#1d1544}
.mb-star{display:grid;place-items:center;flex:0 0 96px;width:96px;height:96px;border-radius:50%;border:1px solid var(--mb-line);background:rgba(124,58,237,.12)}
.mb-star svg{width:52px;height:52px;fill:#a855f7;filter:drop-shadow(0 0 10px rgba(168,85,247,.7))}
.mb-mine-copy{flex:1 1 260px;min-width:0}
.mb-mine-copy h3{margin:0 0 4px;font-size:24px;font-weight:800}
.mb-mine-copy p{margin:0;font-size:17px}
.mb-mine-copy b{color:#f5b83d}
.mb-mine-copy .when{margin-top:8px;font-size:14.5px;color:var(--mb-muted)}
.mb-mine form{margin:0}
.mb-mine .mb-btn{width:auto;min-width:210px}
.tier-dialog{width:min(520px,calc(100% - 32px));padding:0;border:1px solid var(--mb-line,rgba(139,92,246,.4));border-radius:18px;background:#12102e;color:#f1f0ff;box-shadow:0 24px 70px rgba(0,0,0,.6)}
.tier-dialog::backdrop{background:rgba(5,4,20,.72);backdrop-filter:blur(3px)}
html[data-theme="light"] .tier-dialog{background:#fff;color:#1d1544}
.tier-dialog form{display:grid;gap:14px;padding:26px}
.tier-dialog h3{margin:0;font-size:22px}
.mb-label{display:grid;gap:7px;font-size:13px;font-weight:700;color:#a7a9d6}
.mb-input{width:100%;min-height:46px;padding:11px 13px;border-radius:11px;border:1px solid rgba(139,92,246,.4);background:rgba(10,10,32,.7);color:inherit;font:inherit}
html[data-theme="light"] .mb-input{background:#fff;color:#1d1544}
.mb-input:focus{outline:none;border-color:#a855f7;box-shadow:0 0 0 3px rgba(168,85,247,.25)}
textarea.mb-input{min-height:120px;resize:vertical}
.mb-dialog-actions{display:flex;gap:10px;justify-content:flex-end}
.mb-dialog-actions .mb-btn{width:auto;min-width:120px}
@media(max-width:700px){.memberships{padding:16px 14px 60px}.mb-hero{padding:20px;gap:16px}.mb-av{flex-basis:76px;width:76px;height:76px;font-size:30px}.mb-mine .mb-btn{width:100%}}
CSS;
vs_page_start('Channel memberships', 'membership', $extraCss);
$check = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7"/></svg>';
?>
<div class="memberships">

  <section class="mb-hero">
    <svg class="mb-hero-art" viewBox="0 0 24 24" aria-hidden="true"><defs><linearGradient id="mbg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#c084fc"/><stop offset="1" stop-color="#6d28d9"/></linearGradient></defs><path fill="url(#mbg)" d="M7 3.5v17a1 1 0 001.5.86l14-8.5a1 1 0 000-1.72l-14-8.5A1 1 0 007 3.5z"/></svg>
    <div class="mb-av"><?php if ($avatarSrc !== ''): ?><img src="<?= $h($avatarSrc) ?>" alt=""><?php else: ?><?= $h(mb_strtoupper(mb_substr($creator !== '' ? $creator : 'C', 0, 1))) ?><?php endif; ?></div>
    <div class="mb-hero-copy">
      <h1><?= $h($creator !== '' ? $creator : 'Channel') ?></h1>
      <p class="stats"><?= count($tiers) ?> membership tier<?= count($tiers) === 1 ? '' : 's' ?> &nbsp;&bull;&nbsp; <?= (int)$totalMembers ?> member<?= $totalMembers === 1 ? '' : 's' ?></p>
      <p class="tag">Join my membership and get exclusive perks! <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-8-4.9-8-11a4.5 4.5 0 018-2.8A4.5 4.5 0 0120 10c0 6.1-8 11-8 11z"/></svg></p>
    </div>
  </section>

  <?php if ($message !== ''): ?><div class="notice"><?= $h($message) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="error"><?= $h($error) ?></div><?php endif; ?>

  <div class="mb-title"><?= $crown('#f5b83d') ?><h2>Memberships</h2></div>
  <p class="mb-sub"><?= $isOwner ? 'Manage the tiers your fans can join.' : 'Support your favourite creator and unlock exclusive benefits!' ?></p>

  <div class="mb-grid">
    <?php foreach ($tiers as $idx => $tier):
        $tid = (int)$tier['id'];
        $isPopular = $tid === $popularId;
        $isMine = $myActive && (int)$membership['tier_id'] === $tid;
        $perks = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$tier['benefits'])), static fn($l) => $l !== ''));
        if (!$perks) { $perks = ['Member-only access and recognition.']; }
        $members = $tierCounts[$tid] ?? 0;
    ?>
      <article class="mb-card<?= $isPopular ? ' popular' : '' ?><?= $isMine ? ' mine' : '' ?>">
        <?php if ($isPopular): ?><span class="mb-pop"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3 6.5 7 .9-5.1 4.9 1.3 7-6.2-3.500L5.8 21.300l1.3-7L2 9.400l7-.9L12 2z"/></svg>Most Popular</span><?php endif; ?>
        <?= $crown($crownColors[$idx % count($crownColors)]) ?>
        <h3><?= $h($tier['name']) ?></h3>
        <p class="mb-price">$<?= $h(number_format((float)$tier['price'], 2)) ?><small>/ month</small></p>
        <ul class="mb-perks"><?php foreach ($perks as $perk): ?><li><?= $check ?><span><?= $h($perk) ?></span></li><?php endforeach; ?></ul>
        <?php if ($members > 0): ?><p class="mb-members"><?= (int)$members ?> member<?= $members === 1 ? '' : 's' ?></p><?php endif; ?>

        <?php if ($isOwner): ?>
          <div class="mb-actions">
            <button type="button" class="mb-btn<?= $isPopular ? ' primary' : '' ?> js-edit-tier"
                    data-id="<?= $tid ?>" data-name="<?= $h($tier['name']) ?>"
                    data-price="<?= $h(number_format((float)$tier['price'], 2, '.', '')) ?>" data-benefits="<?= $h($tier['benefits']) ?>">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9M16.5 3.500a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.500z"/></svg>Edit
            </button>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="id" value="<?= $tid ?>">
              <button type="submit" name="action" value="delete_tier" class="mb-btn danger" onclick="return confirm('Unpublish this membership tier?')">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2m-9 0l1 14h8l1-14"/></svg>Delete
              </button>
            </form>
          </div>
        <?php elseif ($username === ''): ?>
          <a class="mb-btn primary" href="login.php?redirect=memberships.php?creator=<?= urlencode($creator) ?>">Sign in to join</a>
        <?php else: ?>
          <form method="post" class="mb-actions">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="tier_id" value="<?= $tid ?>">
            <?php if ($isMine): ?>
              <button type="submit" class="mb-btn cancel" name="action" value="cancel">Cancel membership</button>
            <?php else: ?>
              <button type="submit" class="mb-btn primary" name="action" value="join">Join tier</button>
            <?php endif; ?>
          </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>

    <?php if ($isOwner): ?>
      <article class="mb-card mb-add">
        <span class="mb-plus">+</span>
        <h3>Add New Tier</h3>
        <p>Create a new membership tier for your fans.</p>
        <button type="button" class="mb-btn primary js-new-tier">Create Tier</button>
      </article>
    <?php elseif (!$tiers): ?>
      <div class="mb-empty">No membership tiers have been published yet.</div>
    <?php endif; ?>
  </div>

  <?php if (!$isOwner && $myActive): ?>
    <section class="mb-mine">
      <span class="mb-star"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3 6.5 7 .9-5.1 4.9 1.3 7-6.2-3.500L5.8 21.300l1.3-7L2 9.400l7-.9L12 2z"/></svg></span>
      <div class="mb-mine-copy">
        <h3>Your Membership</h3>
        <p>You are currently a <b><?= $h($myTierName) ?></b>!</p>
        <p class="when">
          Started: <?= !empty($membership['started_at']) && strtotime((string)$membership['started_at']) ? $h(date('M j, Y', strtotime((string)$membership['started_at']))) : '&mdash;' ?>
          &nbsp;&bull;&nbsp; Expires: <?= !empty($membership['expires_at']) && strtotime((string)$membership['expires_at']) ? $h(date('M j, Y', strtotime((string)$membership['expires_at']))) : 'Never' ?>
        </p>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
        <button type="submit" class="mb-btn cancel" name="action" value="cancel" onclick="return confirm('Cancel your membership?')">&#10005; Cancel Membership</button>
      </form>
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
      <label class="mb-label">Monthly price
        <input class="mb-input" name="price" type="number" min="0" max="999999" step="0.01" value="4.99" required>
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
    function fill(id, name, price, benefits, heading) {
      f.querySelector('[name=id]').value = id;
      f.querySelector('[name=name]').value = name;
      f.querySelector('[name=price]').value = price;
      f.querySelector('[name=benefits]').value = benefits;
      title.textContent = heading;
      dlg.showModal();
    }
    document.querySelectorAll('.js-edit-tier').forEach(function (b) {
      b.addEventListener('click', function () {
        fill(b.dataset.id, b.dataset.name, b.dataset.price, b.dataset.benefits, 'Edit membership tier');
      });
    });
    document.querySelectorAll('.js-new-tier').forEach(function (b) {
      b.addEventListener('click', function () { fill('0', '', '4.99', '', 'Create membership tier'); });
    });
    dlg.querySelector('.js-close-tier').addEventListener('click', function () { dlg.close(); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
  })();
  </script>
  <?php endif; ?>
</div>
<?php vs_page_end(); ?>