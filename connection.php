<?php
$appEnvironment = strtolower(trim((string)(getenv('APP_ENV') ?: 'development')));
$isProduction = in_array($appEnvironment, ['production', 'prod'], true);
$appDebug = !$isProduction && filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);

ini_set('display_errors', $appDebug ? '1' : '0');
ini_set('display_startup_errors', $appDebug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$host = getenv('DB_HOST') ?: 'localhost';
$configuredUser = getenv('DB_USER');
$configuredPassword = getenv('DB_PASSWORD');
$user = $configuredUser !== false && $configuredUser !== '' ? $configuredUser : ($isProduction ? '' : 'vs_app_8ea51527bc');
$password = $configuredPassword !== false ? $configuredPassword : ($isProduction ? '' : '3a48c69f7080a85f25c7266ea46d499cea2435a4ad918dd15d5c623295b0e5db');
$dbname = getenv('DB_NAME') ?: 'vibestream';
$port = (int)(getenv('DB_PORT') ?: 3306);

if ($isProduction && (strtolower($user) === 'root' || $user === '' || $password === '')) {
    error_log('VibeStream production startup blocked: configure a non-root DB_USER and non-empty DB_PASSWORD.');
    http_response_code(500);
    exit('The database is unavailable. Configure secure production database credentials.');
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli($host, $user, $password, $dbname, $port);
    $conn->set_charset('utf8mb4');

    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $error) {
    error_log('VibeStream database connection failed: ' . $error->getMessage());
    http_response_code(500);
    exit('The database is unavailable. Check the VibeStream database environment settings.');
}

$vsBanGuardExemptRoutes = ['login.php', 'login-handler.php', 'google-login.php', 'signup.php', 'signup-handler.php'];
if (!in_array(strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? ''))), $vsBanGuardExemptRoutes, true)) {
    require_once __DIR__ . '/ban-guard.php';
    vs_bg_guard();
}