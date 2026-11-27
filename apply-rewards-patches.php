<?php
/**
 * VibeStream rewards: applies the small edits to YOUR existing files in one go.
 *
 *   php apply-rewards-patches.php            (run inside the VibeStream folder)
 *   php apply-rewards-patches.php C:\xampp\htdocs\vibestream
 *   php apply-rewards-patches.php --dry      (only shows what it would change)
 *
 * Edits: watch.php + shorts.php (daily quests and the "+N points" pop-up),
 *        comment-store.php + profile.php + index.php + user-menu.php (level badges).
 * Safe to run again: finished edits are skipped, and an older version of the quest edits in watch.php is upgraded.
 * Each changed file is first copied to <name>.bak-rewards.
 * Run it AFTER copying premium-store.php and quest-toast.js into the folder.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line: php apply-rewards-patches.php');
}
$dry = in_array('--dry', $argv, true);
$dir = __DIR__;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg !== '--dry' && is_dir($arg)) {
        $dir = rtrim($arg, '/\\');
    }
}

$REQ = <<<'T'
if (is_file(__DIR__ . '/premium-store.php')) { require_once __DIR__ . '/premium-store.php'; }
T;
$REQ_MARK = "require_once __DIR__ . '/premium-store.php';";

/* ---------- shared pieces ---------- */
$like_old = <<<'T'
if (function_exists('vs_rewards_quest_try')) { vs_rewards_quest_try('like', (string)$vid, $v); }
T;
$like_new = <<<'T'
if (function_exists('vs_rewards_quest_try')) { $questPoints = vs_rewards_quest_try('like', (string)$vid, $v); }
T;
$like_anchor = <<<'T'
vs_notify_video_event($conn, $v, 'like', $actor);
T;
$cm_old = <<<'T'
if (function_exists('vs_rewards_quest_try') && mb_strlen($text) >= 10) { vs_rewards_quest_try('comment', (string)$newCommentId, $v); }
T;
$cm_new = <<<'T'
$questPointsC = (function_exists('vs_rewards_quest_try') && mb_strlen($text) >= 10) ? vs_rewards_quest_try('comment', (string)$newCommentId, $v) : 0;
T;
$cm_anchor = <<<'T'
vs_notify_comment_reply($conn, $v, (string)$user, $text, $newCommentId);
T;
$cm_json_anchor = <<<'T'
'html' => vs_comment_new_item_html($newCommentId, (string)$user, $text),
T;
$cm_json_new = <<<'T'
'html' => vs_comment_new_item_html($newCommentId, (string)$user, $text),
                'quest_points' => (int)($questPointsC ?? 0),
T;
$pg_old = <<<'T'
if (function_exists('vs_rewards_quest_try') && $position >= 30 && $duration >= 30) { vs_rewards_quest_try('watch', (string)$vid, $v); }
T;
$pg_new = <<<'T'
$questPointsW = (function_exists('vs_rewards_quest_try') && $position >= 30 && $duration >= 30) ? vs_rewards_quest_try('watch', (string)$vid, $v) : 0;
T;
$pg_anchor = <<<'T'
vs_history_save_progress($conn, $vid, $position, $duration);
T;
$pg_anchor_new = <<<'T'
$questPointsW = (function_exists('vs_rewards_quest_try') && $position >= 30 && $duration >= 30) ? vs_rewards_quest_try('watch', (string)$vid, $v) : 0;
            vs_history_save_progress($conn, $vid, $position, $duration);
T;
$pg_json_old = <<<'T'
json_out(['success' => true]);
T;
$pg_json_new = <<<'T'
json_out(['success' => true, 'quest_points' => (int)($questPointsW ?? 0)]);
T;
$like_json_anchor = <<<'T'
'disliked' => in_array($vid, $_SESSION['disliked_videos'], true),
T;
$like_json_new = <<<'T'
'disliked' => in_array($vid, $_SESSION['disliked_videos'], true),
                'quest_points' => (int)($questPoints ?? 0),
T;
$script_watch_old = <<<'T'
<script src="video-menu.js?v=4"></script>
T;
$script_watch_new = <<<'T'
<script src="video-menu.js?v=4"></script>
    <script src="quest-toast.js?v=1"></script>
