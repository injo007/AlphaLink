@extends('layouts.admin-shell')

@section('title', $title.' Cloud')

@push('styles')
<style>
    .command-cloud-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--command-os-border, rgba(99, 102, 241, 0.12));
    }
</style>
@endpush

@section('content')
<div class="command-os-report-page">
    <!-- 1. Hero Header -->
    <header class="command-os-report-hero mb-4">
        <div class="command-os-report-hero-left">
            <div class="command-os-report-hero-icon" aria-hidden="true">
                <i class="fas fa-cloud"></i>
            </div>
            <div>
                <div class="command-os-report-hero-eyebrow">CLOUD PROVISIONING // {{ strtoupper($type) }} FLEET</div>
                <h1 class="command-os-report-hero-title">CLOUDS {{ strtoupper($title) }}</h1>
                <p class="command-os-report-hero-desc">Deploy virtual instances, automate IP telemetry audit, set reverse DNS PTR records, and register servers with AlphaLink.</p>
            </div>
        </div>
        <div class="command-os-report-hero-right">
            <div class="command-os-report-hero-meta">
                <span class="command-os-report-badge">CLOUD ENGINE</span>
            </div>
        </div>
    </header>

    <!-- 2. Provisioning Wizard Card -->
    <div class="command-os-table-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
            <h3 style="font-size: 0.9375rem; font-weight: 700; color: var(--command-os-text, #1e293b); margin: 0; text-transform: uppercase; letter-spacing: 0.04em;">
                <i class="fas fa-server mr-1 text-purple"></i> Provision {{ $title }} Instances
            </h3>
        </div>

        <div id="cloud-status" class="alert alert-info mb-3" role="status" aria-live="polite" style="border-radius: 12px; font-size: 0.8125rem;">
            <i class="fas fa-info-circle mr-1"></i> Load provider options with GET DATA, then load existing resources with GET INSTANCES.
        </div>

        <form id="cloud-create-form" novalidate>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label class="command-form-label" for="cloud-account">Account Provider:</label>
                        <div class="d-flex align-items-center" style="gap: 10px;">
                            <div class="command-select-wrapper flex-grow-1">
                                <select id="cloud-account" class="command-form-control command-select w-100" aria-describedby="cloud-account-help">
                                    @forelse ($providers as $account)
                                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                                    @empty
                                        <option value="">No eligible provider accounts</option>
                                    @endforelse
                                </select>
                            </div>
                            <button id="cloud-load-catalog" class="btn-command-primary text-nowrap" type="button" @disabled(!$hasProviders) style="height: 40px; padding: 0 18px;">
                                <i class="fas fa-cloud-download-alt mr-1"></i> GET DATA
                            </button>
                        </div>
                        @if ($hasProviders)
                            <small id="cloud-account-help" class="form-text text-muted" style="font-size: 0.75rem;">Only accounts assigned to your workspace role are listed.</small>
                        @else
                            <small id="cloud-account-help" class="form-text text-warning" style="font-size: 0.75rem;">
                                No eligible {{ $title }} account is configured for this workspace.
                                @if ($canManageCloudProviders)
                                    <a href="{{ $cloudProviderManagementUrl }}" style="font-weight: 600; color: #8b5cf6;">Add a cloud provider account</a> before testing this workflow.
                                @else
                                    Ask a workspace administrator to add a {{ $title }} account.
                                @endif
                            </small>
                        @endif
                    </div>
                    <div class="form-group mb-3">
                        <label class="command-form-label" for="cloud-region">Instance Region:</label>
                        <div class="command-select-wrapper">
                            <select id="cloud-region" name="region" class="command-form-control command-select w-100" required>
                                <option value="">Load account data first</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <label class="command-form-label" for="cloud-image">Instance OS Image:</label>
                        <div class="command-select-wrapper">
                            <select id="cloud-image" name="image" class="command-form-control command-select w-100" required>
                                <option value="">Load account data first</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label class="command-form-label" for="cloud-type">Instance Size / Type:</label>
                        <div class="command-select-wrapper">
                            <select id="cloud-type" name="type" class="command-form-control command-select w-100" required>
                                <option value="">Load account data first</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <label class="command-form-label" for="cloud-name">{{ $type === 'digitalocean' ? 'Hostname Prefix:' : 'Instance Name:' }}</label>
                        <input id="cloud-name" name="name" class="command-form-control w-100" autocomplete="off" placeholder="node-">
                    </div>
                    <div class="form-group mb-3">
                        <label class="command-form-label" for="cloud-count">Number of Instances to Create:</label>
                        <input id="cloud-count" name="count" type="number" step="1" class="command-form-control w-100" value="1" required>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap align-items-center justify-content-between pt-3 mt-3 border-top" style="gap: 12px;">
                <button id="cloud-load-instances" class="btn-command-action" type="button" @disabled(!$hasProviders)>
                    <i class="fas fa-sync mr-1"></i> GET INSTANCES
                </button>
                <button id="cloud-create" class="btn-command-primary" type="submit" @disabled(!$hasProviders)>
                    <i class="fas fa-plus-circle mr-1"></i> CREATE INSTANCES
                </button>
            </div>
        </form>

        <div class="mt-3 pt-2">
            @if ($type === 'linode')
                <p class="small text-muted mb-0" style="font-size: 0.75rem;"><i class="fas fa-info-circle mr-1"></i> The provider password comes from Settings → Cloud Server Password. Set it before creating or adding instances to Servers.</p>
            @else
                <p class="small text-muted mb-0" style="font-size: 0.75rem;"><i class="fas fa-info-circle mr-1"></i> AlphaLink keeps a workspace SSH key encrypted and registers its public key with this provider when creating instances. Add to Servers uses the matching private key.</p>
            @endif
        </div>
    </div>

    <!-- 3. Deployed Instances Card -->
    <div class="command-os-table-card">
        <div class="command-cloud-toolbar">
            <div class="command-cloud-toolbar-left">
                <span class="badge-command-pill" style="font-weight: 700; color: #8b5cf6; padding: 6px 14px;">
                    <i class="fas fa-server mr-1"></i> <span id="cloud-instance-count">0</span> INSTANCES
                </span>
                <div class="command-btn-group ml-1">
                    <button id="cloud-select-none" class="btn-command-action" type="button" title="SELECT NONE" style="padding: 6px 12px; font-size: 0.75rem;">
                        <i class="far fa-square mr-1"></i> SELECT NONE
                    </button>
                    <button id="cloud-select-all-visible" class="btn-command-action" type="button" title="SELECT ALL" style="padding: 6px 12px; font-size: 0.75rem;">
                        <i class="far fa-check-square mr-1"></i> SELECT ALL
                    </button>
                </div>
                <div class="command-btn-group ml-2">
                    <button id="cloud-delete-selected" class="btn-command-danger" type="button" disabled style="padding: 6px 14px; font-size: 0.75rem;">
                        <i class="fas fa-trash-alt mr-1"></i> DELETE INSTANCES
                    </button>
                    <button id="cloud-add-selected" class="btn-command-primary" type="button" disabled style="padding: 6px 14px; font-size: 0.75rem;">
                        <i class="fas fa-database mr-1"></i> ADD IPS TO DB
                    </button>
                </div>
            </div>
            <div class="command-cloud-toolbar-right">
                <a class="btn-command-action" href="{{ route('console.infrastructure.bulk-install', $workspace->id) }}" target="_blank" rel="noopener" style="padding: 6px 14px; font-size: 0.75rem;">
                    <i class="fas fa-external-link-alt mr-1"></i> BULK INSTALL SERVERS
                </a>
            </div>
        </div>
        <div class="p-2 px-3 d-flex align-items-center" style="border-bottom: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.1)); background: rgba(139, 92, 246, 0.02);">
            <p class="small text-muted mb-0" style="font-size: 0.75rem;">
                <i class="fas fa-shield-alt mr-1 text-purple"></i> Telemetry audits for Blacklists (Spamhaus, SpamCop, Barracuda) and Sender Score trigger automatically upon instance discovery.
            </p>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover table-striped command-os-table mb-0" aria-label="Cloud instances">
                <thead>
                    <tr>
                        <th scope="col" style="width: 44px; text-align: center;">
                            <label class="command-table-checkbox-label mb-0" title="Select all instances">
                                <input type="checkbox" id="cloud-header-select-all" aria-label="Select all instances">
                                <span class="command-checkbox-custom-box"></span>
                            </label>
                        </th>
                        <th scope="col" style="width: 70px;">ID</th>
                        @if ($type === 'linode')
                            <th scope="col">LABEL</th>
                            <th scope="col">PLAN / TYPE</th>
                            <th scope="col">DATACENTER</th>
                        @else
                            <th scope="col">NAME</th>
                            <th scope="col">LOCATION</th>
                        @endif
                        <th scope="col">STATUS</th>
                        <th scope="col">IPV4 ADDRESS</th>
                        <th scope="col">BLACKLIST AUDIT</th>
                        <th scope="col">SENDER SCORE</th>
                        <th scope="col">PROVISIONED</th>
                        @if ($canSetRdns)<th scope="col" style="text-align: right; width: 90px;">ACTIONS</th>@endif
                    </tr>
                </thead>
                <tbody id="cloud-instance-rows">
                    <tr>
                        <td colspan="{{ ($type === 'linode' ? 10 : 9) + ($canSetRdns ? 1 : 0) }}" class="text-center py-5">
                            <div class="command-table-empty-state">
                                <div class="command-empty-state-icon mb-2" style="font-size: 2rem; color: #8b5cf6; opacity: 0.8;">
                                    <i class="fas fa-server"></i>
                                </div>
                                <h5 class="font-w600 mb-1 text-ink" style="font-size: 0.9375rem;">No Cloud Instances Loaded</h5>
                                <p class="text-muted mb-0" style="font-size: 0.8125rem;">Select an account provider and click <strong>GET DATA</strong> then <strong>GET INSTANCES</strong> to discover live nodes.</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Confirm Dialog -->
    <div class="modal fade" id="cloud-confirm-dialog" tabindex="-1" role="dialog" aria-labelledby="cloud-confirm-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content command-modal-surface p-4">
                <div class="command-modal-hero mb-3">
                    <div class="command-modal-hero-left">
                        <div class="command-modal-hero-icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <div>
                            <h3 class="command-modal-hero-title" id="cloud-confirm-title">Confirm Cloud Action</h3>
                            <div class="command-modal-hero-eyebrow">CONFIRMATION REQUIRED</div>
                        </div>
                    </div>
                </div>
                <div class="modal-body p-0 mb-3" id="cloud-confirm-message" style="font-size: 0.875rem;"></div>
                <div class="command-modal-actions">
                    <button type="button" class="btn-command-action" data-dismiss="modal">CANCEL</button>
                    <button type="button" class="btn-command-danger" id="cloud-confirm-action">CONTINUE</button>
                </div>
            </div>
        </div>
    </div>

    @if ($canSetRdns)
        <!-- rDNS Dialog -->
        <div class="modal fade" id="cloud-rdns-dialog" tabindex="-1" role="dialog" aria-labelledby="cloud-rdns-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <form class="modal-content command-modal-surface p-4" id="cloud-rdns-form">
                    <div class="command-modal-hero mb-3">
                        <div class="command-modal-hero-left">
                            <div class="command-modal-hero-icon"><i class="fas fa-network-wired"></i></div>
                            <div>
                                <h3 class="command-modal-hero-title" id="cloud-rdns-title">Set Reverse DNS PTR</h3>
                                <div class="command-modal-hero-eyebrow">rDNS RECORD CONFIGURATION</div>
                            </div>
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <label class="command-form-label" for="cloud-rdns-value">rDNS Hostname:</label>
                        <input id="cloud-rdns-value" class="form-control" autocomplete="off" placeholder="mail.domain.com" required>
                    </div>
                    <div class="command-modal-actions">
                        <button type="button" class="btn-command-action" data-dismiss="modal">CANCEL</button>
                        <button type="submit" class="btn-command-primary" id="cloud-rdns-save"><i class="fas fa-check mr-1"></i> SET RDNS</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
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
                status.className = `alert alert-${tone} mb-3`;
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

            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text ?? '';
                return div.innerHTML;
            }

            function instanceCheckbox(instance, index) {
                const label = document.createElement('label');
                label.className = 'command-table-checkbox-label mb-0';
                label.title = `Select ${instance.name || instance.id}`;
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.className = 'cloud-instance-checkbox';
                input.dataset.index = String(index);
                input.setAttribute('aria-label', `Select instance ${instance.name || instance.id}`);
                input.addEventListener('change', (e) => {
                    e.stopPropagation();
                    input.closest('tr')?.classList.toggle('selected', input.checked);
                    updateSelectionButtons();
                });
                const box = document.createElement('span');
                box.className = 'command-checkbox-custom-box';
                label.append(input, box);
                return label;
            }

            function renderInstances(items) {
                instances = items || [];
                rows.replaceChildren();
                count.textContent = String(instances.length);
                const totalColumns = {{ ($type === 'linode' ? 10 : 9) + ($canSetRdns ? 1 : 0) }};
                if (!instances.length) {
                    const row = rows.insertRow();
                    const cell = row.insertCell();
                    cell.colSpan = totalColumns;
                    cell.className = 'text-center py-5';
                    cell.innerHTML = `
                        <div class="command-table-empty-state">
                            <div class="command-empty-state-icon mb-2" style="font-size: 2rem; color: #8b5cf6; opacity: 0.8;">
                                <i class="fas fa-server"></i>
                            </div>
                            <h5 class="font-w600 mb-1 text-ink" style="font-size: 0.9375rem;">No Instances Found</h5>
                            <p class="text-muted mb-0" style="font-size: 0.8125rem;">No active cloud instances are currently deployed in this provider account.</p>
                        </div>
                    `;
                    updateSelectionButtons();
                    return;
                }

                instances.forEach((instance, index) => {
                    const row = rows.insertRow();
                    row.className = 'command-instance-row';
                    row.style.cursor = 'pointer';
                    const checkCell = row.insertCell();
                    checkCell.style.textAlign = 'center';
                    checkCell.append(instanceCheckbox(instance, index));

                    const fields = type === 'linode'
                        ? ['id', 'name', 'type', 'region', 'status', 'ip']
                        : ['id', 'name', 'region', 'status', 'ip'];
                    fields.forEach((key) => {
                        const cell = row.insertCell();
                        if (key === 'id') {
                            cell.innerHTML = `<span class="command-mono-badge font-weight-bold">#${escapeHtml(instance[key])}</span>`;
                        } else if (key === 'name') {
                            cell.innerHTML = `<span class="font-w700 text-ink">${escapeHtml(instance[key])}</span>`;
                        } else if (key === 'status') {
                            const isRunning = String(instance[key] || '').toLowerCase() === 'running' || String(instance[key] || '').toLowerCase() === 'active';
                            cell.innerHTML = `<span class="badge-command-pill ${isRunning ? 'badge-command-active-yes' : ''}"><span class="badge-dot" style="display:inline-block;width:6px;height:6px;border-radius:50%;background:${isRunning ? '#10b981' : '#94a3b8'};margin-right:5px;"></span>${escapeHtml(instance[key] || '—')}</span>`;
                        } else if (key === 'ip') {
                            cell.innerHTML = `<span class="font-mono" style="font-weight: 700; color: #6366f1;">${escapeHtml(instance[key] || '—')}</span>`;
                        } else {
                            cell.innerHTML = `<span class="text-muted" style="font-size: 0.8125rem;">${escapeHtml(String(instance[key] || '—'))}</span>`;
                        }
                    });

                    const reputationCell = row.insertCell();
                    const scoreCell = row.insertCell();
                    if (instance.ip && instance.ip.includes('.')) {
                        [reputationCell, scoreCell].forEach((cell) => {
                            cell.innerHTML = `<span class="spinner-border spinner-border-sm text-purple" role="status" aria-label="Auditing IP telemetry"></span>`;
                        });
                    } else {
                        reputationCell.innerHTML = `<span class="badge-command-pill" style="opacity: 0.6;">NA</span>`;
                        scoreCell.innerHTML = `<span class="badge-command-pill" style="opacity: 0.6;">NA</span>`;
                    }

                    const createdCell = row.insertCell();
                    createdCell.innerHTML = `<span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b); white-space: nowrap;">${escapeHtml(instance.created_at || '—')}</span>`;

                    @if ($canSetRdns)
                        const actionsCell = row.insertCell();
                        actionsCell.style.textAlign = 'right';
                        if (instance.ip) {
                            const rdnsButton = document.createElement('button');
                            rdnsButton.type = 'button';
                            rdnsButton.className = 'btn-command-action';
                            rdnsButton.style.padding = '4px 10px';
                            rdnsButton.style.fontSize = '0.75rem';
                            rdnsButton.innerHTML = '<i class="fas fa-network-wired mr-1"></i> RDNS';
                            rdnsButton.title = 'Set Reverse DNS PTR';
                            rdnsButton.addEventListener('click', (e) => {
                                e.stopPropagation();
                                openRdnsDialog(instance, rdnsButton);
                            });
                            actionsCell.append(rdnsButton);
                        } else {
                            actionsCell.textContent = '—';
                        }
                    @endif
                });
                updateSelectionButtons();
            }

            rows.addEventListener('click', (e) => {
                if (e.target.closest('button, a, input, label')) return;
                const row = e.target.closest('tr');
                if (!row) return;
                const cb = row.querySelector('.cloud-instance-checkbox');
                if (cb) {
                    cb.checked = !cb.checked;
                    row.classList.toggle('selected', cb.checked);
                    updateSelectionButtons();
                }
            });

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
                const allCheckboxes = [...document.querySelectorAll('.cloud-instance-checkbox')];
                const headerCb = document.querySelector('#cloud-header-select-all');
                if (headerCb && allCheckboxes.length > 0) {
                    headerCb.checked = allCheckboxes.every((cb) => cb.checked);
                    headerCb.indeterminate = !headerCb.checked && allCheckboxes.some((cb) => cb.checked);
                }
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
                        const badge = document.createElement('span');
                        const isListed = Boolean(result[key]);
                        badge.className = `badge badge-pill mr-1 ${isListed ? 'badge-danger' : 'badge-success'}`;
                        badge.style.fontSize = '9px';
                        badge.style.fontWeight = '700';
                        badge.style.letterSpacing = '0.04em';
                        badge.style.padding = '3px 6px';
                        badge.textContent = name;
                        badge.title = `${name}: ${isListed ? 'LISTED' : 'CLEAN'}`;
                        reputationCell.append(badge);
                    }
                    scoreCell.innerHTML = result.score
                        ? `<span class="badge-command-pill font-mono" style="font-weight: 800; color: #10b981; border-color: rgba(16, 185, 129, 0.4);"><i class="fas fa-check-circle mr-1"></i>${result.score}/100</span>`
                        : '<span class="badge-command-pill" style="opacity: 0.6;">NA</span>';
                } catch (error) {
                    if (!reputationCell.isConnected || !scoreCell.isConnected) { return; }
                    reputationCell.innerHTML = '<span class="badge badge-warning" style="font-size: 10px;">FAILED</span>';
                    scoreCell.innerHTML = '<span class="badge badge-warning" style="font-size: 10px;">FAILED</span>';
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

            const headerSelectAll = document.querySelector('#cloud-header-select-all');
            headerSelectAll?.addEventListener('change', () => {
                const checked = headerSelectAll.checked;
                document.querySelectorAll('.cloud-instance-checkbox').forEach((input) => {
                    input.checked = checked;
                    input.closest('tr')?.classList.toggle('selected', checked);
                });
                updateSelectionButtons();
            });

            selectNoneButton.addEventListener('click', () => {
                document.querySelectorAll('.cloud-instance-checkbox').forEach((input) => {
                    input.checked = false;
                    input.closest('tr')?.classList.remove('selected');
                });
                if (headerSelectAll) { headerSelectAll.checked = false; headerSelectAll.indeterminate = false; }
                updateSelectionButtons();
            });
            selectAllButton.addEventListener('click', () => {
                document.querySelectorAll('.cloud-instance-checkbox').forEach((input) => {
                    input.checked = true;
                    input.closest('tr')?.classList.add('selected');
                });
                if (headerSelectAll) { headerSelectAll.checked = true; headerSelectAll.indeterminate = false; }
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
