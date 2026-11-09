<?php
/**
 * adminpanel/insights-people.php
 * Used by insights.php: for a list of usernames, returns each person's profile picture and VIP (Premium) level.
 * Admins only. Put this file in the adminpanel folder, next to insights.php.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['AdminSession'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admins only.']);
    exit;
}

require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/../avatar.php';
require_once __DIR__ . '/../premium-store.php';

$names = json_decode((string)($_GET['names'] ?? '[]'), true);
$names = is_array($names) ? array_values(array_unique(array_filter(array_map(
    static fn($n) => mb_substr(trim((string)$n), 0, 80), $names
), static fn($n) => $n !== ''))) : [];
$names = array_slice($names, 0, 100);

$people = [];
foreach ($names as $n) {
    $avatar = '';
    try {
        $avatar = (string)avatar_url($n);
    } catch (Throwable $e) {
        $avatar = '';
    }
    $people[mb_strtolower($n)] = ['name' => $n, 'avatar' => $avatar, 'tier' => ''];
}

try {
    $pdo = vs_premium_pdo();
    if ($pdo && $names) {
        vs_premium_ensure($pdo);
        foreach ($names as $n) {
            $row = vs_premium_get($pdo, $n);
            if (vs_premium_is_active($row)) {
                $plan = (string)$row['plan'];
                $people[mb_strtolower($n)]['tier'] = (strpos($plan, 'plus') === 0 || $plan === 'admin') ? 'plus' : 'premium';
            }
        }
    }
} catch (Throwable $e) {
    error_log('insights-people: ' . $e->getMessage());
}
// People listed as Premium admins always have Premium.
foreach ($names as $n) {
    if (defined('VS_PREMIUM_ADMINS') && in_array(strtolower($n), array_map('strtolower', VS_PREMIUM_ADMINS), true)) {
        $people[mb_strtolower($n)]['tier'] = 'plus';
    }
}
echo json_encode(['ok' => true, 'people' => $people], JSON_UNESCAPED_SLASHES);
