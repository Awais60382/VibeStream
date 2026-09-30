<?php
$host = "localhost";
$user = "root";
$password = "";
$dbname = "vibestream";

$conn = new mysqli($host, $user, $password, $dbname);
if ($conn->connect_error) {
    die("MySQLi Connection failed: " . $conn->connect_error);
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("PDO Connection failed: " . $e->getMessage());
}
?>