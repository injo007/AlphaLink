(() => {
    const root = document.getElementById('command-os-communications');
    if (!root) return;

    const feedUrl = root.dataset.feedUrl;
    const readUrlTemplate = root.dataset.readUrlTemplate;
    const completeUrlTemplate = root.dataset.completeUrlTemplate;
    const inboxButton = root.querySelector('[data-communication-open="inbox"]');
    const notificationButton = root.querySelector('[data-communication-open="notifications"]');
    const inboxPopover = document.getElementById('command-os-inbox-popover');
    const notificationPopover = document.getElementById('command-os-notification-popover');
    const inboxList = root.querySelector('[data-inbox-list]');
    const notificationList = root.querySelector('[data-notification-list]');
    const ticker = root.querySelector('[data-update-ticker]');
    const tickerTrack = root.querySelector('[data-ticker-track]');
    const tickerPrimary = root.querySelector('[data-ticker-primary]');
    const tickerCopy = root.querySelector('[data-ticker-copy]');
    const tickerPause = root.querySelector('[data-ticker-pause]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let currentFeed = { inbox: [], notifications: [], open_task_count: 0 };
    let feedRequest = null;

    const closePopovers = (restoreFocus = false) => {
        const openButton = root.querySelector('[data-communication-open][aria-expanded="true"]');
        [inboxButton, notificationButton].forEach((button) => button?.setAttribute('aria-expanded', 'false'));
        [inboxPopover, notificationPopover].forEach((popover) => { if (popover) popover.hidden = true; });
        if (restoreFocus) {
            (openButton || inboxButton)?.focus();
        }
    };

    const setBadge = (selector, count) => {
        const badge = root.querySelector(selector);
        if (!badge) return;
        badge.textContent = count > 99 ? '99+' : String(count);
        badge.hidden = count < 1;
    };

    const addText = (parent, tag, className, value) => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        element.textContent = value;
        parent.append(element);
        return element;
    };

    const formatDate = (value) => {
        if (!value) return '';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return '';
        return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
    };

    const renderTicker = (items) => {
        if (!ticker || !tickerTrack || !tickerPrimary || !tickerCopy) return;
        tickerPrimary.replaceChildren();
        tickerCopy.replaceChildren();
        if (!items.length) {
            addText(tickerPrimary, 'span', 'command-os-ticker-empty', 'No tasks or messages right now');
            tickerCopy.append(tickerPrimary.firstElementChild.cloneNode(true));
            ticker.dataset.hasUpdates = 'false';
            tickerTrack.style.removeProperty('--ticker-duration');
            return;
        }

        items.slice(0, 12).forEach((item) => {
            const update = document.createElement('button');
            update.type = 'button';
            update.className = `command-os-ticker-item kind-${item.kind}`;
            update.dataset.tickerOpen = 'inbox';
            update.setAttribute('aria-label', `${item.kind}: ${item.title}. Open team inbox.`);
            addText(update, 'span', 'command-os-ticker-kind', item.kind === 'task' ? 'TASK' : 'MESSAGE');
            const copy = document.createElement('span');
            copy.className = 'command-os-ticker-copy';
            addText(copy, 'strong', '', item.title);
            addText(copy, 'span', '', item.body || 'Open to read this update');
            update.append(copy);
            addText(update, 'span', 'command-os-ticker-open-mark', 'View').setAttribute('aria-hidden', 'true');
            tickerPrimary.append(update);
        });

        const viewportWidth = root.querySelector('[data-ticker-viewport]')?.clientWidth || 0;
        const baseItems = [...tickerPrimary.children];
        let repeat = 0;
        while (tickerPrimary.scrollWidth < viewportWidth + 24 && repeat < 10) {
            baseItems.forEach((item) => tickerPrimary.append(item.cloneNode(true)));
            repeat += 1;
        }
        tickerCopy.replaceChildren(...[...tickerPrimary.children].map((item) => item.cloneNode(true)));
        tickerCopy.inert = true;
        tickerCopy.setAttribute('aria-hidden', 'true');
        tickerTrack.style.setProperty('--ticker-duration', `${Math.max(22, Math.min(72, tickerPrimary.childElementCount * 8))}s`);
        ticker.dataset.hasUpdates = 'true';
    };

    const renderItems = (container, items, listType) => {
        if (!container) return;
        container.replaceChildren();
        if (!items.length) {
            const empty = document.createElement('p');
            empty.className = 'command-os-communication-empty';
            empty.textContent = listType === 'inbox' ? 'No tasks or messages for you right now.' : 'You are all caught up.';
            container.append(empty);
            return;
        }

        items.forEach((item) => {
            const card = document.createElement('article');
            card.className = `command-os-communication-item${item.read ? '' : ' is-unread'}${item.completed ? ' is-complete' : ''}`;
            const meta = document.createElement('div');
            meta.className = 'command-os-communication-item-meta';
            const kind = addText(meta, 'span', `command-os-communication-kind kind-${item.kind}`, item.kind);
            kind.setAttribute('aria-label', item.kind);
            const time = addText(meta, 'time', '', formatDate(item.created_at));
            if (item.created_at) time.dateTime = item.created_at;
            card.append(meta);
            addText(card, 'h3', '', item.title);
            addText(card, 'p', 'command-os-communication-body', item.body);
            const byline = item.due_at ? `From ${item.author} · Due ${formatDate(item.due_at)}` : `From ${item.author}`;
            addText(card, 'p', 'command-os-communication-byline', byline);
            const actions = document.createElement('div');
            actions.className = 'command-os-communication-actions';
            if (!item.read) {
                const read = addText(actions, 'button', 'command-os-communication-action', 'Mark read');
                read.type = 'button';
                read.dataset.communicationAction = 'read';
                read.dataset.communicationId = String(item.id);
                read.dataset.communicationKind = item.kind;
            }
            if (item.kind === 'task' && !item.completed) {
                const complete = addText(actions, 'button', 'command-os-communication-action is-primary', 'Complete task');
                complete.type = 'button';
                complete.dataset.communicationAction = 'complete';
                complete.dataset.communicationId = String(item.id);
                complete.dataset.communicationKind = item.kind;
            }
            if (item.kind === 'task' && item.completed) addText(actions, 'span', 'command-os-communication-completed', 'Completed');
            if (actions.childElementCount) card.append(actions);
            container.append(card);
        });
    };

    const render = () => {
        const inboxItems = currentFeed.inbox || [];
        renderItems(inboxList, inboxItems, 'inbox');
        renderItems(notificationList, currentFeed.notifications || [], 'notifications');
        renderTicker(inboxItems);
        setBadge('[data-open-task-count]', Number(currentFeed.unread_inbox_count) || Number(currentFeed.open_task_count) || 0);
        setBadge('[data-unread-count]', Number(currentFeed.unread_notification_count) || 0);
    };

    const loadFeed = async (refreshAfterCurrent = false) => {
        if (!feedUrl) return;
        if (feedRequest) {
            await feedRequest;
            if (refreshAfterCurrent) return loadFeed();
            return;
        }
        const request = (async () => {
            try {
                const response = await fetch(feedUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) throw new Error('Your workspace updates could not be loaded.');
                currentFeed = await response.json();
                render();
            } catch {
                [inboxList, notificationList].forEach((container) => {
                    if (!container) return;
                    const message = document.createElement('p');
                    message.className = 'command-os-communication-empty is-error';
                    message.textContent = 'Updates could not be loaded. Reopen this panel to try again.';
                    container.replaceChildren(message);
                });
                if (tickerPrimary) tickerPrimary.textContent = 'Team updates are temporarily unavailable';
                if (tickerCopy) tickerCopy.textContent = 'Team updates are temporarily unavailable';
                if (ticker) ticker.dataset.hasUpdates = 'false';
                tickerTrack?.style.removeProperty('--ticker-duration');
            }
        })();
        feedRequest = request;
        try {
            await request;
        } finally {
            if (feedRequest === request) feedRequest = null;
        }
    };

    const openPopover = async (name) => {
        const isInbox = name === 'inbox';
        const button = isInbox ? inboxButton : notificationButton;
        const popover = isInbox ? inboxPopover : notificationPopover;
        const otherButton = isInbox ? notificationButton : inboxButton;
        const otherPopover = isInbox ? notificationPopover : inboxPopover;
        if (!button || !popover) return;
        const wasOpen = button.getAttribute('aria-expanded') === 'true';
        closePopovers();
        if (wasOpen) return;
        otherButton?.setAttribute('aria-expanded', 'false');
        if (otherPopover) otherPopover.hidden = true;
        button.setAttribute('aria-expanded', 'true');
        popover.hidden = false;
        await loadFeed();
        popover.querySelector('button, a')?.focus({ preventScroll: true });
    };

    const mutate = async (button) => {
        const action = button.dataset.communicationAction;
        const id = button.dataset.communicationId;
        const template = action === 'complete' ? completeUrlTemplate : readUrlTemplate;
        if (!id || !template) return;
        button.disabled = true;
        try {
            const response = await fetch(template.replace('__COMMUNICATION__', encodeURIComponent(id)), {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
            });
            if (!response.ok) throw new Error('The update could not be saved.');
            await loadFeed(true);
        } catch {
            button.disabled = false;
            button.textContent = action === 'complete' ? 'Try again' : 'Retry mark read';
        }
    };

    const setTickerPaused = (paused) => {
        if (!ticker || !tickerPause) return;
        ticker.dataset.paused = String(paused);
        tickerPause.setAttribute('aria-pressed', String(paused));
        tickerPause.setAttribute('aria-label', paused ? 'Resume team updates' : 'Pause team updates');
        tickerPause.title = paused ? 'Resume team updates' : 'Pause team updates';
    };

    inboxButton?.addEventListener('click', () => openPopover('inbox'));
    notificationButton?.addEventListener('click', () => openPopover('notifications'));
    tickerPause?.addEventListener('click', () => setTickerPaused(ticker?.dataset.paused !== 'true'));
    root.querySelectorAll('[data-communication-close]').forEach((button) => button.addEventListener('click', () => closePopovers(true)));
    root.addEventListener('click', (event) => {
        const tickerItem = event.target.closest('[data-ticker-open="inbox"]');
        if (tickerItem) {
            event.preventDefault();
            openPopover('inbox');
            return;
        }
        const action = event.target.closest('[data-communication-action]');
        if (action) mutate(action);
    });

    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) closePopovers();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && (inboxPopover?.hidden === false || notificationPopover?.hidden === false)) {
            const expandedButton = root.querySelector('[data-communication-open][aria-expanded="true"]');
            closePopovers();
            expandedButton?.focus();
        }
        if (event.key === 'Tab') {
            const open = [inboxPopover, notificationPopover].find((popover) => popover?.hidden === false);
            if (!open) return;
            const focusable = [...open.querySelectorAll('button:not(:disabled), a[href]')];
            if (!focusable.length) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });

    loadFeed();
    if (ticker && 'ResizeObserver' in window) {
        const tickerResizeObserver = new ResizeObserver(() => renderTicker(currentFeed.inbox || []));
        const viewport = root.querySelector('[data-ticker-viewport]');
        if (viewport) tickerResizeObserver.observe(viewport);
    }
    window.setInterval(() => { if (!document.hidden) loadFeed(); }, 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) loadFeed(); });
})();
