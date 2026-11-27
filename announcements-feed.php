<?php
/*
 * announcement-feed.php  (site root, next to announcements-store.php)
 *   GET  -> JSON list of published announcements that are already live, newest first,
 *           each flagged "upcoming" when it has an event date in the future.
 *   POST -> action=hit&id=N  counts one view (once per browser session per announcement).
 */
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/announcements-store.php';
require_once __DIR__ . '/announcements-meta.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function vs_feed_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    vs_updates_ensure($pdo);
    vs_um_ensure($pdo);
    $now = time();

    $live = [];
    foreach (vs_updates_admin_all($pdo) as $u) {
        $pub = strtotime((string)$u['publish_at']);
        if (($u['status'] ?? '') === 'published' && $pub && $pub <= $now) {
            $live[(int)$u['id']] = $u;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        if (($_POST['action'] ?? '') !== 'hit' || !isset($live[$id])) {
            vs_feed_out(['ok' => false], 400);
        }
        $seen = $_SESSION['vs_ann_viewed'] ?? [];
        if (empty($seen[$id])) {
            vs_um_hit($pdo, $id);
            $seen[$id] = 1;
            $_SESSION['vs_ann_viewed'] = $seen;
        }
        vs_feed_out(['ok' => true]);
    }

    $meta = vs_um_all($pdo);
    $items = [];
    foreach ($live as $id => $u) {
        $kind = (string)($u['kind'] ?? 'announcement');
        $evt = !empty($u['event_at']) ? strtotime((string)$u['event_at']) : 0;
        $body = trim((string)$u['body']);
        $items[] = [
            'id' => $id,
            'title' => (string)$u['title'],
            'body' => mb_strlen($body) > 400 ? mb_substr($body, 0, 400) . '…' : $body,
            'kind' => $kind,
            'kindLabel' => VS_UPDATE_TYPES[$kind] ?? 'Announcement',
            'publishAt' => date('c', strtotime((string)$u['publish_at'])),
            'eventAt' => $evt ? date('c', $evt) : null,
            'upcoming' => $evt > $now,
            'thumb' => !empty($meta[$id]['thumb']) ? $meta[$id]['thumb'] : null,
        ];
    }
    usort($items, static function ($a, $b) {
        return strcmp($b['publishAt'], $a['publishAt']);
    });
    vs_feed_out(['ok' => true, 'items' => array_slice($items, 0, 20)]);
} catch (Throwable $e) {
    error_log('announcement-feed failed: ' . $e->getMessage());
    vs_feed_out(['ok' => false, 'items' => []], 500);
}