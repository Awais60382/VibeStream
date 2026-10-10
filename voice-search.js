/*
 * VibeStream - voice search mic for the app top bar (<header class="app-navbar">).
 * Load it on pages that use that bar:
 *   <script src="voice-search.js?v=1" defer></script>
 * (navbar-fix.css already styles the button.)
 */
(function () {
  function init() {
    var form = document.querySelector('.app-navbar .nav-center .search-form');
    if (!form || document.getElementById('vs-app-mic-btn')) { return; }
    var input = form.querySelector('input');
    if (!input) { return; }

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'vs-app-mic-btn';
    btn.className = 'vs-mic-btn';
    btn.setAttribute('aria-label', 'Search with your voice');
    btn.title = 'Search with your voice';
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v4M8 22h8"/></svg>';
    form.insertAdjacentElement('afterend', btn);

    var normalPlaceholder = input.placeholder || 'Search';
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    var rec = null, listening = false, msgTimer = null;

    function note(text) {
      input.placeholder = text;
      clearTimeout(msgTimer);
      msgTimer = setTimeout(function () { if (!listening) { input.placeholder = normalPlaceholder; } }, 3500);
    }
    function stop() { listening = false; btn.classList.remove('is-listening'); input.placeholder = normalPlaceholder; }
    function runSearch() {
      if (!input.value.trim()) { input.focus(); return; }
      if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
    }

    btn.addEventListener('click', function () {
      if (!SR) { note('Voice search needs Chrome, Edge or Safari'); return; }
      if (listening && rec) { rec.stop(); return; }
      rec = new SR();
      rec.lang = document.documentElement.lang || 'en-US';
      rec.interimResults = true;
      rec.maxAlternatives = 1;
      rec.onstart = function () { listening = true; btn.classList.add('is-listening'); input.placeholder = 'Listening...'; };
      rec.onresult = function (e) {
        var text = '';
        for (var i = 0; i < e.results.length; i++) { text += e.results[i][0].transcript; }
        input.value = text;
        input.dispatchEvent(new Event('input', { bubbles: true }));
      };
      rec.onerror = function (e) {
        stop();
        if (e.error === 'not-allowed' || e.error === 'service-not-allowed') { note('Allow microphone access (needs HTTPS)'); }
        else if (e.error === 'no-speech') { note('No speech heard, try again'); }
        else { note('Voice search unavailable'); }
      };
      rec.onend = function () { var had = input.value.trim(); stop(); if (had) { runSearch(); } };
      try { rec.start(); } catch (err) { stop(); }
    });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
