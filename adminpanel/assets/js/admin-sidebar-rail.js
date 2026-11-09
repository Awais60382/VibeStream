/*
 * VibeStream ADMIN PANEL - slim icon bar for a closed menu (desktop only).
 * Works together with admin-sidebar-rail.css. Put this file in adminpanel/assets/js/.
 *
 *  - Marks the sidebar links: the main ones get "vs-arail-keep" (+ a short label), all others "vs-arail-hide".
 *  - When the menu is closed on a desktop screen (body.sidebar-mini, set by main.js), it turns the slim bar on
 *    by adding "vs-arail" to <html>. Opening the menu again turns it off.
 *
 * To change which icons stay in the slim bar, edit KEEP below: 'file name from the link href': 'short label'.
 */
(function () {
  "use strict";

  var KEEP = {
    "index.php": "Home",
    "videos.php": "Videos",
    "Users.php": "Users",
    "messages.php": "Messages",
    "premium.php": "Premium",
    "profile.php": "Profile",
    "platform-settings.php": "Settings"
  };

  var desktop = window.matchMedia("(min-width: 992px)");
  var root = document.documentElement;

  function markLinks() {
    var links = document.querySelectorAll(".admin-sidebar .sidebar-nav .nav-link");
    Array.prototype.forEach.call(links, function (link) {
      var href = (link.getAttribute("href") || "").split("#")[0].split("?")[0];
      if (Object.prototype.hasOwnProperty.call(KEEP, href)) {
        link.classList.add("vs-arail-keep");
        link.classList.remove("vs-arail-hide");
        link.setAttribute("data-rail-label", KEEP[href]);
        if (!link.getAttribute("title")) {
          link.setAttribute("title", KEEP[href]);
        }
      } else {
        link.classList.add("vs-arail-hide");
        link.classList.remove("vs-arail-keep");
      }
    });
  }

  function syncRail() {
    var on = desktop.matches && document.body.classList.contains("sidebar-mini");
    root.classList.toggle("vs-arail", on);
    root.classList.toggle("vs-arail-fixed", on); // keeps the page content flush with the 76px bar
  }

  function start() {
    markLinks();
    syncRail();
    new MutationObserver(syncRail).observe(document.body, { attributes: true, attributeFilter: ["class"] });
    if (desktop.addEventListener) {
      desktop.addEventListener("change", syncRail);
    } else if (desktop.addListener) {
      desktop.addListener(syncRail);
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();