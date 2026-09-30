<?php
$_SESSION['username'] = $row['username'];
$_SESSION['role']     = $row['role']; 

header('Content-Type: application/json');
require 'connection.php'; 

$data = json_decode(file_get_contents('php://input'), true);
$isGuest = $data['isGuest'] ?? false;
$username = trim($data['username'] ?? '');

if ($isGuest) {
    $username = 'Guest_' . rand(1000, 9999);
    $role = 'Guest';
} else {
    if (empty($username)) {
        echo json_encode(['success' => false, 'message' => 'Username required']);
        exit;
    }
    $role = 'Member';
}

$stmt = $pdo->prepare("INSERT INTO users (username, role) VALUES (?, ?)");
$stmt->execute([$username, $role]);

echo json_encode([
    'success' => true,
    'user' => [
        'username' => $username,
        'role' => $role
    ]
]);
?>