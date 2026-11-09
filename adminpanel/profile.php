<?php
require_once __DIR__ . '/admin-bootstrap.php';
require_once __DIR__ . '/ui_helpers.php'; 
require_once __DIR__ . '/../avatar.php';
require_once __DIR__ . '/owner-guard.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Every table/column that stores a user name (same list release-username.php uses).
 * The `users` table itself is skipped: its row is changed by the profile form.
 */
if (!function_exists('vs_profile_name_columns')) {
    function vs_profile_name_columns(PDO $pdo): array
    {
        $names = ['username', 'user_name', 'uploader', 'channel', 'channel_name', 'subscriber', 'subscriber_name',
                  'author', 'owner', 'commenter', 'posted_by', 'created_by'];
        $in = implode(',', array_map(static fn($c) => "'" . $c . "'", $names));
        $rows = $pdo->query(
            "SELECT c.TABLE_NAME, c.COLUMN_NAME
             FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES t
               ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
             WHERE c.TABLE_SCHEMA = DATABASE()
               AND c.DATA_TYPE IN ('varchar','char','tinytext','text','mediumtext','longtext')
               AND c.COLUMN_NAME IN ($in)"
        )->fetchAll(PDO::FETCH_NUM);
        $out = [];
        foreach ($rows as [$t, $c]) {
            if (strcasecmp((string)$t, 'users') !== 0) {
                $out[] = [(string)$t, (string)$c];
            }
        }
        return $out;
    }
}

/** Returns '' when $new is a free, valid user name, otherwise a message to show. */
if (!function_exists('vs_profile_check_username')) {
    function vs_profile_check_username(PDO $pdo, string $new, string $old, string $unameCol, string $matchCol, $matchVal): string
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $new)) {
            return 'User name must be 3 to 30 characters: letters, numbers, dot, dash or underscore.';
        }
        if (stripos($new, 'deleted_') === 0) {
            return 'That user name is not allowed.';
        }
        if (strcasecmp($new, $old) === 0) {
            return ''; // only the letter case changes
        }
        $st = $pdo->prepare(
            'SELECT 1 FROM `users` WHERE LOWER(' . vs_q($unameCol) . ') = LOWER(?) AND ' . vs_q($matchCol) . ' <> ? LIMIT 1'
        );
        $st->execute([$new, $matchVal]);
        if ($st->fetchColumn()) {
            return 'That user name is already taken.';
        }
        // A deleted account may have left videos, comments... under this name.
        foreach (vs_profile_name_columns($pdo) as [$t, $c]) {
            try {
                $st = $pdo->prepare('SELECT 1 FROM ' . vs_q($t) . ' WHERE LOWER(' . vs_q($c) . ') = LOWER(?) LIMIT 1');
                $st->execute([$new]);
                if ($st->fetchColumn()) {
                    return 'That user name is still attached to old content. Ask the owner to free it first.';
                }
            } catch (Throwable $e) {
                // a column we cannot read cannot clash
            }
        }
        return '';
    }
}

/** Renames the user everywhere (videos, shorts, comments, subscriptions, ...). */
if (!function_exists('vs_profile_rename_everywhere')) {
    function vs_profile_rename_everywhere(PDO $pdo, string $old, string $new): void
    {
        foreach (vs_profile_name_columns($pdo) as [$t, $c]) {
            $st = $pdo->prepare('UPDATE ' . vs_q($t) . ' SET ' . vs_q($c) . ' = ? WHERE LOWER(' . vs_q($c) . ') = LOWER(?)');
            $st->execute([$new, $old]);
        }
    }
}

$successMsg = '';
$errorMsg   = '';


$nameCol    = $nameCol    ?? null;
$unameCol   = $unameCol   ?? null;
$matchCol   = $matchCol   ?? null;
$matchVal   = $matchVal   ?? null;
$avatarCol  = $avatarCol  ?? null;
$emailCol   = null;
$passCol    = null;