T;
$script_short_old = <<<'T'
<script src="creator-analytics-tracking.js?v=1"></script>
T;
$script_short_new = <<<'T'
<script src="creator-analytics-tracking.js?v=1"></script>
    <script src="quest-toast.js?v=1"></script>
T;

// shorts.php
$s_like_new = <<<'T'
vs_notify_video_event($conn, $v, 'like', $actor);
                    $questPoints = function_exists('vs_rewards_quest_try') ? vs_rewards_quest_try('like', (string)$vid, $v) : 0;
T;
$s_like_json_old = <<<'T'
echo json_encode(['success' => true, 'liked' => !$liked, 'likes' => $likes]);
T;
$s_like_json_new = <<<'T'
echo json_encode(['success' => true, 'liked' => !$liked, 'likes' => $likes, 'quest_points' => (int)($questPoints ?? 0)]);
T;
$s_cm_new = <<<'T'
vs_notify_comment_reply($conn, $v, (string)$user, $text, $newCommentId);
            $questPointsC = (function_exists('vs_rewards_quest_try') && mb_strlen($text) >= 10) ? vs_rewards_quest_try('comment', (string)$newCommentId, $v) : 0;
T;
$s_action_anchor = <<<'T'
$commentResult = vs_comments_handle_action($conn, (string)$action, (string)$vid);
T;
$s_action_new = <<<'T'
if ($action === 'quest_watch') {
            $questPointsW = function_exists('vs_rewards_quest_try') ? vs_rewards_quest_try('watch', (string)$vid, $v) : 0;
            echo json_encode(['success' => true, 'quest_points' => (int)$questPointsW]);
            exit;
        }

        $commentResult = vs_comments_handle_action($conn, (string)$action, (string)$vid);
T;

