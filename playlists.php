<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/connection.php';

$username = trim((string)($_SESSION['username'] ?? ''));
$isLoggedIn = $username !== '' && strcasecmp((string)($_SESSION['role'] ?? ''), 'guest') !== 0;
$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!$isLoggedIn) {
    $userId = 0;
}
$pageError = '';
$selectedId = false;
$showLiked = (string)($_GET['list'] ?? '') === 'liked';
$likedVideos = [];
$likedVideoIds = isset($_SESSION['liked_videos']) && is_array($_SESSION['liked_videos'])
    ? array_values(array_unique(array_filter(array_map('strval', $_SESSION['liked_videos']), static fn(string $id): bool => $id !== '' && strlen($id) <= 191)))
    : [];
$addVideoId = trim((string)($_GET['add'] ?? ''));
if (strlen($addVideoId) > 191) {
    $addVideoId = '';
}
$sortBy = (string)($_GET['sort'] ?? 'recent');
if (!in_array($sortBy, ['recent', 'oldest', 'name', 'videos'], true)) {
    $sortBy = 'recent';
}
$listScope = (string)($_GET['view'] ?? 'all');
if (!in_array($listScope, ['all', 'owned'], true) || (!$isLoggedIn && $listScope === 'owned')) {
    $listScope = 'all';
}
$notice = (string)($_SESSION['playlist_notice'] ?? '');
unset($_SESSION['playlist_notice']);

