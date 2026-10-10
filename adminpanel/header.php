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
require_once __DIR__ . '/../site-branding.php';
require_once __DIR__ . '/../avatar.php';
$siteLogoUrl = vs_branding_logo_url();
$brandingState = vs_branding_state();
$siteName = vs_branding_app_name($brandingState);
$defaultTheme = vs_branding_default_theme($brandingState);
$brandingNotification = $brandingState['notification'] ?? null;
$brandingNotificationMessage = is_array($brandingNotification)
    ? trim((string)($brandingNotification['message'] ?? ''))
    : '';
$brandingNotificationTime = is_array($brandingNotification)
    ? trim((string)($brandingNotification['created_at'] ?? ''))
    : '';

// "Someone you subscribe to uploaded" notifications for this admin account
require_once __DIR__ . '/../notifications-store.php';
$adminNotifUserId = (int)($_SESSION['user_id'] ?? $_SESSION['AdminID'] ?? 0);
$adminReaderKey = $adminNotifUserId > 0
    ? 'id:' . $adminNotifUserId
    : 'name:' . mb_strtolower(trim((string)($_SESSION['AdminSession'] ?? $_SESSION['username'] ?? '')));
$brandingNotificationRead = is_array($brandingNotification)
    && is_array($brandingNotification['read_by'] ?? null)
    && in_array($adminReaderKey, $brandingNotification['read_by'], true);
if (empty($_SESSION['admin_notif_csrf'])) {
    $_SESSION['admin_notif_csrf'] = bin2hex(random_bytes(32));
}
$adminNotifCsrf = $_SESSION['admin_notif_csrf'];
$adminNotifReturn = (string)($_SERVER['REQUEST_URI'] ?? 'index.php');
$adminChannelNotifs = [];
$adminChannelUnread = 0;
if ($adminNotifUserId > 0 && isset($conn) && $conn instanceof mysqli) {
    try {
        vs_notifications_ensure_table($conn);
        $adminChannelUnread = vs_notifications_unread_count($conn, $adminNotifUserId);
        $notifStmt = $conn->prepare('SELECT message, link, is_read, created_at FROM user_notifications WHERE user_id = ? ORDER BY id DESC LIMIT 8');
        $notifStmt->bind_param('i', $adminNotifUserId);
        $notifStmt->execute();
        $adminChannelNotifs = $notifStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $notifStmt->close();
    } catch (Throwable $error) {
        $adminChannelNotifs = [];
        $adminChannelUnread = 0;
    }
}

// Unread messages from other admins (sidebar badge)
$adminMsgUnread = 0;
if (isset($conn) && $conn instanceof mysqli) {
    $adminMsgUnread = vs_admin_messages_unread($conn, vs_current_user_id($conn));
}

