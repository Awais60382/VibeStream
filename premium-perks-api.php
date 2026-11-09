<?php
/**
 * VibeStream - what the signed-in visitor's plan allows. Used by video-menu.js (Watch later limit, Download).
 * Put this file in the VibeStream main folder.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    require_once __DIR__ . '/connection.php';
    require_once __DIR__ . '/premium-perks.php';
    $me = trim((string)($_SESSION['username'] ?? ''));
    $limits = vs_premium_user_limits(vs_perk_pdo(), $me);
    echo json_encode(['loggedIn' => $me !== '', 'tier' => (string)$limits['tier'], 'limits' => $limits, 'unlimited' => VS_PERK_UNLIMITED]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'unavailable']);
}