try {
    if ($isLoggedIn && ($userId === false || $userId <= 0)) {
        $userColumns = [];
        $result = $conn->query('SHOW COLUMNS FROM `users`');
        while ($column = $result->fetch_assoc()) {
            $userColumns[strtolower((string)$column['Field'])] = (string)$column['Field'];
        }
        $idColumn = $userColumns['id'] ?? $userColumns['user_id'] ?? null;
        $nameColumn = $userColumns['username'] ?? $userColumns['user_name'] ?? null;
        if ($idColumn === null || $nameColumn === null) {
            throw new RuntimeException('Your account table is missing a user ID or username column.');
        }
        $lookup = $conn->prepare('SELECT `' . str_replace('`', '``', $idColumn) . '` FROM `users` WHERE `' . str_replace('`', '``', $nameColumn) . '` = ? LIMIT 1');
        $lookup->bind_param('s', $username);
        $lookup->execute();
        $userId = filter_var($lookup->get_result()->fetch_row()[0] ?? null, FILTER_VALIDATE_INT);
        $lookup->close();
        if ($userId === false || $userId <= 0) {
            throw new RuntimeException('Could not find your account. Please sign in again.');
        }
        $_SESSION['user_id'] = $userId;
    }

    if ($likedVideoIds) {
        $queryIds = array_reverse($likedVideoIds);
        $placeholders = implode(',', array_fill(0, count($queryIds), '?'));
        $likedQuery = $conn->prepare('SELECT * FROM `videos` WHERE CAST(`id` AS CHAR) IN (' . $placeholders . ')');
        $likedTypes = str_repeat('s', count($queryIds));
        $likedQuery->bind_param($likedTypes, ...$queryIds);
        $likedQuery->execute();
        $likedVideos = $likedQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $likedQuery->close();
        $likedOrder = array_flip($queryIds);
        usort($likedVideos, static fn(array $left, array $right): int =>
            ($likedOrder[(string)$left['id']] ?? PHP_INT_MAX) <=> ($likedOrder[(string)$right['id']] ?? PHP_INT_MAX)
        );
    }

    $conn->query('SELECT 1 FROM `playlists` LIMIT 0');
    $conn->query('SELECT 1 FROM `playlist_videos` LIMIT 0');
    if (empty($_SESSION['playlist_csrf'])) {
        $_SESSION['playlist_csrf'] = bin2hex(random_bytes(32));
    }
    $csrf = (string)$_SESSION['playlist_csrf'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$isLoggedIn || $userId <= 0) {
            throw new RuntimeException('Sign in to create or edit playlists.');
        }
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('That request expired. Refresh the page and try again.');
        }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100) {
                throw new RuntimeException('Playlist names must be between 1 and 100 characters.');
            }
            $visibility = (string)($_POST['visibility'] ?? 'private') === 'public' ? 'public' : 'private';
            $returnVideoId = trim((string)($_POST['return_video_id'] ?? ''));
            if (strlen($returnVideoId) > 191) {
                throw new RuntimeException('Enter a valid video ID.');
            }
            if ($returnVideoId !== '') {
                $exists = $conn->prepare('SELECT 1 FROM `videos` WHERE CAST(`id` AS CHAR) = ? LIMIT 1');
                $exists->bind_param('s', $returnVideoId);
                $exists->execute();
                $hasVideo = (bool)$exists->get_result()->fetch_row();
                $exists->close();
                if (!$hasVideo) {
                    throw new RuntimeException('That video could not be found.');
                }
            }
            $insert = $conn->prepare('INSERT INTO `playlists` (`user_id`, `name`, `visibility`) VALUES (?, ?, ?)');
            $insert->bind_param('iss', $userId, $name, $visibility);
            $insert->execute();
            $newPlaylistId = (int)$conn->insert_id;
            $insert->close();
            if ($returnVideoId !== '') {
                $add = $conn->prepare('INSERT IGNORE INTO `playlist_videos` (`playlist_id`, `video_id`) VALUES (?, ?)');
                $add->bind_param('is', $newPlaylistId, $returnVideoId);
                $add->execute();
                $add->close();
            }
            $_SESSION['playlist_notice'] = 'Playlist created.';
            if ($returnVideoId !== '' && (string)($_POST['return_to'] ?? '') === 'save') {
                header('Location: playlists.php?add=' . rawurlencode($returnVideoId));
                exit;
            }
            header('Location: playlists.php?id=' . $newPlaylistId);
            exit;
        }

        $playlistId = filter_var($_POST['playlist_id'] ?? null, FILTER_VALIDATE_INT);
        if ($playlistId === false || $playlistId <= 0) {
            throw new RuntimeException('Choose a valid playlist.');
        }
        $owned = $conn->prepare('SELECT `id` FROM `playlists` WHERE `id` = ? AND `user_id` = ? LIMIT 1');
        $owned->bind_param('ii', $playlistId, $userId);
        $owned->execute();
        $isOwner = (bool)$owned->get_result()->fetch_row();
        $owned->close();
        if (!$isOwner) {
            throw new RuntimeException('You can only change playlists you created.');
        }

        if ($action === 'delete') {
            $delete = $conn->prepare('DELETE FROM `playlists` WHERE `id` = ? AND `user_id` = ?');
            $delete->bind_param('ii', $playlistId, $userId);
            $delete->execute();
            $delete->close();
            $_SESSION['playlist_notice'] = 'Playlist deleted.';
            header('Location: playlists.php');
            exit;
        }
        if ($action === 'add_video') {
            $videoId = trim((string)($_POST['video_id'] ?? ''));
            if ($videoId === '' || strlen($videoId) > 191) {
                throw new RuntimeException('Enter a valid video ID.');
            }
            $exists = $conn->prepare('SELECT 1 FROM `videos` WHERE CAST(`id` AS CHAR) = ? LIMIT 1');
            $exists->bind_param('s', $videoId);
            $exists->execute();
            $hasVideo = (bool)$exists->get_result()->fetch_row();
            $exists->close();
            if (!$hasVideo) {
                throw new RuntimeException('That video could not be found.');
            }
            $add = $conn->prepare('INSERT IGNORE INTO `playlist_videos` (`playlist_id`, `video_id`) VALUES (?, ?)');
            $add->bind_param('is', $playlistId, $videoId);
            $add->execute();
            $added = $add->affected_rows === 1;
            $add->close();
            $_SESSION['playlist_notice'] = $added ? 'Video added to playlist.' : 'That video is already in this playlist.';
            if ((string)($_POST['return_to'] ?? '') === 'save') {
                header('Location: playlists.php?add=' . rawurlencode($videoId));
                exit;
            }
            header('Location: playlists.php?id=' . $playlistId);
            exit;
        }
        if ($action === 'remove_video') {
            $videoId = trim((string)($_POST['video_id'] ?? ''));
            $remove = $conn->prepare('DELETE FROM `playlist_videos` WHERE `playlist_id` = ? AND `video_id` = ?');
            $remove->bind_param('is', $playlistId, $videoId);
            $remove->execute();
            $remove->close();
            $_SESSION['playlist_notice'] = 'Video removed from playlist.';
            if ((string)($_POST['return_to'] ?? '') === 'save') {
                header('Location: playlists.php?add=' . rawurlencode($videoId));
                exit;
            }
            header('Location: playlists.php?id=' . $playlistId);
            exit;
        }
        throw new RuntimeException('That playlist action is not supported.');
    }

    $list = $conn->prepare(
        'SELECT p.`id`, p.`user_id` AS owner_id, p.`name`, p.`visibility`, p.`created_at`, p.`updated_at`, COUNT(pv.`video_id`) AS video_count
         FROM `playlists` p LEFT JOIN `playlist_videos` pv ON pv.`playlist_id` = p.`id`
         WHERE p.`user_id` = ? OR p.`visibility` = \'public\'
         GROUP BY p.`id` ORDER BY p.`updated_at` DESC, p.`id` DESC'
    );
    $list->bind_param('i', $userId);
    $list->execute();
    $playlists = $list->get_result()->fetch_all(MYSQLI_ASSOC);
    $list->close();
    foreach ($playlists as &$playlist) {
        $playlist['is_owned'] = (int)$playlist['owner_id'] === (int)$userId;
        $coverQuery = $conn->prepare(
            'SELECT v.* FROM `playlist_videos` pv
             INNER JOIN `videos` v ON CAST(v.`id` AS CHAR) = pv.`video_id`
             WHERE pv.`playlist_id` = ? ORDER BY pv.`added_at` DESC LIMIT 4'
        );
        $coverQuery->bind_param('i', $playlist['id']);
        $coverQuery->execute();
        $playlist['preview_videos'] = $coverQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $coverQuery->close();
    }
    unset($playlist);
    if ($addVideoId !== '' && $isLoggedIn) {
        $savedQuery = $conn->prepare(
            'SELECT `playlist_id` FROM `playlist_videos`
             WHERE `video_id` = ? AND `playlist_id` IN (SELECT `id` FROM `playlists` WHERE `user_id` = ?)'
        );
        $savedQuery->bind_param('si', $addVideoId, $userId);
        $savedQuery->execute();
        $savedPlaylistIds = array_map('intval', array_column($savedQuery->get_result()->fetch_all(MYSQLI_ASSOC), 'playlist_id'));
        $savedQuery->close();
        foreach ($playlists as &$playlist) {
            $playlist['is_saved'] = in_array((int)$playlist['id'], $savedPlaylistIds, true);
        }
        unset($playlist);
    }
    if ($listScope === 'owned') {
        $playlists = array_values(array_filter($playlists, static fn(array $playlist): bool => $playlist['is_owned']));
    }
    usort($playlists, static function (array $left, array $right) use ($sortBy): int {
        if ($sortBy === 'oldest') {
            return strtotime((string)$left['created_at']) <=> strtotime((string)$right['created_at']);
        }
        if ($sortBy === 'name') {
            return strcasecmp((string)$left['name'], (string)$right['name']);
        }
        if ($sortBy === 'videos') {
            return (int)$right['video_count'] <=> (int)$left['video_count'];
        }
        return strtotime((string)$right['updated_at']) <=> strtotime((string)$left['updated_at']);
    });

    $selectedId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $selected = null;
    $videos = [];
    $canEdit = false;
    if ($selectedId !== false && $selectedId > 0) {
        $find = $conn->prepare('SELECT `id`, `user_id`, `name`, `visibility` FROM `playlists` WHERE `id` = ? AND (`user_id` = ? OR `visibility` = \'public\') LIMIT 1');
        $find->bind_param('ii', $selectedId, $userId);
        $find->execute();
        $selected = $find->get_result()->fetch_assoc() ?: null;
        $find->close();
        if ($selected) {
            $canEdit = (int)$selected['user_id'] === (int)$userId;
            $items = $conn->prepare(
                'SELECT v.* FROM `playlist_videos` pv
                 INNER JOIN `videos` v ON CAST(v.`id` AS CHAR) = pv.`video_id`
                 WHERE pv.`playlist_id` = ? ORDER BY pv.`added_at` DESC'
            );
            $items->bind_param('i', $selectedId);
            $items->execute();
            $videos = $items->get_result()->fetch_all(MYSQLI_ASSOC);
            $items->close();
        }
    }

} catch (Throwable $error) {
    error_log('playlists.php: ' . $error->getMessage());
    $pageError = $error->getMessage();
    $playlists = $playlists ?? [];
    $selected = $selected ?? null;
    $videos = $videos ?? [];
    $canEdit = $canEdit ?? false;
    $addVideoId = $addVideoId ?? '';
    $csrf = (string)($_SESSION['playlist_csrf'] ?? '');
}

