<?php
// Temporary check page: shows which account the admin panel thinks you are. Delete it when done.
require_once __DIR__ . '/admin-bootstrap.php';

$e = static function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

$sessionKeys = ['AdminSession', 'AdminID', 'username', 'UserSession', 'user_id', 'id', 'role', 'email'];
$sessionRows = [];
foreach ($sessionKeys as $k) {
    $sessionRows[$k] = array_key_exists($k, $_SESSION) ? (string)$_SESSION[$k] : '(not set)';
}

$matched = null;
$all = [];
$error = '';
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        if ($matchCol) {
            $st = $pdo->prepare("SELECT * FROM `users` WHERE `$matchCol` = :v LIMIT 1");
            $st->execute([':v' => $matchVal]);
            $matched = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $all = $pdo->query('SELECT * FROM `users` ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}
$hide = static function (array $row): array {
    foreach (array_keys($row) as $k) {
        if (preg_match('/pass|token|secret/i', (string)$k)) {
            unset($row[$k]);
        }
    }
    return $row;
};
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Who am I</title>
<style>
body{font-family:Arial,sans-serif;padding:24px;background:#f4f5f7}
table{border-collapse:collapse;background:#fff;margin:8px 0 22px}
td,th{border:1px solid #ccc;padding:6px 10px;font-size:14px;text-align:left}
th{background:#eee}
</style></head><body>
<h2>Login session</h2>
<table><?php foreach ($sessionRows as $k => $v): ?><tr><th><?php echo $e($k); ?></th><td><?php echo $e($v); ?></td></tr><?php endforeach; ?></table>

<h2>How the admin panel finds your row</h2>
<table>
<tr><th>Matching column</th><td><?php echo $e($matchCol ?: '(none)'); ?></td></tr>
<tr><th>Matching value</th><td><?php echo $e($matchVal); ?></td></tr>
<tr><th>Username column</th><td><?php echo $e($unameCol ?: '(none)'); ?></td></tr>
<tr><th>Display name column</th><td><?php echo $e($nameCol ?: '(none)'); ?></td></tr>
<tr><th>Name shown in the top bar</th><td><?php echo $e($adminDisplayName); ?></td></tr>
</table>

<h2>The row it found</h2>
<?php if ($error !== ''): ?><p><?php echo $e($error); ?></p><?php endif; ?>
<?php if ($matched): ?>
<table><?php foreach ($hide($matched) as $k => $v): ?><tr><th><?php echo $e($k); ?></th><td><?php echo $e($v); ?></td></tr><?php endforeach; ?></table>
<?php else: ?><p><b>No row found.</b> Your login does not match any account in the users table.</p><?php endif; ?>

<h2>All users</h2>
<?php if ($all): $first = $hide($all[0]); ?>
<table><tr><?php foreach (array_keys($first) as $k): ?><th><?php echo $e($k); ?></th><?php endforeach; ?></tr>
<?php foreach ($all as $row): ?><tr><?php foreach ($hide($row) as $v): ?><td><?php echo $e($v); ?></td><?php endforeach; ?></tr><?php endforeach; ?></table>
<?php endif; ?>
</body></html>
