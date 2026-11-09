<?php
// brain-sync.php - keeps each account's brain count on the server, so it is the same on every device.
//   GET  -> {"who":"u:name","bundle":{...}|null}
//   POST -> saves the bundle sent by brain.js (text/plain JSON body)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$name = trim((string)($_SESSION['username'] ?? $_SESSION['AdminSession'] ?? ''));
$csrf = (string)($_SESSION['vs_brain_sync_csrf'] ?? '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['vs_brain_sync_csrf'] = $csrf;
}
session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($name === '') {
    http_response_code(401);
    echo json_encode(['who' => 'guest', 'bundle' => null]);
    exit;
}
$who = 'u:' . mb_strtolower($name);
$key = mb_strtolower($name);

try {
    ob_start();
    include __DIR__ . '/connection.php';   // $pdo (PDO) and/or $conn (mysqli)
    ob_end_clean();

    $run = null;   // run($sql, $params, $wantRow) -> first row | true
    if (isset($pdo) && $pdo instanceof PDO) {
        $run = function (string $sql, array $p = [], bool $row = false) use ($pdo) {
            $st = $pdo->prepare($sql);
            $st->execute(array_values($p));
            return $row ? ($st->fetch(PDO::FETCH_ASSOC) ?: null) : true;
        };
    } elseif (isset($conn) && $conn instanceof mysqli) {
        $run = function (string $sql, array $p = [], bool $row = false) use ($conn) {
            $st = $conn->prepare($sql);
            if ($p) $st->bind_param(str_repeat('s', count($p)), ...array_values($p));
            $st->execute();
            $out = true;
            if ($row) { $res = $st->get_result(); $out = $res ? ($res->fetch_assoc() ?: null) : null; }
            $st->close();
            return $out;
        };
    }
    if (!$run) throw new RuntimeException('no database connection');

    $run('CREATE TABLE IF NOT EXISTS `brain_state` (
            `username` VARCHAR(191) NOT NULL,
            `bundle` TEXT NOT NULL,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`username`)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw) > 20000) {
            http_response_code(413);
            echo json_encode(['ok' => false, 'error' => 'payload_too_large']);
            exit;
        }
        $in = json_decode((string)$raw, true);
        if (!is_array($in)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
            exit;
        }
        if (!is_string($in['who'] ?? null) || !hash_equals($who, $in['who'])) { // stale tabs must not write as another account
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'account_mismatch']);
            exit;
        }
        $dateOk = static function ($value): bool {
            if (!is_string($value) || preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $parts) !== 1) return false;
            return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
        };
        $num = fn($v, $max) => max(0, min($max, (int)$v));
        $st = is_array($in['state'] ?? null) ? $in['state'] : [];
        $clean = ['state' => null, 'hist' => [], 'snooze' => '', 'seen' => 0];
        if ($dateOk($st['date'] ?? null)) {
            $clean['state'] = [
                'date'   => $st['date'],
                'videos' => $num($st['videos'] ?? 0, 10000000),
                'shorts' => $num($st['shorts'] ?? 0, 10000000),
                'nudged' => $num($st['nudged'] ?? 0, 4),
                'ts'     => $num($st['ts'] ?? 0, 99999999999999),
            ];
        }
        foreach (array_slice(is_array($in['hist'] ?? null) ? $in['hist'] : [], -30) as $e) {
            if (is_array($e) && $dateOk($e['d'] ?? null)) $clean['hist'][] = ['d' => $e['d'], 't' => $num($e['t'] ?? 0, 10000000)];
        }
        if ($dateOk($in['snooze'] ?? null)) $clean['snooze'] = $in['snooze'];
        $clean['seen'] = $num($in['seen'] ?? 0, 99999999999999);
        if (!hash_equals($csrf, (string)($in['csrf'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['ok' => false]);
            exit;
        }

        $run('INSERT INTO `brain_state` (`username`, `bundle`) VALUES (?, ?)
              ON DUPLICATE KEY UPDATE `bundle` = VALUES(`bundle`), `updated_at` = CURRENT_TIMESTAMP',
             [$key, json_encode($clean)]);
        echo json_encode(['ok' => true]);
        exit;
    }

    $row = $run('SELECT `bundle` FROM `brain_state` WHERE `username` = ? LIMIT 1', [$key], true);
    $bundle = $row ? json_decode((string)$row['bundle'], true) : null;
    echo json_encode(['who' => $who, 'bundle' => is_array($bundle) ? $bundle : null, 'csrf' => $csrf]);
} catch (Throwable $e) {
    error_log('brain-sync.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['who' => $who, 'bundle' => null]);
}
