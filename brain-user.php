<?php
// brain-user.php - tells brain.js which account is signed in so each account keeps its own brain count.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$name = trim((string)($_SESSION['username'] ?? ''));
if ($name === '') {
    $name = trim((string)($_SESSION['AdminSession'] ?? ''));
}
session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['who' => $name === '' ? 'guest' : 'u:' . mb_strtolower($name)]);