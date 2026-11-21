<?php
/*
 * VibeStream - confetti for the #1 creator.
 * Put this file in the main VibeStream folder, next to studio.php and creator-analytics.php.
 *
 * Usage (right before vs_page_end()):
 *     vs_creator_confetti($rank, $views, $becameFirst);   // 3rd argument: true = they just took #1, always play + show a toast
 *
 * - Prints nothing unless the signed-in creator is ranked #1 and has at least one view.
 * - Auto-plays once per browser session (or every time when $force is true); anything with data-confetti-replay replays it on click.
 * - window.vsConfetti() can also be called from other scripts (e.g. when the live rank becomes #1).
 * - Respects "reduce motion". No external libraries.
 */
if (!function_exists('vs_creator_confetti')) {
    function vs_creator_confetti($rank, $views = 0, bool $force = false): void
    {
        if ((int)$rank !== 1 || (int)$views <= 0) {
            return;
        }
        ?>
<?php if ($force): ?>
<div id="vsConfettiToast" role="status" style="position:fixed;left:50%;top:18px;transform:translateX(-50%);z-index:10000;max-width:calc(100% - 24px);padding:12px 20px;border-radius:14px;background:linear-gradient(100deg,#b45309,#f59e0b 55%,#c026d3);color:#fff;font:700 15px/1.3 system-ui,sans-serif;box-shadow:0 12px 34px rgba(0,0,0,.4);text-align:center;transition:opacity .4s ease">&#127881; Congratulations! You just took the #1 spot on VibeStream!</div>
<script>window.setTimeout(function(){var t=document.getElementById('vsConfettiToast');if(t){t.style.opacity='0';window.setTimeout(function(){t.remove();},450);}},6500);</script>
<?php endif; ?>
<canvas id="vsConfetti" aria-hidden="true" style="position:fixed;left:0;top:0;width:100%;height:100%;pointer-events:none;z-index:9999"></canvas>
<script>
(function () {
    var canvas = document.getElementById('vsConfetti');
    if (!canvas || !canvas.getContext) { return; }
    var ctx = canvas.getContext('2d');
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var colors = ['#8b5cf6', '#ec4899', '#f5b942', '#34d399', '#60a5fa', '#ff2e93', '#00e5ff', '#ffffff'];
    var parts = [];
    var raf = 0, last = 0, started = 0, showerUntil = 0;

    function rnd(a, b) { return a + Math.random() * (b - a); }
    function resize() {
        var dpr = window.devicePixelRatio || 1;
        canvas.width = Math.round(window.innerWidth * dpr);
        canvas.height = Math.round(window.innerHeight * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
    function add(x, y, angle, speed) {
        parts.push({
            x: x, y: y,
            vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed,
            w: rnd(6, 11), h: rnd(4, 8),
            rot: rnd(0, 6.28), vr: rnd(-0.25, 0.25),
            tilt: rnd(0, 6.28), vt: rnd(0.05, 0.2),
            color: colors[(Math.random() * colors.length) | 0],
            round: Math.random() < 0.25
        });
    }
    function cannon(x, y, baseAngle, count) {
        for (var i = 0; i < count; i++) { add(x, y, baseAngle + rnd(-0.4, 0.4), rnd(11, 24)); }
    }
    function frame(ts) {
        var dt = Math.min(3, (ts - (last || ts)) / 16.67);
        last = ts;
        var w = window.innerWidth, h = window.innerHeight;
        ctx.clearRect(0, 0, w, h);
        if (ts < showerUntil) {
            for (var s = 0; s < 3; s++) { add(rnd(0, w), -12, rnd(1.2, 1.9), rnd(2, 5)); }
        }
        for (var i = parts.length - 1; i >= 0; i--) {
            var p = parts[i];
            p.vy += 0.2 * dt;
            p.vx *= Math.pow(0.985, dt);
            p.vy *= Math.pow(0.992, dt);
            p.x += p.vx * dt;
            p.y += p.vy * dt;
            p.rot += p.vr * dt;
            p.tilt += p.vt * dt;
            if (p.y > h + 30 || p.x < -60 || p.x > w + 60) { parts.splice(i, 1); continue; }
            ctx.save();
            ctx.translate(p.x, p.y);
            ctx.rotate(p.rot);
            ctx.fillStyle = p.color;
            if (p.round) {
                ctx.beginPath();
                ctx.arc(0, 0, p.w / 2.4, 0, 6.283);
                ctx.fill();
            } else {
                ctx.fillRect(-p.w / 2, (-p.h / 2) * Math.cos(p.tilt), p.w, p.h * Math.cos(p.tilt));
            }
            ctx.restore();
        }
        if (parts.length || ts < showerUntil) { raf = window.requestAnimationFrame(frame); }
        else { raf = 0; last = 0; ctx.clearRect(0, 0, w, h); }
    }
    function play() {
        if (reduce) { return; }
        resize();
        var w = window.innerWidth, h = window.innerHeight;
        cannon(0, h, -1.05, 90);
        cannon(w, h, -2.09, 90);
        cannon(w / 2, h, -1.57, 60);
        showerUntil = (window.performance ? window.performance.now() : Date.now()) + 2600;
        if (!raf) { last = 0; raf = window.requestAnimationFrame(frame); }
    }
    window.addEventListener('resize', function () { if (raf) { resize(); } });
    window.vsConfetti = play;

    document.addEventListener('click', function (event) {
        var trigger = event.target && event.target.closest ? event.target.closest('[data-confetti-replay]') : null;
        if (trigger) { play(); }
    });

    var seen = false;
    var forced = <?= $force ? 'true' : 'false' ?>;
    try { seen = !forced && window.sessionStorage.getItem('vsConfettiRank1') === '1'; } catch (e) {}
    if (!seen) {
        try { window.sessionStorage.setItem('vsConfettiRank1', '1'); } catch (e) {}
        window.setTimeout(play, 450);
    }
})();
</script>
        <?php
    }
}
