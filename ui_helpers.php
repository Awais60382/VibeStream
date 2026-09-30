<?php


function vs_e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function vs_q(string $id): string
{
    return '`' . str_replace('`', '', $id) . '`';
}

function vs_cols(PDO $pdo, string $table): array
{
    $out = [];
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM ' . vs_q($table));
        if (!$stmt) {
            return [];
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[strtolower($row['Field'])] = $row['Field'];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}
function vs_pick(array $cols, array $candidates): ?string
{
    foreach ($candidates as $c) {
        if (isset($cols[$c])) {
            return $cols[$c];
        }
    }
    return null;
}

function vs_ago(?string $when): string
{
    if (!$when) {
        return '—';
    }
    $ts = strtotime($when);
    if (!$ts) {
        return '—';
    }
    $d = time() - $ts;
    if ($d < 60) {
        return 'Just now';
    }
    if ($d < 3600) {
        return floor($d / 60) . ' min ago';
    }
    if ($d < 86400) {
        return floor($d / 3600) . ' hr ago';
    }
    if ($d < 86400 * 30) {
        $n = (int)floor($d / 86400);
        return $n . ($n === 1 ? ' day ago' : ' days ago');
    }
    return date('M d, Y', $ts);
}

function vs_initials(string $name): string
{
    $parts = preg_split('/[\s._@-]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) {
        return '?';
    }
    $s = mb_substr($parts[0], 0, 1);
    if (count($parts) > 1) {
        $s .= mb_substr(end($parts), 0, 1);
    }
    return mb_strtoupper($s);
}

function vs_avatar_src(?string $v, string $base = '../'): string
{
    $v = trim((string)$v);
    if ($v === '') {
        return '';
    }
    if (preg_match('#^(https?:)?//|^/|^data:#i', $v)) {
        return $v;
    }
    return $base . ltrim($v, '/');
}

function vs_avatar(string $name, string $src = ''): string
{
    $hue = abs(crc32(strtolower($name))) % 360;
    $hue2 = ($hue + 40) % 360;
    $html = '<span class="vs-avatar" style="background:linear-gradient(135deg,hsl(' . $hue . ' 65% 45%),hsl(' . $hue2 . ' 70% 35%))">'
          . vs_e(vs_initials($name));
    if ($src !== '') {
        $html .= '<img src="' . vs_e($src) . '" alt="" loading="lazy" onerror="this.remove()">';
    }
    return $html . '</span>';
}

function vs_status(string $raw): array
{
    $s = strtolower(trim($raw));
    if ($s === '') {
        return ['—', 'text-bg-secondary'];
    }
    if (in_array($s, ['1', 'active', 'approved', 'verified', 'enabled', 'yes'], true)) {
        return ['Active', 'text-bg-success'];
    }
    if (in_array($s, ['0', 'inactive', 'disabled', 'no'], true)) {
        return ['Inactive', 'text-bg-secondary'];
    }
    if ($s === 'pending') {
        return ['Pending', 'text-bg-warning'];
    }
    return [ucfirst($s), 'text-bg-secondary'];
}

function vs_print_styles(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    echo <<<'CSS'
<style>
.vs-avatar{position:relative;width:42px;height:42px;border-radius:12px;flex:none;overflow:hidden;display:inline-grid;place-items:center;font-weight:700;font-size:.9rem;color:#fff}
.vs-avatar img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.vs-mail{max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.vs-num{font-variant-numeric:tabular-nums;font-weight:600}
.progress.vs-mix{width:120px;height:8px;background:rgba(148,163,184,.25)}
.vs-totals{display:flex;gap:20px;flex-wrap:wrap;font-size:.9rem;margin:0 0 12px}
.vs-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}
.vs-empty{padding:32px 8px;text-align:center}
@media (max-width:640px){.vs-hide-sm{display:none}}
</style>
CSS;
}

function vs_uploader_stats(PDO $pdo, string $table = 'videos'): array
{
    $res = [
        'rows'     => [],
        'totals'   => ['uploaders' => 0, 'videos' => 0, 'shorts' => 0, 'views' => 0],
        'has_date' => false,
        'error'    => '',
    ];

    $c = vs_cols($pdo, $table);
    if (!$c) {
        $res['error'] = 'The "' . $table . '" table was not found.';
        return $res;
    }

    $up = vs_pick($c, ['username', 'uploader', 'uploaded_by', 'author', 'user']);
    if (!$up) {
        $res['error'] = 'The "' . $table . '" table has no username column, so uploads can\'t be matched to people.';
        return $res;
    }

    $type  = vs_pick($c, ['type', 'video_type']);
    $views = vs_pick($c, ['views', 'view_count']);
    $date  = vs_pick($c, ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_on', 'created', 'date']);

    $name      = "COALESCE(NULLIF(TRIM(v." . vs_q($up) . "), ''), 'Admin')";
    $shortExpr = $type  ? "SUM(CASE WHEN LOWER(v." . vs_q($type) . ") LIKE 'short%' THEN 1 ELSE 0 END)" : '0';
    $viewsExpr = $views ? 'SUM(COALESCE(v.' . vs_q($views) . ', 0))' : '0';
    $lastExpr  = $date  ? 'MAX(v.' . vs_q($date) . ')' : 'NULL';

    $sql = "SELECT LOWER($name) AS k, MIN($name) AS uname, COUNT(*) AS total,
                   $shortExpr AS shorts, $viewsExpr AS views, $lastExpr AS last_upload
            FROM " . vs_q($table) . " v
            GROUP BY k
            ORDER BY total DESC, uname ASC";

    try {
        $stmt = $pdo->query($sql);
        if (!$stmt) {
            $res['error'] = 'Could not read uploads from the database.';
            return $res;
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $total  = (int)$r['total'];
            $shorts = (int)$r['shorts'];
            $row = [
                'key'    => mb_strtolower(trim((string)$r['k'])),
                'name'   => (string)$r['uname'],
                'total'  => $total,
                'videos' => $total - $shorts,
                'shorts' => $shorts,
                'views'  => (int)$r['views'],
                'last'   => $r['last_upload'],
            ];
            $res['rows'][] = $row;
            $res['totals']['videos'] += $row['videos'];
            $res['totals']['shorts'] += $row['shorts'];
            $res['totals']['views']  += $row['views'];
        }
        $res['totals']['uploaders'] = count($res['rows']);
        $res['has_date'] = (bool)$date;
    } catch (Throwable $e) {
        $res['error'] = 'Could not load uploaders: ' . $e->getMessage();
    }

    return $res;
}
