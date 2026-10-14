<?php
/**
 * Shared helpers for the VibeStream admin pages (admins.php, Users.php, premium.php).
 *
 * Load order on every admin page:
 *   require_once __DIR__ . '/admin-common.php';
 *   vs_admin_require_session();            // before anything else
 *   include_once __DIR__ . '/../connection.php';
 *   include_once __DIR__ . '/ui_helpers.php';
 *   vs_admin_require_live($pdo ?? null);   // re-checks the role in the database
 *
 * Requires ui_helpers.php for: vs_cols(), vs_pick(), vs_q().
 */

if (defined('VS_ADMIN_COMMON_LOADED')) {
    return;
}
define('VS_ADMIN_COMMON_LOADED', true);

/* ------------------------------------------------------------------ */
/* Access control                                                      */
/* ------------------------------------------------------------------ */

function vs_admin_session_start(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function vs_admin_session_is_admin(): bool
{
    return !empty($_SESSION['AdminSession'])
        || (isset($_SESSION['role']) && strcasecmp((string)$_SESSION['role'], 'Admin') === 0);
}

function vs_admin_deny(string $message = 'Access Denied: Administrator privileges required.'): void
{
    http_response_code(403);
    $js = json_encode($message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo '<!doctype html><meta charset="utf-8"><title>Access denied</title>';
    echo '<script>alert(' . $js . ');window.location.href="../login.php";</script>';
    echo '<noscript><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        . ' <a href="../login.php">Sign in</a></p></noscript>';
    exit;
}

/** First check: the session must claim to be an admin. Call before touching the database. */
function vs_admin_require_session(): void
{
    vs_admin_session_start();
    if (!vs_admin_session_is_admin()) {
        vs_admin_deny();
    }
}

/**
 * Second check: confirm in the database that this account still exists and is still an Admin,
 * so a demoted or deleted admin loses access immediately instead of when the session expires.
 * If the table has no usable columns the check is skipped (the session check above still applies).
 */
function vs_admin_require_live($pdo, string $table = 'users'): void
{
    if (!($pdo instanceof PDO)) {
        return;
    }
    try {
        $map = vs_user_map($pdo, $table);
        if (!$map || !$map['role']) {
            return;
        }
        $sid   = $_SESSION['user_id'] ?? $_SESSION['AdminID'] ?? $_SESSION['id'] ?? null;
        $sname = trim((string)($_SESSION['AdminSession'] ?? $_SESSION['username'] ?? ''));

        if ($map['id'] && $sid !== null && $sid !== '') {
            $where = vs_q($map['id']) . ' = ?';
            $arg   = (string)$sid;
        } elseif ($map['username'] && $sname !== '') {
            $where = vs_q($map['username']) . ' = ?';
            $arg   = $sname;
        } else {
            return;
        }

        $st = $pdo->prepare('SELECT ' . vs_q($map['role']) . ' AS r FROM ' . vs_q($table) . ' WHERE ' . $where . ' LIMIT 1');
        $st->execute([$arg]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $_SESSION = [];
            vs_admin_deny('Your account no longer exists.');
        }
        if (strcasecmp(trim((string)$row['r']), 'Admin') !== 0) {
            unset($_SESSION['AdminSession'], $_SESSION['AdminID']);
            $_SESSION['role'] = (string)$row['r'];
            vs_admin_deny();
        }
    } catch (Throwable $e) {
        error_log('VibeStream admin role check failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* CSRF, redirects, flash messages                                     */
/* ------------------------------------------------------------------ */

function vs_admin_csrf(string $key): string
{
    if (empty($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION[$key];
}

function vs_admin_csrf_ok(string $key): bool
{
    return !empty($_SESSION[$key])
        && hash_equals((string)$_SESSION[$key], (string)($_POST['csrf'] ?? ''));
}

function vs_admin_redirect(string $page, array $query = []): void
{
    header('Location: ' . $page . ($query ? '?' . http_build_query($query) : ''));
    exit;
}

function vs_admin_flash(array $messages): string
{
    return $messages[(string)($_GET['msg'] ?? '')] ?? '';
}

/* ------------------------------------------------------------------ */
/* Small formatting helpers                                            */
/* ------------------------------------------------------------------ */

/** Make a value safe to put in a CSV cell (stops =, +, -, @ being run as a spreadsheet formula). */
function vs_csv_cell($value): string
{
    $s = (string)$value;
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) {
        return "'" . $s;
    }
    return $s;
}

function vs_fmt_date($value, string $format = 'M j, Y', string $fallback = '—'): string
{
    $ts = strtotime((string)$value);
    return $ts ? date($format, $ts) : $fallback;
}

function vs_is_recent($value, int $seconds = 120): bool
{
    $ts = strtotime((string)$value);
    return $ts ? (time() - $ts) <= $seconds : false;
}

/** The little green/grey dot plus its text label. */
function vs_status_dot(bool $online): string
{
    return '<span class="vs-dot' . ($online ? ' is-online' : '') . '" aria-hidden="true"></span>'
        . ($online ? 'Online' : 'Offline');
}

/* ------------------------------------------------------------------ */
/* Users table access                                                  */
/* ------------------------------------------------------------------ */

/** Work out which real column plays each role. Returns null when the table is missing. */
function vs_user_map(PDO $pdo, string $table = 'users'): ?array
{
    $uc = vs_cols($pdo, $table);
    if (!$uc) {
        return null;
    }
    return [
        'id'          => vs_pick($uc, ['id', 'user_id']),
        'username'    => vs_pick($uc, ['username', 'user_name', 'login', 'uname']),
        'name'        => vs_pick($uc, ['name', 'full_name', 'fullname', 'display_name', 'first_name']),
        'email'       => vs_pick($uc, ['email', 'email_address', 'mail']),
        'role'        => vs_pick($uc, ['role', 'user_role', 'usertype', 'user_type', 'account_type']),
        'status'      => vs_pick($uc, ['status', 'account_status', 'is_active', 'active']),
        'joined'      => vs_pick($uc, ['created_at', 'joined_at', 'registered_at', 'date_joined', 'created_on', 'reg_date', 'date']),
        'avatar'      => vs_pick($uc, ['avatar', 'profile_image', 'profile_pic', 'photo', 'picture', 'image']),
        'last_active' => vs_pick($uc, ['last_active', 'last_seen', 'last_login']),
    ];
}

/** Display parts for one fetched row: [username, full name, email, display name]. */
function vs_user_names(array $u, string $fallbackLabel = 'User'): array
{
    $uname = trim((string)($u['f_username'] ?? ''));
    $full  = trim((string)($u['f_name'] ?? ''));
    $email = trim((string)($u['f_email'] ?? ''));
    $display = $full !== '' ? $full
        : ($uname !== '' ? $uname
        : ($email !== '' ? $email : $fallbackLabel . ' #' . ($u['f_id'] ?? '?')));
    return [$uname, $full, $email, $display];
}

/**
 * Build the WHERE clause (positional ? parameters appended to $params).
 * Options: admin 'only'|'exclude'|'any', q, search (aliases to search), role, keys (lower-case names).
 */
function vs_users_where(array $map, array $o, array &$params): string
{
    $where = [];
    $role  = $map['role'] ?? null;
    $admin = $o['admin'] ?? 'any';

    if ($role && $admin === 'only') {
        $where[]  = 'LOWER(TRIM(' . vs_q($role) . ')) = ?';
        $params[] = 'admin';
    } elseif ($role && $admin === 'exclude') {
        $where[]  = '(' . vs_q($role) . ' IS NULL OR LOWER(TRIM(' . vs_q($role) . ')) <> ?)';
        $params[] = 'admin';
    }

    $roleFilter = trim((string)($o['role'] ?? ''));
    if ($roleFilter !== '' && $role) {
        $where[]  = 'LOWER(TRIM(' . vs_q($role) . ')) = ?';
        $params[] = mb_strtolower($roleFilter);
    }

    $q = trim((string)($o['q'] ?? ''));
    if ($q !== '') {
        $like  = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        $parts = [];
        foreach (($o['search'] ?? ['name', 'username', 'email', 'role']) as $alias) {
            if (!empty($map[$alias])) {
                $parts[]  = vs_q($map[$alias]) . " LIKE ? ESCAPE '!'";
                $params[] = $like;
            }
        }
        if ($parts) {
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
    }

    if (isset($o['keys']) && is_array($o['keys'])) {
        $nameCols = array_values(array_filter([$map['username'] ?? null, $map['name'] ?? null]));
        if (!$o['keys'] || !$nameCols) {
            $where[] = '0 = 1';
        } else {
            $ph    = implode(',', array_fill(0, count($o['keys']), '?'));
            $parts = [];
            foreach ($nameCols as $c) {
                $parts[] = 'LOWER(TRIM(' . vs_q($c) . ')) IN (' . $ph . ')';
                foreach ($o['keys'] as $k) {
                    $params[] = (string)$k;
                }
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
    }

    return implode(' AND ', $where);
}

function vs_users_count(PDO $pdo, string $table, array $map, array $o = []): int
{
    $params = [];
    $where  = vs_users_where($map, $o, $params);
    $st = $pdo->prepare('SELECT COUNT(*) FROM ' . vs_q($table) . ($where !== '' ? ' WHERE ' . $where : ''));
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/**
 * Fetch rows (aliased f_<field>) with filters, ordering and optional paging (limit, offset).
 * Pass 'total' to skip the extra COUNT query when you already know it.
 */
function vs_users_fetch(PDO $pdo, string $table, array $map, array $o = []): array
{
    $params = [];
    $where  = vs_users_where($map, $o, $params);
    $from   = ' FROM ' . vs_q($table) . ($where !== '' ? ' WHERE ' . $where : '');

    $select = [];
    foreach ($map as $alias => $col) {
        if ($col) {
            $select[] = vs_q($col) . ' AS f_' . $alias;
        }
    }
    if (!$select) {
        return ['rows' => [], 'total' => 0];
    }

    $total = isset($o['total']) ? (int)$o['total'] : vs_users_count($pdo, $table, $map, $o);

    $dir      = (($o['order'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
    $orderCol = $map['joined'] ?: $map['id'];
    $order    = '';
    if ($orderCol) {
        $order = ' ORDER BY ' . vs_q($orderCol) . ' ' . $dir;
        if ($map['id'] && $map['id'] !== $orderCol) {
            $order .= ', ' . vs_q($map['id']) . ' ' . $dir;   // stable paging when dates tie
        }
    }

    $limit = '';
    if (isset($o['limit'])) {
        $limit = ' LIMIT ' . max(1, (int)$o['limit']) . ' OFFSET ' . max(0, (int)($o['offset'] ?? 0));
    }

    $st = $pdo->prepare('SELECT ' . implode(', ', $select) . $from . $order . $limit);
    $st->execute($params);
    return ['rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
}

/** Distinct non-admin roles, for the role filter drop-down. [lower-case => label] */
function vs_users_roles(PDO $pdo, string $table, array $map): array
{
    if (empty($map['role'])) {
        return [];
    }
    $c = vs_q($map['role']);
    $st = $pdo->query(
        'SELECT DISTINCT TRIM(' . $c . ') AS r FROM ' . vs_q($table)
        . ' WHERE ' . $c . ' IS NOT NULL AND TRIM(' . $c . ") <> '' AND LOWER(TRIM(" . $c . ")) <> 'admin'"
        . ' ORDER BY r LIMIT 50'
    );
    $roles = [];
    foreach ($st ? $st->fetchAll(PDO::FETCH_COLUMN) : [] as $r) {
        $roles[mb_strtolower((string)$r)] = (string)$r;
    }
    ksort($roles);
    return $roles;
}

/** Which of the given uploader keys belong to an account (by username or name)? Returns [key => true]. */
function vs_users_matched_keys(PDO $pdo, string $table, array $map, array $keys): array
{
    $nameCols = array_values(array_filter([$map['username'] ?? null, $map['name'] ?? null]));
    $keys = array_values(array_map('strval', $keys));
    if (!$keys || !$nameCols) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $sel = [];
    $whr = [];
    $params = [];
    foreach ($nameCols as $i => $c) {
        $expr  = 'LOWER(TRIM(' . vs_q($c) . '))';
        $sel[] = $expr . ' AS k' . $i;
        $whr[] = $expr . ' IN (' . $ph . ')';
        foreach ($keys as $k) {
            $params[] = $k;
        }
    }
    $st = $pdo->prepare('SELECT DISTINCT ' . implode(', ', $sel) . ' FROM ' . vs_q($table) . ' WHERE ' . implode(' OR ', $whr));
    $st->execute($params);

    $lookup  = array_flip($keys);
    $matched = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $row) {
        foreach ($row as $v) {
            $k = mb_strtolower(trim((string)$v));
            if ($k !== '' && isset($lookup[$k])) {
                $matched[$k] = true;
            }
        }
    }
    return $matched;
}

/* ------------------------------------------------------------------ */
/* Page chrome                                                         */
/* ------------------------------------------------------------------ */

function vs_admin_print_styles(): void
{
    echo '<style>'
        . '.vs-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;background:#888}'
        . '.vs-dot.is-online{background:#2ecc71}'
        . '.vs-w-160{max-width:160px}.vs-w-220{max-width:220px}.vs-w-240{max-width:240px}'
        . '.vs-auto{width:auto}'
        . '</style>';
}

function vs_admin_pager(int $total, int $page, int $perPage, string $base, array $query = []): string
{
    $pages = (int)max(1, ceil($total / max(1, $perPage)));
    if ($pages <= 1) {
        return '';
    }
    $query = array_filter($query, function ($v) {
        return $v !== '' && $v !== null;
    });
    $href = function (int $n) use ($base, $query): string {
        $q = $query;
        if ($n > 1) {
            $q['page'] = $n;
        }
        return htmlspecialchars($base . ($q ? '?' . http_build_query($q) : ''), ENT_QUOTES, 'UTF-8');
    };
    $item = function (string $label, int $n, bool $disabled, bool $active, string $aria = '') use ($href): string {
        $cls = 'page-item' . ($disabled ? ' disabled' : '') . ($active ? ' active' : '');
        $inner = $disabled
            ? '<span class="page-link">' . $label . '</span>'
            : '<a class="page-link" href="' . $href($n) . '"' . ($aria !== '' ? ' aria-label="' . $aria . '"' : '')
              . ($active ? ' aria-current="page"' : '') . '>' . $label . '</a>';
        return '<li class="' . $cls . '">' . $inner . '</li>';
    };

    $out = '<nav aria-label="Pagination" class="mt-3"><ul class="pagination pagination-sm mb-0 flex-wrap">';
    $out .= $item('&laquo;', $page - 1, $page <= 1, false, 'Previous page');

    $shown = [];
    for ($n = 1; $n <= $pages; $n++) {
        if ($n === 1 || $n === $pages || abs($n - $page) <= 2) {
            $shown[] = $n;
        }
    }
    $prev = 0;
    foreach ($shown as $n) {
        if ($prev && $n - $prev > 1) {
            $out .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
        $out .= $item((string)$n, $n, false, $n === $page);
        $prev = $n;
    }

    $out .= $item('&raquo;', $page + 1, $page >= $pages, false, 'Next page');
    return $out . '</ul></nav>';
}

/** Closes the layout opened by header.php and loads the shared scripts. */
function vs_admin_footer(string $inlineScripts = ''): void
{
    echo '<footer class="admin-footer"><div class="container-fluid px-3 px-lg-4"><span>VibeStream Admin Panel · Developed by '
        . '<a href="https://M.Awais.com" target="_blank" rel="noopener noreferrer">Muhammad Awais</a></span></div></footer>'
        . '</div></div>';
    echo $inlineScripts;
    echo '<script src="./assets/js/bootstrap.bundle.min.js"></script>'
        . '<script src="./assets/js/main.js"></script></body></html>';
}
