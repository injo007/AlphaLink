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
    const aceBase = `${window.location.origin}/dms-reference/vendor/ace`;
    let headerEditor = null;
    let bodyEditor = null;
    if (window.ace) {
        window.ace.config.set('basePath', aceBase);
        window.ace.config.set('modePath', `${aceBase}/`);
        window.ace.config.set('themePath', `${aceBase}/`);
        headerEditor = window.ace.edit('header');
        bodyEditor = window.ace.edit('body');
        [headerEditor, bodyEditor].forEach((editor) => {
            editor.setTheme('ace/theme/github');
            editor.session.setUseWorker(false);
            editor.setAutoScrollEditorIntoView(false);
            editor.setShowPrintMargin(false);
            editor.$blockScrolling = Infinity;
            editor.setFontSize(13);
            editor.setOptions({
                minLines: 30,
                maxLines: 30,
                enableBasicAutocompletion: true,
                enableLiveAutocompletion: true,
                wrap: true,
                showGutter: false,
            });
        });
        headerEditor.session.setMode('ace/mode/text');
        bodyEditor.session.setMode('ace/mode/html');
    }
    const getHeader = () => headerEditor ? headerEditor.getValue() : headerElement.value;
    const setHeader = (value) => headerEditor ? headerEditor.setValue(value, -1) : (headerElement.value = value);
    const getBody = () => bodyEditor ? bodyEditor.getValue() : bodyElement.value;
    const setBody = (value) => bodyEditor ? bodyEditor.setValue(value, -1) : (bodyElement.value = value);
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

    const openDialog = (dialog, focusTarget = null) => {
        if (!dialog?.showModal) {
            showToast('This browser does not support the required dialog control.', 'danger');
            return;
        }
        dialog.showModal();
        (focusTarget || dialog.querySelector('input, button, textarea, select'))?.focus();
    };

    document.addEventListener('click', (event) => {
        const closeButton = event.target.closest('[data-dialog-close]');
        if (closeButton) {
            closeButton.closest('dialog')?.close();
        }
    });

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
        if (!action) {
            return;
        }
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
                const address = server.dataset.ip || '';
                const host = address.includes(':') && !address.startsWith('[') ? `[${address}]` : address;
                if (!host) return;
                const port = Number(page.dataset.pmtaAdminPort || 1213);
                const opened = window.open(`${window.location.protocol}//${host}:${port}/`, '_blank');
                if (opened) {
                    opened.opener = null;
                }
            });
        }
    };
    [quickNavMenu, contextMenu].forEach((menu) => menu.addEventListener('click', (event) => {
        runQuickAction(event.target.closest('[data-quick-action]')?.dataset.quickAction);
    }));

    const emailTextarea = byId('email_test');
    byId('saved_emails').addEventListener('change', (event) => {
        emailTextarea.value = [...event.target.selectedOptions].map((option) => option.value).join('\n');
    });
    byId('manage-emails').addEventListener('click', () => openDialog(byId('global-email-dialog'), byId('new-test-email')));

    const addressOptionExists = (email) => [...byId('saved_emails').options].some((option) => option.value === email);
    const addAddressToUi = ({ id, email }) => {
        if (!addressOptionExists(email)) {
            const option = new Option(email, email);
            option.dataset.addressId = id;
            byId('saved_emails').add(option);
        }
        if (![...byId('saved-email-list').children].some((item) => item.dataset.addressId === String(id))) {
            const row = document.createElement('li');
            row.className = 'list-group-item d-flex justify-content-between align-items-center';
            row.dataset.addressId = id;
            const label = document.createElement('span');
            label.textContent = email;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-alt-danger remove-saved-email';
            remove.setAttribute('aria-label', `Delete ${email}`);
            remove.title = `Delete ${email}`;
            remove.innerHTML = '<i class="fas fa-trash" aria-hidden="true"></i>';
            row.append(label, remove);
            byId('saved-email-list').append(row);
        }
    };

    byId('save-email-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const input = byId('new-test-email');
        const submit = event.currentTarget.querySelector('[type="submit"]');
        if (!input.reportValidity()) {
            return;
        }
        submit.disabled = true;
        try {
            const result = await jsonRequest(page.dataset.addressStoreUrl, {
                method: 'POST',
                body: JSON.stringify({ email: input.value.trim() }),
            });
            addAddressToUi(result.data);
            input.value = '';
            showToast('Email address saved.', 'success');
        } catch (error) {
            showToast(error.message, 'danger');
        } finally {
            submit.disabled = false;
        }
    });

    byId('saved-email-list').addEventListener('click', async (event) => {
        const remove = event.target.closest('.remove-saved-email');
        if (!remove) {
            return;
        }
        const row = remove.closest('[data-address-id]');
        remove.disabled = true;
        try {
            await jsonRequest(`${page.dataset.addressDeleteBase}/${encodeURIComponent(row.dataset.addressId)}`, { method: 'DELETE' });
            const email = row.querySelector('span')?.textContent || '';
            row.remove();
            [...byId('saved_emails').options].filter((option) => option.value === email).forEach((option) => option.remove());
            emailTextarea.value = [...byId('saved_emails').selectedOptions].map((option) => option.value).join('\n');
            showToast('Email address deleted.', 'success');
        } catch (error) {
            showToast(error.message, 'danger');
            remove.disabled = false;
        }
    });

    const headerSelect = byId('saved_headers');
    if (headerEditor) {
        headerEditor.on('focus', () => { lastEditor = headerEditor; });
        bodyEditor.on('focus', () => { lastEditor = bodyEditor; });
    } else {
        headerElement.addEventListener('focus', () => { lastEditor = headerElement; });
        bodyElement.addEventListener('focus', () => { lastEditor = bodyElement; });
    }
    emailTextarea.addEventListener('focus', () => { lastEditor = emailTextarea; });

    headerSelect.addEventListener('change', async () => {
        if (!headerSelect.value) {
            return;
        }
        try {
            const result = await jsonRequest(`${page.dataset.headerBase}/${encodeURIComponent(headerSelect.value)}`, { method: 'GET', headers: { 'Content-Type': 'application/json' } });
            setHeader(result.data);
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
            byId('global-header-name-dialog').close();
            nameInput.value = '';
            showToast('Header saved.', 'success');
        } catch (error) {
            showToast(error.message, 'danger');
        } finally {
            button.disabled = false;
        }
    });

    byId('delete-header').addEventListener('click', () => {
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
            byId('global-confirm-dialog').close();
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
        editor.focus();
    };
    document.querySelectorAll('[data-insert-tag]').forEach((button) => button.addEventListener('click', () => insertTag(button.dataset.insertTag)));
    byId('show-global-tags').addEventListener('click', () => openDialog(byId('global-tags-dialog')));

    const setIpTab = (tab) => {
        const stringSelected = tab === 'string';
        byId('global-encoding-string-tab').setAttribute('aria-selected', String(stringSelected));
        byId('global-encoding-ip-tab').setAttribute('aria-selected', String(!stringSelected));
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
                `Hexadecimal IP :${hex}`,
                `OctalHexa IP : ${octalHex}`,
                `DecimalHexa IP : ${decimalHex}`,
                `DecimalOctal IP : ${decimalOctal}`,
            ].join('\n');
        } catch (error) {
            showToast(error.message, 'warning');
        }
    });

    const serverSelect = byId('servers');
    const availableSelect = byId('ips-available');
    const selectedSelect = byId('ips-selected');
    const catalogUrl = new URL(page.dataset.catalogUrl, window.location.origin);

    const applyOptionFilter = (select, filter) => {
        const query = filter.value.trim().toLocaleLowerCase();
        [...select.options].forEach((option) => {
            option.hidden = query !== '' && !option.textContent.toLocaleLowerCase().includes(query);
        });
    };

    byId('toggle-server-search').addEventListener('click', () => {
        const search = byId('server-search');
        search.hidden = !search.hidden;
        if (!search.hidden) {
            search.focus();
        } else {
            search.value = '';
            applyOptionFilter(serverSelect, search);
        }
    });
    byId('server-search').addEventListener('input', (event) => applyOptionFilter(serverSelect, event.currentTarget));

    const renderServerOptions = (servers, selectedIds = []) => {
        const selected = new Set(selectedIds.map(String));
        serverSelect.replaceChildren();
        servers.forEach((server) => {
            const option = new Option(`${server.name} | ${server.ip}`, server.id, false, selected.has(String(server.id)));
            option.dataset.ip = server.ip;
            serverSelect.add(option);
        });
        applyOptionFilter(serverSelect, byId('server-search'));
    };

    const renderIpOptions = (ips) => {
        const selectedIds = new Set([...selectedSelect.options].map((option) => String(option.value)));
        const known = new Map(ips.map((ip) => [String(ip.id), ip]));
        [...selectedIds].forEach((id) => {
            if (!known.has(id)) {
                selectedIds.delete(id);
                [...selectedSelect.options].filter((option) => option.value === id).forEach((option) => option.remove());
            }
        });
        availableSelect.replaceChildren();
        ips.forEach((ip) => {
            const label = `${ip.ip},${ip.dns || ''}${ip.username ? ` (${ip.username})` : ''}`;
            if (!selectedIds.has(String(ip.id))) {
                availableSelect.add(new Option(label, ip.id));
            }
        });
        applyOptionFilter(availableSelect, byId('ips-available-filter'));
        applyOptionFilter(selectedSelect, byId('ips-selected-filter'));
    };

    const loadCatalog = async (includeServerChoices = false) => {
        const selectedServers = [...serverSelect.selectedOptions].map((option) => option.value);
        if (includeServerChoices) {
            catalogUrl.searchParams.delete('servers[]');
            catalogUrl.searchParams.delete('servers');
        } else {
            catalogUrl.searchParams.delete('servers[]');
            selectedServers.forEach((id) => catalogUrl.searchParams.append('servers[]', id));
        }
        catalogUrl.searchParams.set('option', byId('option').value);
        catalogUrl.searchParams.set('type', byId('type').value);
        try {
            const response = await fetch(catalogUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const result = await response.json();
            if (!response.ok) {
                throw new Error(result.message || 'Could not load the selected servers.');
            }
            if (includeServerChoices) {
                const current = selectedServers;
                renderServerOptions(result.servers || [], current);
            }
            renderIpOptions(result.ips || []);
        } catch (error) {
            showToast(error.message, 'danger');
        }
    };

    serverSelect.addEventListener('change', () => {
        selectedSelect.replaceChildren();
        loadCatalog(false);
    });
    byId('option').addEventListener('change', () => {
        selectedSelect.replaceChildren();
        loadCatalog(false);
    });
    byId('type').addEventListener('change', () => {
        selectedSelect.replaceChildren();
        loadCatalog(false);
    });
    byId('reload-servers').addEventListener('click', (event) => {
        const icon = event.currentTarget.querySelector('i');
        icon.classList.add('fa-spin');
        selectedSelect.replaceChildren();
        loadCatalog(true).finally(() => icon.classList.remove('fa-spin'));
    });

    const moveOptions = (source, destination, moveAll = false) => {
        const candidates = [...source.options].filter((option) => moveAll || option.selected);
        candidates.forEach((option) => {
            const exists = [...destination.options].some((selected) => selected.value === option.value);
            if (!exists) {
                destination.add(new Option(option.textContent, option.value));
            }
            option.remove();
        });
        applyOptionFilter(availableSelect, byId('ips-available-filter'));
        applyOptionFilter(selectedSelect, byId('ips-selected-filter'));
    };
    byId('move-all-right').addEventListener('click', () => moveOptions(availableSelect, selectedSelect, true));
    byId('move-right').addEventListener('click', () => moveOptions(availableSelect, selectedSelect));
    byId('move-left').addEventListener('click', () => moveOptions(selectedSelect, availableSelect));
    byId('move-all-left').addEventListener('click', () => moveOptions(selectedSelect, availableSelect, true));
    byId('ips-available-filter').addEventListener('input', (event) => applyOptionFilter(availableSelect, event.currentTarget));
    byId('ips-selected-filter').addEventListener('input', (event) => applyOptionFilter(selectedSelect, event.currentTarget));
    document.querySelectorAll('.global-clear-filter').forEach((button) => button.addEventListener('click', () => {
        const input = byId(button.dataset.clear);
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
    }));

    byId('add-placeholder').addEventListener('click', () => {
        const row = document.createElement('tr');
        row.className = 'placeholder-row';
        const valuesCell = document.createElement('td');
        const values = document.createElement('textarea');
        values.className = 'form-control form-control-sm placeholder-data';
        values.rows = 2;
        values.setAttribute('aria-label', 'Placeholder values, one per line');
        valuesCell.append(values);
        const rotateCell = document.createElement('td');
        const rotate = document.createElement('input');
        rotate.className = 'form-control form-control-sm placeholder-rotate';
        rotate.type = 'number';
        rotate.min = '1';
        rotate.value = '1';
        rotate.setAttribute('aria-label', 'Rotate every');
        rotateCell.append(rotate);
        const actionCell = document.createElement('td');
        const remove = document.createElement('button');
        remove.className = 'btn btn-sm btn-alt-danger remove-placeholder';
        remove.type = 'button';
        remove.setAttribute('aria-label', 'Remove placeholder row');
        remove.title = 'Remove row';
        remove.innerHTML = '<i class="fas fa-times" aria-hidden="true"></i>';
        actionCell.append(remove);
        row.append(valuesCell, rotateCell, actionCell);
        document.querySelector('#placeholders tbody').append(row);
    });
    byId('placeholders').addEventListener('click', (event) => {
        const remove = event.target.closest('.remove-placeholder');
        if (!remove) {
            return;
        }
        const rows = document.querySelectorAll('.placeholder-row');
        if (rows.length === 1) {
            rows[0].querySelector('.placeholder-data').value = '';
            rows[0].querySelector('.placeholder-rotate').value = '1';
        } else {
            remove.closest('.placeholder-row').remove();
        }
    });
    byId('placeholder_combination').addEventListener('change', (event) => {
        if (event.currentTarget.checked) {
            byId('placeholder_all_combination').checked = false;
        }
    });
    byId('placeholder_all_combination').addEventListener('change', (event) => {
        if (event.currentTarget.checked) {
            byId('placeholder_combination').checked = false;
        }
    });
    byId('toggle-placeholders').addEventListener('click', (event) => {
        const content = byId('placeholder-content');
        content.hidden = !content.hidden;
        event.currentTarget.setAttribute('aria-label', content.hidden ? 'Expand placeholders' : 'Collapse placeholders');
        event.currentTarget.innerHTML = `<i class="si ${content.hidden ? 'si-arrow-down' : 'si-arrow-up'}" aria-hidden="true"></i>`;
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

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const emailTests = emailTextarea.value.split(/\r?\n/).map((value) => value.trim()).filter(Boolean);
        const serverIds = [...serverSelect.selectedOptions].map((option) => option.value);
        const ipIds = [...selectedSelect.options].map((option) => option.value);
        if (emailTests.length === 0 || !getHeader().trim() || serverIds.length === 0 || ipIds.length === 0) {
            const message = emailTests.length === 0 ? 'Enter at least one test email.'
                : !getHeader().trim() ? 'Enter a message header.'
                    : serverIds.length === 0 ? 'Select at least one server.' : 'Move at least one IP or MTA into Selected IPs.';
            setStatus(message, 'warning');
            showToast(message, 'warning');
            return;
        }

        const payload = {
            return_path: byId('return_path').value,
            email_test: emailTests,
            negative: byId('negative').value || null,
            header: encodeBase64(getHeader()),
            body: encodeBase64(getBody()),
            option: byId('option').value,
            type: byId('type').value,
            servers: serverIds,
            ips: ipIds,
            placeholder_combination: byId('placeholder_combination').checked,
            placeholder_all_combination: byId('placeholder_all_combination').checked,
            placeholders: readPlaceholders(),
            repeat: byId('repeat').value,
        };
        const button = byId('send-global-test');
        const originalHtml = button.innerHTML;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>TEST RUNNING';
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
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.innerHTML = originalHtml;
        }
    });

    byId('global-confirm-dialog').addEventListener('close', () => {
        byId('delete-header').focus();
    });
    byId('global-header-name-dialog').addEventListener('close', () => byId('save-header').focus());
    byId('global-preview-dialog').addEventListener('close', () => byId('preview-body').focus());
    byId('global-tags-dialog').addEventListener('close', () => byId('show-global-tags').focus());
    byId('global-encoding-dialog').addEventListener('close', () => byId('open-encoding').focus());
    byId('global-email-dialog').addEventListener('close', () => byId('manage-emails').focus());
})();
