/* VibeStream daily quests: "+N points" pop-up and Shorts watch tracking.
   Add before </body> on watch.php and shorts.php:   <script src="quest-toast.js?v=1"></script>
   Server replies from watch.php / shorts.php carry {"quest_points": N}; this shows the pop-up for them. */
(function () {
  'use strict';
  if (window.__vsQuestToast) return;
  window.__vsQuestToast = true;

  var css = '.vs-qt{position:fixed;left:50%;top:calc(var(--navbar-height,72px) + 12px);z-index:2300;transform:translate(-50%,-14px);'
    + 'display:flex;align-items:center;gap:10px;max-width:calc(100vw - 24px);padding:10px 18px;border-radius:999px;'
    + 'background:linear-gradient(90deg,#7c3aed,#a855f7);color:#fff;font:700 14px/1.2 system-ui,Arial,sans-serif;'
    + 'box-shadow:0 10px 30px rgba(124,58,237,.55),0 0 0 1px rgba(255,255,255,.25);opacity:0;pointer-events:none;transition:opacity .25s,transform .25s}'
    + '.vs-qt.show{opacity:1;transform:translate(-50%,0)}'
    + '.vs-qt b{display:grid;place-items:center;width:26px;height:26px;border-radius:50%;background:radial-gradient(circle at 35% 30%,#fff3b0,#ffc23d 55%,#c77a00);color:#4a2b00;font-size:13px}'
    + '@media(prefers-reduced-motion:reduce){.vs-qt{transition:none}}';
  var style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  var box = null, timer = null;
  function show(points) {
    if (!document.body) return;
    if (!box) {
      box = document.createElement('div');
      box.className = 'vs-qt';
      box.setAttribute('role', 'status');
      box.setAttribute('aria-live', 'polite');
      document.body.appendChild(box);
    }
    box.innerHTML = '<b>\u2605</b><span></span>';
    box.lastChild.textContent = '+' + points + (points === 1 ? ' point' : ' points') + ' \u00b7 Daily quest';
    // restart the animation
    box.classList.remove('show');
    void box.offsetWidth;
    box.classList.add('show');
    clearTimeout(timer);
    timer = setTimeout(function () { box.classList.remove('show'); }, 3400);
    try { document.dispatchEvent(new CustomEvent('vs-quest-points', { detail: { points: points } })); } catch (e) {}
  }
  window.vsQuestToast = show;

  // Watch every fetch(); when a JSON reply says quest points were earned, show the pop-up.
  var originalFetch = window.fetch;
  if (typeof originalFetch === 'function') {
    window.fetch = function () {
      var promise = originalFetch.apply(this, arguments);
      try {
        promise.then(function (response) {
          var type = (response.headers && response.headers.get('content-type')) || '';
          if (type.indexOf('json') === -1) return;
          response.clone().json().then(function (data) {
            var points = data && Number(data.quest_points);
            if (points > 0) show(points);
          }).catch(function () {});
        }).catch(function () {});
      } catch (e) {}
      return promise;
    };
  }

  // Shorts: count a "watch" once the short has really been playing for 8 seconds.
  if (/shorts\.php$/i.test(location.pathname)) {
    var playing = false, seconds = 0, sent = false;
    var video = document.getElementById('shortVideo');
    var frame = document.getElementById('ytFrame');
    if (video) {
      video.addEventListener('playing', function () { playing = true; });
      ['pause', 'ended', 'waiting'].forEach(function (name) {
        video.addEventListener(name, function () { playing = false; });
      });
    }
    if (frame) {
      window.addEventListener('message', function (e) {
        if (e.source !== frame.contentWindow) return;
        var d = e.data;
        if (typeof d === 'string') { try { d = JSON.parse(d); } catch (x) { return; } }
        if (!d) return;
        var state = null;
        if (d.event === 'onStateChange') state = d.info;
        else if (d.event === 'infoDelivery' && d.info && typeof d.info.playerState === 'number') state = d.info.playerState;
        if (state !== null) playing = (state === 1);
      });
    }
    setInterval(function () {
      if (!playing || document.hidden || sent) return;
      seconds += 1;
      if (seconds >= 8) {
        sent = true;
        if (typeof send === 'function') { send('quest_watch'); }   // send() is defined by shorts.php
      }
    }, 1000);
  }
})();