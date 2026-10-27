@extends('layouts.dms-admin')

@section('title', 'Campaigns')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">My campaigns</h3>
            <div class="block-options d-flex align-items-center">
                <form method="GET" action="{{ route('console.campaigns.index', $workspace->id) }}" aria-label="Campaign availability filter">
                    <input type="hidden" name="from" value="{{ $from->toDateString() }}">
                    <input type="hidden" name="to" value="{{ $to->toDateString() }}">
                    @if ($search !== '')
                        <input type="hidden" name="q" value="{{ $search }}">
                    @endif
                    <input type="hidden" name="per_page" value="{{ $perPage }}">
                    <div class="custom-control custom-switch custom-control-danger custom-control-inline mb-0">
                        <input id="campaign-disabled" name="disabled" value="1" type="checkbox" class="custom-control-input" @checked($showDisabled) onchange="this.form.submit()">
                        <label class="custom-control-label" for="campaign-disabled">SHOW DISABLED</label>
                    </div>
                </form>
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle campaign register fullscreen">
                    <i class="si si-size-fullscreen" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <div class="block-content block-content-full">
            @if (session('status'))
                <div class="alert alert-success" role="status">{{ session('status') }}</div>
            @endif
            <div id="campaign-auto-test-feedback" class="alert d-none" role="status" aria-live="polite"></div>

            <div class="d-flex flex-wrap align-items-center justify-content-between mb-2 campaign-register-toolbar">
                <div class="d-flex align-items-center mb-2">
                    <form method="GET" action="{{ route('console.campaigns.index', $workspace->id) }}" class="form-inline mr-2" aria-label="Campaign rows per page">
                        <input type="hidden" name="from" value="{{ $from->toDateString() }}">
                        <input type="hidden" name="to" value="{{ $to->toDateString() }}">
                        @if ($search !== '')
                            <input type="hidden" name="q" value="{{ $search }}">
                        @endif
                        @if ($showDisabled)
                            <input type="hidden" name="disabled" value="1">
                        @endif
                        <label class="sr-only" for="campaign-per-page">Rows per page</label>
                        <select id="campaign-per-page" name="per_page" class="form-control form-control-sm" onchange="this.form.submit()" aria-label="Show campaign rows">
                            @foreach ([20, 50, 100] as $pageSize)
                                <option value="{{ $pageSize }}" @selected($perPage === $pageSize)>Show {{ $pageSize }} rows</option>
                            @endforeach
                        </select>
                    </form>
                    <a class="btn btn-sm btn-alt-primary mr-2" href="{{ route('console.campaigns.create', $workspace->id) }}" title="Create campaign" aria-label="Create campaign"><i class="fas fa-plus" aria-hidden="true"></i></a>
                    <a class="btn btn-sm btn-alt-primary mr-2" href="{{ route('console.campaigns.test-draft.create', $workspace->id) }}" title="Create a one-recipient Symfony Mailer test message" aria-label="New test message"><i class="far fa-paper-plane" aria-hidden="true"></i></a>
                    <button class="btn btn-sm btn-alt-secondary mr-2" type="button" disabled title="AlphaLink has no native campaign sender or running send process yet."><i class="fas fa-cog" aria-hidden="true"></i><span class="sr-only">Running processes unavailable</span></button>
                    <button class="btn btn-sm btn-alt-danger mr-2" type="submit" form="campaign-bulk-form" id="campaign-bulk-disable-open" disabled title="Select campaigns to disable"><i class="fas fa-trash" aria-hidden="true"></i><span class="sr-only">Disable selected campaigns</span></button>
                    <a class="btn btn-sm btn-alt-secondary" href="{{ request()->fullUrl() }}" title="Reload campaign register" aria-label="Reload campaign register"><i class="fas fa-sync-alt" aria-hidden="true"></i></a>
                </div>
                <form method="GET" action="{{ route('console.campaigns.index', $workspace->id) }}" class="form-inline mb-2 campaign-register-filters" aria-label="Filter campaigns">
                    <input type="hidden" name="per_page" value="{{ $perPage }}">
                    @if ($showDisabled)
                        <input type="hidden" name="disabled" value="1">
                    @endif
                    <label class="sr-only" for="campaign-from">Date from</label>
                    <input id="campaign-from" name="from" type="date" class="form-control form-control-sm mr-1" value="{{ $from->toDateString() }}" required aria-label="Date from">
                    <label class="sr-only" for="campaign-to">Date to</label>
                    <input id="campaign-to" name="to" type="date" class="form-control form-control-sm mr-2" value="{{ $to->toDateString() }}" required aria-label="Date to">
                    <label class="sr-only" for="campaign-search">Search campaigns</label>
                    <input id="campaign-search" name="q" type="search" class="form-control form-control-sm mr-1" value="{{ $search }}" maxlength="120" placeholder="Search" aria-label="Search campaigns">
                    <button class="btn btn-sm btn-alt-secondary" type="submit" title="Apply campaign filters" aria-label="Apply campaign filters"><i class="fas fa-search" aria-hidden="true"></i></button>
                </form>
            </div>
            <div id="campaign-selection-count" class="text-muted small mb-2" aria-live="polite">No campaigns selected</div>

            <form id="campaign-bulk-form" method="POST" action="{{ route('console.campaigns.bulk-disable', $workspace->id) }}">
                @csrf
                @method('PATCH')
            </form>

            <div class="table-responsive campaign-register-table-wrap">
                <table class="table table-sm table-hover campaign-register-table">
                    <thead>
                        <tr>
                            <th scope="col"><input id="campaign-select-all" type="checkbox" aria-label="Select all campaigns on this page" form="campaign-bulk-form"></th>
                            <th scope="col">#</th>
                            <th scope="col">ISP</th>
                            <th scope="col">OFFER</th>
                            <th scope="col">SERVER</th>
                            <th scope="col">TOTAL SEND</th>
                            <th scope="col">FRACTION IP</th>
                            <th scope="col">SLEEP EMAIL</th>
                            <th scope="col">SLEEP IP</th>
                            <th scope="col">SEED</th>
                            <th scope="col">SEND TYPE</th>
                            <th scope="col">ACTIONS</th>
                            <th scope="col">PROGRESS</th>
                            <th scope="col">SEND</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($campaigns as $campaign)
                            @php
                                $register = $campaign->send_register ?? [];
                            @endphp
                            <tr>
                                <td><input class="campaign-row-select" type="checkbox" name="campaign_ids[]" value="{{ $campaign->id }}" form="campaign-bulk-form" aria-label="Select campaign {{ $campaign->name }}"></td>
                                <td>
                                    <a href="{{ route('console.campaigns.show', [$workspace->id, $campaign->id]) }}" title="{{ $campaign->name }} · created {{ $campaign->created_at?->format('Y-m-d H:i') }}">{{ substr($campaign->id, 0, 8) }}</a>
                                    <div class="small text-muted">{{ $campaign->name }}</div>
                                </td>
                                <td>{{ $register['isp'] ?? '—' }}</td>
                                <td>{{ $register['offer'] ?? '—' }}</td>
                                <td>
                                    @forelse (($register['servers'] ?? []) as $serverName)
                                        <span class="badge badge-primary">{{ $serverName }}</span>
                                    @empty
                                        —
                                    @endforelse
                                </td>
                                <td>{{ $register['total_send'] ?? '—' }}</td>
                                <td>{{ $register['fraction_ip'] ?? '—' }}</td>
                                <td>{{ $register['sleep_email'] ?? '—' }}</td>
                                <td>{{ $register['sleep_ip'] ?? '—' }}</td>
                                <td>{{ $register['seed_every'] ?? '—' }}</td>
                                <td>
                                    @if ($campaign->kind === 'marketing' && ($register['configured'] ?? false))
                                        <label class="sr-only" for="campaign-send-type-{{ $campaign->id }}">Send type for {{ $campaign->name }}</label>
                                        <select id="campaign-send-type-{{ $campaign->id }}" class="form-control form-control-sm" name="send_type" form="campaign-auto-test-{{ $campaign->id }}" aria-label="Send type for {{ $campaign->name }}">
                                            @foreach (['delay' => 'DELAY', 'delayp' => 'DELAYP', 'delayps' => 'DELAYPS', 'hs' => 'HS', 'hsp' => 'HSP', 'hsps' => 'HSPS', 'pickup' => 'PICKUP', 'pickups' => 'PICKUPS'] as $sendType => $sendTypeLabel)
                                                <option value="{{ $sendType }}" @selected(($register['send_type'] ?? 'delay') === $sendType)>{{ $sendTypeLabel }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        {{ isset($register['send_type']) ? strtoupper($register['send_type']) : '—' }}
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    <a class="btn btn-link btn-sm" href="{{ route('console.campaigns.show', [$workspace->id, $campaign->id]) }}" title="Open saved draft" aria-label="Open {{ $campaign->name }}"><i class="fas fa-eye" aria-hidden="true"></i></a>
                                    <a class="btn btn-link btn-sm" href="{{ route('console.campaigns.show', [$workspace->id, $campaign->id]) }}#campaign-test-logs" title="Open campaign test logs" aria-label="Open test logs for {{ $campaign->name }}"><i class="fas fa-history" aria-hidden="true"></i></a>
                                    @if ($campaign->kind === 'transactional')
                                        <a class="btn btn-link btn-sm" href="{{ route('console.campaigns.show', [$workspace->id, $campaign->id]) }}#test-email" title="Send one test email through Symfony Mailer" aria-label="Open test email for {{ $campaign->name }}"><i class="far fa-paper-plane text-warning" aria-hidden="true"></i></a>
                                    @elseif (($register['configured'] ?? false))
                                        <form id="campaign-auto-test-{{ $campaign->id }}" method="POST" action="{{ route('console.campaigns.auto-test', [$workspace->id, $campaign->id]) }}" class="d-inline" data-campaign-auto-test-form data-logs-url="{{ route('console.campaigns.show', [$workspace->id, $campaign->id]) }}#campaign-test-logs" data-ip-count="{{ count($campaign->latestRevision?->payload['send_setup']['ip_ids'] ?? []) }}" data-send-type-selector="campaign-send-type-{{ $campaign->id }}" data-submit-button="campaign-auto-test-submit-{{ $campaign->id }}">
                                            @csrf
                                            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                        </form>
                                        <button id="campaign-auto-test-submit-{{ $campaign->id }}" class="btn btn-link btn-sm" type="submit" form="campaign-auto-test-{{ $campaign->id }}" title="Test saved campaign settings" aria-label="Test {{ $campaign->name }}"><i class="far fa-paper-plane text-warning" aria-hidden="true"></i></button>
                                    @else
                                        <button class="btn btn-link btn-sm" type="button" disabled title="Save Email Test addresses, Header and server settings to test this campaign." aria-label="Test unavailable for {{ $campaign->name }}"><i class="far fa-paper-plane text-warning" aria-hidden="true"></i></button>
                                    @endif
                                    @if ($campaign->kind === 'marketing')
                                        <a class="btn btn-link btn-sm" href="{{ route('console.campaigns.edit', [$workspace->id, $campaign->id]) }}" title="Edit campaign" aria-label="Edit {{ $campaign->name }}"><i class="fas fa-edit" aria-hidden="true"></i></a>
                                    @endif
                                    <button type="button" class="btn btn-link btn-sm" disabled title="Editing selected IPs is not implemented in AlphaLink." aria-label="Edit IPs unavailable for {{ $campaign->name }}"><i class="fas fa-list" aria-hidden="true"></i></button>
                                    <a class="btn btn-link btn-sm" href="{{ route('console.campaign-statistics.index', [$workspace->id, 'campaign_id' => $campaign->id]) }}" title="Open campaign statistics" aria-label="Open statistics for {{ $campaign->name }}"><i class="fas fa-chart-bar" aria-hidden="true"></i></a>
                                    @if ($campaign->kind === 'marketing' && ($register['configured'] ?? false))
                                        <a class="btn btn-link btn-sm" href="{{ route('console.campaigns.clone', [$workspace->id, $campaign->id]) }}" title="Clone campaign into a new draft" aria-label="Clone {{ $campaign->name }}"><i class="fas fa-clone" aria-hidden="true"></i></a>
                                    @else
                                        <button type="button" class="btn btn-link btn-sm" disabled title="Only DMS Send campaigns with a saved audience setup can be cloned." aria-label="Clone unavailable for {{ $campaign->name }}"><i class="fas fa-clone" aria-hidden="true"></i></button>
                                    @endif
                                    <button type="button" class="btn btn-link btn-sm campaign-status-open" data-campaign-name="{{ $campaign->name }}" data-next-active="{{ $campaign->is_active ? '0' : '1' }}" data-status-url="{{ route('console.campaigns.status', [$workspace->id, $campaign->id]) }}" title="{{ $campaign->is_active ? 'Disable' : 'Restore' }} campaign" aria-label="{{ $campaign->is_active ? 'Disable' : 'Restore' }} {{ $campaign->name }}"><i class="{{ $campaign->is_active ? 'fas fa-trash text-danger' : 'fas fa-trash-restore text-success' }}" aria-hidden="true"></i></button>
                                </td>
                                <td><span class="badge badge-secondary">DRAFT</span><div class="small text-muted">No send recorded</div></td>
                                <td class="text-nowrap">
                                    @foreach (($register['pmta_panels'] ?? []) as $panel)
                                        <a class="btn btn-link btn-sm" href="{{ $panel['url'] }}" target="_blank" rel="noopener noreferrer" title="Open PMTA Web Monitor on {{ $panel['name'] }}" aria-label="Open PMTA Web Monitor on {{ $panel['name'] }}"><i class="fas fa-server" aria-hidden="true"></i></a>
                                    @endforeach
                                    <button type="button" class="btn btn-link btn-sm" disabled title="The native DMS campaign sender is not implemented in AlphaLink." aria-label="Send unavailable for {{ $campaign->name }}"><i class="fas fa-paper-plane text-success" aria-hidden="true"></i></button>
                                    <button type="button" class="btn btn-link btn-sm" disabled title="Campaign scheduling is not implemented in AlphaLink." aria-label="Schedule unavailable for {{ $campaign->name }}"><i class="fas fa-calendar-alt" aria-hidden="true"></i></button>
                                    <a class="btn btn-link btn-sm" href="{{ route('console.campaigns.visitors', [$workspace->id, $campaign->id]) }}" title="Open campaign visitor logs" aria-label="Open visitor logs for {{ $campaign->name }}"><i class="fas fa-eye" aria-hidden="true"></i></a>
                                    <div><span class="badge badge-warning">SEND UNAVAILABLE</span></div>
                                    <div class="small text-muted" title="The recipient snapshot is saved. The native DMS sender is not implemented.">Recipient snapshot saved</div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="14" class="text-center">This Grid Is Empty</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-end mt-2">{{ $campaigns->links() }}</div>
            <p class="text-muted small mb-0">Showing {{ $campaigns->firstItem() ?? 0 }} to {{ $campaigns->lastItem() ?? 0 }} of {{ $campaigns->total() }} campaigns in the selected date range.</p>
        </div>
    </div>

    <dialog id="campaign-status-dialog" aria-labelledby="campaign-status-title" aria-describedby="campaign-status-description" class="campaign-status-dialog">
        <h2 id="campaign-status-title">Change campaign availability</h2>
        <p id="campaign-status-description"></p>
        <form id="campaign-status-form" method="POST" action="">
            @csrf
            @method('PATCH')
            <input id="campaign-status-value" type="hidden" name="is_active" value="">
            <div class="d-flex justify-content-end">
                <button type="button" class="btn btn-alt-secondary mr-2" id="campaign-status-cancel">Cancel</button>
                <button type="submit" class="btn btn-primary" id="campaign-status-confirm">Continue</button>
            </div>
        </form>
    </dialog>

    <dialog id="campaign-bulk-dialog" aria-labelledby="campaign-bulk-title" aria-describedby="campaign-bulk-description" class="campaign-status-dialog">
        <h2 id="campaign-bulk-title">Disable selected campaigns</h2>
        <p id="campaign-bulk-description">The selected drafts will be removed from the active campaign register. They can be restored individually later. No email will be sent.</p>
        <div class="d-flex justify-content-end">
            <button type="button" class="btn btn-alt-secondary mr-2" id="campaign-bulk-cancel">Cancel</button>
            <button type="button" class="btn btn-danger" id="campaign-bulk-confirm">Disable campaigns</button>
        </div>
    </dialog>
@endsection

@push('styles')
    <style>
        .campaign-register-table { min-width: 1500px; }
        .campaign-register-table th { white-space: nowrap; }
        .campaign-register-table td { vertical-align: middle; }
        .campaign-register-toolbar .btn[disabled] { cursor: not-allowed; opacity: .55; }
        .campaign-register-filters input[type="date"] { max-width: 9.5rem; }
        .campaign-register-filters input[type="search"] { max-width: 12rem; }
        .campaign-status-dialog { border: 1px solid #d5dce1; border-radius: .35rem; color: #343a40; max-width: 32rem; padding: 1.25rem; width: min(92vw, 32rem); }
        .campaign-status-dialog::backdrop { background: rgba(27, 33, 40, .55); }
        .campaign-status-dialog h2 { font-size: 1.15rem; margin: 0 0 .75rem; }
    </style>
@endpush

@push('scripts')
    <script>
        (() => {
            const statusDialog = document.getElementById('campaign-status-dialog');
            const statusForm = document.getElementById('campaign-status-form');
            const statusConfirm = document.getElementById('campaign-status-confirm');
            const statusDescription = document.getElementById('campaign-status-description');
            const statusValue = document.getElementById('campaign-status-value');
            const bulkForm = document.getElementById('campaign-bulk-form');
            const bulkDialog = document.getElementById('campaign-bulk-dialog');
            const bulkButton = document.getElementById('campaign-bulk-disable-open');
            const selectedCount = document.getElementById('campaign-selection-count');
            const selectAll = document.getElementById('campaign-select-all');
            const rowSelections = Array.from(document.querySelectorAll('.campaign-row-select'));
            const autoTestForms = Array.from(document.querySelectorAll('[data-campaign-auto-test-form]'));
            const autoTestFeedback = document.getElementById('campaign-auto-test-feedback');

            const updateSelection = () => {
                const count = rowSelections.filter(input => input.checked).length;
                bulkButton.disabled = count === 0;
                selectedCount.textContent = count === 0 ? 'No campaigns selected' : `${count} campaign${count === 1 ? '' : 's'} selected`;
                selectAll.checked = count > 0 && count === rowSelections.length;
                selectAll.indeterminate = count > 0 && count < rowSelections.length;
            };

            rowSelections.forEach(input => input.addEventListener('change', updateSelection));
            autoTestForms.forEach(form => form.addEventListener('submit', async event => {
                const ipCount = Number(form.dataset.ipCount || 0);
                if (ipCount > 100 && !window.confirm(`You are testing more than ${ipCount} IPs. Are you sure?`)) {
                    event.preventDefault();

                    return;
                }

                event.preventDefault();
                const submitButton = document.getElementById(form.dataset.submitButton);
                const sendType = document.getElementById(form.dataset.sendTypeSelector);
                const originalMarkup = submitButton.innerHTML;
                const formData = new FormData(form);
                formData.set('send_type', sendType.value);
                submitButton.disabled = true;
                submitButton.setAttribute('aria-busy', 'true');
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i><span class="sr-only">Testing campaign</span>';
                autoTestFeedback.className = 'alert alert-info';
                autoTestFeedback.setAttribute('role', 'status');
                autoTestFeedback.textContent = 'Campaign Test is running. This page will stay open while PMTA responds.';
                autoTestFeedback.scrollIntoView({block: 'nearest'});

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: formData,
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    const result = await response.json().catch(() => ({}));
                    const nextKey = form.querySelector('input[name="idempotency_key"]');
                    if (nextKey && result.next_idempotency_key) nextKey.value = result.next_idempotency_key;
                    if (!response.ok) {
                        const firstValidationError = Object.values(result.errors || {}).flat()[0];
                        throw new Error(result.data || result.message || firstValidationError || 'Campaign test failed.');
                    }
                    const accepted = result.outcome === 'accepted_by_transport';
                    autoTestFeedback.className = `alert alert-${accepted ? 'success' : 'warning'}`;
                    autoTestFeedback.setAttribute('role', 'status');
                    autoTestFeedback.textContent = result.data || 'Campaign test completed.';
                    window.AlphaLinkToast?.show(autoTestFeedback.textContent, accepted ? 'success' : 'warning');
                    if (result.attempt_id) {
                        const logLink = document.createElement('a');
                        logLink.href = form.dataset.logsUrl;
                        logLink.className = 'd-inline-block ml-2';
                        logLink.textContent = 'Open campaign test logs';
                        autoTestFeedback.append(' ', logLink);
                    }
                } catch (error) {
                    autoTestFeedback.className = 'alert alert-danger';
                    autoTestFeedback.setAttribute('role', 'alert');
                    autoTestFeedback.textContent = error instanceof Error ? error.message : 'Campaign test failed.';
                    window.AlphaLinkToast?.show(autoTestFeedback.textContent, 'danger');
                } finally {
                    submitButton.disabled = false;
                    submitButton.removeAttribute('aria-busy');
                    submitButton.innerHTML = originalMarkup;
                }
            }));
            selectAll.addEventListener('change', () => {
                rowSelections.forEach(input => { input.checked = selectAll.checked; });
                updateSelection();
            });

            document.querySelectorAll('.campaign-status-open').forEach(button => {
                button.addEventListener('click', () => {
                    const nextActive = button.dataset.nextActive === '1';
                    statusForm.action = button.dataset.statusUrl;
                    statusValue.value = nextActive ? '1' : '0';
                    statusDescription.textContent = nextActive
                        ? `Restore “${button.dataset.campaignName}” to the active campaign list?`
                        : `Disable “${button.dataset.campaignName}”? It will be excluded from the active campaign list.`;
                    statusConfirm.textContent = nextActive ? 'Restore campaign' : 'Disable campaign';
                    statusDialog.showModal();
                });
            });

            document.getElementById('campaign-status-cancel').addEventListener('click', () => statusDialog.close());
            statusForm.addEventListener('submit', () => {
                statusConfirm.disabled = true;
                statusConfirm.setAttribute('aria-busy', 'true');
                statusConfirm.textContent = 'Saving…';
            });

            bulkForm.addEventListener('submit', event => {
                if (rowSelections.every(input => !input.checked)) {
                    event.preventDefault();
                    return;
                }
                event.preventDefault();
                bulkDialog.showModal();
            });
            document.getElementById('campaign-bulk-cancel').addEventListener('click', () => bulkDialog.close());
            document.getElementById('campaign-bulk-confirm').addEventListener('click', () => {
                const confirm = document.getElementById('campaign-bulk-confirm');
                confirm.disabled = true;
                confirm.setAttribute('aria-busy', 'true');
                confirm.textContent = 'Disabling…';
                bulkForm.submit();
            });

            updateSelection();
        })();
    </script>
@endpush
