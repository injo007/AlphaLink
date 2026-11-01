@extends('layouts.dms-admin')

@section('title', 'Campaigns')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">MY CAMPAIGNS</h3>
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
                    <button class="btn btn-sm btn-alt-secondary mr-2" type="button" disabled title="Running processes" aria-label="Running processes unavailable"><i class="fas fa-cog" aria-hidden="true"></i></button>
                    <button class="btn btn-sm btn-alt-danger mr-2" type="submit" form="campaign-bulk-form" id="campaign-bulk-disable-open" disabled title="Select campaigns to disable"><i class="fas fa-trash" aria-hidden="true"></i><span class="sr-only">Disable selected campaigns</span></button>
                    <a class="btn btn-sm btn-alt-secondary" href="{{ request()->fullUrl() }}" title="Reload campaign register" aria-label="Reload campaign register"><i class="fas fa-sync-alt" aria-hidden="true"></i></a>
                </div>
                <form id="campaign-filter-form" method="GET" action="{{ route('console.campaigns.index', $workspace->id) }}" class="form-inline mb-2 campaign-register-filters" aria-label="Filter campaigns" data-analytics-date-range-form data-storage-key="campaigns" data-min-date="none" novalidate>
                    <input type="hidden" name="per_page" value="{{ $perPage }}">
                    @if ($showDisabled)
                        <input type="hidden" name="disabled" value="1">
                    @endif
                    <label for="analytics-dates" class="mr-1">Date:</label>
                    <input id="analytics-dates" type="text" class="form-control form-control-sm mr-2 campaign-date-range" value="{{ $from->toDateString() }} - {{ $to->toDateString() }}" readonly aria-label="Choose campaign date range">
                    <input type="hidden" name="from" value="{{ $from->toDateString() }}">
                    <input type="hidden" name="to" value="{{ $to->toDateString() }}">
                    <label class="sr-only" for="campaign-search">Search campaigns</label>
                    <div class="campaign-search-control">
                        <input id="campaign-search" name="q" type="search" class="form-control form-control-sm" value="{{ $search }}" maxlength="120" placeholder="Search.." aria-label="Search campaigns">
                        <button id="campaign-search-clear" class="campaign-search-clear" type="button" aria-label="Clear campaign search" title="Clear search" hidden><i class="fas fa-times" aria-hidden="true"></i></button>
                    </div>
                    <button class="btn btn-sm btn-alt-secondary ml-1" type="submit" title="Apply campaign filters" aria-label="Apply campaign filters"><i class="fas fa-search" aria-hidden="true"></i></button>
                    <a class="small ml-2" href="{{ route('console.campaigns.test-draft.create', $workspace->id) }}" aria-label="New test message">New test message</a>
                </form>
            </div>
            <div id="campaign-selection-count" class="text-muted small mb-2" aria-live="polite" hidden></div>

            <form id="campaign-bulk-form" method="POST" action="{{ route('console.campaigns.bulk-disable', $workspace->id) }}">
                @csrf
                @method('PATCH')
            </form>

            <div class="table-responsive campaign-register-table-wrap">
                <table class="table table-sm table-hover campaign-register-table" aria-label="My campaigns">
                    <thead>
                        <tr>
                            <th scope="col" data-campaign-column="select"><input id="campaign-select-all" type="checkbox" aria-label="Select all campaigns on this page" form="campaign-bulk-form"></th>
                            <th scope="col" data-campaign-column="campaign">#</th>
                            <th scope="col" data-campaign-column="isp">ISP</th>
                            <th scope="col" data-campaign-column="offer">OFFER</th>
                            <th scope="col" data-campaign-column="server">SERVER</th>
                            <th scope="col" data-campaign-column="total-send">TOTAL SEND</th>
                            <th scope="col" data-campaign-column="fraction-ip">FRACTION IP</th>
                            <th scope="col" data-campaign-column="sleep-email">SLEEP EMAIL</th>
                            <th scope="col" data-campaign-column="sleep-ip">SLEEP IP</th>
                            <th scope="col" data-campaign-column="seed">SEED</th>
                            <th scope="col" data-campaign-column="send-type">SEND TYPE</th>
                            <th scope="col" data-campaign-column="actions">ACTIONS</th>
                            <th scope="col" data-campaign-column="progress">PROGRESS</th>
                            <th scope="col" data-campaign-column="send">SEND</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($campaigns as $campaign)
                            @php
                                $register = $campaign->send_register ?? [];
                            @endphp
                            <tr>
                                <td data-campaign-column="select"><input class="campaign-row-select" type="checkbox" name="campaign_ids[]" value="{{ $campaign->id }}" form="campaign-bulk-form" aria-label="Select campaign {{ $campaign->name }}"></td>
                                <td data-campaign-column="campaign">
                                    <a href="{{ route('console.campaigns.show', [$workspace->id, $campaign->id]) }}" title="{{ $campaign->name }} · created {{ $campaign->created_at?->format('Y-m-d H:i') }}">{{ substr($campaign->id, 0, 8) }}</a>
                                    <div class="small text-muted">{{ $campaign->name }}</div>
                                </td>
                                <td data-campaign-column="isp">{{ $register['isp'] ?? '—' }}</td>
                                <td data-campaign-column="offer">{{ $register['offer'] ?? '—' }}</td>
                                <td data-campaign-column="server">
                                    @forelse (($register['servers'] ?? []) as $serverName)
                                        <span class="badge badge-primary">{{ $serverName }}</span>
                                    @empty
                                        —
                                    @endforelse
                                </td>
                                <td data-campaign-column="total-send">{{ $register['total_send'] ?? '—' }}</td>
                                <td data-campaign-column="fraction-ip">{{ $register['fraction_ip'] ?? '—' }}</td>
                                <td data-campaign-column="sleep-email">{{ $register['sleep_email'] ?? '—' }}</td>
                                <td data-campaign-column="sleep-ip">{{ $register['sleep_ip'] ?? '—' }}</td>
                                <td data-campaign-column="seed">{{ $register['seed_every'] ?? '—' }}</td>
                                <td data-campaign-column="send-type">
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
                                <td class="text-nowrap" data-campaign-column="actions">
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
                                        <a class="btn btn-link btn-sm" href="{{ route('console.campaigns.clone', [$workspace->id, $campaign->id]) }}" data-campaign-clone-open title="Clone campaign" aria-label="Clone {{ $campaign->name }}" aria-haspopup="dialog"><i class="fas fa-clone" aria-hidden="true"></i></a>
                                    @else
                                        <button type="button" class="btn btn-link btn-sm" disabled title="Only DMS Send campaigns with a saved audience setup can be cloned." aria-label="Clone unavailable for {{ $campaign->name }}"><i class="fas fa-clone" aria-hidden="true"></i></button>
                                    @endif
                                    <button type="button" class="btn btn-link btn-sm campaign-status-open" data-campaign-name="{{ $campaign->name }}" data-next-active="{{ $campaign->is_active ? '0' : '1' }}" data-status-url="{{ route('console.campaigns.status', [$workspace->id, $campaign->id]) }}" title="{{ $campaign->is_active ? 'Disable' : 'Restore' }} campaign" aria-label="{{ $campaign->is_active ? 'Disable' : 'Restore' }} {{ $campaign->name }}"><i class="{{ $campaign->is_active ? 'fas fa-trash text-danger' : 'fas fa-trash-restore text-success' }}" aria-hidden="true"></i></button>
                                </td>
                                <td data-campaign-column="progress">
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
                                <td class="text-nowrap" data-campaign-column="send">
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
                                </td>
                            </tr>
                            <tr id="campaign-statistics-{{ $campaign->id }}" data-campaign-statistics-panel hidden>
                                <td colspan="14" data-campaign-colspan>
                                    <section class="campaign-statistics-dropdown" aria-label="Statistics for {{ $campaign->name }}">
                                        <div data-statistics-content role="status" aria-live="polite">Loading campaign statistics…</div>
                                    </section>
                                </td>
                            </tr>
                        @empty
                            <tr data-campaign-empty-row><td colspan="14" class="text-center text-muted" data-campaign-colspan>No data available in table</td></tr>
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

    <dialog id="campaign-clone-dialog" class="campaign-clone-dialog" aria-labelledby="campaign-clone-dialog-title" aria-modal="true">
        <div class="campaign-clone-dialog-header">
            <h2 id="campaign-clone-dialog-title">CLONE CAMPAIGN</h2>
            <button type="button" class="btn btn-sm btn-alt-secondary" data-campaign-clone-dialog-close aria-label="Close clone campaign dialog"><i class="fas fa-times" aria-hidden="true"></i></button>
        </div>
        <div id="campaign-clone-dialog-content" aria-live="polite">
            <p class="text-muted mb-0">Loading campaign settings…</p>
        </div>
    </dialog>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('dms-reference/vendor/daterangepicker/daterangepicker.css') }}">
    <style>
        .campaign-register-table { font-size: .8rem; min-width: 1360px; }
        .campaign-register-table th { white-space: nowrap; }
        .campaign-register-table td { vertical-align: middle; }
        .campaign-statistics-dropdown { background: #fff; border-top: 1px solid #dce1e5; padding: 0; }
        .campaign-statistics-table { font-size: .78rem; margin-bottom: 0; table-layout: fixed; width: 100%; }
        .campaign-statistics-table thead th { border-top: 0; color: #303941; font-size: .68rem; font-weight: 700; padding: .45rem .6rem .2rem; white-space: nowrap; }
        .campaign-statistics-table td { color: #252b30; font-variant-numeric: tabular-nums; padding: .2rem .6rem .45rem; }
        .campaign-statistics-empty { color: #737e87; padding: .65rem .6rem; }
        .campaign-register-toolbar .btn[disabled] { cursor: not-allowed; opacity: .55; }
        .campaign-register-filters .campaign-date-range { width: 12.6rem; }
        .campaign-register-filters .campaign-search-control { position: relative; }
        .campaign-register-filters input[type="search"] { max-width: 12rem; padding-right: 2rem; }
        .campaign-search-clear { align-items: center; background: transparent; border: 0; color: #77818a; display: inline-flex; height: 100%; justify-content: center; padding: 0 .45rem; position: absolute; right: 0; top: 0; }
        .campaign-search-clear[hidden] { display: none; }
        .campaign-search-clear:focus-visible { outline: 2px solid #376fc4; outline-offset: 2px; }
        .campaign-status-dialog { border: 1px solid #d5dce1; border-radius: .35rem; color: #343a40; max-width: 32rem; padding: 1.25rem; width: min(92vw, 32rem); }
        .campaign-status-dialog::backdrop { background: rgba(27, 33, 40, .55); }
        .campaign-status-dialog h2 { font-size: 1.15rem; margin: 0 0 .75rem; }
        .campaign-clone-dialog { border: 1px solid #d5dce1; border-radius: .35rem; color: #343a40; max-height: min(90vh, 56rem); max-width: 56rem; overflow: auto; padding: 1.25rem; width: min(94vw, 56rem); }
        .campaign-clone-dialog::backdrop { background: rgba(27, 33, 40, .58); }
        .campaign-clone-dialog-header { align-items: center; border-bottom: 1px solid #e1e6ea; display: flex; justify-content: space-between; margin: -0.25rem 0 1rem; padding-bottom: .7rem; }
        .campaign-clone-dialog-header h2 { font-size: 1.05rem; font-weight: 600; margin: 0; }
        .campaign-clone-form-content .form-group { margin-bottom: 1rem; }
        @media (max-width: 767.98px) {
            .campaign-register-filters { align-items: stretch; width: 100%; }
            .campaign-register-filters label[for="analytics-dates"] { width: 100%; }
            .campaign-register-filters .campaign-date-range { flex: 1 1 12rem; width: auto; }
            .campaign-register-filters .campaign-search-control { flex: 1 1 10rem; }
            .campaign-register-filters input[type="search"] { max-width: none; width: 100%; }
        }
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('dms-reference/vendor/daterangepicker/daterangepicker.js') }}?v=3.1.0"></script>
    <script src="{{ asset('dms-reference/analytics-date-range.js') }}?v={{ substr(sha1_file(public_path('dms-reference/analytics-date-range.js')), 0, 12) }}" defer></script>
    <script src="{{ asset('dms-reference/campaign-register-controls.js') }}?v={{ substr(sha1_file(public_path('dms-reference/campaign-register-controls.js')), 0, 12) }}" defer></script>
    <script src="{{ asset('dms-reference/campaign-clone-modal.js') }}?v={{ substr(sha1_file(public_path('dms-reference/campaign-clone-modal.js')), 0, 12) }}" defer></script>
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
                ['opens', 'OPEN'], ['clicks', 'CLICK'], ['leads', 'COUNT LEAD'], ['lead_revenue', 'LEAD'],
                ['unsubscribes', 'UNSUBSCRIBE'], ['bots', 'BOT'],
            ];
            const numberFormat = new Intl.NumberFormat();
            const currencyFormat = new Intl.NumberFormat(undefined, {style: 'currency', currency: 'USD'});
            const renderCampaignStatistics = (target, rows) => {
                target.replaceChildren();
                if (!Array.isArray(rows) || rows.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'campaign-statistics-empty';
                    empty.textContent = 'No campaign statistics are recorded.';
                    target.append(empty);
                    return;
                }
                const totals = Object.fromEntries(metricDefinitions.map(([key]) => [key, 0]));
                for (const row of rows) {
                    for (const [key] of metricDefinitions) totals[key] += Number(row[key] || 0);
                }
                const table = document.createElement('table');
                table.className = 'table table-sm campaign-statistics-table';
                const head = document.createElement('thead');
                const headRow = document.createElement('tr');
                for (const [, label] of [...metricDefinitions, ['epc', 'EPC']]) {
                    const cell = document.createElement('th');
                    cell.scope = 'col';
                    cell.textContent = label;
                    headRow.append(cell);
                }
                head.append(headRow);
                const body = document.createElement('tbody');
                const row = document.createElement('tr');
                for (const [key] of metricDefinitions) {
                    const cell = document.createElement('td');
                    cell.textContent = key === 'lead_revenue' ? currencyFormat.format(totals[key]) : numberFormat.format(totals[key]);
                    row.append(cell);
                }
                const epc = document.createElement('td');
                epc.textContent = currencyFormat.format(totals.clicks > 0 ? totals.lead_revenue / totals.clicks : 0);
                row.append(epc);
                body.append(row);
                table.append(head, body);
                target.append(table);
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
                selectedCount.textContent = `${count} campaign${count === 1 ? '' : 's'} selected`;
                selectedCount.hidden = count === 0;
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
