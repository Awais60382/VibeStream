<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/live-store.php';
require_once __DIR__ . '/youtube-api.php';
if (is_file(__DIR__ . '/vip-badge.php')) {
    require_once __DIR__ . '/vip-badge.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$username = trim((string)($_SESSION['username'] ?? $_SESSION['AdminUsername'] ?? ''));
$streamId = (int)($_GET['stream'] ?? 0);
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
if (empty($_SESSION['live_csrf'])) {
    $_SESSION['live_csrf'] = bin2hex(random_bytes(32));
}
$liveCsrf = $_SESSION['live_csrf'];

try {
    vs_live_ensure($pdo);
    if ($streamId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM live_streams WHERE id = ? LIMIT 1');
        $stmt->execute([$streamId]);
        $activeStream = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $activeStream = $pdo->query(
            "SELECT * FROM live_streams WHERE status = 'live' ORDER BY started_at DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$activeStream) {
        $stmt = $pdo->prepare(
            "SELECT * FROM live_streams WHERE status = 'scheduled' ORDER BY scheduled_at ASC LIMIT 1"
        );
        $stmt->execute();
        $activeStream = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $streams = $pdo->query(
        "SELECT id, username, title, description, thumbnail_url, status, scheduled_at, started_at, viewer_count
         FROM live_streams WHERE status IN ('live', 'scheduled') ORDER BY status = 'live' DESC, started_at DESC, scheduled_at ASC LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $error) {
    $activeStream = null;
    $streams = [];
    error_log('live.php: ' . $error->getMessage());
}

function vs_live_youtube_id(string $url): string
{
    if (!preg_match('#(?:youtu\.be/|youtube\.com/(?:watch\?v=|shorts/|embed/|live/))([A-Za-z0-9_-]{11})#i', $url, $matches)) {
        return '';
    }
    return $matches[1];
}

function vs_live_embed_url(string $url, bool $autoplay = true): string
{
    $youtubeId = vs_live_youtube_id($url);
    if ($youtubeId !== '') {
        return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($youtubeId)
            . ($autoplay ? '?autoplay=1&mute=1&rel=0' : '?rel=0');
    }
    return $url;
}

$activeStreamUrl = $activeStream ? trim((string)$activeStream['stream_url']) : '';
$activeThumbnailUrl = $activeStream ? trim((string)$activeStream['thumbnail_url']) : '';
$activeYoutubeId = $activeThumbnailUrl !== '' ? vs_live_youtube_id($activeThumbnailUrl) : '';
$activePlayableUrl = $activeStreamUrl !== '' ? $activeStreamUrl : ($activeYoutubeId !== '' ? $activeThumbnailUrl : '');

function vs_live_duration(?string $from, ?string $to): string
{
    $a = $from ? strtotime($from) : false;
    $b = $to ? strtotime($to) : false;
    if (!$a || !$b || $b < $a) return '';
    $d = $b - $a;
    $h = (int)floor($d / 3600);
    $m = (int)floor(($d % 3600) / 60);
    if ($h > 0) return $h . 'h ' . $m . 'm';
    return $m > 0 ? $m . 'm' : 'under a minute';
}

function vs_live_ago(?string $when): string
{
    $ts = $when ? strtotime($when) : false;
    if (!$ts) return '';
    $d = max(0, time() - $ts);
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . 'm ago';
    if ($d < 86400) return floor($d / 3600) . 'h ago';
    return floor($d / 86400) . 'd ago';
}

$isLive = $activeStream && $activeStream['status'] === 'live';
$isEnded = $activeStream && $activeStream['status'] === 'ended';
// The creator form only edits a stream that has not ended; after ending it is blank, ready for a new stream.
$isMine = $activeStream && !$isEnded && $username !== '' && strcasecmp((string)$activeStream['username'], $username) === 0;
$chatOn = $username !== '' && $isLive;
$ytThumb = '';
if ($activeStream && $activeThumbnailUrl !== '') {
    $yid = vs_live_youtube_id($activeThumbnailUrl);
    $ytThumb = $yid !== '' ? 'https://i.ytimg.com/vi/' . rawurlencode($yid) . '/hqdefault.jpg' : $activeThumbnailUrl;
}
$desc = $activeStream ? trim((string)$activeStream['description']) : '';
$descLong = mb_strlen($desc) > 130 || substr_count($desc, "\n") > 1;
$creatorName = $activeStream ? (string)$activeStream['username'] : '';
$initial = $creatorName !== '' ? mb_strtoupper(mb_substr($creatorName, 0, 1)) : 'L';

// Creator profile picture, resolved by the site's own avatar.php (uploads/avatars, then the users table).
$creatorAvatar = '';
if ($creatorName !== '') {
    try {
        if (!function_exists('avatar_url') && is_file(__DIR__ . '/avatar.php')) {
            require_once __DIR__ . '/avatar.php';
        }
        if (function_exists('avatar_url')) {
            $creatorAvatar = trim((string)avatar_url($creatorName));
        }
    } catch (Throwable $avatarError) {
        error_log('live.php avatar: ' . $avatarError->getMessage());
    }
}

// VIP / Premium badge helpers (from vip-badge.php); empty strings when the file is missing or the member is not VIP.
$dbRef = $pdo ?? null;
$vipBadge = static function (string $name, int $size = 16) use ($dbRef): string {
    return ($name !== '' && function_exists('vs_vip_badge')) ? vs_vip_badge($name, $dbRef, $size) : '';
};
$ringOpen = ($creatorName !== '' && function_exists('vs_vip_ring_open')) ? vs_vip_ring_open($creatorName, $dbRef, 62) : '';
$ringClose = ($creatorName !== '' && function_exists('vs_vip_ring_close')) ? vs_vip_ring_close($creatorName, $dbRef) : '';

// Live numbers from YouTube when the stream is a YouTube link (server-side, cached ~45s).
$ytId = $activeStream ? vs_live_youtube_id($activePlayableUrl !== '' ? $activePlayableUrl : $activeThumbnailUrl) : '';
$yt = $ytId !== '' ? vs_yt_video_details($ytId) : null;
$ytViewers = $yt && $yt['concurrent_viewers'] !== null ? (int)$yt['concurrent_viewers'] : null;

$extraCss = <<<'CSS'
.live-page{--lv-text:#f1f0ff;--lv-muted:#a7a9d6;--lv-line:rgba(139,92,246,.3);--lv-card:linear-gradient(160deg,rgba(22,20,58,.95),rgba(11,11,32,.98));--lv-field:rgba(10,10,32,.85);--lv-glow:rgba(124,58,237,.4);max-width:1500px;width:100%;margin:0 auto;padding:16px 22px 60px;color:var(--lv-text)}
html[data-theme="light"] .live-page{--lv-text:#1d1544;--lv-muted:#5f6690;--lv-line:rgba(124,77,255,.35);--lv-card:linear-gradient(160deg,#fff,#f8f5ff);--lv-field:#fff;--lv-glow:rgba(124,77,255,.28)}
.lv-main{display:grid;grid-template-columns:minmax(0,1fr) 400px;grid-template-areas:"player chat" "info chat2" "controls controls";gap:18px 20px;align-items:start}
.lv-player-wrap{grid-area:player;position:relative;min-width:0}
.live-player{aspect-ratio:16/9;border-radius:18px;overflow:hidden;background:radial-gradient(circle at 50% 35%,rgba(124,77,255,.18),transparent 30%),#07060f;display:grid;place-items:center;color:var(--lv-muted);border:1px solid var(--lv-line);box-shadow:0 0 28px var(--lv-glow)}
.live-player iframe,.live-player video{width:100%;height:100%;border:0}
.live-empty{display:grid;place-items:center;align-content:center;text-align:center;gap:8px;height:100%;width:100%;padding:20px;background:radial-gradient(circle at 50% 40%,rgba(168,85,247,.22),transparent 36%),#0a0818}
.live-empty strong{font-size:22px;color:#fff}.live-empty span{color:#a7a9d6}
.lv-overlay{position:absolute;left:14px;top:14px;z-index:2;display:flex;gap:8px;pointer-events:none}
.lv-pill{display:inline-flex;align-items:center;gap:7px;padding:7px 13px;border-radius:9px;font-weight:800;font-size:14px;color:#fff;background:rgba(10,8,30,.78);backdrop-filter:blur(6px);border:1px solid rgba(255,255,255,.12)}
.lv-pill.live{background:#e11d48;border-color:transparent;letter-spacing:.04em;box-shadow:0 0 16px rgba(225,29,72,.6)}
.lv-pill.sched{background:#7c3aed;border-color:transparent}
.lv-pill svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.lv-info{grid-area:info;min-width:0}
.lv-title-row{display:flex;align-items:center;gap:16px}
.lv-av img{width:100%;height:100%;object-fit:cover;display:block}
.lv-av{overflow:hidden;flex:0 0 62px;width:62px;height:62px;border-radius:50%;display:grid;place-items:center;font-size:26px;font-weight:800;color:#e9d5ff;background:#0b0620;border:2px solid #a855f7;box-shadow:0 0 16px rgba(168,85,247,.5)}
.lv-title-copy{flex:1;min-width:0}
.lv-title-copy h1{margin:0;font-size:clamp(20px,2.4vw,27px);line-height:1.2;font-weight:800;word-break:break-word}
.lv-sub{margin:5px 0 0;color:var(--lv-muted);font-size:14.5px}
.lv-sub b{color:var(--lv-text);font-weight:700}
.lv-join{display:inline-flex;align-items:center;gap:9px;padding:13px 22px;border-radius:12px;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;font-weight:800;text-decoration:none;box-shadow:0 0 18px rgba(124,58,237,.5);white-space:nowrap}
.lv-join:hover{filter:brightness(1.12);color:#fff}
.lv-join svg{width:22px;height:22px;fill:#fff}
.lv-desc{margin:14px 0 0;line-height:1.55;color:var(--lv-text);word-break:break-word}
.lv-desc.clamp{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.lv-more{margin-top:4px;padding:0;border:0;background:none;color:#a78bfa;font:inherit;font-weight:700;cursor:pointer}
.lv-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
.lv-chip{display:inline-flex;align-items:center;gap:9px;padding:11px 20px;border-radius:999px;border:1px solid var(--lv-line);background:var(--lv-card);color:var(--lv-text);font:inherit;font-weight:700;cursor:pointer}
.lv-chip:hover{border-color:#a855f7;box-shadow:0 0 14px var(--lv-glow)}
.lv-chip svg{width:19px;height:19px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.live-chat{grid-area:chat;grid-row:1/3;position:relative;align-self:stretch;min-height:440px;border:1px solid var(--lv-line);border-radius:18px;background:var(--lv-card);overflow:hidden}
.lv-chat-in{position:absolute;inset:0;display:grid;grid-template-rows:auto 1fr auto}
.lv-chat-head{display:flex;align-items:center;gap:10px;padding:16px 18px;border-bottom:1px solid var(--lv-line)}
.lv-chat-head h2{margin:0;font-size:18px;font-weight:800}
.lv-dot{width:12px;height:12px;border-radius:50%;background:#64748b}.lv-dot.on{background:#22e58b;box-shadow:0 0 10px #22e58b}
.chat-log{overflow-y:auto;padding:14px 16px;display:grid;align-content:start;gap:12px}
.chat-row{display:flex;align-items:flex-start;gap:11px;font-size:15px;line-height:1.4;word-break:break-word}
.chat-av{flex:0 0 36px;width:36px;height:36px;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:15px;color:#0b0620}
.chat-row b{margin-right:8px}
.chat-note{color:var(--lv-muted);font-size:14px}
.chat-form{display:flex;gap:0;margin:12px;border:1px solid var(--lv-line);border-radius:999px;background:var(--lv-field);overflow:hidden}
.chat-form:focus-within{border-color:#a855f7;box-shadow:0 0 14px var(--lv-glow)}
.chat-form input{flex:1;min-width:0;border:0;outline:0;padding:13px 18px;background:transparent;color:inherit;font:inherit}
.chat-form button{display:grid;place-items:center;width:54px;border:0;background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;cursor:pointer}
.chat-form button svg{width:22px;height:22px;fill:#fff}
.chat-form button:disabled{opacity:.45;cursor:not-allowed}
.lv-controls{grid-area:controls;border:1px solid var(--lv-line);border-radius:18px;background:var(--lv-card);overflow:hidden}
.lv-controls summary{display:flex;align-items:center;gap:12px;padding:16px 22px;cursor:pointer;list-style:none;font-size:18px;font-weight:800}
.lv-controls summary::-webkit-details-marker{display:none}
.lv-controls summary i{width:14px;height:14px;border-radius:50%;background:#ff2e93;box-shadow:0 0 12px #ff2e93}
.lv-controls summary::after{content:"";margin-left:auto;width:10px;height:10px;border-right:2px solid var(--lv-muted);border-bottom:2px solid var(--lv-muted);transform:rotate(45deg);transition:transform .15s}
.lv-controls[open] summary::after{transform:rotate(-135deg)}
.live-create{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:14px 16px;padding:6px 22px 22px;align-items:end}
.lv-f{display:grid;gap:7px;font-size:13.5px;font-weight:700;color:var(--lv-muted)}
.lv-f.c4{grid-column:span 4}.lv-f.c3{grid-column:span 3}.lv-f.c6{grid-column:span 6}.lv-f.c12{grid-column:1/-1}
.live-create input,.live-create textarea{width:100%;box-sizing:border-box;min-height:48px;border:1px solid var(--lv-line);border-radius:12px;padding:12px 14px;background:var(--lv-field);color:var(--lv-text);font:inherit}
.live-create input:focus,.live-create textarea:focus{outline:0;border-color:#a855f7;box-shadow:0 0 0 3px rgba(168,85,247,.22)}
.live-create textarea{min-height:86px;resize:vertical}
.lv-thumb{display:flex;align-items:center;gap:12px}
.lv-thumb img{flex:0 0 auto;width:96px;height:54px;object-fit:cover;border-radius:8px;border:1px solid var(--lv-line);background:#150f3a}
.lv-btns{grid-column:1/-1;display:flex;flex-wrap:wrap;gap:12px;align-items:center}
.lv-btn{display:inline-flex;align-items:center;justify-content:center;gap:9px;min-height:48px;padding:0 24px;border-radius:12px;border:1px solid var(--lv-line);background:transparent;color:var(--lv-text);font:inherit;font-weight:800;cursor:pointer}
.lv-btn:hover{border-color:#a855f7;box-shadow:0 0 14px var(--lv-glow)}
.lv-btn.primary{border:0;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff}
.lv-btn.go{border:0;background:linear-gradient(135deg,#ff2e93,#e11d8a);color:#fff;box-shadow:0 0 18px rgba(255,46,147,.45)}
.lv-btn[hidden]{display:none}
.lv-status{color:var(--lv-muted);font-size:14px}
.lv-title-row .vs-vip-ring{align-self:center}
.chat-ring{flex:0 0 auto;display:inline-flex;padding:1px;border:2px solid #f5b301;border-radius:50%;box-shadow:0 0 8px rgba(245,179,1,.45)}
.chat-ring .chat-av{flex:0 0 30px;width:30px;height:30px;font-size:13px}
.chat-vip{display:inline-block;vertical-align:-3px;margin:0 6px 0 -2px}
@media(max-width:480px){.lv-title-row .vs-vip-ring{width:48px!important;height:48px!important}}
.lv-ended{background:radial-gradient(circle at 50% 40%,rgba(120,120,160,.22),transparent 38%),#0a0818}
.lv-ended .lv-btn{margin-top:8px;text-decoration:none}
.live-section{margin-top:34px}
.live-section-head h2{margin:0 0 14px;font-size:22px;font-weight:800}
.live-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px}
.live-card{min-width:0;border-radius:16px;overflow:hidden;background:var(--lv-card);border:1px solid var(--lv-line);color:inherit;text-decoration:none;transition:transform .16s,box-shadow .16s,border-color .16s}
.live-card:hover{transform:translateY(-4px);border-color:rgba(192,132,252,.75);box-shadow:0 0 24px var(--lv-glow)}
.live-card-thumb{position:relative;display:block;aspect-ratio:16/9;background:radial-gradient(circle at 50% 45%,rgba(168,85,247,.25),transparent 40%),linear-gradient(135deg,#23205c,#7c3aed)}
.live-card img{width:100%;height:100%;object-fit:cover}
.live-card-overlay{position:absolute;left:10px;top:10px}
.live-badge{display:inline-flex;align-items:center;gap:6px;border-radius:8px;padding:5px 10px;background:#e11d48;color:#fff;font-size:11px;font-weight:900;letter-spacing:.05em}
.live-badge.scheduled{background:#7c3aed}
.live-card-copy{padding:13px 14px}
.live-card-copy strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-bottom:5px}
.live-card-meta{color:var(--lv-muted);font-size:13px}
.live-none{grid-column:1/-1;padding:30px;text-align:center;border:1px dashed var(--lv-line);border-radius:16px;color:var(--lv-muted)}
@media(max-width:1100px){.lv-main{grid-template-columns:1fr;grid-template-areas:"player" "chat" "info" "controls"}.live-chat{grid-row:auto;height:440px;min-height:0}.live-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.lv-f.c4,.lv-f.c3{grid-column:span 6}}
@media(max-width:760px){.live-page{padding:10px 10px 46px}.lv-title-row{flex-wrap:wrap}.lv-join{width:100%;justify-content:center}.live-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.lv-f.c4,.lv-f.c3,.lv-f.c6{grid-column:1/-1}.live-create{padding:4px 16px 18px}.lv-btn{flex:1 1 140px}.lv-pill{font-size:12px;padding:5px 10px}}
@media(max-width:480px){.live-grid{grid-template-columns:1fr}.lv-av{flex-basis:48px;width:48px;height:48px;font-size:20px}}
CSS;
vs_page_start('Live streams', 'live', $extraCss);
?>
<div class="live-page">
  <div class="lv-main">

    <section class="lv-player-wrap">
      <?php if ($activeStream): ?>
        <div class="lv-overlay">
          <?php if ($isLive): ?>
            <span class="lv-pill live"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8a6 6 0 010 8.4M7.8 16.2a6 6 0 010-8.4M19 5a10 10 0 010 14M5 19A10 10 0 015 5"/></svg>LIVE</span>
            <span class="lv-pill"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg><span class="js-viewers"><?= (int)$activeStream['viewer_count'] ?></span></span>
          <?php else: ?>
            <span class="lv-pill sched"><?= $activeStream['status'] === 'scheduled' ? 'SCHEDULED' : ($isEnded ? 'ENDED' : 'OFFLINE') ?></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="live-player" data-stream-id="<?= $activeStream ? (int)$activeStream['id'] : 0 ?>">
        <?php if ($isEnded): ?>
          <div class="live-empty lv-ended">
            <strong>This stream has ended</strong>
            <span>Thanks for watching<?= $creatorName !== '' ? ' ' . $h($creatorName) . '’s stream' : '' ?>.<?php $ranFor = vs_live_duration((string)$activeStream['started_at'], (string)$activeStream['ended_at']); if ($ranFor !== ''): ?> It ran for <?= $h($ranFor) ?>.<?php endif; ?></span>
            <a class="lv-btn primary" href="#liveMore">Watch more streams</a>
          </div>
        <?php elseif ($activeStream && $activeStream['status'] === 'live' && $activePlayableUrl !== ''): ?>
          <?php if (preg_match('/\.(m3u8|mp4|webm)(?:\?|$)/i', $activePlayableUrl)): ?>
            <video id="liveVideo" controls autoplay playsinline src="<?= $h($activePlayableUrl) ?>"></video>
          <?php else: ?>
            <iframe src="<?= $h(vs_live_embed_url($activePlayableUrl)) ?>" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen loading="eager"></iframe>
          <?php endif; ?>
        <?php elseif ($activeStream && $activeYoutubeId !== ''): ?>
          <iframe src="<?= $h(vs_live_embed_url($activeThumbnailUrl, false)) ?>" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen loading="eager"></iframe>
        <?php elseif ($activeStream && trim((string)$activeStream['thumbnail_url']) !== ''): ?>
          <img src="<?= $h($activeStream['thumbnail_url']) ?>" alt="<?= $h($activeStream['title']) ?>" style="width:100%;height:100%;object-fit:cover" onerror="this.replaceWith(Object.assign(document.createElement('div'),{className:'live-empty',innerHTML:'<strong>Stream preview</strong><span>Waiting for the stream to start.</span>'}))">
        <?php else: ?>
          <div class="live-empty">
            <strong><?= $activeStream && $activeStream['status'] === 'scheduled' ? 'Scheduled live stream' : 'No live stream selected' ?></strong>
            <span>Choose a live stream or schedule one below.</span>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <aside class="live-chat">
      <div class="lv-chat-in">
        <div class="lv-chat-head"><span class="lv-dot<?= $isLive ? ' on' : '' ?>"></span><h2>Live Chat</h2></div>
        <div class="chat-log" id="chatLog">
          <?php if (!$activeStream): ?><div class="chat-note">No live chat selected.</div>
          <?php elseif ($isEnded): ?><div class="chat-note">This stream has ended. Chat is closed.</div>
          <?php elseif (!$isLive): ?><div class="chat-note">Chat opens when the stream goes live.</div><?php endif; ?>
        </div>
        <form class="chat-form" id="chatForm">
          <input name="message" maxlength="500" autocomplete="off" placeholder="<?= $username ? ($isLive ? 'Say something...' : ($isEnded ? 'Chat closed' : 'Chat available when live')) : 'Sign in to chat' ?>" <?= $chatOn ? '' : 'disabled' ?>>
          <button type="submit" aria-label="Send message" <?= $chatOn ? '' : 'disabled' ?>><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 21l21-9L2 3v7l15 2-15 2z"/></svg></button>
        </form>
      </div>
    </aside>

    <section class="lv-info">
      <?php if ($activeStream): ?>
        <div class="lv-title-row">
          <?= $ringOpen ?><span class="lv-av" aria-hidden="true"><?php if ($creatorAvatar !== ''): ?><img src="<?= $h($creatorAvatar) ?>" alt="" data-i="<?= $h($initial) ?>" onerror="this.replaceWith(document.createTextNode(this.dataset.i))"><?php else: ?><?= $h($initial) ?><?php endif; ?></span><?= $ringClose ?>
          <div class="lv-title-copy">
            <h1><?= $h($activeStream['title']) ?></h1>
            <p class="lv-sub"><b><?= $h($creatorName) ?></b><?= $vipBadge($creatorName, 18) ?>
              <?php if ($isLive): ?> &nbsp;&bull;&nbsp; <span class="js-viewers" id="liveViewerCount"><?= (int)$activeStream['viewer_count'] ?></span> watching<?php if ($activeStream['started_at']): ?> &nbsp;&bull;&nbsp; Started <?= $h(vs_live_ago((string)$activeStream['started_at'])) ?><?php endif; ?>
              <?php if ($ytViewers !== null): ?> &nbsp;&bull;&nbsp; <?= number_format($ytViewers) ?> on YouTube<?php endif; ?>
              <?php elseif ($activeStream['status'] === 'scheduled' && $activeStream['scheduled_at']): ?> &nbsp;&bull;&nbsp; Starts <?= $h(date('M j, g:i A', strtotime((string)$activeStream['scheduled_at']))) ?>
              <?php elseif ($isEnded): ?> &nbsp;&bull;&nbsp; Ended <?= $h(vs_live_ago((string)$activeStream['ended_at'])) ?>
              <?php endif; ?></p>
          </div>
          <a class="lv-join" href="memberships.php?creator=<?= urlencode($creatorName) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8l4.5 4L12 5l4.5 7L21 8l-1.6 10H4.600L3 8zm1.8 12h14.400v1.600H4.800z"/></svg>Memberships</a>
        </div>
        <?php if ($desc !== ''): ?>
          <div class="lv-desc<?= $descLong ? ' clamp' : '' ?>" id="lvDesc"><?= nl2br($h($desc)) ?></div>
          <?php if ($descLong): ?><button type="button" class="lv-more" id="lvMore">...more</button><?php endif; ?>
        <?php endif; ?>
        <div class="lv-actions">
          <?php if ($yt && $yt['likes'] !== null): ?><span class="lv-chip" title="Likes on YouTube"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 11v9H3v-9h4zm0 0l4-8a2 2 0 012 2v4h6a2 2 0 012 2.300l-1.300 7A2 2 0 0117.700 20H7"/></svg><span><?= number_format((int)$yt['likes']) ?></span></span><?php endif; ?>
          <button type="button" class="lv-chip" id="lvShare"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12v7a1 1 0 001 1h14a1 1 0 001-1v-7M16 6l-4-4-4 4M12 2v13"/></svg><span>Share</span></button>
        </div>
      <?php else: ?>
        <div class="lv-title-row"><div class="lv-title-copy"><h1>Live streams</h1><p class="lv-sub">Pick a stream below or schedule your own.</p></div></div>
      <?php endif; ?>
    </section>

    <?php if ($username !== ''): ?>
    <details class="lv-controls"<?= $isMine ? ' open' : '' ?>>
      <summary><i></i>Creator Live Controls</summary>
      <form class="live-create" id="liveForm">
        <input type="hidden" name="csrf" value="<?= $h($liveCsrf) ?>">
        <label class="lv-f c4">Stream Title
          <input name="title" maxlength="160" placeholder="Stream title" required value="<?= $h($isMine ? $activeStream['title'] : '') ?>">
        </label>
        <label class="lv-f c4">Stream URL
          <input name="stream_url" type="url" placeholder="HLS, MP4, WebM, or embed URL" value="<?= $h($isMine ? $activeStream['stream_url'] : '') ?>">
        </label>
        <div class="lv-f c4">Thumbnail
          <div class="lv-thumb">
            <?php if ($isMine && $ytThumb !== ''): ?><img src="<?= $h($ytThumb) ?>" alt="" onerror="this.style.display='none'"><?php endif; ?>
            <input name="thumbnail_url" type="url" placeholder="Thumbnail URL" value="<?= $h($isMine ? $activeStream['thumbnail_url'] : '') ?>">
          </div>
        </div>
        <label class="lv-f c4">Schedule
          <input name="scheduled_at" type="datetime-local" value="<?= $h($isMine && $activeStream['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($activeStream['scheduled_at'])) : '') ?>">
        </label>
        <label class="lv-f c12">Description
          <textarea name="description" maxlength="1000" rows="3" placeholder="Describe your live stream"><?= $h($isMine ? $activeStream['description'] : '') ?></textarea>
        </label>
        <div class="lv-btns">
          <button type="submit" class="lv-btn primary">Save stream</button>
          <button type="button" id="ytFetch" class="lv-btn" title="Fill title, description and thumbnail from the YouTube link in Stream URL">Fetch from YouTube</button>
          <button type="button" id="goLiveButton" class="lv-btn go" <?= $isMine && $activeStream['status'] === 'scheduled' ? '' : 'hidden' ?>>Go live</button>
          <button type="button" id="endLiveButton" class="lv-btn go" <?= $isMine && $isLive ? '' : 'hidden' ?>>End Stream</button>
          <span class="lv-status" id="lvStatus" role="status"></span>
        </div>
      </form>
    </details>
    <?php endif; ?>
  </div>

  <section class="live-section" id="liveMore" aria-label="Live streams">
    <div class="live-section-head"><h2>Live now &amp; upcoming</h2></div>
    <div class="live-grid">
      <?php foreach ($streams as $stream): ?>
        <a class="live-card" href="live.php?stream=<?= (int)$stream['id'] ?>">
          <div class="live-card-thumb">
            <?php if ($stream['thumbnail_url'] !== ''): ?>
              <?php $cardYoutubeId = vs_live_youtube_id((string)$stream['thumbnail_url']); ?>
              <?php if ($cardYoutubeId !== ''): ?>
                <img src="<?= $h('https://i.ytimg.com/vi/' . rawurlencode($cardYoutubeId) . '/hqdefault.jpg') ?>" alt="" onerror="this.style.display='none'">
              <?php else: ?>
                <img src="<?= $h($stream['thumbnail_url']) ?>" alt="" onerror="this.style.display='none'">
              <?php endif; ?>
            <?php endif; ?>
            <div class="live-card-overlay"><span class="live-badge<?= $stream['status'] === 'scheduled' ? ' scheduled' : '' ?>"><?= $stream['status'] === 'live' ? 'LIVE' : 'UPCOMING' ?></span></div>
          </div>
          <div class="live-card-copy">
            <strong><?= $h($stream['title']) ?></strong>
            <span class="live-card-meta"><?= $h($stream['username']) ?><?= $vipBadge((string)$stream['username'], 14) ?> · <?= $stream['status'] === 'live' ? number_format((int)$stream['viewer_count']) . ' watching' : ($stream['scheduled_at'] ? date('M j, g:i A', strtotime($stream['scheduled_at'])) : 'Scheduled') ?></span>
          </div>
        </a>
      <?php endforeach; ?>
      <?php if (!$streams): ?><div class="live-none">No live streams or upcoming streams yet.</div><?php endif; ?>
    </div>
  </section>
</div>
<script>
(() => {
  const streamId = <?= $activeStream ? (int)$activeStream['id'] : 0 ?>;
  const streamIsLive = <?= $isLive ? 'true' : 'false' ?>;
  const form = document.getElementById('liveForm');
  const chatForm = document.getElementById('chatForm');
  const statusEl = document.getElementById('lvStatus');
  const liveCsrf = <?= json_encode($liveCsrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  let savedId = <?= $activeStream && $isMine ? (int)$activeStream['id'] : 0 ?>;
  const palette = ['#c084fc', '#22d3ee', '#34d399', '#f472b6', '#fbbf24', '#60a5fa'];
  const VIP_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" role="img" aria-label="VIP"><circle cx="12" cy="12" r="11" fill="#fff" stroke="rgba(128,128,128,.55)" stroke-width="1"/><path d="M7 12.6l3.2 3.2L17 8.9" fill="none" stroke="#111" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  const colorFor = (name) => { let h = 0; for (const c of String(name)) h = (h * 31 + c.charCodeAt(0)) >>> 0; return palette[h % palette.length]; };
  const say = (text) => { if (statusEl) statusEl.textContent = text || ''; };

  if (form) form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const body = new URLSearchParams(new FormData(form));
    body.set('action', 'save');
    if (savedId) body.set('id', String(savedId));
    try {
      const response = await fetch('live-api.php?action=save', { method: 'POST', body });
      const data = await response.json();
      if (!data.ok) return alert(data.error || 'Could not save stream');
      savedId = data.id;
      document.getElementById('goLiveButton').hidden = false;
      document.getElementById('endLiveButton').hidden = false;
      say('Saved.');
    } catch (e) { alert('Could not save stream'); }
  });

  async function control(action) {
    if (!savedId && !streamId) return;
    const body = new URLSearchParams({ action, id: String(savedId || streamId), csrf: liveCsrf });
    const response = await fetch('live-api.php?action=' + action, { method: 'POST', body });
    const data = await response.json();
    if (!data.ok) return alert(data.error || 'Could not update stream');
    location.href = 'live.php?stream=' + (savedId || streamId);
  }
  document.getElementById('goLiveButton')?.addEventListener('click', () => control('go_live'));
  document.getElementById('endLiveButton')?.addEventListener('click', async (event) => {
    if (!confirm('End this stream? Viewers will be disconnected and chat will close.')) return;
    const btn = event.currentTarget;
    btn.disabled = true;
    say('Ending stream...');
    try { await control('end'); } finally { btn.disabled = false; }
  });

  async function chatList() {
    if (!streamId || !streamIsLive) return;
    const log = document.getElementById('chatLog');
    try {
      const response = await fetch('live-api.php?action=chat_list&stream_id=' + streamId, { cache: 'no-store', credentials: 'same-origin' });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Chat could not be loaded.');
      if (data.status && data.status !== 'live') { location.reload(); return; }
      const atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 60;
      if (!data.messages || !data.messages.length) {
        const empty = document.createElement('div');
        empty.className = 'chat-note';
        empty.textContent = 'Be the first to chat.';
        log.replaceChildren(empty);
        return;
      }
      log.replaceChildren(...data.messages.map((row) => {
        const color = colorFor(row.username);
        const div = document.createElement('div');
        div.className = 'chat-row';
        const av = document.createElement('span');
        av.className = 'chat-av';
        av.style.background = color;
        av.textContent = (row.username || '?').charAt(0).toUpperCase();
        const text = document.createElement('div');
        const name = document.createElement('b');
        name.textContent = row.username;
        name.style.color = color;
        text.append(name);
        if (row.vip) {
          const badge = document.createElement('span');
          badge.className = 'chat-vip';
          badge.title = 'VIP - Premium member';
          badge.innerHTML = VIP_SVG;
          text.append(badge);
        }
        text.append(document.createTextNode(row.message));
        if (row.vip) {
          const ring = document.createElement('span');
          ring.className = 'chat-ring';
          ring.append(av);
          div.append(ring, text);
        } else {
          div.append(av, text);
        }
        return div;
      }));
      if (atBottom) log.scrollTop = log.scrollHeight;
    } catch (error) {
      const notice = document.createElement('div');
      notice.className = 'chat-note';
      notice.textContent = error.message || 'Chat is temporarily unavailable.';
      log.replaceChildren(notice);
    }
  }
  if (chatForm) chatForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const input = chatForm.elements.message;
    const sendButton = chatForm.querySelector('button[type="submit"]');
    const message = input.value.trim();
    if (!message) { input.focus(); return; }
    sendButton.disabled = true;
    const body = new URLSearchParams({ action: 'chat_send', stream_id: String(streamId), message: input.value, csrf: liveCsrf });
    try {
      const response = await fetch('live-api.php?action=chat_send', { method: 'POST', body, credentials: 'same-origin' });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Could not send message.');
      input.value = '';
      input.focus();
      await chatList();
      const log = document.getElementById('chatLog'); log.scrollTop = log.scrollHeight;
    } catch (error) {
      alert(error.message || 'Could not send message.');
    } finally {
      sendButton.disabled = false;
    }
  });

  async function heartbeat() {
    if (!streamId) return;
    const response = await fetch('live-api.php?action=heartbeat&stream_id=' + streamId, { cache: 'no-store', credentials: 'same-origin' });
    const data = await response.json();
    if (data.ok && data.status && data.status !== 'live' && streamIsLive) { location.reload(); return; }
    if (data.ok) document.querySelectorAll('.js-viewers').forEach((el) => { el.textContent = Number(data.viewer_count).toLocaleString(); });
  }
  if (streamId) {
    chatList();
    heartbeat();
    setInterval(chatList, 5000);
    setInterval(heartbeat, 30000);
  }

  const ytFetch = document.getElementById('ytFetch');
  if (ytFetch && form) ytFetch.addEventListener('click', async () => {
    const link = form.elements.stream_url.value.trim() || form.elements.thumbnail_url.value.trim();
    if (!link) { say('Paste a YouTube link in Stream URL first.'); return; }
    say('Asking YouTube...');
    try {
      const response = await fetch('live-api.php?action=yt_lookup&url=' + encodeURIComponent(link), { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
      if (!data.ok) { say(data.error || 'YouTube lookup failed.'); return; }
      const v = data.video;
      if (v.title) form.elements.title.value = v.title.slice(0, 160);
      if (v.description) form.elements.description.value = v.description.slice(0, 1000);
      if (v.thumbnail) form.elements.thumbnail_url.value = v.thumbnail;
      if (v.live_status === 'upcoming' && v.scheduled_start) {
        const d = new Date(v.scheduled_start);
        if (!isNaN(d)) { const p = (n) => String(n).padStart(2, '0'); form.elements.scheduled_at.value = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()); }
      }
      say('Filled from YouTube' + (v.channel ? ' (' + v.channel + ')' : '') + '. Review, then Save stream.');
    } catch (e) { say('YouTube lookup failed.'); }
  });

  const more = document.getElementById('lvMore'), desc = document.getElementById('lvDesc');
  if (more && desc) more.addEventListener('click', () => {
    const clamped = desc.classList.toggle('clamp');
    more.textContent = clamped ? '...more' : 'Show less';
  });
  const share = document.getElementById('lvShare');
  if (share) share.addEventListener('click', async () => {
    const url = location.origin + location.pathname.replace(/[^/]*$/, '') + 'live.php?stream=' + streamId;
    try {
      if (navigator.share) { await navigator.share({ title: document.title, url }); return; }
      await navigator.clipboard.writeText(url);
      share.querySelector('span').textContent = 'Link copied';
      setTimeout(() => { share.querySelector('span').textContent = 'Share'; }, 2000);
    } catch (e) {}
  });
})();
</script>
<?php vs_page_end(); ?>