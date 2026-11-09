<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();

if (!isset($_SESSION['AdminSession'])) {
    if (($_GET['live'] ?? '') === '1') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'login']);
        exit;
    }
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

include_once "../connection.php";
include_once "ui_helpers.php";

/* ---------- Settings (change these if your site is different) ---------- */
$PL_TABLE    = 'premium_requests';   // table that holds premium payments
$PL_CURRENCY = 'PKR ';               // currency shown before amounts
$PL_DAYS     = 90;                   // how many days of history to load
$PL_LIVE_SEC = 5;                    // how often the page refreshes itself (seconds)
// A request whose status contains one of these words counts as LOST money.
$PL_LOST_WORDS   = ['reject', 'refund', 'declin', 'cancel', 'denied', 'deny', 'fail', 'revok'];
// A request whose status contains one of these words is not counted yet.
$PL_IGNORE_WORDS = ['pending', 'waiting', 'review'];
// Everything else (approved, active, paid, completed...) counts as EARNED money.

function pl_status_has(string $status, array $words): bool
{
    foreach ($words as $w) {
        if ($status !== '' && strpos($status, $w) !== false) {
            return true;
        }
    }
    return false;
}

/* ---------- Load the numbers (used by the page and by the live endpoint) ---------- */
function pl_load($pdo, string $table, int $numDays, array $lostWords, array $ignoreWords): array
{
    $days = [];
    for ($i = $numDays - 1; $i >= 0; $i--) {
        $k = date('Y-m-d', strtotime("-$i days"));
        $days[$k] = ['date' => $k, 'earned' => 0.0, 'lost' => 0.0, 'earnedCount' => 0, 'lostCount' => 0];
    }
    $statuses = [];
    $problem = '';
    $columnsUsed = '';

    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $cols = vs_cols($pdo, $table);
            if (!$cols) {
                $problem = "The table '" . $table . "' was not found.";
            } else {
                $amountCol = vs_pick($cols, ['amount', 'price', 'total', 'paid', 'cost', 'payment']);
                $statusCol = vs_pick($cols, ['status', 'state', 'request_status']);
                $dateCol   = vs_pick($cols, ['created_at', 'requested_at', 'created_on', 'date', 'timestamp', 'created']);

                if (!$amountCol || !$dateCol) {
                    $problem = "Could not find an amount or date column in '" . $table . "'.";
                } else {
                    $columnsUsed = 'amount: ' . $amountCol . ', date: ' . $dateCol . ($statusCol ? ', status: ' . $statusCol : ', no status column');
                    $sql = 'SELECT ' . vs_q($amountCol) . ' AS a, '
                         . ($statusCol ? vs_q($statusCol) : "''") . ' AS s, '
                         . vs_q($dateCol) . ' AS d FROM ' . vs_q($table)
                         . ' WHERE ' . vs_q($dateCol) . ' >= :since ORDER BY ' . vs_q($dateCol) . ' ASC LIMIT 100000';
                    $st = $pdo->prepare($sql);
                    $st->execute([':since' => date('Y-m-d 00:00:00', strtotime('-' . ($numDays - 1) . ' days'))]);
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $ts = strtotime((string)$r['d']);
                        if (!$ts) { continue; }
                        $k = date('Y-m-d', $ts);
                        if (!isset($days[$k])) { continue; }
                        $amount = (float)preg_replace('/[^0-9.\-]/', '', (string)$r['a']);
                        $status = strtolower(trim((string)$r['s']));
                        $label  = $status === '' ? '(no status)' : $status;
                        $kind   = pl_status_has($status, $ignoreWords) ? 'not counted'
                                : (pl_status_has($status, $lostWords) ? 'lost' : 'made');
                        if (!isset($statuses[$label])) {
                            $statuses[$label] = ['label' => $label, 'count' => 0, 'kind' => $kind];
                        }
                        $statuses[$label]['count']++;

                        if ($kind === 'lost') {
                            $days[$k]['lost'] += $amount;
                            $days[$k]['lostCount']++;
                        } elseif ($kind === 'made') {
                            $days[$k]['earned'] += $amount;
                            $days[$k]['earnedCount']++;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('profit-loss.php: ' . $e->getMessage());
            $problem = 'Could not read the payments table.';
        }
    } else {
        $problem = 'No database connection.';
    }

    return [
        'days'     => array_values($days),
        'statuses' => array_values($statuses),
        'problem'  => $problem,
        'columns'  => $columnsUsed,
        'updated'  => date('H:i:s'),
    ];
}

$payload = pl_load($pdo ?? null, $PL_TABLE, $PL_DAYS, $PL_LOST_WORDS, $PL_IGNORE_WORDS);

/* ---------- Live endpoint: profit-loss.php?live=1 returns fresh numbers as JSON ---------- */
if (($_GET['live'] ?? '') === '1') {
    session_write_close(); // don't lock the session while polling
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
}

include "header.php";
?>

      <style>
        .pl { --pl-green:#16a34a; --pl-green-2:#4ade80; --pl-red:#dc2626; --pl-red-2:#f87171; --pl-blue:#3b82f6;
              --pl-card:var(--bs-body-bg,#fff); --pl-line:var(--bs-border-color,rgba(120,130,150,.25)); --pl-muted:var(--bs-secondary-color,#6b7280); }
        .pl-card { background:var(--pl-card); border:1px solid var(--pl-line); border-radius:16px; box-shadow:0 1px 2px rgba(16,24,40,.04),0 6px 20px rgba(16,24,40,.05); }
        .pl-card-pad { padding:18px 20px; }
        .pl-title { font-size:.95rem; font-weight:600; margin:0; }

        /* live badge */
        .pl-live { display:inline-flex; align-items:center; gap:8px; padding:5px 12px 5px 10px; border-radius:999px; font-size:.78rem; font-weight:500;
                   background:rgba(34,197,94,.12); color:var(--pl-green); border:1px solid rgba(34,197,94,.3); cursor:pointer; user-select:none; }
        .pl-live .dot { width:8px; height:8px; border-radius:50%; background:#22c55e; position:relative; }
        .pl-live .dot::after { content:""; position:absolute; inset:0; border-radius:50%; background:#22c55e; animation:plPulse 1.6s ease-out infinite; }
        .pl-live.warn { background:rgba(245,158,11,.12); color:#d97706; border-color:rgba(245,158,11,.35); }
        .pl-live.warn .dot, .pl-live.warn .dot::after { background:#f59e0b; }
        .pl-live.paused { background:rgba(148,163,184,.15); color:var(--pl-muted); border-color:var(--pl-line); }
        .pl-live.paused .dot { background:#94a3b8; } .pl-live.paused .dot::after { animation:none; opacity:0; }
        @keyframes plPulse { 0%{transform:scale(1);opacity:.7} 100%{transform:scale(3);opacity:0} }

        /* KPI cards */
        .pl-kpi { position:relative; overflow:hidden; height:100%; }
        .pl-kpi .ico { width:38px; height:38px; border-radius:11px; display:grid; place-items:center; font-size:1.1rem; }
        .pl-kpi .lbl { font-size:.8rem; color:var(--pl-muted); font-weight:500; }
        .pl-kpi .val { font-size:1.55rem; font-weight:700; letter-spacing:-.02em; line-height:1.15; margin-top:10px; font-variant-numeric:tabular-nums; }
        .pl-kpi .sub { font-size:.78rem; color:var(--pl-muted); margin-top:2px; }
        .pl-kpi.g .ico { background:rgba(34,197,94,.14); color:var(--pl-green); }
        .pl-kpi.r .ico { background:rgba(239,68,68,.14); color:var(--pl-red); }
        .pl-kpi.b .ico { background:rgba(59,130,246,.14); color:var(--pl-blue); }
        .pl-kpi.v .ico { background:rgba(139,92,246,.14); color:#8b5cf6; }
        .pl-kpi::after { content:""; position:absolute; right:-30px; top:-30px; width:100px; height:100px; border-radius:50%; opacity:.07; background:currentColor; }
        .pl-kpi.g::after { color:var(--pl-green); } .pl-kpi.r::after { color:var(--pl-red); } .pl-kpi.b::after { color:var(--pl-blue); } .pl-kpi.v::after { color:#8b5cf6; }
        .pl-flash { animation:plFlash 1.2s ease-out; }
        @keyframes plFlash { 0%{background:rgba(59,130,246,.28);border-radius:6px} 100%{background:transparent} }

        /* range pills */
        .pl-range { display:inline-flex; padding:3px; border-radius:11px; background:rgba(148,163,184,.14); gap:2px; }
        .pl-range button { border:0; background:transparent; padding:5px 13px; border-radius:8px; font-size:.8rem; font-weight:500; color:var(--pl-muted); transition:.15s; }
        .pl-range button:hover { color:inherit; }
        .pl-range button.active { background:var(--pl-card); color:inherit; box-shadow:0 1px 4px rgba(0,0,0,.14); }

        /* chart */
        .pl-legend { display:flex; gap:16px; font-size:.8rem; color:var(--pl-muted); align-items:center; flex-wrap:wrap; }
        .pl-legend i { display:inline-block; width:10px; height:10px; border-radius:3px; margin-right:6px; vertical-align:-1px; }
        .pl-chart { position:relative; }
        .pl-chart svg { display:block; width:100%; height:auto; max-height:360px; touch-action:pan-y; }
        .pl-chart .bar { transition:y .7s cubic-bezier(.22,1,.36,1), height .7s cubic-bezier(.22,1,.36,1), opacity .2s; }
        .pl-chart .dotc { transition:cx .7s cubic-bezier(.22,1,.36,1), cy .7s cubic-bezier(.22,1,.36,1), r .15s; }
        .pl-chart .dim { opacity:.35; }
        .pl-chart .pctl { font-size:11px; font-weight:700; text-anchor:middle; paint-order:stroke; stroke:#06152d; stroke-width:3px; stroke-linejoin:round; pointer-events:none; font-variant-numeric:tabular-nums; }
        html[data-theme="light"] .pl-chart .pctl, html[data-bs-theme="light"] .pl-chart .pctl { stroke:#fff; }
        .pl-pcttoggle { border:1px solid var(--pl-line); background:transparent; color:var(--pl-muted); border-radius:999px; padding:2px 10px; font-size:.75rem; cursor:pointer; }
        .pl-pcttoggle.on { background:rgba(138,92,255,.25); color:inherit; border-color:rgba(138,92,255,.6); }
        .pl-tip { position:absolute; pointer-events:none; z-index:5; min-width:170px; padding:10px 12px; border-radius:12px; font-size:.8rem; line-height:1.5;
                  background:rgba(15,23,42,.94); color:#f1f5f9; box-shadow:0 10px 30px rgba(0,0,0,.25); opacity:0; transform:translateY(4px); transition:opacity .12s, transform .12s; }
        .pl-tip.show { opacity:1; transform:none; }
        .pl-tip b { display:block; margin-bottom:4px; font-size:.82rem; }
        .pl-tip .row2 { display:flex; justify-content:space-between; gap:14px; }
        .pl-tip .sw { display:inline-block; width:8px; height:8px; border-radius:2px; margin-right:6px; }

        .pl-sums { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-top:14px; }
        .pl-sums > div { text-align:center; padding:10px 6px; border-radius:12px; background:rgba(148,163,184,.08); }
        .pl-sums .t { font-size:.75rem; color:var(--pl-muted); } .pl-sums .n { font-weight:700; font-variant-numeric:tabular-nums; }

        /* table */
        .pl-table { width:100%; border-collapse:separate; border-spacing:0; font-size:.88rem; }
        .pl-table th { font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:var(--pl-muted); font-weight:600; padding:8px 10px; border-bottom:1px solid var(--pl-line); position:sticky; top:0; background:var(--pl-card); }
        .pl-table td { padding:9px 10px; border-bottom:1px solid var(--pl-line); font-variant-numeric:tabular-nums; }
        .pl-table tbody tr:hover td { background:rgba(148,163,184,.08); }
        .pl-table tbody tr:last-child td { border-bottom:0; }
        .pl-scroll { max-height:380px; overflow:auto; }
        .pl-bar { display:inline-block; vertical-align:middle; width:52px; height:6px; border-radius:99px; background:rgba(239,68,68,.25); margin-left:8px; overflow:hidden; }
        .pl-bar > i { display:block; height:100%; background:var(--pl-green); border-radius:99px; transition:width .6s; }

        /* status list */
        .pl-st { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px 0; border-bottom:1px dashed var(--pl-line); font-size:.88rem; }
        .pl-st:last-child { border-bottom:0; }
        .pl-chip { font-size:.7rem; font-weight:600; padding:2px 9px; border-radius:99px; text-transform:uppercase; letter-spacing:.03em; }
        .pl-chip.made { background:rgba(34,197,94,.14); color:var(--pl-green); }
        .pl-chip.lost { background:rgba(239,68,68,.14); color:var(--pl-red); }
        .pl-chip.skip { background:rgba(148,163,184,.2); color:var(--pl-muted); }
        .pl-hero-icon { width:48px; height:48px; border-radius:14px; display:grid; place-items:center; font-size:1.4rem; color:#fff; background:linear-gradient(135deg,#22c55e,#3b82f6); box-shadow:0 8px 20px rgba(34,197,94,.3); }
        @media (max-width:575px){ .pl-kpi .val{font-size:1.25rem} .pl-card-pad{padding:14px} }
        @media (prefers-reduced-motion:reduce){ .pl *, .pl *::after { animation:none !important; transition:none !important; } }

        .pl { --pl-green:#00d6a2; --pl-green-2:#00e5ad; --pl-red:#ff3f70; --pl-red-2:#ff547e; --pl-blue:#357dff;
              --pl-card:#06152d; --pl-line:#12345b; --pl-muted:#89a5c9; color:#eaf2ff; }
        .pl-card { background:linear-gradient(145deg,rgba(7,24,49,.98),rgba(4,16,36,.98)); border-color:var(--pl-line); border-radius:10px;
                   box-shadow:0 8px 26px rgba(0,0,0,.12); }
        .pl-card-pad { padding:16px; }
        .pl-title { color:#edf4ff; font-size:.9rem; }
        .pl .text-muted, .pl-kpi .lbl, .pl-kpi .sub { color:var(--pl-muted)!important; }
        .pl-hero-icon { width:42px; height:42px; border-radius:10px; background:linear-gradient(145deg,#a855f7,#7138ed); box-shadow:0 8px 22px rgba(124,58,237,.3); }
        .pl h1 { color:#f2f6ff; font-size:1.28rem!important; font-weight:700; }
        .pl .pl-heading-copy p:last-child { color:#8ba8cf!important; }
        .pl-live { background:rgba(0,214,162,.08); border-color:rgba(0,214,162,.26); color:#00d6a2; }
        .pl-range { background:#071a35; border:1px solid #17365e; border-radius:8px; padding:3px; }
        .pl-range button { color:#91a9ca; padding:6px 10px; }
        .pl-range button.active { background:#6238ed; color:#fff; box-shadow:none; }
        .pl-kpi { min-height:112px; }
        .pl-kpi .ico { width:38px; height:38px; border-radius:50%; color:#fff!important; }
        .pl-kpi.g .ico { background:linear-gradient(145deg,#00d6a2,#009b83); }
        .pl-kpi.r .ico { background:linear-gradient(145deg,#ff4d78,#e91e58); }
        .pl-kpi.b .ico { background:linear-gradient(145deg,#438cff,#2456e8); }
        .pl-kpi.v .ico { background:linear-gradient(145deg,#8b5cf6,#6132ef); }
        .pl-kpi.g { border-color:rgba(0,214,162,.4); }
        .pl-kpi.r { border-color:rgba(255,63,112,.38); }
        .pl-kpi.b { border-color:rgba(53,125,255,.38); }
        .pl-kpi.v { border-color:rgba(139,92,246,.38); }
        .pl-kpi .val { margin-top:8px; font-size:1.3rem; color:#f2f6ff!important; }
        .pl-kpi.g .val { color:#fff!important; }
        .pl-legend { color:#a5bad8; font-size:.75rem; }
        .pl-legend i { border-radius:50%; }
        .pl-sums > div { background:#081b37; border:1px solid rgba(18,52,91,.65); border-radius:8px; }
        .pl-sums .n { color:#f3f7ff; }
        .pl-table { color:#d8e5f7; font-size:.82rem; }
        .pl-table th { color:#91a9ca; background:#06152d; }
        .pl-table td { border-color:rgba(18,52,91,.7); }
        .pl-table tbody tr:hover td { background:rgba(57,112,183,.12); }
        .pl-scroll { max-height:360px; }
        .pl-st { color:#d8e5f7; border-color:rgba(18,52,91,.7); }
        .pl-chip.made { color:#00d6a2; }
        .pl-chip.lost { color:#ff547e; }
        .pl-chip.skip { color:#a8bad4; }
        .pl-chart svg { max-height:310px; }
        .pl-chart .bar { display:none; }
        .pl-donut-layout { min-height:220px; display:flex; align-items:center; justify-content:center; gap:22px; }
        .pl-donut { width:150px; height:150px; flex:0 0 150px; border-radius:50%; display:grid; place-items:center;
                    background:conic-gradient(var(--pl-green) 0deg, var(--pl-green) 0deg, var(--pl-red) 0deg 360deg); }
        .pl-donut-hole { width:112px; height:112px; border-radius:50%; background:#06152d; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; }
        .pl-donut-hole strong { color:#f3f7ff; font-size:1rem; }
        .pl-donut-hole small { color:var(--pl-muted); font-size:.7rem; margin-top:2px; }
        .pl-donut-legend { min-width:115px; }
        .pl-donut-legend > div { display:grid; grid-template-columns:10px 1fr; align-items:center; column-gap:8px; margin:10px 0; }
        .pl-donut-legend i { width:9px; height:9px; border-radius:50%; grid-row:span 2; }
        .pl-donut-legend span { color:#a9bdd9; font-size:.75rem; }
        .pl-donut-legend strong { color:#f0f5ff; font-size:.85rem; }
        .pl-donut-legend small { color:var(--pl-muted); font-size:.7rem; }
        html[data-theme="light"] .pl { color:#18263b; --pl-card:#fff; --pl-line:#dce5f0; --pl-muted:#667991; }
        html[data-theme="light"] .pl-card { background:#fff; box-shadow:0 5px 20px rgba(30,55,90,.07); }
        html[data-theme="light"] .pl h1, html[data-theme="light"] .pl-title, html[data-theme="light"] .pl-table,
        html[data-theme="light"] .pl-st, html[data-theme="light"] .pl-kpi .val { color:#18263b!important; }
        html[data-theme="light"] .pl-range { background:#f0f4fa; border-color:#dce5f0; }
        html[data-theme="light"] .pl-range button.active { color:#fff; }
        html[data-theme="light"] .pl-sums > div { background:#f5f8fc; border-color:#e4ebf4; }
        html[data-theme="light"] .pl-sums .n, html[data-theme="light"] .pl-donut-legend strong { color:#18263b; }
        html[data-theme="light"] .pl-table th { background:#fff; }
        html[data-theme="light"] .pl-donut-hole { background:#fff; }
        html[data-theme="light"] .pl-donut-hole strong { color:#18263b; }
        html[data-theme="light"] .pl-donut-legend span { color:#53657d; }
        @media (max-width:575px) {
          .pl-donut-layout { min-height:0; gap:15px; padding:12px 0 4px; }
          .pl-donut { width:125px; height:125px; flex-basis:125px; }
          .pl-donut-hole { width:93px; height:93px; }
          .pl-range button { padding:6px 8px; }
          .pl-sums { gap:5px; }
        }
      

/* ===== NEON THEME (dark + light) - same look as creator-analytics.php ===== */
html:not([data-bs-theme="light"]):not([data-theme="light"]){
  --nx-cyan:#00e5ff;--nx-pink:#ff2e93;--nx-violet:#8a5cff;--nx-green:#22e58a;--nx-amber:#ffd23f;--nx-red:#ff4d6d;
  --nx-cyan-rgb:0,229,255;--nx-pink-rgb:255,46,147;--nx-violet-rgb:138,92,255;--nx-green-rgb:34,229,138;--nx-amber-rgb:255,210,63;--nx-red-rgb:255,77,109;
  --nx-cyan-ink:#00e5ff;--nx-pink-ink:#ff7ac0;--nx-muted:#8f9bd0;
  --nx-text:#eaf0ff;--nx-card:rgba(10,13,31,.88);--nx-solid:#0a0d1f;--nx-field:rgba(10,13,31,.9);
  --nx-track:#05060f;--nx-soft:#121634;--nx-active:#fff;
  --nx-page:radial-gradient(900px 480px at 8% -8%,rgba(138,92,255,.16),transparent 70%),radial-gradient(800px 420px at 100% 0,rgba(0,229,255,.10),transparent 70%),radial-gradient(700px 500px at 50% 110%,rgba(255,46,147,.08),transparent 70%),#05060f;
}
html[data-bs-theme="light"],html[data-theme="light"]{
  --nx-cyan:#00b8e6;--nx-pink:#ff2e93;--nx-violet:#7c4dff;--nx-green:#0fb86a;--nx-amber:#f59e0b;--nx-red:#e11d63;
  --nx-cyan-rgb:0,184,230;--nx-pink-rgb:255,46,147;--nx-violet-rgb:124,77,255;--nx-green-rgb:15,184,106;--nx-amber-rgb:245,158,11;--nx-red-rgb:225,29,99;
  --nx-cyan-ink:#0086b3;--nx-pink-ink:#d4147a;--nx-muted:#55608f;
  --nx-text:#0b1030;--nx-card:#ffffff;--nx-solid:#ffffff;--nx-field:#ffffff;
  --nx-track:#eceffd;--nx-soft:#eceffd;--nx-active:#0b1030;
  --nx-page:radial-gradient(900px 480px at 8% -8%,rgba(124,77,255,.14),transparent 70%),radial-gradient(800px 420px at 100% 0,rgba(0,184,230,.14),transparent 70%),radial-gradient(700px 500px at 50% 110%,rgba(255,46,147,.10),transparent 70%),#f4f6ff;
}
html body{background:var(--nx-page);background-attachment:fixed}
html body ::-webkit-scrollbar{width:10px;height:10px}
html body ::-webkit-scrollbar-track{background:var(--nx-track)}
html body ::-webkit-scrollbar-thumb{background:linear-gradient(180deg,var(--nx-violet),var(--nx-pink));border-radius:8px}
html body ::selection{background:rgba(var(--nx-pink-rgb),.4);color:var(--nx-text)}

/* shared admin controls */
html body .panel{background:var(--nx-card);border:1px solid rgba(var(--nx-violet-rgb),.4);box-shadow:0 0 26px -10px rgba(var(--nx-violet-rgb),.55)}
html body .form-control,html body .form-select{background-color:var(--nx-field);color:var(--nx-text);border:1px solid rgba(var(--nx-violet-rgb),.55);box-shadow:0 0 10px -2px rgba(var(--nx-violet-rgb),.45);transition:box-shadow .2s ease,border-color .2s ease}
html body .form-control::placeholder{color:var(--nx-muted);opacity:.8}
html body .form-control:focus,html body .form-select:focus{background-color:var(--nx-field);color:var(--nx-text);outline:none;border-color:var(--nx-cyan);box-shadow:0 0 0 1px var(--nx-cyan),0 0 20px rgba(var(--nx-cyan-rgb),.55)}
html body .btn-primary{background:linear-gradient(90deg,var(--nx-violet),var(--nx-pink));border:0;color:#fff;box-shadow:0 0 20px rgba(var(--nx-pink-rgb),.5);transition:box-shadow .18s ease,transform .18s ease}
html body .btn-primary:hover,html body .btn-primary:focus{background:linear-gradient(90deg,var(--nx-violet),var(--nx-pink));color:#fff;transform:translateY(-1px);box-shadow:0 0 30px rgba(var(--nx-pink-rgb),.85)}
html body .btn-outline-secondary{background:rgba(var(--nx-violet-rgb),.14);color:var(--nx-text);border:1px solid rgba(var(--nx-violet-rgb),.55)}
html body .btn-outline-secondary:hover{background:rgba(var(--nx-cyan-rgb),.12);color:var(--nx-text);border-color:var(--nx-cyan);box-shadow:0 0 14px rgba(var(--nx-cyan-rgb),.5)}
html body .modal-content{background:var(--nx-solid);color:var(--nx-text);border:1px solid rgba(var(--nx-cyan-rgb),.6);box-shadow:0 0 36px -6px rgba(var(--nx-cyan-rgb),.55),0 0 70px -24px rgba(var(--nx-violet-rgb),.7)}
html body .modal-header,html body .modal-footer{border-color:rgba(var(--nx-violet-rgb),.3)}
html body .modal-title{text-shadow:0 0 12px rgba(var(--nx-violet-rgb),.5)}
html[data-bs-theme="dark"] body .btn-close,html:not([data-bs-theme="light"]):not([data-theme="light"]) body .btn-close{filter:invert(1) grayscale(1) brightness(1.6)}
html body .alert-success{color:var(--nx-text);background:rgba(var(--nx-green-rgb),.12);border:1px solid rgba(var(--nx-green-rgb),.55);box-shadow:0 0 20px -6px rgba(var(--nx-green-rgb),.55)}
html body .alert-danger{color:var(--nx-text);background:rgba(var(--nx-pink-rgb),.12);border:1px solid rgba(var(--nx-pink-rgb),.6);box-shadow:0 0 20px -6px rgba(var(--nx-pink-rgb),.55)}
@media (prefers-reduced-motion:reduce){html body .ct-card,html body .ct-tab,html body .ct-quick,html body .btn-primary{transition:none}html body .ct-card:hover,html body .btn-primary:hover{transform:none}}

/* success chart (profit / loss) */
html body main.dashboard-content{background:var(--nx-page)!important}
html body .pl{--pl-green:var(--nx-green);--pl-red:var(--nx-red);--pl-blue:var(--nx-cyan);--pl-card:var(--nx-card);--pl-line:rgba(var(--nx-violet-rgb),.35);--pl-muted:var(--nx-muted)}
html body .pl .pl-card{background:var(--nx-card);border:1px solid rgba(var(--nx-violet-rgb),.4);box-shadow:0 0 26px -10px rgba(var(--nx-violet-rgb),.55);transition:box-shadow .18s ease,border-color .18s ease}
html body .pl .pl-card:hover{border-color:var(--nx-cyan);box-shadow:0 0 26px -4px rgba(var(--nx-cyan-rgb),.55),0 0 50px -16px rgba(var(--nx-violet-rgb),.55)}
html body .pl .pl-title{text-shadow:0 0 12px rgba(var(--nx-violet-rgb),.5)}
html body .pl .pl-live{background:rgba(var(--nx-green-rgb),.12);color:var(--nx-green);border:1px solid rgba(var(--nx-green-rgb),.55);box-shadow:0 0 16px -4px rgba(var(--nx-green-rgb),.6)}
html body .pl .pl-live .dot,html body .pl .pl-live .dot::after{background:var(--nx-green)}
html body .pl .pl-live .dot{box-shadow:0 0 10px var(--nx-green)}
html body .pl .pl-live.warn{background:rgba(var(--nx-amber-rgb),.12);color:var(--nx-amber);border-color:rgba(var(--nx-amber-rgb),.6);box-shadow:0 0 16px -4px rgba(var(--nx-amber-rgb),.6)}
html body .pl .pl-live.warn .dot,html body .pl .pl-live.warn .dot::after{background:var(--nx-amber)}
html body .pl .pl-kpi.g{--kc:var(--nx-green)}
html body .pl .pl-kpi.r{--kc:var(--nx-red)}
html body .pl .pl-kpi.b{--kc:var(--nx-cyan)}
html body .pl .pl-kpi.v{--kc:var(--nx-violet)}
html body .pl .pl-kpi{border-top:3px solid var(--kc,var(--nx-violet))}
html body .pl .pl-kpi .ico{color:var(--kc);background:rgba(var(--nx-violet-rgb),.14);border:1px solid var(--kc);box-shadow:0 0 12px -2px var(--kc)}
html body .pl .pl-kpi::before{content:"";position:absolute;left:0;right:0;top:0;height:3px;background:var(--kc,var(--nx-violet));box-shadow:0 0 16px 2px var(--kc,var(--nx-violet))}
html body .pl .pl-kpi .val{text-shadow:0 0 14px rgba(var(--nx-cyan-rgb),.45)}
html body .pl .pl-flash{animation:none}
html body .pl .pl-range{background:rgba(var(--nx-violet-rgb),.14);border:1px solid rgba(var(--nx-violet-rgb),.4)}
html body .pl .pl-range button:hover{color:var(--nx-cyan-ink)}
html body .pl .pl-range button.active{background:linear-gradient(90deg,var(--nx-violet),var(--nx-pink));color:#fff;box-shadow:0 0 14px rgba(var(--nx-pink-rgb),.6)}
html body .pl .pl-chart svg{filter:drop-shadow(0 0 6px rgba(var(--nx-violet-rgb),.45))}
html body .pl .pl-tip{background:rgba(10,13,31,.96);color:#eaf0ff;border:1px solid rgba(var(--nx-cyan-rgb),.55);box-shadow:0 0 24px -4px rgba(var(--nx-cyan-rgb),.55)}
html body .pl .pl-sums > div{background:rgba(var(--nx-violet-rgb),.10);border:1px solid rgba(var(--nx-violet-rgb),.3)}
html body .pl .pl-table th{color:var(--nx-cyan-ink);text-shadow:0 0 8px rgba(var(--nx-cyan-rgb),.35)}
html body .pl .pl-table tbody tr:hover td{background:rgba(var(--nx-cyan-rgb),.07)}
html body .pl .pl-table tbody tr:hover td:first-child{box-shadow:inset 3px 0 0 var(--nx-cyan)}
html body .pl .pl-bar > i{box-shadow:0 0 10px rgba(var(--nx-green-rgb),.7)}
@media (prefers-reduced-motion:reduce){html body .pl .pl-card{transition:none}}
</style>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4 pl">

          <!-- Heading -->
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
              <span class="pl-hero-icon"><i class="bi bi-bar-chart-line-fill" aria-hidden="true"></i></span>
              <div class="pl-heading-copy">
                <h1 class="h3 mb-0">Today's Profit &amp; Loss</h1>
                <p class="text-muted small mb-0">Track your earnings and losses in real time.</p>
              </div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
              <span class="pl-live" id="plLive" role="button" tabindex="0" title="Updates every <?php echo (int)$PL_LIVE_SEC; ?> seconds. Click to pause / resume.">
                <span class="dot" id="plDot"></span><span id="plLiveText">Live</span>
              </span>
              <div class="pl-range" id="plRange" role="group" aria-label="Chart range">
                <button type="button" data-days="7">7d</button>
                <button type="button" data-days="14" class="active">14d</button>
                <button type="button" data-days="30">30d</button>
                <button type="button" data-days="90">90d</button>
              </div>
            </div>
          </div>

          <div class="alert alert-warning mt-3" id="plProblem" style="display:none"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> <span id="plProblemText"></span></div>

          <!-- Today KPIs -->
          <div class="row g-3 mt-1">
            <div class="col-6 col-lg-3"><div class="pl-card pl-card-pad pl-kpi g">
              <div class="d-flex justify-content-between align-items-start"><span class="lbl">Total earned today</span><span class="ico"><i class="bi bi-arrow-up-right"></i></span></div>
              <div class="val" id="plTE" style="color:var(--pl-green)">&ndash;</div><div class="sub" id="plTEc">&nbsp;</div>
            </div></div>
            <div class="col-6 col-lg-3"><div class="pl-card pl-card-pad pl-kpi r">
              <div class="d-flex justify-content-between align-items-start"><span class="lbl">Total lost today</span><span class="ico"><i class="bi bi-arrow-down-right"></i></span></div>
              <div class="val" id="plTL" style="color:var(--pl-red)">&ndash;</div><div class="sub" id="plTLc">&nbsp;</div>
            </div></div>
            <div class="col-6 col-lg-3"><div class="pl-card pl-card-pad pl-kpi b">
              <div class="d-flex justify-content-between align-items-start"><span class="lbl">Net profit today</span><span class="ico"><i class="bi bi-wallet2"></i></span></div>
              <div class="val" id="plTN">&ndash;</div><div class="sub" id="plTNs">made minus lost</div>
            </div></div>
            <div class="col-6 col-lg-3"><div class="pl-card pl-card-pad pl-kpi v">
              <div class="d-flex justify-content-between align-items-start"><span class="lbl">Transactions today</span><span class="ico"><i class="bi bi-receipt"></i></span></div>
              <div class="val" id="plTR">&ndash;</div><div class="sub">transactions today</div>
            </div></div>
          </div>

          <div class="row g-3 mt-1">
            <div class="col-lg-8">
              <div class="pl-card pl-card-pad h-100">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                  <h2 class="pl-title">Earnings vs Losses</h2>
                  <div class="pl-legend">
                    <span><i style="background:var(--pl-green)"></i>Earned</span>
                    <span><i style="background:var(--pl-red)"></i>Lost</span>
                    <button type="button" class="pl-pcttoggle on" id="plPctBtn" title="Show or hide the earned / lost % on each day">% Share</button>
                  </div>
                </div>
                <div class="pl-chart" id="plChartWrap">
                  <div id="plChart" role="img" aria-label="Live line chart of money earned and lost per day"></div>
                  <div class="pl-tip" id="plTip"></div>
                </div>
                <div class="pl-sums">
                  <div><div class="t">Earned (range)</div><div class="n" id="plSumE" style="color:var(--pl-green)"></div></div>
                  <div><div class="t">Lost (range)</div><div class="n" id="plSumL" style="color:var(--pl-red)"></div></div>
                  <div><div class="t">Net (range)</div><div class="n" id="plSumN"></div></div>
                </div>
              </div>
            </div>
            <div class="col-lg-4">
              <div class="pl-card pl-card-pad h-100">
                <h2 class="pl-title mb-1">Breakdown</h2>
                <p class="text-muted small mb-0">Earned and lost in selected range</p>
                <div class="pl-donut-layout">
                  <div class="pl-donut" id="plDonut" role="img" aria-label="Earned versus lost breakdown">
                    <div class="pl-donut-hole"><strong id="plDonutTotal">PKR 0</strong><small>Total activity</small></div>
                  </div>
                  <div class="pl-donut-legend">
                    <div><i style="background:var(--pl-green)"></i><span>Earned</span><strong id="plDonutEarned">PKR 0</strong><small id="plDonutEarnedPct">0%</small></div>
                    <div><i style="background:var(--pl-red)"></i><span>Lost</span><strong id="plDonutLost">PKR 0</strong><small id="plDonutLostPct">0%</small></div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="row g-3 mt-1">
            <div class="col-lg-8">
              <div class="pl-card pl-card-pad h-100">
                <h2 class="pl-title mb-2">Detailed breakdown</h2>
                <div class="pl-scroll">
                  <table class="pl-table">
                    <thead><tr><th>Day</th><th class="text-end">Made</th><th class="text-end">Lost</th><th class="text-end">Net</th><th class="text-end">Success</th></tr></thead>
                    <tbody id="plRows"></tbody>
                  </table>
                </div>
              </div>
            </div>
            <div class="col-lg-4">
              <div class="pl-card pl-card-pad h-100">
                <h2 class="pl-title">How requests were counted</h2>
                <p class="text-muted small">Last <?php echo (int)$PL_DAYS; ?> days, grouped by status.</p>
                <div id="plStatus"></div>
                <p class="text-muted small mb-0 mt-2" id="plCols"></p>
              </div>
            </div>
          </div>
        </div>
      </main>

      <footer class="admin-footer">
        <div class="container-fluid px-3 px-lg-4">
          <span>  Developed by <a href="https://M.Awais.com" target="_blank" rel="noopener">Muhammad Awais</a> </span>
        </div>
      </footer>
    </div>
  </div>

  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
  <script>
  (function () {
    var DATA = <?php echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var CUR = <?php echo json_encode($PL_CURRENCY, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var LIVE_MS = <?php echo (int)$PL_LIVE_SEC * 1000; ?>;
    var GREEN = '#00d6a2', RED = '#ff3f70', BLUE = '#357dff';
    var RANGE_STORAGE_KEY = 'vibestream-profit-loss-range';
    var showPct = true, range = 14, paused = false, lastOk = Date.now(), lastSig = '';
    try {
      var savedRange = Number(window.localStorage.getItem(RANGE_STORAGE_KEY));
      if ([7, 14, 30, 90].indexOf(savedRange) !== -1) { range = savedRange; }
    } catch (e) {
      console.warn('Could not restore the profit and loss chart range.', e);
    }
    var NS = 'http://www.w3.org/2000/svg';

    function $(id) { return document.getElementById(id); }
    function money(n) { return CUR + Math.abs(n).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
    function signed(n) { return (n < 0 ? '-' : '+') + money(n); }
    function short(n) { var a = Math.abs(n), s = n < 0 ? '-' : ''; return s + (a >= 1000 ? (a / 1000).toFixed(a >= 10000 ? 0 : 1) + 'k' : String(Math.round(a))); }
    function niceMax(v) { if (v <= 0) { return 10; } var p = Math.pow(10, Math.floor(Math.log10(v))), f = v / p; return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * p; }
    function label(d) { var p = d.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]).toLocaleDateString(undefined, {month: 'short', day: 'numeric'}); }
    function longLabel(d) { var p = d.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]).toLocaleDateString(undefined, {weekday: 'short', month: 'short', day: 'numeric'}); }
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); }
    function color(n) { return n >= 0 ? GREEN : RED; }

    /* set text; flash when value changed after first paint */
    function setText(el, txt) {
      if (el.textContent === txt) { return; }
      var had = el.getAttribute('data-set');
      el.textContent = txt;
      el.setAttribute('data-set', '1');
      if (had) { el.classList.remove('pl-flash'); void el.offsetWidth; el.classList.add('pl-flash'); }
    }
    /* animated number */
    function tween(el, to, fmt) {
      var from = el._v == null ? 0 : el._v;
      el._v = to;
      if (from === to) { el.textContent = fmt(to); return; }
      var t0 = performance.now(), dur = 700;
      if (el._raf) { cancelAnimationFrame(el._raf); }
      (function step(t) {
        var p = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - p, 3);
        el.textContent = fmt(from + (to - from) * e);
        if (p < 1) { el._raf = requestAnimationFrame(step); }
      })(t0);
      if (el._seen) { el.classList.remove('pl-flash'); void el.offsetWidth; el.classList.add('pl-flash'); }
      el._seen = true;
    }

    function renderToday() {
      var t = DATA.days[DATA.days.length - 1];
      if (!t) { return; }
      var net = t.earned - t.lost, tot = t.earned + t.lost;
      tween($('plTE'), t.earned, money);
      tween($('plTL'), t.lost, money);
      tween($('plTN'), net, signed);
      $('plTN').style.color = color(net);
      tween($('plTR'), t.earnedCount + t.lostCount, function (v) { return String(Math.round(v)); });
      setText($('plTEc'), t.earnedCount + ' payment' + (t.earnedCount === 1 ? '' : 's'));
      setText($('plTLc'), t.lostCount + ' rejected / refunded');
    }

    /* ---------- Live chart (built once per range, then updated in place) ---------- */
    var W = 900, H = 330, L = 54, R = 14, T = 14, B = 36, pw = W - L - R, ph = H - T - B;
    var C = null; // chart state
    function mk(tag, attrs, parent) {
      var e = document.createElementNS(NS, tag);
      for (var k in attrs) { e.setAttribute(k, attrs[k]); }
      if (parent) { parent.appendChild(e); }
      return e;
    }
    function buildChart(n) {
      var box = $('plChart'); box.innerHTML = '';
      var svg = mk('svg', {viewBox: '0 0 ' + W + ' ' + H, preserveAspectRatio: 'xMidYMid meet'}, box);
      var defs = mk('defs', {}, svg);
      [['plgE', '#00d6a2', '#00d6a2'], ['plgL', '#ff3f70', '#ff3f70']].forEach(function (g) {
        var lg = mk('linearGradient', {id: g[0], x1: 0, y1: 0, x2: 0, y2: 1}, defs);
        mk('stop', {offset: '0%', 'stop-color': g[1], 'stop-opacity': .20}, lg); mk('stop', {offset: '100%', 'stop-color': g[2], 'stop-opacity': 0}, lg);
      });
      C = {n: n, grid: [], gl: [], earnedDots: [], lostDots: [], pointsE: [], pointsL: [], slot: pw / Math.max(1, n - 1)};
      for (var g = 0; g <= 4; g++) {
        C.grid.push(mk('line', {x1: L, x2: W - R, stroke: 'currentColor', 'stroke-opacity': .1, 'stroke-dasharray': '3 4'}, svg));
        C.gl.push(mk('text', {x: L - 8, 'text-anchor': 'end', 'font-size': 11, fill: 'currentColor', 'fill-opacity': .55}, svg));
      }
      C.hover = mk('rect', {y: T, height: ph, width: C.slot, fill: 'currentColor', 'fill-opacity': 0, rx: 6}, svg);
      var every = Math.ceil(n / 12);
      for (var i = 0; i < n; i++) {
        var cx = n === 1 ? (L + W - R) / 2 : L + (W - L - R) * i / (n - 1);
        var tx = null;
        if (i % every === 0 || i === n - 1) {
          tx = mk('text', {x: cx, y: H - 13, 'text-anchor': 'middle', 'font-size': 11, fill: 'currentColor', 'fill-opacity': .55}, svg);
        }
        C.pointsE.push({x: cx, y: T + ph});
        C.pointsL.push({x: cx, y: T + ph});
        C.earnedDots.push(mk('circle', {class: 'dotc', r: n > 45 ? 0 : 3.2, fill: 'var(--pl-card, #fff)', stroke: '#00d6a2', 'stroke-width': 2, cx: cx, cy: T + ph}, svg));
        C.lostDots.push(mk('circle', {class: 'dotc', r: n > 45 ? 0 : 3.2, fill: 'var(--pl-card, #fff)', stroke: '#ff3f70', 'stroke-width': 2, cx: cx, cy: T + ph}, svg));
        if (tx) { tx.setAttribute('data-index', i); }
      }
      C.areaE = mk('path', {fill: 'url(#plgE)', stroke: 'none'}, svg);
      C.areaL = mk('path', {fill: 'url(#plgL)', stroke: 'none'}, svg);
      C.lineE = mk('path', {fill: 'none', stroke: '#00d6a2', 'stroke-width': 3, 'stroke-linejoin': 'round', 'stroke-linecap': 'round'}, svg);
      C.lineL = mk('path', {fill: 'none', stroke: '#ff3f70', 'stroke-width': 3, 'stroke-linejoin': 'round', 'stroke-linecap': 'round'}, svg);
      C.earnedDots.concat(C.lostDots).forEach(function (dot) { svg.appendChild(dot); });
      C.pct = [];
      var pEvery = n <= 14 ? 1 : Math.ceil(n / 14);
      for (var q = 0; q < n; q++) {
        var pt = mk('text', {class: 'pctl', x: C.pointsE[q].x, y: T + ph - 10}, svg);
        pt.setAttribute('data-show', (q % pEvery === 0 || q === n - 1) ? '1' : '0');
        C.pct.push(pt);
      }
      C.pctL = [];
      for (var q2 = 0; q2 < n; q2++) {
        var pl = mk('text', {class: 'pctl', x: C.pointsL[q2].x, y: T + ph - 10}, svg);
        pl.setAttribute('data-show', C.pct[q2].getAttribute('data-show'));
        C.pctL.push(pl);
      }
      C.svg = svg;

      function idxAt(ev) {
        var r = svg.getBoundingClientRect();
        var x = (ev.clientX - r.left) / r.width * W;
        var fraction = Math.max(0, Math.min(1, (x - L) / (W - L - R)));
        return Math.max(0, Math.min(C.n - 1, Math.round(fraction * (C.n - 1))));
      }
      function showTip(ev) {
        var i = idxAt(ev), d = C.data[i]; if (!d) { return; }
        var net = d.earned - d.lost, tot = d.earned + d.lost;
        var tip = $('plTip');
        tip.innerHTML = '<b>' + esc(longLabel(d.date)) + '</b>' +
          '<div class="row2"><span><i class="sw" style="background:#00d6a2"></i>Earned</span><span>' + money(d.earned) + ' <small style="opacity:.6">(' + d.earnedCount + ')</small></span></div>' +
          '<div class="row2"><span><i class="sw" style="background:#ff3f70"></i>Lost</span><span>' + money(d.lost) + ' <small style="opacity:.6">(' + d.lostCount + ')</small></span></div>' +
          '<div class="row2"><span>Net</span><span style="color:' + (net >= 0 ? '#00d6a2' : '#ff547e') + '">' + signed(net) + '</span></div>' +
          '<div class="row2" style="opacity:.7"><span>Success</span><span>' + (tot > 0 ? Math.round(d.earned / tot * 100) + '%' : '–') + '</span></div>';
        var wrap = $('plChartWrap').getBoundingClientRect(), r = svg.getBoundingClientRect();
        var px = r.left - wrap.left + C.pointsE[i].x / W * r.width;
        var tw = tip.offsetWidth, left = px + 16;
        if (left + tw > wrap.width) { left = px - tw - 16; }
        tip.style.left = Math.max(0, left) + 'px';
        tip.style.top = '12px';
        tip.classList.add('show');
        C.hover.setAttribute('x', Math.max(L, C.pointsE[i].x - C.slot / 2));
        C.hover.setAttribute('width', C.slot);
        C.hover.setAttribute('fill-opacity', .07);
        C.earnedDots.forEach(function (dot, k) { dot.setAttribute('r', k === i ? 5 : (C.n > 45 ? 0 : 2.5)); });
        C.lostDots.forEach(function (dot, k) { dot.setAttribute('r', k === i ? 5 : (C.n > 45 ? 0 : 2.5)); });
      }
      function hideTip() {
        $('plTip').classList.remove('show');
        C.hover.setAttribute('fill-opacity', 0);
        C.earnedDots.forEach(function (dot) { dot.setAttribute('r', C.n > 45 ? 0 : 3.2); });
        C.lostDots.forEach(function (dot) { dot.setAttribute('r', C.n > 45 ? 0 : 3.2); });
      }
      svg.addEventListener('pointermove', showTip);
      svg.addEventListener('pointerdown', showTip);
      svg.addEventListener('pointerleave', hideTip);
    }

    function renderChart() {
      var data = DATA.days.slice(-range);
      var fresh = !C || C.n !== data.length;
      if (fresh) { buildChart(data.length); }
      C.data = data;
      var maxV = 0, sumE = 0, sumL = 0;
      data.forEach(function (d) {
        maxV = Math.max(maxV, d.earned, d.lost);
        sumE += d.earned; sumL += d.lost;
      });
      var top = niceMax(maxV), bottom = 0, span = top;
      function y(v) { return T + (top - v) / span * ph; }

      function linePath(points) {
        if (!points.length) { return ''; }
        var d = 'M' + points[0].x + ',' + points[0].y;
        for (var i = 0; i < points.length - 1; i++) {
          var p0 = points[Math.max(0, i - 1)], p1 = points[i], p2 = points[i + 1], p3 = points[Math.min(points.length - 1, i + 2)];
          var dx = (p2.x - p1.x) / 6;
          d += ' C' + (p1.x + dx) + ',' + (p1.y + (p2.y - p0.y) / 6)
            + ' ' + (p2.x - dx) + ',' + (p2.y - (p3.y - p1.y) / 6)
            + ' ' + p2.x + ',' + p2.y;
        }
        return d;
      }
      function apply() {
        for (var g = 0; g <= 4; g++) {
          var v = bottom + span * g / 4, yy = y(v);
          C.grid[g].setAttribute('y1', yy); C.grid[g].setAttribute('y2', yy);
          C.gl[g].setAttribute('y', yy + 4); C.gl[g].textContent = CUR.trim() + short(v);
        }
        var z = y(0);
        data.forEach(function (d, i) {
          var x = data.length === 1 ? (L + W - R) / 2 : L + (W - L - R) * i / (data.length - 1);
          C.pointsE[i] = {x: x, y: y(d.earned)};
          C.pointsL[i] = {x: x, y: y(d.lost)};
          C.earnedDots[i].setAttribute('cy', y(d.earned));
          C.lostDots[i].setAttribute('cy', y(d.lost));
          var tick = C.svg.querySelector('text[data-index="' + i + '"]');
          if (tick) { tick.textContent = label(d.date); }
        });
        data.forEach(function (d, i) {
          var tot = d.earned + d.lost, pt = C.pct[i];
          if (!pt) { return; }
          if (!showPct || tot <= 0 || pt.getAttribute('data-show') !== '1') { pt.textContent = ''; if (C.pctL[i]) { C.pctL[i].textContent = ''; } return; }
          var pc = Math.round(d.earned / tot * 100);
          pt.textContent = pc + '%';
          pt.setAttribute('x', C.pointsE[i].x);
          pt.setAttribute('y', Math.max(T + 10, C.pointsE[i].y - 11));
          pt.setAttribute('fill', GREEN);
          var pl = C.pctL[i];
          pl.textContent = (100 - pc) + '%';
          pl.setAttribute('x', C.pointsL[i].x);
          pl.setAttribute('y', Math.min(T + ph - 2, C.pointsL[i].y + 17));
          pl.setAttribute('fill', RED);
        });
        var pathE = linePath(C.pointsE), pathL = linePath(C.pointsL);
        C.lineE.setAttribute('d', pathE);
        C.lineL.setAttribute('d', pathL);
        C.areaE.setAttribute('d', pathE + ' L' + C.pointsE[C.pointsE.length - 1].x + ',' + z + ' L' + C.pointsE[0].x + ',' + z + ' Z');
        C.areaL.setAttribute('d', pathL + ' L' + C.pointsL[C.pointsL.length - 1].x + ',' + z + ' L' + C.pointsL[0].x + ',' + z + ' Z');
      }
      if (fresh) { requestAnimationFrame(function () { requestAnimationFrame(apply); }); } else { apply(); }

      var net = sumE - sumL;
      setText($('plSumE'), money(sumE)); setText($('plSumL'), money(sumL));
      setText($('plSumN'), signed(net)); $('plSumN').style.color = color(net);
      renderDonut(sumE, sumL);

      var rows = '';
      data.slice().reverse().forEach(function (d) {
        var nt = d.earned - d.lost, tot = d.earned + d.lost, pct = tot > 0 ? Math.round(d.earned / tot * 100) : null;
        rows += '<tr><td>' + esc(label(d.date)) + '</td>' +
          '<td class="text-end" style="color:' + GREEN + '">' + money(d.earned) + '</td>' +
          '<td class="text-end" style="color:' + RED + '">' + money(d.lost) + '</td>' +
          '<td class="text-end fw-semibold" style="color:' + color(nt) + '">' + signed(nt) + '</td>' +
          '<td class="text-end">' + (pct === null ? '–' : pct + '%<span class="pl-bar"><i style="width:' + pct + '%"></i></span>') + '</td></tr>';
      });
      $('plRows').innerHTML = rows;
    }

    function renderDonut(earned, lost) {
      var total = earned + lost;
      var earnedPct = total > 0 ? Math.round(earned / total * 100) : 0;
      var earnedDeg = total > 0 ? earned / total * 360 : 0;
      $('plDonut').style.background = total > 0
        ? 'conic-gradient(' + GREEN + ' 0deg ' + earnedDeg + 'deg, ' + RED + ' ' + earnedDeg + 'deg 360deg)'
        : 'conic-gradient(#29415e 0deg 360deg)';
      $('plDonutTotal').textContent = CUR + Math.round(total).toLocaleString();
      $('plDonutEarned').textContent = money(earned);
      $('plDonutLost').textContent = money(lost);
      $('plDonutEarnedPct').textContent = earnedPct + '%';
      $('plDonutLostPct').textContent = (total > 0 ? 100 - earnedPct : 0) + '%';
    }

    function renderStatus() {
      var box = $('plStatus');
      if (!DATA.statuses.length) {
        box.innerHTML = '<p class="text-muted small">No premium requests found yet.</p>';
      } else {
        var h = '';
        DATA.statuses.forEach(function (s) {
          var cls = s.kind === 'lost' ? 'lost' : (s.kind === 'made' ? 'made' : 'skip');
          h += '<div class="pl-st"><span>' + esc(s.label) + '</span><span class="d-flex align-items-center gap-2"><strong>' + s.count + '</strong><span class="pl-chip ' + cls + '">' + esc(s.kind) + '</span></span></div>';
        });
        box.innerHTML = h;
      }
      $('plCols').textContent = DATA.columns ? 'Columns read: ' + DATA.columns : '';
      $('plProblem').style.display = DATA.problem ? '' : 'none';
      $('plProblemText').textContent = DATA.problem || '';
    }

    function renderAll() { renderToday(); renderChart(); renderStatus(); }

    /* ---------- Live badge ---------- */
    function badge() {
      var live = $('plLive'), txt = $('plLiveText');
      if (paused) { live.className = 'pl-live paused'; txt.textContent = 'Paused · click to resume'; return; }
      var ago = Math.max(0, Math.round((Date.now() - lastOk) / 1000));
      if (Date.now() - lastOk > LIVE_MS * 3) { live.className = 'pl-live warn'; txt.textContent = 'Reconnecting…'; return; }
      live.className = 'pl-live';
      txt.textContent = 'Live · updated ' + (ago < 2 ? 'just now' : ago + 's ago');
    }
    setInterval(badge, 1000);
    function togglePause() { paused = !paused; badge(); if (!paused) { poll(); } }
    $('plLive').addEventListener('click', togglePause);
    $('plLive').addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); togglePause(); } });

    try { showPct = window.localStorage.getItem('vibestream-pl-pct') !== '0'; } catch (e) {}
    $('plPctBtn').classList.toggle('on', showPct);
    $('plPctBtn').addEventListener('click', function () {
      showPct = !showPct;
      this.classList.toggle('on', showPct);
      try { window.localStorage.setItem('vibestream-pl-pct', showPct ? '1' : '0'); } catch (e) {}
      renderChart();
    });

    $('plRange').addEventListener('click', function (e) {
      var b = e.target.closest('button[data-days]');
      if (!b) { return; }
      range = +b.getAttribute('data-days');
      try {
        window.localStorage.setItem(RANGE_STORAGE_KEY, String(range));
      } catch (storageError) {
        console.warn('Could not save the profit and loss chart range.', storageError);
      }
      Array.prototype.forEach.call(this.querySelectorAll('button'), function (x) { x.classList.toggle('active', x === b); });
      renderChart();
    });

    /* ---------- Live polling ---------- */
    var busy = false;
    function poll() {
      if (busy || document.hidden || paused) { return; }
      busy = true;
      fetch('profit-loss.php?live=1&_=' + Date.now(), {credentials: 'same-origin', cache: 'no-store'})
        .then(function (r) {
          if (r.status === 401) { window.location.href = '../login.php'; throw new Error('login'); }
          return r.json();
        })
        .then(function (j) {
          if (!j || !j.days) { return; }
          lastOk = Date.now();
          var sig = JSON.stringify([j.days, j.statuses, j.problem]);
          if (sig !== lastSig) { lastSig = sig; DATA = j; renderAll(); }
          badge();
        })
        .catch(function () { badge(); })
        .then(function () { busy = false; });
    }
    window.setInterval(poll, LIVE_MS);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });

    lastSig = JSON.stringify([DATA.days, DATA.statuses, DATA.problem]);
    Array.prototype.forEach.call($('plRange').querySelectorAll('button[data-days]'), function (button) {
      button.classList.toggle('active', Number(button.getAttribute('data-days')) === range);
    });
    renderAll();
    badge();
    poll();
  })();
  </script>
</body>
</html>