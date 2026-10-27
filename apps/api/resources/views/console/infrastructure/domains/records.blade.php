@extends('layouts.dms-reference')

@section('title', 'Domain Records - DMS')

@push('styles')
    <style>
        .dns-record-editor { min-width: 48rem; }
        .dns-record-toolbar { display: flex; flex-wrap: wrap; gap: .5rem; }
        .dns-record-toolbar .btn { white-space: nowrap; }
        .dns-record-message { min-height: 1.5rem; }
        .dns-record-dialog { border: 0; border-radius: .25rem; box-shadow: 0 8px 28px rgb(52 58 64 / 22%); max-width: 38rem; padding: 1.25rem; width: calc(100% - 2rem); }
        .dns-record-dialog::backdrop { background: rgb(52 58 64 / 45%); }
        .dns-record-dialog textarea { min-height: 9rem; }
        .dns-record-dialog-actions { display: flex; gap: .5rem; justify-content: flex-end; margin-top: 1rem; }
        @media (max-width: 575.98px) { .dns-record-editor { min-width: 42rem; } }
    </style>
@endpush

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Editing <span class="text-info">{{ $domain->name }}</span> nameservers</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle nameservers full screen"></button>
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="content_toggle" aria-label="Collapse nameservers"><i class="si si-arrow-down" aria-hidden="true"></i></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            @if($nameservers['status'])
                <div class="table-responsive">
                    <table class="table table-sm table-hover dns-record-editor" id="nameservers-editor">
                        <thead><tr><th scope="col">NAMESERVER</th><th scope="col" aria-label="Actions"></th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div class="dns-record-toolbar justify-content-end">
                    <button type="button" class="btn btn-secondary btn-sm" data-add-row="nameservers"><i class="fas fa-plus" aria-hidden="true"></i> Add nameserver</button>
                    <button type="button" class="btn btn-primary" id="edit_nameservers"><i class="fas fa-save" aria-hidden="true"></i> EDIT NAMESERVERS</button>
                    <button type="button" class="btn btn-danger" id="reset_nameservers"><i class="fas fa-undo" aria-hidden="true"></i> RESET NAMESERVERS</button>
                </div>
                <p id="nameservers-message" class="dns-record-message text-muted mb-0" role="status" aria-live="polite"></p>
            @else
                <p class="text-danger text-uppercase text-center" role="alert">{{ $nameservers['data'] }}</p>
            @endif
        </div>
    </div>

    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Editing <span class="text-info">{{ $domain->name }}</span> hosts</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle hosts full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            @if($hosts['status'])
                <div class="table-responsive">
                    <table class="table table-sm table-hover dns-record-editor" id="hosts-editor">
                        <thead><tr><th scope="col">HOST</th><th scope="col">TYPE</th><th scope="col">VALUE</th><th scope="col">TTL</th><th scope="col" aria-label="Actions"></th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div class="dns-record-toolbar">
                    <button type="button" class="btn btn-secondary btn-sm" data-add-row="hosts"><i class="fas fa-plus" aria-hidden="true"></i> Add host</button>
                    <button type="button" class="btn btn-danger" id="clear_hosts"><i class="fas fa-eraser" aria-hidden="true"></i> CLEAR HOSTS</button>
                    <button type="button" class="btn btn-primary" id="export_hosts"><i class="fas fa-file-export" aria-hidden="true"></i> EXPORT HOSTS</button>
                    <button type="button" class="btn btn-primary" id="import_hosts"><i class="fas fa-file-import" aria-hidden="true"></i> IMPORT HOSTS</button>
                    <button type="button" class="btn btn-primary ml-md-auto" id="edit_hosts"><i class="fas fa-save" aria-hidden="true"></i> EDIT HOSTS</button>
                </div>
                <p id="hosts-message" class="dns-record-message text-muted mb-0" role="status" aria-live="polite"></p>
            @else
                <p class="text-danger text-uppercase text-center" role="alert">{{ $hosts['data'] }}</p>
            @endif
        </div>
    </div>

    <dialog class="dns-record-dialog" id="remove-row-dialog" aria-labelledby="remove-row-title" aria-describedby="remove-row-description">
        <h2 id="remove-row-title">Remove this row?</h2>
        <p id="remove-row-description">The row will be removed from the editor. Save the page to apply DNS changes.</p>
        <div class="dns-record-dialog-actions">
            <button type="button" class="btn btn-secondary" data-dialog-close>Cancel</button>
            <button type="button" class="btn btn-danger" id="confirm-remove-row">Remove row</button>
        </div>
    </dialog>

    <dialog class="dns-record-dialog" id="import-hosts-dialog" aria-labelledby="import-hosts-title">
        <h2 id="import-hosts-title">Import host records</h2>
        <p>Enter one record per line as <code>host;type;value;ttl</code>.</p>
        <label for="import-hosts-text">Records</label>
        <textarea class="form-control" id="import-hosts-text" placeholder="@;A;192.0.2.1;600"></textarea>
        <div class="dns-record-dialog-actions">
            <button type="button" class="btn btn-secondary" data-dialog-close>Cancel</button>
            <button type="button" class="btn btn-primary" id="confirm-import-hosts">Import records</button>
        </div>
    </dialog>
