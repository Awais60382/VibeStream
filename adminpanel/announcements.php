<?php
require_once __DIR__ . '/admin-bootstrap.php';
require_once __DIR__ . '/../announcements-store.php';
require_once __DIR__ . '/../announcements-meta.php';
require_once __DIR__ . '/../csrf.php';

vs_updates_ensure($pdo);
vs_um_ensure($pdo);

$esc = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
$successMsg = (string)($_SESSION['vs_updates_flash'] ?? '');
unset($_SESSION['vs_updates_flash']);
$errorMsg = '';
$formValues = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formValues = $_POST;
    try {
        if (!vs_csrf_valid($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }

        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save') {
            // The Publish / Save as Draft buttons decide the status; the dropdown is the fallback.
            $status = (string)($_POST['status'] ?? ($_POST['status_pick'] ?? 'draft'));
            $post = $_POST;
            $post['status'] = in_array($status, ['draft', 'published'], true) ? $status : 'draft';

            // Check the thumbnail before saving anything.
            $newThumb = vs_um_store_upload($_FILES['thumb'] ?? ['error' => UPLOAD_ERR_NO_FILE]);

            vs_updates_save($pdo, $post, (string)($_SESSION['AdminSession'] ?? 'Admin'));

            // Work out which announcement was just saved (new ones get the newest id).
            $savedId = (int)($_POST['id'] ?? 0);
            if ($savedId <= 0) {
                $title = trim((string)($post['title'] ?? ''));
                $maxAny = 0;
                foreach (vs_updates_admin_all($pdo) as $u) {
                    $maxAny = max($maxAny, (int)$u['id']);
                    if ((string)$u['title'] === $title) {
                        $savedId = max($savedId, (int)$u['id']);
                    }
                }
                if ($savedId <= 0) {
                    $savedId = $maxAny;
                }
            }

            if ($savedId > 0) {
                $old = vs_um_all($pdo)[$savedId]['thumb'] ?? null;
                if ($newThumb !== null) {
                    vs_um_set_thumb($pdo, $savedId, $newThumb);
                    vs_um_remove_file($old);
                } elseif (!empty($_POST['remove_thumb']) && $old) {
                    vs_um_set_thumb($pdo, $savedId, null);
                    vs_um_remove_file($old);
                }
            }
            $_SESSION['vs_updates_flash'] = $post['status'] === 'published'
                ? 'Announcement published.'
                : 'Draft saved.';
        } elseif ($action === 'delete') {
            $delId = (int)($_POST['id'] ?? 0);
            vs_updates_delete($pdo, $delId);
            vs_um_delete($pdo, $delId);
            $_SESSION['vs_updates_flash'] = 'Announcement deleted.';
        } else {
            throw new RuntimeException('Choose a valid announcement action.');
        }

        header('Location: announcements.php');
        exit;
    } catch (Throwable $error) {
        error_log('Admin announcement action failed: ' . $error->getMessage());
        $errorMsg = $error instanceof RuntimeException
            ? $error->getMessage()
            : 'Could not save this announcement. Please try again.';
    }
}

$editId = (int)($_GET['edit'] ?? ($formValues['id'] ?? 0));
$editing = $editId > 0 ? vs_updates_find($pdo, $editId) : null;
if ($editId > 0 && !$editing && $errorMsg === '') {
    $errorMsg = 'That announcement could not be found.';
}
$meta = [];
try {
    $meta = vs_um_all($pdo);
} catch (Throwable $error) {
    error_log('Announcement meta could not be loaded: ' . $error->getMessage());
}
$editThumb = $editing ? ($meta[(int)$editing['id']]['thumb'] ?? null) : null;

