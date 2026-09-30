<?php
$RU_VIDEOS_TABLE = 'videos';
$RU_USERS_TABLE  = 'users';       
$RU_LIMIT        = 5;              
$RU_ALL_LINK     = 'videos.php';   
$RU_AVATAR_BASE  = '../';        
$RU_ACTIVE_DAYS  = 30;             

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $ru_conn = __DIR__ . '/../connection.php';
    if (is_file($ru_conn)) {
        require_once $ru_conn;
    }
}


if (!function_exists('ru_e')) {
    function ru_e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('ru_q')) {
    function ru_q(string $id): string { return '`' . str_replace('`', '', $id) . '`'; }
}
if (!function_exists('ru_cols')) {

    function ru_cols(PDO $pdo, string $table): array {
        $out = [];
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM ' . ru_q($table));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[strtolower($row['Field'])] = $row;
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }
}
if (!function_exists('ru_pick')) {
    function ru_pick(array $cols, array $candidates): ?string {
        foreach ($candidates as $c) {
            if (isset($cols[$c])) { return $cols[$c]['Field']; }
        }
        return null;
    }
}
if (!function_exists('ru_ago')) {
    function ru_ago(?string $when): string {
        if (!$when) { return '—'; }
        $ts = strtotime($when);
        if (!$ts) { return '—'; }
        $d = time() - $ts;
        if ($d < 60)        { return 'Just now'; }
        if ($d < 3600)      { return floor($d / 60) . ' min ago'; }
        if ($d < 86400)     { return floor($d / 3600) . ' hr ago'; }
        if ($d < 86400 * 30) {
            $n = (int)floor($d / 86400);
            return $n . ($n === 1 ? ' day ago' : ' days ago');
        }
        return date('M d, Y', $ts);
    }
}
if (!function_exists('ru_initials')) {
    function ru_initials(string $name): string {
        $parts = preg_split('/[\s._@-]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) { return '?'; }
        $s = mb_substr($parts[0], 0, 1);
        if (count($parts) > 1) { $s .= mb_substr(end($parts), 0, 1); }
        return mb_strtoupper($s);
    }
}
if (!function_exists('ru_avatar_src')) {
    function ru_avatar_src(?string $v, string $base): string {
        $v = trim((string)$v);
        if ($v === '') { return ''; }
        if (preg_match('#^(https?:)?//|^/|^data:#i', $v)) { return $v; }
        return $base . ltrim($v, '/');
    }
}

$ru_rows   = [];
$ru_note   = '';
$ru_totals = ['videos' => 0, 'shorts' => 0, 'uploaders' => 0];

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $ru_note = 'No database connection found. Check that ../connection.php creates $pdo.';
} else {
    $vc = ru_cols($pdo, $RU_VIDEOS_TABLE);

    if (!$vc) {
        $ru_note = 'Table "' . $RU_VIDEOS_TABLE . '" was not found in the database.';
    } else {
        $typeCol = ru_pick($vc, ['type', 'video_type', 'content_type', 'kind', 'format']);
        $dateCol = ru_pick($vc, ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_on', 'created', 'date']);
        $idCol   = ru_pick($vc, ['id', 'video_id']);
        $upCol   = ru_pick($vc, ['user_id', 'uploader_id', 'uploaded_by', 'uploader', 'username', 'user', 'channel', 'author', 'posted_by', 'owner']);

        if (!$upCol) {
            $ru_note = 'The "' . $RU_VIDEOS_TABLE . '" table has no uploader column yet. '
                     . 'Add one (for example user_id) and save it when a video is uploaded.';
        } else {
            $v         = 'v.' . ru_q($upCol);
            $upType    = strtolower($vc[strtolower($upCol)]['Type']);
            $isIdCol   = (bool)preg_match('/int/', $upType);
            $shortExpr = $typeCol
                ? "SUM(CASE WHEN LOWER(v." . ru_q($typeCol) . ") LIKE 'short%' THEN 1 ELSE 0 END)"
                : '0';
            $lastExpr  = $dateCol ? 'MAX(v.' . ru_q($dateCol) . ')' : 'NULL';
            $orderLast = $dateCol ? 'last_upload DESC, ' : ($idCol ? 'MAX(v.' . ru_q($idCol) . ') DESC, ' : '');

            $select  = [$v . ' AS uploader_key'];
            $groupBy = [$v];
            $join    = '';

            if ($isIdCol) {
                $uc = ru_cols($pdo, $RU_USERS_TABLE);
                $uid = $uc ? ru_pick($uc, ['id', 'user_id']) : null;
                if ($uid) {
                    $join = ' LEFT JOIN ' . ru_q($RU_USERS_TABLE) . ' u ON u.' . ru_q($uid) . ' = ' . $v;
                    $map = [
                        'uname'   => ru_pick($uc, ['name', 'username', 'full_name', 'fullname', 'display_name']),
                        'uemail'  => ru_pick($uc, ['email', 'email_address', 'mail']),
                        'uavatar' => ru_pick($uc, ['avatar', 'profile_image', 'profile_pic', 'photo', 'picture', 'image']),
                    ];
                    foreach ($map as $alias => $col) {
                        if ($col) {
                            $select[]  = 'u.' . ru_q($col) . ' AS ' . $alias;
                            $groupBy[] = 'u.' . ru_q($col);
                        }
                    }
                }
            }

            $where = "$v IS NOT NULL AND $v <> ''";

            try {
                $sql = 'SELECT ' . implode(', ', $select) . ",
                               COUNT(*) AS total,
                               $shortExpr AS shorts,
                               $lastExpr AS last_upload
                        FROM " . ru_q($RU_VIDEOS_TABLE) . " v $join
                        WHERE $where
                        GROUP BY " . implode(', ', $groupBy) . "
                        ORDER BY {$orderLast}total DESC
                        LIMIT " . (int)$RU_LIMIT;
                $ru_rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

                $t = $pdo->query("SELECT COUNT(*) AS total, $shortExpr AS shorts, COUNT(DISTINCT $v) AS uploaders
                                  FROM " . ru_q($RU_VIDEOS_TABLE) . " v WHERE $where")->fetch(PDO::FETCH_ASSOC);
                $ru_totals['shorts']    = (int)($t['shorts'] ?? 0);
                $ru_totals['videos']    = (int)($t['total'] ?? 0) - $ru_totals['shorts'];
                $ru_totals['uploaders'] = (int)($t['uploaders'] ?? 0);
            } catch (Throwable $e) {
                $ru_note = 'Could not load uploaders: ' . $e->getMessage();
            }
        }
    }
}
?>
<style>
.ru-card{
  --ru-bg:#172033; --ru-line:#263349; --ru-text:#f1f5f9; --ru-muted:#94a3b8;
  --ru-video:#60a5fa; --ru-short:#f472b6; --ru-ok:#0f766e; --ru-idle:#334155;
  background:var(--ru-bg); border:1px solid var(--ru-line); border-radius:16px;
  padding:24px 28px 12px; color:var(--ru-text); margin:0 0 24px;
}
.ru-head{display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:8px}
.ru-title{display:flex; align-items:center; gap:12px}
.ru-icon{width:42px; height:42px; border-radius:12px; background:#1e3a5f; color:#93c5fd; display:grid; place-items:center; flex:none}
.ru-title h3{margin:0; font-size:1.35rem; font-weight:600; color:var(--ru-text); line-height:1.2}
.ru-sub{margin:6px 0 0; color:var(--ru-muted); font-size:.95rem}
.ru-btn{display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border:1px solid var(--ru-line); border-radius:10px;
  background:transparent; color:var(--ru-text); font-weight:600; font-size:.9rem; text-decoration:none; transition:background .15s, border-color .15s}
.ru-btn:hover{background:#1e2a42; border-color:#3a4a68; color:var(--ru-text)}
.ru-btn:focus-visible{outline:2px solid #60a5fa; outline-offset:2px}

.ru-totals{display:flex; gap:20px; flex-wrap:wrap; margin:14px 0 4px; font-size:.9rem; color:var(--ru-muted)}
.ru-totals strong{color:var(--ru-text); font-weight:600}
.ru-dot{display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:6px; vertical-align:baseline}

.ru-wrap{overflow-x:auto; margin-top:8px}
.ru-table{width:100%; border-collapse:collapse; min-width:680px}
.ru-table th{padding:12px 10px; text-align:left; font-size:.82rem; font-weight:600; color:var(--ru-muted); border-bottom:1px solid var(--ru-line); white-space:nowrap}
.ru-table td{padding:14px 10px; border-bottom:1px solid var(--ru-line); vertical-align:middle; font-size:.97rem}
.ru-table tbody tr:last-child td{border-bottom:0}
.ru-table tbody tr{transition:background .15s}
.ru-table tbody tr:hover{background:rgba(148,163,184,.06)}
.ru-num{font-variant-numeric:tabular-nums; font-weight:600}
.ru-right{text-align:right !important}

.ru-user{display:flex; align-items:center; gap:12px; min-width:0}
.ru-avatar{position:relative; width:42px; height:42px; border-radius:12px; flex:none; overflow:hidden;
  display:grid; place-items:center; font-weight:700; font-size:.9rem; color:#fff}
.ru-avatar img{position:absolute; inset:0; width:100%; height:100%; object-fit:cover}
.ru-name{font-weight:600; color:var(--ru-text); line-height:1.25}
.ru-mail{font-size:.85rem; color:var(--ru-muted); line-height:1.3; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:220px}

.ru-split{display:flex; width:120px; height:8px; border-radius:99px; overflow:hidden; background:#22304a}
.ru-split i{display:block; height:100%}
.ru-split .v{background:var(--ru-video)}
.ru-split .s{background:var(--ru-short)}

.ru-badge{display:inline-block; padding:4px 12px; border-radius:8px; font-size:.82rem; font-weight:600; color:#fff}
.ru-badge.ok{background:var(--ru-ok)}
.ru-badge.idle{background:var(--ru-idle); color:#cbd5e1}

.ru-empty{padding:36px 8px 28px; text-align:center; color:var(--ru-muted)}
.ru-empty strong{display:block; color:var(--ru-text); font-size:1.05rem; margin-bottom:6px}

@media (max-width:640px){
  .ru-card{padding:18px 16px 8px}
  .ru-split-col{display:none}
}
</style>

<section class="ru-card" aria-labelledby="ru-heading">
  <div class="ru-head">
    <div>
      <div class="ru-title">
        <span class="ru-icon" aria-hidden="true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>
          </svg>
        </span>
        <h3 id="ru-heading">Recent Uploaders</h3>
      </div>
      <p class="ru-sub">People who have uploaded videos and shorts.</p>
    </div>
    <a class="ru-btn" href="<?= ru_e($RU_ALL_LINK) ?>">All videos</a>
  </div>

  <?php if ($ru_note !== ''): ?>
    <div class="ru-empty"><strong>Uploaders can't be shown yet</strong><?= ru_e($ru_note) ?></div>

  <?php elseif (!$ru_rows): ?>
    <div class="ru-empty"><strong>No uploads yet</strong>Uploaders will appear here after the first video or short is added.</div>

  <?php else: ?>
    <div class="ru-totals">
      <span><strong><?= (int)$ru_totals['uploaders'] ?></strong> uploaders</span>
      <span><span class="ru-dot" style="background:var(--ru-video)"></span><strong><?= (int)$ru_totals['videos'] ?></strong> videos</span>
      <span><span class="ru-dot" style="background:var(--ru-short)"></span><strong><?= (int)$ru_totals['shorts'] ?></strong> shorts</span>
    </div>

    <div class="ru-wrap">
      <table class="ru-table">
        <thead>
          <tr>
            <th>Uploader</th>
            <th class="ru-right">Videos</th>
            <th class="ru-right">Shorts</th>
            <th class="ru-split-col">Mix</th>
            <th>Last upload</th>
            <th>Status</th>
            <th class="ru-right">Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($ru_rows as $r):
            $key     = (string)$r['uploader_key'];
            $name    = trim((string)($r['uname'] ?? ''));
            if ($name === '') { $name = $isIdCol ? 'User #' . $key : $key; }
            $email   = trim((string)($r['uemail'] ?? ''));
            $sub     = $email !== '' ? $email : ($isIdCol ? 'ID ' . $key : '');
            $shorts  = (int)$r['shorts'];
            $videos  = (int)$r['total'] - $shorts;
            $total   = max(1, $videos + $shorts);
            $vPct    = round($videos / $total * 100, 1);
            $sPct    = round(100 - $vPct, 1);
            $hue     = crc32($name) % 360;
            $avatar  = ru_avatar_src($r['uavatar'] ?? '', $RU_AVATAR_BASE);
            $last    = $r['last_upload'] ?? null;
            $lastTs  = $last ? strtotime($last) : false;
            $active  = $lastTs ? ($lastTs >= time() - 86400 * $RU_ACTIVE_DAYS) : true;
        ?>
          <tr>
            <td>
              <div class="ru-user">
                <span class="ru-avatar" style="background:linear-gradient(135deg,hsl(<?= $hue ?> 65% 45%),hsl(<?= ($hue + 40) % 360 ?> 70% 35%))">
                  <?= ru_e(ru_initials($name)) ?>
                  <?php if ($avatar !== ''): ?>
                    <img src="<?= ru_e($avatar) ?>" alt="" loading="lazy" onerror="this.remove()">
                  <?php endif; ?>
                </span>
                <div style="min-width:0">
                  <div class="ru-name"><?= ru_e($name) ?></div>
                  <?php if ($sub !== ''): ?><div class="ru-mail"><?= ru_e($sub) ?></div><?php endif; ?>
                </div>
              </div>
            </td>
            <td class="ru-right ru-num"><?= $videos ?></td>
            <td class="ru-right ru-num"><?= $shorts ?></td>
            <td class="ru-split-col">
              <div class="ru-split" role="img" aria-label="<?= $videos ?> videos, <?= $shorts ?> shorts" title="<?= $videos ?> videos, <?= $shorts ?> shorts">
                <i class="v" style="width:<?= $vPct ?>%"></i><i class="s" style="width:<?= $sPct ?>%"></i>
              </div>
            </td>
            <td style="color:var(--ru-muted)"><?= ru_e(ru_ago($last)) ?></td>
            <td><span class="ru-badge <?= $active ? 'ok' : 'idle' ?>"><?= $active ? 'Active' : 'Idle' ?></span></td>
            <td class="ru-right">
              <a class="ru-btn" href="<?= ru_e($RU_ALL_LINK) ?>?uploader=<?= urlencode($key) ?>">View</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
