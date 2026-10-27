@extends('layouts.dms-admin')

@section('title', $title.' Cloud')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">{{ $title }}</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content">
            <div id="cloud-status" class="alert alert-info" role="status" aria-live="polite">Load provider options with GET DATA, then load existing resources with GET INSTANCES.</div>
            <form id="cloud-create-form" class="border-top pt-3" novalidate>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="cloud-account">Account</label>
                            <div class="input-group">
                                <select id="cloud-account" class="form-control" aria-describedby="cloud-account-help">
                                    @forelse ($providers as $account)
                                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                                    @empty
                                        <option value="">No eligible provider accounts</option>
                                    @endforelse
                                </select>
                                <div class="input-group-append"><button id="cloud-load-catalog" class="btn btn-primary" type="button" @disabled(!$hasProviders)>GET DATA</button></div>
                            </div>
                            @if ($hasProviders)
                                <small id="cloud-account-help" class="form-text text-muted">Only accounts assigned to your workspace role are listed.</small>
                            @else
                                <small id="cloud-account-help" class="form-text text-warning">
                                    No eligible {{ $title }} account is configured for this workspace.
                                    @if ($canManageCloudProviders)
                                        <a href="{{ $cloudProviderManagementUrl }}">Add a cloud provider account</a> before testing this workflow.
                                    @else
                                        Ask a workspace administrator to add a {{ $title }} account.
                                    @endif
                                </small>
                            @endif
                        </div>
                        <div class="form-group">
                            <label for="cloud-region">Instance Region</label>
                            <select id="cloud-region" name="region" class="form-control" required><option value="">Load account data first</option></select>
                        </div>
                        <div class="form-group">
                            <label for="cloud-image">Instance Images</label>
                            <select id="cloud-image" name="image" class="form-control" required><option value="">Load account data first</option></select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="cloud-type">Instance Type</label>
                            <select id="cloud-type" name="type" class="form-control" required><option value="">Load account data first</option></select>
                        </div>
                        <div class="form-group">
                            <label for="cloud-name">{{ $type === 'digitalocean' ? 'Hostname' : 'Instance Name' }}</label>
                            <input id="cloud-name" name="name" class="form-control" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label for="cloud-count">Create Count</label>
                            <input id="cloud-count" name="count" type="number" step="1" class="form-control" value="1" required>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12 text-md-right">
                        <button id="cloud-load-instances" class="btn btn-info" type="button" @disabled(!$hasProviders)>GET INSTANCES</button>
                        <button id="cloud-create" class="btn btn-success" type="submit" @disabled(!$hasProviders)>CREATE INSTANCES</button>
                    </div>
                </div>
            </form>


            @if ($type === 'linode')
                <p class="small text-muted">The provider password comes from Settings → Cloud Server Password. Set it before creating or adding instances to Servers.</p>
            @else
                <p class="small text-muted">AlphaLink keeps a workspace SSH key encrypted and registers its public key with this provider when creating instances. Add to Servers uses the matching private key.</p>
            @endif

            <div class="d-flex flex-wrap align-items-center border-top pt-3 mb-2">
                <div class="mr-3"><strong id="cloud-instance-count">0</strong> instances</div>
                <button id="cloud-select-none" class="btn btn-sm btn-outline-secondary mr-2" type="button" title="SELECT NONE">SELECT NONE</button>
                <button id="cloud-select-all-visible" class="btn btn-sm btn-outline-secondary mr-2" type="button" title="SELECT ALL">SELECT ALL</button>
                <button id="cloud-delete-selected" class="btn btn-sm btn-danger mr-2" type="button" disabled>DELETE INSTANCES</button>
                <button id="cloud-add-selected" class="btn btn-sm btn-primary mr-2" type="button" disabled>ADD IPS TO DB</button>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('console.infrastructure.bulk-install', $workspace->id) }}" target="_blank" rel="noopener">BULK INSTALL SERVERS</a>
            </div>
            <p class="small text-muted">IP blacklist and Sender Score checks start automatically when GET INSTANCES loads rows, matching DMS.</p>
            <div class="table-responsive">
                <table class="table table-sm table-hover table-striped" aria-label="Cloud instances">
                    <thead>
                        <tr>
                            <th scope="col" aria-label="Select"></th><th scope="col">#</th>
                            @if ($type === 'linode')
                                <th scope="col">LABEL</th><th scope="col">TYPE</th><th scope="col">REGION</th>
                            @else
                                <th scope="col">NAME</th><th scope="col">LOCATION</th>
                            @endif
                            <th scope="col">STATUS</th><th scope="col">IP</th><th scope="col">BLACKLISTED</th><th scope="col">SCORE</th><th scope="col">CREATED AT</th>
                            @if ($canSetRdns)<th scope="col">ACTIONS</th>@endif
                        </tr>
                    </thead>
                    <tbody id="cloud-instance-rows">
                        <tr><td colspan="{{ $type === 'linode' ? 11 : 9 }}" class="text-muted">No instances loaded.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="cloud-confirm-dialog" tabindex="-1" role="dialog" aria-labelledby="cloud-confirm-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="cloud-confirm-title">Confirm cloud action</h5><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
                <div class="modal-body" id="cloud-confirm-message"></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" id="cloud-confirm-action">Continue</button></div>
            </div>
        </div>
    </div>

    @if ($canSetRdns)
        <div class="modal fade" id="cloud-rdns-dialog" tabindex="-1" role="dialog" aria-labelledby="cloud-rdns-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <form class="modal-content" id="cloud-rdns-form">
                    <div class="modal-header"><h5 class="modal-title" id="cloud-rdns-title">Set rDNS</h5><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
                    <div class="modal-body">
                        <label for="cloud-rdns-value">rDNS hostname</label>
                        <input id="cloud-rdns-value" class="form-control" autocomplete="off" required>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" id="cloud-rdns-save">Set rDNS</button></div>
                </form>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        (() => {
            const type = @json($type);
            const routes = @json($actionRoutes);
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const account = document.querySelector('#cloud-account');
            const status = document.querySelector('#cloud-status');
            const rows = document.querySelector('#cloud-instance-rows');
            const count = document.querySelector('#cloud-instance-count');
            const deleteButton = document.querySelector('#cloud-delete-selected');
            const addButton = document.querySelector('#cloud-add-selected');
            const selectNoneButton = document.querySelector('#cloud-select-none');
            const selectAllButton = document.querySelector('#cloud-select-all-visible');
            const confirmDialog = document.querySelector('#cloud-confirm-dialog');
            const rdnsDialog = document.querySelector('#cloud-rdns-dialog');
            let instances = [];
            let providerCatalog = null;
            let confirmAction = null;
            let rdnsSelection = null;
            let instanceLoadSequence = 0;

            function setDialogVisible(visible) {
                if (window.jQuery?.fn?.modal) {
                    window.jQuery(confirmDialog).modal(visible ? 'show' : 'hide');
                    return;
                }

                confirmDialog.style.display = visible ? 'block' : 'none';
                confirmDialog.classList.toggle('show', visible);
                confirmDialog.setAttribute('aria-hidden', visible ? 'false' : 'true');
                document.body.classList.toggle('modal-open', visible);
                if (visible) { document.querySelector('#cloud-confirm-action').focus(); }
            }

            function setRdnsDialogVisible(visible) {
                if (window.jQuery?.fn?.modal) {
                    window.jQuery(rdnsDialog).modal(visible ? 'show' : 'hide');
                    return;
                }

                rdnsDialog.style.display = visible ? 'block' : 'none';
                rdnsDialog.classList.toggle('show', visible);
                rdnsDialog.setAttribute('aria-hidden', visible ? 'false' : 'true');
                document.body.classList.toggle('modal-open', visible);
                if (visible) { document.querySelector('#cloud-rdns-value').focus(); }
            }

            function urlFor(key, providerId = account.value) {
                return routes[key].replace('__provider__', encodeURIComponent(providerId));
            }

            function setStatus(message, tone = 'info') {
                status.className = `alert alert-${tone}`;
                status.textContent = message;
            }

            async function request(key, options = {}, providerId = account.value) {
                const response = await fetch(urlFor(key, providerId), {
                    ...options,
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...(options.headers || {}) },
                });
                const body = await response.json().catch(() => ({}));
                if (!response.ok) { throw new Error(body.message || `Cloud request failed (HTTP ${response.status}).`); }
                return body;
            }

            function fillLinodeCatalog(data) {
                const fill = (selector, items, valueKey, label) => {
                    const select = document.querySelector(selector);
                    select.replaceChildren();
                    (items || []).forEach((item) => select.add(new Option(label(item), String(item?.[valueKey] ?? ''))));
                    if (!select.options.length) { select.add(new Option('Select', '')); }
                };
                fill('#cloud-image', data.images, 'id', (item) => String(item.label || item.id || '').toUpperCase());
                fill('#cloud-region', data.regions, 'id', (item) => `${String(item.country || '').toUpperCase()} -- ${item.id}`);
                fill('#cloud-type', data.types, 'id', (item) => `${item.label || item.id} -- ($${item.price?.monthly ?? '—'}) -- (${item.memory ?? '—'} KB)`);
                const centos = [...document.querySelector('#cloud-image').options].find((option) => option.text.toLowerCase() === 'centos 7');
                if (centos) { document.querySelector('#cloud-image').value = centos.value; }
            }

            function fillDigitalOceanRegion() {
                const region = document.querySelector('#cloud-region').value;
                const available = (items) => (items || []).filter((item) => Array.isArray(item.regions) && item.regions.includes(region));
                const fill = (selector, items, valueKey, label) => {
                    const select = document.querySelector(selector);
                    select.replaceChildren();
                    items.forEach((item) => select.add(new Option(label(item), String(item?.[valueKey] ?? ''))));
                    if (!select.options.length) { select.add(new Option('Select', '')); }
                };
                fill('#cloud-image', available(providerCatalog?.images), 'id', (item) => String(item.description || item.name || item.id || '').toUpperCase());
                fill('#cloud-type', available(providerCatalog?.types), 'slug', (item) => `($${item.price_monthly ?? '—'}) -- (${item.memory ?? '—'} MB) -- (${item.disk ?? '—'} GB)`);
                const centos = available(providerCatalog?.images).find((item) => String(item.slug || '').toLowerCase().includes('centos-7'));
                if (centos) { document.querySelector('#cloud-image').value = String(centos.id); }
            }

            async function loadCatalog() {
                if (!account.value) { setStatus('Select a cloud account first.', 'warning'); return; }
                const button = document.querySelector('#cloud-load-catalog');
                button.disabled = true;
                setStatus('Loading provider options…');
                try {
                    const data = await request('info');
                    providerCatalog = data;
                    if (type === 'linode') {
                        fillLinodeCatalog(data);
                    } else {
                        const regions = (data.regions || []).filter((region) => region.available);
                        const regionSelect = document.querySelector('#cloud-region');
                        regionSelect.replaceChildren();
                        regions.forEach((region) => regionSelect.add(new Option(String(region.name || region.slug).toUpperCase(), String(region.slug))));
                        if (!regions.length) {
                            document.querySelector('#cloud-image').replaceChildren(new Option('Select', ''));
                            document.querySelector('#cloud-type').replaceChildren(new Option('Select', ''));
                        } else {
                            regionSelect.selectedIndex = 0;
                            fillDigitalOceanRegion();
                        }
                    }
                    setStatus('Provider options loaded. Select GET INSTANCES to load existing instances.', 'success');
                } catch (error) {
                    setStatus(error.message, 'danger');
                } finally {
                    button.disabled = false;
                }
            }

            function instanceCheckbox(instance, index) {
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.className = 'cloud-instance-checkbox';
                input.dataset.index = String(index);
                input.setAttribute('aria-label', `Select instance ${instance.name}`);
                input.addEventListener('change', updateSelectionButtons);
                return input;
            }

            function renderInstances(items) {
                instances = items || [];
                rows.replaceChildren();
                count.textContent = String(instances.length);
                if (!instances.length) {
                    const row = rows.insertRow();
                    const cell = row.insertCell();
                    cell.colSpan = {{ $type === 'linode' ? 11 : 9 }};
                    cell.className = 'text-muted';
                    cell.textContent = 'No instances are available in this account.';
                    updateSelectionButtons();
                    return;
                }

                instances.forEach((instance, index) => {
                    const row = rows.insertRow();
                    row.insertCell().append(instanceCheckbox(instance, index));
                    const fields = type === 'linode'
                        ? ['id', 'name', 'type', 'region', 'status', 'ip']
                        : ['id', 'name', 'region', 'status', 'ip'];
                    fields.forEach((key) => {
                        const cell = row.insertCell();
                        cell.textContent = String(instance[key] ?? '—');
                    });
                    const reputationCell = row.insertCell();
                    const scoreCell = row.insertCell();
                    if (instance.ip && instance.ip.includes('.')) {
                        [reputationCell, scoreCell].forEach((cell) => {
                            const spinner = document.createElement('span');
                            spinner.className = 'spinner-border spinner-border-sm';
                            spinner.setAttribute('role', 'status');
                            spinner.setAttribute('aria-label', 'Checking IP reputation');
                            cell.append(spinner);
                        });
                    } else {
                        reputationCell.textContent = 'NA';
                        scoreCell.textContent = 'NA';
                    }
                    const createdCell = row.insertCell();
                    createdCell.textContent = String(instance.created_at ?? '—');
                    @if ($canSetRdns)
                        const actionsCell = row.insertCell();
                        if (instance.ip) {
                            const rdnsButton = document.createElement('button');
                            rdnsButton.type = 'button';
                            rdnsButton.className = 'btn btn-sm btn-link';
                            rdnsButton.textContent = 'RDNS';
                            rdnsButton.title = 'Set rDNS';
                            rdnsButton.addEventListener('click', () => openRdnsDialog(instance, rdnsButton));
                            actionsCell.append(rdnsButton);
                        } else {
                            actionsCell.textContent = '—';
                        }
                    @endif
                });
                updateSelectionButtons();
            }

            async function loadInstances() {
                if (!account.value) { setStatus('Select a cloud account first.', 'warning'); return; }
                const button = document.querySelector('#cloud-load-instances');
                const providerId = account.value;
                const sequence = ++instanceLoadSequence;
                button.disabled = true;
                setStatus('Loading instances…');
                try {
                    const response = await request('instances', {}, providerId);
                    if (sequence !== instanceLoadSequence || account.value !== providerId) { return; }
                    renderInstances(response.data || []);
                    setStatus(`${instances.length} instances loaded.`, 'success');
                    instances.forEach((instance, index) => {
                        if (!instance.ip || !instance.ip.includes('.')) { return; }
                        const row = rows.rows[index];
                        const blacklistColumn = type === 'linode' ? 7 : 6;
                        if (row) { void checkReputation(instance, row.cells[blacklistColumn], row.cells[blacklistColumn + 1], providerId); }
                    });
                } catch (error) {
                    if (sequence !== instanceLoadSequence || account.value !== providerId) { return; }
                    renderInstances([]);
                    setStatus(error.message, 'danger');
                } finally {
                    if (sequence === instanceLoadSequence) { button.disabled = false; }
                }
            }

            function selectedInstances() {
                return [...document.querySelectorAll('.cloud-instance-checkbox:checked')]
                    .map((input) => instances[Number(input.dataset.index)])
                    .filter(Boolean);
            }

            function updateSelectionButtons() {
                const selected = selectedInstances();
                deleteButton.disabled = selected.length === 0;
                addButton.disabled = selected.length === 0 || selected.some((item) => !item.ip);
            }

            function showConfirm(message, actionLabel, action) {
                document.querySelector('#cloud-confirm-message').textContent = message;
                const actionButton = document.querySelector('#cloud-confirm-action');
                actionButton.textContent = actionLabel;
                confirmAction = action;
                setDialogVisible(true);
            }

            async function performSelectedAction(kind) {
                const selected = selectedInstances();
                if (!selected.length) { return; }
                if (kind === 'delete') {
                    showConfirm(`Delete ${selected.length} selected instance(s) from ${@json($title)}? This removes those resources at the cloud provider.`, 'DELETE INSTANCES', async () => {
                        const response = await request('destroy', { method: 'DELETE', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ instances: selected.map(({ id, ip }) => ({ id, ip })) }) });
                        await loadInstances();
                        const refreshFailed = status.classList.contains('alert-danger');
                        const deleteFailed = (response.data || []).some((result) => /\bFailed$/.test(result));
                        const refreshMessage = refreshFailed ? ` Instance list refresh failed: ${status.textContent}` : '';
                        setStatus(`${(response.data || []).join(' · ')}${refreshMessage}`, deleteFailed || refreshFailed ? 'danger' : 'success');
                    });
                } else {
                    showConfirm(`Add ${selected.length} selected instance(s) to the workspace Servers inventory?`, 'ADD SERVERS', async () => {
                        const response = await request('servers', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ instances: selected.map(({ id, ip }) => ({ id, ip })) }) });
                        setStatus(response.data || 'Servers added.', 'success');
                    });
                }
            }

            function openRdnsDialog(instance, button) {
                rdnsSelection = { instance, button };
                document.querySelector('#cloud-rdns-value').value = '';
                document.querySelector('#cloud-rdns-title').textContent = `Set rDNS for ${instance.ip}`;
                setRdnsDialogVisible(true);
            }

            async function updateRdns(event) {
                event.preventDefault();
                if (!rdnsSelection) { return; }
                const rdns = document.querySelector('#cloud-rdns-value').value.trim();
                const { instance, button: rowButton } = rdnsSelection;
                const saveButton = document.querySelector('#cloud-rdns-save');
                if (!rdns) { setStatus('Enter an rDNS hostname.', 'warning'); return; }
                saveButton.disabled = true;
                rowButton.disabled = true;
                try {
                    const response = await request('rdns', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: instance.id, ip: instance.ip, rdns }) });
                    setStatus(response.data, 'success');
                    setRdnsDialogVisible(false);
                    rdnsSelection = null;
                    rowButton.focus();
                } catch (error) {
                    setStatus(error.message, 'danger');
                } finally {
                    saveButton.disabled = false;
                    rowButton.disabled = false;
                }
            }

            async function checkReputation(instance, reputationCell, scoreCell, providerId) {
                try {
                    const result = await request('reputation', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: instance.id, ip: instance.ip }),
                    }, providerId);
                    if (!reputationCell.isConnected || !scoreCell.isConnected) { return; }
                    reputationCell.replaceChildren();
                    for (const [name, key] of [['SPAMCOP', 'spamcop'], ['SPAMHAUS', 'spamhaus'], ['BARRACUDA', 'barracuda']]) {
                        const mark = document.createElement('span');
                        mark.className = result[key] ? 'text-danger' : 'text-success';
                        mark.textContent = '●';
                        mark.title = `${name}: ${result[key] ? 'listed' : 'clear'}`;
                        mark.setAttribute('aria-label', mark.title);
                        reputationCell.append(mark, document.createTextNode(' '));
                    }
                    scoreCell.textContent = result.score || 'NA';
                } catch (error) {
                    if (!reputationCell.isConnected || !scoreCell.isConnected) { return; }
                    reputationCell.textContent = 'FAILED';
                    scoreCell.textContent = 'FAILED';
                }
            }

            document.querySelector('#cloud-load-catalog').addEventListener('click', loadCatalog);
            document.querySelector('#cloud-load-instances').addEventListener('click', loadInstances);
            account.addEventListener('change', () => {
                instanceLoadSequence += 1;
                document.querySelector('#cloud-load-instances').disabled = !account.value;
                renderInstances([]);
                providerCatalog = null;
                ['#cloud-image', '#cloud-region', '#cloud-type'].forEach((selector) => {
                    const input = document.querySelector(selector);
                    if (input) { input.replaceChildren(new Option('Load account data first', '')); }
                });
                setStatus('Account changed. Load its catalog and instances.');
            });
            document.querySelector('#cloud-region').addEventListener('change', () => {
                if (type === 'digitalocean' && providerCatalog) { fillDigitalOceanRegion(); }
            });
            confirmDialog.querySelectorAll('[data-dismiss="modal"]').forEach((button) => {
                button.addEventListener('click', () => {
                    setDialogVisible(false);
                    confirmAction = null;
                });
            });
            rdnsDialog?.querySelectorAll('[data-dismiss="modal"]').forEach((button) => {
                button.addEventListener('click', () => {
                    setRdnsDialogVisible(false);
                    rdnsSelection?.button.focus();
                    rdnsSelection = null;
                });
            });
            document.querySelector('#cloud-rdns-form')?.addEventListener('submit', updateRdns);
            selectNoneButton.addEventListener('click', () => {
                document.querySelectorAll('.cloud-instance-checkbox').forEach((input) => { input.checked = false; });
                updateSelectionButtons();
            });
            selectAllButton.addEventListener('click', () => {
                document.querySelectorAll('.cloud-instance-checkbox').forEach((input) => { input.checked = true; });
                updateSelectionButtons();
            });
            deleteButton.addEventListener('click', () => performSelectedAction('delete'));
            addButton.addEventListener('click', () => performSelectedAction('add'));
            document.querySelector('#cloud-confirm-action')?.addEventListener('click', async (event) => {
                const button = event.currentTarget;
                if (!confirmAction) { return; }
                button.disabled = true;
                try {
                    await confirmAction();
                    setDialogVisible(false);
                } catch (error) {
                    setStatus(error.message, 'danger');
                } finally {
                    button.disabled = false;
                    confirmAction = null;
                }
            });

            document.querySelector('#cloud-create-form')?.addEventListener('submit', async (event) => {
                event.preventDefault();
                const image = document.querySelector('#cloud-image').value;
                const regionInput = document.querySelector('#cloud-region');
                const region = regionInput?.value || '';
                const typeInput = document.querySelector('#cloud-type');
                const instanceType = typeInput?.value || '';
                const countField = document.querySelector('#cloud-count');
                const amount = Number(countField.value);
                if (!account.value || !image || (regionInput && !region) || (typeInput && !instanceType) || countField.value.trim() === '' || !Number.isInteger(amount)) {
                    setStatus(`Select an account, image${regionInput ? ', region' : ''}${typeInput ? ', and type' : ''}, then enter a whole-number count.`, 'warning');
                    return;
                }
                const button = document.querySelector('#cloud-create');
                button.disabled = true;
                setStatus('Creating instances…');
                try {
                    const response = await request('create', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ image, ...(regionInput ? { region } : {}), ...(typeInput ? { type: instanceType } : {}), name: document.querySelector('#cloud-name').value, count: amount }) });
                    await loadInstances();
                    setStatus(response.data || 'Instance creation requested.', 'success');
                } catch (error) {
                    setStatus(error.message, 'danger');
                } finally {
                    button.disabled = false;
                }
            });
        })();
    </script>
@endpush
