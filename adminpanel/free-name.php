<?php
// Owner-only cleanup: frees a user name that was left behind by an account deleted earlier.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}
include_once "../connection.php";
include_once "ui_helpers.php";
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/release-username.php';

// Only the owner (VS_OWNER_EMAIL in owner-guard.php) may use this page.
if (!isset($pdo) || !($pdo instanceof PDO) || !vs_is_owner($pdo)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Owner only</title></head>'
        . '<body style="font-family:Arial,sans-serif;padding:40px;background:#f4f5f7">'
        . '<h2>Owner only</h2><p>Only the owner account can free user names.</p>'
        . '<p><a href="admins.php">Back to the admin panel</a></p></body></html>';
    exit;
}

if (empty($_SESSION['free_name_csrf'])) {
    $_SESSION['free_name_csrf'] = bin2hex(random_bytes(16));
}
$message = '';
$ok = false;

// The owner's user name: the owner is never shown or changed by the Premium tools below.
$ownerUsername = '';
try {
    $uc0 = vs_cols($pdo, 'users');
    $uCol0 = $uc0 ? vs_pick($uc0, ['username', 'user_name', 'login', 'uname']) : null;
    $eCol0 = $uc0 ? vs_pick($uc0, ['email', 'email_address', 'mail']) : null;
    if ($uCol0 && $eCol0) {
        $st0 = $pdo->prepare('SELECT ' . vs_q($uCol0) . ' FROM `users` WHERE LOWER(TRIM(' . vs_q($eCol0) . ')) = ? LIMIT 1');
        $st0->execute([strtolower(VS_OWNER_EMAIL)]);
        $ownerUsername = trim((string)$st0->fetchColumn());
    }
} catch (Throwable $e) {
    error_log('free-name owner lookup failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'purge') {
    if (!hash_equals($_SESSION['free_name_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $message = 'The request expired. Refresh the page and try again.';
    } elseif (!isset($pdo) || !($pdo instanceof PDO)) {
        $message = 'No database connection found.';
    } else {
        try {
            $n = vs_purge_deleted_premium($pdo);
            $ok = true;
            $message = 'Done. ' . $n . ' deleted account(s) removed from Premium members.';
        } catch (Throwable $e) {
            error_log('purge premium failed: ' . $e->getMessage());
            $message = 'Something went wrong. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'remove_premium') {
    $pname = trim((string)($_POST['username'] ?? ''));
    if (!hash_equals($_SESSION['free_name_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $message = 'The request expired. Refresh the page and try again.';
    } elseif ($pname === '') {
        $message = 'No user was chosen.';
    } elseif ($ownerUsername !== '' && strcasecmp($pname, $ownerUsername) === 0) {
        $message = 'The owner account cannot be changed here.';
    } else {
        try {
            $st = $pdo->prepare('DELETE FROM `premium_members` WHERE LOWER(`username`) = LOWER(?)');
            $st->execute([$pname]);
            if ($st->rowCount() > 0) {
                $ok = true;
                $message = 'Done. Premium was removed from "' . $pname . '".';
            } else {
                $message = '"' . $pname . '" is not a Premium member.';
            }
        } catch (Throwable $e) {
            error_log('remove premium failed: ' . $e->getMessage());
            $message = 'Something went wrong. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    if (!hash_equals($_SESSION['free_name_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $message = 'The request expired. Refresh the page and try again.';
    } elseif ($name === '') {
        $message = 'Type the name you want to free.';
    } elseif (!isset($pdo) || !($pdo instanceof PDO)) {
        $message = 'No database connection found.';
    } else {
        try {
            $n = vs_release_username($pdo, $name, 'deleted_' . substr(md5($name . microtime()), 0, 8));
            if ($n < 0) {
                $message = 'A live account still uses "' . $name . '", so nothing was changed. Delete that account first.';
            } else {
                $ok = true;
                $message = 'Done. "' . $name . '" is free now (' . $n . ' leftover row(s) cleaned).';
            }
        } catch (Throwable $e) {
            error_log('free-name failed: ' . $e->getMessage());
            $message = 'Something went wrong. Please try again.';
        }
    }
}

// Accounts list (owner only: the owner check at the top of this file already stopped everyone else).
$accounts = [];
try {
    $uc = vs_cols($pdo, 'users');
    if ($uc) {
        $m = [
            'id'       => vs_pick($uc, ['id', 'user_id']),
            'username' => vs_pick($uc, ['username', 'user_name', 'login', 'uname']),
            'name'     => vs_pick($uc, ['name', 'full_name', 'fullname', 'display_name', 'first_name']),
            'email'    => vs_pick($uc, ['email', 'email_address', 'mail']),
            'role'     => vs_pick($uc, ['role', 'user_role', 'usertype', 'user_type', 'account_type']),
        ];
        $sel = [];
        foreach ($m as $alias => $col) {
            if ($col) {
                $sel[] = vs_q($col) . ' AS f_' . $alias;
            }
        }
        if ($sel) {
            $order = $m['username'] ? vs_q($m['username']) . ' ASC' : ($m['id'] ? vs_q($m['id']) . ' ASC' : '1');
            $rows = $pdo->query('SELECT ' . implode(', ', $sel) . ' FROM `users` ORDER BY ' . $order)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $u) {
                $uname = trim((string)($u['f_username'] ?? ''));
                $full  = trim((string)($u['f_name'] ?? ''));
                $email = trim((string)($u['f_email'] ?? ''));
                $role  = trim((string)($u['f_role'] ?? ''));
                // The owner account is never listed here.
                if ($email !== '' && strtolower($email) === strtolower(VS_OWNER_EMAIL)) {
                    continue;
                }
                $accounts[] = [
                    'name'     => $uname !== '' ? $uname : ($full !== '' ? $full : $email),
                    'full'     => $full,
                    'email'    => $email,
                    'role'     => $role,
                    'is_admin' => strcasecmp($role, 'Admin') === 0,
                    'is_owner' => $email !== '' && strtolower($email) === strtolower(VS_OWNER_EMAIL),
                ];
            }
        }
    }
} catch (Throwable $e) {
    error_log('free-name accounts list failed: ' . $e->getMessage());
}
$adminCount = count(array_filter($accounts, static fn($a) => $a['is_admin']));

// Leftover names: accounts that were deleted but whose name was never freed.
// Any table (other than `users`) that still holds a user name which no live account uses is scanned;
// names already moved to "deleted_..." are ignored.
$leftovers = [];
try {
    $live = [];
    foreach ($accounts as $a) {
        $live[strtolower($a['name'])] = true;
    }
    if ($ownerUsername !== '') {
        $live[strtolower($ownerUsername)] = true;
    }
    $colsQ = $pdo->query(
        "SELECT c.TABLE_NAME, c.COLUMN_NAME FROM information_schema.COLUMNS c
         JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
         WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
           AND LOWER(c.COLUMN_NAME) IN ('username','user_name')
           AND LOWER(c.TABLE_NAME) <> 'users'"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($colsQ as $c) {
        try {
            $names = $pdo->query('SELECT DISTINCT ' . vs_q($c['COLUMN_NAME']) . ' FROM ' . vs_q($c['TABLE_NAME'])
                . ' WHERE ' . vs_q($c['COLUMN_NAME']) . " IS NOT NULL AND " . vs_q($c['COLUMN_NAME']) . " <> ''")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            continue;
        }
        foreach ($names as $nm) {
            $nm = trim((string)$nm);
            $key = strtolower($nm);
            if ($nm === '' || isset($live[$key]) || strpos($key, 'deleted_') === 0) {
                continue;
            }
            $leftovers[$key] = $leftovers[$key] ?? ['name' => $nm, 'where' => []];
            $leftovers[$key]['where'][$c['TABLE_NAME']] = true;
        }
    }
    ksort($leftovers);
} catch (Throwable $e) {
    error_log('free-name leftover scan failed: ' . $e->getMessage());
}

// Premium members (the owner is left out).
$premiumRows = [];
try {
    $prem = $pdo->query('SELECT `username`, `plan`, `status`, `expires_at` FROM `premium_members` ORDER BY `expires_at` DESC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($prem as $pm) {
        $pn = trim((string)$pm['username']);
        if ($pn === '' || ($ownerUsername !== '' && strcasecmp($pn, $ownerUsername) === 0)) {
            continue;
        }
        $exp = strtotime((string)$pm['expires_at']);
        $premiumRows[] = [
            'name'    => $pn,
            'plan'    => ucfirst((string)$pm['plan']),
            'expires' => $exp ? date('M d, Y', $exp) : '',
            'active'  => strtolower((string)$pm['status']) === 'active' && $exp && $exp > time(),
        ];
    }
} catch (Throwable $e) {
    error_log('free-name premium list failed: ' . $e->getMessage());
}

// Same layout as the other admin pages: header.php draws the navbar and the sidebar.
include "header.php";
vs_print_styles();
?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-person-x" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Owner tools</p>
                <h1 class="h3 mb-1">Delete names</h1>
                <p class="text-muted mb-0">Frees a user name left behind by an account you already deleted. Only the owner can see this page.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="admins.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Admins</a>
            </div>
          </div>

          <?php if ($message !== ''): ?>
            <div class="alert <?php echo $ok ? 'alert-success' : 'alert-danger'; ?> mt-3 mb-0" role="status"><?php echo vs_e($message); ?></div>
          <?php endif; ?>

          <div class="row g-3 mt-1">
            <div class="col-12 col-lg-6">
              <section class="panel h-100" aria-label="Free a user name">
                <div class="panel-header">
                  <h2 class="h5 mb-1 section-title"><i class="bi bi-person-x" aria-hidden="true"></i><span>Free a user name</span></h2>
                  <p class="text-muted mb-0">Old videos and comments under that name are kept but moved to a "deleted_..." name.</p>
                </div>
                <form method="post" action="free-name.php">
                  <input type="hidden" name="csrf" value="<?php echo vs_e($_SESSION['free_name_csrf']); ?>">
                  <label class="form-label" for="name">User name to free</label>
                  <input class="form-control mb-3" type="text" id="name" name="name" placeholder="AdminHunter" autocomplete="off" required>
                  <button class="btn btn-danger btn-sm" type="submit">Free this name</button>
                </form>
              </section>
            </div>

            <div class="col-12 col-lg-6">
              <section class="panel h-100" aria-label="Premium members cleanup">
                <div class="panel-header">
                  <h2 class="h5 mb-1 section-title"><i class="bi bi-stars" aria-hidden="true"></i><span>Premium members cleanup</span></h2>
                  <p class="text-muted mb-0">Remove deleted accounts from the Premium members list (rows named "deleted_...").</p>
                </div>
                <form method="post" action="free-name.php">
                  <input type="hidden" name="csrf" value="<?php echo vs_e($_SESSION['free_name_csrf']); ?>">
                  <input type="hidden" name="do" value="purge">
                  <button class="btn btn-outline-danger btn-sm" type="submit">Remove from Premium members</button>
                </form>
              </section>
            </div>
          </div>

          <section class="panel mt-3" aria-label="Accounts">
            <div class="panel-header d-flex flex-wrap align-items-center justify-content-between gap-2">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-people" aria-hidden="true"></i><span>Accounts</span></h2>
                <p class="text-muted mb-0">Pick a name to put it in the box above. A name can only be freed after its account has been deleted.</p>
              </div>
              <div class="d-flex flex-wrap gap-2">
                <div class="btn-group btn-group-sm" role="group" aria-label="Switch list">
                  <button type="button" class="btn btn-primary" data-vs-list="all"><i class="bi bi-people" aria-hidden="true"></i> All users (<?php echo count($accounts) + count($leftovers); ?>)</button>
                  <button type="button" class="btn btn-outline-primary" data-vs-list="admins"><i class="bi bi-shield-check" aria-hidden="true"></i> Admins (<?php echo (int)$adminCount; ?>)</button>
                  <button type="button" class="btn btn-outline-primary" data-vs-list="deleted"><i class="bi bi-person-x" aria-hidden="true"></i> Deleted (<?php echo count($leftovers); ?>)</button>
                </div>
                <input class="form-control form-control-sm" style="width:200px" type="search" id="vsListSearch" placeholder="Search names" aria-label="Search names" autocomplete="off">
              </div>
            </div>
            <p class="text-muted small mb-2">Showing <span id="vsListCount"><?php echo count($accounts) + count($leftovers); ?></span> of <?php echo count($accounts) + count($leftovers); ?></p>
            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col" class="text-end">Action</th></tr>
                </thead>
                <tbody id="vsListBody">
                  <?php foreach ($accounts as $a): ?>
                    <tr data-admin="<?php echo $a['is_admin'] ? '1' : '0'; ?>" data-search="<?php echo vs_e(mb_strtolower($a['name'] . ' ' . $a['full'] . ' ' . $a['email'])); ?>">
                      <td class="fw-semibold"><?php echo vs_e($a['name']); ?>
                        <?php if ($a['is_owner']): ?><span class="badge text-bg-warning">Owner</span><?php endif; ?>
                      </td>
                      <td class="text-muted small"><?php echo vs_e($a['email']); ?></td>
                      <td><span class="badge <?php echo $a['is_admin'] ? 'text-bg-primary' : 'text-bg-secondary'; ?>"><?php echo vs_e($a['role'] !== '' ? ucfirst($a['role']) : 'User'); ?></span></td>
                      <td class="text-end"><button type="button" class="btn btn-light btn-sm" data-vs-use="<?php echo vs_e($a['name']); ?>">Use this name</button></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php foreach ($leftovers as $lo): ?>
                    <tr data-admin="0" data-deleted="1" data-search="<?php echo vs_e(mb_strtolower($lo['name'] . ' deleted')); ?>">
                      <td class="fw-semibold"><?php echo vs_e($lo['name']); ?>
                        <span class="badge text-bg-danger">Deleted account</span>
                      </td>
                      <td class="text-muted small">Name not freed yet (in: <?php echo vs_e(implode(', ', array_keys($lo['where']))); ?>)</td>
                      <td><span class="badge text-bg-secondary">Deleted</span></td>
                      <td class="text-end">
                        <form method="post" action="free-name.php" class="d-inline" onsubmit="return confirm('Free this deleted account name?');">
                          <input type="hidden" name="csrf" value="<?php echo vs_e($_SESSION['free_name_csrf']); ?>">
                          <input type="hidden" name="name" value="<?php echo vs_e($lo['name']); ?>">
                          <button type="submit" class="btn btn-outline-danger btn-sm">Free this name</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <tr id="vsListEmpty"<?php echo ($accounts || $leftovers) ? ' hidden' : ''; ?>><td colspan="4" class="text-center text-muted py-4">No accounts found.</td></tr>
                </tbody>
              </table>
            </div>
          </section>

          <section class="panel mt-3" aria-label="Remove Premium">
            <div class="panel-header">
              <h2 class="h5 mb-1 section-title"><i class="bi bi-stars" aria-hidden="true"></i><span>Remove Premium</span></h2>
              <p class="text-muted mb-0">Everyone who has Premium. Removing it takes their Premium away right away. Their account and videos stay.</p>
            </div>
            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr><th scope="col">User</th><th scope="col">Plan</th><th scope="col">Status</th><th scope="col">Ends</th><th scope="col" class="text-end">Action</th></tr>
                </thead>
                <tbody>
                  <?php if (!$premiumRows): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Nobody has Premium right now.</td></tr>
                  <?php else: foreach ($premiumRows as $pr): ?>
                    <tr>
                      <td class="fw-semibold"><?php echo vs_e($pr['name']); ?></td>
                      <td><?php echo vs_e($pr['plan']); ?></td>
                      <td><span class="badge <?php echo $pr['active'] ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $pr['active'] ? 'Active' : 'Expired'; ?></span></td>
                      <td class="text-muted small"><?php echo vs_e($pr['expires']); ?></td>
                      <td class="text-end">
                        <form method="post" action="free-name.php" class="d-inline" onsubmit="return confirm('Remove Premium from this user?');">
                          <input type="hidden" name="csrf" value="<?php echo vs_e($_SESSION['free_name_csrf']); ?>">
                          <input type="hidden" name="do" value="remove_premium">
                          <input type="hidden" name="username" value="<?php echo vs_e($pr['name']); ?>">
                          <button type="submit" class="btn btn-outline-danger btn-sm">Remove Premium</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </main>

      <footer class="admin-footer">
        <div class="container-fluid px-3 px-lg-4">
          <span>VibeStream Admin Panel</span>
        </div>
      </footer>
    </div>
  </div>

  <script>
    (function () {
      var body = document.getElementById('vsListBody');
      if (!body) { return; }
      var rows = body.querySelectorAll('tr[data-search]');
      var empty = document.getElementById('vsListEmpty');
      var count = document.getElementById('vsListCount');
      var search = document.getElementById('vsListSearch');
      var btns = document.querySelectorAll('[data-vs-list]');
      var mode = 'all';

      function apply() {
        var q = (search.value || '').toLowerCase().trim(), n = 0;
        rows.forEach(function (r) {
          var show = (mode === 'all' || (mode === 'admins' && r.getAttribute('data-admin') === '1') ||
                      (mode === 'deleted' && r.getAttribute('data-deleted') === '1')) &&
                     (!q || r.getAttribute('data-search').indexOf(q) !== -1);
          r.hidden = !show;
          if (show) { n++; }
        });
        count.textContent = n;
        empty.hidden = n !== 0;
      }
      btns.forEach(function (b) {
        b.addEventListener('click', function () {
          mode = b.getAttribute('data-vs-list');
          btns.forEach(function (x) {
            var on = x === b;
            x.classList.toggle('btn-primary', on);
            x.classList.toggle('btn-outline-primary', !on);
          });
          apply();
        });
      });
      search.addEventListener('input', apply);
      body.addEventListener('click', function (e) {
        var b = e.target.closest('[data-vs-use]');
        if (!b) { return; }
        var f = document.getElementById('name');
        f.value = b.getAttribute('data-vs-use');
        f.scrollIntoView({ behavior: 'smooth', block: 'center' });
        f.focus();
      });
      apply();
    })();
  </script>
  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
</body>
</html>