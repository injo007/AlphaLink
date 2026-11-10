@extends('layouts.admin-shell')

@section('title', 'GSend Accounts')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero" aria-labelledby="gsend-accounts-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true">
                <i class="fab fa-google"></i>
            </span>
            <div class="command-os-report-hero-mark" aria-hidden="true">
                <i class="fab fa-google"></i>
            </div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">CAMPAIGN STUDIO // GOOGLE WORKSPACE DISPATCH</p>
                <h1 id="gsend-accounts-title">GSEND ACCOUNTS</h1>
                <p>Manage connected Google Workspace dispatch accounts, projects, OAuth status, and sending availability.</p>
            </div>
            <div class="command-os-hero-actions">
                <button class="btn-command-primary" type="button" id="workspace-account-add" @disabled($projects->where('is_active', true)->isEmpty())>
                    <i class="fas fa-plus mr-1" aria-hidden="true"></i>ADD NEW ACCOUNTS
                </button>
                <button class="command-os-btn-option" type="button" id="workspace-account-refresh" title="Refresh accounts" aria-label="Refresh accounts">
                    <i class="fas fa-sync-alt" aria-hidden="true"></i>
                </button>
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="fas fa-expand" aria-hidden="true"></i>
                </button>
            </div>
        </header>

        <!-- 2. KPI Overview Cards -->
        <section class="command-os-all-stats-overview" aria-label="Accounts Overview">
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-at"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Total Accounts</span>
                    <strong id="stat-total-accounts">{{ $accounts->count() }}</strong>
                    <small>Connected senders</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-emerald" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Active Senders</span>
                    <strong id="stat-active-accounts">{{ $accounts->where('is_active', true)->count() }}</strong>
                    <small>Ready for dispatch</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-amber" aria-hidden="true"><i class="fas fa-ban"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Disabled</span>
                    <strong id="stat-disabled-accounts">{{ $accounts->where('is_active', false)->count() }}</strong>
                    <small>Paused or offline</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-blue" aria-hidden="true"><i class="fas fa-project-diagram"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Google Projects</span>
                    <strong id="stat-total-projects">{{ $projects->count() }}</strong>
                    <small>Workspace clusters</small>
                </span>
            </article>
        </section>

        <!-- 3. Status Alert -->
        <div id="workspace-account-status" class="alert d-none" role="status" aria-live="polite"></div>

        <!-- 4. Table Card -->
        <div class="command-os-table-card">
            <div class="d-flex flex-wrap align-items-center justify-content-between mb-3" style="gap: 12px;">
                <div class="d-flex align-items-center">
                    <i class="fab fa-google text-purple font-size-lg mr-2"></i>
                    <h2 class="h6 font-w700 text-uppercase tracking-wider mb-0 text-ink">GMAIL ACCOUNTS DIRECTORY</h2>
                    <span class="badge badge-purple ml-2">{{ $accounts->count() }}</span>
                </div>
                <div class="d-flex flex-wrap align-items-center" style="gap: 8px;">
                    @if ($projects->isNotEmpty())
                        <div class="d-inline-flex align-items-center mr-1">
                            <select id="workspace-account-project-filter" class="form-control selectpicker" data-width="auto" title="Filter by Project">
                                <option value="" @selected($selectedProjectId === null)>All Projects ({{ $projects->count() }})</option>
                                @foreach ($projects as $project)
                                    <option value="{{ $project->id }}" @selected((string) $selectedProjectId === (string) $project->id)>
                                        {{ $project->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <button class="btn-command-action" id="workspace-accounts-disable" type="button">
                        <i class="fas fa-minus-circle mr-1" aria-hidden="true"></i>DISABLE SELECTED
                    </button>
                    <button class="btn-command-danger" id="workspace-accounts-delete" type="button">
                        <i class="fas fa-trash-alt mr-1" aria-hidden="true"></i>DELETE SELECTED
                    </button>
                    <button class="btn-command-action" id="workspace-accounts-search" type="button">
                        <i class="fas fa-search mr-1" aria-hidden="true"></i>BULK SEARCH
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table id="workspace-account-table" class="table table-sm table-hover table-striped">
                    <thead>
                        <tr>
                            <th style="width: 40px;">
                                <label class="command-table-checkbox-label">
                                    <input type="checkbox" id="workspace-accounts-all" aria-label="Select all accounts on this page">
                                    <span class="command-checkbox-custom-box"></span>
                                </label>
                            </th>
                            <th style="width: 60px;">#</th>
                            <th>PROJECT</th>
                            <th>EMAIL</th>
                            <th style="width: 90px;">IS AUTH</th>
                            <th style="width: 100px;">IS ACTIVE</th>
                            <th style="width: 160px;">CREATED AT</th>
                            <th class="text-right" style="width: 130px;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($accounts as $account)
                            <tr>
                                <td>
                                    <label class="command-table-checkbox-label">
                                        <input type="checkbox" value="{{ $account->id }}" class="workspace-account-select" aria-label="Select account {{ $account->email }}">
                                        <span class="command-checkbox-custom-box"></span>
                                    </label>
                                </td>
                                <td><span class="font-w700 text-muted">#{{ $account->id }}</span></td>
                                <td>
                                    @if ($account->project)
                                        <a href="{{ route('console.gcampaigns.accounts', [$workspace->id, 'project' => $account->project->id]) }}" class="badge badge-purple-subtle" title="Filter by this project">
                                            <i class="fas fa-folder mr-1 text-purple"></i>{{ $account->project->name }}
                                        </a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <i class="fab fa-google mr-2 text-purple font-size-sm"></i>
                                        <span class="font-w600 text-ink font-monospace">{{ $account->email }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-light border text-muted" title="Per-account OAuth tokens are not configured in AlphaLink">NO</span>
                                </td>
                                <td>
                                    @if ($account->is_active)
                                        <span class="badge badge-success-subtle text-success font-w700"><i class="fas fa-check-circle mr-1"></i>ACTIVE</span>
                                    @else
                                        <span class="badge badge-danger-subtle text-danger font-w700"><i class="fas fa-times-circle mr-1"></i>DISABLED</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="text-muted small"><i class="far fa-clock mr-1"></i>{{ $account->created_at?->format('Y-m-d H:i') ?? '—' }}</span>
                                </td>
                                <td class="text-right text-nowrap">
                                    <div class="command-action-btn-group justify-content-end">
                                        <button class="command-action-btn action-primary" type="button" disabled title="Per-account OAuth authentication is not configured in AlphaLink" style="width: auto; padding: 0 10px; font-weight: 700; font-size: 0.6875rem;">AUTH</button>
                                        @if ($account->is_active)
                                            <button class="command-action-btn action-warning" type="button" data-account-id="{{ $account->id }}" data-account-status="0" title="DISABLE ACCOUNT" aria-label="Disable {{ $account->email }}">
                                                <i class="fas fa-pause" aria-hidden="true"></i>
                                            </button>
                                        @else
                                            <button class="command-action-btn action-success" type="button" data-account-id="{{ $account->id }}" data-account-status="1" title="ENABLE ACCOUNT" aria-label="Enable {{ $account->email }}">
                                                <i class="fas fa-play" aria-hidden="true"></i>
                                            </button>
                                        @endif
                                        <button class="command-action-btn action-danger" type="button" data-account-delete="{{ $account->id }}" title="DELETE ACCOUNT" aria-label="Delete {{ $account->email }}">
                                            <i class="fas fa-trash-alt" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="fab fa-google font-size-h3 d-block mb-2 text-muted"></i>
                                    No synced Workspace accounts found. Click <strong>ADD NEW ACCOUNTS</strong> to connect dispatch accounts.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const base = @json(url('/app/workspaces/'.$workspace->id.'/tools/workspace'));
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const status = document.getElementById('workspace-account-status');
            const add = document.getElementById('workspace-account-add');
            const request = async (url, method = 'POST', body = {}) => {
                const response = await fetch(url, {method, headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf}, body: method === 'GET' ? undefined : JSON.stringify(body)});
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(payload.message || payload.error || Object.values(payload.errors || {}).flat().join(' ') || 'Request failed.');
                return payload;
            };
            const showStatus = (message, ok = true) => { status.textContent = message; status.className = `alert ${ok ? 'alert-success' : 'alert-danger'}`; };
            const busy = (button, value) => { button.disabled = value; button.setAttribute('aria-busy', String(value)); };
            const dialog = async (options) => {
                const modal = window.Swal || window.swal;
                if (modal?.fire) return modal.fire(options);
                if (options.input) {
                    const value = window.prompt(options.inputPlaceholder || options.inputLabel || 'Enter a value');
                    return {isConfirmed:value !== null,isDismissed:value === null,value};
                }
                return {isConfirmed:window.confirm(options.text || options.title || 'Continue?')};
            };

            let frameModal = null;
            const openFrame = (url) => {
                if (!window.bootbox?.dialog) return window.location.assign(url);
                frameModal = window.bootbox.dialog({scrollable:true,closeButton:true,size:'lg',message:`<iframe src="${url}" width="100%" scrolling="no" frameborder="0"></iframe>`,onEscape:true,centerVertical:true})
                    .on('shown.bs.modal', function () { if (window.iFrameResize) window.iFrameResize({}, 'iframe'); })
                    .on('hidden.bs.modal', function () { window.jQuery?.(this).find('iframe').remove(); });
            };
            window.closeModal = function () { frameModal?.modal('hide'); window.location.reload(); };

            document.getElementById('workspace-account-refresh').addEventListener('click', () => window.location.reload());
            add.addEventListener('click', () => {
                const url = new URL(`${base}/accounts/create`, window.location.origin);
                @if ($selectedProjectId !== null)
                    url.searchParams.set('project', @json((string) $selectedProjectId));
                @endif
                openFrame(url.toString());
            });

            document.getElementById('workspace-accounts-all').addEventListener('change', (event) => {
                document.querySelectorAll('#workspace-account-table tbody .workspace-account-select').forEach((checkbox) => { checkbox.checked = event.target.checked; });
            });

            const updateAccounts = async (mode, button) => {
                const accounts = [...document.querySelectorAll('#workspace-account-table tbody .workspace-account-select:checked')].map((checkbox) => Number(checkbox.value));
                if (!accounts.length) return showStatus('Select at least one account.', false);
                const decision = await dialog({icon:'question',title:'Are you sure?',text:mode === 'delete' ? 'Delete the selected Workspace accounts?' : `${mode.toUpperCase()} the selected Workspace accounts?`,showCancelButton:true,confirmButtonText:`${mode.toUpperCase()} ACCOUNTS`});
                if (mode === 'delete') {
                    if (!decision.isConfirmed && !decision.value) return;
                } else if (!decision.isConfirmed && !decision.value) {
                    return;
                }
                busy(button, true);
                try {
                    const result = await request(`${base}/accounts${mode === 'delete' ? '' : '/status'}`, mode === 'delete' ? 'DELETE' : 'PATCH', mode === 'delete' ? {ids:accounts} : {accounts,status:0});
                    showStatus(result.data || result.message || 'Accounts updated.');
                    window.location.reload();
                } catch (error) {
                    showStatus(error.message, false);
                    busy(button, false);
                }
            };
            document.getElementById('workspace-accounts-disable').addEventListener('click', (event) => updateAccounts('disable', event.currentTarget));
            document.getElementById('workspace-accounts-delete').addEventListener('click', (event) => updateAccounts('delete', event.currentTarget));

            const table = window.jQuery?.fn?.DataTable
                ? window.jQuery('#workspace-account-table').DataTable({
                    pageLength: 50,
                    order: [[1, 'desc']],
                    language: {
                        search: "_INPUT_",
                        searchPlaceholder: "Search accounts...",
                        lengthMenu: "Show _MENU_ rows"
                    }
                })
                : null;

            // Project filter dropdown navigation
            const projectFilter = document.getElementById('workspace-account-project-filter');
            if (projectFilter) {
                projectFilter.addEventListener('change', () => {
                    const val = projectFilter.value;
                    const url = new URL(window.location.href);
                    if (val) {
                        url.searchParams.set('project', val);
                    } else {
                        url.searchParams.delete('project');
                    }
                    window.location.assign(url.toString());
                });
            }

            document.getElementById('workspace-accounts-search').addEventListener('click', async () => {
                const choice = await dialog({input:'textarea',inputLabel:'Bulk search accounts',inputPlaceholder:'Type or paste account emails here (one per line)...',inputAttributes:{'aria-label':'Type accounts here'},showCancelButton:true});
                if (choice.isDismissed || !choice.isConfirmed) {
                    if (table) table.columns().search('').draw();
                    return;
                }
                const accounts = [...new Set(String(choice.value || '').split(/[\r\n,;]+/).map((value) => value.trim()).filter((value) => value))];
                if (table && accounts.length) table.column(3).search(accounts.join('|'), true, false).draw();
                else if (table) table.columns().search('').draw();
            });

            document.addEventListener('click', async (event) => {
                const button = event.target.closest('[data-account-id][data-account-status], [data-account-delete]');
                if (!button) return;
                const accountId = Number(button.dataset.accountId || button.dataset.accountDelete);
                const isDelete = button.hasAttribute('data-account-delete');
                if (isDelete) {
                    const decision = await dialog({icon:'question',title:'Are you sure?',showCancelButton:true,confirmButtonText:'DELETE'});
                    if (!decision.isConfirmed && !decision.value) return;
                }
                busy(button, true);
                try {
                    const result = isDelete
                        ? await request(`${base}/accounts`, 'DELETE', {ids:[accountId]})
                        : await request(`${base}/accounts/status?id=${encodeURIComponent(accountId)}`, 'PATCH', {accounts:[accountId],status:Number(button.dataset.accountStatus)});
                    showStatus(result.data || result.message || 'Account updated.');
                    window.location.reload();
                } catch (error) {
                    showStatus(error.message, false);
                    busy(button, false);
                }
            });
        })();
    </script>
@endpush
