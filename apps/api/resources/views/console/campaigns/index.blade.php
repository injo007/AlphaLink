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
            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    <strong>Campaign send was not started.</strong>
                    <ul class="mb-0 mt-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
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
                    <span class="badge badge-info mr-2" title="Campaign sends run in the background and report SMTP outcomes here.">BACKGROUND SENDS</span>
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
                                    <button type="button" class="btn btn-link btn-sm" data-campaign-statistics-toggle aria-expanded="false" aria-controls="campaign-statistics-{{ $campaign->id }}" data-statistics-url="{{ route('console.campaign-statistics.index', [$workspace->id, 'campaign_id' => $campaign->id, 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}" title="Show campaign statistics" aria-label="Show statistics for {{ $campaign->name }}"><i class="fas fa-chart-bar" aria-hidden="true"></i></button>
                                    @if ($campaign->kind === 'marketing' && ($register['configured'] ?? false))
                                        <a class="btn btn-link btn-sm" href="{{ route('console.campaigns.clone', [$workspace->id, $campaign->id]) }}" title="Clone campaign into a new draft" aria-label="Clone {{ $campaign->name }}"><i class="fas fa-clone" aria-hidden="true"></i></a>
                                    @else
                                        <button type="button" class="btn btn-link btn-sm" disabled title="Only DMS Send campaigns with a saved audience setup can be cloned." aria-label="Clone unavailable for {{ $campaign->name }}"><i class="fas fa-clone" aria-hidden="true"></i></button>
                                    @endif
                                    <button type="button" class="btn btn-link btn-sm campaign-status-open" data-campaign-name="{{ $campaign->name }}" data-next-active="{{ $campaign->is_active ? '0' : '1' }}" data-status-url="{{ route('console.campaigns.status', [$workspace->id, $campaign->id]) }}" title="{{ $campaign->is_active ? 'Disable' : 'Restore' }} campaign" aria-label="{{ $campaign->is_active ? 'Disable' : 'Restore' }} {{ $campaign->name }}"><i class="{{ $campaign->is_active ? 'fas fa-trash text-danger' : 'fas fa-trash-restore text-success' }}" aria-hidden="true"></i></button>
                                </td>
                                <td>
                                    @php
                                        $sendRun = $campaign->send_run;
                                        $sendProcessed = $sendRun
                                            ? (int) $sendRun->accepted_count + (int) $sendRun->rejected_count + (int) $sendRun->suppressed_count + (int) $sendRun->uncertain_count
                                            : 0;
                                        $sendPercent = $sendRun && $sendRun->recipient_count > 0
                                            ? min(100, (int) floor($sendProcessed * 100 / $sendRun->recipient_count))
                                            : 0;
                                    @endphp
                                    @if ($campaign->status === 'preparing_snapshot')
                                        <span class="badge badge-primary" data-snapshot-state>PREPARING SUPPRESSION</span>
                                        <div class="progress mt-1" style="height: .7rem" role="progressbar" aria-label="Campaign suppression preparation" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) $campaign->snapshot_build_percent }}" data-campaign-snapshot-progress data-progress-url="{{ route('console.campaigns.snapshot-progress', [$workspace->id, $campaign->id]) }}">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: {{ max(5, (int) $campaign->snapshot_build_percent) }}%"></div>
                                        </div>
                                        <div class="small text-muted" data-snapshot-summary>Preparing the cloned recipient list…</div>
                                        <div class="small text-danger" data-snapshot-error hidden></div>
                                    @elseif ($campaign->status === 'snapshot_failed')
                                        <span class="badge badge-danger">PREPARATION FAILED</span>
                                        <div class="small text-danger">{{ $campaign->snapshot_build_error ?? 'Recipient preparation failed.' }}</div>
                                        <a class="small" href="{{ route('console.campaigns.show', [$workspace->id, $campaign->id]) }}">Review and retry</a>
                                    @elseif ($sendRun)
                                        <span class="badge {{ in_array($sendRun->state, ['completed', 'running'], true) ? 'badge-primary' : ($sendRun->state === 'needs_review' ? 'badge-warning' : ($sendRun->state === 'failed' ? 'badge-danger' : 'badge-secondary')) }}" data-send-state>{{ strtoupper(str_replace('_', ' ', $sendRun->state)) }}</span>
                                        <div class="progress mt-1" style="height: .7rem" role="progressbar" aria-label="Campaign send progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $sendPercent }}" @if (in_array($sendRun->state, ['planned', 'running'], true)) data-campaign-send-progress data-progress-url="{{ route('console.campaigns.send-progress', [$workspace->id, $campaign->id, $sendRun->id]) }}" @endif>
                                            <div class="progress-bar {{ $sendRun->state === 'needs_review' ? 'bg-warning' : ($sendRun->state === 'failed' ? 'bg-danger' : '') }}" style="width: {{ $sendPercent }}%"></div>
                                        </div>
                                        <div class="small text-muted" data-send-summary>{{ $sendProcessed }} / {{ $sendRun->recipient_count }} · {{ $sendRun->accepted_count }} accepted · {{ $sendRun->rejected_count }} rejected · {{ $sendRun->suppressed_count }} suppressed · {{ $sendRun->uncertain_count }} uncertain</div>
                                        @if ($sendRun->last_smtp_error)
                                            <div class="small text-danger">Latest SMTP rejection: {{ $sendRun->last_smtp_error }}</div>
                                        @endif
                                        @if ($sendRun->state === 'failed')
                                            <div class="small text-danger">The sender stopped before completion. Review the latest error and retry only if no recipients were accepted.</div>
                                        @elseif ($sendRun->state === 'needs_review')
                                            <div class="small text-warning">Some SMTP outcomes are uncertain. This revision is locked to prevent duplicate delivery.</div>
                                        @endif
                                    @else
                                        <span class="badge badge-secondary">DRAFT</span><div class="small text-muted">No send recorded</div>
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    @foreach (($register['pmta_panels'] ?? []) as $panel)
                                        <a class="btn btn-link btn-sm" href="{{ $panel['url'] }}" target="_blank" rel="noopener noreferrer" title="Open PMTA Web Monitor on {{ $panel['name'] }}" aria-label="Open PMTA Web Monitor on {{ $panel['name'] }}"><i class="fas fa-server" aria-hidden="true"></i></a>
                                    @endforeach
                                    @if ($campaign->send_ready)
                                        <form method="POST" action="{{ route('console.campaigns.send', [$workspace->id, $campaign->id]) }}" class="d-inline" data-campaign-send-form>
                                            @csrf
                                            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                            <button type="submit" class="btn btn-success btn-sm" title="Send the saved campaign through its selected PMTA routes" aria-label="Send {{ $campaign->name }}"><i class="fas fa-paper-plane mr-1" aria-hidden="true"></i>SEND</button>
                                        </form>
                                        <div><span class="badge badge-success">READY TO SEND</span></div>
                                    @elseif ($sendRun && in_array($sendRun->state, ['planned', 'running'], true))
                                        <button type="button" class="btn btn-link btn-sm" disabled title="Campaign send is running." aria-label="Campaign send running"><i class="fas fa-paper-plane text-success" aria-hidden="true"></i></button>
                                        <div><span class="badge badge-primary">SENDING</span></div>
                                    @elseif ($sendRun && in_array($sendRun->state, ['completed', 'needs_review'], true))
                                        <button type="button" class="btn btn-link btn-sm" disabled title="This revision already has a send record. Revise or clone the campaign before sending again." aria-label="Campaign revision already sent"><i class="fas fa-paper-plane text-muted" aria-hidden="true"></i></button>
                                        <div><span class="badge badge-secondary">REVISION SENT</span></div>
                                    @elseif ($campaign->status === 'preparing_snapshot')
                                        <button type="button" class="btn btn-link btn-sm" disabled title="Suppression and recipient preparation are running." aria-label="Campaign preparation running"><i class="fas fa-spinner fa-spin text-primary" aria-hidden="true"></i></button>
                                        <div><span class="badge badge-primary">PREPARING</span></div>
                                    @else
                                        <button type="button" class="btn btn-link btn-sm" disabled title="The campaign must have a completed recipient snapshot and valid saved server routes before sending." aria-label="Send not ready"><i class="fas fa-paper-plane text-muted" aria-hidden="true"></i></button>
                                        <div><span class="badge badge-warning">SEND NOT READY</span></div>
                                    @endif
                                    <button type="button" class="btn btn-link btn-sm" disabled title="Campaign scheduling is not implemented in AlphaLink." aria-label="Schedule unavailable for {{ $campaign->name }}"><i class="fas fa-calendar-alt" aria-hidden="true"></i></button>
                                    <a class="btn btn-link btn-sm" href="{{ route('console.campaigns.visitors', [$workspace->id, $campaign->id]) }}" title="Open campaign visitor logs" aria-label="Open visitor logs for {{ $campaign->name }}"><i class="fas fa-eye" aria-hidden="true"></i></a>
                                    <div class="small text-muted" title="Live suppression and current email consent are checked immediately before delivery.">PMTA route · consent and suppression checked</div>
                                </td>
                            </tr>
                            <tr id="campaign-statistics-{{ $campaign->id }}" data-campaign-statistics-panel hidden>
                                <td colspan="14">
                                    <section class="campaign-statistics-dropdown" aria-label="Statistics for {{ $campaign->name }}">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h4 class="h6 mb-0">Campaign activity · {{ $from->toDateString() }} to {{ $to->toDateString() }}</h4>
                                            <a class="small" href="{{ route('console.campaign-statistics.index', [$workspace->id, 'campaign_id' => $campaign->id, 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">Open full statistics</a>
                                        </div>
                                        <div data-statistics-content role="status" aria-live="polite">Loading campaign statistics…</div>
                                    </section>
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
        .campaign-statistics-dropdown { background: #f7f9fa; border: 1px solid #d9e0e5; border-radius: .25rem; padding: .9rem; }
        .campaign-statistics-metrics { display: grid; gap: .5rem; grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr)); }
        .campaign-statistics-metric { background: #fff; border: 1px solid #e1e6ea; border-radius: .25rem; padding: .55rem .7rem; }
        .campaign-statistics-metric strong { display: block; font-size: 1.15rem; }
        .campaign-statistics-metric span { color: #6b747c; font-size: .72rem; }
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
            const liveSendProgress = Array.from(document.querySelectorAll('[data-campaign-send-progress]'));
            const snapshotProgressRows = Array.from(document.querySelectorAll('[data-campaign-snapshot-progress]'));

            snapshotProgressRows.forEach(progress => {
                const refreshProgress = async () => {
                    try {
                        const response = await fetch(progress.dataset.progressUrl, {
                            cache: 'no-store',
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });
                        if (!response.ok) return;
                        const result = await response.json();
                        const cell = progress.closest('td');
                        const badge = cell.querySelector('[data-snapshot-state]');
                        const summary = cell.querySelector('[data-snapshot-summary]');
                        const error = cell.querySelector('[data-snapshot-error]');
                        const bar = progress.querySelector('.progress-bar');
                        const percent = Math.max(0, Math.min(100, Number(result.percent || 0)));
                        progress.setAttribute('aria-valuenow', String(percent));
                        bar.style.width = `${Math.max(5, percent)}%`;
                        if (result.stage === 'suppression_file' && result.total) {
                            summary.textContent = `Checking suppression file · ${Math.floor(result.processed / 1048576)} MB of ${Math.ceil(result.total / 1048576)} MB`;
                        } else if (result.stage === 'audience' && result.total !== null) {
                            summary.textContent = `Checking selected addresses · ${Number(result.processed).toLocaleString()} of ${Number(result.total).toLocaleString()} · ${percent}%`;
                        } else {
                            const stages = {
                                queued: 'Waiting for the background preparation worker…',
                                counting_audience: 'Counting selected list addresses…',
                                writing_snapshot: 'Saving the recipient snapshot…',
                            };
                            summary.textContent = stages[result.stage] || `Preparing the cloned recipient list… ${percent}%`;
                        }
                        if (result.status === 'snapshot_failed') {
                            badge.textContent = 'PREPARATION FAILED';
                            badge.className = 'badge badge-danger';
                            error.hidden = false;
                            error.textContent = result.error || 'Recipient preparation failed. Open the campaign to review and retry.';
                            progress.removeAttribute('data-campaign-snapshot-progress');
                        } else if (result.ready) {
                            badge.textContent = 'DRAFT READY';
                            badge.className = 'badge badge-success';
                            summary.textContent = `Recipient snapshot ready · ${Number(result.total || result.processed || 0).toLocaleString()} addresses`;
                            progress.removeAttribute('data-campaign-snapshot-progress');
                            window.setTimeout(() => window.location.reload(), 900);
                        }
                    } catch (_) {
                        // Keep the last displayed progress when one status poll fails.
                    }
                };
                refreshProgress();
                const timer = window.setInterval(() => {
                    if (!progress.isConnected || !progress.hasAttribute('data-campaign-snapshot-progress')) {
                        window.clearInterval(timer);
                        return;
                    }
                    refreshProgress();
                }, 4000);
            });

            const metricDefinitions = [
                ['deliveries', 'DELIVERIES'], ['soft_bounces', 'SOFT BOUNCES'], ['hard_bounces', 'HARD BOUNCES'],
                ['opens', 'OPENS'], ['clicks', 'CLICKS'], ['leads', 'LEADS'], ['conversions', 'CONVERSIONS'],
                ['unsubscribes', 'UNSUBSCRIBES'], ['bots', 'BOTS'], ['lead_revenue', 'LEAD REVENUE'],
            ];
            const numberFormat = new Intl.NumberFormat();
            const currencyFormat = new Intl.NumberFormat(undefined, {style: 'currency', currency: 'USD'});
            const renderCampaignStatistics = (target, rows) => {
                target.replaceChildren();
                if (!Array.isArray(rows) || rows.length === 0) {
                    target.textContent = 'No campaign activity or attributed leads match this date range.';
                    return;
                }
                const totals = Object.fromEntries(metricDefinitions.map(([key]) => [key, 0]));
                const byDate = new Map();
                for (const row of rows) {
                    for (const [key] of metricDefinitions) totals[key] += Number(row[key] || 0);
                    const date = String(row.at || '');
                    if (!byDate.has(date)) byDate.set(date, Object.fromEntries(metricDefinitions.map(([key]) => [key, 0])));
                    const daily = byDate.get(date);
                    for (const [key] of metricDefinitions) daily[key] += Number(row[key] || 0);
                }
                const metrics = document.createElement('div');
                metrics.className = 'campaign-statistics-metrics mb-3';
                for (const [key, label] of metricDefinitions) {
                    const card = document.createElement('div');
                    card.className = 'campaign-statistics-metric';
                    const value = document.createElement('strong');
                    value.textContent = key === 'lead_revenue' ? currencyFormat.format(totals[key]) : numberFormat.format(totals[key]);
                    const caption = document.createElement('span');
                    caption.textContent = label;
                    card.append(value, caption);
                    metrics.append(card);
                }
                target.append(metrics);
                const recentDays = Array.from(byDate.entries()).sort(([first], [second]) => second.localeCompare(first)).slice(0, 7);
                if (recentDays.length === 0) return;
                const tableWrap = document.createElement('div');
                tableWrap.className = 'table-responsive';
                const table = document.createElement('table');
                table.className = 'table table-sm table-bordered mb-0';
                const head = document.createElement('thead');
                const headRow = document.createElement('tr');
                for (const label of ['DATE', 'DELIVERIES', 'OPENS', 'CLICKS', 'LEADS', 'UNSUBSCRIBES']) {
                    const cell = document.createElement('th');
                    cell.scope = 'col';
                    cell.textContent = label;
                    headRow.append(cell);
                }
                head.append(headRow);
                const body = document.createElement('tbody');
                for (const [date, daily] of recentDays) {
                    const row = document.createElement('tr');
                    for (const value of [date, daily.deliveries, daily.opens, daily.clicks, daily.leads, daily.unsubscribes]) {
                        const cell = document.createElement('td');
                        cell.textContent = typeof value === 'number' ? numberFormat.format(value) : value;
                        row.append(cell);
                    }
                    body.append(row);
                }
                table.append(head, body);
                tableWrap.append(table);
                target.append(tableWrap);
            };
            document.querySelectorAll('[data-campaign-statistics-toggle]').forEach(button => {
                button.addEventListener('click', async () => {
                    const panel = document.getElementById(button.getAttribute('aria-controls'));
                    if (!panel) return;
                    const open = button.getAttribute('aria-expanded') !== 'true';
                    button.setAttribute('aria-expanded', String(open));
                    panel.hidden = !open;
                    if (!open || panel.dataset.loaded === 'true') return;
                    const target = panel.querySelector('[data-statistics-content]');
                    target.textContent = 'Loading campaign statistics…';
                    try {
                        const response = await fetch(button.dataset.statisticsUrl, {
                            cache: 'no-store',
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });
                        if (!response.ok) throw new Error('Campaign statistics could not be loaded.');
                        renderCampaignStatistics(target, await response.json());
                        panel.dataset.loaded = 'true';
                    } catch (_) {
                        target.replaceChildren();
                        const message = document.createElement('span');
                        message.className = 'text-danger';
                        message.textContent = 'Campaign statistics could not be loaded. Try opening the full report or close and reopen this panel.';
                        target.append(message);
                    }
                });
            });

            liveSendProgress.forEach(progress => {
                const refreshProgress = async () => {
                    try {
                        const response = await fetch(progress.dataset.progressUrl, {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });
                        if (!response.ok) return;
                        const result = await response.json();
                        const bar = progress.querySelector('.progress-bar');
                        const cell = progress.closest('td');
                        const state = cell.querySelector('[data-send-state]');
                        const summary = cell.querySelector('[data-send-summary]');
                        progress.setAttribute('aria-valuenow', String(result.percent));
                        bar.style.width = `${result.percent}%`;
                        state.textContent = result.state.replaceAll('_', ' ').toUpperCase();
                        summary.textContent = `${result.processed} / ${result.total} · ${result.accepted} accepted · ${result.rejected} rejected · ${result.suppressed} suppressed · ${result.uncertain} uncertain`;
                        if (!['planned', 'running'].includes(result.state)) {
                            progress.removeAttribute('data-campaign-send-progress');
                            window.setTimeout(() => window.location.reload(), 1200);
                        }
                    } catch (_) {
                        // A missed refresh does not affect the queued send.
                    }
                };
                refreshProgress();
                const timer = window.setInterval(() => {
                    if (!progress.isConnected || !progress.hasAttribute('data-campaign-send-progress')) {
                        window.clearInterval(timer);
                        return;
                    }
                    refreshProgress();
                }, 4000);
            });

            const updateSelection = () => {
                const count = rowSelections.filter(input => input.checked).length;
                bulkButton.disabled = count === 0;
                selectedCount.textContent = count === 0 ? 'No campaigns selected' : `${count} campaign${count === 1 ? '' : 's'} selected`;
                selectAll.checked = count > 0 && count === rowSelections.length;
                selectAll.indeterminate = count > 0 && count < rowSelections.length;
            };

            rowSelections.forEach(input => input.addEventListener('change', updateSelection));
            document.querySelectorAll('[data-campaign-send-form]').forEach(form => form.addEventListener('submit', event => {
                if (!window.confirm('Send this campaign to the selected audience? Only contacts with current recorded email marketing consent will be sent.')) {
                    event.preventDefault();
                    return;
                }
                const button = form.querySelector('button[type="submit"]');
                if (button) {
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                    button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>QUEUING';
                }
            }));
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
