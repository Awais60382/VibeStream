<?php
require_once __DIR__ . '/admin-bootstrap.php';
require_once __DIR__ . '/../site-branding.php';
require_once __DIR__ . '/../avatar.php';
require_once __DIR__ . '/../notifications-store.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('The database is unavailable.');
}

if (empty($_SESSION['vs_messages_csrf'])) {
    $_SESSION['vs_messages_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['vs_messages_csrf'];

$errorMsg = '';
$meId = vs_current_user_id($conn);
$meName = (string)($_SESSION['username'] ?? $_SESSION['AdminSession'] ?? 'Admin');

vs_admin_messages_ensure_table($conn);

/* ---- other admins ---- */
$admins = [];
$res = $conn->query("SELECT id, username FROM users WHERE LOWER(role) = 'admin' ORDER BY username");
while ($row = $res->fetch_assoc()) {
    if ((int)$row['id'] !== $meId) {
        $admins[(int)$row['id']] = (string)$row['username'];
    }
}
$res->free();

/* ---- send a message ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $toId = (int)($_POST['to'] ?? 0);
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The request expired. Refresh the page and try again.');
        }
        if ($meId <= 0) {
            throw new RuntimeException('Your admin account could not be identified. Sign in again.');
        }
        if (!isset($admins[$toId])) {
            throw new RuntimeException('Choose another admin to message.');
        }
        $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
        if ($body === '') {
            throw new RuntimeException('Type a message first.');
        }
        if (mb_strlen($body) > 2000) {
            throw new RuntimeException('Messages can be at most 2000 characters.');
        }
        if (!vs_admin_send_message($conn, $meId, $meName, $toId, $body)) {
            throw new RuntimeException('The message could not be sent. Please try again.');
        }
        header('Location: messages.php?with=' . $toId);
        exit;
    } catch (Throwable $error) {
        $errorMsg = $error->getMessage();
        $_GET['with'] = $toId;
    }
}

/* ---- open a conversation ---- */
$withId = (int)($_GET['with'] ?? 0);
if (!isset($admins[$withId])) {
    $withId = 0;
}

if ($withId > 0 && $meId > 0) {
    $mark = $conn->prepare('UPDATE admin_messages SET is_read = 1 WHERE recipient_id = ? AND sender_id = ? AND is_read = 0');
    $mark->bind_param('ii', $meId, $withId);
    $mark->execute();
    $mark->close();

    vs_notifications_ensure_table($conn);
    $notifLink = 'adminpanel/messages.php?with=' . $withId;
    $markNotif = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND type = 'message' AND link = ?");
    $markNotif->bind_param('is', $meId, $notifLink);
    $markNotif->execute();
    $markNotif->close();
}

function vs_msg_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function vs_msg_time(?string $when): string
{
    $ts = $when ? strtotime($when) : false;
    if (!$ts) {
        return '';
    }
    return date(date('Y-m-d') === date('Y-m-d', $ts) ? 'g:i A' : 'M j, g:i A', $ts);
}

function vs_msg_thread_html(mysqli $conn, int $meId, int $withId, string $meName, string $withName): string
{
    $stmt = $conn->prepare(
        'SELECT id, sender_id, body, created_at FROM (
            SELECT id, sender_id, body, created_at FROM admin_messages
            WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)
            ORDER BY id DESC LIMIT 200
         ) recent ORDER BY id ASC'
    );
    $stmt->bind_param('iiii', $meId, $withId, $withId, $meId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$rows) {
        return '<div class="msg-empty">No messages yet. Say hello below.</div>';
    }
    $html = '';
    foreach ($rows as $row) {
        $mine = (int)$row['sender_id'] === $meId;
        $html .= '<div class="msg-row ' . ($mine ? 'me' : 'them') . '">'
            . avatar_html($mine ? $meName : $withName, 32)
            . '<div class="msg-bubble ' . ($mine ? 'me' : 'them') . '">'
            . '<div class="msg-text">' . nl2br(vs_msg_h($row['body'])) . '</div>'
            . '<div class="msg-time">' . vs_msg_h(vs_msg_time((string)$row['created_at'])) . '</div>'
            . '</div></div>';
    }
    return $html;
}

/* ---- live refresh endpoint (polled by the page) ---- */
if (isset($_GET['partial'])) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $withId > 0 ? vs_msg_thread_html($conn, $meId, $withId, $meName, $admins[$withId]) : '';
    exit;
}

