<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["AdminSession"])) {
    header("Location: ../login.php");
    exit();
}

// --- Update "last active" timestamp for the logged-in admin ---
require_once __DIR__ . '/../connection.php'; // creates $pdo
require_once __DIR__ . '/ui_helpers.php';

if (isset($pdo) && $pdo instanceof PDO) {
    $uCols = vs_cols($pdo, 'users');
    if ($uCols) {
        $idCol    = vs_pick($uCols, ['id', 'user_id']);
        $unameCol = vs_pick($uCols, ['username', 'user_name', 'login', 'uname']);
        $nameCol  = vs_pick($uCols, ['name', 'full_name', 'fullname', 'display_name']);
        $laCol    = vs_pick($uCols, ['last_active', 'last_seen', 'last_login']);
        $pwdChangedCol = vs_pick($uCols, ['password_changed_at', 'pass_changed_at', 'pwd_changed_at', 'password_updated_at', 'credentials_changed_at']);

        // Figure out which column identifies "this admin", used by every check below.
        $matchCol = null; $matchVal = null;
        if ($idCol && !empty($_SESSION['AdminID'])) {
            $matchCol = $idCol; $matchVal = $_SESSION['AdminID'];
        } elseif ($unameCol && !empty($_SESSION['AdminSession'])) {
            $matchCol = $unameCol; $matchVal = $_SESSION['AdminSession'];
        } elseif ($nameCol && !empty($_SESSION['AdminSession'])) {
            $matchCol = $nameCol; $matchVal = $_SESSION['AdminSession'];
        }

        if ($laCol && $matchCol) {
            try {
                $upd = $pdo->prepare("UPDATE `users` SET `$laCol` = NOW() WHERE `$matchCol` = :val");
                $upd->execute([':val' => $matchVal]);
            } catch (Throwable $e) {
                // don't let a DB hiccup break the page
            }
        }

        // --- Force logout everywhere if the password changed since this session started ---
        if ($pwdChangedCol && $matchCol) {
            try {
                $pcStmt = $pdo->prepare("SELECT `$pwdChangedCol` FROM `users` WHERE `$matchCol` = :val LIMIT 1");
                $pcStmt->execute([':val' => $matchVal]);
                $dbPwdChangedAt = $pcStmt->fetchColumn();

                if (!isset($_SESSION['PwdChangedAt'])) {
                    // First request seen under this feature (or a fresh login) - just remember it.
                    $_SESSION['PwdChangedAt'] = $dbPwdChangedAt;
                } elseif ($dbPwdChangedAt !== false && $_SESSION['PwdChangedAt'] !== $dbPwdChangedAt) {
                    // Password was changed (here or on another device) after this session began.
                    $_SESSION = [];
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_destroy();
                    }
                    header("Location: ../login.php?reason=password_changed");
                    exit();
                }
            } catch (Throwable $e) {
                // fail open - a DB hiccup here should not lock everyone out
            }
        }
    }
}

// --- Resolve a display name + avatar for whoever is actually logged in ---
$adminDisplayName = $_SESSION["AdminSession"]; // fallback: whatever is in session
$adminAvatarPath  = './assets/images/avatar/avatar.jpg'; // fallback avatar

