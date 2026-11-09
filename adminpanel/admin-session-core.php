<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function vs_admin_secure_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function vs_admin_mark_authenticated_session(string $name, string $id = '', string $role = 'admin'): void
{
    $name = trim($name);
    if ($name === '') return;

    $_SESSION['AdminSession'] = $name;
    if ($id !== '') $_SESSION['AdminID'] = $id;
    $_SESSION['role'] = $role;
    $_SESSION['vs_admin_authenticated'] = 1;
    $_SESSION['vs_admin_identity'] = hash(
        'sha256',
        mb_strtolower($name) . '|' . $id . '|' . strtolower(trim($role))
    );
}

function vs_admin_session_is_valid(): bool
{
    $name = trim((string)($_SESSION['AdminSession'] ?? ''));
    $id = trim((string)($_SESSION['AdminID'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? ''));
    $role = trim((string)($_SESSION['role'] ?? ''));

    if ($name === '' || empty($_SESSION['vs_admin_authenticated'])) return false;

    $expected = hash(
        'sha256',
        mb_strtolower($name) . '|' . $id . '|' . strtolower($role)
    );
    return hash_equals((string)($_SESSION['vs_admin_identity'] ?? ''), $expected);
}

function vs_admin_require_session(): void
{
    vs_admin_secure_session_start();
    if (!vs_admin_session_is_valid()) {
        http_response_code(403);
        header('Location: ../login.php');
        exit;
    }
}
