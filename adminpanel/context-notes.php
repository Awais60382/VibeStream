<?php
require_once __DIR__ . '/admin-bootstrap.php';
require_once __DIR__ . '/../context-notes-store.php';

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['admin_csrf'];
$adminName = trim((string)($_SESSION['AdminSession'] ?? 'Admin'));
$message = '';
$notesReady = true;
try {
    vs_context_notes_ensure($conn);
} catch (Throwable $error) {
    error_log('Community context notes schema failed: ' . $error->getMessage());
    $notesReady = false;
    $message = 'Community context notes are temporarily unavailable.';
}
if ($notesReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? null;
    $noteId = filter_var($_POST['note_id'] ?? null, FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    if (!is_string($token) || !hash_equals($csrf, $token)) {
        $message = 'Your session expired. Refresh and try again.';
    } elseif ($noteId === false || $noteId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        $message = 'Choose a valid moderation action.';
    } else {
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $conn->prepare(
            'UPDATE `video_context_notes`
             SET `status` = ?, `reviewed_by` = ?, `reviewed_at` = NOW()
             WHERE `id` = ? AND `status` = \'pending\''
        );
        $stmt->bind_param('ssi', $status, $adminName, $noteId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        $message = $changed === 1
            ? ($status === 'approved' ? 'Context note approved and published.' : 'Context note rejected.')
            : 'That note was already reviewed or could not be found.';
    }
}

$pendingNotes = [];
$pendingCount = 0;
try {
    if ($notesReady) {
        $pendingCount = (int)$conn->query("SELECT COUNT(*) FROM `video_context_notes` WHERE `status` = 'pending'")->fetch_row()[0];
        $videoTitleSelect = 'NULL AS `content_title`';
        $videoColumns = $conn->query("SHOW COLUMNS FROM `videos`");
        if ($videoColumns) {
            $availableVideoColumns = [];
            while ($videoColumn = $videoColumns->fetch_assoc()) {
                $availableVideoColumns[strtolower((string)$videoColumn['Field'])] = true;
            }
            foreach (['title', 'video_title', 'name'] as $candidate) {
                if (isset($availableVideoColumns[$candidate])) {
                    $videoTitleSelect = 'v.`' . $candidate . '` AS `content_title`';
                    break;
                }
            }
        }
        $result = $conn->query(
            "SELECT n.`id`, n.`video_id`, n.`content_type`, n.`author`, n.`note`, n.`source_url`, n.`created_at`,
                    {$videoTitleSelect}
             FROM `video_context_notes` n
             LEFT JOIN `videos` v ON v.`id` = n.`video_id`
             WHERE n.`status` = 'pending'
             ORDER BY n.`created_at` ASC, n.`id` ASC
             LIMIT 200"
        );
        $pendingNotes = $result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $error) {
    error_log('Community context moderation queue failed: ' . $error->getMessage());
    $message = 'The moderation queue could not be loaded. Please try again later.';
}

require_once __DIR__ . '/header.php';
$e = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
$safeHttpUrl = static function ($value): string {
    $url = trim((string)$value);
    return preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL)
        ? $url
        : '';
};
?>
<style>
  .context-notes-page {
    min-height: calc(100vh - 140px);
    color: var(--vs-shell-text, #241b39);
  }
  .context-notes-page .context-notes-heading {
    color: var(--vs-shell-text, inherit);
  }
  .context-notes-page .context-notes-card,
  .context-notes-page .card {
    background: var(--vs-shell-panel, #fff);
    color: var(--vs-shell-text, inherit);
    border: 1px solid var(--vs-shell-line, rgba(112,67,166,.16));
    box-shadow: 0 10px 30px rgba(40, 10, 70, .08);
  }
  html[data-theme="dark"] .context-notes-page .context-notes-card,
  html[data-bs-theme="dark"] .context-notes-page .context-notes-card,
  html[data-theme="dark"] .context-notes-page .card,
  html[data-bs-theme="dark"] .context-notes-page .card {
    box-shadow: 0 12px 32px rgba(0, 0, 0, .32);
  }
  .context-notes-page .text-muted {
    color: var(--vs-shell-muted, #6c607e) !important;
  }
  .context-notes-page .context-note-meta {
    display: block;
    margin: 10px 0 14px;
  }
  .context-notes-page .context-note-source {
    display: inline-block;
    margin-bottom: 4px;
  }
</style>
<main class="dashboard-content context-notes-page">
    <div class="container-fluid py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1">Community context notes</h1>
                <p class="text-muted mb-0">Review notes before they are shown on videos and Shorts.</p>
            </div>
            <span class="badge text-bg-warning"><?= number_format($pendingCount) ?> pending</span>
        </div>
        <?php if ($message !== ''): ?><div class="alert alert-info" role="status"><?= $e($message) ?></div><?php endif; ?>
        <?php if (!$pendingNotes): ?>
            <section class="card context-notes-card p-4"><p class="mb-0">No context notes are waiting for review.</p></section>
        <?php else: ?>
            <div class="d-grid gap-3">
                <?php foreach ($pendingNotes as $note): ?>
                    <article class="card context-notes-card p-3 p-md-4">
                        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                            <strong><?= $e($note['content_title'] ?: 'Content no longer available') ?></strong>
                            <span class="text-muted"><?= $e(ucfirst((string)$note['content_type'])) ?> · #<?= $e($note['video_id']) ?></span>
                        </div>
                        <p class="mb-2"><?= nl2br($e($note['note'])) ?></p>
                        <?php if (!empty($note['source_url'])): ?>
                            <?php $sourceUrl = $safeHttpUrl($note['source_url']); ?>
                            <?php if ($sourceUrl !== ''): ?>
                                <p><a class="context-note-source" href="<?= $e($sourceUrl) ?>" target="_blank" rel="noopener noreferrer">Open submitted source</a></p>
                            <?php endif; ?>
                        <?php endif; ?>
                        <small class="text-muted context-note-meta">Submitted by @<?= $e($note['author']) ?> · <?= $e($note['created_at']) ?></small>
                        <div class="d-flex gap-2">
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="note_id" value="<?= $e($note['id']) ?>">
                                <input type="hidden" name="action" value="approve">
                                <button class="btn btn-success" type="submit">Approve and publish</button>
                            </form>
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="note_id" value="<?= $e($note['id']) ?>">
                                <input type="hidden" name="action" value="reject">
                                <button class="btn btn-outline-danger" type="submit">Reject</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
