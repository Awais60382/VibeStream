(function () {
  function postEvent(videoId, contentType, eventType, seconds, keepalive) {
    const body = new URLSearchParams({
      video_id: videoId,
      content_type: contentType,
      event_type: eventType
    });
    if (seconds) body.set('seconds', String(seconds));
    return fetch('creator-analytics-event-api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
      credentials: 'same-origin',
      keepalive: !!keepalive
    }).then(function (response) {
      return response.json().then(function (result) {
        if (!response.ok || !result.success) {
          throw new Error(result.error || 'Could not save creator analytics event');
        }
      });
    });
  }

  window.VSAnalyticsTracking = {
    trackWatchTime: function (videoId, contentType) {
      let isPlaying = false;
      let pendingSeconds = 0;
      let lastTick = Date.now();

      function flush(useBeacon) {
        if (pendingSeconds < 1) return;
        const seconds = Math.min(30, pendingSeconds);
        pendingSeconds -= seconds;
        const body = new URLSearchParams({
          video_id: videoId,
          content_type: contentType,
          event_type: 'watch_time',
          seconds: String(seconds)
        });
        if (useBeacon && navigator.sendBeacon
          && navigator.sendBeacon('creator-analytics-event-api.php', body)) {
          return;
        }
        postEvent(videoId, contentType, 'watch_time', seconds, true).catch(function (error) {
          pendingSeconds = Math.min(30, pendingSeconds + seconds);
          console.error('Could not record creator watch time:', error);
        });
      }

      window.setInterval(function () {
        const now = Date.now();
        const elapsed = Math.min(5, Math.floor((now - lastTick) / 1000));
        lastTick = now;
        if (isPlaying && document.visibilityState === 'visible') {
          pendingSeconds = Math.min(30, pendingSeconds + elapsed);
          if (pendingSeconds >= 10) flush(false);
        }
      }, 1000);

      document.addEventListener('visibilitychange', function () {
        lastTick = Date.now();
        if (document.visibilityState === 'hidden') flush(true);
      });
      window.addEventListener('pagehide', function () {
        flush(true);
      });

      return {
        setPlaying: function (playing) {
          isPlaying = !!playing;
          lastTick = Date.now();
        }
      };
    },
    trackShare: function (videoId, contentType) {
      postEvent(videoId, contentType, 'share', 0, true).catch(function (error) {
        console.error('Could not record creator share:', error);
      });
    }
  };
})();
