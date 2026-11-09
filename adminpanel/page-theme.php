<?php
/*
 * VibeStream admin panel - shared look for System check, Backup and Admin pay.
 * Put this file in the adminpanel folder, next to those pages.
 * It follows the site theme: LIGHT theme = light cards + dark text, DARK theme = dark neon cards + light text.
 * (The theme is read from data-theme / data-bs-theme on <html>, the same switch header.php already uses.)
 */

if (!function_exists('vx_cat_icon')) {
    /** Bootstrap-icon name for a check category, guessed from its title. */
    function vx_cat_icon(string $title): string
    {
        $t = strtolower($title);
        if (strpos($t, 'database') !== false || strpos($t, 'table') !== false) {
            return 'bi-database';
        }
        if (strpos($t, 'permission') !== false || strpos($t, 'writable') !== false || strpos($t, 'folder') !== false && strpos($t, 'file') === false) {
            return 'bi-lock';
        }
        if (strpos($t, 'file') !== false) {
            return 'bi-folder2-open';
        }
        if (strpos($t, 'php') !== false || strpos($t, 'setting') !== false) {
            return 'bi-gear';
        }
        if (strpos($t, 'secur') !== false) {
            return 'bi-shield-check';
        }
        if (strpos($t, 'mail') !== false) {
            return 'bi-envelope';
        }
        return 'bi-check2-square';
    }
}

