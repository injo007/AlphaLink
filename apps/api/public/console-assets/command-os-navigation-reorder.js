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
        let dropTarget = null;
        let initialOrder = null;

        const groups = () => Array.from(navigation.querySelectorAll(':scope > [data-nav-group]'));
        const groupFor = (item) => item?.matches('[data-nav-group]') ? item : item?.closest('[data-nav-group]');
        const isGroup = (item) => item?.hasAttribute('data-nav-group') ?? false;
        const submenuFor = (group) => group?.querySelector('.command-os-navigation-submenu') ?? null;
        const groupLabel = (group) => group?.querySelector('.command-os-navigation-link span')?.textContent?.trim() || 'section';

        const ensureSubmenu = (group) => {
            let disclosure = group.querySelector('.command-os-navigation-disclosure');
            if (disclosure) return submenuFor(group);

            const row = group.querySelector('.command-os-navigation-row');
            if (!row) return null;

            const groupId = group.dataset.navGroup.replace(/[^a-z0-9-]/gi, '-');
            const submenuId = `nav-arranged-${groupId}`;
            disclosure = document.createElement('details');
            disclosure.className = 'command-os-navigation-disclosure';
            disclosure.dataset.navigationTemporaryDisclosure = 'true';

            const summary = document.createElement('summary');
            summary.setAttribute('aria-label', `Toggle ${groupLabel(group)} pages`);
            summary.setAttribute('aria-controls', submenuId);
            summary.title = `Toggle ${groupLabel(group)} pages`;
            const icon = document.createElement('i');
            icon.className = 'fas fa-chevron-down';
            icon.setAttribute('aria-hidden', 'true');
            summary.append(icon);

            const list = document.createElement('ul');
            list.className = 'command-os-navigation-submenu';
            list.id = submenuId;
            disclosure.append(summary, list);
            row.classList.remove('is-standalone');
            row.append(disclosure);

            return list;
        };

        const readOrder = () => ({
            groups: groups().map((group) => group.dataset.navGroup),
            items: Object.fromEntries(groups().map((group) => [
                group.dataset.navGroup,
                Array.from(submenuFor(group)?.children || [])
                    .filter((item) => item.hasAttribute('data-nav-item'))
                    .map((item) => item.dataset.navItem),
            ])),
            expanded: Object.fromEntries(groups().map((group) => [
                group.dataset.navGroup,
                group.querySelector('.command-os-navigation-disclosure')?.open ?? false,
            ])),
        });

        const clearDropTarget = () => {
            dropTarget?.classList.remove('is-drop-target');
            dropTarget?.classList.remove('is-parent-drop-target');
            dropTarget = null;
        };

        const cleanupDisclosures = (saved = false) => {
            navigation.querySelectorAll('.command-os-navigation-disclosure').forEach((disclosure) => {
                const group = groupFor(disclosure);
                const list = submenuFor(group);
                const hasItems = Boolean(list?.querySelector('[data-nav-item]'));
                const isTemporary = disclosure.hasAttribute('data-navigation-temporary-disclosure');

                if (saved && hasItems) {
                    disclosure.removeAttribute('data-navigation-temporary-disclosure');
                    return;
                }
                if (hasItems || (!saved && !isTemporary)) return;

                disclosure.remove();
                group?.querySelector('.command-os-navigation-row')?.classList.add('is-standalone');
            });
        };

        const setEditing = (enabled, { saved = false } = {}) => {
            editing = enabled;
            navigation.classList.toggle('is-reordering', enabled);
            toggle.setAttribute('aria-pressed', String(enabled));
            toggle.querySelector('span').textContent = enabled ? 'Finish arranging' : 'Arrange navigation';
            status.textContent = enabled
                ? 'Drag a page onto a section to move it. Alt + ↑/↓ reorders; Alt + ←/→ moves pages between sections.'
                : 'Enable to reorder pages and move subpages between sections.';

            if (enabled) groups().forEach(ensureSubmenu);
            else cleanupDisclosures(saved);

            navigation.querySelectorAll('[data-nav-group], [data-nav-item]').forEach((item) => {
                item.draggable = enabled;
                item.tabIndex = enabled ? 0 : -1;
                item.setAttribute('aria-grabbed', 'false');
            });
        };

        const restoreOrder = (order) => {
            if (!order) return;

            order.groups.forEach((key) => {
                const group = groups().find((candidate) => candidate.dataset.navGroup === key);
                if (group) navigation.append(group);
            });

            const allItems = new Map(Array.from(navigation.querySelectorAll('[data-nav-item]'))
                .map((item) => [item.dataset.navItem, item]));
            Object.entries(order.items).forEach(([groupKey, keys]) => {
                const group = groups().find((candidate) => candidate.dataset.navGroup === groupKey);
                if (!group) return;
                const list = submenuFor(group) || ensureSubmenu(group);
                keys.forEach((key) => {
                    const item = allItems.get(key);
                    if (item) list.append(item);
                });
            });

            Object.entries(order.expanded || {}).forEach(([groupKey, expanded]) => {
                const group = groups().find((candidate) => candidate.dataset.navGroup === groupKey);
                const disclosure = group?.querySelector('.command-os-navigation-disclosure');
                if (disclosure) disclosure.open = expanded;
            });
            cleanupDisclosures();
        };

        const sortableItem = (target) => target?.closest('[data-nav-item], [data-nav-group]') ?? null;
        const canDropOn = (target) => {
            if (!draggedItem || !target || target === draggedItem) return false;
            if (isGroup(draggedItem)) return isGroup(target) && target.parentElement === navigation;
            if (isGroup(target)) return !target.classList.contains('is-disabled');
            return target.hasAttribute('data-nav-item');
        };

        const moveChildToGroup = (item, group) => {
            const list = ensureSubmenu(group);
            if (!list) return false;
            list.append(item);
            const disclosure = group.querySelector('.command-os-navigation-disclosure');
            if (disclosure) disclosure.open = true;
            return true;
        };

        const placeAtPointer = (target, pointerY) => {
            if (!canDropOn(target)) return false;

            if (isGroup(draggedItem) || target.hasAttribute('data-nav-item')) {
                const bounds = target.getBoundingClientRect();
                const insertAfter = pointerY > bounds.top + bounds.height / 2;
                const list = isGroup(draggedItem) ? navigation : target.parentElement;
                list.insertBefore(draggedItem, insertAfter ? target.nextElementSibling : target);
                if (!isGroup(draggedItem)) {
                    const parentGroup = groupFor(target);
                    const disclosure = parentGroup?.querySelector('.command-os-navigation-disclosure');
                    if (disclosure) disclosure.open = true;
                }
                return true;
            }

            return moveChildToGroup(draggedItem, target);
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
            if (!canDropOn(target)) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            if (dropTarget !== target) {
                clearDropTarget();
                dropTarget = target;
                target.classList.add('is-drop-target');
                if (!isGroup(draggedItem) && isGroup(target)) {
                    target.classList.add('is-parent-drop-target');
                }
            }
        });

        navigation.addEventListener('drop', (event) => {
            if (!editing || !draggedItem) return;
            const target = sortableItem(event.target);
            if (!canDropOn(target)) return;
            event.preventDefault();

            const moved = placeAtPointer(target, event.clientY);
            clearDropTarget();
            if (moved && !isGroup(draggedItem)) {
                status.textContent = `Moved ${draggedItem.querySelector('span')?.textContent?.trim() || 'page'} to ${groupLabel(groupFor(draggedItem))}.`;
            }
        });

        navigation.addEventListener('dragleave', (event) => {
            if (!event.relatedTarget || !navigation.contains(event.relatedTarget)) clearDropTarget();
        });

        navigation.addEventListener('dragend', () => {
            clearDropTarget();
            draggedItem?.classList.remove('is-dragging');
            draggedItem?.setAttribute('aria-grabbed', 'false');
            draggedItem = null;
        });

        navigation.addEventListener('keydown', (event) => {
            if (!editing || !event.altKey || !['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            const item = sortableItem(event.target);
            if (!item) return;
            event.preventDefault();

            if (item.hasAttribute('data-nav-item') && ['ArrowLeft', 'ArrowRight'].includes(event.key)) {
                const currentGroup = groupFor(item);
                const sectionList = groups();
                const currentIndex = sectionList.indexOf(currentGroup);
                const destination = sectionList[currentIndex + (event.key === 'ArrowLeft' ? -1 : 1)];
                if (!destination || destination.classList.contains('is-disabled')) return;
                if (moveChildToGroup(item, destination)) {
                    item.focus();
                    status.textContent = `Moved ${item.querySelector('span')?.textContent?.trim() || 'page'} to ${groupLabel(destination)}.`;
                }
                return;
            }

            if (!['ArrowUp', 'ArrowDown'].includes(event.key)) return;
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
                const order = readOrder();
                const response = await fetch(editor.dataset.saveUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ groups: order.groups, items: order.items }),
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(payload.message || 'Navigation could not be saved. Try again.');

                dialog.close();
                setEditing(false, { saved: true });
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