if (isset($pdo) && $pdo instanceof PDO) {
    if (!isset($uCols) || !$uCols) {
        $uCols = vs_cols($pdo, 'users');
    }

    if ($uCols) {
        $emailCol = vs_pick($uCols, ['email', 'email_address']);
        $passCol  = vs_pick($uCols, ['password', 'pass', 'passwd']);

        if (!$nameCol)   $nameCol   = vs_pick($uCols, ['name', 'full_name', 'fullname', 'display_name']);
        if (!$unameCol)  $unameCol  = vs_pick($uCols, ['username', 'user_name', 'login', 'uname']);
        if (!$avatarCol) $avatarCol = vs_pick($uCols, ['avatar', 'profile_image', 'photo', 'image']);

        if (!$matchCol) {
            $idCol = vs_pick($uCols, ['id', 'user_id']);
            if ($idCol && !empty($_SESSION['AdminID'])) {
                $matchCol = $idCol;
                $matchVal = $_SESSION['AdminID'];
            } elseif ($unameCol && !empty($_SESSION['AdminSession'])) {
                $matchCol = $unameCol;
                $matchVal = $_SESSION['AdminSession'];
            } elseif ($nameCol && !empty($_SESSION['AdminSession'])) {
                $matchCol = $nameCol;
                $matchVal = $_SESSION['AdminSession'];
            }
        }
    }
}