$defaultPublishAt = (new DateTimeImmutable('now'))->format('Y-m-d\TH:i');
$formData = [
    'id' => (string)($formValues['id'] ?? ($editing['id'] ?? '')),
    'title' => (string)($formValues['title'] ?? ($editing['title'] ?? '')),
    'body' => (string)($formValues['body'] ?? ($editing['body'] ?? '')),
    'kind' => (string)($formValues['kind'] ?? ($editing['kind'] ?? 'announcement')),
    'status' => (string)($formValues['status_pick'] ?? ($formValues['status'] ?? ($editing['status'] ?? 'published'))),
    'publish_at' => (string)($formValues['publish_at'] ?? (isset($editing['publish_at']) ? date('Y-m-d\TH:i', strtotime((string)$editing['publish_at'])) : $defaultPublishAt)),
    'event_at' => (string)($formValues['event_at'] ?? (isset($editing['event_at']) && $editing['event_at'] ? date('Y-m-d\TH:i', strtotime((string)$editing['event_at'])) : '')),
];

$updates = [];
try {
    $updates = vs_updates_admin_all($pdo);
} catch (Throwable $error) {
    error_log('Admin announcements could not be listed: ' . $error->getMessage());
    if ($errorMsg === '') {
        $errorMsg = 'Announcements could not be loaded. Please refresh the page.';
    }
}

// ---- numbers for the stat cards + per-row state ----
$now = time();
$totalViews = 0;
$publishedCount = 0;
$upcomingCount = 0;
foreach ($updates as &$u) {
    $pub = strtotime((string)$u['publish_at']) ?: $now;
    $isDraft = ($u['status'] ?? '') !== 'published';
    $u['_state'] = $isDraft ? 'draft' : ($pub > $now ? 'scheduled' : 'published');
    $u['_views'] = (int)($meta[(int)$u['id']]['views'] ?? 0);
    $u['_thumb'] = $meta[(int)$u['id']]['thumb'] ?? null;
    $totalViews += $u['_views'];
    if ($u['_state'] === 'published') {
        $publishedCount++;
    }
    if (!$isDraft && !empty($u['event_at']) && strtotime((string)$u['event_at']) > $now) {
        $upcomingCount++;
    }
}
unset($u);

$kindIcon = static function (string $kind): string {
    $k = strtolower($kind);
    if (strpos($k, 'event') !== false) return 'bi-calendar-event-fill';
    if (strpos($k, 'maint') !== false || strpos($k, 'service') !== false) return 'bi-tools';
    if (strpos($k, 'release') !== false || strpos($k, 'update') !== false || strpos($k, 'feature') !== false) return 'bi-rocket-takeoff-fill';
    if (strpos($k, 'premium') !== false) return 'bi-gem';
    return 'bi-megaphone-fill';
};
$fmtViews = static function (int $n): string {
    if ($n >= 1000000) return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    if ($n >= 1000) return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
    return (string)$n;
};
$stateLabels = ['published' => 'Published', 'scheduled' => 'Scheduled', 'draft' => 'Draft'];

require_once __DIR__ . '/header.php';
?>
<style>
.vsa{--r1:#ff2b3a;--r2:#c4121f;--ink:#f6f1f1;--mut:#a89aa0;--line:rgba(255,60,70,.28);--panel:#0d0709;--panel2:#150a0d;
  --page:#060304;--field:#0a0507;--row:#0a0507;--row-text:#f6f1f1;
  color:var(--ink);border-radius:22px;padding:16px;background:radial-gradient(900px 400px at 0 0,rgba(255,30,45,.14),transparent 70%),var(--page)}
html[data-theme="light"] .vsa{--ink:#291a1a;--mut:#766565;--line:rgba(205,24,32,.22);--panel:#fff;--panel2:#fffafa;
  --page:#fffafa;--field:#fff;--row:#fff5f5;--row-text:#291a1a;
  background:radial-gradient(900px 400px at 0 0,rgba(255,30,45,.07),transparent 70%),var(--page)}
