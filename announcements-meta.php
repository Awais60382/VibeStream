<?php
/*
 * announcements-meta.php
 * Extra data for announcements (view counter + thumbnail) in its own table,
 * so announcements-store.php does not need to change.
 * Put this file next to announcements-store.php (the site root).
 */

function vs_um_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS vs_update_meta (
            update_id INT UNSIGNED NOT NULL PRIMARY KEY,
            views INT UNSIGNED NOT NULL DEFAULT 0,
            thumb VARCHAR(255) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

/** @return array<int, array{views:int, thumb:?string}> keyed by announcement id */
function vs_um_all(PDO $pdo): array
{
    vs_um_ensure($pdo);
    $out = [];
    foreach ($pdo->query('SELECT update_id, views, thumb FROM vs_update_meta') as $row) {
        $out[(int)$row['update_id']] = [
            'views' => (int)$row['views'],
            'thumb' => $row['thumb'] !== null && $row['thumb'] !== '' ? (string)$row['thumb'] : null,
        ];
    }
    return $out;
}

function vs_um_hit(PDO $pdo, int $id): void
{
    if ($id <= 0) {
        return;
    }
    vs_um_ensure($pdo);
    $st = $pdo->prepare(
        'INSERT INTO vs_update_meta (update_id, views) VALUES (?, 1)
         ON DUPLICATE KEY UPDATE views = views + 1'
    );
    $st->execute([$id]);
}

function vs_um_set_thumb(PDO $pdo, int $id, ?string $path): void
{
    if ($id <= 0) {
        return;
    }
    vs_um_ensure($pdo);
    $st = $pdo->prepare(
        'INSERT INTO vs_update_meta (update_id, thumb) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE thumb = VALUES(thumb)'
    );
    $st->execute([$id, $path]);
}

function vs_um_delete(PDO $pdo, int $id): void
{
    vs_um_ensure($pdo);
    $st = $pdo->prepare('SELECT thumb FROM vs_update_meta WHERE update_id = ?');
    $st->execute([$id]);
    $thumb = (string)$st->fetchColumn();
    vs_um_remove_file($thumb);
    $pdo->prepare('DELETE FROM vs_update_meta WHERE update_id = ?')->execute([$id]);
}

/** Deletes a thumbnail file, but only inside uploads/announcements/. */
function vs_um_remove_file(?string $relPath): void
{
    if (!$relPath || strpos($relPath, 'uploads/announcements/') !== 0 || strpos($relPath, '..') !== false) {
        return;
    }
    $full = __DIR__ . '/' . $relPath;
    if (is_file($full)) {
        @unlink($full);
    }
}

/**
 * Validates and stores an uploaded thumbnail. Returns the path relative to the
 * site root, or null when nothing was uploaded. Throws RuntimeException on a bad file.
 */
function vs_um_store_upload(array $file): ?string
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The thumbnail could not be uploaded. Try a smaller image.');
    }
    if ((int)$file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('The thumbnail must be 2 MB or smaller.');
    }
    if (!is_uploaded_file((string)$file['tmp_name'])) {
        throw new RuntimeException('The thumbnail upload was not valid.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('The thumbnail must be a JPG, PNG, WebP or GIF image.');
    }
    $dir = __DIR__ . '/uploads/announcements';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('The thumbnail folder could not be created.');
    }
    $name = 'ann_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('The thumbnail could not be saved.');
    }
    return 'uploads/announcements/' . $name;
}