$extraCss = <<<'CSS'
.pl-page{width:100%;max-width:1600px;margin:0 auto;padding:28px clamp(20px,3.4vw,52px) 64px}
.pl-page h1,.pl-page h2,.pl-page h3,.pl-page p{margin-top:0}
.pl-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px}
.pl-heading h1{margin:0;font-size:clamp(28px,3vw,38px);letter-spacing:-.035em}
.pl-heading-actions{display:flex;align-items:center;gap:10px}
.pl-button{min-height:42px;display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:0 18px;border:0;border-radius:999px;background:var(--surface-2);color:var(--text);font:inherit;font-weight:650;text-decoration:none;cursor:pointer}
.pl-button:hover,.pl-filter:hover{background:var(--surface-hover,var(--surface-3))}
.pl-button-primary{background:var(--text);color:var(--bg)}
.pl-button-primary:hover{opacity:.88;background:var(--text)}
.pl-button svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round}
.pl-toolbar{display:flex;align-items:center;gap:9px;margin-bottom:20px}
.pl-filter,.pl-filter-link{min-height:38px;display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:0 14px;border:0;border-radius:10px;background:var(--surface-2);color:var(--text);font:inherit;font-size:14px;font-weight:650;text-decoration:none;cursor:pointer}
.pl-filter select{max-width:180px;border:0;outline:0;background:transparent;color:inherit;font:inherit;cursor:pointer}
.pl-filter select option{background:var(--surface);color:var(--text)}
.pl-filter-link[aria-current="page"]{background:var(--text);color:var(--bg)}
.pl-count{margin-left:auto;color:var(--muted);font-size:13px}
.pl-grid{display:grid;grid-template-columns:repeat( auto-fill,minmax(min(100%,245px),1fr));gap:28px 18px}
.pl-card{min-width:0}
.pl-cover-link{position:relative;display:block;overflow:hidden;aspect-ratio:16/9;border-radius:12px;background:#282828;color:#aaa;text-decoration:none}
.pl-cover-grid{position:absolute;inset:0;display:grid;grid-template-columns:1fr 1fr;grid-template-rows:1fr 1fr;gap:2px;background:#171717}
.pl-cover-grid img{width:100%;height:100%;min-width:0;min-height:0;object-fit:cover}
.pl-cover-grid.single{display:block}
.pl-cover-grid.single img{width:100%;height:100%;object-fit:cover}
.pl-cover-empty{position:absolute;inset:0;display:grid;place-items:center;background:#dedede;color:#888}
.pl-cover-empty svg{width:94px;height:70px;fill:currentColor}
.pl-cover-empty svg path{fill:currentColor}
.pl-cover-count{position:absolute;right:8px;bottom:8px;display:inline-flex;align-items:center;gap:5px;padding:5px 8px;border-radius:5px;background:rgba(0,0,0,.75);color:#fff;font-size:12px;font-weight:700}
.pl-cover-count svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2}
.pl-card-details{position:relative;padding:12px 30px 0 0}
.pl-card-title{display:block;overflow:hidden;color:var(--text);font-size:16px;font-weight:700;text-overflow:ellipsis;white-space:nowrap;text-decoration:none}
.pl-card-title:hover,.pl-view-link:hover{text-decoration:underline}
.pl-card-meta,.pl-card-updated{margin:5px 0 0;color:var(--muted);font-size:14px}
.pl-card-meta span+span:before{content:"·";padding:0 7px}
.pl-view-link{display:inline-block;margin-top:7px;color:var(--muted);font-size:14px;font-weight:650;text-decoration:none}
.pl-card-menu{position:absolute;top:5px;right:0}
.pl-card-menu summary{width:32px;height:32px;display:grid;place-items:center;border-radius:50%;color:var(--text);cursor:pointer;list-style:none}
.pl-card-menu summary::-webkit-details-marker{display:none}
.pl-card-menu summary:hover{background:var(--surface-2)}
.pl-card-menu summary svg{width:20px;height:20px;fill:currentColor}
.pl-card-menu form{position:absolute;z-index:2;top:35px;right:0;padding:6px;border:1px solid var(--border);border-radius:10px;background:var(--surface);box-shadow:0 8px 24px #0005}
.pl-card-menu button{padding:8px 12px;border:0;border-radius:7px;background:none;color:var(--text);font:inherit;white-space:nowrap;cursor:pointer}
.pl-card-menu button:hover{background:var(--surface-2)}
.pl-note{padding:12px 14px;border-radius:10px;background:var(--surface-2);color:var(--text)}
.pl-error-banner{margin-bottom:22px}
.pl-error-banner p{margin:8px 0 0;color:var(--muted)}
.pl-error-banner code{overflow-wrap:anywhere}
.pl-empty-state{grid-column:1/-1;padding:56px 18px;border:1px dashed var(--border);border-radius:16px;color:var(--muted);text-align:center}
.pl-empty-state h2{margin-bottom:8px;color:var(--text)}
.pl-detail{max-width:1050px}
.pl-back{display:inline-block;margin-bottom:20px;color:var(--muted);font-weight:650;text-decoration:none}
.pl-detail-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:24px}
.pl-detail-heading h1{margin-bottom:8px;font-size:clamp(28px,3vw,38px)}
.pl-detail-heading p{margin-bottom:0;color:var(--muted)}
.pl-video{display:grid;grid-template-columns:minmax(120px,220px) minmax(0,1fr) auto;gap:16px;align-items:center;padding:14px 0;border-bottom:1px solid var(--border)}
.pl-thumb{width:100%;aspect-ratio:16/9;object-fit:cover;border-radius:10px;background:var(--surface-2)}
.pl-video h3{margin:0 0 6px;font-size:16px}.pl-video p{margin:0;color:var(--muted);font-size:13px}
.pl-empty{padding:42px 16px;border:1px dashed var(--border);border-radius:12px;color:var(--muted);text-align:center}
.pl-dialog{width:min(440px,calc(100vw - 28px));max-width:none;max-height:calc(100dvh - 32px);padding:0;overflow:auto;border:1px solid var(--border);border-radius:18px;background:var(--surface);color:var(--text);box-shadow:0 22px 80px #0008}
.pl-dialog::backdrop{background:rgba(0,0,0,.66);backdrop-filter:blur(2px)}
.pl-dialog-inner{padding:22px 24px 20px}
.pl-dialog h2{margin:0 0 20px;font-size:23px}
.pl-dialog label{display:block;margin:0 0 7px;color:var(--muted);font-size:14px}
.pl-field{width:100%;min-height:58px;margin-bottom:14px;padding:12px 15px;border:1px solid var(--border-strong,var(--border));border-radius:11px;background:transparent;color:var(--text);font:inherit}
.pl-field::placeholder{color:var(--muted)}
.pl-dialog-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}
.pl-button-outline{border:1px solid var(--border-strong,var(--border));background:transparent}
.pl-create-submit:disabled{opacity:.4;cursor:not-allowed}
.pl-save-list{max-height:min(48vh,400px);overflow:auto}
.pl-save-row{display:flex;align-items:center;gap:14px;width:100%;padding:9px 0;border:0;border-bottom:1px solid var(--border);background:none;color:var(--text);text-align:left;cursor:pointer}
.pl-save-row:hover .pl-save-name{text-decoration:underline}
.pl-save-cover{width:66px;height:48px;flex:0 0 66px;overflow:hidden;border-radius:7px;background:var(--surface-2)}
.pl-save-cover img{width:100%;height:100%;object-fit:cover}
.pl-save-copy{display:grid;gap:4px;min-width:0}
.pl-save-name{overflow:hidden;font-weight:650;text-overflow:ellipsis;white-space:nowrap}
.pl-save-visibility{color:var(--muted);font-size:13px}
.pl-save-check{margin-left:auto;width:22px;height:22px;flex:0 0 22px;color:var(--text)}
.pl-save-check svg{width:100%;height:100%;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.pl-save-check.is-saved svg{fill:currentColor}
.pl-save-new{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:48px;margin-top:14px;border:1px solid var(--border);border-radius:999px;background:var(--surface-2);color:var(--text);font:inherit;font-weight:700;cursor:pointer}
.pl-save-empty{padding:18px 0;color:var(--muted);text-align:center}
.pl-liked-layout{display:grid;grid-template-columns:minmax(290px,360px) minmax(0,1fr);gap:28px;align-items:start}
.pl-liked-hero{position:sticky;top:calc(var(--navbar-height,72px) + 18px);min-height:520px;display:flex;flex-direction:column;justify-content:flex-end;overflow:hidden;padding:24px;border-radius:18px;background:linear-gradient(180deg,#565b62,#25272b 58%,#171719);color:#fff}
.pl-liked-hero>img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;filter:blur(22px);opacity:.42;transform:scale(1.12)}
.pl-liked-hero:after{position:absolute;inset:0;background:linear-gradient(180deg,rgba(15,15,15,.08) 0%,rgba(15,15,15,.42) 42%,rgba(15,15,15,.96) 100%);content:""}
.pl-liked-hero-content{position:relative;z-index:1}
.pl-liked-cover{display:block;width:min(100%,190px);height:270px;margin:0 auto 18px;border-radius:12px;object-fit:cover;box-shadow:0 12px 34px #0008}
.pl-liked-placeholder{display:grid;place-items:center;background:linear-gradient(135deg,#5c626b,#27292c)}
.pl-liked-placeholder svg{width:76px;height:76px;fill:none;stroke:#fff;stroke-width:1.5}
.pl-liked-hero h1{margin:0 0 12px;font-size:27px;letter-spacing:-.03em}
.pl-liked-owner{margin:0 0 6px;font-size:14px;font-weight:700}
.pl-liked-meta{margin:0;color:#d0d0d0;font-size:13px}
.pl-liked-actions{display:flex;gap:9px;margin-top:20px}
.pl-liked-actions .pl-button{flex:1}
.pl-liked-play{background:#fff;color:#101010}
.pl-liked-shuffle{background:rgba(255,255,255,.16);color:#fff}
.pl-liked-list{min-width:0}
.pl-liked-notice{margin:0 0 16px;padding:16px 20px;background:var(--surface-2);color:var(--text)}
.pl-liked-toolbar{display:flex;gap:8px;margin-bottom:14px}
.pl-liked-toolbar .pl-filter-link{min-height:34px;padding:0 13px}
.pl-liked-toolbar .pl-filter-link[aria-current=page]{background:var(--text);color:var(--bg)}
.pl-liked-row{display:grid;grid-template-columns:minmax(150px,200px) minmax(0,1fr) 38px;gap:12px;align-items:start;padding:0 0 14px;margin-bottom:14px;border-bottom:1px solid var(--border)}
.pl-liked-thumb-link{position:relative;display:block;overflow:hidden;aspect-ratio:16/9;border-radius:9px;background:var(--surface-2)}
.pl-liked-thumb{width:100%;height:100%;object-fit:cover}
.pl-liked-duration{position:absolute;right:5px;bottom:5px;padding:2px 5px;border-radius:4px;background:#000c;color:#fff;font-size:12px}
.pl-liked-copy{min-width:0}
.pl-liked-title{display:block;display:-webkit-box;overflow:hidden;color:var(--text);font-size:15px;font-weight:700;line-height:1.35;text-decoration:none;-webkit-box-orient:vertical;-webkit-line-clamp:2}
.pl-liked-channel{margin:6px 0 0;color:var(--muted);font-size:13px}
.pl-liked-stats{margin:4px 0 0;color:var(--muted);font-size:12px}
.pl-liked-open{width:36px;height:36px;display:grid;place-items:center;border:0;border-radius:50%;background:transparent;color:var(--text);text-decoration:none}
.pl-liked-open:hover{background:var(--surface-2)}
.pl-liked-open svg{width:20px;height:20px;fill:currentColor}
.pl-liked-empty{padding:44px 18px;border:1px dashed var(--border);border-radius:14px;color:var(--muted);text-align:center}
.pl-error-liked{margin-top:20px}
@media(max-width:760px){.pl-page{padding:22px 16px 48px}.pl-heading{align-items:flex-start}.pl-heading h1{font-size:30px}.pl-toolbar{flex-wrap:wrap}.pl-count{width:100%;margin:2px 0 0}.pl-grid{grid-template-columns:repeat(auto-fill,minmax(min(100%,210px),1fr));gap:24px 14px}.pl-video{grid-template-columns:120px minmax(0,1fr)}.pl-video form{grid-column:2}.pl-detail-heading{display:block}.pl-detail-heading form{margin-top:14px}}
@media(max-width:480px){.pl-page{padding:20px 12px 40px}.pl-heading{align-items:center}.pl-heading h1{font-size:27px}.pl-heading-actions .pl-button{min-height:40px;padding:0 13px}.pl-grid{grid-template-columns:1fr;gap:24px}.pl-cover-link{aspect-ratio:16/9}.pl-dialog-inner{padding:20px}.pl-video{grid-template-columns:100px minmax(0,1fr);gap:11px}.pl-video form{grid-column:2}}
@media(max-width:1000px){.pl-liked-layout{grid-template-columns:minmax(250px,320px) minmax(0,1fr);gap:20px}.pl-liked-hero{min-height:460px}}
@media(max-width:760px){.pl-liked-layout{grid-template-columns:1fr}.pl-liked-hero{position:relative;top:auto;min-height:0;padding:20px}.pl-liked-cover{width:min(58%,190px);height:220px}.pl-liked-row{grid-template-columns:minmax(110px,160px) minmax(0,1fr) 32px}}
@media(max-width:480px){.pl-liked-row{grid-template-columns:112px minmax(0,1fr) 28px;gap:9px}.pl-liked-title{font-size:14px}.pl-liked-open{width:28px;height:30px}}
CSS;

vs_page_start($showLiked ? 'Liked videos' : 'Playlists', $showLiked ? 'liked' : 'playlists', $extraCss);
?>
<main class="pl-page">
    <?php if ($pageError !== '' && !$showLiked): ?>
        <div class="pl-panel pl-error-banner" role="alert">
            <strong>Playlists are unavailable.</strong>
            <p><?= htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') ?></p>
            <?php if (stripos($pageError, 'playlist') !== false): ?>
                <p>Run <code>database/migrations/20261016_create_playlists.sql</code> in your VibeStream database to enable playlists.</p>
            <?php endif; ?>
        </div>
        <section class="pl-grid pl-error-liked" aria-label="Your liked videos">
            <article class="pl-card">
                <a class="pl-cover-link" href="playlists.php?list=liked" aria-label="View liked videos">
                    <?php if ($likedVideos):
                        $likedCoverSource = find_source($likedVideos[0], __DIR__);
                        $likedCoverUrl = thumb_for($likedVideos[0], $likedCoverSource, $likedCoverSource !== '' ? extract_youtube_id($likedCoverSource, true) : null);
                    ?>
                        <span class="pl-cover-grid single"><img src="<?= htmlspecialchars($likedCoverUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"></span>
                    <?php else: ?>
                        <span class="pl-cover-empty" aria-hidden="true"><svg viewBox="0 0 100 72"><path d="M8 15a7 7 0 0 1 7-7h70a7 7 0 0 1 7 7v42a7 7 0 0 1-7 7H15a7 7 0 0 1-7-7V15Zm22 21a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm20 0a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm20 0a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z"/></svg></span>
                    <?php endif; ?>
                    <span class="pl-cover-count"><?= count($likedVideos) ?> <?= count($likedVideos) === 1 ? 'video' : 'videos' ?></span>
                </a>
                <div class="pl-card-details">
                    <a class="pl-card-title" href="playlists.php?list=liked">Liked videos</a>
                    <p class="pl-card-meta"><span>Private</span><span>Playlist</span></p>
                    <p class="pl-card-updated">Updated today</p>
                    <a class="pl-view-link" href="playlists.php?list=liked">View full playlist</a>
                </div>
            </article>
        </section>
    <?php elseif ($showLiked): ?>
        <?php if ($pageError !== ''): ?>
            <div class="pl-panel pl-error-banner" role="alert">
                <strong>Playlist creation is unavailable.</strong>
                <p><?= htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        <?php endif; ?>
        <?php
        $likedCount = count($likedVideos);
        $likedCoverSource = $likedVideos ? find_source($likedVideos[0], __DIR__) : '';
        $likedCoverUrl = $likedVideos
            ? thumb_for($likedVideos[0], $likedCoverSource, $likedCoverSource !== '' ? extract_youtube_id($likedCoverSource, true) : null)
            : '';
        $firstLikedId = $likedVideos ? (string)$likedVideos[0]['id'] : '';
        $firstLikedKind = $likedVideos && in_array(strtolower((string)($likedVideos[0]['type'] ?? '')), ['short', 'shorts'], true) ? 'short' : 'video';
        ?>
        <a class="pl-back" href="playlists.php">&larr; Playlists</a>
        <section class="pl-liked-layout">
            <aside class="pl-liked-hero">
                <?php if ($likedCoverUrl !== ''): ?><img src="<?= htmlspecialchars($likedCoverUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" aria-hidden="true"><?php endif; ?>
                <div class="pl-liked-hero-content">
                    <?php if ($likedCoverUrl !== ''): ?>
                        <img class="pl-liked-cover" src="<?= htmlspecialchars($likedCoverUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                    <?php else: ?>
                        <span class="pl-liked-cover pl-liked-placeholder" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21l7.8-7.5 1.1-1.1a5.5 5.5 0 0 0-.1-7.8Z"/></svg></span>
                    <?php endif; ?>
                    <h1>Liked videos</h1>
                    <p class="pl-liked-owner"><?= htmlspecialchars($username !== '' ? $username : 'You', ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="pl-liked-meta"><?= $likedCount ?> <?= $likedCount === 1 ? 'video' : 'videos' ?> · Private · Updated today</p>
                    <div class="pl-liked-actions">
                        <?php if ($firstLikedId !== ''): ?>
                            <a class="pl-button pl-liked-play" href="<?= $firstLikedKind === 'short' ? 'shorts.php?id=' : 'watch.php?id=' ?><?= rawurlencode($firstLikedId) ?>">
                                <svg viewBox="0 0 24 24"><path d="m8 5 12 7-12 7z" fill="currentColor" stroke="none"/></svg>
                                Play all
                            </a>
                            <button class="pl-button pl-liked-shuffle" type="button" data-pl-shuffle>
                                <svg viewBox="0 0 24 24"><path d="M16 3h5v5M4 20 21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></svg>
                                Shuffle
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </aside>
            <section class="pl-liked-list" aria-label="Liked videos">
                <?php if (count($likedVideoIds) > $likedCount): ?>
                    <div class="pl-liked-notice" role="status">Unavailable videos are hidden</div>
                <?php endif; ?>
                <nav class="pl-liked-toolbar" aria-label="Filter liked videos">
                    <button class="pl-filter-link" type="button" data-pl-kind="all" aria-current="page">All</button>
                    <button class="pl-filter-link" type="button" data-pl-kind="video">Videos</button>
                    <button class="pl-filter-link" type="button" data-pl-kind="short">Shorts</button>
                </nav>
                <?php if ($likedVideos): ?>
                    <?php foreach ($likedVideos as $video):
                        $source = find_source($video, __DIR__);
                        $youtubeId = $source !== '' ? extract_youtube_id($source, true) : null;
                        $thumbnail = thumb_for($video, $source, $youtubeId);
                        $videoId = (string)$video['id'];
                        $kind = in_array(strtolower((string)($video['type'] ?? '')), ['short', 'shorts'], true) ? 'short' : 'video';
                        $creator = trim((string)($video['username'] ?? $video['channel_name'] ?? ''));
                        $videoHref = ($kind === 'short' ? 'shorts.php?id=' : 'watch.php?id=') . rawurlencode($videoId);
                    ?>
                        <article class="pl-liked-row" data-pl-liked-row data-kind="<?= $kind ?>" data-video-url="<?= htmlspecialchars($videoHref, ENT_QUOTES, 'UTF-8') ?>">
                            <a class="pl-liked-thumb-link" href="<?= htmlspecialchars($videoHref, ENT_QUOTES, 'UTF-8') ?>">
                                <img class="pl-liked-thumb" src="<?= htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy">
                                <?php if (!empty($video['duration'])): ?><span class="pl-liked-duration"><?= htmlspecialchars((string)$video['duration'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                            </a>
                            <div class="pl-liked-copy">
                                <a class="pl-liked-title" href="<?= htmlspecialchars($videoHref, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($video['title'] ?? 'Untitled'), ENT_QUOTES, 'UTF-8') ?></a>
                                <p class="pl-liked-channel"><?= htmlspecialchars($creator, ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="pl-liked-stats"><?= number_format((int)($video['views'] ?? 0)) ?> views<?php if (!empty($video['created_at'])): ?> · <?= htmlspecialchars(date('M j, Y', strtotime((string)$video['created_at']) ?: time()), ENT_QUOTES, 'UTF-8') ?><?php endif; ?></p>
                            </div>
                            <a class="pl-liked-open" href="<?= htmlspecialchars($videoHref, ENT_QUOTES, 'UTF-8') ?>" aria-label="Open video">
                                <svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                            </a>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="pl-liked-empty"><h2>No liked videos yet</h2><p>Videos you like will be collected here.</p></div>
                <?php endif; ?>
            </section>
        </section>
    <?php else: ?>
        <?php if ($notice !== ''): ?><p class="pl-note" role="status"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

        <?php if ($selected && $addVideoId === ''): ?>
            <section class="pl-detail">
                <a class="pl-back" href="playlists.php">&larr; Playlists</a>
                <header class="pl-detail-heading">
                    <div>
                        <h1><?= htmlspecialchars((string)$selected['name'], ENT_QUOTES, 'UTF-8') ?></h1>
                        <p><?= htmlspecialchars(ucfirst((string)$selected['visibility']), ENT_QUOTES, 'UTF-8') ?> playlist · <?= count($videos) ?> videos</p>
                    </div>
                    <?php if ($canEdit): ?>
                        <form method="post" onsubmit="return confirm('Delete this playlist?');">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="playlist_id" value="<?= (int)$selected['id'] ?>">
                            <button class="pl-button" type="submit">Delete playlist</button>
                        </form>
                    <?php endif; ?>
                </header>
                <?php if ($canEdit): ?><p>To add videos, open a video's watch page and choose <strong>Add to playlist</strong>.</p><?php endif; ?>
                <?php if ($videos): ?>
                    <?php foreach ($videos as $video):
                        $source = find_source($video, __DIR__);
                        $youtubeId = $source !== '' ? extract_youtube_id($source, true) : null;
                        $thumbnail = thumb_for($video, $source, $youtubeId);
                        $videoId = (string)$video['id'];
                        $creator = trim((string)($video['username'] ?? $video['channel_name'] ?? ''));
                    ?>
                        <article class="pl-video">
                            <a href="watch.php?id=<?= rawurlencode($videoId) ?>">
                                <img class="pl-thumb" src="<?= htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy">
                            </a>
                            <div>
                                <h3><a href="watch.php?id=<?= rawurlencode($videoId) ?>"><?= htmlspecialchars((string)($video['title'] ?? 'Untitled'), ENT_QUOTES, 'UTF-8') ?></a></h3>
                                <p><?= htmlspecialchars($creator, ENT_QUOTES, 'UTF-8') ?> · <?= number_format((int)($video['views'] ?? 0)) ?> views</p>
                            </div>
                            <?php if ($canEdit): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="remove_video">
                                    <input type="hidden" name="playlist_id" value="<?= (int)$selected['id'] ?>">
                                    <input type="hidden" name="video_id" value="<?= htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8') ?>">
                                    <button class="pl-button" type="submit">Remove</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="pl-empty">This playlist is empty. Add a video from its watch page using “Add to playlist”.</p>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <header class="pl-heading">
                <h1>Playlists</h1>
                <div class="pl-heading-actions">
                    <?php if ($isLoggedIn): ?>
                        <button class="pl-button pl-button-primary" type="button" data-pl-open-create>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                            New playlist
                        </button>
                    <?php else: ?>
                        <a class="pl-button pl-button-primary" href="login.php?redirect=playlists.php">Sign in</a>
                    <?php endif; ?>
                </div>
            </header>
            <nav class="pl-toolbar" aria-label="Playlist filters">
                <label class="pl-filter">
                    <select aria-label="Sort playlists" data-pl-sort>
                        <option value="recent"<?= $sortBy === 'recent' ? ' selected' : '' ?>>Recently added</option>
                        <option value="oldest"<?= $sortBy === 'oldest' ? ' selected' : '' ?>>Oldest</option>
                        <option value="name"<?= $sortBy === 'name' ? ' selected' : '' ?>>Name</option>
                        <option value="videos"<?= $sortBy === 'videos' ? ' selected' : '' ?>>Most videos</option>
                    </select>
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="m7 10 5 5 5-5" fill="none" stroke="currentColor" stroke-width="2"/></svg>
                </label>
                <a class="pl-filter-link" href="playlists.php?view=all&amp;sort=<?= rawurlencode($sortBy) ?>"<?= $listScope === 'all' ? ' aria-current="page"' : '' ?>>Playlists</a>
                <?php if ($isLoggedIn): ?>
                    <a class="pl-filter-link" href="playlists.php?view=owned&amp;sort=<?= rawurlencode($sortBy) ?>"<?= $listScope === 'owned' ? ' aria-current="page"' : '' ?>>Owned</a>
                <?php endif; ?>
                <span class="pl-count"><?= count($playlists) ?> <?= count($playlists) === 1 ? 'playlist' : 'playlists' ?></span>
            </nav>
            <?php if ($selectedId !== false && $selectedId > 0 && !$selected): ?>
                <p class="pl-note" role="status">This playlist is private or does not exist.</p>
            <?php endif; ?>
            <section class="pl-grid" aria-label="Playlists">
                    <article class="pl-card">
                        <a class="pl-cover-link" href="playlists.php?list=liked" aria-label="View liked videos">
                            <?php if ($likedVideos):
                                $likedCoverSource = find_source($likedVideos[0], __DIR__);
                                $likedCoverUrl = thumb_for($likedVideos[0], $likedCoverSource, $likedCoverSource !== '' ? extract_youtube_id($likedCoverSource, true) : null);
                            ?>
                                <span class="pl-cover-grid single"><img src="<?= htmlspecialchars($likedCoverUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"></span>
                            <?php else: ?>
                                <span class="pl-cover-empty" aria-hidden="true"><svg viewBox="0 0 100 72"><path d="M8 15a7 7 0 0 1 7-7h70a7 7 0 0 1 7 7v42a7 7 0 0 1-7 7H15a7 7 0 0 1-7-7V15Zm22 21a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm20 0a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm20 0a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z"/></svg></span>
                            <?php endif; ?>
                            <span class="pl-cover-count">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h12M4 17h8"/><path d="m16 15 5 3-5 3z"/></svg>
                                <?= count($likedVideos) ?> <?= count($likedVideos) === 1 ? 'video' : 'videos' ?>
                            </span>
                        </a>
                        <div class="pl-card-details">
                            <a class="pl-card-title" href="playlists.php?list=liked">Liked videos</a>
                            <p class="pl-card-meta"><span>Private</span><span>Playlist</span></p>
                            <p class="pl-card-updated">Updated today</p>
                            <a class="pl-view-link" href="playlists.php?list=liked">View full playlist</a>
                        </div>
                    </article>
                    <?php foreach ($playlists as $playlist):
                        $previewVideos = $playlist['preview_videos'] ?? [];
                        $updatedTime = strtotime((string)($playlist['updated_at'] ?? $playlist['created_at']));
                        $elapsedDays = $updatedTime !== false ? (int)floor((time() - $updatedTime) / 86400) : 0;
                        $updatedLabel = $elapsedDays < 1 ? 'Updated today' : ($elapsedDays === 1 ? 'Updated yesterday' : 'Updated ' . $elapsedDays . ' days ago');
                    ?>
                        <article class="pl-card" data-pl-card data-owned="<?= !empty($playlist['is_owned']) ? '1' : '0' ?>" data-updated="<?= (int)($updatedTime ?: 0) ?>" data-created="<?= (int)(strtotime((string)$playlist['created_at']) ?: 0) ?>" data-name="<?= htmlspecialchars(mb_strtolower((string)$playlist['name']), ENT_QUOTES, 'UTF-8') ?>" data-videos="<?= (int)$playlist['video_count'] ?>">
                            <a class="pl-cover-link" href="playlists.php?id=<?= (int)$playlist['id'] ?>" aria-label="View playlist <?= htmlspecialchars((string)$playlist['name'], ENT_QUOTES, 'UTF-8') ?>">
                                <?php if ($previewVideos): ?>
                                    <span class="pl-cover-grid<?= count($previewVideos) === 1 ? ' single' : '' ?>">
                                        <?php foreach ($previewVideos as $previewVideo):
                                            $previewSource = find_source($previewVideo, __DIR__);
                                            $previewYoutubeId = $previewSource !== '' ? extract_youtube_id($previewSource, true) : null;
                                            $previewThumbnail = thumb_for($previewVideo, $previewSource, $previewYoutubeId);
                                        ?>
                                            <img src="<?= htmlspecialchars($previewThumbnail, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy">
                                        <?php endforeach; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="pl-cover-empty" aria-hidden="true">
                                        <svg viewBox="0 0 100 72"><path d="M8 15a7 7 0 0 1 7-7h70a7 7 0 0 1 7 7v42a7 7 0 0 1-7 7H15a7 7 0 0 1-7-7V15Zm22 21a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm20 0a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm20 0a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z"/></svg>
                                    </span>
                                <?php endif; ?>
                                <span class="pl-cover-count">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h12M4 17h8"/><path d="m16 15 5 3-5 3z"/></svg>
                                    <?= (int)$playlist['video_count'] ?> <?= (int)$playlist['video_count'] === 1 ? 'video' : 'videos' ?>
                                </span>
                            </a>
                            <div class="pl-card-details">
                                <a class="pl-card-title" href="playlists.php?id=<?= (int)$playlist['id'] ?>"><?= htmlspecialchars((string)$playlist['name'], ENT_QUOTES, 'UTF-8') ?></a>
                                <p class="pl-card-meta"><span><?= htmlspecialchars(ucfirst((string)$playlist['visibility']), ENT_QUOTES, 'UTF-8') ?></span><span>Playlist</span></p>
                                <p class="pl-card-updated"><?= htmlspecialchars($updatedLabel, ENT_QUOTES, 'UTF-8') ?></p>
                                <a class="pl-view-link" href="playlists.php?id=<?= (int)$playlist['id'] ?>">View full playlist</a>
                                <?php if (!empty($playlist['is_owned'])): ?>
                                    <details class="pl-card-menu">
                                        <summary aria-label="Playlist options">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                                        </summary>
                                        <form method="post" onsubmit="return confirm('Delete this playlist?');">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="playlist_id" value="<?= (int)$playlist['id'] ?>">
                                            <button type="submit">Delete playlist</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?php if ($pageError === '' && $isLoggedIn): ?>
    <dialog class="pl-dialog" id="pl-create-dialog" aria-labelledby="pl-create-title">
        <form class="pl-dialog-inner" method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create">
            <?php if ($addVideoId !== ''): ?>
                <input type="hidden" name="return_video_id" value="<?= htmlspecialchars($addVideoId, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="return_to" value="save">
            <?php endif; ?>
            <h2 id="pl-create-title">New playlist</h2>
            <label for="playlist-name">Title</label>
            <input class="pl-field" id="playlist-name" name="name" maxlength="100" placeholder="Choose a title" required autocomplete="off">
            <label for="playlist-visibility">Visibility</label>
            <select class="pl-field" id="playlist-visibility" name="visibility">
                <option value="private">Private</option>
                <option value="public">Public</option>
            </select>
            <div class="pl-dialog-actions">
                <button class="pl-button pl-button-outline" type="button" data-pl-close>Cancel</button>
                <button class="pl-button pl-button-primary pl-create-submit" type="submit" disabled>Create</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($pageError === '' && $addVideoId !== ''): ?>
    <dialog class="pl-dialog" id="pl-save-dialog" aria-labelledby="pl-save-title">
        <div class="pl-dialog-inner">
            <h2 id="pl-save-title">Save to...</h2>
            <div class="pl-save-list">
                <?php
                $ownedPlaylists = array_values(array_filter($playlists, static fn(array $playlist): bool => !empty($playlist['is_owned'])));
                foreach ($ownedPlaylists as $playlist):
                    $saved = !empty($playlist['is_saved']);
                    $cover = $playlist['preview_videos'][0] ?? null;
                    $coverUrl = '';
                    if ($cover) {
                        $coverSource = find_source($cover, __DIR__);
                        $coverUrl = thumb_for($cover, $coverSource, $coverSource !== '' ? extract_youtube_id($coverSource, true) : null);
                    }
                ?>
                    <form method="post" class="pl-save-form">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="<?= $saved ? 'remove_video' : 'add_video' ?>">
                        <input type="hidden" name="playlist_id" value="<?= (int)$playlist['id'] ?>">
                        <input type="hidden" name="video_id" value="<?= htmlspecialchars($addVideoId, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="return_to" value="save">
                        <button class="pl-save-row" type="submit" aria-label="<?= $saved ? 'Remove saved video from ' : 'Save video to ' ?><?= htmlspecialchars((string)$playlist['name'], ENT_QUOTES, 'UTF-8') ?>">
                            <span class="pl-save-cover"><?php if ($coverUrl !== ''): ?><img src="<?= htmlspecialchars($coverUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"><?php endif; ?></span>
                            <span class="pl-save-copy">
                                <span class="pl-save-name"><?= htmlspecialchars((string)$playlist['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="pl-save-visibility"><?= htmlspecialchars(ucfirst((string)$playlist['visibility']), ENT_QUOTES, 'UTF-8') ?></span>
                            </span>
                            <span class="pl-save-check<?= $saved ? ' is-saved' : '' ?>" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M6 3.5h12a1 1 0 0 1 1 1v16l-7-4.5-7 4.5v-16a1 1 0 0 1 1-1z"/></svg>
                            </span>
                        </button>
                    </form>
                <?php endforeach; ?>
                <?php if (!$ownedPlaylists): ?>
                    <p class="pl-save-empty"><?= $isLoggedIn ? 'Create a playlist to save this video.' : 'Sign in to save this video to a playlist.' ?></p>
                <?php endif; ?>
            </div>
            <?php if ($isLoggedIn): ?>
                <button class="pl-save-new" type="button" data-pl-open-create>
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2"/></svg>
                    New playlist
                </button>
            <?php else: ?>
                <a class="pl-save-new" href="login.php?redirect=playlists.php%3Fadd%3D<?= rawurlencode($addVideoId) ?>">Sign in to save</a>
            <?php endif; ?>
        </div>
    </dialog>
<?php endif; ?>

<script>
    (function () {
        var createDialog = document.getElementById('pl-create-dialog');
        var saveDialog = document.getElementById('pl-save-dialog');
        document.querySelectorAll('[data-pl-open-create]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (saveDialog && saveDialog.open) saveDialog.close();
                if (createDialog && !createDialog.open) createDialog.showModal();
                var title = document.getElementById('playlist-name');
                if (title) title.focus();
            });
        });
        document.querySelectorAll('[data-pl-close]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (createDialog && createDialog.open) createDialog.close();
            });
        });
        if (createDialog) {
            var titleInput = document.getElementById('playlist-name');
            var createButton = createDialog.querySelector('.pl-create-submit');
            titleInput.addEventListener('input', function () {
                createButton.disabled = titleInput.value.trim().length === 0;
            });
            createDialog.addEventListener('click', function (event) {
                if (event.target === createDialog) createDialog.close();
            });
        }
        if (saveDialog) {
            saveDialog.showModal();
            saveDialog.addEventListener('click', function (event) {
                if (event.target === saveDialog) saveDialog.close();
            });
        }
        var sortSelect = document.querySelector('[data-pl-sort]');
        if (sortSelect) {
            sortSelect.addEventListener('change', function () {
                var url = new URL(window.location.href);
                url.searchParams.set('sort', sortSelect.value);
                window.location.assign(url.toString());
            });
        }
        var kindButtons = document.querySelectorAll('[data-pl-kind]');
        kindButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var kind = button.getAttribute('data-pl-kind');
                kindButtons.forEach(function (item) { item.removeAttribute('aria-current'); });
                button.setAttribute('aria-current', 'page');
                document.querySelectorAll('[data-pl-liked-row]').forEach(function (row) {
                    row.hidden = kind !== 'all' && row.getAttribute('data-kind') !== kind;
                });
            });
        });
        var shuffleButton = document.querySelector('[data-pl-shuffle]');
        if (shuffleButton) {
            shuffleButton.addEventListener('click', function () {
                var rows = Array.from(document.querySelectorAll('[data-pl-liked-row]'));
                if (rows.length) window.location.assign(rows[Math.floor(Math.random() * rows.length)].getAttribute('data-video-url'));
            });
        }
        document.querySelectorAll('.pl-card-menu').forEach(function (menu) {
            document.addEventListener('click', function (event) {
                if (!menu.contains(event.target)) menu.open = false;
            });
        });
    })();
</script>
<?php vs_page_end(); ?>
