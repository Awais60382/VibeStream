<?php
/*
 * VibeStream admin panel - My tasks (every admin).
 * Read-only list of the tasks the site owner assigned to the logged-in admin.
 * Put this file in the adminpanel folder next to header.php / checkin.php.
 * Only the owner closes tasks (on assign-task.php); the admin goes back to Available when the last one is closed.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['AdminSession'])) {
    header('Location: ../login.php');
    exit;
}

// header.php prints the page shell. Buffer it so we can still redirect after a form.
ob_start();
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/checkin-lib.php';

$me = vs_checkin_admin_name();   // the same name the owner's task was assigned to
if (!$me) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Location: ../login.php');
    exit;
}

$payCur = defined('VS_PAY_CURRENCY') ? VS_PAY_CURRENCY : 'PKR';
$esc = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$open = [];
$closed = [];
foreach (array_reverse(vs_task_all(), true) as $t) {
    if (($t['admin'] ?? '') !== $me) {
        continue;
    }
    if (($t['status'] ?? '') === 'open') {
        $open[] = $t;
    } elseif (count($closed) < 15) {
        $closed[] = $t;
    }
}
$open = array_reverse($open);   // oldest first: do them in the order they were given

$rec = vs_checkin_all()[$me] ?? null;
$eff = $rec ? vs_checkin_effective($rec) : 'Offline';

/** <time> tag that the script at the bottom turns into the viewer's own local time. */
$lt = static function ($at) use ($esc): string {
    $iso = '';
    try {
        if ((string)$at !== '') {
            $iso = (new DateTimeImmutable((string)$at))->format('c');
        }
    } catch (Exception $ex) {
    }
    return '<time class="mt-time" datetime="' . $esc($iso) . '">' . $esc($at) . '</time>';
};
?>

      <style>
        .mt-page{--mt-card:linear-gradient(160deg,rgba(14,19,58,.97),rgba(8,12,40,.98));--mt-line:rgba(112,104,255,.40);--mt-row:rgba(112,104,255,.18);--mt-text:#eef2ff;--mt-muted:#9aa8d1;--mt-chip-bg:rgba(10,14,56,.85);--mt-hover:rgba(124,77,255,.08);color:var(--mt-text)}
        html[data-theme="light"] .mt-page{--mt-card:linear-gradient(160deg,#ffffff,#f6f7ff);--mt-line:rgba(124,77,255,.30);--mt-row:rgba(124,77,255,.14);--mt-text:#1b2447;--mt-muted:#5f6c93;--mt-chip-bg:#eef1fb;--mt-hover:rgba(124,77,255,.06)}
        .mt-wrap{max-width:1500px;margin:0 auto}
        .mt-head{display:flex;align-items:center;gap:22px;margin-bottom:22px;flex-wrap:wrap}
        .mt-head-ico{display:grid;place-items:center;width:104px;height:104px;border-radius:24px;background:linear-gradient(145deg,#6d4dff,#4527c9);border:1px solid rgba(255,255,255,.22);color:#fff;font-size:3rem;flex:none}
        .mt-head-copy{flex:1;min-width:240px}
        .mt-head h1{margin:0;font-size:clamp(1.9rem,3.6vw,2.8rem);font-weight:800;line-height:1.1;color:var(--mt-text)}
        .mt-head p{margin:6px 0 0;color:var(--mt-muted);font-size:1.05rem}
        .mt-status{display:inline-flex;align-items:center;gap:10px;padding:12px 22px;border-radius:999px;border:1px solid var(--mt-line);background:var(--mt-chip-bg);color:var(--mt-text);font-size:.95rem;font-weight:600;white-space:nowrap}
        .mt-dot{width:16px;height:16px;border-radius:50%;display:inline-block;flex:none}
        .mt-flash{margin-bottom:14px;padding:10px 14px;border-radius:10px;font-size:.88rem}
        .mt-card{margin-bottom:22px;border:1px solid var(--mt-line);border-radius:20px;background:var(--mt-card);overflow:hidden}
        .mt-card-head{display:flex;align-items:center;gap:16px;padding:18px 24px;border-bottom:1px solid var(--mt-line)}
        .mt-card-ico{display:grid;place-items:center;width:40px;height:40px;flex:none;border-radius:50%;font-size:1.4rem;color:#fff;background:#5b3bd6}
        .mt-card-ico.dot{width:26px;height:26px;margin:0 7px;background:#5b3bd6}
        .mt-card-ico.ok{background:#14c27a}
        .mt-card-head > div{flex:1;min-width:0}
        .mt-card-head h2{margin:0;font-size:1.45rem;font-weight:700;color:var(--mt-text)}
        .mt-card-head p{margin:2px 0 0;color:var(--mt-muted);font-size:.95rem}
        .mt-chip{flex:none;padding:7px 20px;border:1px solid var(--mt-line);border-radius:999px;background:var(--mt-chip-bg);color:var(--mt-text);font-size:.92rem;font-weight:700;white-space:nowrap}
        .mt-list{margin:0;padding:0;list-style:none}
        .mt-item{display:flex;align-items:center;gap:20px;padding:18px 24px;border-top:1px solid var(--mt-row)}
        .mt-item:first-child{border-top:0}
        .mt-item:hover{background:var(--mt-hover)}
        .mt-num{display:grid;place-items:center;flex:none;width:48px;height:48px;border-radius:50%;background:linear-gradient(145deg,#6d4dff,#4527c9);color:#fff;font-size:1.25rem;font-weight:800}
        .mt-main{flex:1;min-width:0}
        .mt-title{font-weight:700;font-size:1.15rem;color:var(--mt-text);overflow-wrap:anywhere}
        .mt-details{margin-top:3px;color:var(--mt-muted);font-size:.98rem;overflow-wrap:anywhere;white-space:pre-line}
        .mt-sub{display:flex;align-items:center;gap:8px;margin-top:6px;color:var(--mt-muted);font-size:.9rem}
        .mt-reward{margin-top:8px;color:#e0a100;font-size:.88rem;font-weight:700}
        html:not([data-theme="light"]) .mt-reward{color:#fbbf24}
        .mt-wait{display:inline-flex;align-items:center;gap:6px;padding:8px 24px;border-radius:999px;border:1.5px solid #7c5cff;background:transparent;color:var(--mt-text);font-size:.98rem;font-weight:700;white-space:nowrap}
        .mt-empty{padding:34px 16px;color:var(--mt-muted);font-size:1rem;text-align:center;margin:0}
        .mt-empty .bi{display:block;font-size:2.4rem;margin-bottom:8px;color:#14c27a}
        .mt-done{display:grid;grid-template-columns:44px minmax(0,1fr) minmax(170px,auto) auto;align-items:center;gap:16px;padding:14px 24px;border-top:1px solid var(--mt-row)}
        .mt-done:first-child{border-top:0}
        .mt-done:hover{background:var(--mt-hover)}
        .mt-tick{display:grid;place-items:center;width:36px;height:36px;border-radius:50%;background:#14c27a;color:#fff;font-size:1.2rem;justify-self:center}
        .mt-tick.bad{background:#e0306f}
        .mt-when{display:flex;align-items:center;gap:8px;color:var(--mt-muted);font-size:.95rem;white-space:nowrap}
        .mt-badge{display:inline-flex;align-items:center;justify-content:center;min-width:104px;padding:7px 22px;border-radius:999px;border:1.5px solid;font-size:.95rem;font-weight:700}
        .mt-badge.ok{color:#34d399;border-color:#14c27a;background:rgba(20,194,122,.12)}
        .mt-badge.bad{color:#fb7185;border-color:#f43f5e;background:rgba(244,63,94,.12)}
        html[data-theme="light"] .mt-badge.ok{color:#047857}
        html[data-theme="light"] .mt-badge.bad{color:#be123c}
        @media (max-width:767.98px){
          .mt-head-ico{width:68px;height:68px;font-size:2rem;border-radius:18px}
          .mt-item{flex-wrap:wrap;padding:14px 16px}
          .mt-done{grid-template-columns:36px minmax(0,1fr) auto;padding:12px 16px}
          .mt-done .mt-when{grid-column:2/3;grid-row:2}
          .mt-card-head{padding:14px 16px}
        }
      </style>
      <style data-vs-neon>
        /* neon glow layer: the lightning button switches this block off */
        .mt-head-ico{box-shadow:0 0 30px -4px rgba(124,77,255,.9),inset 0 0 18px rgba(255,255,255,.12)}
        .mt-head h1{text-shadow:0 0 22px rgba(138,92,255,.55)}
        .mt-status{border-color:rgba(34,211,238,.6);box-shadow:0 0 18px -4px rgba(34,211,238,.55)}
        .mt-dot{box-shadow:0 0 0 3px rgba(255,255,255,.07),0 0 12px 2px currentColor}
        .mt-card{box-shadow:0 0 28px -10px rgba(112,104,255,.65),inset 0 1px 0 rgba(255,255,255,.04);transition:border-color .2s,box-shadow .2s}
        .mt-card:before{content:"";display:block;height:2px;margin:0 8%;border-radius:2px;background:linear-gradient(90deg,transparent,#8a5cff 30%,#22d3ee 50%,#8a5cff 70%,transparent);opacity:.85}
        .mt-card-head h2{text-shadow:0 0 14px rgba(138,92,255,.5)}
        .mt-card-ico.dot{box-shadow:0 0 16px 2px rgba(124,77,255,.9)}
        .mt-card-ico.ok{box-shadow:0 0 16px -2px rgba(20,194,122,.9)}
        .mt-chip{border-color:rgba(138,92,255,.6);box-shadow:0 0 12px -3px rgba(138,92,255,.7)}
        .mt-num{box-shadow:0 0 18px -2px rgba(124,77,255,.95)}
        .mt-item:hover{box-shadow:inset 3px 0 0 #22d3ee,inset 0 0 24px rgba(34,211,238,.06)}
        .mt-wait{border-color:#8a5cff;box-shadow:0 0 14px -2px rgba(138,92,255,.85);animation:mtPulse 2.4s ease-in-out infinite}
        @keyframes mtPulse{50%{box-shadow:0 0 22px 0 rgba(138,92,255,1)}}
        .mt-tick{box-shadow:0 0 14px -2px rgba(20,194,122,.9)}
        .mt-tick.bad{box-shadow:0 0 14px -2px rgba(224,48,111,.9)}
        .mt-badge.ok{box-shadow:0 0 14px -3px rgba(20,194,122,.9)}
        .mt-badge.bad{box-shadow:0 0 14px -3px rgba(244,63,94,.9)}
        .mt-reward{text-shadow:0 0 8px rgba(251,191,36,.55)}
        @media (prefers-reduced-motion:reduce){.mt-wait{animation:none}}
      </style>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4 mt-page">
          <div class="mt-wrap">
            <div class="mt-head">
              <span class="mt-head-ico"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span>
              <div class="mt-head-copy">
                <h1>My Tasks</h1>
                <p>Here are the tasks assigned to you by the owner.</p>
              </div>
              <span class="mt-status"><span class="mt-dot" style="background:<?php echo $esc(vs_checkin_color($eff)); ?>;color:<?php echo $esc(vs_checkin_color($eff)); ?>"></span><?php echo $esc($open ? 'Busy' : $eff); ?></span>
            </div>

            <section class="mt-card" aria-label="Open tasks">
              <div class="mt-card-head">
                <span class="mt-card-ico dot" aria-hidden="true"></span>
                <div><h2>Open Tasks</h2><p>Complete these tasks in the given order.</p></div>
                <span class="mt-chip"><?php echo count($open); ?> Task<?php echo count($open) === 1 ? '' : 's'; ?></span>
              </div>
              <?php if (!$open): ?>
                <p class="mt-empty"><i class="bi bi-check2-circle" aria-hidden="true"></i><strong>All finished.</strong> There is nothing left to do.</p>
              <?php else: ?>
              <ul class="mt-list">
                <?php foreach ($open as $i => $t): ?>
                  <li class="mt-item">
                    <span class="mt-num"><?php echo $i + 1; ?></span>
                    <div class="mt-main">
                      <div class="mt-title"><?php echo $esc($t['title'] ?? ''); ?></div>
                      <?php if (!empty($t['details'])): ?><div class="mt-details"><?php echo $esc($t['details']); ?></div><?php endif; ?>
                      <div class="mt-sub"><i class="bi bi-calendar3" aria-hidden="true"></i><span>From <?php echo $esc($t['assigned_by'] ?? 'the owner'); ?> &middot; <?php echo $lt($t['assigned_at'] ?? ''); ?></span></div>
                      <?php if (!empty($t['reward'])): ?><div class="mt-reward"><i class="bi bi-cash-coin" aria-hidden="true"></i> <?php echo $esc($payCur . ' ' . number_format((float)$t['reward'], 2)); ?> added to your pay when this is done</div><?php endif; ?>
                    </div>
                    <span class="mt-wait">Open</span>
                  </li>
                <?php endforeach; ?>
              </ul>
              <?php endif; ?>
            </section>

            <section class="mt-card" aria-label="Closed tasks">
              <div class="mt-card-head">
                <span class="mt-card-ico ok"><i class="bi bi-check-lg" aria-hidden="true"></i></span>
                <div><h2>Closed Tasks (Recent)</h2><p>Your recently completed tasks.</p></div>
                <span class="mt-chip">Last 15</span>
              </div>
              <?php if (!$closed): ?>
                <p class="mt-empty">Nothing finished yet.</p>
              <?php else: ?>
              <ul class="mt-list">
                <?php foreach ($closed as $t): $done = ($t['status'] ?? '') === 'done'; ?>
                  <li class="mt-done">
                    <span class="mt-tick<?php echo $done ? '' : ' bad'; ?>"><i class="bi <?php echo $done ? 'bi-check-lg' : 'bi-x-lg'; ?>" aria-hidden="true"></i></span>
                    <div class="mt-main">
                      <div class="mt-title" style="font-size:1.05rem"><?php echo $esc($t['title'] ?? ''); ?></div>
                      <?php if (!empty($t['details'])): ?><div class="mt-details" style="font-size:.9rem"><?php echo $esc($t['details']); ?></div><?php endif; ?>
                      <?php if (!empty($t['reward']) && $done): ?><div class="mt-reward"><?php echo $esc('+' . $payCur . ' ' . number_format((float)$t['reward'], 2)); ?> pay</div><?php endif; ?>
                    </div>
                    <span class="mt-when"><i class="bi bi-calendar3" aria-hidden="true"></i><span><?php echo $done ? 'Closed' : 'Cancelled'; ?>: <?php echo $lt($t['closed_at'] ?? ''); ?></span></span>
                    <span class="mt-badge <?php echo $done ? 'ok' : 'bad'; ?>"><?php echo $done ? 'Closed' : 'Cancelled'; ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
              <?php endif; ?>
            </section>
          </div>
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
  <script>
  // Show times in this computer's local time
  (function () {
    function pad(v) { return String(v).padStart(2, '0'); }
    document.querySelectorAll('time.mt-time').forEach(function (el) {
      var d = new Date(el.getAttribute('datetime'));
      if (isNaN(d.getTime())) { return; }
      el.textContent = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
        + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    });
  })();
  </script>
</body>
</html>