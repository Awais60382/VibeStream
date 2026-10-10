<?php
if (!defined('AVATAR_DIR')) {
    define('AVATAR_DIR', __DIR__ . '/uploads/avatars');
}

if (!function_exists('avatar_key')) {

    function avatar_key($username) {
        return md5(mb_strtolower(trim((string)$username)));
    }


    function avatar_path($username) {
        if (trim((string)$username) === '') return '';
        foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
            $p = AVATAR_DIR . '/' . avatar_key($username) . '.' . $ext;
            if (is_file($p)) return $p;
        }
        return '';
    }


    function avatar_file_url($path) {
        $file = realpath($path);
        $project = realpath(__DIR__);
        if ($file === false || $project === false || strncasecmp($file, $project . DIRECTORY_SEPARATOR, strlen($project . DIRECTORY_SEPARATOR)) !== 0) {
            return '';
        }

        $relative = str_replace('\\', '/', substr($file, strlen($project) + 1));
        $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($documentRoot !== false && strncasecmp($file, rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, strlen(rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) === 0) {
            $urlPath = '/' . str_replace('\\', '/', substr($file, strlen(rtrim($documentRoot, DIRECTORY_SEPARATOR)) + 1));
        } else {
            $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
            if (preg_match('~/(?:adminpanel)(?:/adminpanel)?/.*$~', $scriptName, $match, PREG_OFFSET_CAPTURE)) {
                $basePath = substr($scriptName, 0, $match[0][1]);
            } else {
                $basePath = dirname($scriptName);
            }
            $urlPath = rtrim($basePath, '/') . '/' . $relative;
        }

        return $urlPath . '?v=' . filemtime($file);
    }

    function avatar_stored_url($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value)) {
            return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
        }
        if (str_starts_with($value, '//')) {
            return filter_var('https:' . $value, FILTER_VALIDATE_URL) ? 'https:' . $value : '';
        }

        $path = preg_match('/^[a-z]:[\\\\\/]/i', $value)
            ? $value
            : parse_url($value, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || preg_match('~(?:^|[\\\\/])\.\.(?:[\\\\/]|$)~', $path)) {
            return '';
        }

        $candidates = [];
        if (preg_match('/^[a-z]:[\\\\\/]/i', $path)) {
            $candidates[] = $path;
        } elseif (str_starts_with($path, '/')) {
            $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
            if ($documentRoot !== false) {
                $candidates[] = $documentRoot . str_replace('/', DIRECTORY_SEPARATOR, $path);
            }
        } else {
            $relative = ltrim(preg_replace('~^(?:\./)+~', '', str_replace('\\', '/', $path)), '/');
            $candidates[] = __DIR__ . '/' . $relative;
            if (str_starts_with($relative, 'assets/images/avatar/')) {
                $candidates[] = __DIR__ . '/adminpanel/' . $relative;
            }
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $url = avatar_file_url($candidate);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        return '';
    }

    function avatar_database_url($username) {
        static $cache = [];
        static $pdoColumns = null;
        static $mysqliColumns = null;
        static $queryErrorLogged = false;
        $key = mb_strtolower(trim((string)$username));
        if ($key === '' || array_key_exists($key, $cache)) {
            return $cache[$key] ?? '';
        }

        global $pdo;
        $avatarCandidates = ['avatar', 'profile_image', 'profile_picture', 'profile_pic', 'profile_photo', 'avatar_url', 'avatar_path', 'user_avatar', 'photo', 'picture', 'image'];
        $usernameCandidates = ['username', 'user_name', 'login', 'uname'];

        if (isset($pdo) && $pdo instanceof PDO) {
            try {
                if ($pdoColumns === null) {
                    $pdoColumns = [];
                    $result = $pdo->query('SHOW COLUMNS FROM `users`');
                    if ($result) {
                        foreach ($result->fetchAll(PDO::FETCH_ASSOC) as $column) {
                            $pdoColumns[strtolower((string)$column['Field'])] = (string)$column['Field'];
                        }
                    }
                }

                $usernameColumn = null;
                foreach ($usernameCandidates as $candidate) {
                    if (isset($pdoColumns[$candidate])) {
                        $usernameColumn = $pdoColumns[$candidate];
                        break;
                    }
                }
                $avatarColumn = null;
                foreach ($avatarCandidates as $candidate) {
                    if (isset($pdoColumns[$candidate])) {
                        $avatarColumn = $pdoColumns[$candidate];
                        break;
                    }
                }
                if ($usernameColumn !== null && $avatarColumn !== null) {
                    $quote = static function (string $identifier): string {
                        return '`' . str_replace('`', '``', $identifier) . '`';
                    };
                    $statement = $pdo->prepare('SELECT ' . $quote($avatarColumn) . ' FROM `users` WHERE ' . $quote($usernameColumn) . ' = :username LIMIT 1');
                    $statement->execute([':username' => $username]);
                    $url = avatar_stored_url($statement->fetchColumn() ?: '');
                    if ($url !== '') {
                        return $cache[$key] = $url;
                    }
                }
            } catch (Throwable $error) {
                if (!$queryErrorLogged) {
                    error_log('Could not resolve profile avatars from the users table: ' . $error->getMessage());
                    $queryErrorLogged = true;
                }
            }
        }

        global $conn;
        if (isset($conn) && $conn instanceof mysqli && $conn->thread_id !== 0) {
            try {
                if ($mysqliColumns === null) {
                    $mysqliColumns = [];
                    $result = $conn->query('SHOW COLUMNS FROM `users`');
                    if ($result) {
                        while ($column = $result->fetch_assoc()) {
                            $mysqliColumns[strtolower((string)$column['Field'])] = (string)$column['Field'];
                        }
                        $result->free();
                    }
                }

                $usernameColumn = null;
                foreach ($usernameCandidates as $candidate) {
                    if (isset($mysqliColumns[$candidate])) {
                        $usernameColumn = $mysqliColumns[$candidate];
                        break;
                    }
                }
                $avatarColumn = null;
                foreach ($avatarCandidates as $candidate) {
                    if (isset($mysqliColumns[$candidate])) {
                        $avatarColumn = $mysqliColumns[$candidate];
                        break;
                    }
                }
                if ($usernameColumn !== null && $avatarColumn !== null) {
                    $quote = static function (string $identifier): string {
                        return '`' . str_replace('`', '``', $identifier) . '`';
                    };
                    $statement = $conn->prepare('SELECT ' . $quote($avatarColumn) . ' FROM `users` WHERE ' . $quote($usernameColumn) . ' = ? LIMIT 1');
                    if (!$statement) {
                        throw new RuntimeException('Could not prepare the profile avatar lookup.');
                    }
                    $statement->bind_param('s', $username);
                    if (!$statement->execute()) {
                        $statement->close();
                        throw new RuntimeException('Could not execute the profile avatar lookup.');
                    }
                    $statement->bind_result($storedAvatar);
                    $statement->fetch();
                    $statement->close();
                    $url = avatar_stored_url($storedAvatar ?? '');
                    if ($url !== '') {
                        return $cache[$key] = $url;
                    }
                }
            } catch (Throwable $error) {
                if (!$queryErrorLogged) {
                    error_log('Could not resolve profile avatars from the users table: ' . $error->getMessage());
                    $queryErrorLogged = true;
                }
            }
        }

        return $cache[$key] = '';
    }

    function avatar_url($username, $storedAvatar = '') {
        $localPath = avatar_path($username);
        if ($localPath !== '') {
            return avatar_file_url($localPath);
        }

        $databaseAvatar = avatar_database_url($username);
        if ($databaseAvatar !== '') {
            return $databaseAvatar;
        }

        return avatar_stored_url($storedAvatar);
    }

    function avatar_for_record($username, array $record) {
        $currentAvatar = avatar_url($username);
        if ($currentAvatar !== '') {
            return $currentAvatar;
        }

        foreach (['avatar_url', 'profile_image', 'profile_picture', 'profile_pic', 'profile_photo', 'avatar', 'avatar_path', 'user_avatar', 'photo', 'picture', 'image'] as $column) {
            if (!empty($record[$column])) {
                $recordAvatar = avatar_stored_url((string)$record[$column]);
                if ($recordAvatar !== '') {
                    return $recordAvatar;
                }
            }
        }

        return '';
    }

    function avatar_import_google($username, $url) {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (
            parse_url($url, PHP_URL_SCHEME) !== 'https'
            || !preg_match('/(^|\.)googleusercontent\.com$/', $host)
            || (int)(parse_url($url, PHP_URL_PORT) ?: 443) !== 443
        ) {
            return false;
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXFILESIZE => 2 * 1024 * 1024,
        ]);
        $contents = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $mime = (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);

        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        $mime = strtolower(trim(explode(';', $mime)[0]));
        if (
            !is_string($contents)
            || $status !== 200
            || strlen($contents) > 2 * 1024 * 1024
            || !isset($extensions[$mime])
        ) {
            return false;
        }

        $image = @getimagesizefromstring($contents);
        if ($image === false || ($image['mime'] ?? '') !== $mime) {
            return false;
        }
        if (!is_dir(AVATAR_DIR) && !@mkdir(AVATAR_DIR, 0775, true)) {
            return false;
        }

        $destination = AVATAR_DIR . '/' . avatar_key($username) . '.' . $extensions[$mime];
        return file_put_contents($destination, $contents, LOCK_EX) !== false;
    }


    function avatar_html($username, $size = 36, $extraClass = '') {
        $username = trim((string)$username);
        $px  = (int)$size;
        $cls = htmlspecialchars('avatar ' . $extraClass);
        $box = 'width:' . $px . 'px;height:' . $px . 'px;border-radius:50%;flex-shrink:0;';
        $url = avatar_url($username);

        if ($url !== '') {
            return '<img class="' . $cls . '" src="' . htmlspecialchars($url) . '" alt="" style="' . $box . 'object-fit:cover;display:block;">';
        }

        $letter = $username !== '' ? mb_strtoupper(mb_substr($username, 0, 1)) : 'G';
        $hue = function_exists('avatar_hue') ? (int)avatar_hue($username) : 210;
        return '<span class="' . $cls . '" style="' . $box . 'display:inline-flex;align-items:center;justify-content:center;'
             . 'font-weight:700;color:#fff;font-size:' . round($px * 0.42) . 'px;background:hsl(' . $hue . ' 55% 42%);">'
             . htmlspecialchars($letter) . '</span>';
    }


    function avatar_delete($username) {
        foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
            $p = AVATAR_DIR . '/' . avatar_key($username) . '.' . $ext;
            if (is_file($p)) @unlink($p);
        }
    }

    function avatar_save($username, $file) {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 'Please choose an image first.';
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return 'That image is too large (max 2 MB).';
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return 'Upload failed, please try again.';
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            return 'That image is too large (max 2 MB).';
        }

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $mime = (string)finfo_file($fi, $file['tmp_name']);
            finfo_close($fi);
        }
        if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
            return 'Only JPG, PNG, WEBP or GIF images are allowed.';
        }

        if (!is_dir(AVATAR_DIR) && !@mkdir(AVATAR_DIR, 0775, true)) {
            return 'Could not create the uploads/avatars folder.';
        }

        avatar_delete($username);
        $dest = AVATAR_DIR . '/' . avatar_key($username) . '.' . $allowed[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return 'Could not save the image.';
        }
        return '';
    }
}