/* ---- contact list (unread count + last message) ---- */
$unreadBy = [];
$unreadStmt = $conn->prepare('SELECT sender_id, COUNT(*) AS n FROM admin_messages WHERE recipient_id = ? AND is_read = 0 GROUP BY sender_id');
$unreadStmt->bind_param('i', $meId);
$unreadStmt->execute();
$unreadRes = $unreadStmt->get_result();
while ($row = $unreadRes->fetch_assoc()) {
    $unreadBy[(int)$row['sender_id']] = (int)$row['n'];
}
$unreadStmt->close();

$lastStmt = $conn->prepare(
    'SELECT id, sender_id, body, created_at FROM admin_messages
     WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)
     ORDER BY id DESC LIMIT 1'
);
$contacts = [];
foreach ($admins as $id => $name) {
    $lastStmt->bind_param('iiii', $meId, $id, $id, $meId);
    $lastStmt->execute();
    $last = $lastStmt->get_result()->fetch_assoc();
    $contacts[] = [
        'id' => $id,
        'name' => $name,
        'unread' => $unreadBy[$id] ?? 0,
        'last_id' => (int)($last['id'] ?? 0),
        'preview' => $last ? (((int)$last['sender_id'] === $meId ? 'You: ' : '') . mb_substr(trim((string)preg_replace('/\s+/', ' ', (string)$last['body'])), 0, 60)) : 'No messages yet',
        'time' => $last ? vs_msg_time((string)$last['created_at']) : '',
    ];
}
usort($contacts, function ($a, $b) {
    return $b['last_id'] <=> $a['last_id'] ?: strcasecmp($a['name'], $b['name']);
});

require_once __DIR__ . '/header.php';
?>

