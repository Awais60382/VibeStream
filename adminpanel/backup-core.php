<?php
/*
 * VibeStream - backup engine (shared by backup.php and system-monitor.php).
 * Put this file in the adminpanel folder. It has no page of its own and nothing here needs a session.
 */

const VS_BACKUP_KEEP = 10;          // how many saved backups are kept on the server (older ones are deleted)
const VS_BACKUP_STALE_DAYS = 7;     // warn when the last backup is older than this


/* ---------- helpers ---------- */

function vs_backup_dir(): string
{
    return __DIR__ . '/../backups';
}

function vs_backup_ensure_dir(): bool
{
    $dir = vs_backup_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Options -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
    if (!is_file($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }
    return is_writable($dir);
}

function vs_backup_valid_name(string $name): bool
{
    return (bool)preg_match('/^vibestream-backup-\d{4}-\d{2}-\d{2}_\d{6}\.(zip|sql\.gz|sql)$/', $name);
}

function vs_backup_history(): array
{
    $f = vs_backup_dir() . '/history.json';
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : [];
    return is_array($d) ? $d : [];
}

function vs_backup_history_add(array $entry): void
{
    $h = vs_backup_history();
    array_unshift($h, $entry);
    @file_put_contents(vs_backup_dir() . '/history.json', json_encode(array_slice($h, 0, 50), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** @return string[] saved backup file names, newest first */
function vs_backup_files(): array
{
    $out = [];
    foreach (glob(vs_backup_dir() . '/vibestream-backup-*') ?: [] as $p) {
        if (is_file($p) && vs_backup_valid_name(basename($p))) {
            $out[] = basename($p);
        }
    }
    rsort($out);   // the name starts with the date, so this is newest first
    return $out;
}

function vs_backup_prune(): void
{
    // Leftover temporary dumps from a backup that was interrupted.
    foreach (glob(vs_backup_dir() . '/dump-*') ?: [] as $tmp) {
        if (is_file($tmp) && (time() - (int)@filemtime($tmp)) > 3600) {
            @unlink($tmp);
        }
    }
    foreach (array_slice(vs_backup_files(), VS_BACKUP_KEEP) as $old) {
        @unlink(vs_backup_dir() . '/' . $old);
    }
}

function vs_backup_ends(string $s, string $suffix): bool
{
    return substr($s, -strlen($suffix)) === $suffix;
}

function vs_backup_human(int $bytes): string
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

function vs_backup_ago(int $ts): string
{
    $d = max(0, time() - $ts);
    if ($d < 90)    return 'just now';
    if ($d < 3600)  return floor($d / 60) . ' minutes ago';
    if ($d < 86400) return floor($d / 3600) . ' hours ago';
    $days = (int)floor($d / 86400);
    return $days . ($days === 1 ? ' day ago' : ' days ago');
}

function vs_backup_value(PDO $pdo, $v): string
{
    if ($v === null) {
        return 'NULL';
    }
    $v = (string)$v;
    if ($v !== '' && !mb_check_encoding($v, 'UTF-8')) {
        return '0x' . bin2hex($v);   // binary data
    }
    return $pdo->quote($v);
}

/**
 * Writes the whole database as SQL through $w (tables, rows, views, then triggers).
 * @return array<string,int> table name => number of rows saved
 */
function vs_backup_dump(PDO $pdo, callable $w): array
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Read everything as utf8mb4 so emoji / non-Latin text is not turned into "?".
    $pdo->exec('SET NAMES utf8mb4');
    // Exact values: no PHP float rounding. Rows are streamed instead of loaded in pages.
    // Some PDO builds refuse to READ these settings ("driver does not support that attribute"),
    // so the defaults are assumed and every change is wrapped: if streaming is not possible,
    // the backup simply falls back to normal (buffered) reading.
    $setAttr = static function (int $attr, bool $value) use ($pdo): void {
        try {
            $pdo->setAttribute($attr, $value);
        } catch (Throwable $e) {
            // not supported by this driver: carry on without it
        }
    };
    $setAttr(PDO::ATTR_STRINGIFY_FETCHES, true);
    try {
        $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $w("-- VibeStream database backup\n-- Created: " . date('Y-m-d H:i:s') . "\n-- Database: {$db}\n\n");
        $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        $counts = [];
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        $views  = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as $trow) {
            $t = (string)$trow[0];
            $q = '`' . str_replace('`', '``', $t) . '`';
            $w("-- ----------------------------\n-- Table {$q}\n-- ----------------------------\nDROP TABLE IF EXISTS {$q};\n");
            $create = $pdo->query("SHOW CREATE TABLE {$q}")->fetch(PDO::FETCH_NUM);
            $w($create[1] . ";\n\n");

            $counts[$t] = 0;
            $setAttr(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $st = $pdo->query("SELECT * FROM {$q}");
            $cols = [];
            for ($i = 0, $n = $st->columnCount(); $i < $n; $i++) {
                $meta = $st->getColumnMeta($i);
                $cols[] = '`' . str_replace('`', '``', (string)$meta['name']) . '`';
            }
            $colSql = implode(', ', $cols);
            $vals = [];
            $flush = static function () use (&$vals, $w, $q, $colSql): void {
                if ($vals) {
                    $w("INSERT INTO {$q} ({$colSql}) VALUES\n" . implode(",\n", $vals) . ";\n");
                    $vals = [];
                }
            };
            while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) {
                $vals[] = '(' . implode(', ', array_map(static fn($v) => vs_backup_value($pdo, $v), $r)) . ')';
                $counts[$t]++;
                if (count($vals) >= 100) {
                    $flush();
                }
            }
            $st->closeCursor();
            $setAttr(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            $flush();
            $w("\n");
        }

        foreach ($views as $vrow) {
            $vn = '`' . str_replace('`', '``', (string)$vrow[0]) . '`';
            $c = $pdo->query("SHOW CREATE VIEW {$vn}")->fetch(PDO::FETCH_NUM);
            $stmt = preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/', '', (string)$c[1], 1);
            $w("-- ----------------------------\n-- View {$vn}\n-- ----------------------------\nDROP VIEW IF EXISTS {$vn};\n{$stmt};\n\n");
        }

        // Triggers go last so they do not fire while the data is being restored.
        $triggers = $pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC);
        if ($triggers) {
            $w("-- ----------------------------\n-- Triggers\n-- ----------------------------\nDELIMITER $$\n");
            foreach ($triggers as $tr) {
                $qn = '`' . str_replace('`', '``', (string)$tr['Trigger']) . '`';
                $c = $pdo->query("SHOW CREATE TRIGGER {$qn}")->fetch(PDO::FETCH_NUM);
                $stmt = preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/', '', (string)$c[2], 1);
                $w("DROP TRIGGER IF EXISTS {$qn}$$\n{$stmt}$$\n");
            }
            $w("DELIMITER ;\n\n");
        }
        $w("SET FOREIGN_KEY_CHECKS=1;\n-- End of backup\n");
        return $counts;
    } finally {
        $setAttr(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        $setAttr(PDO::ATTR_STRINGIFY_FETCHES, false);
    }
}

/** Opens the finished backup again and makes sure it is complete (the page promises this check). */
function vs_backup_verify(string $path, string $format, int $uploadFiles): void
{
    $tail = '';
    $keepTail = static function (string $chunk) use (&$tail): void {
        $tail = substr($tail . $chunk, -300);
    };
    if ($format === 'zip') {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('The backup failed its check: the zip file is damaged.');
        }
        if ($zip->numFiles !== $uploadFiles + 2 || $zip->locateName('database.sql') === false) {
            $zip->close();
            throw new RuntimeException('The backup failed its check: files are missing from the zip.');
        }
        $in = $zip->getStream('database.sql');
        if (!$in) {
            $zip->close();
            throw new RuntimeException('The backup failed its check: database.sql cannot be read.');
        }
        while (!feof($in)) {
            $keepTail((string)fread($in, 1048576));
        }
        fclose($in);
        $zip->close();
    } elseif ($format === 'gz') {
        $gz = gzopen($path, 'rb');
        if (!$gz) {
            throw new RuntimeException('The backup failed its check: the compressed file cannot be opened.');
        }
        while (!gzeof($gz)) {
            $keepTail((string)gzread($gz, 1048576));
        }
        gzclose($gz);
    } else {
        $in = fopen($path, 'rb');
        if (!$in) {
            throw new RuntimeException('The backup failed its check: the file cannot be opened.');
        }
        fseek($in, -300, SEEK_END);
        $keepTail((string)stream_get_contents($in));
        fclose($in);
    }
    if (strpos($tail, '-- End of backup') === false) {
        throw new RuntimeException('The backup failed its check: it looks cut off (is the disk full?). Nothing was kept.');
    }
}

/** Sends a backup file to the browser without it being compressed, cut off or stopped half way. */
function vs_backup_stream(string $path, string $name, string $mime): void
{
    @set_time_limit(0);
    ignore_user_abort(true);
    session_write_close();
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    @ini_set('zlib.output_compression', '0');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    clearstatcache(true, $path);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($path);
}

/**
 * Builds a backup file inside the backups folder.
 * $format: 'zip' (database + uploads), 'gz' (database, compressed) or 'sql' (database).
 * @return array{file:string,created:string,by:string,size:int,sha256:string,format:string,tables:int,rows:int,files:int}
 */
function vs_backup_create(PDO $pdo, string $format, string $by): array
{
    if (!vs_backup_ensure_dir()) {
        throw new RuntimeException('Could not create the "backups" folder next to the adminpanel folder. Check folder permissions.');
    }
    $dir = vs_backup_dir();
    $ext = ['zip' => 'zip', 'gz' => 'sql.gz', 'sql' => 'sql'][$format] ?? null;
    if ($ext === null) {
        throw new RuntimeException('Unknown backup type.');
    }
    if ($format === 'zip' && !class_exists('ZipArchive')) {
        throw new RuntimeException('Your PHP has no zip support. Turn on extension=zip in php.ini, or choose a database-only backup.');
    }
    if ($format === 'gz' && !function_exists('gzopen')) {
        throw new RuntimeException('Your PHP has no zlib support. Choose the plain .sql backup instead.');
    }

    @set_time_limit(0);
    $tmpSql = tempnam($dir, 'dump-');
    if ($tmpSql === false) {
        throw new RuntimeException('Could not write to the backups folder.');
    }
    $path = null;
    try {
        $fh = fopen($tmpSql, 'wb');
        if (!$fh) {
            throw new RuntimeException('Could not write to the backups folder.');
        }
        $counts = vs_backup_dump($pdo, static function (string $s) use ($fh): void {
            if ($s !== '' && fwrite($fh, $s) !== strlen($s)) {
                throw new RuntimeException('Could not write the backup. The disk may be full.');
            }
        });
        if (!fclose($fh)) {
            throw new RuntimeException('Could not finish writing the backup. The disk may be full.');
        }

        $stamp = date('Y-m-d_His');
        while (is_file($dir . "/vibestream-backup-{$stamp}.{$ext}")) {
            sleep(1);
            $stamp = date('Y-m-d_His');
        }
        $name = "vibestream-backup-{$stamp}.{$ext}";
        $path = $dir . '/' . $name;
        $files = 0;

        if ($format === 'zip') {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the zip file.');
            }
            $zip->addFile($tmpSql, 'database.sql');
            $up = realpath(__DIR__ . '/../uploads');
            if ($up !== false && is_dir($up)) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($up, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isLink() || !$f->isFile()) {
                        continue;
                    }
                    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($up) + 1));
                    if ($zip->addFile($f->getPathname(), 'uploads/' . $rel)) {
                        $files++;
                    }
                }
            }
            $manifest = "VibeStream backup\nCreated: " . date('Y-m-d H:i:s') . "\nCreated by: {$by}\nUploaded files: {$files}\n\nTable rows:\n";
            foreach ($counts as $t => $n) {
                $manifest .= "  {$t}: {$n}\n";
            }
            $manifest .= "\nTo restore: import database.sql in phpMyAdmin, then copy the uploads folder back into your VibeStream folder.\n";
            $zip->addFromString('manifest.txt', $manifest);
            if (!$zip->close()) {
                throw new RuntimeException('Could not finish the zip file. Check free disk space.');
            }
            @unlink($tmpSql);
        } elseif ($format === 'gz') {
            $in = fopen($tmpSql, 'rb');
            $gz = gzopen($path, 'wb6');
            if (!$in || !$gz) {
                throw new RuntimeException('Could not create the compressed file.');
            }
            while (!feof($in)) {
                $chunk = (string)fread($in, 1048576);
                if ($chunk !== '' && gzwrite($gz, $chunk) === 0) {
                    throw new RuntimeException('Could not write the compressed file. The disk may be full.');
                }
            }
            fclose($in);
            gzclose($gz);
            @unlink($tmpSql);
        } else {
            if (!@rename($tmpSql, $path)) {
                throw new RuntimeException('Could not save the backup file.');
            }
        }
        vs_backup_verify($path, $format, $files);
    } catch (Throwable $e) {
        @unlink($tmpSql);
        if ($path !== null) {
            @unlink($path);
        }
        throw $e;
    }

    return [
        'file' => $name, 'created' => date('Y-m-d H:i:s'), 'by' => $by, 'size' => (int)filesize($path),
        'sha256' => (string)hash_file('sha256', $path), 'format' => $format,
        'tables' => count($counts), 'rows' => (int)array_sum($counts), 'files' => $files,
    ];
}