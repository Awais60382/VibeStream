<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();
// Same admin check as the other admin pages
if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

// connection.php creates the PDO object: $pdo
include_once "../connection.php";

$id = $_GET['editid'] ?? '';

$stmt = $pdo->prepare("SELECT * FROM videos WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "<script>alert('Video not found.'); window.location.href='videos.php';</script>";
    exit;
}
?>

<?php
include "header.php";
$currentType = (($row['type'] ?? 'video') === 'short') ? 'short' : 'video';
$backFile = $currentType === 'short' ? 'shorts.php' : 'videos.php';
$backLabel = $currentType === 'short' ? 'Back to Manage Shorts' : 'Back to Manage Videos';
$e = static function ($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
?>

      <style>
        .ev-page{--ev-card:linear-gradient(155deg,rgba(16,24,68,.96),rgba(7,12,39,.98));--ev-line:rgba(112,104,255,.38);--ev-text:#eaf0ff;--ev-muted:#9aa8d1;--ev-field:rgba(8,14,46,.9);--ev-field-ro:rgba(20,28,74,.85);color:var(--ev-text);max-width:1500px}
        html[data-theme="light"] .ev-page{--ev-card:linear-gradient(155deg,#ffffff,#f6f7ff);--ev-line:rgba(124,77,255,.30);--ev-text:#171b3b;--ev-muted:#5d6688;--ev-field:#ffffff;--ev-field-ro:#eef0fb}
        .ev-hero{display:flex;align-items:center;gap:22px;flex-wrap:wrap;padding:22px 28px;border-radius:20px;border:1px solid rgba(143,115,255,.55);background:linear-gradient(110deg,#1b1260 0%,#3b1b9c 52%,#12104a 100%);color:#fff;position:relative;overflow:hidden}
        .ev-hero-ico{width:76px;height:76px;border-radius:18px;display:grid;place-items:center;font-size:38px;color:#fff;background:linear-gradient(145deg,#6d4dff,#4527c9);border:1px solid rgba(255,255,255,.25);flex:none;position:relative}
        .ev-hero h1{font-size:clamp(1.6rem,3vw,2.4rem);font-weight:800;margin:0 0 4px;line-height:1.1;color:#fff}
        .ev-hero h1 span{color:#d46bff}
        .ev-hero p{margin:0;color:rgba(255,255,255,.82)}
        .ev-hero-right{margin-left:auto;position:relative;z-index:1}
        .ev-back{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:999px;border:1px solid rgba(255,255,255,.45);background:rgba(10,12,50,.55);color:#fff;font-weight:600;text-decoration:none;white-space:nowrap}
        .ev-back:hover{color:#fff;background:rgba(255,255,255,.14)}
        .ev-card{margin-top:16px;padding:22px 24px;border-radius:20px;border:1px solid var(--ev-line);background:var(--ev-card)}
        .ev-row{margin-bottom:16px}
        .ev-label{display:flex;align-items:center;gap:12px;font-weight:700;font-size:1.02rem;margin-bottom:8px;color:var(--ev-text)}
        .ev-label i{font-size:1.35rem;color:#a78bfa;width:28px;text-align:center}
        .ev-field{position:relative}
        .ev-field input,.ev-field select{width:100%;padding:14px 48px 14px 18px;font-size:1.05rem;border-radius:14px;border:1px solid var(--ev-line);background:var(--ev-field);color:var(--ev-text);appearance:none;-webkit-appearance:none}
        .ev-field input[readonly]{background:var(--ev-field-ro);color:var(--ev-muted);cursor:not-allowed}
        .ev-field select{background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='%23b8c2ee' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'><path d='M6 9l6 6 6-6'/></svg>");background-repeat:no-repeat;background-position:right 18px center}
        .ev-field input:focus,.ev-field select:focus{outline:none;border-color:#22d3ee}
        .ev-lock{position:absolute;right:18px;top:50%;transform:translateY(-50%);color:var(--ev-muted);pointer-events:none}
        .ev-actions{display:flex;justify-content:center;margin-top:8px}
        .ev-update{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-width:min(420px,100%);padding:14px 36px;border:0;border-radius:999px;background:linear-gradient(90deg,#6d4dff,#c026d3);color:#fff;font-size:1.15rem;font-weight:700;cursor:pointer}
        .ev-update:hover{filter:brightness(1.1)}
        @media (max-width:767.98px){.ev-hero{padding:18px}.ev-hero-ico{width:56px;height:56px;font-size:28px}.ev-hero-right{margin-left:0}.ev-card{padding:16px}}
      </style>
      <style data-vs-neon>
        /* neon glow layer: the lightning button switches this block off */
        .ev-hero{box-shadow:0 0 38px -10px rgba(138,92,255,.75),inset 0 0 60px -30px rgba(0,229,255,.35)}
        .ev-hero:before{content:"";position:absolute;inset:0;background:radial-gradient(520px 220px at 84% 15%,rgba(255,46,147,.26),transparent 70%),radial-gradient(420px 200px at 6% 100%,rgba(0,229,255,.16),transparent 70%);pointer-events:none}
        .ev-hero-ico{box-shadow:0 0 26px -2px rgba(124,77,255,.9)}
        .ev-hero h1 span{background:linear-gradient(90deg,#c084fc,#f0abfc);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:transparent;filter:drop-shadow(0 0 12px rgba(192,132,252,.65))}
        .ev-back{box-shadow:0 0 18px -4px rgba(34,211,238,.6);border-color:rgba(34,211,238,.7)}
        .ev-card{box-shadow:0 0 30px -12px rgba(112,104,255,.65),inset 0 1px 0 rgba(255,255,255,.04)}
        .ev-card:before{content:"";display:block;height:2px;margin:-22px -24px 20px;border-radius:2px;background:linear-gradient(90deg,transparent,#8a5cff 30%,#22d3ee 50%,#8a5cff 70%,transparent);opacity:.8}
        .ev-label i{filter:drop-shadow(0 0 6px rgba(167,139,250,.8))}
        .ev-field input,.ev-field select{box-shadow:0 0 14px -6px rgba(112,104,255,.7)}
        .ev-field input:focus,.ev-field select:focus{box-shadow:0 0 0 1px #22d3ee,0 0 18px rgba(34,211,238,.4)}
        .ev-update{box-shadow:0 0 24px rgba(192,38,211,.6),0 0 46px -8px rgba(109,77,255,.8)}
        .ev-update:hover{box-shadow:0 0 32px rgba(192,38,211,.85),0 0 60px -6px rgba(34,211,238,.5)}
      </style>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4 ev-page">
          <section class="ev-hero" aria-label="Edit video">
            <span class="ev-hero-ico"><i class="bi bi-pencil-square" aria-hidden="true"></i></span>
            <div>
              <h1>Edit Video / <span>Short</span></h1>
              <p>Update the title or type of this upload.</p>
            </div>
            <div class="ev-hero-right">
              <a href="<?php echo $backFile; ?>" class="ev-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> <?php echo $backLabel; ?></a>
            </div>
          </section>

          <section class="ev-card" aria-label="Edit form">
            <form action="code.php" method="POST">
              <!-- The ID is the record key, so it is sent as hidden and shown read-only -->
              <input type="hidden" name="id" value="<?php echo $e($row['id']); ?>">

              <div class="ev-row">
                <label class="ev-label" for="evId"><i class="bi bi-link-45deg" aria-hidden="true"></i> Video ID / URL</label>
                <div class="ev-field">
                  <input type="text" id="evId" value="<?php echo $e($row['id']); ?>" readonly>
                  <i class="bi bi-lock-fill ev-lock" aria-hidden="true"></i>
                </div>
              </div>

              <div class="ev-row">
                <label class="ev-label" for="evTitle"><i class="bi bi-play-btn-fill" aria-hidden="true"></i> Video Title</label>
                <div class="ev-field">
                  <input type="text" id="evTitle" value="<?php echo $e($row['title'] ?? ''); ?>" name="title" required>
                </div>
              </div>

              <div class="ev-row">
                <label class="ev-label" for="evType"><i class="bi bi-tag-fill" aria-hidden="true"></i> Type</label>
                <div class="ev-field">
                  <select name="type" id="evType">
                    <option value="video" <?php echo ($currentType === 'video') ? 'selected' : ''; ?>>Video</option>
                    <option value="short" <?php echo ($currentType === 'short') ? 'selected' : ''; ?>>Short</option>
                  </select>
                </div>
              </div>

              <div class="ev-row">
                <label class="ev-label" for="evWho"><i class="bi bi-person-badge-fill" aria-hidden="true"></i> Uploader</label>
                <div class="ev-field">
                  <input type="text" id="evWho" value="<?php echo $e($row['username'] ?? 'Admin'); ?>" readonly>
                  <i class="bi bi-lock-fill ev-lock" aria-hidden="true"></i>
                </div>
              </div>

              <div class="ev-actions">
                <button type="submit" name="update_video" value="Update" class="ev-update"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Update</button>
              </div>
            </form>
          </section>
        </div>
      </main>

      <footer class="admin-footer">
        <div class="container-fluid px-3 px-lg-4">
          <span>VibeStream Admin Panel</span>
        </div>
      </footer>
    </div>
  </div>

  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
</body>
</html>