if (isset($pdo) && $pdo instanceof PDO && isset($uCols) && $uCols) {
    $avatarCol = vs_pick($uCols, ['avatar', 'profile_image', 'photo', 'image']);
    $selectCols = [];
    if (!empty($nameCol))   $selectCols[] = $nameCol;
    if (!empty($unameCol))  $selectCols[] = $unameCol;
    if (!empty($avatarCol)) $selectCols[] = $avatarCol;

    if (!empty($matchCol) && !empty($selectCols)) {
        try {
            $cols = implode(', ', array_map(fn($c) => "`$c`", array_unique($selectCols)));
            $stmt = $pdo->prepare("SELECT $cols FROM `users` WHERE `$matchCol` = :val LIMIT 1");
            $stmt->execute([':val' => $matchVal]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                if (!empty($nameCol) && !empty($row[$nameCol])) {
                    $adminDisplayName = $row[$nameCol];
                } elseif (!empty($unameCol) && !empty($row[$unameCol])) {
                    $adminDisplayName = $row[$unameCol];
                }

                if (!empty($avatarCol) && !empty($row[$avatarCol])) {
                    $adminAvatarPath = $row[$avatarCol];
                }
            }
        } catch (Throwable $e) {
            // keep fallbacks on failure
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="adminHMD professional admin dashboard template">
  <title>Dashboard | adminHMD</title>

  <link rel="stylesheet" href="./assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="./assets/vendors/bootstrap-icons/bootstrap-icons.css">
  <link rel="stylesheet" href="./assets/css/style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
  <div class="admin-shell">
    <div class="sidebar-backdrop" data-sidebar-close></div>

    <aside class="admin-sidebar" id="adminSidebar" aria-label="Main navigation">
      <div class="sidebar-header">
        <a class="brand-mark" href="index.php" aria-label="adminHMD dashboard">
          <span class="brand-icon"><i class="bi bi-grid-1x2-fill" aria-hidden="true"></i></span>
          <span class="brand-copy">
            <span class="brand-title"><?php echo htmlspecialchars($adminDisplayName); ?></span>
            <span class="brand-subtitle">Admin Template</span>
          </span>
        </a>
      </div>

      <nav class="sidebar-nav">
        <a class="nav-link active" href="index.php" aria-current="page">
          <span class="nav-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span>
          <span class="nav-text">Dashboard</span>
        </a>

        <a class="nav-link" href="videos.php">
          <span class="nav-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
          <span class="nav-text">Videos</span>
        </a>

        <a class="nav-link" href="addvideos.php">
          <span class="nav-icon"><i class="bi bi-plus-square" aria-hidden="true"></i></span>
          <span class="nav-text">Add Videos</span>
        </a>

        <a class="nav-link" href="Users.php">
          <span class="nav-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
          <span class="nav-text">Users</span>
        </a>

        <a class="nav-link" href="user_profile.php">
          <span class="nav-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
          <span class="nav-text">Users profile</span>
        </a>

        <a class="nav-link" href="profile.php">
          <span class="nav-icon"><i class="bi bi-person-badge" aria-hidden="true"></i></span>
          <span class="nav-text">Profile</span>
        </a>
        <a class="nav-link" href="settings.php">
          <span class="nav-icon"><i class="bi bi-gear" aria-hidden="true"></i></span>
          <span class="nav-text">Settings</span>
        </a>
      </nav>

      <div class="sidebar-user">
        <img class="avatar-img avatar-md sidebar-user-avatar" id="sidebarAvatar" src="<?php echo htmlspecialchars($adminAvatarPath); ?>" alt="Admin Avatar">
        <strong><?php echo htmlspecialchars($adminDisplayName); ?></strong>
        <small>Active Workspace</small>
      </div>

      <div class="sidebar-footer">
        <span class="status-dot"></span>
        <span class="sidebar-footer-text">System running smoothly</span>
      </div>
    </aside>

    <div class="admin-main">
      <nav class="navbar admin-navbar navbar-expand bg-white">
        <div class="container-fluid px-3 px-lg-4">
          <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-controls="adminSidebar" aria-expanded="true" aria-label="Toggle sidebar">
            <span></span>
            <span></span>
            <span></span>
          </button>

          <form class="d-none d-md-flex ms-3 flex-grow-1" role="search">
            <input class="form-control search-input" type="search" placeholder="Search users, orders, reports" aria-label="Search">
          </form>

          <div class="navbar-actions ms-auto">
            <button class="icon-button theme-toggle" type="button" data-theme-toggle aria-label="Switch color theme" title="Switch color theme">
              <i class="bi bi-moon-stars" data-theme-icon aria-hidden="true"></i>
            </button>

            <div class="dropdown">
              <button class="profile-button dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <img class="avatar-img avatar-sm" id="navbarAvatar" src="<?php echo htmlspecialchars($adminAvatarPath); ?>" alt="Admin">
                <span class="profile-name d-none d-sm-inline"><?php echo htmlspecialchars($adminDisplayName); ?></span>
              </button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                <li><a class="dropdown-item" href="settings.php">Account settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="../index.php">Go to Main Site</a></li>
                <li><a class="dropdown-item" href="../logout.php">Log out</a></li>
              </ul>
            </div>
          </div>
        </div>
      </nav>
      <script>
        // Give main.js the real logged-in admin's data so its initUserProfile()
        // doesn't overwrite the sidebar with its hardcoded fallback ("Admin Hasan").
        window.adminHMDUser = {
          name: <?php echo json_encode($adminDisplayName); ?>,
          workspace: 'Active Workspace',
          avatar: <?php echo json_encode($adminAvatarPath); ?>
        };
      </script>