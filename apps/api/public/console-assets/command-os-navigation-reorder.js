(function () {
    'use strict';

    const initialize = () => {
        const editor = document.querySelector('[data-navigation-editor]');
        const navigation = document.querySelector('.command-os-navigation');
        const dialog = document.getElementById('command-os-nav-save-dialog');
        if (!editor || !navigation || !dialog) return;

        const toggle = editor.querySelector('[data-navigation-toggle]');
        const status = editor.querySelector('[data-navigation-editor-status]');
        const saveButton = dialog.querySelector('[data-navigation-save-submit]');
        const error = dialog.querySelector('[data-navigation-save-error]');
        let editing = false;
        let draggedItem = null;
        let initialOrder = null;

        const readOrder = () => ({
            groups: Array.from(navigation.querySelectorAll(':scope > [data-nav-group]')).map((item) => item.dataset.navGroup),
            items: Object.fromEntries(Array.from(navigation.children).filter((group) => group.hasAttribute('data-nav-group')).map((group) => [
                group.dataset.navGroup,
                Array.from(group.querySelector('.command-os-navigation-submenu')?.children || [])
                    .filter((item) => item.hasAttribute('data-nav-item'))
                    .map((item) => item.dataset.navItem),
            ])),
        });

        const setEditing = (enabled) => {
            editing = enabled;
            navigation.classList.toggle('is-reordering', enabled);
            toggle.setAttribute('aria-pressed', String(enabled));
            toggle.querySelector('span').textContent = enabled ? 'Finish arranging' : 'Arrange navigation';
            status.textContent = enabled ? 'Drag to reorder, or focus an item and use Alt + ↑ / ↓.' : 'Enable to drag navigation items.';

            navigation.querySelectorAll('[data-nav-group], [data-nav-item]').forEach((item) => {
                item.draggable = enabled;
                item.tabIndex = enabled ? 0 : -1;
                item.setAttribute('aria-grabbed', 'false');
            });
        };

        const restoreOrder = (order) => {
            if (!order) return;
            order.groups.forEach((key) => {
                const item = Array.from(navigation.children).find((candidate) => candidate.dataset.navGroup === key);
                if (item) navigation.append(item);
            });
            Object.entries(order.items).forEach(([groupKey, keys]) => {
                const group = Array.from(navigation.children).find((candidate) => candidate.dataset.navGroup === groupKey);
                const list = group?.querySelector('.command-os-navigation-submenu');
                if (!list) return;
                keys.forEach((key) => {
                    const item = Array.from(list.children).find((candidate) => candidate.dataset.navItem === key);
                    if (item) list.append(item);
                });
            });
        };

        const sortableItem = (target) => target.closest('[data-nav-group], [data-nav-item]');

        const reorderAtPointer = (target, pointerY) => {
            if (!draggedItem || !target || target === draggedItem || target.parentElement !== draggedItem.parentElement) return;
            const bounds = target.getBoundingClientRect();
            const insertAfter = pointerY > bounds.top + bounds.height / 2;
            target.parentElement.insertBefore(draggedItem, insertAfter ? target.nextElementSibling : target);
        };

        toggle.addEventListener('click', () => {
            if (!editing) {
                initialOrder = readOrder();
                setEditing(true);
                return;
            }

            error.hidden = true;
            error.textContent = '';
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        });

        navigation.addEventListener('dragstart', (event) => {
            if (!editing) return;
            const item = sortableItem(event.target);
            if (!item || !navigation.contains(item)) return;
            draggedItem = item;
            item.classList.add('is-dragging');
            item.setAttribute('aria-grabbed', 'true');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', item.dataset.navGroup || item.dataset.navItem || 'navigation-item');
        });

        navigation.addEventListener('dragover', (event) => {
            if (!editing || !draggedItem) return;
            const target = sortableItem(event.target);
            if (!target || target.parentElement !== draggedItem.parentElement) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            reorderAtPointer(target, event.clientY);
        });

        navigation.addEventListener('drop', (event) => {
            if (!editing || !draggedItem) return;
            event.preventDefault();
            reorderAtPointer(sortableItem(event.target), event.clientY);
        });

        navigation.addEventListener('dragend', () => {
            draggedItem?.classList.remove('is-dragging');
            draggedItem?.setAttribute('aria-grabbed', 'false');
            draggedItem = null;
        });

        navigation.addEventListener('keydown', (event) => {
            if (!editing || !event.altKey || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;
            const item = sortableItem(event.target);
            if (!item) return;
            event.preventDefault();
            const sibling = event.key === 'ArrowUp' ? item.previousElementSibling : item.nextElementSibling;
            if (!sibling) return;
            item.parentElement.insertBefore(item, event.key === 'ArrowUp' ? sibling : sibling.nextElementSibling);
            item.focus();
        });

        dialog.querySelector('[data-navigation-save-cancel]').addEventListener('click', () => dialog.close());
        dialog.addEventListener('cancel', () => {
            error.hidden = true;
        });
        dialog.querySelector('[data-navigation-save-discard]').addEventListener('click', () => {
            dialog.close();
            restoreOrder(initialOrder);
            setEditing(false);
            initialOrder = null;
        });

        saveButton.addEventListener('click', async () => {
            saveButton.disabled = true;
            saveButton.setAttribute('aria-busy', 'true');
            error.hidden = true;

            try {
                const response = await fetch(editor.dataset.saveUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(readOrder()),
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(payload.message || 'Navigation could not be saved. Try again.');

                dialog.close();
                setEditing(false);
                initialOrder = null;
                status.textContent = 'Navigation saved as the default for everyone.';
            } catch (saveError) {
                error.textContent = saveError.message || 'Navigation could not be saved. Try again.';
                error.hidden = false;
            } finally {
                saveButton.disabled = false;
                saveButton.removeAttribute('aria-busy');
            }
        });

        setEditing(false);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
