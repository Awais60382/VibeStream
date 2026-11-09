<?php
/*
 * VibeStream - your login activity: when someone signed in to YOUR account (and failed attempts).
 * Put this file in the main VibeStream folder.
 */
require_once __DIR__ . '/layout.php';
include __DIR__ . '/connection.php';
require_once __DIR__ . '/account-safety-store.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$username = trim((string)($_SESSION['username'] ?? ''));
if ($username === '') {
    header('Location: login.php?next=' . rawurlencode('login-activity.php'));
    exit;
}
$h = static function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

$rows = [];
$error = '';
try {
    $rows = vs_login_history($pdo, $username, 50);
} catch (Throwable $e) {
    error_log('login-activity.php: ' . $e->getMessage());
    $error = 'Your login activity could not be loaded right now.';
}
$failed = 0;
$newDevices = 0;
foreach ($rows as $r) {
    if (!(int)$r['success']) { $failed++; }
    if ((int)$r['is_new']) { $newDevices++; }
}
$ago = static function (string $t): string {
    $d = time() - (int)strtotime($t);
    if ($d < 60) return 'just now';
    if ($d < 3600) return (int)floor($d / 60) . ' min ago';
    if ($d < 86400) return (int)floor($d / 3600) . ' h ago';
    if ($d < 604800) return (int)floor($d / 86400) . ' d ago';
    return date('M j, Y', strtotime($t));
};

$extraCss = <<<'CSS'
.la-page{max-width:900px;margin:0 auto;padding:36px clamp(16px,4vw,32px) 90px;color:inherit}
.la-page h1{font-size:clamp(26px,5vw,36px);margin:0 0 6px}
.la-lead{opacity:.75;margin:0 0 22px;line-height:1.55}
.la-panel{background:var(--surface,#181818);border:1px solid var(--border,#303030);border-radius:18px;padding:18px;margin-bottom:16px}
.la-table{width:100%;border-collapse:collapse}
.la-table th,.la-table td{text-align:left;padding:11px 8px;border-bottom:1px solid var(--border,#303030);vertical-align:middle}
.la-table tbody tr:last-child td{border-bottom:0}
.la-table th{font-size:12px;opacity:.65;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.la-pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700}
.la-ok{background:rgba(52,211,153,.16);color:#34d399}
.la-bad{background:rgba(239,107,138,.16);color:#ef6b8a}
.la-new{background:rgba(245,185,66,.18);color:#f5b942;margin-left:6px}
.la-note{background:rgba(245,185,66,.12);border:1px solid rgba(245,185,66,.35);border-radius:14px;padding:14px 16px;margin-bottom:16px;line-height:1.55}
.la-note a{font-weight:700}
.la-small{font-size:13px;opacity:.7}
@media (max-width:640px){.la-col-ip{display:none}}
CSS;
vs_page_start('Login activity', 'login-activity', $extraCss);
?>
<div class="la-page">
    <h1>Login activity</h1>
    <p class="la-lead">Sign-ins to your account <strong>@<?= $h($username) ?></strong> in the last 90 days, newest first. This is only visible to you.</p>

    <?php if ($error !== ''): ?>
        <div class="la-note" role="alert"><?= $h($error) ?></div>
    <?php else: ?>
        <?php if ($failed > 0 || $newDevices > 0): ?>
            <div class="la-note">
                <?php if ($failed > 0): ?><strong><?= (int)$failed ?></strong> wrong-password attempt<?= $failed === 1 ? '' : 's' ?> on your account. <?php endif; ?>
                <?php if ($newDevices > 0): ?><strong><?= (int)$newDevices ?></strong> sign-in<?= $newDevices === 1 ? '' : 's' ?> from a new device or address. <?php endif; ?>
                Do not recognise something? <a href="forgot-password.php">Change your password</a> now.
            </div>
        <?php endif; ?>

        <section class="la-panel">
            <?php if (!$rows): ?>
                <p class="la-small" style="margin:0">Nothing recorded yet. New sign-ins will appear here.</p>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="la-table">
                        <thead><tr><th>When</th><th>Result</th><th>Device</th><th class="la-col-ip">Address</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td title="<?= $h(date('M j, Y g:i A', strtotime((string)$r['created_at']))) ?>"><?= $h($ago((string)$r['created_at'])) ?></td>
                                <td>
                                    <?php if ((int)$r['success']): ?><span class="la-pill la-ok">Signed in</span><?php else: ?><span class="la-pill la-bad">Wrong password</span><?php endif; ?>
                                    <?php if ((int)$r['is_new']): ?><span class="la-pill la-new">New device</span><?php endif; ?>
                                    <?php if ($r['note'] !== ''): ?><span class="la-small"> &middot; <?= $h($r['note']) ?></span><?php endif; ?>
                                </td>
                                <td><?= $h($r['device']) ?></td>
                                <td class="la-col-ip la-small"><?= $h($r['ip']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <p class="la-small">Addresses are shown as your internet provider gives them. Entries older than 90 days are removed automatically.</p>
    <?php endif; ?>
</div>
<?php
vs_page_end();
