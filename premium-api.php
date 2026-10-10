<?php
/**
 * Called by premium.js. Lets the signed-in user send a payment request or cancel Premium.
 * Premium is never switched on here: only an admin approving the payment does that
 * (premium-requests.php).
 * Put this file in the VibeStream root, next to header.php.
 */

// true  = error toasts show the real reason (good while testing on localhost).
// false = generic messages only (use this when the site goes live).
const VS_API_DEBUG = true;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$GLOBALS['vs_sent'] = false;

function vs_json(int $code, array $data): void
{
    $GLOBALS['vs_sent'] = true;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// If anything dies before we answer, still return JSON.
register_shutdown_function(function () {
    if (!empty($GLOBALS['vs_sent'])) {
        return;
    }
    $err = error_get_last();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'Server error' . (VS_API_DEBUG && $err ? ': ' . $err['message'] : '.')]);
});

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/premium-store.php';

$username = trim((string)($_SESSION['username'] ?? ''));
if ($username === '') {
    vs_json(401, ['error' => 'Please sign in first.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    vs_json(405, ['error' => 'Method not allowed.']);
}

$pdo = vs_premium_pdo();
if (!$pdo) {
    vs_json(500, ['error' => 'No database connection.']);
}

$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
if (!hash_equals((string)($_SESSION['premium_csrf'] ?? ''), (string)($in['csrf'] ?? ''))) {
    vs_json(403, ['error' => 'Session expired. Refresh the page and try again.']);
}

try {
    vs_premium_ensure($pdo);
    $action = (string)($in['action'] ?? '');
    if (vs_premium_is_admin($username)) {
        // Admins always have Premium: nothing to buy or cancel.
        vs_json(200, vs_premium_state($pdo, $username));
    }
    if ($action === 'request') {
        if (vs_premium_is_active(vs_premium_get($pdo, $username))) {
            vs_json(400, ['error' => 'You already have Premium.']);
        }
        $err = vs_premium_request_create($pdo, $username, (string)($in['plan'] ?? ''), (string)($in['ref'] ?? ''));
        if ($err !== null) {
            vs_json(400, ['error' => $err]);
        }
    } elseif ($action === 'cancel') {
        vs_premium_cancel($pdo, $username);
    } else {
        vs_json(400, ['error' => 'Unknown action.']);
    }
    vs_json(200, vs_premium_state($pdo, $username));
} catch (Throwable $e) {
    vs_json(500, ['error' => 'Could not save.' . (VS_API_DEBUG ? ' ' . $e->getMessage() : ' Try again.')]);
}