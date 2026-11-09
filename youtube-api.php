<?php
/**
 * youtube-api.php  (site root, next to live.php and live-api.php)
 * Small server-side helper for the YouTube Data API v3. The key is read from the
 * YOUTUBE_API_KEY environment variable or youtube-config.php and never reaches the browser.
 */

function vs_yt_key(): string
{
    $env = getenv('YOUTUBE_API_KEY');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    $cfg = @include __DIR__ . '/youtube-config.php';
    return is_array($cfg) ? trim((string)($cfg['api_key'] ?? '')) : '';
}

function vs_yt_video_id(string $url): string
{
    if (preg_match('#(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/))([A-Za-z0-9_-]{11})#i', $url, $m)) {
        return $m[1];
    }
    return '';
}

/** GET a URL and decode JSON; null on any failure. */
function vs_yt_http(string $url): ?array
{
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false]);
        $body = curl_exec($ch);
        if ($body === false) {
            error_log('youtube-api.php curl: ' . curl_error($ch));
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 6]]);
        $body = @file_get_contents($url, false, $ctx);
    }
    if (!is_string($body) || $body === '') {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return null;
    }
    if (isset($data['error'])) {
        error_log('youtube-api.php: ' . (string)($data['error']['message'] ?? 'API error'));
        return null;
    }
    return $data;
}

/**
 * Details for one video / live stream, cached briefly to save API quota.
 * Returns title, description, channel, thumbnail, live_status (live|upcoming|none),
 * concurrent_viewers, views, likes, actual_start, scheduled_start; or null.
 */
function vs_yt_video_details(string $videoId, int $ttl = 45): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
        return null;
    }
    $key = vs_yt_key();
    if ($key === '') {
        return null;
    }
    $dir = __DIR__ . '/cache/yt';
    $file = $dir . '/' . $videoId . '.json';
    if (is_file($file) && (time() - (int)@filemtime($file)) < $ttl) {
        $cached = json_decode((string)@file_get_contents($file), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $data = vs_yt_http(
        'https://www.googleapis.com/youtube/v3/videos?part=snippet,liveStreamingDetails,statistics&id='
        . rawurlencode($videoId) . '&key=' . rawurlencode($key)
    );
    $item = $data['items'][0] ?? null;
    if (!is_array($item)) {
        return null;
    }
    $sn = $item['snippet'] ?? [];
    $ld = $item['liveStreamingDetails'] ?? [];
    $st = $item['statistics'] ?? [];
    $thumbs = $sn['thumbnails'] ?? [];
    $thumb = (string)($thumbs['maxres']['url'] ?? $thumbs['high']['url'] ?? $thumbs['medium']['url'] ?? $thumbs['default']['url'] ?? '');
    $result = [
        'id' => $videoId,
        'title' => (string)($sn['title'] ?? ''),
        'description' => (string)($sn['description'] ?? ''),
        'channel' => (string)($sn['channelTitle'] ?? ''),
        'thumbnail' => $thumb,
        'live_status' => (string)($sn['liveBroadcastContent'] ?? 'none'),
        'concurrent_viewers' => isset($ld['concurrentViewers']) ? (int)$ld['concurrentViewers'] : null,
        'views' => isset($st['viewCount']) ? (int)$st['viewCount'] : null,
        'likes' => isset($st['likeCount']) ? (int)$st['likeCount'] : null,
        'actual_start' => (string)($ld['actualStartTime'] ?? ''),
        'scheduled_start' => (string)($ld['scheduledStartTime'] ?? ''),
    ];
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($file, json_encode($result), LOCK_EX);
    return $result;
}
