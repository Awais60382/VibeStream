<?php
require_once __DIR__ . '/admin-bootstrap.php';
require_once __DIR__ . '/ui_helpers.php'; 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_avatar') {
    header('Content-Type: application/json');

    if (!(isset($pdo) && $pdo instanceof PDO && $matchCol && $avatarCol)) {
        $missing = [];
        if (!isset($pdo) || !($pdo instanceof PDO)) $missing[] = 'database connection ($pdo)';
        if (!$matchCol) $missing[] = 'admin identity ($matchCol — session/users table mismatch)';
        if (!$avatarCol) $missing[] = 'avatar column ($avatarCol — no matching column found on users table)';
        echo json_encode(['ok' => false, 'error' => 'Avatar upload is not available right now. Missing: ' . implode(', ', $missing)]);
        exit;
    }

    if (empty($_FILES['avatar']['name']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'No file received.']);
        exit;
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed, true)) {
        echo json_encode(['ok' => false, 'error' => 'Avatar must be jpg, jpeg, png, or webp.']);
        exit;
    }

    $uploadDir = __DIR__ . '/assets/images/avatar/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $newFileName = 'avatar_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$matchVal) . '_' . time() . '.' . $ext;

    if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadDir . $newFileName)) {
        echo json_encode(['ok' => false, 'error' => 'Avatar upload failed. Please check folder permissions.']);
        exit;
    }

    $relativePath = './assets/images/avatar/' . $newFileName;

    try {
        $upd = $pdo->prepare("UPDATE `users` SET `$avatarCol` = :avatar WHERE `$matchCol` = :val");
        $upd->execute([':avatar' => $relativePath, ':val' => $matchVal]);
        echo json_encode(['ok' => true, 'path' => $relativePath]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Could not save avatar. Please try again.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($pdo) && $pdo instanceof PDO && $matchCol) {
    $newName  = trim($_POST['name'] ?? '');
    $newEmail = trim($_POST['email'] ?? '');
    $newPass  = trim($_POST['password'] ?? '');

    $setParts = [];
    $params   = [':val' => $matchVal];

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


    if ($avatarCol && !empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed, true)) {
            $uploadDir = __DIR__ . '/assets/images/avatar/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $newFileName = 'avatar_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$matchVal) . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadDir . $newFileName)) {
                $relativePath = './assets/images/avatar/' . $newFileName;
                $setParts[] = "`$avatarCol` = :avatar";
                $params[':avatar'] = $relativePath;
                $adminAvatarPath = $relativePath;
            } else {
                $errorMsg = 'Avatar upload failed. Please check folder permissions.';
            }
        } else {
            $errorMsg = 'Avatar must be jpg, jpeg, png, or webp.';
        }
    }

    if (!empty($setParts)) {
        try {
            $sql = "UPDATE `users` SET " . implode(', ', $setParts) . " WHERE `$matchCol` = :val";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if ($nameCol && $newName !== '') {
                $adminDisplayName = $newName;
                $_SESSION['AdminSession'] = $newName;
            }

            $successMsg = 'Profile updated successfully.';
        } catch (Throwable $e) {
            $errorMsg = 'Could not update profile. Please try again.';
        }
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