<style>
  .msg-page h1 { font-size: 1.6rem; font-weight: 700; margin: 0 0 4px; }
  .msg-page .msg-sub { color: var(--admin-muted); margin: 0 0 20px; }
  .msg-shell { display: grid; grid-template-columns: 300px 1fr; gap: 16px; height: calc(100vh - 230px); min-height: 460px; }
  .msg-side, .msg-main {
    background: var(--admin-surface); border: 1px solid var(--admin-border); border-radius: 16px; overflow: hidden;
    display: flex; flex-direction: column; min-height: 0;
  }
  .msg-side-title { padding: 14px 18px; font-weight: 700; border-bottom: 1px solid var(--admin-border); }
  .msg-contacts { overflow-y: auto; flex: 1; }
  .msg-contact {
    display: flex; align-items: center; gap: 12px; padding: 12px 18px; color: var(--admin-text); text-decoration: none;
    border-bottom: 1px solid var(--admin-border);
  }
  .msg-contact:hover { background: rgba(127, 127, 127, 0.1); color: var(--admin-text); }
  .msg-contact.active { background: rgba(124, 58, 237, 0.12); }
  .msg-contact-copy { min-width: 0; flex: 1; }
  .msg-contact-name { display: flex; justify-content: space-between; gap: 8px; font-weight: 600; }
  .msg-contact-time { font-weight: 400; font-size: 0.75rem; color: var(--admin-muted); white-space: nowrap; }
  .msg-contact-preview { font-size: 0.85rem; color: var(--admin-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .msg-unread { background: #dc2626; color: #fff; border-radius: 999px; font-size: 0.72rem; font-weight: 700; padding: 2px 8px; }
  .msg-main-head { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--admin-border); font-weight: 700; }
  .msg-thread { flex: 1; overflow-y: auto; padding: 18px; display: flex; flex-direction: column; gap: 8px; min-height: 0; }
  .msg-row { display: flex; align-items: flex-end; gap: 8px; max-width: 85%; }
  .msg-row.me { align-self: flex-end; flex-direction: row-reverse; }
  .msg-row.them { align-self: flex-start; }
  .msg-bubble { min-width: 0; padding: 9px 14px; border-radius: 16px; overflow-wrap: anywhere; }
  .msg-bubble.me { background: var(--admin-primary); color: #fff; border-bottom-right-radius: 4px; }
  .msg-bubble.them { background: var(--admin-bg); border: 1px solid var(--admin-border); color: var(--admin-text); border-bottom-left-radius: 4px; }
  .msg-time { font-size: 0.7rem; opacity: 0.7; margin-top: 3px; text-align: right; }
  .msg-empty { margin: auto; color: var(--admin-muted); text-align: center; padding: 24px; }
  .msg-form { display: flex; gap: 10px; padding: 12px; border-top: 1px solid var(--admin-border); }
  .msg-form textarea {
    flex: 1; resize: none; min-height: 46px; max-height: 120px; padding: 11px 14px; border-radius: 12px;
    border: 1px solid var(--admin-border); background: var(--admin-bg); color: var(--admin-text); outline: none;
  }
  .msg-form textarea:focus { border-color: var(--admin-primary); box-shadow: var(--admin-ring); }
  .msg-form button {
    border: 0; border-radius: 12px; padding: 0 22px; background: var(--admin-primary); color: #fff; font-weight: 700; cursor: pointer;
  }
  .msg-form button:hover { background: var(--admin-primary-dark); }
  .msg-placeholder { margin: auto; text-align: center; color: var(--admin-muted); padding: 24px; }
  @media (max-width: 860px) {
    .msg-shell { grid-template-columns: 1fr; height: auto; }
    .msg-main { min-height: 420px; }
    .msg-contacts { max-height: 240px; }
  }
</style>

<main class="admin-content p-3 p-lg-4">
  <div class="msg-page">
    <h1>Messages</h1>
    <p class="msg-sub">Send private messages to the other admins. They get a notification in the bell.</p>

    <?php if ($errorMsg !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo vs_msg_h($errorMsg); ?></div>
    <?php endif; ?>

    <?php if (!$admins): ?>
      <div class="msg-side" style="padding: 36px 22px; text-align: center; color: var(--admin-muted);">
        There are no other admins yet. Make someone an admin from the Users page, then you can message each other here.
      </div>
    <?php else: ?>
      <div class="msg-shell">
        <aside class="msg-side" aria-label="Admins">
          <div class="msg-side-title">Admins</div>
          <div class="msg-contacts">
            <?php foreach ($contacts as $contact): ?>
              <a class="msg-contact<?php echo $contact['id'] === $withId ? ' active' : ''; ?>" href="messages.php?with=<?php echo (int)$contact['id']; ?>">
                <?php echo avatar_html($contact['name'], 40); ?>
                <span class="msg-contact-copy">
                  <span class="msg-contact-name"><span><?php echo vs_msg_h($contact['name']); ?></span><span class="msg-contact-time"><?php echo vs_msg_h($contact['time']); ?></span></span>
                  <span class="msg-contact-preview d-block"><?php echo vs_msg_h($contact['preview']); ?></span>
                </span>
                <?php if ($contact['unread'] > 0): ?><span class="msg-unread"><?php echo (int)$contact['unread']; ?></span><?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </aside>

        <section class="msg-main" aria-label="Conversation">
          <?php if ($withId > 0): ?>
            <div class="msg-main-head">
              <?php echo avatar_html($admins[$withId], 36); ?>
              <span><?php echo vs_msg_h($admins[$withId]); ?></span>
            </div>
            <div class="msg-thread" id="msgThread"><?php echo vs_msg_thread_html($conn, $meId, $withId, $meName, $admins[$withId]); ?></div>
            <form method="post" class="msg-form" id="msgForm" autocomplete="off">
              <input type="hidden" name="csrf_token" value="<?php echo vs_msg_h($csrf); ?>">
              <input type="hidden" name="to" value="<?php echo (int)$withId; ?>">
              <textarea name="body" id="msgBody" maxlength="2000" placeholder="Write a message... (Enter to send, Shift+Enter for a new line)" required></textarea>
              <button type="submit">Send</button>
            </form>
          <?php else: ?>
            <div class="msg-placeholder">Choose an admin on the left to start a conversation.</div>
          <?php endif; ?>
        </section>
      </div>
    <?php endif; ?>
  </div>
</main>

</div>
</div>
<script src="./assets/js/bootstrap.bundle.min.js"></script>
<script src="./assets/js/main.js"></script>
<?php if ($withId > 0): ?>
<script>
  (function () {
    var thread = document.getElementById('msgThread');
    var body = document.getElementById('msgBody');
    var form = document.getElementById('msgForm');
    if (!thread) return;

    function toBottom() { thread.scrollTop = thread.scrollHeight; }
    toBottom();

    if (body && form) {
      body.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
          event.preventDefault();
          if (body.value.trim() !== '') form.submit();
        }
      });
    }

    var url = 'messages.php?with=<?php echo (int)$withId; ?>&partial=1';
    setInterval(function () {
      if (document.hidden) return;
      fetch(url, { credentials: 'same-origin', cache: 'no-store' })
        .then(function (response) { return response.ok ? response.text() : null; })
        .then(function (html) {
          if (html === null || html === thread.innerHTML) return;
          var nearBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 80;
          thread.innerHTML = html;
          if (nearBottom) toBottom();
        })
        .catch(function () {});
    }, 8000);
  })();
</script>
<?php endif; ?>
</body>
</html>