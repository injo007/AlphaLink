(() => {
    const page = document.getElementById('global-tests-page');
    if (!page) {
        return;
    }

    const form = document.getElementById('global-test-form');
    const byId = (id) => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const headerElement = byId('header');
    const bodyElement = byId('body');
    const aceBase = `${window.location.origin}/console-assets/vendor/ace`;
    let headerEditor = null;
    let bodyEditor = null;

    if (window.jQuery && window.jQuery.fn?.selectpicker) {
        const pickers = window.jQuery('#global-tests-page .selectpicker');
        pickers.selectpicker({ container: 'body' });
        pickers.each(function () {
            window.jQuery(this).parent('.bootstrap-select').addClass('campaign-select');
        });
    }

    const refreshPicker = (el) => {
        if (window.jQuery && window.jQuery.fn?.selectpicker && el?.classList?.contains('selectpicker')) {
            window.jQuery(el).selectpicker('refresh');
        }
    };

    if (window.ace) {
        window.ace.config.set('basePath', aceBase);
        window.ace.config.set('modePath', `${aceBase}/`);
        window.ace.config.set('themePath', `${aceBase}/`);
        headerEditor = window.ace.edit('header');
        bodyEditor = window.ace.edit('body');
        const applyAceTheme = () => {
            [headerEditor, bodyEditor].forEach((ed) => ed && ed.setTheme('ace/theme/github'));
        };
        [headerEditor, bodyEditor].forEach((editor) => {
            editor.session.setUseWorker(false);
            editor.setAutoScrollEditorIntoView(false);
            editor.setShowPrintMargin(false);
            editor.$blockScrolling = Infinity;
            editor.setFontSize(12);
            editor.setOptions({
                minLines: 18,
                maxLines: 22,
                enableBasicAutocompletion: true,
                enableLiveAutocompletion: true,
                wrap: true,
                showGutter: false,
            });
        });
        applyAceTheme();
        headerEditor.session.setMode('ace/mode/text');
        bodyEditor.session.setMode('ace/mode/html');
        new MutationObserver(applyAceTheme).observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-command-theme'],
        });
    }

    const getHeader = () => (headerEditor ? headerEditor.getValue() : headerElement.value);
    const setHeader = (value) => (headerEditor ? headerEditor.setValue(value, -1) : (headerElement.value = value));
    const getBody = () => (bodyEditor ? bodyEditor.getValue() : bodyElement.value);
    const setBody = (value) => (bodyEditor ? bodyEditor.setValue(value, -1) : (bodyElement.value = value));
    let lastEditor = headerEditor || headerElement;

    const showToast = (message, tone = 'info') => {
        if (window.AlphaLinkToast) {
            window.AlphaLinkToast.show(message, tone);
        }
    };

    const setStatus = (message, tone = 'info') => {
        const status = byId('global-test-status');
        status.className = `alert alert-${tone} mt-3`;
        status.textContent = message;
        status.hidden = false;
    };

    const jsonRequest = async (url, options = {}) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                ...(options.headers || {}),
            },
        });
        let body = {};
        try {
            body = await response.json();
        } catch {
            body = {};
        }
        if (!response.ok) {
            const errors = body.errors ? Object.values(body.errors).flat() : [];
            throw new Error(errors.join(' ') || body.data || body.message || `Request failed (${response.status}).`);
        }
        return body;
    };

    const copyText = async (text) => {
        if (!text) return false;
        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(text);
                return true;
            }
        } catch {
            // Fall through to execCommand fallback
        }
        const temporary = document.createElement('textarea');
        temporary.value = text;
        temporary.style.position = 'fixed';
        temporary.style.opacity = '0';
        document.body.append(temporary);
        temporary.select();
        const copied = document.execCommand('copy');
        temporary.remove();
        return copied;
    };

    const openDialog = (dialog, focusTarget = null) => {
        if (!dialog) return;
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
        (focusTarget || dialog.querySelector('input, button, textarea, select'))?.focus();
    };

    const closeDialog = (dialog) => {
        if (!dialog) return;
        if (typeof dialog.close === 'function' && dialog.open) {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
        }
    };

    document.addEventListener('click', (event) => {
        const closeButton = event.target.closest('[data-dialog-close]');
        if (closeButton) {
            closeDialog(closeButton.closest('dialog'));
        }
    });

    // Stage Track Jump Navigation
    const stageItems = [...document.querySelectorAll('.campaign-stage-track li[data-jump-target]')];
    const activateStageItem = (item) => {
        const target = document.querySelector(item.dataset.jumpTarget);
        if (!target) return;
        stageItems.forEach((li) => {
            const active = li === item;
            li.classList.toggle('is-current', active);
            if (active) li.setAttribute('aria-current', 'step');
            else li.removeAttribute('aria-current');
        });
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    stageItems.forEach((item) => {
        item.addEventListener('click', () => activateStageItem(item));
        item.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                activateStageItem(item);
            }
        });
    });

    // Quick Nav & Context Menu
    const quickNav = byId('global-quick-nav');
    const quickNavToggle = byId('quick-nav-toggle');
    const quickNavMenu = byId('global-quick-nav-menu');
    const contextMenu = byId('global-test-context-menu');
    const contextArea = byId('global-tests-block');
    let contextFocusTarget = null;
    const setQuickNavOpen = (open) => {
        contextMenu.hidden = true;
        quickNavMenu.hidden = !open;
        quickNavToggle.setAttribute('aria-expanded', String(open));
        quickNavToggle.setAttribute('aria-label', open ? 'Close quick navigation' : 'Open quick navigation');
        if (open) {
            quickNavMenu.querySelector('[role="menuitem"]')?.focus();
        }
    };
    quickNavToggle.addEventListener('click', () => setQuickNavOpen(quickNavMenu.hidden));
    document.addEventListener('click', (event) => {
        if (!quickNav.contains(event.target) && !contextMenu.contains(event.target)) {
            setQuickNavOpen(false);
            contextMenu.hidden = true;
        }
    });
    const openContextMenu = (event) => {
        event.preventDefault();
        setQuickNavOpen(false);
        contextFocusTarget = event.target instanceof Element
            ? event.target.closest('button, input, select, textarea, [contenteditable="true"], [tabindex]:not([tabindex="-1"])') || contextArea
            : contextArea;
        contextMenu.hidden = false;
        const bounds = contextMenu.getBoundingClientRect();
        const maxX = (document.documentElement.clientWidth || window.innerWidth) - bounds.width - 4;
        const maxY = (document.documentElement.clientHeight || window.innerHeight) - bounds.height - 4;
        contextMenu.style.left = `${Math.max(4, Math.min(event.clientX, maxX))}px`;
        contextMenu.style.top = `${Math.max(4, Math.min(event.clientY, maxY))}px`;
        contextMenu.querySelector('[role="menuitem"]')?.focus();
    };
    contextArea.addEventListener('contextmenu', openContextMenu);
    const closeMenus = () => {
        setQuickNavOpen(false);
        contextMenu.hidden = true;
    };
    document.addEventListener('keydown', (event) => {
        const activeMenu = quickNavMenu.hidden ? (contextMenu.hidden ? null : contextMenu) : quickNavMenu;
        if (event.key === 'Escape' && activeMenu) {
            closeMenus();
            (activeMenu === contextMenu ? contextFocusTarget : quickNavToggle)?.focus({ preventScroll: true });
            return;
        }
        if (activeMenu && ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            const items = [...activeMenu.querySelectorAll('[role="menuitem"]')];
            const currentIndex = items.indexOf(document.activeElement);
            const nextIndex = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (currentIndex + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
            event.preventDefault();
            items[nextIndex]?.focus();
        }
    });

    const runQuickAction = (action) => {
        if (!action) return;
        closeMenus();
        if (action === 'send') {
            form.requestSubmit();
            return;
        }
        const selectedServers = [...byId('servers').selectedOptions];
        if (action === 'pmta-admin') {
            if (selectedServers.length === 0) {
                showToast('Select a server first to open PMTA Admin.', 'warning');
                return;
            }
            const url = new URL(page.dataset.pmtaAdminUrl, window.location.origin);
            selectedServers.forEach((option) => url.searchParams.append('server_ids[]', option.value));
            const opened = window.open(url.toString(), '_blank');
            if (!opened) {
                showToast('The PMTA Admin page was blocked by the browser. Allow pop-ups for AlphaLink.', 'warning');
            } else {
                opened.opener = null;
            }
            return;
        }
        if (action === 'pmta-panel') {
            if (selectedServers.length === 0) {
                showToast('Select a server first to open its PMTA panel.', 'warning');
                return;
            }
            selectedServers.forEach((server) => {
                const panelUrl = server.dataset.monitorUrl || server.dataset.panelUrl;
                if (panelUrl) {
                    const opened = window.open(panelUrl, '_blank');
                    if (opened) opened.opener = null;
                    return;
                }
                const address = server.dataset.ip || '';
                const host = address.includes(':') && !address.startsWith('[') ? `[${address}]` : address;
                if (!host) return;
                const port = Number(page.dataset.pmtaAdminPort || 1213);
                const opened = window.open(`${window.location.protocol}//${host}:${port}/`, '_blank');
                if (opened) opened.opener = null;
            });
        }
    };
    [quickNavMenu, contextMenu].forEach((menu) => menu.addEventListener('click', (event) => {
        runQuickAction(event.target.closest('[data-quick-action]')?.dataset.quickAction);
    }));

    // Saved Emails + Add Email Test (Inline Manager + Modal Sync + Smart Merge)
    const savedEmailsSelect = byId('saved_emails');
    const emailTextarea = byId('email_test');
    const inlineEmailManager = byId('saved-email-manager');
    const inlineEmailInput = byId('saved-email-new');
    const inlineEmailStatus = byId('saved-email-status');
    const manageEmailsButton = byId('manage-emails');
    const clearEmailTestButton = byId('clear-email-test');
    let syncedSavedEmails = new Set();

    const parsedEmailLines = () => emailTextarea.value.split(/\r?\n/).map((v) => v.trim()).filter(Boolean);
    const setInlineEmailStatus = (msg, tone = 'muted') => {
        if (!inlineEmailStatus) return;
        inlineEmailStatus.className = `small mt-1 text-${tone}`;
        inlineEmailStatus.textContent = msg;
    };

    const mergeSavedSelectionsIntoTextarea = () => {
        const selectedNow = new Set([...savedEmailsSelect.selectedOptions].map((o) => o.value));
        const manualLines = parsedEmailLines().filter((email) => !syncedSavedEmails.has(email));
        const combined = [...new Set([...manualLines, ...selectedNow])];
        emailTextarea.value = combined.join('\n');
        syncedSavedEmails = selectedNow;
        updateRouteCountsAndSummary();
    };

    savedEmailsSelect.addEventListener('change', mergeSavedSelectionsIntoTextarea);

    manageEmailsButton.addEventListener('click', () => {
        inlineEmailManager.hidden = !inlineEmailManager.hidden;
        manageEmailsButton.setAttribute('aria-expanded', String(!inlineEmailManager.hidden));
        if (!inlineEmailManager.hidden) {
            inlineEmailInput.focus();
        }
    });
    byId('saved-email-close')?.addEventListener('click', () => {
        inlineEmailManager.hidden = true;
        manageEmailsButton.setAttribute('aria-expanded', 'false');
        manageEmailsButton.focus();
    });
    byId('open-saved-emails-dialog')?.addEventListener('click', () => {
        openDialog(byId('global-email-dialog'), byId('new-test-email'));
    });

    clearEmailTestButton?.addEventListener('click', () => {
        emailTextarea.value = '';
        syncedSavedEmails.clear();
        [...savedEmailsSelect.options].forEach((o) => { o.selected = false; });
        refreshPicker(savedEmailsSelect);
        updateRouteCountsAndSummary();
        emailTextarea.focus();
    });

    const addressOptionExists = (email) => [...savedEmailsSelect.options].some((option) => option.value.toLowerCase() === email.toLowerCase());
    const addAddressToUi = ({ id, email }, selectAndInsert = true) => {
        let targetOption = [...savedEmailsSelect.options].find((o) => o.value.toLowerCase() === email.toLowerCase());
        if (!targetOption) {
            targetOption = new Option(email, email);
            targetOption.dataset.addressId = id;
            savedEmailsSelect.add(targetOption);
        }
        if (selectAndInsert) {
            targetOption.selected = true;
        }
        refreshPicker(savedEmailsSelect);
        if (selectAndInsert) {
            mergeSavedSelectionsIntoTextarea();
        }
        if (![...byId('saved-email-list').children].some((item) => item.dataset.addressId === String(id))) {
            const row = document.createElement('li');
            row.className = 'list-group-item d-flex justify-content-between align-items-center';
            row.dataset.addressId = id;
            const label = document.createElement('span');
            label.textContent = email;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'campaign-icon-button is-delete remove-saved-email';
            remove.setAttribute('aria-label', `Delete ${email}`);
            remove.title = `Delete ${email}`;
            remove.innerHTML = '<i class="fas fa-trash-alt" aria-hidden="true"></i>';
            row.append(label, remove);
            byId('saved-email-list').append(row);
        }
    };

    const removeAddressFromUiById = (addressId) => {
        const option = [...savedEmailsSelect.options].find((o) => String(o.dataset.addressId) === String(addressId));
        const email = option?.value || '';
        option?.remove();
        refreshPicker(savedEmailsSelect);
        byId('saved-email-list').querySelector(`[data-address-id="${CSS.escape(String(addressId))}"]`)?.remove();
        if (email) {
            syncedSavedEmails.delete(email);
            const remaining = parsedEmailLines().filter((line) => line !== email);
            emailTextarea.value = remaining.join('\n');
        }
        updateRouteCountsAndSummary();
    };

    const handleAddSavedEmail = async (inputEl, triggerBtn, feedbackFn) => {
        const rawEmail = inputEl.value.trim();
        if (!rawEmail || !inputEl.checkValidity()) {
            inputEl.focus();
            inputEl.reportValidity();
            feedbackFn('Enter a valid email address.', 'danger');
            return;
        }
        triggerBtn.disabled = true;
        try {
            const result = await jsonRequest(page.dataset.addressStoreUrl, {
                method: 'POST',
                body: JSON.stringify({ email: rawEmail }),
            });
            addAddressToUi(result.data, true);
            inputEl.value = '';
            feedbackFn('Email address saved & added to test list.', 'success');
            showToast('Email address saved and selected.', 'success');
        } catch (error) {
            feedbackFn(error.message, 'danger');
            showToast(error.message, 'danger');
        } finally {
            triggerBtn.disabled = false;
        }
    };

    byId('saved-email-add')?.addEventListener('click', () => handleAddSavedEmail(inlineEmailInput, byId('saved-email-add'), setInlineEmailStatus));
    inlineEmailInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            handleAddSavedEmail(inlineEmailInput, byId('saved-email-add'), setInlineEmailStatus);
        }
    });

    byId('saved-email-remove')?.addEventListener('click', async () => {
        const selected = [...savedEmailsSelect.selectedOptions];
        if (!selected.length) {
            setInlineEmailStatus('Select one or more saved addresses in Saved Emails first.', 'warning');
            return;
        }
        const btn = byId('saved-email-remove');
        btn.disabled = true;
        try {
            for (const option of selected) {
                const addressId = option.dataset.addressId;
                if (!addressId) continue;
                await jsonRequest(`${page.dataset.addressDeleteBase}/${encodeURIComponent(addressId)}`, { method: 'DELETE' });
                removeAddressFromUiById(addressId);
            }
            setInlineEmailStatus('Selected addresses deleted.', 'success');
            showToast('Selected saved addresses deleted.', 'success');
        } catch (error) {
            setInlineEmailStatus(error.message, 'danger');
            showToast(error.message, 'danger');
        } finally {
            btn.disabled = false;
        }
    });

    byId('save-email-form').addEventListener('submit', (event) => {
        event.preventDefault();
        handleAddSavedEmail(byId('new-test-email'), event.currentTarget.querySelector('[type="submit"]'), () => {});
    });

    byId('saved-email-list').addEventListener('click', async (event) => {
        const remove = event.target.closest('.remove-saved-email');
        if (!remove) return;
        const row = remove.closest('[data-address-id]');
        const addressId = row?.dataset.addressId;
        if (!addressId) return;
        remove.disabled = true;
        try {
            await jsonRequest(`${page.dataset.addressDeleteBase}/${encodeURIComponent(addressId)}`, { method: 'DELETE' });
            removeAddressFromUiById(addressId);
            showToast('Email address deleted.', 'success');
        } catch (error) {
            showToast(error.message, 'danger');
            remove.disabled = false;
        }
    });

    // Header & Body Editor Controls
    const headerSelect = byId('saved_headers');
    const deleteHeaderBtn = byId('delete-header');
    const headerStatusEl = byId('saved-header-status');
    if (headerEditor) {
        headerEditor.on('focus', () => { lastEditor = headerEditor; });
        bodyEditor.on('focus', () => { lastEditor = bodyEditor; });
    } else {
        headerElement.addEventListener('focus', () => { lastEditor = headerElement; });
        bodyElement.addEventListener('focus', () => { lastEditor = bodyElement; });
    }
    emailTextarea.addEventListener('focus', () => { lastEditor = emailTextarea; });
    byId('return_path').addEventListener('focus', () => { lastEditor = byId('return_path'); });

    headerSelect.addEventListener('change', async () => {
        deleteHeaderBtn.disabled = !headerSelect.value;
        if (!headerSelect.value) {
            if (headerStatusEl) headerStatusEl.textContent = '';
            return;
        }
        try {
            const result = await jsonRequest(`${page.dataset.headerBase}/${encodeURIComponent(headerSelect.value)}`, { method: 'GET', headers: { 'Content-Type': 'application/json' } });
            setHeader(result.data);
            if (headerStatusEl) headerStatusEl.textContent = 'Loaded saved header template.';
            showToast('Saved header loaded.', 'info');
        } catch (error) {
            showToast(error.message, 'danger');
        }
    });

    byId('save-header').addEventListener('click', () => openDialog(byId('global-header-name-dialog'), byId('new-header-name')));
    byId('confirm-save-header').addEventListener('click', async () => {
        const nameInput = byId('new-header-name');
        const name = nameInput.value.trim();
        if (!name) {
            nameInput.focus();
            nameInput.reportValidity();
            return;
        }
        const button = byId('confirm-save-header');
        button.disabled = true;
        try {
            const result = await jsonRequest(page.dataset.headerBase, {
                method: 'POST',
                body: JSON.stringify({ name, text: getHeader() }),
            });
            const option = new Option(name, result.header_id);
            headerSelect.add(option);
            headerSelect.value = result.header_id;
            deleteHeaderBtn.disabled = false;
            refreshPicker(headerSelect);
            closeDialog(byId('global-header-name-dialog'));
            nameInput.value = '';
            if (headerStatusEl) headerStatusEl.textContent = `Saved "${name}".`;
            showToast('Header saved.', 'success');
        } catch (error) {
            showToast(error.message, 'danger');
        } finally {
            button.disabled = false;
        }
    });

    deleteHeaderBtn.addEventListener('click', () => {
        if (!headerSelect.value) {
            showToast('Select a saved header first.', 'warning');
            return;
        }
        const selected = headerSelect.selectedOptions[0];
        byId('global-confirm-copy').textContent = `Delete “${selected.textContent}” from saved headers?`;
        openDialog(byId('global-confirm-dialog'), byId('confirm-delete-header'));
    });
    byId('confirm-delete-header').addEventListener('click', async () => {
        const id = headerSelect.value;
        const button = byId('confirm-delete-header');
        button.disabled = true;
        try {
            await jsonRequest(`${page.dataset.headerBase}/${encodeURIComponent(id)}`, { method: 'DELETE' });
            headerSelect.selectedOptions[0]?.remove();
            headerSelect.value = '';
            deleteHeaderBtn.disabled = true;
            refreshPicker(headerSelect);
            closeDialog(byId('global-confirm-dialog'));
            if (headerStatusEl) headerStatusEl.textContent = 'Deleted saved header.';
            showToast('Header deleted.', 'success');
        } catch (error) {
            showToast(error.message, 'danger');
        } finally {
            button.disabled = false;
        }
    });

    byId('shuffle-header').addEventListener('click', () => {
        const lines = getHeader().replace(/\r\n/g, '\n').split('\n');
        for (let index = lines.length - 1; index > 0; index -= 1) {
            const target = Math.floor(Math.random() * (index + 1));
            [lines[index], lines[target]] = [lines[target], lines[index]];
        }
        setHeader(lines.join('\n'));
        headerEditor ? headerEditor.focus() : headerElement.focus();
        showToast('Header lines shuffled.', 'info');
    });

    byId('global-header-use-smtp')?.addEventListener('click', () => {
        const lines = getHeader().split(/\r\n|\r|\n/);
        let replaced = false;
        const updated = lines.map((line) => {
            if (!replaced && /^\s*from\s*:/i.test(line)) {
                replaced = true;
                return 'From: [smtp]@[domain]';
            }
            return line;
        });
        if (!replaced) updated.push('From: [smtp]@[domain]');
        setHeader(updated.join('\n'));
        const route = byId('option');
        if (route && route.value !== 'smtp') {
            route.value = 'smtp';
            refreshPicker(route);
            route.dispatchEvent(new Event('change', { bubbles: true }));
        }
        showToast('Header From now uses [smtp]@[domain] and switched Option to SMTP.', 'info');
    });

    byId('domain').addEventListener('change', (event) => {
        const domain = event.currentTarget.value || '[domain]';
        setBody(getBody().replace(/(https?:\/\/)(.*?)\//g, `http://${domain}/`));
    });
    byId('preview-body').addEventListener('click', () => {
        const body = getBody();
        if (!body) {
            showToast('Add a message body before opening the preview.', 'warning');
            return;
        }
        byId('global-preview-frame').srcdoc = body;
        openDialog(byId('global-preview-dialog'));
    });

    const insertTag = (tag) => {
        const editor = lastEditor || headerEditor || headerElement;
        if (editor.insert) {
            editor.insert(tag);
            editor.focus();
            return;
        }
        const start = editor.selectionStart ?? editor.value.length;
        const end = editor.selectionEnd ?? editor.value.length;
        editor.setRangeText(tag, start, end, 'end');
        editor.dispatchEvent(new Event('input', { bubbles: true }));
        editor.focus();
    };
    document.querySelectorAll('[data-insert-tag]').forEach((button) => button.addEventListener('click', () => insertTag(button.dataset.insertTag)));
    byId('show-global-tags').addEventListener('click', () => openDialog(byId('global-tags-dialog')));

    // Encoding Modal
    const setIpTab = (tab) => {
        const stringSelected = tab === 'string';
        const stringTab = byId('global-encoding-string-tab');
        const ipTab = byId('global-encoding-ip-tab');
        stringTab.setAttribute('aria-selected', String(stringSelected));
        stringTab.classList.toggle('active', stringSelected);
        ipTab.setAttribute('aria-selected', String(!stringSelected));
        ipTab.classList.toggle('active', !stringSelected);
        byId('global-encoding-string').hidden = !stringSelected;
        byId('global-encoding-ip').hidden = stringSelected;
    };
    byId('global-encoding-string-tab').addEventListener('click', () => setIpTab('string'));
    byId('global-encoding-ip-tab').addEventListener('click', () => setIpTab('ip'));
    byId('open-encoding').addEventListener('click', () => openDialog(byId('global-encoding-dialog'), byId('encoding-text')));
    byId('run-string-encoding').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const value = byId('encoding-text').value;
        if (!value) {
            byId('encoding-text').focus();
            return;
        }
        button.disabled = true;
        try {
            const result = await jsonRequest(page.dataset.encodeUrl, {
                method: 'POST',
                body: JSON.stringify({
                    string: value,
                    schema: byId('encoding-schema').value,
                    charset: byId('encoding-charset').value,
                }),
            });
            byId('encoding-result').value = result;
        } catch (error) {
            showToast(error.message, 'danger');
        } finally {
            button.disabled = false;
        }
    });

    const ipv4Parts = (address) => {
        const parts = address.split('.').map(Number);
        if (parts.length !== 4 || parts.some((part) => !Number.isInteger(part) || part < 0 || part > 255)) {
            throw new Error('Enter a valid IPv4 address.');
        }
        return parts;
    };
    const padded = (value, base, length) => value.toString(base).padStart(length, '0');
    byId('run-ip-encoding').addEventListener('click', () => {
        const address = byId('encoding-ip').value.trim();
        try {
            const parts = ipv4Parts(address);
            const octal = parts.map((part) => padded(part, 8, 4)).join('.');
            const integer = parts[0] * 16777216 + parts[1] * 65536 + parts[2] * 256 + parts[3];
            const hex = `0x${parts.map((part) => padded(part, 16, 2)).join('').toUpperCase()}`;
            const octalHex = `${padded(parts[0], 8, 4)}.${padded(parts[1], 8, 4)}.0x${padded(parts[2], 16, 2)}${padded(parts[3], 16, 2)}`;
            const decimalHex = `${parts[0]}.${parts[1]}.0x${padded(parts[2], 16, 2)}${padded(parts[3], 16, 2)}`;
            const decimalOctal = `${parts[0]}.${parts[1]}.${padded(parts[2], 8, 4)}.${padded(parts[3], 8, 4)}`;
            byId('encoding-ip-result').value = [
                `Dotted Decimal IP : ${address}`,
                `Octal IP : ${octal}`,
                `Integer IP : ${integer}`,
                `Hexadecimal IP : ${hex}`,
                `OctalHexa IP : ${octalHex}`,
                `DecimalHexa IP : ${decimalHex}`,
                `DecimalOctal IP : ${decimalOctal}`,
            ].join('\n');
        } catch (error) {
            showToast(error.message, 'warning');
        }
    });

    // Servers, Routes & IPs Management (Full Edit-Campaign Parity + Preserved Selections)
    const optionSelect = byId('option');
    const typeSelect = byId('type');
    const serverSelect = byId('servers');
    const availableSelect = byId('ips-available');
    const selectedSelect = byId('ips-selected');
    const pmtaPanels = byId('global-pmta-panels');
    const domainField = byId('domain');
    const domainHint = byId('global-domain-route-hint');
    const catalogUrl = new URL(page.dataset.catalogUrl, window.location.origin);
    const maxMessages = Math.max(1, Number(page.dataset.maxMessages || 100));
    const routeChoiceNames = { ip: 'IPs', vmta: 'VMTAs', smtp: 'SMTP routes' };
    let loadedRouteMode = optionSelect.value;

    const formatRouteLabel = (ip, routeMode) => {
        if (routeMode === 'vmta') {
            return [ip.ip, ip.dns, ip.name || ip.pmta_name].filter(Boolean).join(' | ');
        }
        if (routeMode === 'smtp') {
            return [ip.ip, ip.username, ip.name, ip.dns].filter(Boolean).join(' | ');
        }
        return [ip.ip, ip.dns, ip.username].filter(Boolean).join(' | ');
    };

    const syncRouteChoiceLabels = () => {
        const route = optionSelect.value || 'ip';
        const routeName = routeChoiceNames[route] || 'IPs';
        byId('send-route-choice-heading').textContent = routeName;
        byId('send-ips-available-label').textContent = `Available ${routeName}`;
        byId('send-ips-selected-label').textContent = `Selected ${routeName}`;
        availableSelect.setAttribute('aria-label', `Available ${routeName}`);
        selectedSelect.setAttribute('aria-label', `Selected ${routeName}`);
        byId('ips-available-filter').placeholder = `Filter available ${routeName.toLowerCase()}`;
        byId('ips-selected-filter').placeholder = `Filter selected ${routeName.toLowerCase()}`;
        const quickLabel = byId('global-quick-vmtas-label');
        if (quickLabel) quickLabel.textContent = routeName;

        const behavior = byId('send-route-behavior');
        if (behavior) {
            behavior.innerHTML = route === 'smtp'
                ? '<strong>SMTP Mode:</strong> Option SMTP selects the outbound relay. <code>[smtp]</code> is its username and <code>[smtp_name]</code> is its label. AlphaLink connects through the server PMTA listener.'
                : route === 'vmta'
                    ? '<strong>VMTA Mode:</strong> Uses the selected virtual MTA for outbound routing and its configured domain. Output logs are saved to Global Test Histories.'
                    : '<strong>IP Mode:</strong> Sends through the selected source-IP route. Double-click or use arrows to add routes. Output logs are saved to Global Test Histories immediately.';
        }

        const moveLabels = {
            'all-right': `Move all ${routeName} to selected`,
            right: `Move selected ${routeName} to selected`,
            left: `Move selected ${routeName} back to available`,
            'all-left': `Remove all selected ${routeName}`,
        };
        document.querySelectorAll('[data-ip-move]').forEach((btn) => {
            const label = moveLabels[btn.dataset.ipMove];
            if (label) {
                btn.title = label;
                btn.setAttribute('aria-label', label);
            }
        });
    };

    const syncTransferButtons = () => {
        const availCount = availableSelect.options.length;
        const selCount = selectedSelect.options.length;
        byId('move-all-right').disabled = availCount === 0;
        byId('move-right').disabled = availCount === 0;
        byId('move-left').disabled = selCount === 0;
        byId('move-all-left').disabled = selCount === 0;
    };

    const renderPmtaMonitorLinks = (serversList = null) => {
        if (!pmtaPanels) return;
        const selectedOptions = [...serverSelect.selectedOptions];
        pmtaPanels.replaceChildren();
        if (selectedOptions.length === 0) {
            pmtaPanels.hidden = true;
            return;
        }
        const byServerId = new Map((serversList || []).map((srv) => [String(srv.id), srv]));
        selectedOptions.forEach((opt) => {
            const meta = byServerId.get(String(opt.value));
            const monitorUrl = meta?.pmta_panel_display_url || meta?.pmta_panel_url || opt.dataset.monitorUrl || opt.dataset.panelUrl;
            const rawPanelUrl = meta?.pmta_panel_url || opt.dataset.panelUrl || monitorUrl;
            if (!monitorUrl) return;
            const link = document.createElement('a');
            link.href = monitorUrl;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            const usesProxy = rawPanelUrl && monitorUrl !== rawPanelUrl;
            const scheme = (() => {
                try {
                    return new URL(monitorUrl, window.location.href).protocol === 'https:' ? 'HTTPS' : 'HTTP/IP';
                } catch {
                    return 'HTTP/IP';
                }
            })();
            const srvName = meta?.name || opt.textContent.split('|')[0].trim();
            link.innerHTML = `<i class="fas fa-external-link-alt mr-1" aria-hidden="true"></i>Open PMTA monitor (${usesProxy ? 'HTTPS read-only proxy' : scheme}) — `;
            link.append(document.createTextNode(srvName));
            pmtaPanels.append(link);
        });
        pmtaPanels.hidden = pmtaPanels.children.length === 0;
    };

    const syncDomainFromSelectedRoute = () => {
        if (!domainField || !domainHint) return;
        const firstSelected = selectedSelect.options[0];
        if (!firstSelected) {
            domainHint.hidden = true;
            return;
        }
        const candidateDomain = (optionSelect.value === 'vmta' ? firstSelected.dataset.vmtaDomain : firstSelected.dataset.dns) || firstSelected.dataset.dns || '';
        if (candidateDomain && [...domainField.options].some((o) => o.value === candidateDomain)) {
            if (!domainField.value) {
                domainField.value = candidateDomain;
                refreshPicker(domainField);
            }
            domainHint.hidden = false;
            domainHint.textContent = `Route domain: ${candidateDomain}`;
        } else {
            domainHint.hidden = true;
        }
    };

    const applyOptionFilter = (select, filter) => {
        const query = filter.value.trim().toLocaleLowerCase();
        [...select.options].forEach((option) => {
            option.hidden = query !== '' && !option.textContent.toLocaleLowerCase().includes(query);
        });
    };

    const updateRouteCountsAndSummary = () => {
        const availCount = availableSelect?.options?.length ?? 0;
        const selCount = selectedSelect?.options?.length ?? 0;
        const srvCount = serverSelect?.selectedOptions?.length ?? 0;
        const emailsList = parsedEmailLines();
        const hasReturnPath = (byId('return_path')?.value || '').trim().length > 0;
        const hasHeader = getHeader().trim().length > 0;
        const hasBody = getBody().trim().length > 0;
        const repeatVal = Math.max(1, Number(byId('repeat')?.value || 1));
        const projected = emailsList.length * selCount * repeatVal;

        const emailBadge = byId('email-test-count-badge');
        if (emailBadge) emailBadge.textContent = String(emailsList.length);
        if (clearEmailTestButton) clearEmailTestButton.hidden = emailsList.length === 0;

        const availEl = byId('send-ips-available-count');
        const selEl = byId('send-ips-selected-count');
        if (availEl) availEl.textContent = String(availCount);
        if (selEl) selEl.textContent = String(selCount);

        const qRecip = byId('global-quick-recipients');
        const qSrv = byId('global-quick-servers');
        const qVmtas = byId('global-quick-vmtas');
        if (qRecip) qRecip.textContent = String(emailsList.length);
        if (qSrv) qSrv.textContent = String(srvCount);
        if (qVmtas) qVmtas.textContent = String(selCount);

        const projEl = byId('global-projected-messages');
        const estBox = byId('global-send-estimate-box');
        if (projEl) projEl.textContent = String(projected);
        if (estBox) estBox.classList.toggle('is-over-limit', projected > maxMessages);

        const sPath = byId('global-summary-path');
        const sEmails = byId('global-summary-emails');
        const sRoutes = byId('global-summary-routes');
        const sHeaders = byId('global-summary-headers');
        const sBody = byId('global-summary-body');

        if (sPath) {
            sPath.textContent = hasReturnPath ? 'Configured' : 'Missing';
            sPath.className = hasReturnPath ? 'is-ready' : 'is-pending';
        }
        if (sEmails) {
            sEmails.textContent = emailsList.length > 0 ? `${emailsList.length} selected` : 'Required';
            sEmails.className = emailsList.length > 0 ? 'is-ready' : 'is-pending';
        }
        if (sRoutes) {
            sRoutes.textContent = selCount > 0 ? `${selCount} selected` : (srvCount > 0 ? 'Pick routes' : 'Not selected');
            sRoutes.className = selCount > 0 ? 'is-ready' : 'is-pending';
        }
        if (sHeaders) {
            sHeaders.textContent = hasHeader ? 'Ready' : 'Required';
            sHeaders.className = hasHeader ? 'is-ready' : 'is-pending';
        }
        if (sBody) {
            sBody.textContent = hasBody ? 'Ready' : 'Empty (optional)';
            sBody.className = hasBody ? 'is-ready' : 'is-optional';
        }

        const totalPhRows = document.querySelectorAll('#placeholders .placeholder-row').length;
        const activePhRows = [...document.querySelectorAll('#placeholders .placeholder-row .placeholder-data')]
            .filter((el) => el.value.trim() !== '').length;
        const isPhOpen = !byId('placeholder-content').hidden;
        const phBadge = byId('placeholder-count-badge');
        if (phBadge) {
            phBadge.textContent = totalPhRows === 0
                ? (isPhOpen ? 'Open · 0' : 'Closed · 0')
                : `${isPhOpen ? 'Open' : 'Closed'} · ${totalPhRows}`;
        }
        const sPlaceholders = byId('global-summary-placeholders');
        if (sPlaceholders) {
            sPlaceholders.textContent = activePhRows > 0 ? `${activePhRows} active` : (totalPhRows > 0 ? `${totalPhRows} empty` : (isPhOpen ? 'Optional (0)' : 'Closed (0)'));
            sPlaceholders.className = activePhRows > 0 ? 'is-ready' : 'is-optional';
        }

        const checks = [hasReturnPath, emailsList.length > 0, hasHeader, selCount > 0];
        const passed = checks.filter(Boolean).length;
        const pct = Math.round((passed / checks.length) * 100);
        const pctEl = byId('global-readiness-pct');
        const barEl = byId('global-readiness-bar');
        const descEl = byId('global-readiness-desc');
        if (pctEl) pctEl.textContent = `${pct}%`;
        if (barEl) barEl.style.width = `${pct}%`;
        if (descEl) descEl.textContent = `${passed} of ${checks.length} test checks complete`;

        const canDispatch = page.dataset.senderConfigured === 'true' && page.dataset.canSend === 'true' && projected <= maxMessages;
        const mainBtn = byId('send-global-test');
        const sideBtn = byId('global-summary-submit-btn');
        [mainBtn, sideBtn].forEach((btn) => {
            if (!btn) return;
            btn.disabled = !canDispatch;
            btn.title = projected > maxMessages
                ? `Reduce this run to ${maxMessages} messages or fewer.`
                : page.dataset.canSend !== 'true'
                    ? 'Your role cannot dispatch Global Tests.'
                    : '';
        });

        syncTransferButtons();
    };

    const makeRouteOption = (ip, routeMode) => {
        const opt = new Option(formatRouteLabel(ip, routeMode), String(ip.id));
        opt.dataset.serverId = String(ip.server_id || '');
        opt.dataset.ip = String(ip.ip || '');
        opt.dataset.dns = String(ip.dns || '');
        opt.dataset.vmtaDomain = String(ip.vmta_domain || '');
        opt.dataset.username = String(ip.username || '');
        return opt;
    };

    const renderServerOptions = (servers, selectedIds = []) => {
        const selected = new Set(selectedIds.map(String));
        serverSelect.replaceChildren();
        servers.forEach((server) => {
            const option = new Option(`${server.name} | ${server.ip}`, server.id, false, selected.has(String(server.id)));
            option.dataset.ip = server.ip || '';
            option.dataset.panelUrl = server.pmta_panel_url || '';
            option.dataset.monitorUrl = server.pmta_panel_display_url || server.pmta_panel_url || '';
            serverSelect.add(option);
        });
        refreshPicker(serverSelect);
        renderPmtaMonitorLinks(servers);
        updateRouteCountsAndSummary();
    };

    const renderIpOptions = (ips, preserveSelected = true) => {
        const routeMode = optionSelect.value || 'ip';
        const routeChanged = routeMode !== loadedRouteMode;
        const selectedServerIds = new Set([...serverSelect.selectedOptions].map((o) => String(o.value)));
        const existingSelectedIds = (preserveSelected && !routeChanged)
            ? new Set([...selectedSelect.options]
                .filter((opt) => selectedServerIds.has(String(opt.dataset.serverId || '')) || opt.dataset.serverId === '')
                .map((opt) => String(opt.value)))
            : new Set();

        availableSelect.replaceChildren();
        selectedSelect.replaceChildren();

        ips.forEach((ip) => {
            const idStr = String(ip.id);
            const opt = makeRouteOption(ip, routeMode);
            if (existingSelectedIds.has(idStr)) {
                selectedSelect.add(opt);
            } else {
                availableSelect.add(opt);
            }
        });

        loadedRouteMode = routeMode;
        applyOptionFilter(availableSelect, byId('ips-available-filter'));
        applyOptionFilter(selectedSelect, byId('ips-selected-filter'));
        syncDomainFromSelectedRoute();
        updateRouteCountsAndSummary();
    };

    const loadCatalog = async (includeServerChoices = false, preserveSelected = true) => {
        const selectedServers = [...serverSelect.selectedOptions].map((option) => option.value);
        catalogUrl.searchParams.delete('servers[]');
        catalogUrl.searchParams.delete('servers');
        if (!includeServerChoices || selectedServers.length > 0) {
            selectedServers.forEach((id) => catalogUrl.searchParams.append('servers[]', id));
        }
        catalogUrl.searchParams.set('option', optionSelect.value);
        catalogUrl.searchParams.set('type', typeSelect.value);
        try {
            const response = await fetch(catalogUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const result = await response.json();
            if (!response.ok) {
                throw new Error(result.message || 'Could not load the selected servers.');
            }
            if (includeServerChoices) {
                renderServerOptions(result.servers || [], selectedServers);
            } else {
                renderPmtaMonitorLinks(result.servers || []);
            }
            renderIpOptions(result.ips || [], preserveSelected);
        } catch (error) {
            showToast(error.message, 'danger');
        }
    };

    // Bulk Server Search Modal (Matches Edit Campaign)
    byId('toggle-server-search')?.addEventListener('click', () => {
        openDialog(byId('global-server-search-dialog'), byId('global-server-search-input'));
    });
    byId('global-server-search-apply')?.addEventListener('click', () => {
        const queries = [...new Set(byId('global-server-search-input').value.split(/\r?\n/).map((q) => q.trim()).filter(Boolean))];
        closeDialog(byId('global-server-search-dialog'));
        if (!queries.length) return;
        const matches = new Set();
        queries.forEach((query) => {
            const normalized = query.toLowerCase();
            [...serverSelect.options].forEach((opt) => {
                const [name = '', ...rest] = opt.textContent.split('|');
                const ip = rest.join('|').trim();
                if (name.trim().toLowerCase().includes(normalized) || ip.includes(query)) {
                    matches.add(opt);
                }
            });
        });
        [...serverSelect.options].forEach((opt) => { opt.selected = matches.has(opt); });
        refreshPicker(serverSelect);
        serverSelect.dispatchEvent(new Event('change', { bubbles: true }));
        showToast(matches.size ? `${matches.size} matching server(s) selected.` : 'No matching servers found.', matches.size ? 'success' : 'warning');
    });

    serverSelect.addEventListener('change', () => {
        loadCatalog(false, true);
    });
    optionSelect.addEventListener('change', () => {
        syncRouteChoiceLabels();
        loadCatalog(false, false);
    });
    typeSelect.addEventListener('change', () => {
        loadCatalog(false, true);
    });
    byId('reload-servers').addEventListener('click', (event) => {
        const icon = event.currentTarget.querySelector('i');
        icon?.classList.add('fa-spin');
        loadCatalog(true, true).finally(() => icon?.classList.remove('fa-spin'));
    });

    const moveOptions = (source, destination, moveAll = false) => {
        const candidates = [...source.options].filter((option) => (moveAll && !option.hidden) || option.selected);
        candidates.forEach((option) => {
            option.selected = false;
            const exists = [...destination.options].some((selected) => selected.value === option.value);
            if (!exists) {
                destination.add(option);
            } else {
                option.remove();
            }
        });
        applyOptionFilter(availableSelect, byId('ips-available-filter'));
        applyOptionFilter(selectedSelect, byId('ips-selected-filter'));
        syncDomainFromSelectedRoute();
        updateRouteCountsAndSummary();
    };
    byId('move-all-right').addEventListener('click', () => moveOptions(availableSelect, selectedSelect, true));
    byId('move-right').addEventListener('click', () => moveOptions(availableSelect, selectedSelect));
    byId('move-left').addEventListener('click', () => moveOptions(selectedSelect, availableSelect));
    byId('move-all-left').addEventListener('click', () => moveOptions(selectedSelect, availableSelect, true));
    availableSelect.addEventListener('dblclick', () => moveOptions(availableSelect, selectedSelect));
    selectedSelect.addEventListener('dblclick', () => moveOptions(selectedSelect, availableSelect));
    byId('ips-available-filter').addEventListener('input', (event) => applyOptionFilter(availableSelect, event.currentTarget));
    byId('ips-selected-filter').addEventListener('input', (event) => applyOptionFilter(selectedSelect, event.currentTarget));

    document.querySelectorAll('[data-copy-ip-list]').forEach((btn) => btn.addEventListener('click', async () => {
        const list = btn.dataset.copyIpList === 'selected' ? selectedSelect : availableSelect;
        const lines = [...list.options].filter((opt) => !opt.hidden).map((opt) => opt.dataset.ip || opt.textContent.trim()).filter(Boolean);
        if (lines.length === 0) {
            showToast('No routes in this list to copy.', 'warning');
            return;
        }
        const ok = await copyText(lines.join('\n'));
        showToast(ok ? `Copied ${lines.length} route IP(s) to clipboard.` : 'Clipboard permission denied.', ok ? 'success' : 'warning');
    }));

    document.querySelectorAll('.global-clear-filter').forEach((button) => button.addEventListener('click', () => {
        const input = byId(button.dataset.clear);
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
    }));

    // Closable & Clean Message Placeholders (Starts Closed when empty, Closes when emptied)
    const placeholderContent = byId('placeholder-content');
    const togglePlaceholdersBtn = byId('toggle-placeholders');
    const clearPlaceholdersBtn = byId('clear-placeholders');
    const placeholderEmptyNote = byId('placeholder-empty-note');
    const placeholderRowsBody = document.querySelector('#placeholders tbody');

    const syncPlaceholderChrome = () => {
        const rows = [...placeholderRowsBody.querySelectorAll('.placeholder-row')];
        rows.forEach((row, index) => {
            const chip = row.querySelector('.global-placeholder-token-chip');
            if (chip) chip.textContent = `[placeholder${index + 1}]`;
            row.querySelector('.placeholder-data')?.setAttribute('aria-label', `Placeholder ${index + 1} values, one per line`);
            row.querySelector('.placeholder-rotate')?.setAttribute('aria-label', `Placeholder ${index + 1} rotate every`);
        });
        if (clearPlaceholdersBtn) clearPlaceholdersBtn.hidden = rows.length === 0;
        if (placeholderEmptyNote) placeholderEmptyNote.hidden = rows.length > 0;
        if (togglePlaceholdersBtn) {
            const open = !placeholderContent.hidden;
            togglePlaceholdersBtn.setAttribute('aria-expanded', String(open));
            togglePlaceholdersBtn.setAttribute('aria-label', open ? 'Close placeholders' : 'Open placeholders');
            togglePlaceholdersBtn.title = open ? 'Close placeholders' : 'Open placeholders';
            togglePlaceholdersBtn.innerHTML = `<i class="fas ${open ? 'fa-chevron-up' : 'fa-chevron-down'}" aria-hidden="true"></i>`;
        }
        updateRouteCountsAndSummary();
    };

    const setPlaceholdersOpen = (open) => {
        placeholderContent.hidden = !open;
        syncPlaceholderChrome();
    };

    byId('add-placeholder').addEventListener('click', () => {
        setPlaceholdersOpen(true);
        const index = placeholderRowsBody.querySelectorAll('.placeholder-row').length + 1;
        const row = document.createElement('tr');
        row.className = 'placeholder-row';

        const valuesCell = document.createElement('td');
        const chip = document.createElement('span');
        chip.className = 'global-placeholder-token-chip';
        chip.textContent = `[placeholder${index}]`;
        const values = document.createElement('textarea');
        values.className = 'form-control form-control-sm placeholder-data';
        values.rows = 2;
        values.placeholder = 'One value per line';
        valuesCell.append(chip, values);

        const rotateCell = document.createElement('td');
        const rotate = document.createElement('input');
        rotate.className = 'form-control form-control-sm placeholder-rotate';
        rotate.type = 'number';
        rotate.min = '1';
        rotate.value = '1';
        rotateCell.append(rotate);

        const actionCell = document.createElement('td');
        actionCell.className = 'text-center';
        const remove = document.createElement('button');
        remove.className = 'campaign-icon-button is-delete remove-placeholder';
        remove.type = 'button';
        remove.setAttribute('aria-label', 'Remove placeholder row');
        remove.title = 'Remove row';
        remove.innerHTML = '<i class="fas fa-times" aria-hidden="true"></i>';
        actionCell.append(remove);

        row.append(valuesCell, rotateCell, actionCell);
        placeholderRowsBody.append(row);
        syncPlaceholderChrome();
        values.focus();
    });

    byId('placeholders').addEventListener('click', (event) => {
        const remove = event.target.closest('.remove-placeholder');
        if (!remove) return;
        remove.closest('.placeholder-row')?.remove();
        if (placeholderRowsBody.querySelectorAll('.placeholder-row').length === 0) {
            placeholderContent.hidden = true;
        }
        syncPlaceholderChrome();
    });
    byId('placeholders').addEventListener('input', updateRouteCountsAndSummary);

    clearPlaceholdersBtn?.addEventListener('click', () => {
        placeholderRowsBody.replaceChildren();
        byId('placeholder_combination').checked = false;
        byId('placeholder_all_combination').checked = false;
        placeholderContent.hidden = true;
        syncPlaceholderChrome();
    });

    byId('placeholder_combination').addEventListener('change', (event) => {
        if (event.currentTarget.checked) byId('placeholder_all_combination').checked = false;
    });
    byId('placeholder_all_combination').addEventListener('change', (event) => {
        if (event.currentTarget.checked) byId('placeholder_combination').checked = false;
    });

    togglePlaceholdersBtn.addEventListener('click', () => {
        setPlaceholdersOpen(placeholderContent.hidden);
    });

    const readPlaceholders = () => [...document.querySelectorAll('.placeholder-row')].map((row) => ({
        data: row.querySelector('.placeholder-data').value.split(/\r?\n/).map((value) => value.trim()).filter(Boolean),
        rotate_every: Number(row.querySelector('.placeholder-rotate').value),
    })).filter((item) => item.data.length > 0 && item.rotate_every > 0);

    const encodeBase64 = (value) => {
        const bytes = new TextEncoder().encode(value);
        let binary = '';
        for (let offset = 0; offset < bytes.length; offset += 0x8000) {
            binary += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000));
        }
        return btoa(binary);
    };

    // Form Listeners & Dispatch
    emailTextarea.addEventListener('input', updateRouteCountsAndSummary);
    byId('return_path').addEventListener('input', updateRouteCountsAndSummary);
    byId('repeat').addEventListener('input', updateRouteCountsAndSummary);
    if (headerEditor && bodyEditor) {
        headerEditor.session.on('change', updateRouteCountsAndSummary);
        bodyEditor.session.on('change', updateRouteCountsAndSummary);
    } else {
        headerElement.addEventListener('input', updateRouteCountsAndSummary);
        bodyElement.addEventListener('input', updateRouteCountsAndSummary);
    }

    byId('global-summary-submit-btn')?.addEventListener('click', () => {
        form.requestSubmit();
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const emailTests = parsedEmailLines();
        const serverIds = [...serverSelect.selectedOptions].map((option) => option.value);
        const ipIds = [...selectedSelect.options].map((option) => option.value);
        const repeatValue = Math.max(1, Number(byId('repeat').value || 1));

        if (emailTests.length === 0 || !getHeader().trim() || serverIds.length === 0 || ipIds.length === 0) {
            const message = emailTests.length === 0 ? 'Enter at least one test email.'
                : !getHeader().trim() ? 'Enter a message header.'
                    : serverIds.length === 0 ? 'Select at least one server.' : 'Move at least one route into Selected.';
            setStatus(message, 'warning');
            showToast(message, 'warning');
            if (emailTests.length === 0) emailTextarea.focus();
            else if (serverIds.length === 0) serverSelect.focus();
            else if (ipIds.length === 0) availableSelect.focus();
            return;
        }
        if (emailTests.length * ipIds.length * repeatValue > maxMessages) {
            const message = `Reduce this run to ${maxMessages} messages or fewer (currently ${emailTests.length * ipIds.length * repeatValue}).`;
            setStatus(message, 'warning');
            showToast(message, 'warning');
            return;
        }

        const placeholdersList = readPlaceholders();
        const payload = {
            return_path: byId('return_path').value,
            email_test: emailTests,
            domain: byId('domain')?.value || null,
            negative: byId('negative').value || null,
            header: encodeBase64(getHeader()),
            body: encodeBase64(getBody()),
            option: optionSelect.value,
            type: typeSelect.value,
            servers: serverIds,
            ips: ipIds,
            placeholder_combination: byId('placeholder_combination').checked,
            placeholder_all_combination: byId('placeholder_all_combination').checked,
            ...(placeholdersList.length > 0 ? { placeholders: placeholdersList } : {}),
            repeat: repeatValue,
        };

        const mainBtn = byId('send-global-test');
        const sideBtn = byId('global-summary-submit-btn');
        const origMainHtml = mainBtn.innerHTML;
        const origSideHtml = sideBtn ? sideBtn.innerHTML : '';
        [mainBtn, sideBtn].forEach((b) => {
            if (!b) return;
            b.disabled = true;
            b.setAttribute('aria-busy', 'true');
            b.innerHTML = '<i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>TEST RUNNING';
        });
        setStatus('Global Test is running. Keep this page open until the sender returns.', 'info');
        byId('global-test-output-panel').hidden = true;

        try {
            const result = await jsonRequest(page.dataset.testUrl, { method: 'POST', body: JSON.stringify(payload) });
            setStatus('Global Test completed. Sender output was saved to history.', 'success');
            byId('global-test-output').textContent = result.data;
            byId('global-test-output-panel').hidden = false;
            const logLink = byId('global-test-log-link');
            logLink.href = `${window.location.pathname}/histories/${encodeURIComponent(result.log_id)}`;
            logLink.hidden = !result.log_id;
            showToast(result.data, 'success');
        } catch (error) {
            setStatus(error.message, 'danger');
            showToast(error.message, 'danger');
        } finally {
            mainBtn.innerHTML = origMainHtml;
            if (sideBtn) sideBtn.innerHTML = origSideHtml;
            [mainBtn, sideBtn].forEach((b) => {
                if (!b) return;
                b.removeAttribute('aria-busy');
            });
            updateRouteCountsAndSummary();
        }
    });

    // Initial sync
    syncRouteChoiceLabels();
    syncPlaceholderChrome();
    renderPmtaMonitorLinks();
    updateRouteCountsAndSummary();

    byId('global-confirm-dialog').addEventListener('close', () => byId('delete-header').focus());
    byId('global-header-name-dialog').addEventListener('close', () => byId('save-header').focus());
    byId('global-preview-dialog').addEventListener('close', () => byId('preview-body').focus());
    byId('global-tags-dialog').addEventListener('close', () => byId('show-global-tags').focus());
    byId('global-encoding-dialog').addEventListener('close', () => byId('open-encoding').focus());
    byId('global-email-dialog').addEventListener('close', () => byId('open-saved-emails-dialog')?.focus());
    byId('global-server-search-dialog')?.addEventListener('close', () => byId('toggle-server-search')?.focus());
})();
