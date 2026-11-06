(() => {
    const dialog = document.getElementById('command-os-search-dialog');
    const trigger = document.querySelector('[data-action="command_os_search_open"]');
    const closeButton = document.querySelector('[data-action="command_os_search_close"]');
    const input = document.getElementById('command-os-search-input');
    const results = document.getElementById('command-os-search-results');
    const help = document.getElementById('command-os-search-help');
    const navigation = document.querySelector('#sidebar .command-os-navigation');

    if (!dialog || !trigger || !closeButton || !input || !results || !help || !navigation || typeof dialog.showModal !== 'function') {
        return;
    }

    const shortcutHint = trigger.querySelector('[data-command-os-search-shortcut]');
    if (shortcutHint) {
        const platform = window.navigator?.userAgentData?.platform || window.navigator?.platform || '';
        shortcutHint.textContent = /mac|iphone|ipad/i.test(platform) ? '⌘ K' : 'Ctrl K';
    }

    const normalize = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().trim();
    const pages = Array.from(navigation.querySelectorAll('a[href]'))
        .filter((anchor) => !anchor.closest('[aria-disabled="true"], .is-disabled'))
        .map((anchor) => {
            const url = new URL(anchor.href, window.location.href);
            const item = anchor.closest('.command-os-navigation-item');
            const sectionLink = item?.querySelector('.command-os-navigation-row > .command-os-navigation-link');
            return {
                label: anchor.textContent.replace(/\s+/g, ' ').trim(),
                section: sectionLink?.textContent.replace(/\s+/g, ' ').trim() || 'AlphaLink',
                href: `${url.pathname}${url.search}${url.hash}`,
                origin: url.origin,
            };
        })
        .filter((page) => page.label && page.origin === window.location.origin)
        .filter((page, index, all) => all.findIndex((candidate) => candidate.href === page.href) === index)
        .map(({ origin, ...page }) => ({ ...page, searchText: normalize(`${page.label} ${page.section}`) }));

    let activeIndex = 0;
    let visiblePages = [];
    let returnFocus = null;

    const select = (index) => {
        if (visiblePages.length === 0) {
            activeIndex = -1;
            input.setAttribute('aria-activedescendant', '');
            return;
        }
        activeIndex = (index + visiblePages.length) % visiblePages.length;
        results.querySelectorAll('[role="option"]').forEach((option, optionIndex) => {
            option.setAttribute('aria-selected', String(optionIndex === activeIndex));
        });
        const activeOption = results.querySelector(`#command-os-search-result-${activeIndex}`);
        input.setAttribute('aria-activedescendant', activeOption?.id || '');
        activeOption?.scrollIntoView({ block: 'nearest' });
    };

    const navigateTo = (page) => {
        if (!page) return;
        dialog.close();
        window.location.assign(page.href);
    };

    const render = () => {
        const query = normalize(input.value);
        visiblePages = pages.filter((page) => !query || page.searchText.includes(query)).slice(0, 8);
        results.replaceChildren();

        if (visiblePages.length === 0) {
            activeIndex = -1;
            input.setAttribute('aria-activedescendant', '');
            const empty = document.createElement('div');
            empty.className = 'command-os-search-empty';
            empty.textContent = 'No available pages match that search.';
            results.append(empty);
            help.textContent = 'Try a different page or module name.';
            return;
        }

        visiblePages.forEach((page, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'command-os-search-result';
            option.id = `command-os-search-result-${index}`;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.tabIndex = -1;

            const icon = document.createElement('span');
            icon.className = 'command-os-search-result-icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.innerHTML = '<i class="fas fa-arrow-right"></i>';

            const copy = document.createElement('span');
            copy.className = 'command-os-search-result-copy';
            const label = document.createElement('strong');
            label.textContent = page.label;
            const section = document.createElement('small');
            section.textContent = page.section;
            copy.append(label, section);

            const arrow = document.createElement('i');
            arrow.className = 'fas fa-arrow-up-right-from-square';
            arrow.setAttribute('aria-hidden', 'true');
            option.append(icon, copy, arrow);
            option.addEventListener('pointerenter', () => select(index));
            option.addEventListener('click', () => navigateTo(page));
            results.append(option);
        });

        select(0);
        help.textContent = query
            ? `${visiblePages.length} matching ${visiblePages.length === 1 ? 'page' : 'pages'}. Use the arrow keys to choose.`
            : 'Search destinations available to your account.';
    };

    const open = () => {
        if (dialog.open) {
            input.focus();
            return;
        }
        returnFocus = document.activeElement;
        input.value = '';
        render();
        dialog.showModal();
        requestAnimationFrame(() => input.focus());
    };

    trigger.addEventListener('click', open);
    closeButton.addEventListener('click', () => dialog.close());
    input.addEventListener('input', render);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            select(activeIndex + (event.key === 'ArrowDown' ? 1 : -1));
        } else if (event.key === 'Enter' && activeIndex >= 0) {
            event.preventDefault();
            navigateTo(visiblePages[activeIndex]);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            dialog.close();
        }
    });
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        dialog.close();
    });
    dialog.addEventListener('close', () => {
        if (returnFocus?.isConnected) returnFocus.focus();
        returnFocus = null;
    });

    document.addEventListener('keydown', (event) => {
        if (!(event.metaKey || event.ctrlKey) || event.altKey || event.key.toLocaleLowerCase() !== 'k') return;
        const target = event.target;
        const isEditing = target instanceof Element && target.closest('input, textarea, select, [contenteditable="true"]');
        if (isEditing && !dialog.contains(target)) return;
        event.preventDefault();
        open();
    });
})();
