<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$allowedOrigins = [
    'http://localhost:3000',
    'http://localhost:4173',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:4173',
];
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$originHost = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?: ''));
$originPort = parse_url($origin, PHP_URL_PORT);
$requestPort = $_SERVER['SERVER_PORT'] ?? null;
$requestScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$sameOrigin = $originHost !== '' &&
    $originHost === strtolower((string)(parse_url($requestHost, PHP_URL_HOST) ?: $requestHost)) &&
    ($originPort === null || (string)$originPort === (string)$requestPort) &&
    parse_url($origin, PHP_URL_SCHEME) === $requestScheme;
$originAllowed = in_array($origin, $allowedOrigins, true) ||
    $sameOrigin ||
    ($origin === '' && $_SERVER['REQUEST_METHOD'] === 'GET');
if ($origin !== '' && $originAllowed) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

function site_icon_reply(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!$originAllowed) {
    site_icon_reply(403, ['success' => false, 'message' => 'This admin origin is not allowed.']);
}

$icon192 = __DIR__ . DIRECTORY_SEPARATOR . 'hero-app-192.png';
$icon512 = __DIR__ . DIRECTORY_SEPARATOR . 'hero-app-512.png';
$manifestPath = __DIR__ . DIRECTORY_SEPARATOR . 'manifest.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $manifestContents = file_get_contents($manifestPath);
    $manifest = is_string($manifestContents) ? json_decode($manifestContents, true) : null;
    if (!is_array($manifest)) {
        site_icon_reply(500, ['success' => false, 'message' => 'The site app manifest could not be read.']);
    }

    $version = '';
    foreach (($manifest['icons'] ?? []) as $icon) {
        if (($icon['sizes'] ?? '') === '192x192') {
            parse_str((string)(parse_url((string)($icon['src'] ?? ''), PHP_URL_QUERY) ?? ''), $query);
            $version = (string)($query['v'] ?? '');
            break;
        }
    }

    site_icon_reply(200, [
        'success' => true,
        'version' => $version !== '' ? $version : (string)max(
            (int)(@filemtime($icon192) ?: 0),
            (int)(@filemtime($icon512) ?: 0)
        ),
        'icons' => $manifest['icons'] ?? [],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST, OPTIONS');
    site_icon_reply(405, ['success' => false, 'message' => 'Use GET to view or POST to change the site icon.']);
}

$credential = trim((string)($_POST['credential'] ?? ''));
$email = '';
$adminId = 0;
if ($credential !== '') {
    $context = stream_context_create([
        'http' => ['timeout' => 10, 'ignore_errors' => true],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $tokenInfo = @file_get_contents(
        'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential),
        false,
        $context
    );
    $claims = is_string($tokenInfo) ? json_decode($tokenInfo, true) : null;
    $issuer = is_array($claims) ? (string)($claims['iss'] ?? '') : '';
    $emailVerified = is_array($claims)
        ? in_array($claims['email_verified'] ?? null, ['true', '1', true, 1], true)
        : false;

    if (
        !is_array($claims) ||
        ($claims['aud'] ?? '') !== '778247495161-37fq631q2nm026vm5ofn1qj79vib9au4.apps.googleusercontent.com' ||
        !in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true) ||
        (int)($claims['exp'] ?? 0) <= time() ||
        !$emailVerified ||
        !filter_var($claims['email'] ?? '', FILTER_VALIDATE_EMAIL)
    ) {
        site_icon_reply(401, ['success' => false, 'message' => 'Your Google sign-in could not be verified. Sign in again and retry.']);
    }

    $email = strtolower(trim((string)$claims['email']));
} else {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $adminId = (int)($_SESSION['AdminId'] ?? 0);
    if ($adminId < 1) {
        site_icon_reply(401, ['success' => false, 'message' => 'Sign in to Hero with an admin account before changing the site icon.']);
    }
}

require __DIR__ . DIRECTORY_SEPARATOR . 'connection.php';
if ($email !== '') {
    $statement = $con->prepare('SELECT id, username, role FROM users WHERE LOWER(email) = ? LIMIT 1');
    $statement->bind_param('s', $email);
} else {
    $statement = $con->prepare('SELECT id, username, role FROM users WHERE id = ? LIMIT 1');
    $statement->bind_param('i', $adminId);
}
if (!$statement) {
    error_log('Could not prepare site icon admin lookup: ' . $con->error);
    site_icon_reply(500, ['success' => false, 'message' => 'Could not verify admin permissions.']);
}
$statement->bind_param('s', $email);
if (!$statement->execute()) {
    error_log('Could not execute site icon admin lookup: ' . $statement->error);
    $statement->close();
    site_icon_reply(500, ['success' => false, 'message' => 'Could not verify admin permissions.']);
}
$user = $statement->get_result()->fetch_assoc();
$statement->close();
$role = strtolower(trim((string)($user['role'] ?? '')));
if (!$user || !in_array($role, ['owner', 'admin'], true)) {
    site_icon_reply(403, ['success' => false, 'message' => 'Only Hero owners and admins can change the site icon.']);
}

$uploads = [
    'icon192' => ['path' => $icon192, 'size' => 192],
    'icon512' => ['path' => $icon512, 'size' => 512],
];
$stagedFiles = [];
$backups = [];

try {
    foreach ($uploads as $field => $upload) {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Choose a valid image and try uploading again.');
        }
        if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
            throw new RuntimeException('The generated app icon is too large. Choose a smaller source image.');
        }

        $image = @getimagesize($file['tmp_name']);
        if (
            !is_array($image) ||
            ($image[0] ?? 0) !== $upload['size'] ||
            ($image[1] ?? 0) !== $upload['size'] ||
            ($image['mime'] ?? '') !== 'image/png' ||
            (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) !== 'image/png'
        ) {
            throw new RuntimeException('The icon image could not be validated. Please select an image and retry.');
        }

        $stagedPath = tempnam(__DIR__, '.site-icon-upload-');
        if ($stagedPath === false || !move_uploaded_file($file['tmp_name'], $stagedPath)) {
            if (is_string($stagedPath) && is_file($stagedPath)) {
                unlink($stagedPath);
            }
            throw new RuntimeException('The new icon could not be staged on the server.');
        }
        $stagedFiles[$upload['path']] = $stagedPath;
    }

    $manifestContents = file_get_contents($manifestPath);
    $manifest = is_string($manifestContents) ? json_decode($manifestContents, true) : null;
    if (!is_array($manifest) || !isset($manifest['icons']) || !is_array($manifest['icons'])) {
        throw new RuntimeException('The site app manifest could not be updated.');
    }

    $version = (string)time() . '-' . bin2hex(random_bytes(4));
    foreach ($manifest['icons'] as &$icon) {
        if (($icon['sizes'] ?? '') === '192x192') {
            $icon['src'] = 'hero-app-192.png?v=' . $version;
        } elseif (($icon['sizes'] ?? '') === '512x512') {
            $icon['src'] = 'hero-app-512.png?v=' . $version;
        }
    }
    unset($icon);

    $updatedManifest = json_encode(
        $manifest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    $manifestStage = tempnam(__DIR__, '.site-icon-manifest-');
    if ($manifestStage === false || file_put_contents($manifestStage, $updatedManifest, LOCK_EX) === false) {
        throw new RuntimeException('The updated app manifest could not be staged.');
    }
    $stagedFiles[$manifestPath] = $manifestStage;

    foreach ($stagedFiles as $target => $stagedPath) {
        $backupPath = tempnam(__DIR__, '.site-icon-backup-');
        if ($backupPath === false || !copy($target, $backupPath)) {
            throw new RuntimeException('The existing site icon could not be backed up safely.');
        }
        $backups[$target] = $backupPath;

        if (!rename($stagedPath, $target)) {
            throw new RuntimeException('The new site icon could not be saved.');
        }
        unset($stagedFiles[$target]);
    }
} catch (Throwable $error) {
    foreach ($backups as $target => $backupPath) {
        if (is_file($backupPath)) {
            rename($backupPath, $target);
        }
    }
    foreach (array_merge(array_values($stagedFiles), array_values($backups)) as $temporaryPath) {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }

    if ($error instanceof JsonException) {
        error_log('Could not encode the updated site icon manifest: ' . $error->getMessage());
        site_icon_reply(500, ['success' => false, 'message' => 'The app manifest could not be updated.']);
    }
    if ($error instanceof RuntimeException) {
        site_icon_reply(400, ['success' => false, 'message' => $error->getMessage()]);
    }

    error_log('Could not update the Hero site icon: ' . $error->getMessage());
    site_icon_reply(500, ['success' => false, 'message' => 'The site icon could not be updated. Please retry.']);
}

foreach (array_values($backups) as $backupPath) {
    if (is_file($backupPath)) {
        unlink($backupPath);
    }
}

site_icon_reply(200, [
    'success' => true,
    'message' => 'The Hero site and app icons have been updated.',
    'version' => $version,
]);
