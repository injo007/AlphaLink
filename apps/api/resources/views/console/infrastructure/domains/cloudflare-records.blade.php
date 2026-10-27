@extends('layouts.dms-reference')

@section('title', 'Cloudflare DNS Records - DMS')

@push('styles')
    <style>
        .cloudflare-dns-message { min-height: 1.5rem; }
        .cloudflare-dns-dialog { border: 0; border-radius: .25rem; box-shadow: 0 8px 28px rgb(52 58 64 / 22%); max-width: 44rem; padding: 1.25rem; width: calc(100% - 2rem); }
        .cloudflare-dns-dialog::backdrop { background: rgb(52 58 64 / 45%); }
        .cloudflare-dns-dialog textarea { min-height: 7rem; }
        .cloudflare-dns-actions { display: flex; gap: .5rem; justify-content: flex-end; margin-top: 1rem; }
        .cloudflare-dns-records { min-width: 58rem; }
        .cloudflare-dns-help { max-width: 60rem; }
        @media (max-width: 575.98px) { .cloudflare-dns-records { min-width: 48rem; } }
    </style>
@endpush

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">Cloudflare DNS Records <span class="text-info">{{ $domain->name }}</span></h3>
            <div class="block-options">
                <a class="btn btn-sm btn-secondary" href="{{ route('console.infrastructure.domains.index', $workspace->id) }}">Back to domains</a>
                @if($records['status'])
                    <button class="btn btn-sm btn-primary" type="button" id="add-cloudflare-record"><i class="fas fa-plus" aria-hidden="true"></i> Add record</button>
                @endif
            </div>
        </div>
        <div class="block-content block-content-full">
            <p class="cloudflare-dns-help text-muted">Records are managed through the saved Cloudflare API token. Nameservers and zone activation are managed in Cloudflare; this page edits DNS records inside the selected zone.</p>
            @if($records['status'])
                <div class="table-responsive">
                    <table class="table table-sm table-hover cloudflare-dns-records">
                        <thead>
                            <tr><th scope="col">NAME</th><th scope="col">TYPE</th><th scope="col">VALUE / DATA</th><th scope="col">TTL</th><th scope="col">PROXIED</th><th scope="col">PRIORITY</th><th scope="col" aria-label="Actions"></th></tr>
                        </thead>
                        <tbody id="cloudflare-record-list"></tbody>
                    </table>
                </div>
                <p id="cloudflare-record-message" class="cloudflare-dns-message text-muted mb-0" role="status" aria-live="polite"></p>
            @else
                <div class="alert alert-danger" role="alert">{{ $records['data'] }} Verify that the token has Zone Read and DNS Read access for this zone.</div>
                <button class="btn btn-secondary" type="button" onclick="window.location.reload()"><i class="fas fa-sync-alt" aria-hidden="true"></i> Retry</button>
            @endif
        </div>
    </div>

    @if($records['status'])
        <dialog class="cloudflare-dns-dialog" id="cloudflare-record-dialog" aria-labelledby="cloudflare-record-title">
            <form id="cloudflare-record-form" novalidate>
                <h2 class="h4" id="cloudflare-record-title">Add DNS record</h2>
                <div class="form-group">
                    <label for="record-type">Type</label>
                    <select class="form-control" id="record-type" name="type" required>
                        @foreach(['A', 'AAAA', 'CAA', 'CERT', 'CNAME', 'DNSKEY', 'DS', 'HTTPS', 'LOC', 'MX', 'NAPTR', 'NS', 'OPENPGPKEY', 'PTR', 'SMIMEA', 'SRV', 'SSHFP', 'SVCB', 'TLSA', 'TXT', 'URI'] as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="record-name">Name</label>
                    <input class="form-control" id="record-name" name="name" required maxlength="253" placeholder="@ or mail">
                    <small class="form-text text-muted">Relative names are saved inside {{ $domain->name }}. Fully qualified names must belong to this zone.</small>
                </div>
                <div class="form-group">
                    <label for="record-content">Content</label>
                    <textarea class="form-control" id="record-content" name="content" maxlength="4096" placeholder="IP address, target, text value, or other content"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="record-ttl">TTL</label>
                        <input class="form-control" id="record-ttl" name="ttl" type="number" min="1" max="86400" step="1" value="1" required>
                        <small class="form-text text-muted">1 is automatic; otherwise use 60–86400 seconds.</small>
                    </div>
                    <div class="form-group col-md-4" id="record-priority-group">
                        <label for="record-priority">Priority</label>
                        <input class="form-control" id="record-priority" name="priority" type="number" min="0" max="65535" step="1">
                    </div>
                    <div class="form-group col-md-4" id="record-proxied-group">
                        <label for="record-proxied">Proxy</label>
                        <select class="form-control" id="record-proxied" name="proxied">
                            <option value="false">DNS only</option>
                            <option value="true">Proxied</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="record-comment">Comment <small>(optional)</small></label>
                    <input class="form-control" id="record-comment" name="comment" maxlength="500">
                </div>
                <div class="form-group">
                    <label for="record-data">Type-specific data <small>(optional JSON object)</small></label>
                    <textarea class="form-control" id="record-data" name="data_json" maxlength="4000" spellcheck="false" placeholder='{"flags":0,"tag":"issue","value":"ca.example"}'></textarea>
                    <small class="form-text text-muted">Use this for structured record types such as CAA, SRV, TLSA, or URI. Cloudflare validates the fields for the selected type.</small>
                </div>
                <p class="text-muted">Cloudflare token needs DNS Write for changes. Deletes require explicit confirmation.</p>
                <p id="cloudflare-form-error" class="text-danger" role="alert" aria-live="polite"></p>
                <div class="cloudflare-dns-actions">
                    <button class="btn btn-secondary" type="button" data-close-dialog>Cancel</button>
                    <button class="btn btn-primary" id="save-cloudflare-record" type="submit">Save record</button>
                </div>
            </form>
        </dialog>
    @endif
@endsection

@if($records['status'])
    @push('scripts')
        <script>
            (() => {
                const records = @json($records['data']);
                const list = document.getElementById('cloudflare-record-list');
                const dialog = document.getElementById('cloudflare-record-dialog');
                const form = document.getElementById('cloudflare-record-form');
                const message = document.getElementById('cloudflare-record-message');
                const error = document.getElementById('cloudflare-form-error');
                const baseUrl = @json(route('console.infrastructure.domains.records.cloudflare.store', [$workspace->id, $domain->id]));
                const csrf = document.querySelector('meta[name="csrf-token"]').content;
                let editingId = null;

                const field = (name) => form.elements.namedItem(name);
                const setMessage = (text, failed = false) => {
                    message.textContent = text;
                    message.classList.toggle('text-danger', failed);
                    message.classList.toggle('text-success', !failed && Boolean(text));
                };
                const addCell = (row, text) => {
                    const cell = document.createElement('td');
                    cell.textContent = text;
                    row.append(cell);
                };
                const renderRecords = () => {
                    list.replaceChildren();
                    records.forEach((record) => {
                        const row = document.createElement('tr');
                        addCell(row, record.name ?? '');
                        addCell(row, record.type ?? '');
                        addCell(row, record.content ?? JSON.stringify(record.data ?? {}));
                        addCell(row, Number(record.ttl) === 1 ? 'Auto' : String(record.ttl ?? ''));
                        addCell(row, record.proxied ? 'Yes' : 'No');
                        addCell(row, record.priority == null ? '' : String(record.priority));
                        const actions = document.createElement('td');
                        actions.className = 'text-right text-nowrap';
                        const edit = document.createElement('button');
                        edit.type = 'button';
                        edit.className = 'btn btn-sm btn-secondary mr-1';
                        edit.textContent = 'Edit';
                        edit.setAttribute('aria-label', `Edit ${record.name} ${record.type} record`);
                        edit.addEventListener('click', () => openEditor(record));
                        const remove = document.createElement('button');
                        remove.type = 'button';
                        remove.className = 'btn btn-sm btn-danger';
                        remove.textContent = 'Delete';
                        remove.setAttribute('aria-label', `Delete ${record.name} ${record.type} record`);
                        remove.addEventListener('click', () => deleteRecord(record));
                        actions.append(edit, remove);
                        row.append(actions);
                        list.append(row);
                    });
                    if (records.length === 0) {
                        const row = document.createElement('tr');
                        const cell = document.createElement('td');
                        cell.colSpan = 7;
                        cell.className = 'text-muted text-center';
                        cell.textContent = 'No DNS records were returned for this zone.';
                        row.append(cell);
                        list.append(row);
                    }
                };
                const syncTypeFields = () => {
                    const type = field('type').value;
                    document.getElementById('record-priority-group').hidden = !['MX', 'URI'].includes(type);
                    document.getElementById('record-proxied-group').hidden = !['A', 'AAAA', 'CNAME'].includes(type);
                    field('priority').required = ['MX', 'URI'].includes(type);
                };
                const openEditor = (record = null) => {
                    editingId = record?.id ?? null;
                    form.reset();
                    field('type').value = record?.type ?? 'A';
                    field('name').value = record?.name ?? '@';
                    field('content').value = record?.content ?? '';
                    field('ttl').value = record?.ttl ?? 1;
                    field('priority').value = record?.priority ?? '';
                    field('proxied').value = record?.proxied ? 'true' : 'false';
                    field('comment').value = record?.comment ?? '';
                    field('data_json').value = record?.data ? JSON.stringify(record.data, null, 2) : '';
                    document.getElementById('cloudflare-record-title').textContent = record ? 'Edit DNS record' : 'Add DNS record';
                    document.getElementById('save-cloudflare-record').textContent = record ? 'Update record' : 'Save record';
                    error.textContent = '';
                    syncTypeFields();
                    dialog.showModal();
                };
                const request = async (url, method, payload = undefined) => {
                    const options = {method, headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}};
                    if (payload !== undefined) {
                        options.headers['Content-Type'] = 'application/json';
                        options.body = JSON.stringify(payload);
                    }
                    const response = await fetch(url, options);
                    const body = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        const validation = Object.values(body.errors ?? {}).flat().join(' ');
                        throw new Error(validation || body.message || 'Cloudflare DNS request failed.');
                    }
                    return body;
                };
                const deleteRecord = async (record) => {
                    if (!window.confirm(`Delete ${record.type} record ${record.name}? This change will be sent to Cloudflare.`)) return;
                    setMessage('Deleting record…');
                    try {
                        await request(`${baseUrl}/${encodeURIComponent(record.id)}`, 'DELETE');
                        const index = records.findIndex((item) => item.id === record.id);
                        if (index >= 0) records.splice(index, 1);
                        renderRecords();
                        setMessage('DNS record deleted.');
                    } catch (requestError) {
                        setMessage(requestError.message || 'Delete failed.', true);
                    }
                };

                document.getElementById('add-cloudflare-record').addEventListener('click', () => openEditor());
                document.querySelectorAll('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => dialog.close()));
                field('type').addEventListener('change', syncTypeFields);
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    error.textContent = '';
                    if (!form.reportValidity()) return;
                    const payload = Object.fromEntries(new FormData(form));
                    if (payload.priority === '') delete payload.priority;
                    if (payload.content === '') delete payload.content;
                    if (payload.data_json === '') delete payload.data_json;
                    if (payload.comment === '') delete payload.comment;
                    payload.proxied = payload.proxied === 'true';
                    const button = document.getElementById('save-cloudflare-record');
                    const label = button.textContent;
                    button.disabled = true;
                    button.textContent = 'Saving…';
                    try {
                        await request(editingId ? `${baseUrl}/${encodeURIComponent(editingId)}` : baseUrl, editingId ? 'PATCH' : 'POST', payload);
                        dialog.close();
                        setMessage(editingId ? 'DNS record updated.' : 'DNS record created.');
                        window.setTimeout(() => window.location.reload(), 350);
                    } catch (requestError) {
                        error.textContent = requestError.message || 'Save failed.';
                    } finally {
                        button.disabled = false;
                        button.textContent = label;
                    }
                });
                renderRecords();
            })();
        </script>
    @endpush
@endif