if (!function_exists('vx_theme_css')) {
    function vx_theme_css(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        ?>
<style>
/* ================= colours: LIGHT (default) ================= */
:root{
  --vx-scheme:light;
  --vx-wrap:#f1f4fb;
  --vx-card:#ffffff;
  --vx-card-shadow:0 2px 14px rgba(40,70,160,.10);
  --vx-line:#cfd9f3; --vx-line-soft:#e3e9f8;
  --vx-text:#1c2547; --vx-strong:#0f1838; --vx-muted:#5d6a93; --vx-link:#4a3fe0;
  --vx-brand:#5146ea; --vx-brand2:#6d5ffd; --vx-btn-shadow:0 4px 14px rgba(81,70,234,.30);
  --vx-soft:#f5f7fe; --vx-qa:#f5f7fe; --vx-qa-h:#eaf0ff; --vx-detail:#f5f7fe; --vx-sel:rgba(81,70,234,.07);
  --vx-ico-bg:#e8eeff; --vx-ico-bd:#c5d0f5; --vx-ico-fg:#4a3fe0; --vx-ico-glow:none;
  --vx-fi-bg:#e8eeff; --vx-fi-bd:#c5d0f5; --vx-fi-fg:#4a3fe0;
  --vx-ghost-bg:#eef2fd; --vx-ghost-bd:#cfd9f3; --vx-ghost-fg:#2a3a78; --vx-hover:#dfe7fb;
  --vx-input:#ffffff; --vx-ph:#8a96b8;
  --vx-code-bg:rgba(81,70,234,.10); --vx-code-fg:#4a3fe0;
  --vx-bar:#dfe6fb;
  --vx-tag-bg:rgba(81,70,234,.12); --vx-tag-bd:#b5aef5; --vx-tag-fg:#4a3fe0;
  --vx-ok-t:#0b7d4f;   --vx-ok-bg:rgba(18,165,107,.12);  --vx-ok-bd:#7fd1ae;
  --vx-warn-t:#9a6200; --vx-warn-bg:rgba(255,176,32,.18); --vx-warn-bd:#e6b14d;
  --vx-bad-t:#c8264a;  --vx-bad-bg:rgba(229,54,90,.10);  --vx-bad-bd:#ec9aab;
  --vx-info-t:#4a3fe0; --vx-info-bg:rgba(81,70,234,.10); --vx-info-bd:#b5aef5;
  --vx-pur-t:#7a35d6;  --vx-pur-bg:rgba(168,85,247,.12); --vx-pur-bd:#c9a0f5;
  --vx-st-ok:linear-gradient(180deg,rgba(18,165,107,.10),#fff);
  --vx-st-warn:linear-gradient(180deg,rgba(255,176,32,.14),#fff);
  --vx-st-bad:linear-gradient(180deg,rgba(229,54,90,.09),#fff);
  --vx-up:#0f9d63; --vx-down:#d12a4f; --vx-pay:#8a3fd6;
}
/* ================= colours: DARK ================= */
:is([data-bs-theme="dark"],[data-theme="dark"]){
  --vx-scheme:dark;
  --vx-wrap:radial-gradient(900px 420px at 8% -10%,rgba(80,70,229,.20),transparent 70%),radial-gradient(700px 380px at 100% 0,rgba(34,120,255,.10),transparent 70%),#050a22;
  --vx-card:linear-gradient(180deg,rgba(20,34,104,.55),rgba(8,16,50,.92));
  --vx-card-shadow:0 0 0 1px rgba(90,110,255,.06) inset,0 10px 30px rgba(0,0,12,.35);
  --vx-line:#1c2f70; --vx-line-soft:rgba(28,47,112,.6);
  --vx-text:#e9ecfb; --vx-strong:#ffffff; --vx-muted:#8f9bc4; --vx-link:#8ea2ff;
  --vx-btn-shadow:0 4px 16px rgba(81,70,234,.35);
  --vx-soft:rgba(10,20,70,.55); --vx-qa:rgba(20,34,100,.35); --vx-qa-h:rgba(40,56,150,.45); --vx-detail:rgba(10,20,70,.55); --vx-sel:rgba(80,100,255,.10);
  --vx-ico-bg:#10195a; --vx-ico-bd:#3b4bd0; --vx-ico-fg:#ffffff; --vx-ico-glow:0 0 18px rgba(80,90,255,.35);
  --vx-fi-bg:#18246b; --vx-fi-bd:#3a4cc8; --vx-fi-fg:#c9d1ff;
  --vx-ghost-bg:rgba(80,100,255,.10); --vx-ghost-bd:#34479c; --vx-ghost-fg:#cdd5ff; --vx-hover:rgba(80,100,255,.25);
  --vx-input:#081038; --vx-ph:#6874a3;
  --vx-code-bg:rgba(80,100,255,.14); --vx-code-fg:#b4bfff;
  --vx-bar:#16226b;
  --vx-tag-bg:rgba(80,70,229,.25); --vx-tag-bd:#4a56d8; --vx-tag-fg:#c3caff;
  --vx-ok-t:#4ade80;   --vx-ok-bg:rgba(34,197,94,.14);   --vx-ok-bd:#1f9d55;
  --vx-warn-t:#fbbf24; --vx-warn-bg:rgba(245,165,36,.14); --vx-warn-bd:#b8801a;
  --vx-bad-t:#ff7d8b;  --vx-bad-bg:rgba(240,71,90,.14);  --vx-bad-bd:#c23346;
  --vx-info-t:#9fb0ff; --vx-info-bg:rgba(91,107,255,.14); --vx-info-bd:#3b4bd0;
  --vx-pur-t:#cfa9ff;  --vx-pur-bg:rgba(168,85,247,.16); --vx-pur-bd:#7e3fd0;
  --vx-st-ok:linear-gradient(180deg,rgba(34,197,94,.14),rgba(8,16,50,.92));
  --vx-st-warn:linear-gradient(180deg,rgba(245,165,36,.14),rgba(8,16,50,.92));
  --vx-st-bad:linear-gradient(180deg,rgba(240,71,90,.14),rgba(8,16,50,.92));
  --vx-up:#34d27b; --vx-down:#ff6b7a; --vx-pay:#b04dff;
}

/* ================= base ================= */
.vx{color:var(--vx-text);background:var(--vx-wrap);border-radius:16px;padding:20px;font-size:.92rem;color-scheme:var(--vx-scheme)}
.vx *{box-sizing:border-box}
.vx [hidden]{display:none!important}
.vx h1,.vx h2,.vx h3{color:var(--vx-strong);margin:0}
.vx p{margin:0}
.vx a{color:var(--vx-link)}
.vx code{color:var(--vx-code-fg);background:var(--vx-code-bg);padding:1px 6px;border-radius:5px}
.vx pre{color:var(--vx-code-fg);background:var(--vx-soft);border:1px solid var(--vx-line);border-radius:10px;padding:12px;margin:0;white-space:pre-wrap}
.vx .text-muted,.vx .form-text{color:var(--vx-muted)!important}
.vx .form-control,.vx .form-select{background-color:var(--vx-input);border:1px solid var(--vx-line);color:var(--vx-text)}
.vx .form-control::placeholder{color:var(--vx-ph)}
.vx .form-control:focus,.vx .form-select:focus{background-color:var(--vx-input);border-color:var(--vx-brand2);color:var(--vx-text);box-shadow:0 0 0 .2rem rgba(109,95,253,.22)}
.vx .form-label{color:var(--vx-muted);font-size:.8rem}
.vx input[type=file]::file-selector-button{background:var(--vx-ghost-bg);color:var(--vx-ghost-fg);border:0;border-right:1px solid var(--vx-line);margin-right:10px;padding:.4rem .8rem}
.vx .input-group-text{background:var(--vx-ghost-bg);border:1px solid var(--vx-line);color:var(--vx-ghost-fg)}

.vx-stack{display:grid;gap:16px}
.vx-card{background:var(--vx-card);border:1px solid var(--vx-line);border-radius:14px;padding:18px;box-shadow:var(--vx-card-shadow);min-width:0;color:var(--vx-text)}
.vx-card-title{font-size:1.02rem;font-weight:700;display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.vx-card-note{color:var(--vx-muted);font-size:.82rem;margin:-6px 0 12px}

.vx-head{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.vx-head-copy{flex:1;min-width:260px}
.vx-ico{width:52px;height:52px;flex:none;border-radius:12px;display:grid;place-items:center;font-size:1.5rem;color:var(--vx-ico-fg);background:var(--vx-ico-bg);border:1px solid var(--vx-ico-bd);box-shadow:var(--vx-ico-glow)}
.vx-title{font-size:1.45rem;font-weight:700;display:flex;gap:10px;align-items:center;flex-wrap:wrap;line-height:1.2}
.vx-tag{font-size:.65rem;letter-spacing:.06em;font-weight:700;padding:3px 9px;border-radius:7px;background:var(--vx-tag-bg);border:1px solid var(--vx-tag-bd);color:var(--vx-tag-fg)}
.vx-sub{color:var(--vx-muted);margin-top:4px!important;font-size:.85rem}
.vx-chip{display:flex;align-items:center;gap:12px;padding:10px 16px;border:1px solid var(--vx-line);border-radius:12px;background:var(--vx-soft);font-size:.85rem}
.vx-chip i{font-size:1.3rem;color:var(--vx-link)}

.vx-btn{display:inline-flex;gap:8px;align-items:center;justify-content:center;border:1px solid transparent;background:linear-gradient(180deg,var(--vx-brand2),var(--vx-brand));
  color:#fff!important;font-weight:600;padding:10px 18px;border-radius:10px;text-decoration:none;cursor:pointer;font-size:.9rem;line-height:1.2;box-shadow:var(--vx-btn-shadow)}
.vx-btn:hover{filter:brightness(1.08)}
.vx-btn-ghost{background:var(--vx-ghost-bg);border-color:var(--vx-ghost-bd);color:var(--vx-ghost-fg)!important;box-shadow:none}
.vx-btn-ghost:hover{background:var(--vx-hover);filter:none}
.vx-btn-sm{padding:6px 14px;font-size:.8rem;border-radius:8px}
.vx-btn-block{width:100%}
.vx-btn[disabled]{opacity:.5;cursor:not-allowed}
.vx-ibtn{width:30px;height:30px;display:inline-grid;place-items:center;border-radius:8px;border:1px solid var(--vx-ghost-bd);background:var(--vx-ghost-bg);color:var(--vx-ghost-fg);cursor:pointer;text-decoration:none;padding:0;font-size:.85rem}
.vx-ibtn:hover{background:var(--vx-hover);color:var(--vx-strong)}
.vx-ibtn-danger:hover{background:var(--vx-bad-bg);border-color:var(--vx-bad-bd);color:var(--vx-bad-t)}
.vx-ibtn-on{background:var(--vx-warn-bg);border-color:var(--vx-warn-bd);color:var(--vx-warn-t)}
.vx-actions{display:inline-flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}
.vx-actions form{margin:0}

.vx-alert{padding:12px 16px;border-radius:12px;border:1px solid;display:flex;gap:10px 16px;flex-wrap:wrap;align-items:center;justify-content:space-between}
.vx-alert-success{background:var(--vx-ok-bg);border-color:var(--vx-ok-bd);color:var(--vx-ok-t)}
.vx-alert-warning{background:var(--vx-warn-bg);border-color:var(--vx-warn-bd);color:var(--vx-warn-t)}
.vx-alert-danger{background:var(--vx-bad-bg);border-color:var(--vx-bad-bd);color:var(--vx-bad-t)}
.vx-alert a{color:inherit;text-decoration:underline}
.vx-danger-text{color:var(--vx-bad-t)}

.vx-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.vx-stat{display:flex;align-items:center;gap:16px;padding:16px 20px}
.vx-stat-ico{width:52px;height:52px;flex:none;border-radius:14px;display:grid;place-items:center;font-size:1.6rem;color:#fff}
.vx-stat b{display:block;font-size:1.9rem;line-height:1.1;color:var(--vx-strong)}
.vx-stat span{color:var(--vx-muted);font-size:.8rem}
.vx-stat-label{display:block;font-size:.95rem!important;font-weight:600}
.vx-stat-ok{border-color:var(--vx-ok-bd);background:var(--vx-st-ok)}
.vx-stat-ok .vx-stat-ico{background:linear-gradient(180deg,#34d27b,#16a34a)}.vx-stat-ok .vx-stat-label{color:var(--vx-ok-t)}
.vx-stat-warn{border-color:var(--vx-warn-bd);background:var(--vx-st-warn)}
.vx-stat-warn .vx-stat-ico{background:linear-gradient(180deg,#fbbf24,#d98a06)}.vx-stat-warn .vx-stat-label{color:var(--vx-warn-t)}
.vx-stat-bad{border-color:var(--vx-bad-bd);background:var(--vx-st-bad)}
.vx-stat-bad .vx-stat-ico{background:linear-gradient(180deg,#ff6b7a,#d92d43)}.vx-stat-bad .vx-stat-label{color:var(--vx-bad-t)}
.vx-stat-all .vx-stat-ico{background:linear-gradient(180deg,#6d5ffd,#4a3fe0)}

.vx-grid{display:grid;grid-template-columns:minmax(0,1.9fr) minmax(0,1fr) minmax(0,1.1fr);gap:16px;align-items:start}
.vx-bgrid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:16px;align-items:start}
.vx-tools{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.vx-tool{display:flex;flex-direction:column;gap:14px;padding:16px}
.vx-tool-top{display:flex;gap:14px;align-items:center}
.vx-tool-top .vx-ico{width:46px;height:46px;font-size:1.3rem;border-radius:50%}
.vx-tool-top b{display:block;color:var(--vx-strong)}
.vx-tool-top small{color:var(--vx-link);font-size:.78rem}

.vx-scroll{overflow-x:auto}
.vx-table{width:100%;border-collapse:collapse;color:var(--vx-text)}
.vx-table th{color:var(--vx-muted);font-weight:500;font-size:.8rem;text-align:left;padding:10px 12px;border-bottom:1px solid var(--vx-line);white-space:nowrap}
.vx-table td{padding:12px;border-bottom:1px solid var(--vx-line-soft);vertical-align:middle}
.vx-table tr:last-child td{border-bottom:0}
.vx-table .r{text-align:right}
.vx-cat{display:flex;gap:10px;align-items:center;white-space:nowrap}
.vx-cat i,.vx-fileico{width:30px;height:30px;flex:none;border-radius:50%;display:grid;place-items:center;background:var(--vx-fi-bg);border:1px solid var(--vx-fi-bd);color:var(--vx-fi-fg);font-size:.85rem}
.vx-fileico{border-radius:8px}
.vx-mono{font-family:ui-monospace,Consolas,monospace;font-size:.75rem;color:var(--vx-muted)}
.vx-detail td{background:var(--vx-detail)}
.vx-detail ul{list-style:none;margin:0;padding:0;display:grid;gap:8px}
.vx-detail li{display:flex;gap:12px;align-items:baseline;flex-wrap:wrap}

.vx-pill{display:inline-block;min-width:62px;text-align:center;padding:3px 12px;border-radius:999px;font-size:.74rem;font-weight:600;border:1px solid;white-space:nowrap}
.vx-pill-ok{color:var(--vx-ok-t);background:var(--vx-ok-bg);border-color:var(--vx-ok-bd)}
.vx-pill-warn{color:var(--vx-warn-t);background:var(--vx-warn-bg);border-color:var(--vx-warn-bd)}
.vx-pill-bad{color:var(--vx-bad-t);background:var(--vx-bad-bg);border-color:var(--vx-bad-bd)}
.vx-pill-info{color:var(--vx-info-t);background:var(--vx-info-bg);border-color:var(--vx-info-bd)}
.vx-pill-purple{color:var(--vx-pur-t);background:var(--vx-pur-bg);border-color:var(--vx-pur-bd)}

.vx-qa{display:flex;align-items:center;gap:12px;width:100%;padding:12px;border:1px solid var(--vx-line);border-radius:12px;background:var(--vx-qa);color:var(--vx-text)!important;text-align:left;cursor:pointer;text-decoration:none;font:inherit}
.vx-qa:hover{border-color:var(--vx-brand2);background:var(--vx-qa-h)}
.vx-qa .vx-fileico{width:40px;height:40px;border-radius:10px;font-size:1.1rem}
.vx-qa span{flex:1;min-width:0}
.vx-qa b{display:block;font-size:.88rem;color:var(--vx-strong)}
.vx-qa small{display:block;color:var(--vx-muted);font-size:.76rem}
.vx-qa form{margin:0}
.vx-list{display:grid;gap:10px}
.vx-list form{margin:0}

.vx-act{display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--vx-line-soft)}
.vx-act:last-child{border-bottom:0}
.vx-act-ico{width:30px;height:30px;flex:none;border-radius:50%;display:grid;place-items:center;font-size:.9rem;color:#fff}
.vx-act-ok{background:#16a34a}.vx-act-warn{background:#d98a06}.vx-act-bad{background:#d92d43}
.vx-act-body{flex:1;min-width:0}
.vx-act-body b{display:block;font-size:.85rem;font-weight:600;color:var(--vx-strong)}
.vx-act-body small{display:block;color:var(--vx-muted);font-size:.75rem;overflow-wrap:anywhere}
.vx-act time{color:var(--vx-muted);font-size:.74rem;white-space:nowrap}

.vx-set{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--vx-line-soft)}
.vx-set:last-of-type{border-bottom:0}
.vx-set b{color:var(--vx-strong)}
.vx-set small{display:block;color:var(--vx-muted);font-size:.76rem}
.vx-storage{min-width:250px;padding:10px 16px;border:1px solid var(--vx-line);border-radius:12px;background:var(--vx-soft)}
.vx-storage-top{display:flex;align-items:center;gap:12px;margin-bottom:8px}
.vx-storage-top i{font-size:1.4rem;color:var(--vx-link)}
.vx-storage small{display:block;color:var(--vx-muted);font-size:.74rem}
.vx-bar{height:6px;background:var(--vx-bar);border-radius:99px;overflow:hidden}
.vx-bar>i{display:block;height:100%;background:linear-gradient(90deg,#5b6bff,#8a6dff)}
.vx-restore td{background:var(--vx-warn-bg)}
.vx-restore .vx-restore-box{border:1px solid var(--vx-warn-bd);border-radius:10px;padding:12px}
.vx-empty{text-align:center;color:var(--vx-muted);padding:22px 0}

/* charts (inline SVG) */
.vx-svg-grid{stroke:var(--vx-line)}
.vx-svg-lbl{fill:var(--vx-muted)}
.vx-svg-pay{fill:var(--vx-pay)}
.vx-svg-track{stroke:var(--vx-bar)}
.vx-svg-center{fill:var(--vx-strong)}

@media (max-width:1199px){.vx-grid,.vx-bgrid{grid-template-columns:minmax(0,1fr)}.vx-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:767px){.vx{padding:12px}.vx-tools{grid-template-columns:minmax(0,1fr)}.vx-stats{grid-template-columns:minmax(0,1fr)}}
</style>
<script>
function vxToggle(id){var e=document.getElementById(id);if(e){e.hidden=!e.hidden;}}
</script>
        <?php
    }
}