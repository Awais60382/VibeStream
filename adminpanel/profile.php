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

require_once __DIR__ . '/header.php';
?>

      <main class="admin-content p-3 p-lg-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <h1 class="h4 mb-0">My Profile</h1>
        </div>

        <?php if ($successMsg): ?>
          <div class="alert alert-success" role="alert"><?php echo htmlspecialchars($successMsg); ?></div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
          <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($errorMsg); ?></div>
        <?php endif; ?>

        <div id="avatarAlert"></div>

        <div class="card border-0 shadow-sm">
          <div class="card-body p-4">
            <form method="post" enctype="multipart/form-data" id="profileForm">
              <div class="d-flex align-items-center gap-3 mb-4">
                <img src="<?php echo htmlspecialchars($adminAvatarPath); ?>" alt="Avatar"
                     id="avatarPreview"
                     class="avatar-img" style="width:72px;height:72px;border-radius:50%;object-fit:cover;">
                <div>
                  <label for="avatar" class="form-label mb-1">Change avatar</label>
                  <input class="form-control form-control-sm" type="file" id="avatar" name="avatar" accept=".jpg,.jpeg,.png,.webp">
                </div>
              </div>

              <?php if ($unameCol): ?>
              <div class="mb-3">
                <label for="username" class="form-label">User name</label>
                <input type="text" class="form-control" id="username" name="username"
                       value="<?php echo htmlspecialchars($avatarUser); ?>" placeholder="User name" autocomplete="off">
                <div class="form-text">Changing it also renames your videos, shorts, comments and profile picture.</div>
              </div>
              <?php endif; ?>

              <div class="mb-3">
                <label for="name" class="form-label">Display name</label>
                <input type="text" class="form-control" id="name" name="name"
                       value="<?php echo htmlspecialchars($adminDisplayName); ?>" placeholder="Full name">
              </div>

              <?php if ($emailCol): ?>
              <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email"
                       value="<?php echo htmlspecialchars($currentEmail); ?>" placeholder="you@example.com">
              </div>
              <?php endif; ?>

              <?php if ($passCol): ?>
              <div class="mb-4">
                <label for="password" class="form-label">New password</label>
                <div style="position: relative;">
                  <input type="password" class="form-control" id="password" name="password"
                         placeholder="Leave blank to keep current password"
                         style="padding-right: 2.6rem;">
                  <button type="button" id="togglePassword"
                          aria-label="Show password" aria-pressed="false" tabindex="-1"
                          style="position: absolute; top: 50%; right: 0.6rem; transform: translateY(-50%);
                                 background: none; border: none; padding: 0.25rem; line-height: 1;
                                 color: var(--admin-muted, #6b7280);">
                    <i class="bi bi-eye" id="togglePasswordIcon" aria-hidden="true"></i>
                  </button>
                </div>
              </div>
              <?php endif; ?>

              <button type="submit" class="btn btn-primary">Save changes</button>
            </form>
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

      if (avatarInput && avatarPreview) {
        avatarInput.addEventListener('change', function () {
          var file = avatarInput.files && avatarInput.files[0];
          if (!file) return;
          if (!file.type.startsWith('image/')) return;

    
          var reader = new FileReader();
          reader.onload = function (e) {
            setAllAvatars(e.target.result);
          };
          reader.readAsDataURL(file);

      
          var formData = new FormData();
          formData.append('action', 'update_avatar');
          formData.append('avatar', file);

          fetch(window.location.href, {
            method: 'POST',
            body: formData
          })
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
            .finally(function () {
              avatarInput.value = '';
            });
        });
      }
    })();

    (function () {
      var input = document.getElementById('password');
      var btn = document.getElementById('togglePassword');
      var icon = document.getElementById('togglePasswordIcon');
      if (!input || !btn || !icon) return;

      btn.addEventListener('click', function () {
        var currentlyVisible = input.type === 'text';
        input.type = currentlyVisible ? 'password' : 'text';
        btn.setAttribute('aria-pressed', String(!currentlyVisible));
        btn.setAttribute('aria-label', currentlyVisible ? 'Show password' : 'Hide password');
        icon.classList.toggle('bi-eye-slash', !currentlyVisible);
        icon.classList.toggle('bi-eye', currentlyVisible);
      });
    })();
  </script>

  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
</body>
</html>