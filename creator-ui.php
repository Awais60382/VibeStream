<?php
/*
 * VibeStream - shared look & feel for Creator Studio and Creator Analytics.
 * Put this file in the main VibeStream folder, next to studio.php and creator-analytics.php.
 *
 * Design notes
 *  - Violet is the brand colour; pink / blue / green only ever mean likes / comments / subscribers.
 *  - Gold is reserved for rank. The #1 creator gets the one showpiece: animated gold border, crown and gold rank number.
 *  - One page-load moment (numbers count up, mini charts and the main line draw in together). Nothing else moves on its own.
 *  - Cards that are not clickable do not react to hover. Everything respects "reduce motion".
 */
if (!function_exists('vs_creator_ui_css')) {
    function vs_creator_ui_css(): string
    {
        return <<<'CSS'

/* ===== Creator UI polish (creator-ui.php) ===== */
@property --vs-angle{syntax:'<angle>';initial-value:0deg;inherits:false}
.ca-page{-webkit-font-smoothing:antialiased;font-variant-numeric:tabular-nums}
.ca-page .ca-panel{position:relative}
.ca-page .ca-panel::after{content:"";position:absolute;left:0;right:0;top:0;height:1px;border-radius:inherit;background:linear-gradient(90deg,transparent,rgba(168,85,247,.5),transparent);pointer-events:none}
html[data-theme="light"] .ca-page .ca-panel::after{opacity:.4}

/* keyboard focus */
.ca-page a:focus-visible,.ca-page button:focus-visible,.ca-page select:focus-visible,.ca-page [tabindex]:focus-visible{outline:2px solid #c4b5fd;outline-offset:3px;border-radius:8px}

/* stat cards are not clickable, so they do not lift or glow cyan on hover */
.ca-page .ca-card:hover,.ca-page .ca-kpi:hover{transform:none}
html:not([data-theme="light"]) body .ca-page .ca-card.st-kpi:hover{border-color:color-mix(in srgb,var(--accent) 50%,transparent);box-shadow:none}
html:not([data-theme="light"]) body .ca-page .ca-kpi:hover{box-shadow:0 18px 40px -26px var(--k)}

/* trends read as pills: colour + arrow + number */
.st-kpi-trend,.ca-page .ca-trend{padding:3px 10px 3px 8px;border-radius:999px}
.st-kpi-trend.is-up,.ca-page .ca-trend.is-up{background:rgba(52,211,153,.13)}
.st-kpi-trend.is-down,.ca-page .ca-trend.is-down{background:rgba(251,113,133,.14)}
.ca-page .ca-trend{width:max-content}

/* readable small text */
.st-pstat-note,.vs-rank-note,.st-chart-foot,.st-chart-legend,.st-promo-copy span{font-size:12px}
.st-promo-copy strong{font-size:13px}
.st-promo-link{font-size:12px}
.vs-rank-note span+span{opacity:.9}

/* date range select gets a visible chevron */
.st-period select{padding-right:18px;background:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23bc8bff' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'><path d='M6 9l6 6 6-6'/></svg>") no-repeat right center/10px}

/* video thumbnails: soft fade so the duration badge always reads */
.st-recent-thumb::after{content:"";position:absolute;left:0;right:0;bottom:0;height:46%;background:linear-gradient(180deg,transparent,rgba(5,4,14,.72));pointer-events:none}
.st-duration{z-index:1}
.st-recent-play{z-index:1;transition:transform .18s ease,background .18s ease}
.st-recent-card:hover .st-recent-play{transform:translate(-50%,-50%) scale(1.12);background:rgba(139,92,246,.9)}

/* ---- the one load moment ---- */
@keyframes vsWipe{from{clip-path:inset(0 100% 0 0)}to{clip-path:inset(0 0 0 0)}}
@keyframes vsDraw{to{stroke-dashoffset:0}}
@keyframes vsFade{from{opacity:0}to{opacity:1}}
@keyframes vsGrow{from{transform:scaleY(.12)}to{transform:scaleY(1)}}
.st-kpi-spark{animation:vsWipe .95s cubic-bezier(.3,.7,.2,1) backwards}
.ca-grid>.st-kpi:nth-child(2) .st-kpi-spark{animation-delay:.08s}
.ca-grid>.st-kpi:nth-child(3) .st-kpi-spark{animation-delay:.16s}
.ca-grid>.st-kpi:nth-child(4) .st-kpi-spark{animation-delay:.24s}
.st-chart .st-line{stroke:url(#stLineG);stroke-dasharray:1;stroke-dashoffset:1;animation:vsDraw 1.1s .3s cubic-bezier(.3,.7,.2,1) forwards}
.st-chart .st-area{animation:vsFade .8s .7s backwards}
.ca-spark i,.ca-rank-bars rect{transform-origin:50% 100%;transform-box:fill-box;animation:vsGrow .7s cubic-bezier(.3,.7,.2,1) backwards}
.ca-spark i:nth-child(2),.ca-rank-bars rect:nth-of-type(2){animation-delay:.05s}
.ca-spark i:nth-child(3),.ca-rank-bars rect:nth-of-type(3){animation-delay:.1s}
.ca-spark i:nth-child(4),.ca-rank-bars rect:nth-of-type(4){animation-delay:.15s}
.ca-spark i:nth-child(5){animation-delay:.2s}
.ca-spark i:nth-child(6){animation-delay:.25s}
.ca-spark i:nth-child(7){animation-delay:.3s}

/* ---- the showpiece: #1 creator ---- */
html:not([data-theme="light"]) body .ca-page .ca-panel.is-champion,html[data-theme="light"] body .ca-page .ca-panel.is-champion{border-color:rgba(251,191,36,.55);box-shadow:0 0 0 1px rgba(251,191,36,.2),0 22px 56px -24px rgba(245,158,11,.6)}
.ca-page .is-champion::before{--vs-angle:0deg;content:"";position:absolute;inset:0;z-index:0;border-radius:inherit;padding:1.5px;background:conic-gradient(from var(--vs-angle),#fbbf24,#ec4899,#8b5cf6,#fbbf24);-webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask:linear-gradient(#000 0 0) content-box exclude,linear-gradient(#000 0 0);animation:vsSpin 7s linear infinite;pointer-events:none}
@keyframes vsSpin{to{--vs-angle:360deg}}
.st-profile.is-champion .st-profile-top{padding-top:30px}
.st-profile.is-champion .st-avatar{border-color:#fbbf24;box-shadow:0 0 0 3px rgba(251,191,36,.18),0 0 26px rgba(251,191,36,.6)}
.st-crown{position:absolute;left:40px;top:6px;z-index:2;display:inline-flex;font-size:30px;color:#fbbf24;transform:rotate(-14deg);filter:drop-shadow(0 0 8px rgba(251,191,36,.7))}
.st-crown .st-icon{fill:currentColor}
.st-profile.is-champion .st-pstat-value{color:#fde68a}
.ca-rank-card.is-champion .ca-rank-num{background:linear-gradient(180deg,#fde68a,#f59e0b);-webkit-background-clip:text;background-clip:text;color:transparent}

@media(prefers-reduced-motion:reduce){
  .st-kpi-spark,.st-chart .st-area,.ca-spark i,.ca-rank-bars rect,.ca-page .is-champion::before{animation:none!important}
  .st-chart .st-line{animation:none!important;stroke-dasharray:none;stroke-dashoffset:0}
}
@media(hover:none){.st-recent-card:hover .st-recent-play{transform:translate(-50%,-50%);background:rgba(10,8,30,.72)}}
CSS;
    }
}

if (!function_exists('vs_creator_ui_script')) {
    /** Count-up for the headline numbers. Runs once on load; the final text is always the server's own value. */
    function vs_creator_ui_script(): void
    {
        ?>
<script>
(function () {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
    var targets = document.querySelectorAll('.st-kpi .ca-value, .ca-kpi .ca-kpi-value');
    Array.prototype.forEach.call(targets, function (el, index) {
        var finalText = el.textContent.trim();
        var m = /^(\d[\d,]*\.?\d*)\s*([KM]?)$/.exec(finalText);
        if (!m) { return; }
        var raw = parseFloat(m[1].replace(/,/g, ''));
        if (!isFinite(raw) || raw === 0) { return; }
        var decimals = (m[1].split('.')[1] || '').length;
        var grouped = m[1].indexOf(',') > -1;
        var suffix = m[2];
        function format(v) {
            return (grouped
                ? Number(v.toFixed(decimals)).toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
                : v.toFixed(decimals)) + suffix;
        }
        el.textContent = format(0);
        window.setTimeout(function () {
            var begin = 0;
            function step(ts) {
                if (!begin) { begin = ts; }
                var p = Math.min(1, (ts - begin) / 1000);
                el.textContent = format(raw * (1 - Math.pow(1 - p, 3)));
                if (p < 1) { window.requestAnimationFrame(step); } else { el.textContent = finalText; }
            }
            window.requestAnimationFrame(step);
        }, index * 80);
    });
})();
</script>
        <?php
    }
}
