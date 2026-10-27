(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    })[character]);
    const toast = (text, tone) => {
        if (window.AlphaLinkToast) {
            window.AlphaLinkToast.show(text, tone || 'info');
        }
    };
    const errorText = async (response) => {
        const body = await response.json().catch(() => ({}));
        if (body.message) {
            return body.message;
        }
        return Object.values(body.errors || {}).flat().join(' ') || ('Request failed (' + response.status + ').');
    };
    const requestJson = async (url, method, body) => {
        const response = await fetch(url, {
            method: method || 'GET',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        if (!response.ok) {
            throw new Error(await errorText(response));
        }
        return response.json();
    };
    const showMessage = (element, text) => {
        element.textContent = text;
        element.hidden = text === '';
    };
    const confirmAction = async (title, description, label) => {
        if (typeof window.PmtaControls?.confirm === 'function') {
            return window.PmtaControls.confirm(title, description, label || 'Continue', true);
        }
        return window.confirm(title + '\n\n' + description);
    };

    const listRoot = document.getElementById('warmup-seeds');
    if (listRoot) {
        const tableElement = document.getElementById('warmup-seeds-table');
        const message = document.getElementById('warmup-seeds-message');
        const dialog = document.getElementById('warmup-seeds-dialog');
        const dialogTitle = document.getElementById('warmup-seeds-dialog-title');
        const dialogContent = document.getElementById('warmup-seeds-dialog-content');
        const closeDialog = () => dialog.close();
        document.getElementById('warmup-seeds-dialog-close').addEventListener('click', closeDialog);
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                closeDialog();
            }
        });

        if (!window.jQuery?.fn?.DataTable) {
            showMessage(message, 'The Seeds table could not start because its local table assets did not load.');
        } else {
            const $ = window.jQuery;
            const grid = $(tableElement).DataTable({
                ajax: { url: listRoot.dataset.rowsUrl, dataSrc: 'data' },
                serverSide: true,
                processing: true,
                displayLength: 50,
                order: [[0, 'desc']],
                dom: '<"dms-table-tools"B>frtip',
                buttons: [
                    { extend: 'pageLength' },
                    { text: '<i class="fas fa-sync" aria-hidden="true"></i>', titleAttr: 'REFRESH', action: (event, table) => table.ajax.reload(null, false) },
                    { text: '<i class="fas fa-plus" aria-hidden="true"></i>', titleAttr: 'ADD NEW SEED LIST', action: () => openForm(listRoot.dataset.createFormUrl, 'ADD SEED LIST') },
                    { text: '<i class="fas fa-broom" aria-hidden="true"></i>', titleAttr: 'CLEAN SEEDS', action: () => openForm(listRoot.dataset.cleanFormUrl, 'CLEAN SEEDS') },
                ],
                rowCallback: (row, data) => row.classList.toggle('table-danger', !data.is_active),
                columns: [
                    { title: '#', data: 'id', width: '5%' },
                    { title: 'owner', data: 'user', searchable: false, orderable: false, render: (data) => escapeHtml(data?.username || 'NA') },
                    { title: 'name', data: 'name', render: (data) => escapeHtml(data) },
                    { title: 'total emails', data: 'seed_emails_count', searchable: false, orderable: false, render: (data, type, row) => '<a href="' + escapeHtml(listRoot.dataset.showUrlTemplate.replace('__SEED_ID__', encodeURIComponent(row.id))) + '" target="_blank" rel="noopener">' + escapeHtml(data) + '</a>' },
                    { title: 'created at', data: 'created_at' },
                    { title: 'actions', data: 'id', searchable: false, orderable: false, render: (id, type, row) => {
                        const add = '<button data-id="' + escapeHtml(id) + '" class="btn btn-info btn-sm seed-email-add" title="ADD EMAILS" aria-label="Add emails"><i class="fas fa-plus" aria-hidden="true"></i></button> ';
                        const status = '<button data-id="' + escapeHtml(id) + '" data-status="' + (row.is_active ? '0' : '1') + '" class="btn btn-' + (row.is_active ? 'warning' : 'success') + ' btn-sm seed-status" title="' + (row.is_active ? 'DISABLE LIST' : 'ENABLE LIST') + '" aria-label="' + (row.is_active ? 'Disable list' : 'Enable list') + '"><i class="fas ' + (row.is_active ? 'fa-times' : 'fa-check') + '" aria-hidden="true"></i></button> ';
                        const remove = row.can_delete ? '<button data-id="' + escapeHtml(id) + '" class="btn btn-danger btn-sm seed-remove" title="REMOVE LIST" aria-label="Remove list"><i class="fas fa-trash" aria-hidden="true"></i></button>' : '';
                        return add + status + remove;
                    } },
                ],
            });

            async function openForm(url, title) {
                dialogTitle.textContent = title;
                dialogContent.textContent = 'Loading…';
                dialog.showModal();
                try {
                    const response = await fetch(url, { headers: { Accept: 'text/html' } });
                    if (!response.ok) {
                        throw new Error('Could not load this form (' + response.status + ').');
                    }
                    dialogContent.innerHTML = await response.text();
                    if (window.jQuery?.fn?.selectpicker) {
                        window.jQuery(dialogContent).find('.selectpicker').selectpicker();
                    }
                } catch (error) {
                    dialogContent.textContent = error.message;
                }
            }

            dialogContent.addEventListener('submit', async (event) => {
                const form = event.target.closest('form[data-seed-form]');
                if (!form) {
                    return;
                }
                event.preventDefault();
                const button = form.querySelector('button[type="submit"]');
                const error = form.querySelector('.seed-form-error');
                const original = button.textContent;
                button.disabled = true;
                button.textContent = 'SAVING…';
                error.hidden = true;
                try {
                    let url;
                    let method = 'POST';
                    let payload;
                    if (form.dataset.seedForm === 'create') {
                        url = listRoot.dataset.storeUrl;
                        payload = { name: form.querySelector('[name="name"]').value };
                    } else if (form.dataset.seedForm === 'upload') {
                        url = listRoot.dataset.uploadUrlTemplate.replace('__SEED_ID__', encodeURIComponent(form.dataset.seedId));
                        payload = { emails: form.querySelector('[name="emails"]').value.split(/\r?\n/).map((value) => value.trim()).filter(Boolean) };
                    } else {
                        url = listRoot.dataset.cleanUrl;
                        payload = { server: form.querySelector('#seed-clean-server').value, date: form.querySelector('#seed-clean-date').value };
                        button.textContent = 'CLEANING…';
                    }
                    const result = await requestJson(url, method, payload);
                    toast(result.data || 'Completed', 'success');
                    closeDialog();
                    grid.ajax.reload(null, false);
                } catch (failure) {
                    error.textContent = failure.message;
                    error.hidden = false;
                } finally {
                    button.disabled = false;
                    button.textContent = original;
                }
            });

            tableElement.addEventListener('click', async (event) => {
                const button = event.target.closest('button[data-id]');
                if (!button) {
                    return;
                }
                const id = button.dataset.id;
                const original = button.innerHTML;
                if (button.classList.contains('seed-email-add')) {
                    await openForm(listRoot.dataset.uploadFormTemplate.replace('__SEED_ID__', encodeURIComponent(id)), 'ADD EMAILS IN SEED');
                    return;
                }
                if (button.classList.contains('seed-status')) {
                    button.disabled = true;
                    try {
                        const url = listRoot.dataset.statusUrlTemplate.replace('__SEED_ID__', encodeURIComponent(id));
                        const result = await requestJson(url, 'PATCH', { status: button.dataset.status });
                        toast(result.data, 'success');
                        grid.ajax.reload(null, false);
                    } catch (failure) {
                        toast(failure.message, 'danger');
                    } finally {
                        button.disabled = false;
                        button.innerHTML = original;
                    }
                    return;
                }
                if (button.classList.contains('seed-remove')) {
                    if (!await confirmAction('Remove seed list?', 'The list and its saved email rows will be removed.', 'Remove')) {
                        return;
                    }
                    button.disabled = true;
                    try {
                        const url = listRoot.dataset.deleteUrlTemplate.replace('__SEED_ID__', encodeURIComponent(id));
                        const result = await requestJson(url, 'DELETE', {});
                        toast(result.data, 'success');
                        grid.ajax.reload(null, false);
                    } catch (failure) {
                        toast(failure.message, 'danger');
                    } finally {
                        button.disabled = false;
                        button.innerHTML = original;
                    }
                }
            });
        }
    }

    const emailsRoot = document.getElementById('warmup-seed-emails');
    if (emailsRoot) {
        const tableElement = document.getElementById('warmup-seed-emails-table');
        const message = document.getElementById('warmup-seed-emails-message');
        const dialog = document.getElementById('warmup-seed-emails-dialog');
        const dialogContent = document.getElementById('warmup-seed-emails-dialog-content');
        document.getElementById('warmup-seed-emails-dialog-close').addEventListener('click', () => dialog.close());
        if (!window.jQuery?.fn?.DataTable) {
            showMessage(message, 'The seed email table could not start because its local table assets did not load.');
            return;
        }
        const $ = window.jQuery;
        const grid = $(tableElement).DataTable({
            ajax: { url: emailsRoot.dataset.rowsUrl, dataSrc: 'data' },
            serverSide: true,
            processing: true,
            displayLength: 50,
            order: [[1, 'desc']],
            select: { style: 'os', selector: 'td:first-child' },
            dom: '<"dms-table-tools"B>frtip',
            buttons: [
                { extend: 'pageLength' },
                { text: '<i class="fas fa-trash" aria-hidden="true"></i>', titleAttr: 'DELETE EMAILS', action: removeSelected },
                { text: '<i class="fas fa-sync" aria-hidden="true"></i>', titleAttr: 'REFRESH', action: (event, table) => table.ajax.reload(null, false) },
            ],
            columns: [
                { data: null, defaultContent: '', orderable: false, searchable: false, className: 'select-checkbox' },
                { title: '#', data: 'id' },
                { title: 'email', data: 'email', render: (value) => escapeHtml(value) },
                { title: 'created at', data: 'created_at' },
                { title: 'updated at', data: 'updated_at' },
                { title: 'actions', data: 'id', defaultContent: '', orderable: false, searchable: false, render: (id) => '<button class="btn btn-sm btn-warning seed-email-edit" data-id="' + escapeHtml(id) + '" aria-label="Edit email"><i class="fas fa-edit" aria-hidden="true"></i></button>' },
            ],
        });

        async function removeSelected(event, table, node) {
            const ids = table.rows({ selected: true }).data().toArray().map((row) => row.id);
            if (!ids.length || !await confirmAction('Delete selected seed emails?', 'The selected addresses will be removed from this list.', 'Delete')) {
                return;
            }
            table.buttons(node).disable();
            try {
                const result = await requestJson(emailsRoot.dataset.deleteUrl, 'DELETE', { ids: ids });
                toast(result.data, 'success');
                table.ajax.reload(null, false);
            } catch (failure) {
                toast(failure.message, 'danger');
            } finally {
                table.buttons(node).enable();
            }
        }

        tableElement.addEventListener('click', async (event) => {
            const button = event.target.closest('.seed-email-edit');
            if (!button) {
                return;
            }
            dialogContent.textContent = 'Loading…';
            dialog.showModal();
            try {
                const url = emailsRoot.dataset.editFormTemplate.replace('__EMAIL_ID__', encodeURIComponent(button.dataset.id));
                const response = await fetch(url, { headers: { Accept: 'text/html' } });
                if (!response.ok) {
                    throw new Error('Could not load the email editor (' + response.status + ').');
                }
                dialogContent.innerHTML = await response.text();
            } catch (failure) {
                dialogContent.textContent = failure.message;
            }
        });

        dialogContent.addEventListener('submit', async (event) => {
            const form = event.target.closest('form[data-seed-form="edit-email"]');
            if (!form) {
                return;
            }
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            const error = form.querySelector('.seed-form-error');
            button.disabled = true;
            const original = button.textContent;
            button.textContent = 'UPDATING…';
            try {
                const url = emailsRoot.dataset.updateUrlTemplate.replace('__EMAIL_ID__', encodeURIComponent(form.dataset.emailId));
                const result = await requestJson(url, 'PUT', { email: form.querySelector('[name="email"]').value });
                toast(result.data, 'success');
                dialog.close();
                grid.ajax.reload(null, false);
            } catch (failure) {
                error.textContent = failure.message;
                error.hidden = false;
            } finally {
                button.disabled = false;
                button.textContent = original;
            }
        });
    }
})();