if (isset($pdo) && $pdo instanceof PDO) {
    $uCols = vs_cols($pdo, 'users');
    if ($uCols) {
        $idCol    = vs_pick($uCols, ['id', 'user_id']);
        $unameCol = vs_pick($uCols, ['username', 'user_name', 'login', 'uname']);
        $nameCol  = vs_pick($uCols, ['name', 'full_name', 'fullname', 'display_name']);
        $emailCol = vs_pick($uCols, ['email', 'email_address', 'mail']);
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
$adminEmail = '';
$adminUsername = trim((string)($_SESSION['username'] ?? $_SESSION['AdminSession'] ?? ''));

if (isset($pdo) && $pdo instanceof PDO && isset($uCols) && $uCols) {
    $avatarCol = vs_pick($uCols, ['avatar', 'profile_image', 'photo', 'image']);
    $selectCols = [];
    if (!empty($nameCol))   $selectCols[] = $nameCol;
    if (!empty($unameCol))  $selectCols[] = $unameCol;
    if (!empty($avatarCol)) $selectCols[] = $avatarCol;
    if (!empty($emailCol))  $selectCols[] = $emailCol;

    if (!empty($matchCol) && !empty($selectCols)) {
        try {
            $cols = implode(', ', array_map(fn($c) => "`$c`", array_unique($selectCols)));
            $stmt = $pdo->prepare("SELECT $cols FROM `users` WHERE `$matchCol` = :val LIMIT 1");
            $stmt->execute([':val' => $matchVal]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                if (!empty($unameCol) && !empty($row[$unameCol])) {
                    $adminUsername = (string)$row[$unameCol];
                }
                if (!empty($nameCol) && !empty($row[$nameCol])) {
                    $adminDisplayName = $row[$nameCol];
                } elseif (!empty($unameCol) && !empty($row[$unameCol])) {
                    $adminDisplayName = $row[$unameCol];
                }

                if (!empty($avatarCol) && !empty($row[$avatarCol])) {
                    $adminAvatarPath = $row[$avatarCol];
                }
                if (!empty($emailCol) && !empty($row[$emailCol])) {
                    $adminEmail = (string)$row[$emailCol];
                }
            }
        } catch (Throwable $e) {
            // keep fallbacks on failure
        }
    }
}
$isSiteOwner = vs_is_site_owner_email($adminEmail);
$adminAvatarPath = avatar_url($adminUsername !== '' ? $adminUsername : $adminDisplayName, (string)$adminAvatarPath);
// Letter-avatar fallback so the avatar never renders as a blank/broken box
$adminInitial = mb_strtoupper(mb_substr(trim((string)$adminDisplayName) !== '' ? trim((string)$adminDisplayName) : 'A', 0, 1));
$adminAvatarFallback = 'data:image/svg+xml;charset=UTF-8,' . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#4f6df5"/>'
    . '<text x="32" y="42" font-family="Arial,sans-serif" font-size="30" font-weight="700" fill="#fff" text-anchor="middle">'
    . htmlspecialchars($adminInitial, ENT_QUOTES | ENT_XML1) . '</text></svg>'
);
if (trim((string)$adminAvatarPath) === '') {
    $adminAvatarPath = $adminAvatarFallback;
}
$vsCurrent = basename($_SERVER['SCRIPT_NAME']);
if ($vsCurrent === 'editvideos.php') { $vsCurrent = 'videos.php'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="<?php echo htmlspecialchars($siteName . ' admin panel', ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="theme-color" content="<?php echo $defaultTheme === 'dark' ? '#b02a2a' : '#ffffff'; ?>">
  <meta name="vs-default-theme" content="<?php echo htmlspecialchars($defaultTheme, ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="manifest" href="../manifest.php">
  <link rel="apple-touch-icon" href="../branding-icon.php">
  <title>Admin | <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></title>

  <script>
    (function () {
      var configured = document.querySelector('meta[name="vs-default-theme"]');
      var t = configured && configured.content === 'light' ? 'light' : 'dark';
      try {
        var saved = localStorage.getItem('vs-theme');
        if (saved === 'light' || saved === 'dark') t = saved;
      } catch (e) {}
      document.documentElement.setAttribute('data-theme', t);
      document.documentElement.setAttribute('data-bs-theme', t);
    })();
  </script>
  <link rel="stylesheet" href="./assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="./assets/vendors/bootstrap-icons/bootstrap-icons.css">
  <link rel="stylesheet" href="./assets/css/style.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/style.css'); ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
  <div class="admin-shell">
    <div class="sidebar-backdrop" data-sidebar-close></div>

    <aside class="admin-sidebar" id="adminSidebar" aria-label="Main navigation">
      <div class="sidebar-header">
        <a class="brand-mark" href="index.php" aria-label="VibeStream admin dashboard">
          <span class="brand-icon" aria-hidden="true">
            <img src="<?php echo htmlspecialchars($siteLogoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" width="42" height="42" onerror="this.parentNode.textContent='<?php echo htmlspecialchars(strtoupper(substr($siteName, 0, 1)), ENT_QUOTES, 'UTF-8'); ?>'">
          </span>
          <span class="brand-copy">
            <span class="brand-title"><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="brand-subtitle">Admin Panel</span>
          </span>
        </a>
      </div>

      <nav class="sidebar-nav">
        <div class="sidebar-section-title">MAIN</div>
        <a class="nav-link<?php echo $vsCurrent === 'index.php' ? ' active' : ''; ?>" href="index.php"<?php echo $vsCurrent === 'index.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span>
          <span class="nav-text">Dashboard</span>
        </a>

        <a class="nav-link<?php echo $vsCurrent === 'videos.php' ? ' active' : ''; ?>" href="videos.php"<?php echo $vsCurrent === 'videos.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><i class="bi bi-collection-play" aria-hidden="true"></i></span>
          <span class="nav-text">Videos</span>
        </a>

        <a class="nav-link<?php echo $vsCurrent === 'shorts.php' ? ' active' : ''; ?>" href="shorts.php"<?php echo $vsCurrent === 'shorts.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><i class="bi bi-phone" aria-hidden="true"></i></span>
          <span class="nav-text">Shorts</span>
        </a>

        <a class="nav-link<?php echo $vsCurrent === 'addvideos.php' ? ' active' : ''; ?>" href="addvideos.php"<?php echo $vsCurrent === 'addvideos.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><i class="bi bi-plus-square" aria-hidden="true"></i></span>
          <span class="nav-text">Add Video</span>
        </a>

        <a class="nav-link<?php echo $vsCurrent === 'categories.php' ? ' active' : ''; ?>" href="categories.php"<?php echo $vsCurrent === 'categories.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><i class="bi bi-tags" aria-hidden="true"></i></span>
          <span class="nav-text">Categories</span>
        </a>

        <div class="sidebar-section-title">USERS</div>
        <a class="nav-link<?php echo $vsCurrent === 'Users.php' ? ' active' : ''; ?>" href="Users.php"<?php echo $vsCurrent === 'Users.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
          <span class="nav-text">Users</span>
        </a>

        <a class="nav-link<?php echo $vsCurrent === 'admins.php' ? ' active' : ''; ?>" href="admins.php"<?php echo $vsCurrent === 'admins.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
          <span class="nav-text">Admins</span>
        </a>

        <a class="nav-link<?php echo $vsCurrent === 'user_profile.php' ? ' active' : ''; ?>" href="user_profile.php"<?php echo $vsCurrent === 'user_profile.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><i class="bi bi-person-lines-fill" aria-hidden="true"></i></span>
          <span class="nav-text">Users profile</span>
        </a>

        <a class="nav-link<?php echo $vsCurrent === 'messages.php' ? ' active' : ''; ?>" href="messages.php"<?php echo $vsCurrent === 'messages.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><i class="bi bi-chat-dots" aria-hidden="true"></i></span>
          <span class="nav-text">Messages</span>
          <?php if ($adminMsgUnread > 0): ?><span class="badge rounded-pill bg-danger ms-auto"><?php echo $adminMsgUnread > 99 ? '99+' : (int)$adminMsgUnread; ?></span><?php endif; ?>
        </a>

        <div class="sidebar-section-title">SYSTEM</div>
        <a class="nav-link<?php echo $vsCurrent === 'profile.php' ? ' active' : ''; ?>" href="profile.php"<?php echo $vsCurrent === 'profile.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><i class="bi bi-person-badge" aria-hidden="true"></i></span>
          <span class="nav-text">Profile</span>
        </a>
        <a class="nav-link<?php echo $vsCurrent === 'platform-settings.php' ? ' active' : ''; ?>" href="platform-settings.php"<?php echo $vsCurrent === 'platform-settings.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><i class="bi bi-sliders" aria-hidden="true"></i></span>
          <span class="nav-text">App settings</span>
        </a>

        <div class="sidebar-section-title">NAVIGATION</div>
        <a class="nav-link" href="../index.php">
          <span class="nav-icon"><i class="bi bi-house" aria-hidden="true"></i></span>
          <span class="nav-text">Main Site</span>
        </a>
      </nav>

      <div class="sidebar-user">
        <img class="avatar-img avatar-md sidebar-user-avatar" id="sidebarAvatar" src="<?php echo htmlspecialchars($adminAvatarPath); ?>" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($adminAvatarFallback, ENT_QUOTES); ?>';" alt="Admin Avatar">
        <span class="sidebar-user-copy">
          <strong><?php echo htmlspecialchars($adminDisplayName); ?></strong>
          <small>Active Workspace</small>
        </span>
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

          <form class="d-none d-md-flex ms-3 flex-grow-1" role="search" action="../index.php" method="get">
            <input class="form-control search-input" type="search" name="q" placeholder="Search videos" aria-label="Search videos">
          </form>

          <div class="navbar-actions ms-auto">
            <button class="icon-button theme-toggle" type="button" data-theme-toggle aria-label="Switch color theme" title="Switch color theme">
              <i class="bi bi-moon-stars" data-theme-icon aria-hidden="true"></i>
            </button>

            <div class="dropdown">
              <button class="icon-button notification-toggle dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Admin notifications" title="Admin notifications">
                <i class="bi bi-bell" aria-hidden="true"></i>
                <?php if (($brandingNotificationMessage !== '' && !$brandingNotificationRead) || $adminChannelUnread > 0): ?><span class="notification-dot" aria-hidden="true"></span><?php endif; ?>
              </button>
              <div class="dropdown-menu dropdown-menu-end notification-menu">
                <div class="px-3 py-2 fw-bold">Admin notifications</div>
                <?php if ($brandingNotificationMessage !== ''): ?>
                  <div class="dropdown-item<?php echo $brandingNotificationRead ? ' text-muted' : ''; ?>">
                    <span class="notification-title"<?php echo $brandingNotificationRead ? ' style="font-weight:400"' : ''; ?>><?php echo htmlspecialchars($brandingNotificationMessage); ?></span>
                    <?php if ($brandingNotificationTime !== ''): ?>
                      <small class="notification-time"><?php echo htmlspecialchars($brandingNotificationTime); ?></small>
                    <?php endif; ?>
                    <?php if (!$brandingNotificationRead): ?>
                      <form method="post" action="mark_notifications_read.php" class="mt-1">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($adminNotifCsrf); ?>">
                        <input type="hidden" name="target" value="branding">
                        <input type="hidden" name="return" value="<?php echo htmlspecialchars($adminNotifReturn); ?>">
                        <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none"><i class="bi bi-check2" aria-hidden="true"></i> Mark as read</button>
                      </form>
                    <?php else: ?>
                      <small class="d-block text-muted"><i class="bi bi-check2-all" aria-hidden="true"></i> Read</small>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
                <?php foreach ($adminChannelNotifs as $adminNotif): ?>
                  <a class="dropdown-item<?php echo (int)$adminNotif['is_read'] ? '' : ' fw-semibold'; ?>" href="../<?php echo htmlspecialchars((string)$adminNotif['link']); ?>">
                    <span class="notification-title"><?php echo htmlspecialchars((string)$adminNotif['message']); ?></span>
                    <small class="notification-time"><?php echo htmlspecialchars(vs_notification_time_ago((string)$adminNotif['created_at'])); ?></small>
                  </a>
                <?php endforeach; ?>
                <?php if ($brandingNotificationMessage === '' && !$adminChannelNotifs): ?>
                  <div class="dropdown-item text-muted">No notifications yet.</div>
                <?php endif; ?>
                <?php if ($adminNotifUserId > 0): ?>
                  <div class="dropdown-divider"></div>
                  <?php if (($brandingNotificationMessage !== '' && !$brandingNotificationRead) || $adminChannelUnread > 0): ?>
                    <form method="post" action="mark_notifications_read.php">
                      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($adminNotifCsrf); ?>">
                      <input type="hidden" name="target" value="all">
                      <input type="hidden" name="return" value="<?php echo htmlspecialchars($adminNotifReturn); ?>">
                      <button type="submit" class="dropdown-item text-center"><i class="bi bi-check2-all" aria-hidden="true"></i> Mark all as read</button>
                    </form>
                  <?php endif; ?>
                  <a class="dropdown-item text-center" href="../notifications.php">View all notifications</a>
                <?php endif; ?>
              </div>
            </div>

            <div class="dropdown">
              <button class="profile-button dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <img class="avatar-img avatar-sm" id="navbarAvatar" src="<?php echo htmlspecialchars($adminAvatarPath); ?>" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($adminAvatarFallback, ENT_QUOTES); ?>';" alt="Admin">
                <span class="profile-name d-none d-sm-inline"><?php echo htmlspecialchars($adminDisplayName); ?></span>
              </button>
              <ul class="dropdown-menu dropdown-menu-end profile-menu">
                <li><a class="dropdown-item" href="profile.php"><span class="menu-icon"><i class="bi bi-person-badge" aria-hidden="true"></i></span><span>Profile</span></a></li>
                <li><a class="dropdown-item" href="platform-settings.php"><span class="menu-icon"><i class="bi bi-sliders" aria-hidden="true"></i></span><span>App settings</span></a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="../index.php"><span class="menu-icon"><i class="bi bi-house" aria-hidden="true"></i></span><span>Go to Main Site</span></a></li>
                <li><a class="dropdown-item menu-danger" href="../logout.php"><span class="menu-icon"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></span><span>Log out</span></a></li>
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
        <script>
          if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
              navigator.serviceWorker.register('../sw.js').catch(function (error) {
                console.error('VibeStream offline support could not be enabled.', error);
              });
            });
          }
        </script>