/* Each edit: [name, marker (text that exists once it is done), [[find, replace], ...]]. The first pair whose find text occurs exactly once is used. */
$patches = [
    'watch.php' => [
        ['require premium-store', $REQ_MARK, [["require_once __DIR__ . '/premium-popup.php';", "require_once __DIR__ . '/premium-popup.php';\n" . $REQ]]],
        ['like quest', '$questPoints = vs_rewards_quest_try(\'like\'', [[$like_old, $like_new], [$like_anchor, $like_anchor . "\n                " . $like_new]]],
        ['like reply carries points', '(int)($questPoints ?? 0)', [[$like_json_anchor, $like_json_new]]],
        ['comment quest', '$questPointsC = ', [[$cm_old, $cm_new], [$cm_anchor, $cm_anchor . "\n            " . $cm_new]]],
        ['comment reply carries points', '(int)($questPointsC ?? 0)', [[$cm_json_anchor, $cm_json_new]]],
        ['watch quest', '$questPointsW = ', [[$pg_old, $pg_new], [$pg_anchor, $pg_anchor_new]]],
        ['progress reply carries points', '(int)($questPointsW ?? 0)', [[$pg_json_old, $pg_json_new]]],
        ['points pop-up script', 'quest-toast.js', [[$script_watch_old, $script_watch_new]]],
    ],
    'shorts.php' => [
        ['require premium-store', $REQ_MARK, [["require_once __DIR__ . '/csrf.php';", "require_once __DIR__ . '/csrf.php';\n" . $REQ]]],
        ['like quest', '$questPoints = function_exists', [[$like_anchor, $s_like_new]]],
        ['like reply carries points', '(int)($questPoints ?? 0)', [[$s_like_json_old, $s_like_json_new]]],
        ['comment quest', '$questPointsC = ', [[$cm_anchor, $s_cm_new]]],
        ['comment reply carries points', '(int)($questPointsC ?? 0)', [[$cm_json_anchor, $cm_json_new]]],
        ['watch quest action', "'quest_watch'", [[$s_action_anchor, $s_action_new]]],
        ['points pop-up script', 'quest-toast.js', [[$script_short_old, $script_short_new]]],
    ],
    'comment-store.php' => [
        ['require premium-store', $REQ_MARK, [["require_once __DIR__ . '/notifications-store.php';", "require_once __DIR__ . '/notifications-store.php';\n" . $REQ]]],
        ['prefetch levels', 'vs_rewards_level_prefetch', [["foreach (\$rows as &\$row) {", "if (function_exists('vs_rewards_level_prefetch')) { vs_rewards_level_prefetch(array_column(\$rows, 'username')); }\n        foreach (\$rows as &\$row) {"]]],
        ['level pill in comments', 'vs_rewards_user_level_html', [["(function_exists('vs_vip_badge_html') ? vs_vip_badge_html(\$user) : '');", "(function_exists('vs_vip_badge_html') ? vs_vip_badge_html(\$user) : '') . (function_exists('vs_rewards_user_level_html') ? vs_rewards_user_level_html(\$user) : '');"]]],
    ],
    'profile.php' => [
        ['require premium-store', $REQ_MARK, [["require_once __DIR__ . '/subscription-store.php';", "require_once __DIR__ . '/subscription-store.php';\n" . $REQ]]],
        ['level pill on channel name', 'vs_rewards_user_level_html($profileUser', [["<?php echo function_exists('vs_vip_badge_html') ? vs_vip_badge_html(\$profileUser) : ''; ?></h1>", "<?php echo function_exists('vs_vip_badge_html') ? vs_vip_badge_html(\$profileUser) : ''; ?><?php echo function_exists('vs_rewards_user_level_html') ? vs_rewards_user_level_html(\$profileUser, 13) : ''; ?></h1>"]]],
    ],
    'index.php' => [
        ['prefetch levels', 'vs_rewards_level_prefetch', [["\$renderVideo = function (array \$row) {", "if (function_exists('vs_rewards_level_prefetch')) { vs_rewards_level_prefetch(array_map(static function (\$r) { return trim((string)(\$r['username'] ?? \$r['channel_name'] ?? '')); }, \$videos)); }\n                    \$renderVideo = function (array \$row) {"]]],
        ['level pill on video cards', 'vs_rewards_user_level_html($uploader', [["<a class=\"channel-name\" href=\"profile.php?u=<?php echo rawurlencode(\$uploader); ?>\"><?php echo \$username; ?></a>", "<a class=\"channel-name\" href=\"profile.php?u=<?php echo rawurlencode(\$uploader); ?>\"><?php echo \$username; ?></a><?php echo function_exists('vs_rewards_user_level_html') ? vs_rewards_user_level_html(\$uploader, 9) : ''; ?>"]]],
    ],
    'user-menu.php' => [
        ['require premium-store', $REQ_MARK, [["require_once __DIR__ . '/vip-badge.php';", "require_once __DIR__ . '/vip-badge.php';\n" . $REQ]]],
        ['level pill in the menu', 'vs_rewards_level_badge_html', [["\$nameBadge = vs_vip_badge(\$username, \$vipDb, 16);", "\$nameBadge = vs_vip_badge(\$username, \$vipDb, 16);\n        if (function_exists('vs_rewards_level_badge_html')) { \$nameBadge .= vs_rewards_level_badge_html(\$username); }"]]],
    ],
];

echo ($dry ? "DRY RUN in " : "Patching in ") . $dir . "\n\n";
$failed = 0;
foreach ($patches as $file => $edits) {
    $path = $dir . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        echo "[skip]   $file (not found)\n";
        continue;
    }
    $src = (string)file_get_contents($path);
    $crlf = strpos($src, "\r\n") !== false;
    $changed = false;
    foreach ($edits as [$name, $marker, $pairs]) {
        if (strpos($src, $marker) !== false) {
            echo "[done]   $file: $name (already applied)\n";
            continue;
        }
        $applied = false;
        $counts = [];
        foreach ($pairs as [$find, $replace]) {
            $count = substr_count($src, $find);
            $counts[] = $count;
            if ($count !== 1) {
                continue;
            }
            if ($crlf) {
                $replace = str_replace("\n", "\r\n", $replace);
            }
            $src = str_replace($find, $replace, $src);
            $applied = true;
            $changed = true;
            echo "[ok]     $file: $name\n";
            break;
        }
        if (!$applied) {
            echo "[FAILED] $file: $name (matches found: " . implode(', ', $counts) . "; expected exactly 1) - tell Claude which file version you have\n";
            $failed++;
        }
    }
    if ($changed && !$dry) {
        if (!is_file($path . '.bak-rewards')) {
            copy($path, $path . '.bak-rewards');
        }
        file_put_contents($path, $src, LOCK_EX);
    }
}
echo "\n" . ($failed ? "$failed edit(s) failed; nothing wrong was written for those.\n" : "All done.\n");