.vsa *{box-sizing:border-box}
.vsa h1,.vsa h2,.vsa h3,.vsa p{margin:0}
.vsa-hero{position:relative;display:flex;align-items:center;justify-content:space-between;gap:18px;padding:22px 26px;margin-bottom:16px;overflow:hidden;
  border:1px solid var(--line);border-radius:20px;background:linear-gradient(110deg,#140508 0%,#3a0910 55%,#a3121d 130%)}
.vsa-hero:after{content:"";position:absolute;inset:0;background:radial-gradient(500px 160px at 85% 0,rgba(255,60,70,.35),transparent 70%);pointer-events:none}
.vsa-hero>*{position:relative;z-index:1}
.vsa-hero-l{display:flex;align-items:center;gap:20px;min-width:0}
.vsa-hero-ic{flex:none;display:grid;place-items:center;width:78px;height:78px;border-radius:22px;font-size:40px;color:#fff;
  background:linear-gradient(145deg,var(--r1),var(--r2));box-shadow:0 0 34px rgba(255,40,55,.55)}
.vsa-hero h1{font-size:clamp(26px,4vw,44px);font-weight:900;letter-spacing:-.02em;line-height:1.05}
.vsa-hero h1 b{color:var(--r1);text-shadow:0 0 22px rgba(255,40,55,.6)}
.vsa-hero p{margin-top:6px;color:#e7d6d9;font-size:15px}
.vsa-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 16px;border-radius:12px;border:1px solid rgba(255,255,255,.22);
  background:rgba(255,255,255,.04);color:var(--ink);font:600 14px/1 inherit;text-decoration:none;cursor:pointer;transition:.15s}
.vsa-btn:hover{border-color:var(--r1);background:rgba(255,43,58,.12);color:#fff}
.vsa-btn.red{background:linear-gradient(135deg,var(--r1),var(--r2));border-color:transparent;box-shadow:0 8px 24px -8px rgba(255,43,58,.7)}
.vsa-btn.red:hover{filter:brightness(1.1)}
.vsa-btn.danger{border-color:rgba(255,43,58,.5);color:#ff5560}
.vsa-btn.sm{padding:8px 12px;font-size:13px;border-radius:10px}
.vsa-btn.ghost-red{border-color:var(--line)}
.vsa-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr);gap:16px;align-items:start}
.vsa-card{border:1px solid var(--line);border-radius:20px;background:linear-gradient(180deg,var(--panel2),var(--panel));overflow:hidden}
.vsa-card-h{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:16px 20px;border-bottom:1px solid var(--line)}
.vsa-card-h h2{display:flex;align-items:center;gap:10px;font-size:21px;font-weight:800}
.vsa-card-h h2 i{color:var(--r1)}
.vsa-card-b{padding:18px 20px 20px}
.vsa-tips{display:none;margin-bottom:14px;padding:12px 14px;border:1px solid rgba(245,185,66,.4);border-radius:12px;background:rgba(245,185,66,.08);font-size:13px;line-height:1.55;color:#f3dcae}
.vsa-tips.open{display:block}
.vsa-tips ul{margin:6px 0 0;padding-left:18px}
.vsa-form{display:grid;gap:14px}
.vsa-form label{display:flex;justify-content:space-between;margin-bottom:6px;font-size:14px;font-weight:700}
.vsa-form label em{font-style:normal;color:var(--r1)}
.vsa-form label small{color:var(--mut);font-weight:500;font-size:12px}
.vsa-in{width:100%;padding:11px 13px;border:1px solid rgba(255,255,255,.14);border-radius:11px;background:var(--field);color:var(--ink);font:inherit;font-size:14px}
.vsa-in::placeholder{color:#6e6065}
.vsa-in:focus{outline:none;border-color:var(--r1);box-shadow:0 0 0 3px rgba(255,43,58,.18)}
.vsa-in[type=datetime-local]{color-scheme:dark}
select.vsa-in{color-scheme:dark}
textarea.vsa-in{min-height:140px;resize:vertical}
.vsa-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.vsa-thumb{display:flex;align-items:center;gap:14px;padding:12px;border:1px dashed rgba(255,60,70,.4);border-radius:12px;background:var(--field)}
.vsa-thumb-prev{flex:none;display:grid;place-items:center;width:84px;height:62px;border-radius:10px;overflow:hidden;font-size:26px;color:var(--r1);
  background:radial-gradient(circle,#3a0910,#14080a)}
.vsa-thumb-prev img{width:100%;height:100%;object-fit:cover;display:block}
.vsa-thumb-in{flex:1;min-width:0;font-size:12px;color:var(--mut)}
.vsa-thumb-in input[type=file]{width:100%;color:var(--mut);font-size:13px}
.vsa-thumb-in label{display:flex;gap:6px;align-items:center;margin:6px 0 0;font-size:12px;font-weight:500;color:var(--mut)}
.vsa-actions{display:flex;flex-wrap:wrap;gap:10px}
.vsa-note{font-size:12px;line-height:1.55;color:var(--mut)}
.vsa-tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:14px}
.vsa-search{flex:1;min-width:150px}
.vsa-chips{display:flex;gap:6px;flex-wrap:wrap}
.vsa-chip{padding:7px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.16);background:transparent;color:var(--mut);font:600 12px/1 inherit;cursor:pointer}
.vsa-chip.on{border-color:var(--r1);background:rgba(255,43,58,.16);color:#fff}
.vsa-list{display:grid;gap:12px}
.vsa-row{display:flex;align-items:center;gap:14px;padding:12px;border:1px solid rgba(255,255,255,.1);border-radius:14px;background:var(--row);color:var(--row-text)}
.vsa-row:hover{border-color:var(--line)}
.vsa-row-img{flex:none;display:grid;place-items:center;width:112px;height:84px;border-radius:12px;overflow:hidden;font-size:38px;color:var(--r1);
  background:radial-gradient(circle at 50% 60%,#4a0b13,#14080a)}
.vsa-row-img img{width:100%;height:100%;object-fit:cover;display:block}
.vsa-row-main{flex:1;min-width:0}
.vsa-badge{display:inline-flex;align-items:center;gap:6px;margin-bottom:6px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:800;border:1px solid}
.vsa-badge:before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}
.vsa-badge.published{color:#2fe27a;border-color:rgba(47,226,122,.5);background:rgba(47,226,122,.1)}
.vsa-badge.draft{color:#ffb12e;border-color:rgba(255,177,46,.5);background:rgba(255,177,46,.1)}
.vsa-badge.scheduled{color:#c07bff;border-color:rgba(192,123,255,.5);background:rgba(192,123,255,.1)}
.vsa-kind{margin-left:8px;color:var(--mut);font-size:11px;font-weight:600}
.vsa-row h3{font-size:16px;font-weight:800;overflow-wrap:anywhere}
.vsa-row p{margin-top:3px;font-size:13px;color:#cdbfc3;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}
.vsa-meta{display:flex;flex-wrap:wrap;gap:4px 14px;margin-top:7px;font-size:12px;color:var(--mut)}
.vsa-meta i{margin-right:5px}
.vsa-row-act{flex:none;display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end;max-width:210px}
.vsa-row-act form{margin:0}
.vsa-empty{padding:30px 14px;border:1px dashed var(--line);border-radius:14px;color:var(--mut);text-align:center}
.vsa-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:16px}
.vsa-stat{display:flex;align-items:center;gap:14px;padding:16px 18px;border:1px solid var(--line);border-radius:16px;background:linear-gradient(180deg,var(--panel2),var(--panel))}
.vsa-stat i{flex:none;display:grid;place-items:center;width:46px;height:46px;border-radius:13px;font-size:22px;color:#fff;background:linear-gradient(145deg,var(--r1),var(--r2))}
.vsa-stat.v i{background:linear-gradient(145deg,#a45bff,#6a2bd0)}
.vsa-stat.g i{background:linear-gradient(145deg,#2fe27a,#139f4f)}
.vsa-stat.e i{background:linear-gradient(145deg,#ff9a2e,#d06a0a)}
.vsa-stat span{display:block;font-size:13px;color:var(--mut)}
.vsa-stat b{display:block;font-size:30px;font-weight:900;line-height:1.1}
@media(max-width:1100px){.vsa-grid{grid-template-columns:1fr}.vsa-stats{grid-template-columns:1fr 1fr}}
@media(max-width:640px){.vsa-hero{flex-direction:column;align-items:flex-start}.vsa-hero-ic{width:60px;height:60px;font-size:30px}
  .vsa-two{grid-template-columns:1fr}.vsa-row{flex-wrap:wrap}.vsa-row-img{width:84px;height:64px;font-size:28px}
  .vsa-row-act{max-width:none;width:100%;justify-content:flex-start}.vsa-stats{grid-template-columns:1fr}}
html[data-theme="light"] .admin-shell .vsa .vsa-hero{background:linear-gradient(110deg,#fff1f1 0%,#ffe4e5 60%,#ffd1d3 130%)}
html[data-theme="light"] .admin-shell .vsa :is(h1,h2,h3,.vsa-row h3){color:#291a1a!important}
html[data-theme="light"] .admin-shell .vsa :is(.vsa-hero p,.vsa-row p,.vsa-meta,.vsa-kind,.vsa-note,.vsa-thumb-in,.vsa-stat span){color:#766565!important}
html[data-theme="light"] .admin-shell .vsa :is(.vsa-row,.vsa-thumb){background:var(--row)!important;border-color:var(--line)}
html[data-theme="light"] .admin-shell .vsa .vsa-row{color:var(--row-text)!important}
html[data-theme="light"] .admin-shell .vsa .vsa-row-act .vsa-btn{color:#4b3030}
html[data-theme="light"] .admin-shell .vsa .vsa-btn:not(.red){border-color:var(--line);background:#fff;color:#4b3030}
html[data-theme="light"] .admin-shell .vsa .vsa-chip{color:#766565}
html[data-theme="light"] .admin-shell .vsa .vsa-chip.on{color:#9e101a}
html[data-theme="light"] .admin-shell .vsa .vsa-in{color:#291a1a!important;background:#fff!important;border-color:var(--line)!important}
html[data-theme="light"] .admin-shell .vsa .vsa-in::placeholder{color:#897777}
html[data-theme="light"] .admin-shell .vsa :is(select.vsa-in,input[type="datetime-local"].vsa-in){color-scheme:light}
</style>

<main class="dashboard-content">
  <div class="container-fluid px-3 px-lg-4 py-4">
    <?php if ($successMsg !== ''): ?><div class="alert alert-success" role="status"><?= $esc($successMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg !== ''): ?><div class="alert alert-danger" role="alert"><?= $esc($errorMsg) ?></div><?php endif; ?>

    <div class="vsa">
      <header class="vsa-hero">
        <div class="vsa-hero-l">
          <div class="vsa-hero-ic"><i class="bi bi-megaphone-fill" aria-hidden="true"></i></div>
          <div>
            <h1>Announcement <b>Studio</b></h1>
            <p>Create, manage and schedule announcements for your community.</p>
          </div>
        </div>
        <a class="vsa-btn ghost-red" href="../announcements.php"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>View Public Page<i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </header>

      <div class="vsa-grid">
        <!-- ============ form ============ -->
        <section class="vsa-card" aria-labelledby="vsaFormTitle">
          <div class="vsa-card-h">
            <h2 id="vsaFormTitle"><i class="bi bi-pencil-fill" aria-hidden="true"></i><?= $formData['id'] !== '' ? 'Edit Announcement' : 'Create Announcement' ?></h2>
            <button type="button" class="vsa-btn sm" id="vsaTipsBtn" aria-expanded="false"><i class="bi bi-lightbulb" aria-hidden="true"></i>Quick Tips</button>
          </div>
          <div class="vsa-card-b">
            <div class="vsa-tips" id="vsaTips">
              <b>Quick tips</b>
              <ul>
                <li>Published posts appear when their <em>Publish Date</em> arrives, so a future date schedules them.</li>
                <li>Add an <em>Event Date</em> and the post is also shown to visitors as an upcoming event.</li>
                <li>Drafts stay private. Text is shown as plain text.</li>
                <li>A 16:9 thumbnail (JPG, PNG, WebP or GIF, up to 2 MB) looks best.</li>
              </ul>
            </div>
            <form class="vsa-form" method="post" enctype="multipart/form-data" id="vsaForm">
              <?= vs_csrf_field() ?>
              <input type="hidden" name="action" value="save">
              <input type="hidden" name="id" value="<?= $esc($formData['id']) ?>">

              <div>
                <label for="vsaTitle"><span>Title <em>*</em></span><small><span id="vsaTitleN">0</span>/160</small></label>
                <input class="vsa-in" id="vsaTitle" name="title" type="text" maxlength="160" required value="<?= $esc($formData['title']) ?>" placeholder="Enter announcement title...">
              </div>

              <div class="vsa-two">
                <div>
                  <label for="vsaKind">Type</label>
                  <select class="vsa-in" id="vsaKind" name="kind">
                    <?php foreach (VS_UPDATE_TYPES as $kind => $label): ?>
                      <option value="<?= $esc($kind) ?>"<?= $formData['kind'] === $kind ? ' selected' : '' ?>><?= $esc($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label for="vsaStatus">Status</label>
                  <select class="vsa-in" id="vsaStatus" name="status_pick">
                    <option value="draft"<?= $formData['status'] === 'draft' ? ' selected' : '' ?>>Draft (not public)</option>
                    <option value="published"<?= $formData['status'] === 'published' ? ' selected' : '' ?>>Published</option>
                  </select>
                </div>
              </div>

              <div>
                <label for="vsaBody"><span>Announcement Content <em>*</em></span><small><span id="vsaBodyN">0</span>/5000</small></label>
                <textarea class="vsa-in" id="vsaBody" name="body" maxlength="5000" required placeholder="Write your announcement here..."><?= $esc($formData['body']) ?></textarea>
              </div>

              <div>
                <label for="vsaThumb">Thumbnail <small>optional</small></label>
                <div class="vsa-thumb">
                  <div class="vsa-thumb-prev" id="vsaThumbPrev">
                    <?php if ($editThumb): ?><img src="../<?= $esc($editThumb) ?>" alt=""><?php else: ?><i class="bi bi-image" aria-hidden="true"></i><?php endif; ?>
                  </div>
                  <div class="vsa-thumb-in">
                    <input id="vsaThumb" name="thumb" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
                    <?php if ($editThumb): ?><label><input type="checkbox" name="remove_thumb" value="1"> Remove current thumbnail</label><?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="vsa-two">
                <div>
                  <label for="vsaPub">Publish Date</label>
                  <input class="vsa-in" id="vsaPub" name="publish_at" type="datetime-local" required value="<?= $esc($formData['publish_at']) ?>">
                </div>
                <div>
                  <label for="vsaEvt">Event Date <small>optional</small></label>
                  <input class="vsa-in" id="vsaEvt" name="event_at" type="datetime-local" value="<?= $esc($formData['event_at']) ?>">
                </div>
              </div>

              <div class="vsa-actions">
                <button type="submit" name="status" value="published" class="vsa-btn red"><i class="bi bi-send-fill" aria-hidden="true"></i>Publish Announcement</button>
                <button type="submit" name="status" value="draft" class="vsa-btn" formnovalidate><i class="bi bi-save" aria-hidden="true"></i>Save as Draft</button>
                <?php if ($formData['id'] !== ''): ?>
                  <a class="vsa-btn" href="announcements.php"><i class="bi bi-x-lg" aria-hidden="true"></i>Cancel</a>
                <?php else: ?>
                  <button type="reset" class="vsa-btn" id="vsaReset"><i class="bi bi-x-lg" aria-hidden="true"></i>Reset</button>
                <?php endif; ?>
              </div>
              <p class="vsa-note">Visitors with VibeStream open see a popup when a post goes live, and upcoming events are listed in it. Browser notifications are optional and only arrive while the site is open.</p>
            </form>
          </div>
        </section>

        <!-- ============ list ============ -->
        <section class="vsa-card" aria-labelledby="vsaListTitle">
          <div class="vsa-card-h">
            <h2 id="vsaListTitle"><i class="bi bi-megaphone-fill" aria-hidden="true"></i>All Announcements (<?= count($updates) ?>)</h2>
          </div>
          <div class="vsa-card-b">
            <div class="vsa-tools">
              <input class="vsa-in vsa-search" id="vsaSearch" type="search" placeholder="Search announcements..." aria-label="Search announcements">
              <div class="vsa-chips" role="group" aria-label="Filter by status">
                <button type="button" class="vsa-chip on" data-f="all">All</button>
                <button type="button" class="vsa-chip" data-f="published">Published</button>
                <button type="button" class="vsa-chip" data-f="scheduled">Scheduled</button>
                <button type="button" class="vsa-chip" data-f="draft">Draft</button>
              </div>
            </div>

            <div class="vsa-list" id="vsaList">
              <?php if (!$updates): ?>
                <div class="vsa-empty">No announcements yet. Create a draft or publish your first update.</div>
              <?php else: ?>
                <?php foreach ($updates as $update):
                    $kind = (string)($update['kind'] ?? 'announcement');
                    $state = $update['_state'];
                    $pubTs = strtotime((string)$update['publish_at']) ?: $now;
                    $excerpt = trim(preg_replace('/\s+/', ' ', (string)$update['body']));
                ?>
                  <article class="vsa-row" data-state="<?= $esc($state) ?>" data-text="<?= $esc(mb_strtolower($update['title'] . ' ' . $excerpt)) ?>">
                    <div class="vsa-row-img">
                      <?php if ($update['_thumb']): ?><img src="../<?= $esc($update['_thumb']) ?>" alt="" loading="lazy"><?php else: ?><i class="bi <?= $esc($kindIcon($kind)) ?>" aria-hidden="true"></i><?php endif; ?>
                    </div>
                    <div class="vsa-row-main">
                      <span class="vsa-badge <?= $esc($state) ?>"><?= $esc($stateLabels[$state]) ?></span><span class="vsa-kind"><?= $esc(VS_UPDATE_TYPES[$kind] ?? 'Announcement') ?></span>
                      <h3><?= $esc($update['title']) ?></h3>
                      <p><?= $esc(mb_substr($excerpt, 0, 140)) ?></p>
                      <div class="vsa-meta">
                        <span><i class="bi bi-calendar3" aria-hidden="true"></i><?= $esc(date('M j, Y · H:i', $pubTs)) ?></span>
                        <span><i class="bi bi-eye" aria-hidden="true"></i><?= $esc($fmtViews($update['_views'])) ?> Views</span>
                        <?php if (!empty($update['event_at'])): ?><span><i class="bi bi-calendar-event" aria-hidden="true"></i>Event: <?= $esc(date('M j, Y · H:i', strtotime((string)$update['event_at']))) ?></span><?php endif; ?>
                      </div>
                    </div>
                    <div class="vsa-row-act">
                      <a class="vsa-btn sm" href="announcements.php?edit=<?= (int)$update['id'] ?>"><i class="bi bi-pencil" aria-hidden="true"></i>Edit</a>
                      <form method="post" onsubmit="return confirm('Delete this announcement permanently?');">
                        <?= vs_csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$update['id'] ?>">
                        <button class="vsa-btn sm danger" type="submit"><i class="bi bi-trash3" aria-hidden="true"></i>Delete</button>
                      </form>
                      <?php if ($state === 'draft'): ?>
                        <a class="vsa-btn sm" href="announcements.php?edit=<?= (int)$update['id'] ?>"><i class="bi bi-eye" aria-hidden="true"></i>Preview</a>
                      <?php else: ?>
                        <a class="vsa-btn sm" href="../announcements.php"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>View</a>
                      <?php endif; ?>
                    </div>
                  </article>
                <?php endforeach; ?>
                <div class="vsa-empty" id="vsaNone" hidden>No announcements match your filter.</div>
              <?php endif; ?>
            </div>
          </div>
        </section>
      </div>

      <div class="vsa-stats">
        <div class="vsa-stat"><i class="bi bi-megaphone-fill" aria-hidden="true"></i><div><span>Total Announcements</span><b><?= count($updates) ?></b></div></div>
        <div class="vsa-stat v"><i class="bi bi-eye-fill" aria-hidden="true"></i><div><span>Total Views</span><b><?= $esc($fmtViews($totalViews)) ?></b></div></div>
        <div class="vsa-stat e"><i class="bi bi-calendar-event-fill" aria-hidden="true"></i><div><span>Upcoming Events</span><b><?= $upcomingCount ?></b></div></div>
        <div class="vsa-stat g"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><div><span>Published</span><b><?= $publishedCount ?></b></div></div>
      </div>
    </div>
  </div>
</main>
<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };

  // character counters
  function count(inputId, outId) {
    var i = $(inputId), o = $(outId);
    if (!i || !o) { return; }
    var upd = function () { o.textContent = i.value.length; };
    i.addEventListener('input', upd); upd();
    return upd;
  }
  var t = count('vsaTitle', 'vsaTitleN'), b = count('vsaBody', 'vsaBodyN');
  $('vsaTitle').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
  var reset = $('vsaReset');
  if (reset) { reset.addEventListener('click', function () { setTimeout(function () { t(); b(); resetThumb(); }, 0); }); }

  // quick tips
  var tipsBtn = $('vsaTipsBtn'), tips = $('vsaTips');
  tipsBtn.addEventListener('click', function () {
    var open = tips.classList.toggle('open');
    tipsBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  // thumbnail preview
  var file = $('vsaThumb'), prev = $('vsaThumbPrev'), original = prev.innerHTML;
  function resetThumb() { prev.innerHTML = original; }
  file.addEventListener('change', function () {
    var f = file.files && file.files[0];
    if (!f) { resetThumb(); return; }
    if (f.size > 2 * 1024 * 1024) { alert('The thumbnail must be 2 MB or smaller.'); file.value = ''; resetThumb(); return; }
    var img = document.createElement('img');
    img.alt = '';
    img.src = URL.createObjectURL(f);
    prev.innerHTML = '';
    prev.appendChild(img);
  });

  // filter + search
  var rows = Array.prototype.slice.call(document.querySelectorAll('#vsaList .vsa-row'));
  var chips = Array.prototype.slice.call(document.querySelectorAll('.vsa-chip'));
  var none = $('vsaNone'), q = $('vsaSearch'), state = 'all';
  function apply() {
    var term = q.value.trim().toLowerCase(), shown = 0;
    rows.forEach(function (r) {
      var ok = (state === 'all' || r.getAttribute('data-state') === state) &&
               (!term || r.getAttribute('data-text').indexOf(term) !== -1);
      r.hidden = !ok;
      r.style.display = ok ? '' : 'none';
      if (ok) { shown++; }
    });
    if (none) { none.hidden = shown !== 0 || !rows.length; }
  }
  chips.forEach(function (c) {
    c.addEventListener('click', function () {
      state = c.getAttribute('data-f');
      chips.forEach(function (x) { x.classList.toggle('on', x === c); });
      apply();
    });
  });
  q.addEventListener('input', apply);
})();
</script>
<script src="../announcement-popup.js?v=<?= (int)@filemtime(__DIR__ . '/../announcement-popup.js') ?>" defer></script>
</div>
</div>
<script src="./assets/js/bootstrap.bundle.min.js"></script>
<script src="./assets/js/main.js"></script>
</body>
</html>