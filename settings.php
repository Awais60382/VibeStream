<?php
require_once __DIR__ . '/admin-bootstrap.php';

$successMsg = '';
$errorMsg   = '';

$passCol = null;
if (isset($pdo) && $pdo instanceof PDO && isset($uCols) && $uCols) {
    $passCol = vs_pick($uCols, ['password', 'pass', 'passwd']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_username') {
    $newUsername = trim($_POST['new_username'] ?? '');

    if (!$unameCol) {
        $errorMsg = 'Username field not found on the users table.';
    } elseif ($newUsername === '') {
        $errorMsg = 'Username cannot be empty.';
    } elseif (!preg_match('/^[A-Za-z0-9_.\-]{3,30}$/', $newUsername)) {
        $errorMsg = 'Username must be 3-30 characters (letters, numbers, _ . - only).';
    } elseif (isset($pdo) && $pdo instanceof PDO && $matchCol) {
        try {
            $check = $pdo->prepare("SELECT COUNT(*) FROM `users` WHERE `$unameCol` = :newuname AND `$matchCol` != :val");
            $check->execute([':newuname' => $newUsername, ':val' => $matchVal]);

            if ((int)$check->fetchColumn() > 0) {
                $errorMsg = 'That username is already taken.';
            } else {
                $upd = $pdo->prepare("UPDATE `users` SET `$unameCol` = :newuname WHERE `$matchCol` = :val");
                $upd->execute([':newuname' => $newUsername, ':val' => $matchVal]);

                if ($matchCol === $unameCol) {
                    $matchVal = $newUsername;
                }
                if (empty($nameCol)) {
                    $adminDisplayName = $newUsername;
                }
                $_SESSION['AdminSession'] = $adminDisplayName;

                $successMsg = 'Username updated successfully.';
            }
        } catch (Throwable $e) {
            $errorMsg = 'Could not update username. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $currentPass = $_POST['current_password'] ?? '';
    $newPass     = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (!$passCol) {
        $errorMsg = 'Password field not found on the users table.';
    } elseif ($newPass === '' || $newPass !== $confirmPass) {
        $errorMsg = 'New password and confirmation do not match.';
    } elseif (isset($pdo) && $pdo instanceof PDO && $matchCol) {
        try {
            $stmt = $pdo->prepare("SELECT `$passCol` FROM `users` WHERE `$matchCol` = :val LIMIT 1");
            $stmt->execute([':val' => $matchVal]);
            $storedHash = $stmt->fetchColumn();

            $ok = $storedHash && (password_verify($currentPass, $storedHash) || $currentPass === $storedHash);

            if (!$ok) {
                $errorMsg = 'Current password is incorrect.';
            } else {
                $setParts   = ["`$passCol` = :newpass"];
                $params     = [':newpass' => password_hash($newPass, PASSWORD_DEFAULT), ':val' => $matchVal];

                if (!empty($pwdChangedCol)) {
                    $setParts[] = "`$pwdChangedCol` = NOW()";
                }

                $upd = $pdo->prepare("UPDATE `users` SET " . implode(', ', $setParts) . " WHERE `$matchCol` = :val");
                $upd->execute($params);

                $_SESSION = [];
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_destroy();
                }
                header("Location: ../login.php?reason=password_changed");
                exit();
            }
        } catch (Throwable $e) {
            $errorMsg = 'Could not change password. Please try again.';
        }
    }
}

$currentUsername = '';
if (isset($pdo) && $pdo instanceof PDO && $matchCol && $unameCol) {
    try {
        $stmt = $pdo->prepare("SELECT `$unameCol` FROM `users` WHERE `$matchCol` = :val LIMIT 1");
        $stmt->execute([':val' => $matchVal]);
        $currentUsername = $stmt->fetchColumn() ?: '';
    } catch (Throwable $e) {
  
    }
}

require_once __DIR__ . '/header.php';
?>

      <main class="admin-content p-3 p-lg-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <h1 class="h4 mb-0">Settings</h1>
        </div>

        <?php if ($successMsg): ?>
          <div class="alert alert-success" role="alert"><?php echo htmlspecialchars($successMsg); ?></div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
          <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($errorMsg); ?></div>
        <?php endif; ?>

        <?php if ($unameCol): ?>
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-body p-4">
            <h2 class="h6 mb-3">Username</h2>
            <form method="post">
              <input type="hidden" name="action" value="change_username">
              <div class="mb-3">
                <label for="new_username" class="form-label">Username</label>
                <input type="text" class="form-control" id="new_username" name="new_username"
                       value="<?php echo htmlspecialchars($currentUsername); ?>"
                       pattern="[A-Za-z0-9_.\-]{3,30}" title="3-30 characters: letters, numbers, _ . -" required>
              </div>
              <button type="submit" class="btn btn-primary">Update username</button>
            </form>
          </div>
        </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-4">
          <div class="card-body p-4">
            <h2 class="h6 mb-3">Change password</h2>
            <form method="post">
              <input type="hidden" name="action" value="change_password">
              <div class="mb-3">
                <label for="current_password" class="form-label">Current password</label>
                <div class="input-group">
                  <input type="password" class="form-control" id="current_password" name="current_password" required>
                  <button type="button" class="btn btn-outline-secondary" data-toggle-password="current_password"
                          aria-label="Show password" aria-pressed="false" tabindex="-1">
                    <i class="bi bi-eye" aria-hidden="true"></i>
                  </button>
                </div>
              </div>
              <div class="mb-3">
                <label for="new_password" class="form-label">New password</label>
                <div class="input-group">
                  <input type="password" class="form-control" id="new_password" name="new_password" required>
                  <button type="button" class="btn btn-outline-secondary" data-toggle-password="new_password"
                          aria-label="Show password" aria-pressed="false" tabindex="-1">
                    <i class="bi bi-eye" aria-hidden="true"></i>
                  </button>
                </div>
              </div>
              <div class="mb-4">
                <label for="confirm_password" class="form-label">Confirm new password</label>
                <div class="input-group">
                  <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                  <button type="button" class="btn btn-outline-secondary" data-toggle-password="confirm_password"
                          aria-label="Show password" aria-pressed="false" tabindex="-1">
                    <i class="bi bi-eye" aria-hidden="true"></i>
                  </button>
                </div>
              </div>
              <button type="submit" class="btn btn-primary">Update password</button>
            </form>
          </div>
        </div>

        <div class="card border-0 shadow-sm">
          <div class="card-body p-4">
            <h2 class="h6 mb-3">Appearance</h2>
            <p class="text-muted mb-3">Switch between light and dark mode. Your choice is remembered on this device.</p>

            <button type="button"
                    class="btn btn-outline-secondary d-inline-flex align-items-center gap-2"
                    data-theme-toggle>
              <i class="bi bi-moon-stars" data-theme-icon aria-hidden="true"></i>
              <span>Toggle theme</span>
            </button>

            <div class="d-flex align-items-center gap-2 mt-4">
              <span class="status-dot"></span>
              <span>Logged in as <strong><?php echo htmlspecialchars($adminDisplayName); ?></strong></span>
            </div>
          </div>
        </div>
      </main>
    </div>
  </div>

  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
  <script>
    (function () {
      var buttons = document.querySelectorAll('[data-toggle-password]');
      Array.prototype.forEach.call(buttons, function (btn) {
        var input = document.getElementById(btn.getAttribute('data-toggle-password'));
        var icon = btn.querySelector('i');
        if (!input || !icon) return;

        btn.addEventListener('click', function () {
          var showing = input.type === 'text';
          input.type = showing ? 'password' : 'text';
          btn.setAttribute('aria-pressed', String(!showing));
          btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
          icon.classList.toggle('bi-eye', showing);
          icon.classList.toggle('bi-eye-slash', !showing);
        });
      });
    })();
  </script>
</body>
</html>