// The main site stores avatars per username, so use the real username here
// (not the display name) to keep the admin panel, messages and main site in sync.
$avatarUser = trim((string)($_SESSION['username'] ?? ''));
if ($avatarUser === '' && isset($pdo) && $pdo instanceof PDO && $unameCol && $matchCol) {
    try {
        $unStmt = $pdo->prepare("SELECT `$unameCol` FROM `users` WHERE `$matchCol` = :val LIMIT 1");
        $unStmt->execute([':val' => $matchVal]);
        $avatarUser = trim((string)$unStmt->fetchColumn());
    } catch (Throwable $e) {
    }
}
if ($avatarUser === '') {
    $avatarUser = trim((string)($_SESSION['AdminSession'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_avatar') {
    header('Content-Type: application/json');

    if ($avatarUser === '') {
        echo json_encode(['ok' => false, 'error' => 'Avatar upload is not available right now. Your account name could not be found.']);
        exit;
    }

    $saveError = avatar_save($avatarUser, $_FILES['avatar'] ?? null);
    if ($saveError !== '') {
        echo json_encode(['ok' => false, 'error' => $saveError]);
        exit;
    }

    if (isset($pdo) && $pdo instanceof PDO && $matchCol && $avatarCol) {
        try {
            $upd = $pdo->prepare("UPDATE `users` SET `$avatarCol` = :avatar WHERE `$matchCol` = :val");
            $upd->execute([':avatar' => 'uploads/avatars/' . basename(avatar_path($avatarUser)), ':val' => $matchVal]);
        } catch (Throwable $e) {
            // the picture itself is saved; the database copy is optional
        }
    }
    echo json_encode(['ok' => true, 'path' => avatar_url($avatarUser)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'check_username') {
    header('Content-Type: application/json');
    $candidate = trim((string)($_POST['username'] ?? ''));
    if (!isset($pdo) || !($pdo instanceof PDO) || !$unameCol || !$matchCol || $avatarUser === '') {
        echo json_encode(['ok' => false, 'message' => 'Could not check right now.']);
        exit;
    }
    $problem = vs_profile_check_username($pdo, $candidate, $avatarUser, $unameCol, $matchCol, $matchVal);
    echo json_encode(['ok' => $problem === '', 'same' => strcasecmp($candidate, $avatarUser) === 0, 'message' => $problem]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($pdo) && $pdo instanceof PDO && $matchCol) {
    $newName  = trim($_POST['name'] ?? '');
    $newEmail = trim($_POST['email'] ?? '');
    $newPass  = trim($_POST['password'] ?? '');
    $newUser  = trim($_POST['username'] ?? '');

    $setParts = [];
    $params   = [':val' => $matchVal];

    // Check everything first: if anything is wrong, nothing is saved.
    $blocked  = false;
    $renameTo = null;

    $confirmPass = trim((string)($_POST['confirm_password'] ?? ''));
    if ($newPass !== '' || $confirmPass !== '') {
        if ($newPass !== $confirmPass) {
            $errorMsg = 'The two passwords do not match.';
            $blocked  = true;
        } elseif (strlen($newPass) < 8 || strlen($newPass) > 72) {
            $errorMsg = 'The new password must be 8 to 72 characters.';
            $blocked  = true;
        }
    }

    if ($unameCol && $newUser !== '' && $avatarUser !== '' && $newUser !== $avatarUser) {
        $problem = vs_profile_check_username($pdo, $newUser, $avatarUser, $unameCol, $matchCol, $matchVal);
        if ($problem !== '') {
            $errorMsg = $problem;
            $blocked  = true;
        } else {
            $renameTo = $newUser;
            $setParts[] = "`$unameCol` = :uname";
            $params[':uname'] = $renameTo;
        }
    }

    if (!$blocked && $emailCol && $newEmail !== '') {
        $ownerMail  = strtolower(VS_OWNER_EMAIL);
        $isOwnerNow = vs_is_owner($pdo);
        $emailLower = strtolower($newEmail);
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = 'That email address is not valid.';
            $blocked  = true;
        } elseif ($isOwnerNow && $emailLower !== $ownerMail) {
            $errorMsg = 'The owner email cannot be changed.';
            $blocked  = true;
        } elseif (!$isOwnerNow && $emailLower === $ownerMail) {
            $errorMsg = 'That email belongs to the owner.';
            $blocked  = true;
        } else {
            try {
                $st = $pdo->prepare(
                    'SELECT 1 FROM `users` WHERE LOWER(TRIM(' . vs_q($emailCol) . ')) = ? AND ' . vs_q($matchCol) . ' <> ? LIMIT 1'
                );
                $st->execute([$emailLower, $matchVal]);
                if ($st->fetchColumn()) {
                    $errorMsg = 'That email is already used by another account.';
                    $blocked  = true;
                }
            } catch (Throwable $e) {
                // the database lock still protects the owner email
            }
        }
    }

    if ($nameCol && $newName !== '') {
        $setParts[] = "`$nameCol` = :name";
        $params[':name'] = $newName;
    }
    if ($emailCol && $newEmail !== '') {
        $setParts[] = "`$emailCol` = :email";
        $params[':email'] = $newEmail;
    }
    if ($passCol && $newPass !== '') {
        $setParts[] = "`$passCol` = :password";
        $params[':password'] = password_hash($newPass, PASSWORD_DEFAULT);
    }


    $avatarSaved = false;
    if (!$blocked && !empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $saveError = $avatarUser !== '' ? avatar_save($avatarUser, $_FILES['avatar']) : 'Your account name could not be found.';
        if ($saveError === '') {
            $avatarSaved = true;
            if ($avatarCol) {
                $setParts[] = "`$avatarCol` = :avatar";
                $params[':avatar'] = 'uploads/avatars/' . basename(avatar_path($avatarUser));
            }
            $adminAvatarPath = avatar_url($avatarUser);
        } else {
            $errorMsg = $saveError;
        }
    }

    if ($blocked) {
        // $errorMsg already says why; nothing was changed.
    } elseif (!empty($setParts)) {
        try {
            $sql = "UPDATE `users` SET " . implode(', ', $setParts) . " WHERE `$matchCol` = :val";
            $pdo->beginTransaction();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if ($renameTo !== null) {
                vs_profile_rename_everywhere($pdo, $avatarUser, $renameTo);
            }
            $pdo->commit();

            if ($nameCol && $newName !== '') {
                $adminDisplayName = $newName;
                $_SESSION['AdminSession'] = $newName;
            }

            $successMsg = 'Profile updated successfully.';

            if ($renameTo !== null) {
                // Move the profile picture to the new name and keep the session in sync.
                $oldKey = md5(mb_strtolower($avatarUser));
                $newKey = md5(mb_strtolower($renameTo));
                $movedFile = '';
                if ($oldKey !== $newKey) {
                    foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
                        $from = __DIR__ . '/../uploads/avatars/' . $oldKey . '.' . $ext;
                        $to   = __DIR__ . '/../uploads/avatars/' . $newKey . '.' . $ext;
                        if (is_file($from) && @rename($from, $to)) {
                            $movedFile = $newKey . '.' . $ext;
                        }
                    }
                }

                $newMatchVal = ($matchCol === $unameCol) ? $renameTo : $matchVal;
                if ($avatarCol && $movedFile !== '') {
                    try {
                        $up = $pdo->prepare("UPDATE `users` SET `$avatarCol` = :avatar WHERE `$matchCol` = :val");
                        $up->execute([':avatar' => 'uploads/avatars/' . $movedFile, ':val' => $newMatchVal]);
                    } catch (Throwable $e) {
                        // the picture file is moved; the database copy is optional
                    }
                }

                $matchVal = $newMatchVal;
                $_SESSION['username'] = $renameTo;
                if ($matchCol === $unameCol || strcasecmp((string)($_SESSION['AdminSession'] ?? ''), $avatarUser) === 0) {
                    $_SESSION['AdminSession'] = $renameTo;
                }
                $avatarUser = $renameTo;
                $adminAvatarPath = avatar_url($avatarUser);
                $successMsg = 'Profile updated successfully. Your user name is now "' . $renameTo . '".';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMsg = 'Could not update profile. Please try again.';
        }
    } elseif ($avatarSaved) {
        $successMsg = 'Profile updated successfully.';
    } elseif ($errorMsg === '') {
        $errorMsg = 'No changes submitted.';
    }
}

$currentEmail = '';
if (isset($pdo) && $pdo instanceof PDO && $matchCol && $emailCol) {
    try {
        $stmt = $pdo->prepare("SELECT `$emailCol` FROM `users` WHERE `$matchCol` = :val LIMIT 1");
        $stmt->execute([':val' => $matchVal]);
        $currentEmail = $stmt->fetchColumn() ?: '';
    } catch (Throwable $e) {
      
    }
}


// Account info card (best effort: shows a dash when the column does not exist)
$acct = ['id' => '', 'role' => '', 'joined' => '', 'last' => ''];
if (isset($pdo) && $pdo instanceof PDO && $matchCol && !empty($uCols)) {
    try {
        $st = $pdo->prepare("SELECT * FROM `users` WHERE `$matchCol` = :val LIMIT 1");
        $st->execute([':val' => $matchVal]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $pickCol = static function (array $names) use ($uCols) { return vs_pick($uCols, $names); };
        $idC = $pickCol(['id', 'user_id']);
        $roleC = $pickCol(['role', 'user_role', 'usertype', 'user_type', 'account_type']);
        $joinC = $pickCol(['created_at', 'joined_at', 'registered_at', 'date_joined', 'created_on', 'reg_date']);
        $lastC = $pickCol(['last_login', 'last_active', 'last_seen']);
        $acct['id'] = $idC && isset($row[$idC]) ? '#' . $row[$idC] : '';
        $acct['role'] = $roleC && isset($row[$roleC]) ? ucfirst(strtolower((string)$row[$roleC])) : '';
        $acct['joined'] = $joinC && !empty($row[$joinC]) ? date('M j, Y', strtotime((string)$row[$joinC])) : '';
        $acct['last'] = $lastC && !empty($row[$lastC]) ? date('M j, Y H:i', strtotime((string)$row[$lastC])) : '';
        if (function_exists('vs_is_owner') && vs_is_owner($pdo)) {
            $acct['role'] = 'Owner';
        }
        if ($acct['last'] === '' && $avatarUser !== '') {
            // fall back to the sign-in log from the account-safety feature, if it is installed
            try {
                $ls = $pdo->prepare('SELECT created_at FROM login_activity WHERE username = :u AND success = 1 ORDER BY id DESC LIMIT 1');
                $ls->execute([':u' => $avatarUser]);
                $lt = $ls->fetchColumn();
                if ($lt) {
                    $acct['last'] = date('M j, Y H:i', strtotime((string)$lt));
                }
            } catch (Throwable $e) {
                // no sign-in log table: leave it empty
            }
        }
    } catch (Throwable $e) {
        // leave the card with dashes
    }
}

require_once __DIR__ . '/header.php';

$h = static function ($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};
?>

<style>
  .mp-card{border:1px solid rgba(128,128,128,.28);border-radius:14px;background:rgba(128,128,128,.04);height:100%}
  .mp-card-head{display:flex;align-items:center;gap:10px;padding:14px 18px 4px}
  .mp-card-head h2{font-size:1rem;margin:0;font-weight:700}
  .mp-body{padding:10px 18px 18px}
  .mp-avatar{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;padding:22px 14px;height:100%}
  .mp-avatar img{width:112px;height:112px;border-radius:50%;object-fit:cover}
  .mp-info-row{display:flex;align-items:center;gap:12px;padding:10px 0}
  .mp-info-row i{font-size:18px;color:#4f6df5;width:22px;text-align:center}
  .mp-info-row .k{flex:1;opacity:.85}
  .mp-info-row .v{font-weight:600;text-align:right}
  .mp-mini{display:flex;gap:14px;align-items:flex-start;padding:16px 18px;border:1px solid rgba(128,128,128,.28);border-radius:14px;background:rgba(128,128,128,.04);height:100%}
  .mp-mini>i{font-size:26px;color:#4f6df5}
  .mp-mini h3{font-size:.95rem;margin:0 0 2px;font-weight:700}
  .mp-mini p{font-size:.8rem;opacity:.75;margin:0 0 10px}
  .mp-ok{display:flex;gap:14px;align-items:flex-start;padding:16px 18px;border-radius:14px;background:rgba(39,199,122,.12);border:1px solid rgba(39,199,122,.4)}
  .mp-ok>i{font-size:30px;color:#27c77a}
  .mp-ok strong{display:block;color:#1fa971}
  .mp-pw{position:relative}
  .mp-pw .form-control{padding-right:2.6rem}
  .mp-eye{position:absolute;top:50%;right:.6rem;transform:translateY(-50%);background:none;border:0;padding:.25rem;line-height:1;color:inherit;opacity:.7}
  .mp-state{font-size:.75rem;font-weight:700;padding:2px 10px;border-radius:6px}
  .mp-state.ok{background:rgba(39,199,122,.18);color:#1fa971}
  .mp-state.bad{background:rgba(240,69,127,.16);color:#e0306f}
  .mp-req{color:#e0306f}
</style>

<main class="admin-content p-3 p-lg-4">
  <div class="page-heading mb-3">
    <div class="page-heading-copy">
      <span class="page-icon"><i class="bi bi-person-fill" aria-hidden="true"></i></span>
      <div>
        <h1 class="h3 mb-1">Manage Profile</h1>
        <p class="text-muted mb-0">Update your profile information and settings.</p>
      </div>
    </div>
  </div>

  <?php if ($successMsg): ?>
    <div class="alert alert-success" role="alert"><?php echo $h($successMsg); ?></div>
  <?php endif; ?>
  <?php if ($errorMsg): ?>
    <div class="alert alert-danger" role="alert"><?php echo $h($errorMsg); ?></div>
  <?php endif; ?>
  <div id="avatarAlert"></div>

  <!-- the picture chooser lives outside the form but belongs to it -->
  <input class="d-none" type="file" id="avatar" name="avatar" form="profileForm" accept=".jpg,.jpeg,.png,.webp">

  <div class="row g-3">
    <div class="col-12 col-xl-9">
      <div class="row g-3">
        <!-- Avatar -->
        <div class="col-12 col-md-4 col-lg-3">
          <section class="mp-card" aria-label="Avatar">
            <div class="mp-avatar">
              <img src="<?php echo $h($adminAvatarPath); ?>" alt="Avatar" id="avatarPreview" class="avatar-img">
              <button type="button" class="btn btn-primary btn-sm" data-pick-avatar>Change Avatar</button>
            </div>
          </section>
        </div>

        <!-- Profile information -->
        <div class="col-12 col-md-8 col-lg-9">
          <section class="mp-card" aria-label="Profile information">
            <div class="mp-card-head"><h2 class="text-primary">Profile Information</h2></div>
            <form method="post" enctype="multipart/form-data" id="profileForm" class="mp-body" autocomplete="off">
              <div class="row g-3">
                <div class="col-12 col-lg-7">
                  <div class="mb-3">
                    <label for="name" class="form-label fw-semibold">Full Name <span class="mp-req">*</span></label>
                    <input type="text" class="form-control" id="name" name="name" value="<?php echo $h($adminDisplayName); ?>" placeholder="Full name">
                  </div>

                  <?php if ($unameCol): ?>
                  <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center">
                      <label for="username" class="form-label fw-semibold">User Name <span class="mp-req">*</span></label>
                      <span class="mp-state" id="unameState" hidden></span>
                    </div>
                    <input type="text" class="form-control" id="username" name="username" value="<?php echo $h($avatarUser); ?>" placeholder="User name" autocomplete="off" maxlength="30">
                    <div class="form-text">3 - 30 characters (letters, numbers, . - _). Changing it also renames your videos, Shorts, comments and profile picture.</div>
                  </div>
                  <?php endif; ?>

                  <?php if ($emailCol): ?>
                  <div class="mb-3">
                    <label for="email" class="form-label fw-semibold">Email Address</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?php echo $h($currentEmail); ?>" placeholder="you@example.com">
                  </div>
                  <?php endif; ?>
                </div>

                <?php if ($passCol): ?>
                <div class="col-12 col-lg-5">
                  <div class="mb-3">
                    <label for="password" class="form-label fw-semibold">New Password</label>
                    <div class="mp-pw">
                      <input type="password" class="form-control" id="password" name="password" placeholder="Leave blank to keep current" autocomplete="new-password" maxlength="72">
                      <button type="button" class="mp-eye" data-eye="password" aria-label="Show password" tabindex="-1"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                  </div>
                  <div class="mb-3">
                    <label for="confirm_password" class="form-label fw-semibold">Confirm Password</label>
                    <div class="mp-pw">
                      <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Leave blank to keep current" autocomplete="new-password" maxlength="72">
                      <button type="button" class="mp-eye" data-eye="confirm_password" aria-label="Show password" tabindex="-1"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                    <div class="form-text">At least 8 characters.</div>
                  </div>
                </div>
                <?php endif; ?>
              </div>
              <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Update Profile</button>
            </form>
          </section>
        </div>
      </div>

      <div class="row g-3 mt-0">
        <div class="col-12 col-md-4">
          <div class="mp-mini"><i class="bi bi-shield-check" aria-hidden="true"></i>
            <div><h3>Security</h3><p>Keep your account secure with a strong password.</p><button type="button" class="btn btn-outline-primary btn-sm" id="goPassword">Change Password <i class="bi bi-chevron-right" aria-hidden="true"></i></button></div></div>
        </div>
        <div class="col-12 col-md-4">
          <div class="mp-mini"><i class="bi bi-image" aria-hidden="true"></i>
            <div><h3>Avatar</h3><p>Update your profile picture.</p><button type="button" class="btn btn-outline-primary btn-sm" data-pick-avatar>Change Avatar <i class="bi bi-chevron-right" aria-hidden="true"></i></button></div></div>
        </div>
        <div class="col-12 col-md-4">
          <div class="mp-mini"><i class="bi bi-gear-fill" aria-hidden="true"></i>
            <div><h3>Account Settings</h3><p>Manage your account preferences.</p><a class="btn btn-outline-primary btn-sm" href="../profile.php">View Settings <i class="bi bi-chevron-right" aria-hidden="true"></i></a></div></div>
        </div>
      </div>
    </div>

    <!-- Account info -->
    <div class="col-12 col-xl-3">
      <section class="mp-card" aria-label="Account info">
        <div class="mp-card-head"><i class="bi bi-people-fill text-primary" aria-hidden="true"></i><h2>Account Info</h2></div>
        <div class="mp-body">
          <div class="mp-info-row"><i class="bi bi-person-circle" aria-hidden="true"></i><span class="k">User ID</span><span class="v"><?php echo $acct['id'] !== '' ? $h($acct['id']) : '&mdash;'; ?></span></div>
          <div class="mp-info-row"><i class="bi bi-person-badge" aria-hidden="true"></i><span class="k">Role</span><span class="v"><?php echo $acct['role'] !== '' ? $h($acct['role']) : '&mdash;'; ?></span></div>
          <div class="mp-info-row"><i class="bi bi-calendar3" aria-hidden="true"></i><span class="k">Joined</span><span class="v"><?php echo $acct['joined'] !== '' ? $h($acct['joined']) : '&mdash;'; ?></span></div>
          <div class="mp-info-row"><i class="bi bi-clock-history" aria-hidden="true"></i><span class="k">Last Login</span><span class="v"><?php echo $acct['last'] !== '' ? $h($acct['last']) : '&mdash;'; ?></span></div>
        </div>
      </section>
      <div class="mp-ok mt-3">
        <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
        <div>
          <strong><?php echo $successMsg ? 'Profile updated!' : 'Your account is active'; ?></strong>
          <span class="small"><?php echo $successMsg ? 'Your changes were saved.' : 'Keep your password strong and your e-mail up to date.'; ?></span>
        </div>
      </div>
    </div>
  </div>
</main>
    </div>
  </div>

  <script>
    (function () {
      var avatarInput = document.getElementById('avatar');
      var avatarPreview = document.getElementById('avatarPreview');
      var sidebarAvatar = document.getElementById('sidebarAvatar');
      var navbarAvatar = document.getElementById('navbarAvatar');

      function setAllAvatars(src) {
        if (avatarPreview) avatarPreview.src = src;
        if (sidebarAvatar) sidebarAvatar.src = src;
        if (navbarAvatar) navbarAvatar.src = src;
      }

      var avatarAlert = document.getElementById('avatarAlert');

      function showAvatarMessage(text, isError) {
        if (!avatarAlert) return;
        avatarAlert.innerHTML =
          '<div class="alert ' + (isError ? 'alert-danger' : 'alert-success') + '" role="alert">' +
          text.replace(/[<>&]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]; }) +
          '</div>';
      }

      document.querySelectorAll('[data-pick-avatar]').forEach(function (b) {
        b.addEventListener('click', function () { if (avatarInput) avatarInput.click(); });
      });

      if (avatarInput && avatarPreview) {
        avatarInput.addEventListener('change', function () {
          var file = avatarInput.files && avatarInput.files[0];
          if (!file) return;
          if (!file.type.startsWith('image/')) return;

          var reader = new FileReader();
          reader.onload = function (e) { setAllAvatars(e.target.result); };
          reader.readAsDataURL(file);

          var formData = new FormData();
          formData.append('action', 'update_avatar');
          formData.append('avatar', file);

          fetch(window.location.href, { method: 'POST', body: formData })
            .then(function (res) { return res.json(); })
            .then(function (data) {
              if (data.ok) {
                setAllAvatars(data.path + '?t=' + Date.now());
                showAvatarMessage('Profile updated successfully.', false);
              } else {
                showAvatarMessage(data.error || 'Could not save avatar.', true);
              }
            })
            .catch(function () {
              showAvatarMessage('Could not save avatar. Please check your connection and try again.', true);
            })
            .finally(function () { avatarInput.value = ''; });
        });
      }
    })();

    // Show / hide password buttons
    document.querySelectorAll('[data-eye]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = document.getElementById(btn.getAttribute('data-eye'));
        var icon = btn.querySelector('i');
        if (!input) return;
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        icon.classList.toggle('bi-eye-slash', show);
        icon.classList.toggle('bi-eye', !show);
      });
    });

    // "Change Password" shortcut
    (function () {
      var go = document.getElementById('goPassword'), pw = document.getElementById('password');
      if (go && pw) go.addEventListener('click', function () { pw.scrollIntoView({ behavior: 'smooth', block: 'center' }); pw.focus(); });
    })();

    // Live "Available" check for the user name
    (function () {
      var input = document.getElementById('username'), badge = document.getElementById('unameState'), timer = null;
      if (!input || !badge) return;
      function show(ok, text) {
        badge.hidden = false;
        badge.className = 'mp-state ' + (ok ? 'ok' : 'bad');
        badge.textContent = text;
      }
      input.addEventListener('input', function () {
        clearTimeout(timer);
        var value = input.value.trim();
        if (value === '') { badge.hidden = true; return; }
        timer = setTimeout(function () {
          var fd = new FormData();
          fd.append('action', 'check_username');
          fd.append('username', value);
          fetch(window.location.href, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
              if (value !== input.value.trim()) return;
              if (d.same) { badge.hidden = true; return; }
              show(!!d.ok, d.ok ? 'Available' : (d.message || 'Not available'));
            })
            .catch(function () { badge.hidden = true; });
        }, 400);
      });
    })();
  </script>

  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
</body>
</html>