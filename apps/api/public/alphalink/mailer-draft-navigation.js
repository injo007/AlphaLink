(() => {
    const quickNav = document.querySelector('.quick-nav');
    const trigger = quickNav?.querySelector('.quick-nav-trigger');
    const actionList = quickNav?.querySelector('#mailer-page-actions');
    const overlay = document.querySelector('.quick-nav-overlay');
    const contextMenu = document.querySelector('#mailer-draft-context-menu');
    const composer = document.querySelector('#mailer-composer');

    if (!quickNav || !trigger || !actionList || !overlay || !contextMenu || !composer || quickNav.dataset.alphalinkNavigationBound === 'true') {
        return;
    }

    const selectedServerIds = () => [...(composer.querySelector('#send-servers')?.selectedOptions || [])]
        .map((option) => String(option.value));

    const getPmtaAdminUrl = () => {
        const adminLink = actionList.querySelector('[data-pmta-admin-link]');
        const serverIds = selectedServerIds();
        if (!adminLink || serverIds.length === 0) {
            return null;
        }

        const url = new URL(adminLink.href, window.location.href);
        [...url.searchParams.keys()]
            .filter((key) => key === 'server_ids' || key.startsWith('server_ids['))
            .forEach((key) => url.searchParams.delete(key));
        serverIds.forEach((serverId) => url.searchParams.append('server_ids[]', serverId));

        return url.toString();
    };

    const getPmtaPanelUrls = () => {
        const panelLink = actionList.querySelector('[data-pmta-panel-link]');
        let targets = {};
        try {
            targets = JSON.parse(panelLink?.dataset.pmtaPanelTargets || '{}');
        } catch (_) {
            targets = {};
        }

        return selectedServerIds()
            .map((serverId) => targets[serverId])
            .filter((url) => typeof url === 'string' && url.length > 0);
    };

    let contextFocusTarget = null;

    const closeContextMenu = (restoreFocus = false) => {
        contextMenu.hidden = true;
        contextMenu.replaceChildren();
        contextMenu.style.left = '';
        contextMenu.style.top = '';
        if (restoreFocus) {
            (contextFocusTarget instanceof HTMLElement ? contextFocusTarget : trigger).focus({ preventScroll: true });
        }
    };

    const setQuickNavOpen = (open, focusFirst = false) => {
        closeContextMenu();
        quickNav.classList.toggle('nav-is-visible', open);
        trigger.setAttribute('aria-expanded', String(open));
        trigger.setAttribute('aria-label', open ? 'Close page actions' : 'Open page actions');
        actionList.setAttribute('aria-hidden', String(!open));

        if (open && focusFirst) {
            actionList.querySelector('[role="menuitem"]')?.focus({ preventScroll: true });
        }
    };

    const menuItems = (menu) => [...menu.querySelectorAll('[role="menuitem"]')];

    actionList.querySelectorAll(':scope > li > a').forEach((link) => {
        link.setAttribute('role', 'menuitem');
        link.tabIndex = -1;
    });

    trigger.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        setQuickNavOpen(!quickNav.classList.contains('nav-is-visible'), true);
    });

    overlay.addEventListener('click', () => setQuickNavOpen(false));

    document.addEventListener('click', (event) => {
        if (!quickNav.contains(event.target) && !contextMenu.contains(event.target)) {
            setQuickNavOpen(false);
            closeContextMenu();
        }
    });

    const openContextMenu = (event) => {
        if (!composer.contains(event.target)) {
            return;
        }

        if (event.target instanceof Element && event.target.closest('input, textarea, select, button, a, [contenteditable="true"], .ace_editor, .bootstrap-select')) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        setQuickNavOpen(false);
        contextFocusTarget = event.target instanceof Element
            ? event.target.closest('button, input, select, textarea, [contenteditable="true"], [tabindex]:not([tabindex="-1"])') || event.target
            : trigger;

        const fragment = document.createDocumentFragment();
        actionList.querySelectorAll(':scope > li > a').forEach((source) => {
            const link = source.cloneNode(true);
            const originalId = link.id;
            link.removeAttribute('id');
            link.setAttribute('role', 'menuitem');
            link.tabIndex = -1;
            if (originalId === 'clear-mailer-params') {
                link.dataset.contextAction = 'clear-params';
            }
            const item = document.createElement('li');
            item.append(link);
            fragment.append(item);
        });
        contextMenu.replaceChildren(fragment);
        contextMenu.hidden = false;

        const bounds = contextMenu.getBoundingClientRect();
        const maxX = Math.max(4, document.documentElement.clientWidth - bounds.width - 4);
        const maxY = Math.max(4, document.documentElement.clientHeight - bounds.height - 4);
        contextMenu.style.left = `${Math.max(4, Math.min(event.clientX, maxX))}px`;
        contextMenu.style.top = `${Math.max(4, Math.min(event.clientY, maxY))}px`;
        contextMenu.querySelector('[role="menuitem"]')?.focus({ preventScroll: true });
    };

    document.addEventListener('contextmenu', openContextMenu);

    const closeMenus = (restoreFocus = false) => {
        const contextWasOpen = !contextMenu.hidden;
        setQuickNavOpen(false);
        if (contextWasOpen) {
            closeContextMenu(restoreFocus);
        } else if (restoreFocus) {
            trigger.focus({ preventScroll: true });
        }
    };

    const moveMenuFocus = (menu, event) => {
        const items = menuItems(menu);
        if (items.length === 0) {
            return;
        }

        const currentIndex = items.indexOf(document.activeElement);
        let nextIndex = currentIndex;
        if (event.key === 'Home') {
            nextIndex = 0;
        } else if (event.key === 'End') {
            nextIndex = items.length - 1;
        } else if (event.key === 'ArrowDown') {
            nextIndex = (currentIndex + 1 + items.length) % items.length;
        } else if (event.key === 'ArrowUp') {
            nextIndex = (currentIndex - 1 + items.length) % items.length;
        } else {
            return;
        }

        event.preventDefault();
        items[nextIndex]?.focus({ preventScroll: true });
    };

    document.addEventListener('keydown', (event) => {
        const activeMenu = contextMenu.hidden
            ? (quickNav.classList.contains('nav-is-visible') ? actionList : null)
            : contextMenu;

        if (event.key === 'Escape' && activeMenu) {
            closeMenus(true);
            return;
        }

        if (activeMenu && ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            moveMenuFocus(activeMenu, event);
        }
    });

    contextMenu.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a') : null;
        if (!link) {
            return;
        }

        if (link.getAttribute('aria-disabled') === 'true') {
            event.preventDefault();
            return;
        }

        if (link.hasAttribute('data-save-draft')) {
            event.preventDefault();
            closeContextMenu();
            actionList.querySelector('[data-save-draft]')?.click();
            return;
        }

        if (link.dataset.contextAction === 'clear-params') {
            event.preventDefault();
            closeContextMenu();
            actionList.querySelector('#clear-mailer-params')?.click();
            return;
        }

        if (link.hasAttribute('data-pmta-admin-link')) {
            event.preventDefault();
            const url = getPmtaAdminUrl();
            if (url) {
                window.open(url, '_blank', 'noopener,noreferrer');
            }
            closeContextMenu();
            return;
        }

        if (link.hasAttribute('data-pmta-panel-link')) {
            event.preventDefault();
            getPmtaPanelUrls().forEach((url) => window.open(url, '_blank', 'noopener,noreferrer'));
            closeContextMenu();
            return;
        }

        window.setTimeout(() => closeContextMenu(), 0);
    });

    trigger.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' && !quickNav.classList.contains('nav-is-visible')) {
            event.preventDefault();
            setQuickNavOpen(true, true);
        }
    });

    quickNav.dataset.alphalinkNavigationBound = 'true';
})();
