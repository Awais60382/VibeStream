(function () {
    'use strict';

    var videos = document.querySelectorAll('video[data-video-preview-src]');
    if (!videos.length) return;

    function loadPreview(video) {
        var src = video.getAttribute('data-video-preview-src');
        if (!src) return;
        video.removeAttribute('data-video-preview-src');
        video.addEventListener('loadedmetadata', function () {
            if (Number.isFinite(video.duration) && video.duration > 0) {
                video.currentTime = Math.min(1, video.duration / 2);
            }
        }, { once: true });
        video.addEventListener('seeked', function () {
            video.classList.add('video-preview-ready');
        }, { once: true });
        video.src = src;
        video.preload = 'metadata';
        video.load();
    }

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                observer.unobserve(entry.target);
                loadPreview(entry.target);
            });
        }, { rootMargin: '200px' });
        videos.forEach(function (video) {
            observer.observe(video);
        });
    } else {
        videos.forEach(loadPreview);
    }
})();
