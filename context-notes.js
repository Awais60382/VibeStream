(function () {
    'use strict';
    document.querySelectorAll('[data-context-notes]').forEach(function (panel) {
        var toggle = panel.querySelector('.context-notes-toggle');
        var form = panel.querySelector('[data-context-form]');
        var status = panel.querySelector('[data-context-status]');
        if (toggle && form) {
            toggle.addEventListener('click', function () {
                var open = form.hidden;
                form.hidden = !open;
                toggle.setAttribute('aria-expanded', String(open));
            });
        }
        if (!form) return;
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            var button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            status.textContent = 'Submitting for review…';
            var data = new FormData(form);
            data.set('video_id', panel.dataset.videoId || '');
            data.set('content_type', panel.dataset.contentType || '');
            try {
                var response = await fetch('context-notes-api.php', { method: 'POST', body: data });
                var result = await response.json();
                if (!response.ok || !result.success) {
                    status.textContent = result.error || 'The context note could not be submitted.';
                    return;
                }
                form.reset();
                form.hidden = true;
                toggle.setAttribute('aria-expanded', 'false');
                status.textContent = result.message;
            } catch (error) {
                console.error('Context note submission failed:', error);
                status.textContent = 'The context note could not be submitted. Check your connection and try again.';
            } finally {
                button.disabled = false;
            }
        });
    });
})();
