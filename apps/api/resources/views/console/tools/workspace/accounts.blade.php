@extends('layouts.dms-admin')

@section('title', 'Gmail Accounts - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">GMAIL ACCOUNTS</h3>
            <div class="block-options">
                <button class="btn btn-sm btn-primary" type="button" id="workspace-account-add" @disabled($projects->where('is_active', true)->isEmpty())><i class="fas fa-plus mr-1" aria-hidden="true"></i>ADD NEW ACCOUNTS</button>
                <button class="btn btn-sm btn-secondary" type="button" id="workspace-account-refresh" title="REFRESH ACCOUNTS" aria-label="Refresh accounts"><i class="fas fa-sync" aria-hidden="true"></i></button>
                <button class="btn-block-option" type="button" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content">
            <div id="workspace-account-status" class="alert d-none" role="status" aria-live="polite"></div>
            <div class="mb-2">
                <button class="btn btn-sm btn-outline-secondary" id="workspace-accounts-disable" type="button"><i class="fas fa-minus mr-1" aria-hidden="true"></i>DISABLE SELECTED</button>
                <button class="btn btn-sm btn-danger" id="workspace-accounts-delete" type="button"><i class="fas fa-trash mr-1" aria-hidden="true"></i>DELETE SELECTED</button>
                <button class="btn btn-sm btn-outline-secondary" id="workspace-accounts-search" type="button"><i class="fas fa-search mr-1" aria-hidden="true"></i>SEARCH ACCOUNTS</button>
            </div>
            <div class="table-responsive">
                <table id="workspace-account-table" class="table table-sm table-hover table-striped">
                    <thead><tr><th><input type="checkbox" id="workspace-accounts-all" aria-label="Select all accounts on this page"></th><th>#</th><th>project</th><th>email</th><th>is auth</th><th>is active</th><th>created at</th><th>ACTIONS</th></tr></thead>
                    <tbody>
                        @forelse ($accounts as $account)
                            <tr>
                                <td><input type="checkbox" value="{{ $account->id }}" class="workspace-account-select" aria-label="Select account {{ $account->email }}"></td>
                                <td>{{ $account->id }}</td>
                                <td>{{ $account->project?->name }}</td>
                                <td>{{ $account->email }}</td>
                                <td title="Per-account OAuth tokens are not configured in AlphaLink">NO</td>
                                <td>{{ $account->is_active ? 'YES' : 'NO' }}</td>
                                <td>{{ $account->created_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                                <td class="text-nowrap">
                                    <button class="btn btn-sm btn-primary" type="button" disabled title="Per-account OAuth authentication is not configured in AlphaLink">AUTH</button>
                                    <button class="btn btn-sm {{ $account->is_active ? 'btn-warning' : 'btn-success' }}" type="button" data-account-id="{{ $account->id }}" data-account-status="{{ $account->is_active ? '0' : '1' }}" title="{{ $account->is_active ? 'DISABLE' : 'ENABLE' }} ACCOUNT" aria-label="{{ $account->is_active ? 'Disable' : 'Enable' }} {{ $account->email }}"><i class="fas {{ $account->is_active ? 'fa-times' : 'fa-check' }}" aria-hidden="true"></i></button>
                                    <button class="btn btn-sm btn-danger" type="button" data-account-delete="{{ $account->id }}" title="DELETE ACCOUNT" aria-label="Delete {{ $account->email }}"><i class="fas fa-trash" aria-hidden="true"></i></button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-muted">No synced Workspace accounts have been added.</td></tr>
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
                ? window.jQuery('#workspace-account-table').DataTable({pageLength:50, order:[[1,'desc']]})
                : null;
            document.getElementById('workspace-accounts-search').addEventListener('click', async () => {
                const choice = await dialog({input:'textarea',inputLabel:'Search accounts',inputPlaceholder:'Type your accounts here...',inputAttributes:{'aria-label':'Type your accounts here'},showCancelButton:true});
                if (choice.isDismissed || !choice.isConfirmed) {
                    if (table) table.columns().search('').draw();
                    return;
                }
                const accounts = [...new Set(String(choice.value || '').split('\n').filter((value) => value))];
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
