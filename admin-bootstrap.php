<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["AdminSession"])) {
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . '/../connection.php'; 
require_once __DIR__ . '/ui_helpers.php';

$uCols = null;
$idCol = $unameCol = $nameCol = $laCol = $pwdChangedCol = $avatarCol = null;
$matchCol = null; $matchVal = null;

if (isset($pdo) && $pdo instanceof PDO) {
    $uCols = vs_cols($pdo, 'users');
    if ($uCols) {
        $idCol         = vs_pick($uCols, ['id', 'user_id']);
        $unameCol      = vs_pick($uCols, ['username', 'user_name', 'login', 'uname']);
        $nameCol       = vs_pick($uCols, ['name', 'full_name', 'fullname', 'display_name']);
        $laCol         = vs_pick($uCols, ['last_active', 'last_seen', 'last_login']);
        $pwdChangedCol = vs_pick($uCols, ['password_changed_at', 'pass_changed_at', 'pwd_changed_at', 'password_updated_at', 'credentials_changed_at']);
        $avatarCol     = vs_pick($uCols, ['avatar', 'profile_image', 'photo', 'image']);

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
            }
        }

        if ($pwdChangedCol && $matchCol) {
            try {
                $pcStmt = $pdo->prepare("SELECT `$pwdChangedCol` FROM `users` WHERE `$matchCol` = :val LIMIT 1");
                $pcStmt->execute([':val' => $matchVal]);
                $dbPwdChangedAt = $pcStmt->fetchColumn();

                if (!isset($_SESSION['PwdChangedAt'])) {
                    $_SESSION['PwdChangedAt'] = $dbPwdChangedAt;
                } elseif ($dbPwdChangedAt !== false && $_SESSION['PwdChangedAt'] !== $dbPwdChangedAt) {
                    $_SESSION = [];
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_destroy();
                    }
                    header("Location: ../login.php?reason=password_changed");
                    exit();
                }
            } catch (Throwable $e) {
            }
        }
    }
}

$adminDisplayName = $_SESSION["AdminSession"];
$adminAvatarPath  = './assets/images/avatar/avatar.jpg'; 

if (isset($pdo) && $pdo instanceof PDO && $uCols) {
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
        }
    }
}
