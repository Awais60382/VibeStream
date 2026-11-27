<!-- Paste this block in header.php, directly above the closing </head> tag. -->
<style id="vs-admin-redblack-fix">
  /* Replace the leftover purple/navy shell colours with red and black */
  html[data-theme="dark"] {
    --vs-shell-bg:#070505; --vs-shell-panel:#0b0707; --vs-shell-soft:#160c0d;
    --vs-shell-text:#f7f1f1; --vs-shell-muted:#bfb0b0; --vs-shell-line:rgba(255,42,50,.26);
    --vs-shell-active:linear-gradient(110deg,#ff343c,#ad0c13); --vs-shell-accent:#ff8a8f;
  }
  html[data-theme="light"] {
    --vs-shell-bg:#f7f5f5; --vs-shell-panel:#fff; --vs-shell-soft:#fff0f0;
    --vs-shell-text:#291a1a; --vs-shell-muted:#766565; --vs-shell-line:rgba(205,24,32,.2);
    --vs-shell-active:linear-gradient(110deg,#e5131b,#a80810); --vs-shell-accent:#be1e27;
  }
  /* Card and field colours used by every admin page (were purple-tinted navy) */
  html[data-theme="dark"] .admin-shell :is(.dashboard-content,.admin-content) {
    --vs-page-card:#100808; --vs-page-field:#0b0606; --vs-page-line:rgba(255,42,50,.30);
  }
  /* Page background: was navy (#090b18) */
  html[data-theme="dark"] .admin-shell .dashboard-content {
    background:
      radial-gradient(850px 360px at 100% 0,rgba(255,26,26,.09),transparent 58%),
      radial-gradient(700px 320px at 0 100%,rgba(255,26,26,.045),transparent 62%),
      #090909 !important;
    color:#f7f3f3;
  }
  /* Sidebar card and hover colours */
  .admin-shell .vs-side-card {
    background:radial-gradient(circle at 50% 0,rgba(255,26,26,.22),transparent 70%),#0b0606 !important;
    border-color:rgba(255,42,50,.30) !important;
  }
  html[data-theme="light"] .admin-shell .vs-side-card { background:linear-gradient(145deg,#fff5f5,#fff) !important; }
  .admin-shell .vs-create:hover { border-color:#ff4d52; }
  .admin-shell .sidebar-nav .nav-link.active { box-shadow:0 5px 18px rgba(255,26,26,.28) !important; }
</style>
