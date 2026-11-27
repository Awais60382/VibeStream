(function () {
  'use strict';

  if (window.__vsAnnouncementNotificationsStarted) return;
  window.__vsAnnouncementNotificationsStarted = true;

  var currentScript = document.currentScript;
  var scriptUrl = currentScript ? new URL(currentScript.src) : null;
  var styles = document.createElement('link');
  var stylesLoaded = false;
  styles.rel = 'stylesheet';
  styles.href = 'announcements-notifications.css?v='
    + encodeURIComponent(scriptUrl ? (scriptUrl.searchParams.get('v') || '1') : '1');
  styles.addEventListener('load', function () {
    stylesLoaded = true;
    if (pendingAnnouncement && document.visibilityState === 'visible') showDialog(pendingAnnouncement);
  });
  styles.addEventListener('error', function (error) {
    console.error('Announcement popup styles could not be loaded.', error);
    stylesLoaded = true;
    if (pendingAnnouncement && document.visibilityState === 'visible') showDialog(pendingAnnouncement);
  });
  document.head.appendChild(styles);

  var seenKey = 'vibestream:seen-announcements';
  var seenIds = new Set();
  var pendingAnnouncement = null;
  var currentAnnouncement = null;
  var overlay = null;
  var notificationStatus = null;
  var enableNotificationsButton = null;
  var dialogTitle = null;
  var dialogBody = null;
  var dialogKind = null;
  var detailsLink = null;
  var previousFocus = null;
  var kindLabels = {
    announcement: 'Announcement',
    upcoming: 'Upcoming update',
    release: 'Release notes',
    maintenance: 'Maintenance'
  };

  try {
    var savedIds = JSON.parse(window.localStorage.getItem(seenKey) || '[]');
    if (Array.isArray(savedIds)) {
      savedIds.forEach(function (id) { seenIds.add(String(id)); });
    }
  } catch (error) {
    console.error('Previously seen announcements could not be read.', error);
  }

  function isSeen(id) {
    return seenIds.has(String(id));
  }

  function markSeen(id) {
    seenIds.add(String(id));
    while (seenIds.size > 100) {
      seenIds.delete(seenIds.values().next().value);
    }
    try {
      window.localStorage.setItem(seenKey, JSON.stringify(Array.from(seenIds)));
    } catch (error) {
      console.error('Announcement dismissal could not be saved in this browser.', error);
    }
  }

  function buildDialog() {
    if (overlay) return;
    overlay = document.createElement('div');
    overlay.className = 'vs-announcement-overlay';
    overlay.hidden = true;
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML =
      '<section class="vs-announcement-dialog" role="dialog" aria-modal="true" aria-labelledby="vsAnnouncementTitle" aria-describedby="vsAnnouncementBody">' +
        '<button class="vs-announcement-close" type="button" aria-label="Close announcement">&times;</button>' +
        '<div class="vs-announcement-kind"><span aria-hidden="true">●</span><span></span></div>' +
        '<h2 id="vsAnnouncementTitle"></h2>' +
        '<p class="vs-announcement-body" id="vsAnnouncementBody"></p>' +
        '<p class="vs-announcement-notification-status" aria-live="polite"></p>' +
        '<div class="vs-announcement-actions">' +
          '<button class="vs-announcement-enable" type="button">Enable browser notifications</button>' +
          '<a href="announcements.php">Read all updates</a>' +
          '<button class="vs-announcement-dismiss" type="button">Got it</button>' +
        '</div>' +
      '</section>';
    document.body.appendChild(overlay);

    dialogTitle = overlay.querySelector('#vsAnnouncementTitle');
    dialogBody = overlay.querySelector('#vsAnnouncementBody');
    dialogKind = overlay.querySelector('.vs-announcement-kind span:last-child');
    notificationStatus = overlay.querySelector('.vs-announcement-notification-status');
    detailsLink = overlay.querySelector('.vs-announcement-actions a');
    enableNotificationsButton = overlay.querySelector('.vs-announcement-enable');

    overlay.querySelector('.vs-announcement-close').addEventListener('click', closeDialog);
    overlay.querySelector('.vs-announcement-dismiss').addEventListener('click', closeDialog);
    detailsLink.addEventListener('click', function () {
      if (currentAnnouncement) markSeen(currentAnnouncement.id);
    });
    overlay.addEventListener('click', function (event) {
      if (event.target === overlay) closeDialog();
    });
    overlay.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeDialog();
      }
    });
    enableNotificationsButton.addEventListener('click', requestNotifications);
  }

  function canUseBrowserNotifications() {
    return window.isSecureContext && 'Notification' in window;
  }

  function updateNotificationControls() {
    if (!enableNotificationsButton) return;
    if (!canUseBrowserNotifications()) {
      enableNotificationsButton.hidden = true;
      notificationStatus.textContent = 'Browser notifications require a supported browser and a secure connection.';
      return;
    }
    if (Notification.permission === 'granted') {
      enableNotificationsButton.hidden = true;
      notificationStatus.textContent = 'Browser notifications are enabled while VibeStream is open.';
    } else if (Notification.permission === 'denied') {
      enableNotificationsButton.hidden = true;
      notificationStatus.textContent = 'Browser notifications are blocked in this browser’s site settings.';
    } else {
      enableNotificationsButton.hidden = false;
      notificationStatus.textContent = 'Optional: browser notifications arrive only while VibeStream is open.';
    }
  }

  function requestNotifications() {
    if (!canUseBrowserNotifications()) return;
    Notification.requestPermission().then(function (permission) {
      updateNotificationControls();
      if (permission !== 'granted') {
        notificationStatus.textContent = 'Browser notification permission was not granted.';
      }
    }).catch(function (error) {
      console.error('Browser notification permission could not be requested.', error);
      notificationStatus.textContent = 'Browser notification permission could not be requested.';
    });
  }

  function showDialog(announcement) {
    if (!announcement || overlay && !overlay.hidden) return;
    if (!stylesLoaded) {
      pendingAnnouncement = announcement;
      return;
    }
    var premiumPrompt = document.getElementById('vsPremiumPrompt');
    if (premiumPrompt && premiumPrompt.open) {
      pendingAnnouncement = announcement;
      premiumPrompt.addEventListener('close', function () {
        if (pendingAnnouncement) showDialog(pendingAnnouncement);
      }, { once: true });
      return;
    }

    buildDialog();
    currentAnnouncement = announcement;
    dialogTitle.textContent = announcement.title;
    dialogBody.textContent = announcement.body;
    dialogKind.textContent = kindLabels[announcement.kind] || 'Announcement';
    detailsLink.href = 'announcements.php#update-' + encodeURIComponent(String(announcement.id));
    notificationStatus.textContent = '';
    updateNotificationControls();
    previousFocus = document.activeElement;
    overlay.hidden = false;
    overlay.setAttribute('aria-hidden', 'false');
    overlay.querySelector('.vs-announcement-close').focus();
  }

  function closeDialog() {
    if (!overlay || overlay.hidden) return;
    if (currentAnnouncement) markSeen(currentAnnouncement.id);
    overlay.hidden = true;
    overlay.setAttribute('aria-hidden', 'true');
    if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
    currentAnnouncement = null;
    pendingAnnouncement = null;
  }

  function notifyWhileOpen(announcement) {
    try {
      var notification = new Notification(announcement.title, {
        body: announcement.body.slice(0, 220),
        icon: 'branding-icon.php',
        tag: 'vibestream-announcement-' + announcement.id,
        renotify: false
      });
      notification.onclick = function () {
        window.focus();
        window.location.href = 'announcements.php#update-' + encodeURIComponent(String(announcement.id));
      };
      notification.onerror = function (error) {
        console.error('The browser could not display an announcement notification.', error);
      };
      markSeen(announcement.id);
    } catch (error) {
      console.error('The browser could not display an announcement notification.', error);
      pendingAnnouncement = announcement;
      if (document.visibilityState === 'visible') showDialog(announcement);
    }
  }

  function deliver(announcement) {
    if (!announcement || !announcement.id || isSeen(announcement.id)) return;
    if (document.visibilityState === 'hidden' && canUseBrowserNotifications()
      && Notification.permission === 'granted') {
      notifyWhileOpen(announcement);
      return;
    }
    pendingAnnouncement = announcement;
    if (document.visibilityState === 'visible') showDialog(announcement);
  }

  function checkForAnnouncement() {
    fetch('announcements-feed.php', {
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    }).then(function (response) {
      if (!response.ok) throw new Error('Announcement feed returned HTTP ' + response.status);
      return response.json();
    }).then(function (data) {
      if (!data || data.ok !== true) throw new Error((data && data.error) || 'Invalid announcement feed response');
      deliver(data.announcement);
    }).catch(function (error) {
      console.error('Announcements could not be checked.', error);
    });
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && pendingAnnouncement) {
      showDialog(pendingAnnouncement);
    }
  });
  window.addEventListener('focus', function () {
    if (pendingAnnouncement) showDialog(pendingAnnouncement);
  });

  checkForAnnouncement();
  window.setInterval(checkForAnnouncement, 60000);
})();