@endsection

@push('scripts')
    <script>
        (() => {
            const endpoint = @json(route('console.infrastructure.domains.records', [$workspace->id, $domain->id]));
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const hostTypes = ['A', 'AAAA', 'ALIAS', 'CAA', 'CNAME', 'MX', 'MXE', 'NS', 'TXT', 'URL', 'URL301', 'FRAME', 'SRV'];
            const initialHosts = @json($hosts['status'] ? $hosts['data'] : []);
            const initialNameservers = @json($nameservers['status'] ? $nameservers['data'] : []);
            const rowDialog = document.getElementById('remove-row-dialog');
            let rowPendingRemoval = null;

            const createInput = (name, value, type = 'text') => {
                const input = document.createElement('input');
                input.className = 'form-control form-control-sm';
                input.name = name;
                input.type = type;
                input.value = value ?? '';
                if (type === 'number') { input.min = '0'; input.step = '1'; }
                return input;
            };

            const createRemoveButton = () => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-danger btn-sm';
                button.dataset.removeRow = 'true';
                button.setAttribute('aria-label', 'Remove row');
                const icon = document.createElement('i');
                icon.className = 'fas fa-times';
                icon.setAttribute('aria-hidden', 'true');
                button.append(icon);
                return button;
            };

            const addRow = (kind, values = {}) => {
                const body = document.querySelector(`#${kind}-editor tbody`);
                if (!body) { return; }
                const row = document.createElement('tr');
                if (kind === 'nameservers') {
                    const value = document.createElement('td');
                    value.append(createInput('nameserver', values.nameserver));
                    row.append(value);
                } else {
                    const host = document.createElement('td');
                    host.append(createInput('host', values.host));
                    const typeCell = document.createElement('td');
                    const type = document.createElement('select');
                    type.className = 'form-control form-control-sm';
                    type.name = 'type';
                    hostTypes.forEach((optionValue) => {
                        const option = document.createElement('option');
                        option.value = optionValue;
                        option.textContent = optionValue;
                        type.append(option);
                    });
                    if (values.type && !hostTypes.includes(values.type)) {
                        const option = document.createElement('option');
                        option.value = values.type;
                        option.textContent = values.type;
                        type.append(option);
                    }
                    type.value = values.type || 'A';
                    typeCell.append(type);
                    const content = document.createElement('td');
                    content.append(createInput('value', values.value));
                    const ttl = document.createElement('td');
                    ttl.append(createInput('ttl', values.ttl ?? '', 'number'));
                    row.append(host, typeCell, content, ttl);
                }
                const actions = document.createElement('td');
                actions.className = 'text-right';
                actions.append(createRemoveButton());
                row.append(actions);
                body.append(row);
            };

            const rowsFor = (kind) => [...document.querySelectorAll(`#${kind}-editor tbody tr`)].map((row) => {
                const values = [...row.querySelectorAll('[name]')].map((field) => field.value);
                return kind === 'nameservers'
                    ? {nameserver: values[0]}
                    : {host: values[0], type: values[1], value: values[2], ttl: values[3]};
            });

            const setMessage = (kind, message, isError = false) => {
                const target = document.getElementById(`${kind}-message`);
                if (!target) { return; }
                target.textContent = message;
                target.classList.toggle('text-danger', isError);
                target.classList.toggle('text-success', !isError && Boolean(message));
            };

            const save = async (kind, type, data, button) => {
                if (!button) { return false; }
                const label = button.innerHTML;
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…';
                setMessage(kind, 'Saving changes…');
                try {
                    const response = await fetch(endpoint, {
                        method: 'POST',
                        headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                        body: JSON.stringify({type, data})
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok) { throw new Error(payload.data || payload.message || 'Failed'); }
                    setMessage(kind, payload.data || 'Updated Successfully');
                    return true;
                } catch (error) {
                    setMessage(kind, error.message || 'Failed', true);
                    return false;
                } finally {
                    button.disabled = false;
                    button.innerHTML = label;
                }
            };

            initialHosts.length ? initialHosts.forEach((row) => addRow('hosts', row)) : addRow('hosts');
            initialNameservers.length ? initialNameservers.forEach((row) => addRow('nameservers', row)) : addRow('nameservers');

            document.querySelectorAll('[data-add-row]').forEach((button) => button.addEventListener('click', () => addRow(button.dataset.addRow)));
            document.querySelectorAll('.dns-record-editor').forEach((table) => table.addEventListener('click', (event) => {
                const button = event.target.closest('[data-remove-row]');
                if (!button) { return; }
                rowPendingRemoval = button.closest('tr');
                rowDialog.showModal();
            }));
            document.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', () => button.closest('dialog').close()));
            document.getElementById('confirm-remove-row').addEventListener('click', () => {
                rowPendingRemoval?.remove();
                rowPendingRemoval = null;
                rowDialog.close();
            });

            document.getElementById('edit_nameservers')?.addEventListener('click', (event) => save('nameservers', 'nameservers', rowsFor('nameservers'), event.currentTarget));
            document.getElementById('reset_nameservers')?.addEventListener('click', async (event) => {
                const reset = await save('nameservers', 'reset', [1], event.currentTarget);
                if (reset) { window.location.reload(); }
            });
            document.getElementById('edit_hosts')?.addEventListener('click', (event) => save('hosts', 'hosts', rowsFor('hosts'), event.currentTarget));
            document.getElementById('clear_hosts')?.addEventListener('click', () => {
                document.querySelector('#hosts-editor tbody').replaceChildren();
                addRow('hosts');
                setMessage('hosts', 'Host rows cleared in the editor. Select EDIT HOSTS to save.');
            });

            document.getElementById('export_hosts')?.addEventListener('click', () => {
                const data = rowsFor('hosts').map((row) => `${row.host};${row.type};${row.value};${row.ttl}`).join('\n');
                if (!data) { setMessage('hosts', 'There are no host records to export.', true); return; }
                const url = URL.createObjectURL(new Blob([data], {type: 'text/plain;charset=utf-8'}));
                const link = document.createElement('a');
                link.href = url;
                link.download = @json($domain->name.'_host.txt');
                link.click();
                URL.revokeObjectURL(url);
                setMessage('hosts', 'Host records exported.');
            });

            const importDialog = document.getElementById('import-hosts-dialog');
            document.getElementById('import_hosts')?.addEventListener('click', () => importDialog.showModal());
            document.getElementById('confirm-import-hosts').addEventListener('click', () => {
                const imported = document.getElementById('import-hosts-text').value.split(/\r?\n/).flatMap((line) => {
                    const record = line.split(';');
                    return record.length > 3 ? [{host: record[0], type: record[1], value: record[2], ttl: record[3] || 60}] : [];
                });
                if (!imported.length) { setMessage('hosts', 'No valid rows found. Use host;type;value;ttl.', true); return; }
                imported.forEach((row) => addRow('hosts', row));
                document.getElementById('import-hosts-text').value = '';
                importDialog.close();
                setMessage('hosts', `${imported.length} host record${imported.length === 1 ? '' : 's'} loaded. Select EDIT HOSTS to save.`);
            });
        })();
    </script>
@endpush
