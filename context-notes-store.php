<?php

function vs_context_notes_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (!$conn->query(
        'CREATE TABLE IF NOT EXISTS `video_context_notes` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `video_id` VARCHAR(64) NOT NULL,
            `content_type` VARCHAR(10) NOT NULL,
            `author` VARCHAR(100) NOT NULL,
            `note` TEXT NOT NULL,
            `source_url` VARCHAR(500) NOT NULL DEFAULT \'\',
            `status` VARCHAR(12) NOT NULL DEFAULT \'pending\',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `reviewed_by` VARCHAR(100) NULL,
            `reviewed_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `idx_context_notes_status` (`status`, `created_at`),
            KEY `idx_context_notes_video` (`video_id`, `content_type`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    )) {
        throw new RuntimeException('Could not prepare community context notes.');
    }
    $done = true;
}

function vs_context_notes_fetch(mysqli $conn, string $videoId, string $contentType, string $status = 'approved'): array
{
    if (!in_array($contentType, ['video', 'short'], true) || !in_array($status, ['pending', 'approved', 'rejected'], true)) {
        throw new InvalidArgumentException('Invalid context note filter.');
    }
    vs_context_notes_ensure($conn);
    $stmt = $conn->prepare(
        'SELECT `id`, `author`, `note`, `source_url`, `created_at`
         FROM `video_context_notes`
         WHERE `video_id` = ? AND `content_type` = ? AND `status` = ?
         ORDER BY `created_at` DESC, `id` DESC LIMIT 20'
    );
    if (!$stmt) {
        throw new RuntimeException('Could not load community context notes.');
    }
    $stmt->bind_param('sss', $videoId, $contentType, $status);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not load community context notes.');
    }
    $notes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $notes;
}

function vs_context_notes_panel_html(array $notes, string $videoId, string $contentType, string $csrfToken, bool $canSubmit, bool $available = true): string
{
    if ($available && !$notes) {
        return '';
    }
    $e = static function ($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };
    ob_start();
    ?>
    <section class="context-notes" data-context-notes data-video-id="<?= $e($videoId) ?>" data-content-type="<?= $e($contentType) ?>">
        <div class="context-notes-heading">
            <div>
                <h3>Community context</h3>
                <p>Helpful background and sources, reviewed by moderators before they appear.</p>
            </div>
            <?php if ($canSubmit): ?>
                <button type="button" class="context-notes-toggle" aria-expanded="false">Add context</button>
            <?php else: ?>
                <a class="context-notes-toggle" href="login.php">Sign in to add context</a>
            <?php endif; ?>
        </div>
        <p class="context-notes-status" data-context-status aria-live="polite"></p>
        <?php if ($canSubmit): ?>
            <form class="context-notes-form" data-context-form hidden>
                <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                <label>
                    Context note
                    <textarea name="note" minlength="30" maxlength="1000" required placeholder="Add concise, relevant context (30–1,000 characters)."></textarea>
                </label>
                <label>
                    Source link (optional)
                    <input type="url" name="source_url" maxlength="500" placeholder="https://…">
                </label>
                <p>Approved notes display your public username, note, and source. Do not include personal or sensitive information.</p>
                <button type="submit">Submit for review</button>
            </form>
        <?php endif; ?>
        <div class="context-notes-list" data-context-list>
            <?php if (!$available): ?>
                <p class="context-notes-empty">Community context is temporarily unavailable.</p>
            <?php elseif (!$notes): ?>
                <p class="context-notes-empty">No approved context notes yet.</p>
            <?php else: ?>
                <?php foreach ($notes as $note): ?>
                    <article class="context-note">
                        <p><?= nl2br($e($note['note'] ?? '')) ?></p>
                        <?php if (!empty($note['source_url'])): ?>
                            <a href="<?= $e($note['source_url']) ?>" target="_blank" rel="noopener noreferrer">Open source</a>
                        <?php endif; ?>
                        <small>Submitted by @<?= $e($note['author'] ?? 'user') ?> · <?= $e($note['created_at'] ?? '') ?></small>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
    <?php
    return (string)ob_get_clean();
}
