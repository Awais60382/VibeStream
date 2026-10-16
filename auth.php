<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(405, ['success' => false, 'message' => 'Use POST to start a guest session.']);
}

try {
    $data = json_decode(file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    $respond(400, ['success' => false, 'message' => 'Invalid JSON request.']);
}

if (!is_object($data) || ($data->isGuest ?? false) !== true) {
    $respond(401, ['success' => false, 'message' => 'Sign in or create an account to continue.']);
}

if (session_status() === PHP_SESSION_NONE && !session_start()) {
    error_log('auth.php: could not start a session');
    $respond(500, ['success' => false, 'message' => 'Could not start a guest session.']);
}

if (!session_regenerate_id(true)) {
    error_log('auth.php: could not regenerate the session ID');
    $respond(500, ['success' => false, 'message' => 'Could not start a guest session.']);
}

$_SESSION = [];
$username = 'Guest_' . bin2hex(random_bytes(4));
$_SESSION['username'] = $username;
$_SESSION['role'] = 'Guest';

$respond(200, [
    'success' => true,
    'user' => [
        'username' => $username,
        'role' => 'Guest',
    ],
]);
