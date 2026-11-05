(() => {
    const page = document.getElementById('page-container');
    const sidebar = document.getElementById('sidebar');
    const pageHeader = document.getElementById('page-header');
    const backgroundRegions = ['page-header', 'main-container', 'page-footer']
        .map((id) => document.getElementById(id))
        .filter(Boolean);
    const opener = document.querySelector('[data-action="sidebar_open"]');
    const backdrop = document.querySelector('[data-action="sidebar_backdrop"]');
    const desktopWidthToggle = document.querySelector('[data-action="sidebar_mini_toggle"]');

    if (!page?.classList.contains('command-os-page') || !sidebar || !pageHeader || backgroundRegions.length === 0 || !opener || !backdrop) {
        return;
    }

    let returnFocus = null;
    const focusableItems = () => Array.from(sidebar.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'))
        .filter((item) => !item.hidden
            && item.getAttribute('aria-disabled') !== 'true'
            && item.getClientRects().length > 0
            && !item.closest('[hidden], [aria-hidden="true"]'));
    const syncDesktopWidthToggle = () => {
        if (desktopWidthToggle) {
            desktopWidthToggle.setAttribute('aria-pressed', String(page.classList.contains('sidebar-mini')));
        }
    };

    syncDesktopWidthToggle();
    if (desktopWidthToggle && typeof MutationObserver === 'function') {
        const widthStateObserver = new MutationObserver(syncDesktopWidthToggle);
        widthStateObserver.observe(page, { attributes: true, attributeFilter: ['class'] });
    }

    const close = (restoreFocus = true, syncOneUiLayout = true) => {
        if (!page.classList.contains('command-os-sidebar-open')) {
            return;
        }

        page.classList.remove('command-os-sidebar-open');
        if (syncOneUiLayout && window.App && typeof window.App.layout === 'function') {
            window.App.layout('sidebar_close');
        }
        page.classList.remove('sidebar-o-xs');
        sidebar.removeAttribute('aria-modal');
        sidebar.removeAttribute('role');
        opener.setAttribute('aria-expanded', 'false');
        backgroundRegions.forEach((region) => { region.inert = false; });
        backdrop.hidden = true;
        if (restoreFocus && returnFocus?.isConnected) {
            returnFocus.focus();
        }
        returnFocus = null;
    };

    const open = () => {
        if (page.classList.contains('command-os-sidebar-open')) {
            return;
        }

        returnFocus = document.activeElement;
        page.classList.add('command-os-sidebar-open');
        sidebar.setAttribute('role', 'dialog');
        sidebar.setAttribute('aria-modal', 'true');
        opener.setAttribute('aria-expanded', 'true');
        backgroundRegions.forEach((region) => { region.inert = true; });
        backdrop.hidden = false;
        focusableItems()[0]?.focus();
    };

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        if (window.matchMedia('(min-width: 992px)').matches
            && page.classList.contains('sidebar-mini')
            && target.closest('#sidebar a[data-toggle="submenu"]')) {
            // Let OneUI's delegated click handler activate the existing submenu after the rail expands.
            page.classList.remove('sidebar-mini');
            syncDesktopWidthToggle();
        }

        if (target.closest('[data-action="sidebar_open"]')) {
            event.preventDefault();
            open();
        } else if (target.closest('[data-action="sidebar_close"], [data-action="sidebar_backdrop"]')) {
            event.preventDefault();
            close();
        }
    }, true);

    document.addEventListener('keydown', (event) => {
        if (!page.classList.contains('command-os-sidebar-open')) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }

        const items = focusableItems();
        if (items.length === 0) {
            event.preventDefault();
            sidebar.focus();
            return;
        }

        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && (document.activeElement === first || !sidebar.contains(document.activeElement))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (document.activeElement === last || !sidebar.contains(document.activeElement))) {
            event.preventDefault();
            first.focus();
        }
    });

    const desktopBreakpoint = window.matchMedia('(min-width: 992px)');
    desktopBreakpoint.addEventListener('change', (event) => {
        if (event.matches) {
            close(false, false);
        }
    });
})();
