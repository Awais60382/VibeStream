
(function () {
    var BREAKPOINT = 1000;

    function isMobile() {
        return window.innerWidth <= BREAKPOINT;
    }

    function isOpen() {
        var b = document.body;
        return isMobile()
            ? b.classList.contains('sidebar-open')
            : !b.classList.contains('sidebar-collapsed');
    }

    function setSidebar(open) {
        var b = document.body;
        if (isMobile()) {
            b.classList.toggle('sidebar-open', open);
        } else {
            b.classList.toggle('sidebar-collapsed', !open);
        }
        var toggle = document.getElementById('navToggle');
        if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function init() {
        var toggle = document.getElementById('navToggle');
        if (toggle) {
            toggle.addEventListener('click', function () {
                setSidebar(!isOpen());
            });
        }

        var closers = document.querySelectorAll('[data-sidebar-close]');
        for (var i = 0; i < closers.length; i++) {
            closers[i].addEventListener('click', function () {
                setSidebar(false);
            });
        }

        var links = document.querySelectorAll('.sidebar-link');
        for (var j = 0; j < links.length; j++) {
            links[j].addEventListener('click', function () {
                if (isMobile()) setSidebar(false);
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isMobile()) setSidebar(false);
        });

        window.addEventListener('resize', function () {
            if (!isMobile()) document.body.classList.remove('sidebar-open');
            if (toggle) toggle.setAttribute('aria-expanded', isOpen() ? 'true' : 'false');
        });

        if (toggle) toggle.setAttribute('aria-expanded', isOpen() ? 'true' : 'false');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
