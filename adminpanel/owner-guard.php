<?php
/**
 * Owner check. Only this email may delete admin accounts.
 * Put this file in the adminpanel folder. On any page that deletes an admin,
 * require this file and check vs_is_owner($pdo) on the SERVER before deleting.
 */
include_once __DIR__ . '/ui_helpers.php';

const VS_OWNER_EMAIL = 'muhammadawaiscom0@gmail.com';

/** Email of the admin who is signed in right now (read from the users table), or ''. */
function vs_current_admin_email(PDO $pdo): string
{
    $cols = vs_cols($pdo, 'users');
    if ($cols) {
        $emailCol = vs_pick($cols, ['email', 'email_address', 'mail']);
        $idCol    = vs_pick($cols, ['id', 'user_id']);
        $nameCol  = vs_pick($cols, ['username', 'user_name', 'login', 'uname']);
        $id = $_SESSION['user_id'] ?? $_SESSION['AdminID'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? $_SESSION['uid'] ?? null;
        $name = trim((string)($_SESSION['AdminSession'] ?? $_SESSION['username'] ?? ''));

        if ($emailCol) {
            try {
                if ($id !== null && $idCol) {
                    $st = $pdo->prepare('SELECT ' . vs_q($emailCol) . ' FROM `users` WHERE ' . vs_q($idCol) . ' = :v LIMIT 1');
                    $st->execute([':v' => $id]);
                    $e = $st->fetchColumn();
                    if (is_string($e) && trim($e) !== '') {
                        return strtolower(trim($e));
                    }
                }
                if ($name !== '' && $nameCol) {
                    $st = $pdo->prepare('SELECT ' . vs_q($emailCol) . ' FROM `users` WHERE ' . vs_q($nameCol) . ' = :v LIMIT 1');
                    $st->execute([':v' => $name]);
                    $e = $st->fetchColumn();
                    if (is_string($e) && trim($e) !== '') {
                        return strtolower(trim($e));
                    }
                }
            } catch (Throwable $e) {
                // fall through
            }
        }
    }
    if (!empty($_SESSION['email']) && is_string($_SESSION['email'])) {
        return strtolower(trim($_SESSION['email']));
    }
    return '';
}

/** True only when the signed-in admin's email is VS_OWNER_EMAIL. */
function vs_is_owner(PDO $pdo): bool
{
    $email = vs_current_admin_email($pdo);
    return $email !== '' && hash_equals(strtolower(VS_OWNER_EMAIL), $email);
}