<?php
// brain-user.php - tells brain.js which account is signed in, so every account keeps its own brain count.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$name = trim((string)($_SESSION['username'] ?? $_SESSION['AdminSession'] ?? ''));
session_write_close();   // read-only: do not hold the session lock
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['who' => $name === '' ? 'guest' : 'u:' . mb_strtolower($name)]);
