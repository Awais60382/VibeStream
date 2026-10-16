<?php
/*
 * VibeStream admin panel - backup engine (no page, no login check here).
 * Used by backup.php and by the System check "Make a backup now" repair button.
 * Put this file in the adminpanel folder, next to backup.php.
 *
 *   vs_backup_create($pdo, 'zip' | 'gz' | 'sql', 'who')  builds a backup file in ../backups and returns its details
 *   vs_backup_history_add($entry) / vs_backup_prune() / vs_backup_files() / vs_backup_human($bytes)
 */
if (defined('VS_BACKUP_CORE_LOADED')) {
    return;
}
define('VS_BACKUP_CORE_LOADED', true);

if (!defined('VS_BACKUP_KEEP')) {
    define('VS_BACKUP_KEEP', 10);            // how many saved backups are kept on the server (older ones are deleted)
}
if (!defined('VS_BACKUP_STALE_DAYS')) {
    define('VS_BACKUP_STALE_DAYS', 7);       // warn when the last backup is older than this
}


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
 * Writes the whole database as SQL through $w (tables, rows, then triggers).
 * @return array<string,int> table name => number of rows saved
 */
function vs_backup_dump(PDO $pdo, callable $w): array
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $w("-- VibeStream database backup\n-- Created: " . date('Y-m-d H:i:s') . "\n-- Database: {$db}\n\n");
    $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

    $counts = [];
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $trow) {
        $t = (string)$trow[0];
        $q = '`' . str_replace('`', '``', $t) . '`';
        $w("-- ----------------------------\n-- Table {$q}\n-- ----------------------------\nDROP TABLE IF EXISTS {$q};\n");
        $create = $pdo->query("SHOW CREATE TABLE {$q}")->fetch(PDO::FETCH_NUM);
        $w($create[1] . ";\n\n");

        $counts[$t] = 0;
        $offset = 0;
        $batch = 500;
        while (true) {
            $rows = $pdo->query("SELECT * FROM {$q} LIMIT {$batch} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }
            $cols = implode(', ', array_map(static fn($c) => '`' . str_replace('`', '``', (string)$c) . '`', array_keys($rows[0])));
            foreach (array_chunk($rows, 100) as $chunk) {
                $vals = [];
                foreach ($chunk as $r) {
                    $vals[] = '(' . implode(', ', array_map(static fn($v) => vs_backup_value($pdo, $v), array_values($r))) . ')';
                }
                $w("INSERT INTO {$q} ({$cols}) VALUES\n" . implode(",\n", $vals) . ";\n");
            }
            $counts[$t] += count($rows);
            $w("\n");
            $offset += $batch;
            if (count($rows) < $batch) {
                break;
            }
        }
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
    try {
        $fh = fopen($tmpSql, 'wb');
        $counts = vs_backup_dump($pdo, static function (string $s) use ($fh): void { fwrite($fh, $s); });
        fclose($fh);

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
                    $zip->addFile($f->getPathname(), 'uploads/' . $rel);
                    $files++;
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
                gzwrite($gz, (string)fread($in, 1048576));
            }
            fclose($in);
            gzclose($gz);
            @unlink($tmpSql);
        } else {
            if (!@rename($tmpSql, $path)) {
                throw new RuntimeException('Could not save the backup file.');
            }
        }
    } catch (Throwable $e) {
        @unlink($tmpSql);
        throw $e;
    }

    return [
        'file' => $name, 'created' => date('Y-m-d H:i:s'), 'by' => $by, 'size' => (int)filesize($path),
        'sha256' => (string)hash_file('sha256', $path), 'format' => $format,
        'tables' => count($counts), 'rows' => (int)array_sum($counts), 'files' => $files,
    ];
}