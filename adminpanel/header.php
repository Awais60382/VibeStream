<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["AdminSession"])) {
    header("Location: ../login.php");
    exit();
}

// Admin check-in: must check in after login (can change it, never remove it)
require_once __DIR__ . '/checkin-guard.php';

// --- Update "last active" timestamp for the logged-in admin ---
require_once __DIR__ . '/../connection.php'; // creates $pdo
require_once __DIR__ . '/ui_helpers.php';
require_once __DIR__ . '/../site-branding.php';
require_once __DIR__ . '/../sidebar-icons-store.php';
require_once __DIR__ . '/icon-access-store.php';
require_once __DIR__ . '/../avatar.php';
$siteLogoUrl = vs_branding_logo_url();
$brandingState = vs_branding_state();
$adminLogoUrl = vs_branding_admin_logo_url($brandingState);
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

// "Premium ended" alerts for admins (shown in the bell menu until an admin opens Notifications)
$adminPremiumEnded = [];
$adminPremiumEndedCount = 0;
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        require_once __DIR__ . '/../premium-store.php';
        vs_premium_ensure($pdo);
        vs_premium_sync_end_alerts($pdo);
        $adminPremiumEndedCount = vs_premium_end_alerts_unseen($pdo);
        $adminPremiumEnded = vs_premium_end_alerts($pdo, true, 8);
    } catch (Throwable $error) {
        $adminPremiumEnded = [];
        $adminPremiumEndedCount = 0;
    }
}

