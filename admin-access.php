<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

$body = json_decode(file_get_contents('php://input'), true);
$credential = trim((string)($body['credential'] ?? ''));
if ($credential === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing Google credential']);
    exit;
}

$context = stream_context_create([
    'http' => ['timeout' => 10, 'ignore_errors' => true],
    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
]);
$claims = json_decode(file_get_contents(
    'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential),
    false,
    $context
) ?: '[]', true);

if (!is_array($claims) || ($claims['aud'] ?? '') !== '778247495161-37fq631q2nm026vm5ofn1qj79vib9au4.apps.googleusercontent.com') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid Google credential']);
    exit;
}

require __DIR__ . '/connection.php';
$email = strtolower(trim((string)($claims['email'] ?? '')));
$statement = $con->prepare('SELECT id, username, role FROM users WHERE LOWER(email) = ? LIMIT 1');
$statement->bind_param('s', $email);
$statement->execute();
$user = $statement->get_result()->fetch_assoc();
$statement->close();

$role = trim((string)($user['role'] ?? ''));
if (!$user || !in_array(strtolower($role), ['owner', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

session_regenerate_id(true);
$_SESSION['AdminSession'] = $user['username'];
$_SESSION['AdminId'] = (int)$user['id'];
$_SESSION['AdminRole'] = $role;

echo json_encode([
    'success' => true,
    'role' => $role,
    'name' => $user['username'],
]);
