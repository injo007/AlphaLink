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
        renderItems(inboxList, currentFeed.inbox || [], 'inbox');
        renderItems(notificationList, currentFeed.notifications || [], 'notifications');
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

    inboxButton?.addEventListener('click', () => openPopover('inbox'));
    notificationButton?.addEventListener('click', () => openPopover('notifications'));
    root.querySelectorAll('[data-communication-close]').forEach((button) => button.addEventListener('click', () => closePopovers(true)));
    root.addEventListener('click', (event) => {
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
    window.setInterval(() => { if (!document.hidden) loadFeed(); }, 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) loadFeed(); });
})();