// Premium requests waiting for an admin (bell + sidebar badge). Skipped when this admin turned them off on Notifications.
$adminPremiumPendingCount = 0;
$adminPremiumPending = [];
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $wantPremiumPending = true;
        if ($adminNotifUserId > 0) {
            try {
                $pf = $pdo->prepare('SELECT premium_requests FROM admin_notification_prefs WHERE user_id = :id LIMIT 1');
                $pf->execute([':id' => $adminNotifUserId]);
                $pv = $pf->fetchColumn();
                if ($pv !== false && (int)$pv === 0) {
                    $wantPremiumPending = false;
                }
            } catch (Throwable $e) {
                // no saved settings: everything is on
            }
        }
        if ($wantPremiumPending) {
            $adminPremiumPendingCount = (int)$pdo->query("SELECT COUNT(*) FROM premium_requests WHERE status = 'pending'")->fetchColumn();
            if ($adminPremiumPendingCount > 0) {
                $adminPremiumPending = $pdo->query("SELECT username, plan, amount, created_at FROM premium_requests WHERE status = 'pending' ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Throwable $e) {
        $adminPremiumPendingCount = 0;
        $adminPremiumPending = [];
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
$_SESSION['vs_is_owner'] = $isSiteOwner ? 1 : 0; // lets checkin.php show the history to the owner only
// Icon permission requests: only the site owner is told about pending requests.
$vsIconPending = $isSiteOwner ? vs_ia_pending_list() : [];
$vsIconPendingCount = count($vsIconPending);
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

// Record this admin's activity (only the site owner can read it, on admin-activity.php)
require_once __DIR__ . '/admin-activity-store.php';
vs_admin_log_page_view(
    (isset($pdo) && $pdo instanceof PDO) ? $pdo : null,
    (isset($adminUsername) && trim((string)$adminUsername) !== '') ? trim((string)$adminUsername) : vs_activity_session_name(),
    basename($_SERVER['SCRIPT_NAME'])
);
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
  <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($adminLogoUrl, ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="shortcut icon" type="image/png" href="<?php echo htmlspecialchars($adminLogoUrl, ENT_QUOTES, 'UTF-8'); ?>">
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
  <link rel="stylesheet" href="../search-suggest.css?v=<?php echo (int)@filemtime(__DIR__ . '/../search-suggest.css'); ?>">
</head>

<body>
  <div class="admin-shell">
    <div class="sidebar-backdrop" data-sidebar-close></div>

    <aside class="admin-sidebar" id="adminSidebar" aria-label="Main navigation">
      <div class="sidebar-header">
        <a class="brand-mark" href="index.php" aria-label="VibeStream admin dashboard">
          <span class="brand-icon" aria-hidden="true">
            <img src="<?php echo htmlspecialchars($adminLogoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" width="42" height="42" onerror="this.parentNode.textContent='<?php echo htmlspecialchars(strtoupper(substr($siteName, 0, 1)), ENT_QUOTES, 'UTF-8'); ?>'">
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
          <span class="nav-icon"><?php vs_si_open('admin','dashboard'); ?><i class="bi bi-speedometer2" aria-hidden="true"></i><?php vs_si_close(); ?></span>
          <span class="nav-text">Dashboard</span>
        </a>

        <a class="nav-link<?php echo $vsCurrent === 'videos.php' ? ' active' : ''; ?>" href="videos.php"<?php echo $vsCurrent === 'videos.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><?php vs_si_open('admin','videos'); ?><i class="bi bi-collection-play" aria-hidden="true"></i><?php vs_si_close(); ?></span>
          <span class="nav-text">Videos</span>
        </a>

        <a class="nav-link<?php echo $vsCurrent === 'shorts.php' ? ' active' : ''; ?>" href="shorts.php"<?php echo $vsCurrent === 'shorts.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><?php vs_si_open('admin','shorts'); ?><span aria-hidden="true" style="display:block;width:16px;height:20px;background-color:currentColor;-webkit-mask:url(data:image/png;base64,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) center/contain no-repeat;mask:url(data:image/png;base64,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) center/contain no-repeat;"></span><?php vs_si_close(); ?></span>
          <span class="nav-text">Shorts</span>
        </a>

        <a class="nav-link<?php echo $vsCurrent === 'addvideos.php' ? ' active' : ''; ?>" href="addvideos.php"<?php echo $vsCurrent === 'addvideos.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><?php vs_si_open('admin','addvideo'); ?><i class="bi bi-plus-square" aria-hidden="true"></i><?php vs_si_close(); ?></span>
          <span class="nav-text">Add Video</span>
        </a>

        <a class="nav-link<?php echo $vsCurrent === 'categories.php' ? ' active' : ''; ?>" href="categories.php"<?php echo $vsCurrent === 'categories.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><?php vs_si_open('admin','categories'); ?><i class="bi bi-tags" aria-hidden="true"></i><?php vs_si_close(); ?></span>
          <span class="nav-text">Categories</span>
        </a>

        <a class="nav-link<?php echo $vsCurrent === 'reported.php' ? ' active' : ''; ?>" href="reported.php"<?php echo $vsCurrent === 'reported.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><?php vs_si_open('admin','reported'); ?><i class="bi bi-flag" aria-hidden="true"></i><?php vs_si_close(); ?></span>
          <span class="nav-text">Reported content</span>
        </a>

        <div class="sidebar-section-title">USERS</div>
        <a class="nav-link<?php echo $vsCurrent === 'Users.php' ? ' active' : ''; ?>" href="Users.php"<?php echo $vsCurrent === 'Users.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><?php vs_si_open('admin','users'); ?><i class="bi bi-people" aria-hidden="true"></i><?php vs_si_close(); ?></span>
          <span class="nav-text">Users</span>
        </a>

        <a class="nav-link<?php echo $vsCurrent === 'admins.php' ? ' active' : ''; ?>" href="admins.php"<?php echo $vsCurrent === 'admins.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><?php vs_si_open('admin','admins'); ?><i class="bi bi-shield-check" aria-hidden="true"></i><?php vs_si_close(); ?></span>
          <span class="nav-text">Admins</span>
        </a>

        <a class="nav-link<?php echo $vsCurrent === 'user_profile.php' ? ' active' : ''; ?>" href="user_profile.php"<?php echo $vsCurrent === 'user_profile.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><?php vs_si_open('admin','userprofile'); ?><i class="bi bi-person-lines-fill" aria-hidden="true"></i><?php vs_si_close(); ?></span>
          <span class="nav-text">Users profile</span>
        </a>

        <a class="nav-link<?php echo $vsCurrent === 'messages.php' ? ' active' : ''; ?>" href="messages.php"<?php echo $vsCurrent === 'messages.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><?php vs_si_open('admin','messages'); ?><i class="bi bi-chat-dots" aria-hidden="true"></i><?php vs_si_close(); ?></span>
          <span class="nav-text">Messages</span>
          <?php if ($adminMsgUnread > 0): ?><span class="badge rounded-pill bg-danger ms-auto"><?php echo $adminMsgUnread > 99 ? '99+' : (int)$adminMsgUnread; ?></span><?php endif; ?>
        </a>

        <a class="nav-link<?php echo $vsCurrent === 'premium.php' ? ' active' : ''; ?>" href="premium.php"<?php echo $vsCurrent === 'premium.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><?php vs_si_open('admin','premium'); ?><i class="bi bi-gem" aria-hidden="true"></i><?php vs_si_close(); ?></span>
          <span class="nav-text">Premium</span>
        </a>

        <div class="sidebar-section-title">SYSTEM</div>
        <a class="nav-link<?php echo $vsCurrent === 'profile.php' ? ' active' : ''; ?>" href="profile.php"<?php echo $vsCurrent === 'profile.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><?php vs_si_open('admin','profile'); ?><i class="bi bi-person-badge" aria-hidden="true"></i><?php vs_si_close(); ?></span>
          <span class="nav-text">Profile</span>
        </a>
        <a class="nav-link<?php echo $vsCurrent === 'platform-settings.php' ? ' active' : ''; ?>" href="platform-settings.php"<?php echo $vsCurrent === 'platform-settings.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><?php vs_si_open('admin','settings'); ?><i class="bi bi-sliders" aria-hidden="true"></i><?php vs_si_close(); ?></span>
          <span class="nav-text">App settings</span>
        </a>
        <a class="nav-link<?php echo $vsCurrent === 'icons.php' ? ' active' : ''; ?>" href="icons.php<?php echo $vsIconPendingCount > 0 ? '#tab-access' : ''; ?>"<?php echo $vsCurrent === 'icons.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><?php vs_si_open('admin','icons'); ?><i class="bi bi-image" aria-hidden="true"></i><?php vs_si_close(); ?></span>
          <span class="nav-text">Icons</span>
          <?php if ($vsIconPendingCount > 0): ?><span class="badge rounded-pill bg-danger ms-auto"><?php echo (int)$vsIconPendingCount; ?></span><?php endif; ?>
        </a>
        <a class="nav-link<?php echo $vsCurrent === 'owner-notices.php' ? ' active' : ''; ?>" href="owner-notices.php"<?php echo $vsCurrent === 'owner-notices.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><i class="bi bi-send-check" aria-hidden="true"></i></span>
          <span class="nav-text">Owner notices</span>
        </a>
        <a class="nav-link<?php echo $vsCurrent === 'notifications.php' ? ' active' : ''; ?>" href="notifications.php"<?php echo $vsCurrent === 'notifications.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><i class="bi bi-bell" aria-hidden="true"></i></span>
          <span class="nav-text">Notifications</span>
          <?php if ($adminPremiumPendingCount > 0): ?><span class="badge rounded-pill bg-danger ms-auto"><?php echo (int)$adminPremiumPendingCount; ?></span><?php endif; ?>
        </a>
<?php if (!empty($isSiteOwner)): ?>
        <a class="nav-link<?php echo $vsCurrent === 'site-icons.php' ? ' active' : ''; ?>" href="site-icons.php"<?php echo $vsCurrent === 'site-icons.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><i class="bi bi-images" aria-hidden="true"></i></span>
          <span class="nav-text">Site icons</span>
        </a>
<?php
        // Amber "!" on Backups when the last backup is missing or older than 7 days
        $vsBackupAge = null;
        $vsBackupFile = __DIR__ . '/../backups/history.json';
        if (is_file($vsBackupFile)) {
            $vsBackupList = json_decode((string)@file_get_contents($vsBackupFile), true);
            if (is_array($vsBackupList) && $vsBackupList) {
                $vsBackupTime = strtotime((string)($vsBackupList[0]['created'] ?? ''));
                if ($vsBackupTime) {
                    $vsBackupAge = (int)floor((time() - $vsBackupTime) / 86400);
                }
            }
        }
        $vsBackupStale = $vsBackupAge === null || $vsBackupAge >= 7;
?>
        <a class="nav-link<?php echo $vsCurrent === 'backup.php' ? ' active' : ''; ?>" href="backup.php"<?php echo $vsCurrent === 'backup.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><i class="bi bi-database-down" aria-hidden="true"></i></span>
          <span class="nav-text">Backups</span>
          <?php if ($vsBackupStale): ?><span class="badge rounded-pill bg-warning text-dark ms-auto" title="<?php echo $vsBackupAge === null ? 'No backup yet' : 'Last backup is ' . (int)$vsBackupAge . ' days old'; ?>">!</span><?php endif; ?>
        </a>
        <a class="nav-link<?php echo $vsCurrent === 'system-check.php' ? ' active' : ''; ?>" href="system-check.php"<?php echo $vsCurrent === 'system-check.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><i class="bi bi-heart-pulse" aria-hidden="true"></i></span>
          <span class="nav-text">System check</span>
        </a>
        <a class="nav-link<?php echo $vsCurrent === 'admin-activity.php' ? ' active' : ''; ?>" href="admin-activity.php"<?php echo $vsCurrent === 'admin-activity.php' ? ' aria-current="page"' : ''; ?>>
          <span class="nav-icon"><?php vs_si_open('admin','activity'); ?><i class="bi bi-activity" aria-hidden="true"></i><?php vs_si_close(); ?></span>
          <span class="nav-text">Admin activity</span>
        </a>
<?php endif; ?>

        <div class="sidebar-section-title">NAVIGATION</div>
        <a class="nav-link" href="../index.php">
          <span class="nav-icon"><?php vs_si_open('admin','mainsite'); ?><i class="bi bi-house" aria-hidden="true"></i><?php vs_si_close(); ?></span>
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
        <span class="sidebar-footer-text">System running smoothly &middot; <a href="checkin.php#team" style="color:inherit;text-decoration:underline dotted"><?php echo (int)vs_checkin_online_count(); ?> online</a></span>
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
            <input class="form-control search-input" type="search" name="q" placeholder="Search videos" aria-label="Search videos" autocomplete="off" data-vs-suggest-input="videos">
          </form>

          <div class="navbar-actions ms-auto">
            <?php $vsCk = $_SESSION['vs_checkin'] ?? null; if (is_array($vsCk)): ?>
            <a href="checkin.php" class="vs-checkin-badge" title="Your check-in (click to change)">
              <span class="vs-ck-dot" style="background:<?php echo htmlspecialchars(vs_checkin_color($vsCk['status']), ENT_QUOTES); ?>"></span><?php echo htmlspecialchars($vsCk['status']); ?>
            </a>
            <style>
              .vs-checkin-badge{display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:999px;border:1px solid rgba(128,128,128,.35);color:inherit;font-size:13px;text-decoration:none;white-space:nowrap}
              .vs-checkin-badge:hover{background:rgba(128,128,128,.15);color:inherit}
              .vs-ck-dot{width:9px;height:9px;border-radius:50%;display:inline-block}
            </style>
            <?php endif; ?>

            <button class="icon-button theme-toggle" type="button" data-theme-toggle aria-label="Switch color theme" title="Switch color theme">
              <i class="bi bi-moon-stars" data-theme-icon aria-hidden="true"></i>
            </button>

            <div class="dropdown">
              <button class="icon-button notification-toggle dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Admin notifications" title="Admin notifications">
                <i class="bi bi-bell" aria-hidden="true"></i>
                <?php if (($brandingNotificationMessage !== '' && !$brandingNotificationRead) || $adminChannelUnread > 0 || $adminPremiumEndedCount > 0 || $vsIconPendingCount > 0 || $adminPremiumPendingCount > 0): ?><span class="notification-dot" aria-hidden="true"></span><?php endif; ?>
              </button>
              <style>
                .vs-an-menu { width: 380px; max-width: calc(100vw - 24px); padding: 0; border-radius: 14px; overflow: hidden; }
                .vs-an-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--bs-border-color, rgba(128,128,128,.3)); font-size: 17px; }
                .vs-an-head a { color: inherit; font-size: 20px; line-height: 1; display: inline-flex; padding: 4px; border-radius: 50%; }
                .vs-an-head a:hover { background: rgba(128,128,128,.18); }
                .vs-an-body { max-height: 340px; overflow-y: auto; }
                .vs-an-body .dropdown-item { white-space: normal; padding: 10px 18px; }
                .vs-an-empty { text-align: center; padding: 40px 28px 34px; }
                .vs-an-empty .bi { font-size: 54px; opacity: .55; display: block; line-height: 1; margin-bottom: 12px; }
                .vs-an-empty strong { display: block; font-size: 17px; margin-bottom: 8px; }
                .vs-an-empty p { margin: 0; font-size: 14px; opacity: .7; line-height: 1.5; }
                .vs-an-foot { display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid var(--bs-border-color, rgba(128,128,128,.3)); }
                .vs-an-foot form { margin: 0; }
                .vs-an-foot .vs-an-btn { display: block; width: 100%; padding: 13px 8px; text-align: center; background: none; border: 0; color: inherit; font-size: 15px; font-weight: 500; text-decoration: none; cursor: pointer; }
                .vs-an-foot .vs-an-btn:hover { background: rgba(128,128,128,.14); }
                .vs-an-foot .vs-an-read { color: #3ea6ff; }
                .vs-an-foot > :last-child { border-left: 1px solid var(--bs-border-color, rgba(128,128,128,.3)); }
                .vs-an-item { display: flex !important; align-items: flex-start; gap: 12px; }
                .vs-an-av { width: 38px; height: 38px; flex: 0 0 38px; border-radius: 50%; object-fit: cover; background: #4f6df5; }
                .vs-an-txt { min-width: 0; flex: 1 1 auto; }
                .vs-an-item.is-read .vs-an-av { opacity: .75; }
                .vs-an-item.is-read .notification-title { font-weight: 400; opacity: .8; }
                .vs-an-sep { padding: 8px 18px 4px; font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; opacity: .55; }
              </style>
              <div class="dropdown-menu dropdown-menu-end notification-menu vs-an-menu">
                <div class="vs-an-head">
                  <span>Notifications</span>
                  <a href="notifications.php#notifSettings" title="Notification settings" aria-label="Notification settings"><i class="bi bi-gear" aria-hidden="true"></i></a>
                </div>
                <div class="vs-an-body">
                  <?php
                  // Build one list: unread first, anything already read is moved below. Every row gets an avatar.
                  $vsAnLetter = static function (string $n): string {
                      $c = mb_strtoupper(mb_substr(trim($n) !== '' ? trim($n) : 'N', 0, 1));
                      return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode(
                          '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#4f6df5"/>'
                          . '<text x="32" y="43" font-family="Arial,sans-serif" font-size="30" font-weight="700" fill="#fff" text-anchor="middle">'
                          . htmlspecialchars($c, ENT_QUOTES | ENT_XML1) . '</text></svg>'
                      );
                  };
                  $vsAnItems = [];
                  foreach ($adminPremiumPending as $pp) {
                      $vsAnItems[] = [
                          'unread' => true, 'href' => 'notifications.php', 'name' => (string)$pp['username'],
                          'title' => (string)$pp['username'] . ' wants Premium',
                          'sub' => ucfirst((string)$pp['plan']) . ' plan · $' . number_format((float)$pp['amount'], 2) . ' · ' . date('M j, g:i A', strtotime((string)$pp['created_at'])),
                          'form' => false,
                      ];
                  }
                  // Owner only: admins asking for permission to change icons (stays here until approved or denied)
                  foreach ($vsIconPending as $reqName => $reqAt) {
                      $vsAnItems[] = [
                          'unread' => true, 'href' => 'icons.php#tab-access', 'name' => (string)$reqName,
                          'title' => (string)$reqName . ' is asking permission to change icons',
                          'sub' => ($reqAt !== '' && strtotime($reqAt)) ? date('M j, Y, H:i', strtotime($reqAt)) . ' · Tap to approve or deny' : 'Tap to approve or deny',
                          'form' => false,
                      ];
                  }
                  foreach ($adminPremiumEnded as $pe) {
                      $vsAnItems[] = [
                          'unread' => true, 'href' => 'notifications.php', 'name' => (string)$pe['username'],
                          'title' => (string)$pe['username'] . "'s Premium has ended",
                          'sub' => ucfirst((string)$pe['plan']) . ' plan · ' . date('M j, Y', strtotime((string)$pe['ended_at'])),
                          'form' => false,
                      ];
                  }
                  if ($brandingNotificationMessage !== '') {
                      $bName = '';
                      if (preg_match('/ by ([^.]+?)\.?$/u', $brandingNotificationMessage, $bm)) {
                          $bName = trim($bm[1]);
                      }
                      $vsAnItems[] = [
                          'unread' => !$brandingNotificationRead, 'href' => '', 'name' => $bName, 'logo' => ($bName === ''),
                          'title' => $brandingNotificationMessage, 'sub' => $brandingNotificationTime, 'form' => !$brandingNotificationRead,
                      ];
                  }
                  foreach ($adminChannelNotifs as $adminNotif) {
                      $msg = (string)$adminNotif['message'];
                      $cName = preg_match('/^(.+?) (?:uploaded|posted|published|liked|commented|replied|subscribed|started|shared|added)\b/u', $msg, $cm) ? trim($cm[1]) : '';
                      $vsAnItems[] = [
                          'unread' => !(int)$adminNotif['is_read'], 'href' => '../' . (string)$adminNotif['link'], 'name' => $cName,
                          'title' => $msg, 'sub' => vs_notification_time_ago((string)$adminNotif['created_at']), 'form' => false,
                      ];
                  }
                  $vsAnUnread = array_values(array_filter($vsAnItems, static fn($i) => $i['unread']));
                  $vsAnRead   = array_values(array_filter($vsAnItems, static fn($i) => !$i['unread']));
                  $vsAnShow = function (array $it) use ($vsAnLetter, $siteLogoUrl, $adminNotifCsrf, $adminNotifReturn) {
                      $name = (string)$it['name'];
                      $fallback = $vsAnLetter($name !== '' ? $name : 'N');
                      $avatar = !empty($it['logo']) ? $siteLogoUrl : ($name !== '' ? avatar_url($name) : $fallback);
                      if (trim((string)$avatar) === '') { $avatar = $fallback; }
                      $cls = 'dropdown-item vs-an-item' . ($it['unread'] ? ' fw-semibold' : ' is-read text-muted');
                      $tag = $it['href'] !== '' ? 'a' : 'div';
                      echo '<' . $tag . ' class="' . $cls . '"' . ($it['href'] !== '' ? ' href="' . htmlspecialchars($it['href']) . '"' : '') . '>';
                      echo '<img class="vs-an-av" src="' . htmlspecialchars($avatar) . '" alt="" onerror="this.onerror=null;this.src=\'' . htmlspecialchars($fallback, ENT_QUOTES) . '\';">';
                      echo '<span class="vs-an-txt"><span class="notification-title"' . ($it['unread'] ? '' : ' style="font-weight:400"') . '>' . htmlspecialchars((string)$it['title']) . '</span>';
                      if ((string)$it['sub'] !== '') { echo '<small class="notification-time d-block">' . htmlspecialchars((string)$it['sub']) . '</small>'; }
                      if (!empty($it['form'])) {
                          echo '<form method="post" action="mark_notifications_read.php" class="mt-1">'
                             . '<input type="hidden" name="csrf" value="' . htmlspecialchars($adminNotifCsrf) . '">'
                             . '<input type="hidden" name="target" value="branding">'
                             . '<input type="hidden" name="return" value="' . htmlspecialchars($adminNotifReturn) . '">'
                             . '<button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none"><i class="bi bi-check2" aria-hidden="true"></i> Mark as read</button></form>';
                      } elseif (!$it['unread'] && $it['href'] === '') {
                          echo '<small class="d-block text-muted"><i class="bi bi-check2-all" aria-hidden="true"></i> Read</small>';
                      }
                      echo '</span></' . $tag . '>';
                  };
                  foreach ($vsAnUnread as $it) { $vsAnShow($it); }
                  if ($vsAnUnread && $vsAnRead) { echo '<div class="vs-an-sep">Earlier</div>'; }
                  foreach ($vsAnRead as $it) { $vsAnShow($it); }
                  ?>
                  <?php if (!$vsAnItems): ?>
                    <div class="vs-an-empty">
                      <i class="bi bi-bell" aria-hidden="true"></i>
                      <strong>Your notifications live here</strong>
                      <p>Subscribe to channels and get told when they upload, plus likes and comments on your videos.</p>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="vs-an-foot">
                  <form method="post" action="mark_notifications_read.php">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($adminNotifCsrf); ?>">
                    <input type="hidden" name="target" value="all">
                    <input type="hidden" name="return" value="<?php echo htmlspecialchars($adminNotifReturn); ?>">
                    <button type="submit" class="vs-an-btn vs-an-read">Mark all as read</button>
                  </form>
                  <a class="vs-an-btn" href="notifications.php">View all</a>
                </div>
                <script>
                  // "Mark all as read" also clears the Premium-ended alerts
                  document.querySelectorAll('.vs-an-foot form').forEach(function (f) {
                    f.addEventListener('submit', function () {
                      try {
                        var d = new FormData();
                        d.append('csrf', f.querySelector('[name=csrf]').value);
                        navigator.sendBeacon('mark_premium_alerts_seen.php', d);
                      } catch (e) {}
                    });
                  });
                </script>
              </div>
            </div>

            <div class="dropdown">
              <button class="profile-button dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <img class="avatar-img avatar-sm" id="navbarAvatar" src="<?php echo htmlspecialchars($adminAvatarPath); ?>" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($adminAvatarFallback, ENT_QUOTES); ?>';" alt="Admin">
                <span class="profile-name d-none d-sm-inline"><?php echo htmlspecialchars($adminDisplayName); ?></span>
              </button>
              <ul class="dropdown-menu dropdown-menu-end profile-menu">
                <li><a class="dropdown-item" href="profile.php"><span class="menu-icon"><?php vs_si_open('admin','menu_profile'); ?><i class="bi bi-person-badge" aria-hidden="true"></i><?php vs_si_close(); ?></span><span>Profile</span></a></li>
                <li><a class="dropdown-item" href="platform-settings.php"><span class="menu-icon"><?php vs_si_open('admin','menu_settings'); ?><i class="bi bi-sliders" aria-hidden="true"></i><?php vs_si_close(); ?></span><span>App settings</span></a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="../index.php"><span class="menu-icon"><?php vs_si_open('admin','menu_mainsite'); ?><i class="bi bi-house" aria-hidden="true"></i><?php vs_si_close(); ?></span><span>Go to Main Site</span></a></li>
                <li><a class="dropdown-item menu-danger" href="../logout.php"><span class="menu-icon"><?php vs_si_open('admin','menu_logout'); ?><i class="bi bi-box-arrow-right" aria-hidden="true"></i><?php vs_si_close(); ?></span><span>Log out</span></a></li>
              </ul>
            </div>
          </div>
        </div>
      </nav>
      <script src="../search-suggest.js?v=<?php echo (int)@filemtime(__DIR__ . '/../search-suggest.js'); ?>"></script>
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