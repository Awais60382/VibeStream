/* VibeStream rewards page add-on.
   Add this ONE line to premium-points.php, just before </body>:
       <script src="premium-points-extras.js?v=1"></script>
   It adds: Daily Quests card, rewards level bar, phone menu drawer, leaderboard tabs (week / month / all time). */
(function () {
  'use strict';

  var css = [
    /* ---- quests ---- */
    '.pp-row-1{grid-template-columns:minmax(0,1fr)!important}',
    '.pq-list{list-style:none;margin:12px 0 0;padding:0;display:grid;gap:10px}',
    '.pq-item{display:grid;grid-template-columns:44px minmax(0,1fr) auto;align-items:center;gap:6px 14px;padding:12px 14px;border:1px solid var(--pp-line);border-radius:14px;background:var(--pp-row)}',
    '.pq-ico{grid-row:1/3;display:grid;place-items:center;width:44px;height:44px;border-radius:50%;background:rgba(168,85,247,.2);font-size:20px}',
    '.pq-main{min-width:0;display:flex;flex-direction:column;gap:3px}',
    '.pq-main strong{font-size:15px}',
    '.pq-sub{color:var(--pp-muted);font-size:12px}',
    '.pq-state{font-weight:800;font-size:13px;color:var(--pp-gold);white-space:nowrap;text-align:right}',
    '.pq-go{grid-column:3;justify-self:end;color:var(--pp-gold);font-weight:800;font-size:13px;text-decoration:none;white-space:nowrap}',
    '.pq-go:hover{text-decoration:underline}',
    '.pq-item .pp-bar{grid-column:2;margin-top:4px}',
    '.pq-item.is-done{border-color:rgba(47,224,138,.65);background:rgba(47,224,138,.09)}',
    '.pq-item.is-done .pq-ico{background:var(--pp-green);color:#04130b;font-weight:900}',
    '.pq-item.is-done .pq-state{color:var(--pp-green)}',
    '.pq-item.is-bonus{border-style:dashed}',
    '.pq-foot{margin-top:10px}',

    /* ---- streak freeze, activity, achievements, level-up ---- */
    '.pq-freeze{margin-top:12px}',
    '.pq-freeze .pq-item{border-style:dashed}',
    '.pq-btn{grid-column:3;justify-self:end;border:0;border-radius:999px;padding:8px 16px;background:linear-gradient(90deg,#7c3aed,#a855f7);color:#fff;font:inherit;font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap;touch-action:manipulation}',
    '.pq-btn:disabled{opacity:.5;cursor:default}',
    '.pa-list{list-style:none;margin:12px 0 0;padding:0;display:grid;gap:8px}',
    '.pa-item{display:flex;align-items:center;gap:12px;padding:9px 12px;border:1px solid var(--pp-line);border-radius:12px;background:var(--pp-row);font-size:13px}',
    '.pa-ico{flex:0 0 34px;width:34px;height:34px;border-radius:50%;display:grid;place-items:center;background:rgba(168,85,247,.2);font-size:16px}',
    '.pa-body{flex:1;min-width:0;display:flex;flex-direction:column}',
    '.pa-body small{color:var(--pp-muted);font-size:11px}',
    '.pa-amt{font-weight:800;color:var(--pp-green);white-space:nowrap}',
    '.pa-amt.neg{color:var(--pp-pink)}',
    '.pa-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-top:12px}',
    '.pa-tile{display:flex;flex-direction:column;gap:6px;padding:12px;border:1px solid var(--pp-line);border-radius:14px;background:var(--pp-row);opacity:.8}',
    '.pa-tile.done{opacity:1;border-color:rgba(255,194,61,.7);background:radial-gradient(160px 70px at 0 0,rgba(255,194,61,.16),transparent 75%),var(--pp-row)}',
    '.pa-tile .pa-ico{width:38px;height:38px;flex-basis:38px;font-size:19px}',
    '.pa-tile strong{font-size:14px}',
    '.pa-tile span{color:var(--pp-muted);font-size:12px;line-height:1.35}',
    '.pa-tile .pp-bar{margin:2px 0 0}',
    '.pl-overlay{position:fixed;inset:0;z-index:2500;display:grid;place-items:center;padding:16px;background:rgba(4,2,12,.78)}',
    '.pl-box{position:relative;width:min(380px,100%);padding:28px 22px;border-radius:22px;text-align:center;border:1px solid rgba(255,194,61,.7);background:radial-gradient(260px 140px at 50% 0,rgba(255,194,61,.25),transparent 75%),var(--pp-surface,#120a2a);color:var(--pp-text);box-shadow:0 0 50px rgba(255,194,61,.3)}',
    '.pl-box h2{margin:6px 0 4px;font-size:30px;letter-spacing:.04em}',
    '.pl-box p{margin:6px 0 18px;color:var(--pp-muted)}',
    '.pl-conf{position:fixed;top:-14px;z-index:2510;width:9px;height:14px;border-radius:2px;pointer-events:none;animation:plConf var(--d) cubic-bezier(.3,.6,.4,1) forwards}',
    '@keyframes plConf{to{transform:translate(var(--x),105vh) rotate(var(--r));opacity:.9}}',
    '@media(prefers-reduced-motion:reduce){.pl-conf{display:none}}',
    /* ---- leaderboard tabs ---- */
    '.pp-tabs{display:flex;gap:6px;margin:12px 0 0;padding:4px;border:1px solid var(--pp-line);border-radius:999px;background:var(--pp-row)}',
    '.pp-tab{flex:1;border:0;border-radius:999px;padding:7px 4px;background:transparent;color:var(--pp-muted);font:inherit;font-size:12px;font-weight:800;cursor:pointer;touch-action:manipulation}',
    '.pp-tab[aria-selected="true"]{background:linear-gradient(90deg,#7c3aed,#a855f7);color:#fff;box-shadow:0 0 14px rgba(139,60,255,.5)}',
    '.pp-tab:focus-visible{outline:3px solid #ffb020;outline-offset:2px}',
    '.pp-leaders li.is-me{border:1px solid rgba(255,194,61,.7);background:rgba(255,194,61,.10)}',
    '.pp-you{margin-left:6px;padding:1px 7px;border-radius:999px;background:linear-gradient(90deg,#7c3aed,#a855f7);color:#fff;font-size:10px;font-weight:800;vertical-align:middle}',

    /* ---- phone menu drawer ---- */
    '.pp-menu-btn,.pp-scrim,.pp-drawer-close{display:none}',
    '@media(max-width:900px){',
    'html body .pp-layout>.app-sidebar{display:flex!important;position:fixed!important;top:0!important;left:0!important;bottom:0!important;height:100%!important;width:min(300px,84vw)!important;flex:none!important;z-index:2000!important;transform:translateX(-105%)!important;transition:transform .25s ease;overflow-y:auto;padding-top:calc(8px + env(safe-area-inset-top,0px));padding-bottom:env(safe-area-inset-bottom,0px);visibility:hidden}',
    'html.pp-drawer-open body .pp-layout>.app-sidebar{transform:none!important;visibility:visible;box-shadow:12px 0 40px rgba(0,0,0,.5)}',
    'html body .pp-layout .sidebar-top{display:flex!important}',
    'html body .pp-layout .sidebar-close{display:none!important}',
    '.pp-drawer-close{display:grid;place-items:center;align-self:flex-end;width:44px;height:44px;margin:0 8px 0 0;border:0;border-radius:50%;background:transparent;color:var(--pp-text);font-size:28px;line-height:1;cursor:pointer}',
    '.pp-scrim{display:block;position:fixed;inset:0;z-index:1990;background:rgba(4,2,12,.62);opacity:0;pointer-events:none;transition:opacity .2s}',
    'html.pp-drawer-open .pp-scrim{opacity:1;pointer-events:auto}',
    'html.pp-drawer-open{overflow:hidden}',
    '.pp-menu-btn{display:grid;place-items:center;position:fixed;left:12px;bottom:calc(16px + env(safe-area-inset-bottom,0px));z-index:1500;width:52px;height:52px;border:1px solid rgba(255,255,255,.25);border-radius:50%;background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;cursor:pointer;box-shadow:0 8px 24px -6px rgba(168,85,247,.8);touch-action:manipulation;-webkit-tap-highlight-color:transparent}',
    '.pp-menu-btn svg{width:24px;height:24px}',
    'html body{background-attachment:scroll}',
    '.pp-page{padding-bottom:calc(60px + env(safe-area-inset-bottom,0px))}',
    '}',

    /* ---- small-screen polish ---- */
    '.pp-btn,.pp-btn-secondary,.pp-tab{touch-action:manipulation;-webkit-tap-highlight-color:transparent}',
    '@media(max-width:560px){',
    '.pp-btn{min-height:44px}',
    '.pq-item{grid-template-columns:38px minmax(0,1fr) auto;gap:6px 10px;padding:10px 12px}',
    '.pq-ico{width:38px;height:38px;font-size:17px}',
    '.pp-dialog-actions{flex-direction:column-reverse}',
    '.pp-dialog-actions>*{width:100%;min-height:46px}',
    '.pp-toast{bottom:calc(16px + env(safe-area-inset-bottom,0px));width:calc(100vw - 32px);box-sizing:border-box}',
    '.pp-checkin-foot .pp-btn{width:100%}',
    '}',
    '@media(prefers-reduced-motion:reduce){html body .pp-layout>.app-sidebar,.pp-scrim{transition:none!important}}'
  ].join('\n');

  function ready(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn); else fn();
  }

  ready(function () {
    // Appended to <body> so it comes after the page's own <style> and wins ties.
    var style = document.createElement('style');
    style.textContent = css;
    document.body.appendChild(style);

    var $ = function (id) { return document.getElementById(id); };
    function node(tag, cls, text) {
      var n = document.createElement(tag);
      if (cls) n.className = cls;
      if (text != null) n.textContent = text;
      return n;
    }

    /* ================= Daily Quests card ================= */
    var QUESTS = [
      { key: 'watch', icon: '\uD83C\uDFAC', title: 'Watch videos', sub: function (q) { return '+' + q.points + ' pts each \u00b7 watch at least 30 seconds'; }, href: 'watch.php', link: 'Watch' },
      { key: 'like', icon: '\uD83D\uDC4D', title: 'Like videos', sub: function (q) { return '+' + q.points + ' pt each \u00b7 your own videos don\u2019t count'; }, href: 'watch.php', link: 'Explore' },
      { key: 'comment', icon: '\uD83D\uDCAC', title: 'Leave a comment', sub: function (q) { return '+' + q.points + ' pts \u00b7 at least 10 characters'; }, href: 'watch.php', link: 'Watch' }
    ];
    var card = null, list = null, chip = null, foot = null, freezeBox = null;

    function buildQuestCard() {
      var anchor = document.querySelector('.pp-vipbar');
      if (!anchor || !anchor.parentNode) return;
      var row = node('div', 'pp-row pp-row-1');
      card = node('section', 'pp-card');
      card.id = 'ppQuests';
      card.setAttribute('aria-labelledby', 'ppQuestsTitle');
      var head = node('div', 'pp-head');
      var ico = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      ico.setAttribute('class', 'pp-head-ico'); ico.setAttribute('viewBox', '0 0 24 24'); ico.setAttribute('aria-hidden', 'true');
      var use = document.createElementNS('http://www.w3.org/2000/svg', 'use'); use.setAttribute('href', '#ppStar');
      ico.appendChild(use);
      var h2 = node('h2', '', 'Daily Quests'); h2.id = 'ppQuestsTitle';
      chip = node('span', 'pp-chip', '\u2014');
      head.appendChild(ico); head.appendChild(h2); head.appendChild(chip);
      list = node('ul', 'pq-list');
      var loading = node('li', 'pp-note', 'Loading your quests\u2026');
      list.appendChild(loading);
      foot = node('p', 'pp-note pq-foot', 'Quests reset every day at midnight.');
      freezeBox = node('div', 'pq-freeze');
      card.appendChild(head); card.appendChild(list); card.appendChild(freezeBox); card.appendChild(foot);
      row.appendChild(card);
      anchor.parentNode.insertBefore(row, anchor);
    }

    function renderQuests(q) {
      if (!list) return;
      list.textContent = '';
      if (!q) {
        list.appendChild(node('li', 'pp-note', 'Sign in to start earning daily quest points.'));
        chip.textContent = '\u2014';
        return;
      }
      var finished = 0, maxPoints = 0;
      QUESTS.forEach(function (def) {
        var d = q[def.key]; if (!d) return;
        var done = d.done >= d.cap;
        if (done) finished += 1;
        maxPoints += d.cap * d.points;
        var li = node('li', 'pq-item' + (done ? ' is-done' : ''));
        li.appendChild(node('span', 'pq-ico', done ? '\u2713' : def.icon));
        var main = node('div', 'pq-main');
        main.appendChild(node('strong', '', def.title + ' (' + d.cap + ')'));
        main.appendChild(node('span', 'pq-sub', def.sub(d)));
        li.appendChild(main);
        li.appendChild(node('span', 'pq-state', done ? 'Done' : d.done + ' / ' + d.cap));
        var bar = node('div', 'pp-bar'); var fill = document.createElement('span');
        fill.style.width = Math.min(100, d.done / d.cap * 100) + '%'; bar.appendChild(fill);
        li.appendChild(bar);
        if (!done) { var a = node('a', 'pq-go', def.link + ' \u2192'); a.href = def.href; li.appendChild(a); }
        list.appendChild(li);
      });
      if (q.bonus) {
        var b = q.bonus, bonusDone = !!b.done;
        maxPoints += b.points;
        var bli = node('li', 'pq-item is-bonus' + (bonusDone ? ' is-done' : ''));
        bli.appendChild(node('span', 'pq-ico', bonusDone ? '\u2713' : '\uD83C\uDF81'));
        var bmain = node('div', 'pq-main');
        bmain.appendChild(node('strong', '', 'Finish every quest'));
        bmain.appendChild(node('span', 'pq-sub', 'Complete all three for a +' + b.points + ' point bonus'));
        bli.appendChild(bmain);
        bli.appendChild(node('span', 'pq-state', bonusDone ? 'Claimed' : '+' + b.points + ' pts'));
        list.appendChild(bli);
      }
      chip.textContent = finished + ' / ' + QUESTS.length + ' done';
      foot.textContent = 'Earn up to ' + maxPoints + ' points a day from quests. Quests reset every day at midnight.';
    }

    /* ================= streak freeze, activity, achievements, level-up ================= */
    function toastMsg(text) {
      var t = $('ppToast');
      if (!t) { window.alert(text); return; }
      t.textContent = text;
      t.classList.add('show');
      setTimeout(function () { t.classList.remove('show'); }, 4500);
    }

    function renderFreeze(f) {
      if (!freezeBox) return;
      freezeBox.textContent = '';
      if (!f) return;
      var li = node('div', 'pq-item');
      li.appendChild(node('span', 'pq-ico', '\u2744\uFE0F'));
      var main = node('div', 'pq-main');
      main.appendChild(node('strong', '', 'Streak Freeze'));
      main.appendChild(node('span', 'pq-sub', f.available
        ? 'You missed yesterday. Save your streak for ' + f.cost + ' points.'
        : (f.reason || 'Missed a day? Save your streak for ' + f.cost + ' points.')));
      li.appendChild(main);
      var btn = node('button', 'pq-btn', f.available ? 'Use freeze \u00b7 ' + f.cost + ' pts' : 'Not available');
      btn.type = 'button';
      btn.disabled = !f.available;
      btn.addEventListener('click', function () {
        if (typeof VSPremium === 'undefined' || !window.confirm('Use a streak freeze for ' + f.cost + ' points? It fills in yesterday so your streak continues.')) return;
        btn.disabled = true;
        VSPremium.freeze().then(function () {
          toastMsg('Streak saved! Your streak continues.');
          setTimeout(function () { window.location.reload(); }, 900);
        }, function (err) {
          toastMsg((err && err.message) || 'Could not use the freeze.');
          btn.disabled = false;
        });
      });
      li.appendChild(btn);
      freezeBox.appendChild(li);
    }

    var ACT = {
      upload: ['\uD83C\uDFAC', 'Published a video or Short'],
      daily_checkin: ['\uD83C\uDF81', 'Daily check-in'],
      streak_bonus: ['\uD83D\uDD25', 'Streak bonus'],
      streak_freeze: ['\u2744\uFE0F', 'Streak freeze'],
      quest_watch: ['\uD83C\uDFAC', 'Quest: watched a video'],
      quest_like: ['\uD83D\uDC4D', 'Quest: liked a video'],
      quest_comment: ['\uD83D\uDCAC', 'Quest: commented'],
      quest_bonus: ['\uD83C\uDF81', 'All daily quests done'],
      premium_monthly: ['\uD83D\uDC51', 'Redeemed 31 days of Premium'],
      premium_plus: ['\uD83D\uDC8E', 'Redeemed 31 days of Premium Plus'],
      premium_plus_3m: ['\uD83C\uDF81', 'Redeemed 93 days of Premium Plus']
    };
    var actList = null, achGrid = null, achChip = null;

    function buildInfoRow() {
      var anchor = document.querySelector('.pp-vipbar');
      if (!anchor || !anchor.parentNode) return;
      var row = node('div', 'pp-row pp-row-2');

      var a = node('section', 'pp-card');
      var ah = node('div', 'pp-head');
      var aIco = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      aIco.setAttribute('class', 'pp-head-ico'); aIco.setAttribute('viewBox', '0 0 24 24'); aIco.setAttribute('aria-hidden', 'true');
      var aUse = document.createElementNS('http://www.w3.org/2000/svg', 'use'); aUse.setAttribute('href', '#ppCalendar');
      aIco.appendChild(aUse);
      ah.appendChild(aIco); ah.appendChild(node('h2', '', 'Recent Activity'));
      actList = node('ul', 'pa-list');
      actList.appendChild(node('li', 'pp-note', 'Sign in to see your activity.'));
      a.appendChild(ah); a.appendChild(actList);

      var b = node('section', 'pp-card');
      var bh = node('div', 'pp-head');
      var bIco = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      bIco.setAttribute('class', 'pp-head-ico'); bIco.setAttribute('viewBox', '0 0 24 24'); bIco.setAttribute('aria-hidden', 'true');
      var bUse = document.createElementNS('http://www.w3.org/2000/svg', 'use'); bUse.setAttribute('href', '#ppTrophy');
      bIco.appendChild(bUse);
      achChip = node('span', 'pp-chip', '\u2014');
      bh.appendChild(bIco); bh.appendChild(node('h2', '', 'Achievements')); bh.appendChild(achChip);
      achGrid = node('div', 'pa-grid');
      b.appendChild(bh); b.appendChild(achGrid);

      row.appendChild(a); row.appendChild(b);
      anchor.parentNode.insertBefore(row, anchor);
    }

    function renderActivity(rows) {
      if (!actList) return;
      actList.textContent = '';
      rows = rows || [];
      if (!rows.length) { actList.appendChild(node('li', 'pp-note', 'No activity yet. Check in or finish a quest!')); return; }
      rows.slice(0, 8).forEach(function (row) {
        var meta = ACT[row.reason] || ['\u2B50', String(row.reason || 'Points').replace(/_/g, ' ').replace(/^./, function (ch) { return ch.toUpperCase(); })];
        var d = new Date(String(row.createdAt).replace(' ', 'T'));
        var when = isNaN(d) ? '' : d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
        var li = node('li', 'pa-item');
        li.appendChild(node('span', 'pa-ico', meta[0]));
        var body = node('span', 'pa-body');
        body.appendChild(node('strong', '', meta[1]));
        body.appendChild(node('small', '', when));
        li.appendChild(body);
        li.appendChild(node('span', 'pa-amt' + (row.amount < 0 ? ' neg' : ''), (row.amount > 0 ? '+' : '') + Number(row.amount).toLocaleString()));
        actList.appendChild(li);
      });
    }

    var ACH_ICON = { first_points: '\u2B50', creator: '\uD83C\uDFAC', prolific: '\uD83D\uDE80', regular: '\uD83D\uDCC5', on_fire: '\uD83D\uDD25', quest_master: '\uD83C\uDFAF', chatterbox: '\uD83D\uDCAC', gold_rank: '\uD83E\uDD47', collector: '\uD83D\uDC51' };
    function renderAchievements(list) {
      if (!achGrid) return;
      achGrid.textContent = '';
      list = list || [];
      var done = 0;
      list.forEach(function (a) {
        if (a.done) done += 1;
        var tile = node('div', 'pa-tile' + (a.done ? ' done' : ''));
        tile.appendChild(node('span', 'pa-ico', a.done ? (ACH_ICON[a.id] || '\u2B50') : '\uD83D\uDD12'));
        tile.appendChild(node('strong', '', a.title));
        tile.appendChild(node('span', '', a.desc));
        var bar = node('div', 'pp-bar'); var fill = document.createElement('span');
        fill.style.width = Math.min(100, a.progress / a.target * 100) + '%'; bar.appendChild(fill);
        tile.appendChild(bar);
        if (!a.done && a.target > 1) tile.appendChild(node('span', '', a.progress.toLocaleString() + ' / ' + a.target.toLocaleString()));
        achGrid.appendChild(tile);
      });
      achChip.textContent = list.length ? done + ' / ' + list.length : '\u2014';
    }

    var LEVEL_COLORS = ['#e79a62', '#aab3e8', '#ffc23d', '#5ccbf5', '#c58bff'];
    function celebrate(idx) {
      var overlay = node('div', 'pl-overlay');
      overlay.setAttribute('role', 'dialog'); overlay.setAttribute('aria-label', 'Level up');
      var box = node('div', 'pl-box');
      box.appendChild(node('div', '', '\uD83C\uDFC6'));
      box.lastChild.style.fontSize = '54px';
      box.appendChild(node('h2', '', 'LEVEL UP!'));
      var name = node('p', '', 'You reached ' + LEVELS[idx][0] + ' level. Keep earning!');
      box.appendChild(name);
      var ok = node('button', 'pq-btn', 'Awesome!'); ok.type = 'button'; ok.style.justifySelf = 'auto';
      box.appendChild(ok);
      box.firstChild.style.filter = 'drop-shadow(0 0 14px ' + LEVEL_COLORS[idx] + ')';
      overlay.appendChild(box);
      document.body.appendChild(overlay);
      var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (!reduce) {
        var colors = [LEVEL_COLORS[idx], '#ffc23d', '#ff2e93', '#a855f7', '#2fe08a', '#ffffff'];
        for (var i = 0; i < 48; i += 1) {
          var piece = node('span', 'pl-conf');
          piece.style.left = (Math.random() * 100) + 'vw';
          piece.style.background = colors[i % colors.length];
          piece.style.setProperty('--x', (Math.random() * 160 - 80) + 'px');
          piece.style.setProperty('--r', (Math.random() * 900 - 450) + 'deg');
          piece.style.setProperty('--d', (1.8 + Math.random() * 1.4) + 's');
          piece.style.animationDelay = (Math.random() * .4) + 's';
          piece.addEventListener('animationend', function () { this.remove(); });
          document.body.appendChild(piece);
        }
      }
      function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
      function onKey(e) { if (e.key === 'Escape') close(); }
      ok.addEventListener('click', close);
      overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
      document.addEventListener('keydown', onKey);
      ok.focus();
    }
    function checkLevelUp(lifetime) {
      var idx = 0;
      LEVELS.forEach(function (l, i) { if (lifetime >= l[1]) idx = i; });
      var stored = null;
      try { stored = window.localStorage.getItem('vs-rewards-level'); } catch (e) {}
      var before = stored === null ? idx : (Number(stored) || 0);
      try { window.localStorage.setItem('vs-rewards-level', String(Math.max(idx, before))); } catch (e) {}
      if (stored !== null && idx > before) celebrate(idx);
    }

    /* ================= rewards level (Lifetime Earned card) ================= */
    var LEVELS = [
      ['Bronze', 0], ['Silver', 250], ['Gold', 1000], ['Platinum', 2500], ['Diamond', 5000]
    ];
    function renderLevel(lifetime) {
      var note = $('ppLifetimeNote');
      if (!note) return;
      var idx = 0;
      LEVELS.forEach(function (l, i) { if (lifetime >= l[1]) idx = i; });
      var next = LEVELS[idx + 1];
      note.textContent = LEVELS[idx][0] + ' level' + (next ? ' \u00b7 ' + (next[1] - lifetime).toLocaleString() + ' pts to ' + next[0] : ' \u00b7 Top level!');
      var body = note.parentNode, bar = $('ppLevelBar');
      if (!bar) {
        bar = node('div', 'pp-bar'); bar.id = 'ppLevelBar';
        bar.appendChild(document.createElement('span'));
        body.appendChild(bar);
      }
      bar.firstChild.style.width = (next ? (lifetime - LEVELS[idx][1]) / (next[1] - LEVELS[idx][1]) * 100 : 100) + '%';
    }

    /* ================= data ================= */
    var fetching = false;
    function loadRewards() {
      if (typeof VSPremium === 'undefined' || !VSPremium.LOGGED_IN || !VSPremium.pointsAvailable() || fetching) {
        if (typeof VSPremium === 'undefined' || !VSPremium.LOGGED_IN) renderQuests(null);
        return;
      }
      fetching = true;
      VSPremium.rewards().then(function (r) {
        fetching = false;
        if (!r) return;
        renderQuests(r.quests || null);
        renderLevel(Number(r.lifetimeEarned) || 0);
        renderFreeze(r.freeze || null);
        renderActivity(r.activity || []);
        renderAchievements(r.achievements || []);
        checkLevelUp(Number(r.lifetimeEarned) || 0);
      }, function () { fetching = false; });
    }
    buildQuestCard();
    buildInfoRow();
    loadRewards();
    // Come back from watching a video: refresh the quest progress.
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') loadRewards(); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) loadRewards(); });

    /* ================= phone menu drawer ================= */
    (function () {
      var sidebar = document.querySelector('.pp-layout > .app-sidebar');
      if (!sidebar) return;
      var root = document.documentElement, media = window.matchMedia('(max-width: 900px)');
      sidebar.id = sidebar.id || 'ppDrawer';
      var scrim = node('div', 'pp-scrim');
      var open = node('button', 'pp-menu-btn'); open.type = 'button';
      open.setAttribute('aria-label', 'Open menu'); open.setAttribute('aria-controls', sidebar.id); open.setAttribute('aria-expanded', 'false');
      open.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>';
      var close = node('button', 'pp-drawer-close'); close.type = 'button';
      close.setAttribute('aria-label', 'Close menu'); close.innerHTML = '&times;';
      sidebar.insertBefore(close, sidebar.firstChild);
      document.body.appendChild(scrim); document.body.appendChild(open);
      function setOpen(on) {
        root.classList.toggle('pp-drawer-open', on);
        open.setAttribute('aria-expanded', on ? 'true' : 'false');
        if (on) close.focus(); else if (document.activeElement && sidebar.contains(document.activeElement)) open.focus();
      }
      open.addEventListener('click', function () { setOpen(true); });
      close.addEventListener('click', function () { setOpen(false); });
      scrim.addEventListener('click', function () { setOpen(false); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && root.classList.contains('pp-drawer-open')) setOpen(false); });
      (media.addEventListener ? media.addEventListener.bind(media, 'change') : media.addListener.bind(media))(function () {
        if (!media.matches) root.classList.remove('pp-drawer-open');
      });
    })();

    /* ================= leaderboard tabs ================= */
    (function () {
      var board = $('ppLeaders');
      if (!board || typeof VSPremium === 'undefined' || typeof VSPremium.leaderboard !== 'function') return;
      var period = 'all';
      var tabs = node('div', 'pp-tabs'); tabs.setAttribute('role', 'tablist'); tabs.setAttribute('aria-label', 'Leaderboard period');
      [['week', 'This week'], ['month', 'This month'], ['all', 'All time']].forEach(function (t) {
        var b = node('button', 'pp-tab', t[1]); b.type = 'button'; b.setAttribute('role', 'tab');
        b.setAttribute('data-period', t[0]); b.setAttribute('aria-selected', t[0] === period ? 'true' : 'false');
        b.addEventListener('click', function () {
          period = t[0];
          Array.prototype.forEach.call(tabs.children, function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
          draw();
        });
        tabs.appendChild(b);
      });
      board.parentNode.insertBefore(tabs, board);

      function message(text) { board.textContent = ''; board.appendChild(node('li', '', text)); }
      function draw() {
        VSPremium.leaderboard(period).then(function (rows) {
          rows = (rows || []).slice(0, 5);
          if (!rows.length) {
            message(period === 'all' ? 'No earners yet. Be the first!' : 'No points earned ' + (period === 'week' ? 'this week' : 'this month') + ' yet. Be the first!');
            return;
          }
          board.textContent = '';
          rows.forEach(function (row, i) {
            var name = String(row.name || 'Member');
            var li = node('li', 'is-row' + (row.me ? ' is-me' : ''));
            li.appendChild(node('span', 'pp-rank', String(i + 1)));
            var avatar = node('span', 'pp-leader-avatar', name.trim().charAt(0).toUpperCase());
            if (row.avatar) {
              var img = document.createElement('img');
              img.alt = ''; img.loading = 'lazy'; img.onerror = function () { img.remove(); }; img.src = row.avatar;
              avatar.appendChild(img);
            }
            li.appendChild(avatar);
            var nm = node('span', 'pp-leader-name', name);
            if (row.me) nm.appendChild(node('span', 'pp-you', 'YOU'));
            li.appendChild(nm);
            if (row.vip) { var vip = node('span', 'pp-vip-badge', 'VIP'); vip.title = 'Active Premium member'; li.appendChild(vip); }
            li.appendChild(node('span', 'pp-leader-pts', Number(row.points || 0).toLocaleString()));
            board.appendChild(li);
          });
        }, function () { message('Leaderboard unavailable.'); });
      }
      draw();
    })();
  });
})();