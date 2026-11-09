<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function vs_watch_later_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$username = trim((string)($_SESSION['username'] ?? ''));
if ($username === '') {
    vs_watch_later_json(['success' => true, 'loggedIn' => false, 'ids' => []]);
}

if (empty($_SESSION['watch_later_csrf'])) {
    $_SESSION['watch_later_csrf'] = bin2hex(random_bytes(32));
}

try {
    require_once __DIR__ . '/connection.php';
    require_once __DIR__ . '/premium-perks.php';
    $pdo = vs_perk_pdo();
    if (!$pdo) {
        throw new RuntimeException('Watch Later storage is unavailable.');
    }
    vs_watch_later_ensure($pdo);
    $limits = vs_premium_user_limits($pdo, $username);
    $limit = vs_perk_limit($username, 'watch_later');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        vs_watch_later_json([
            'success' => true,
            'loggedIn' => true,
            'ids' => vs_watch_later_ids($pdo, $username),
            'limit' => $limit,
            'csrf' => (string)$_SESSION['watch_later_csrf'],
            'tier' => (string)$limits['tier'],
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        vs_watch_later_json(['error' => 'Method not allowed.'], 405);
    }
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input) || !hash_equals((string)$_SESSION['watch_later_csrf'], (string)($input['csrf'] ?? ''))) {
        vs_watch_later_json(['error' => 'Your session expired. Refresh the page and try again.'], 403);
    }
    $action = (string)($input['action'] ?? '');
    $ids = $action === 'add'
        ? [(string)($input['id'] ?? '')]
        : (is_array($input['ids'] ?? null) ? $input['ids'] : []);
    $result = vs_watch_later_change($pdo, $username, $action, $ids);
    vs_watch_later_json([
        'success' => true,
        'loggedIn' => true,
        'ids' => $result['ids'],
        'skipped' => $result['skipped'],
        'limit' => $limit,
        'tier' => (string)$limits['tier'],
    ]);
} catch (InvalidArgumentException $e) {
    vs_watch_later_json(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('Watch Later API failed: ' . $e->getMessage());
    vs_watch_later_json(['error' => 'Watch Later is temporarily unavailable. Please try again.'], 500);
}
