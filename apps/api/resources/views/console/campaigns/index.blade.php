@extends('layouts.admin-shell')

@section('title', 'Campaigns')

@section('content')
    <div class="block block-rounded campaign-register-page">
        <div class="block-header campaign-register-page-header">
            <div class="campaign-register-heading">
                <span class="campaign-register-heading-icon" aria-hidden="true"><i class="fas fa-paper-plane"></i></span>
                <div class="campaign-register-heading-copy">
                    <span class="campaign-register-eyebrow">EMAIL OPERATIONS</span>
                    <h1 class="block-title">My Campaigns</h1>
                    <p>Review campaigns, delivery progress, and send readiness.</p>
                </div>
            </div>
            <span class="campaign-hero-illustration" aria-hidden="true"><i class="fas fa-paper-plane"></i></span>
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

            <div class="campaign-workspace-grid">
                <main class="campaign-register-main" aria-label="Campaign operations">
                    <section class="campaign-overview" aria-label="Campaign overview">
                        <article class="campaign-overview-card campaign-overview-total">
                            <span class="campaign-overview-icon" aria-hidden="true"><i class="fas fa-paper-plane"></i></span>
                            <span class="campaign-overview-copy"><span>Total Campaigns</span><strong data-campaign-overview-value="total">{{ number_format($campaignOverview['total']) }}</strong><small>In selected date range</small></span>
                        </article>
                        <article class="campaign-overview-card campaign-overview-active" title="Active means enabled and available to launch; active send runs are shown separately.">
                            <span class="campaign-overview-icon" aria-hidden="true"><i class="fas fa-play"></i></span>
                            <span class="campaign-overview-copy"><span>Active</span><strong data-campaign-overview-value="active">{{ number_format($campaignOverview['active']) }}</strong><small>Enabled for launch</small></span>
                        </article>
                        <article class="campaign-overview-card campaign-overview-paused">
                            <span class="campaign-overview-icon" aria-hidden="true"><i class="fas fa-pause"></i></span>
                            <span class="campaign-overview-copy"><span>Paused</span><strong data-campaign-overview-value="paused">{{ number_format($campaignOverview['paused']) }}</strong><small>Disabled campaigns</small></span>
                        </article>
                        <article class="campaign-overview-card campaign-overview-completed" title="Counts campaigns with a completed send run in the selected date range.">
                            <span class="campaign-overview-icon" aria-hidden="true"><i class="fas fa-check"></i></span>
                            <span class="campaign-overview-copy"><span>Completed</span><strong data-campaign-overview-value="completed">{{ number_format($campaignOverview['completed']) }}</strong><small>Completed send runs</small></span>
                        </article>
                        <article class="campaign-overview-card campaign-overview-issues" title="Includes failed or review-required sends and failed recipient preparation.">
                            <span class="campaign-overview-icon" aria-hidden="true"><i class="fas fa-exclamation-triangle"></i></span>
                            <span class="campaign-overview-copy"><span>Issues</span><strong data-campaign-overview-value="issues">{{ number_format($campaignOverview['issues']) }}</strong><small>Failed or needs review</small></span>
                        </article>
                    </section>

            <div class="d-flex flex-wrap align-items-center justify-content-between mb-2 campaign-register-toolbar">
                <div class="d-flex align-items-center campaign-register-actions">
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
                    <a class="btn btn-sm btn-alt-primary campaign-register-create" href="{{ route('console.campaigns.create', $workspace->id) }}" title="Create campaign"><i class="fas fa-plus" aria-hidden="true"></i><span>New campaign</span></a>
                    <a class="btn btn-sm campaign-register-active {{ $activeOnly ? 'btn-primary' : 'btn-alt-secondary' }}" href="{{ $activeOnly ? route('console.campaigns.index', $workspace->id) : route('console.campaigns.index', [$workspace->id, 'send_state' => 'active']) }}" title="{{ $activeOnly ? 'Show all campaigns' : 'Show active campaign sends' }}" aria-label="{{ $activeOnly ? 'Show all campaigns' : 'Show active campaign sends' }}"><i class="fas fa-paper-plane mr-1" aria-hidden="true"></i>{{ $activeOnly ? 'All campaigns' : 'Active sends' }} <span class="badge badge-light ml-1">{{ $activeSendCount }}</span></a>
                    <button class="btn btn-sm btn-alt-danger campaign-register-icon-action" type="submit" form="campaign-bulk-form" id="campaign-bulk-disable-open" disabled title="Select campaigns to disable" aria-label="Disable selected campaigns"><i class="fas fa-trash" aria-hidden="true"></i></button>
                    <a class="btn btn-sm btn-alt-secondary campaign-register-icon-action" href="{{ request()->fullUrl() }}" title="Reload campaign register" aria-label="Reload campaign register"><i class="fas fa-sync-alt" aria-hidden="true"></i></a>
                </div>
                <form id="campaign-filter-form" method="GET" action="{{ route('console.campaigns.index', $workspace->id) }}" class="form-inline mb-2 campaign-register-filters" aria-label="Filter campaigns" data-analytics-date-range-form data-storage-key="campaigns" data-min-date="none" novalidate>
                    <input type="hidden" name="per_page" value="{{ $perPage }}">
                    @if ($showDisabled)
                        <input type="hidden" name="disabled" value="1">
                    @endif
                    <label for="analytics-dates" class="mr-1">Date</label>
                    <input id="analytics-dates" type="text" class="form-control form-control-sm mr-2 campaign-date-range" value="{{ $from->toDateString() }} - {{ $to->toDateString() }}" readonly aria-label="Choose campaign date range">
                    <input type="hidden" name="from" value="{{ $from->toDateString() }}">
                    <input type="hidden" name="to" value="{{ $to->toDateString() }}">
                    <label class="sr-only" for="campaign-search">Search campaigns</label>
                    <div class="campaign-search-control">
                        <input id="campaign-search" name="q" type="search" class="form-control form-control-sm" value="{{ $search }}" maxlength="120" placeholder="Search.." aria-label="Search campaigns">
                        <button id="campaign-search-clear" class="campaign-search-clear" type="button" aria-label="Clear campaign search" title="Clear search" hidden><i class="fas fa-times" aria-hidden="true"></i></button>
                    </div>
                    <button class="btn btn-sm btn-alt-secondary ml-1" type="submit" title="Apply campaign filters" aria-label="Apply campaign filters"><i class="fas fa-search" aria-hidden="true"></i></button>
                    <a class="campaign-register-test-link" href="{{ route('console.campaigns.test-draft.create', $workspace->id) }}" aria-label="New test message"><i class="far fa-envelope" aria-hidden="true"></i><span>New test message</span></a>
                </form>
            </div>
            @if ($activeOnly)
                <p class="small text-muted mb-2" role="status">Showing campaigns with an active send, including campaigns created outside the selected date range. Stop prevents new recipients from starting; an in-progress SMTP message may finish.</p>
            @endif
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
                            <th scope="col" data-campaign-column="actions">ACTIONS</th>
                            <th scope="col" data-campaign-column="progress">PROGRESS</th>
                            <th scope="col" data-campaign-column="send">SEND</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($campaigns as $campaign)
                            @php
                                $register = $campaign->send_register ?? [];
                                $sendRun = $campaign->send_run;
                                $sendActive = $sendRun && in_array($sendRun->state, ['planned', 'running'], true);
                            @endphp
                            <tr>
                                <td data-campaign-column="select"><input class="campaign-row-select" type="checkbox" name="campaign_ids[]" value="{{ $campaign->id }}" form="campaign-bulk-form" aria-label="Select campaign {{ $campaign->name }}" @disabled($sendActive) title="{{ $sendActive ? 'Stop the active send before disabling this campaign.' : 'Select campaign to disable' }}"></td>
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
                                <td data-campaign-column="actions">
                                    @if ($campaign->kind !== 'transactional' && ($register['configured'] ?? false))
                                        <select id="campaign-send-type-{{ $campaign->id }}" class="sr-only" name="send_type" form="campaign-auto-test-{{ $campaign->id }}" aria-label="Stored send type for {{ $campaign->name }}" hidden>
                                            @foreach (['delay' => 'DELAY', 'delayp' => 'DELAYP', 'delayps' => 'DELAYPS', 'hs' => 'HS', 'hsp' => 'HSP', 'hsps' => 'HSPS', 'pickup' => 'PICKUP', 'pickups' => 'PICKUPS'] as $sendType => $sendTypeLabel)
                                                <option value="{{ $sendType }}" @selected(($register['send_type'] ?? 'delay') === $sendType)>{{ $sendTypeLabel }}</option>
                                            @endforeach
                                        </select>
                                        <form id="campaign-auto-test-{{ $campaign->id }}" method="POST" action="{{ route('console.campaigns.auto-test', [$workspace->id, $campaign->id]) }}" class="d-none" data-campaign-auto-test-form data-logs-url="{{ route('console.campaigns.show', [$workspace->id, $campaign->id]) }}#campaign-test-logs" data-ip-count="{{ count($campaign->latestRevision?->payload['send_setup']['ip_ids'] ?? []) }}" data-send-type-selector="campaign-send-type-{{ $campaign->id }}" data-submit-button="campaign-auto-test-submit-{{ $campaign->id }}">
                                            @csrf
                                            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                        </form>
                                    @endif
                                    <div class="campaign-register-row-actions" role="group" aria-label="Actions for {{ $campaign->name }}">
                                        <a class="btn btn-link btn-sm campaign-register-icon-action" href="{{ route('console.campaigns.show', [$workspace->id, $campaign->id]) }}" title="Open saved draft" aria-label="Open {{ $campaign->name }}"><i class="fas fa-eye" aria-hidden="true"></i></a>
                                        <a class="btn btn-link btn-sm campaign-register-icon-action" href="{{ route('console.campaigns.show', [$workspace->id, $campaign->id]) }}#campaign-test-logs" title="Open campaign test logs" aria-label="Open test logs for {{ $campaign->name }}"><i class="fas fa-history" aria-hidden="true"></i></a>
                                        @if ($campaign->kind === 'transactional')
                                            <a class="btn btn-link btn-sm campaign-register-icon-action" href="{{ route('console.campaigns.show', [$workspace->id, $campaign->id]) }}#test-email" title="Send one test email through Symfony Mailer" aria-label="Open test email for {{ $campaign->name }}"><i class="far fa-paper-plane" aria-hidden="true"></i></a>
                                        @elseif (($register['configured'] ?? false))
                                            <button id="campaign-auto-test-submit-{{ $campaign->id }}" class="btn btn-link btn-sm campaign-register-icon-action" type="submit" form="campaign-auto-test-{{ $campaign->id }}" title="Test saved campaign settings" aria-label="Test {{ $campaign->name }}"><i class="far fa-paper-plane" aria-hidden="true"></i></button>
                                        @else
                                            <button class="btn btn-link btn-sm campaign-register-icon-action" type="button" disabled title="Save Email Test addresses, Header and server settings to test this campaign." aria-label="Test unavailable for {{ $campaign->name }}"><i class="far fa-paper-plane" aria-hidden="true"></i></button>
                                        @endif
                                        @if ($campaign->kind === 'marketing')
                                            <a class="btn btn-link btn-sm campaign-register-icon-action" href="{{ route('console.campaigns.edit', [$workspace->id, $campaign->id]) }}" title="Edit campaign" aria-label="Edit {{ $campaign->name }}"><i class="fas fa-edit" aria-hidden="true"></i></a>
                                        @endif
                                        <button type="button" class="btn btn-link btn-sm campaign-register-icon-action" disabled title="Editing selected IPs is not implemented in AlphaLink." aria-label="Edit IPs unavailable for {{ $campaign->name }}"><i class="fas fa-list" aria-hidden="true"></i></button>
                                        <button type="button" class="btn btn-link btn-sm campaign-register-icon-action" data-campaign-statistics-toggle data-campaign-id="{{ $campaign->id }}" aria-expanded="false" aria-controls="campaign-statistics-{{ $campaign->id }}" data-statistics-url="{{ route('console.campaign-statistics.index', [$workspace->id, 'campaign_id' => $campaign->id, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'inline' => 1]) }}" title="Show campaign statistics and focus analytics" aria-label="Show statistics for {{ $campaign->name }}"><i class="fas fa-chart-bar" aria-hidden="true"></i></button>
                                        @if ($campaign->kind === 'marketing' && ($register['configured'] ?? false))
                                            <a class="btn btn-link btn-sm campaign-register-icon-action" href="{{ route('console.campaigns.clone', [$workspace->id, $campaign->id]) }}" data-campaign-clone-open title="Clone campaign" aria-label="Clone {{ $campaign->name }}" aria-haspopup="dialog"><i class="fas fa-clone" aria-hidden="true"></i></a>
                                        @else
                                            <button type="button" class="btn btn-link btn-sm campaign-register-icon-action" disabled title="Only campaigns with a saved audience setup can be cloned." aria-label="Clone unavailable for {{ $campaign->name }}"><i class="fas fa-clone" aria-hidden="true"></i></button>
                                        @endif
                                        <button type="button" class="btn btn-link btn-sm campaign-register-icon-action {{ $campaign->is_active ? 'campaign-register-icon-action-danger' : 'campaign-register-icon-action-success' }} campaign-status-open" data-campaign-name="{{ $campaign->name }}" data-next-active="{{ $campaign->is_active ? '0' : '1' }}" data-status-url="{{ route('console.campaigns.status', [$workspace->id, $campaign->id]) }}" title="{{ $sendActive ? 'Stop the active send before disabling this campaign.' : ($campaign->is_active ? 'Disable campaign' : 'Restore campaign') }}" aria-label="{{ $sendActive ? 'Stop active send before disabling '.$campaign->name : ($campaign->is_active ? 'Disable ' : 'Restore ').$campaign->name }}" @disabled($sendActive)><i class="{{ $campaign->is_active ? 'fas fa-trash' : 'fas fa-trash-restore' }}" aria-hidden="true"></i></button>
                                    </div>
                                </td>
                                <td data-campaign-column="progress">
                                    @php
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
                                        @if (in_array($sendRun->state, ['planned', 'running'], true))
                                            <div class="small text-muted" data-send-next data-sleep-email="{{ (int) ($register['sleep_email'] ?? 0) }}" data-next-batch-at="{{ $sendRun->execution_options['next_batch_at'] ?? '' }}">{{ $sendRun->state === 'planned' ? 'Waiting for send worker…' : ((int) ($register['sleep_email'] ?? 0) > 0 ? 'Pacing: '.(int) $register['sleep_email'].' seconds between recipients.' : 'Processing next batch…') }}</div>
                                        @endif
                                        @if ($sendRun->last_smtp_error)
                                            <div class="small text-danger">Latest SMTP rejection: {{ $sendRun->last_smtp_error }}</div>
                                        @endif
                                        @if ($sendRun->state === 'failed')
                                            <div class="small text-danger">The sender stopped before completion. Review the latest error and retry only if no recipients were accepted.</div>
                                        @elseif ($sendRun->state === 'needs_review')
                                            <div class="small text-warning">Some SMTP outcomes are uncertain. This revision is locked to prevent duplicate delivery.</div>
                                        @elseif ($sendRun->state === 'stopped')
                                            <div class="small text-muted">Stopped by an operator. Accepted messages remain sent; clone or revise before sending again.</div>
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
                                        <button type="button" class="btn btn-danger btn-sm" data-campaign-stop-open data-campaign-name="{{ $campaign->name }}" data-stop-url="{{ route('console.campaigns.send-stop', [$workspace->id, $campaign->id, $sendRun->id]) }}" aria-label="Stop sending {{ $campaign->name }}"><i class="fas fa-stop mr-1" aria-hidden="true"></i>STOP</button>
                                        <div><span class="badge badge-primary">{{ $sendRun->state === 'planned' ? 'QUEUED' : 'SENDING' }}</span></div>
                                    @elseif ($sendRun && in_array($sendRun->state, ['completed', 'needs_review', 'stopped'], true))
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
                                <td colspan="8" data-campaign-colspan>
                                    <section class="campaign-statistics-dropdown" aria-label="Statistics for {{ $campaign->name }}">
                                        <div data-statistics-content role="status" aria-live="polite">Loading campaign statistics…</div>
                                    </section>
                                </td>
                            </tr>
                        @empty
                            <tr data-campaign-empty-row><td colspan="8" class="text-center text-muted" data-campaign-colspan>No data available in table</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="campaign-register-scroll-note"><i class="fas fa-arrows-left-right" aria-hidden="true"></i> Scroll horizontally to view all campaign fields and actions.</p>

            <div class="d-flex justify-content-end mt-2">{{ $campaigns->links() }}</div>
            <p class="text-muted small mb-0">Showing {{ $campaigns->firstItem() ?? 0 }} to {{ $campaigns->lastItem() ?? 0 }} of {{ $campaigns->total() }} campaigns in the selected date range.</p>
                </main>

                <aside class="campaign-intelligence" aria-label="Campaign intelligence">
                    <section class="campaign-intelligence-card campaign-analytics-card" data-campaign-analytics data-aggregate-url="{{ route('console.campaign-statistics.index', [$workspace->id, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'register' => 1]) }}">
                        <header class="campaign-intelligence-heading">
                            <span class="campaign-intelligence-heading-icon" aria-hidden="true"><i class="fas fa-chart-bar"></i></span>
                            <div><h2>Campaign Analytics</h2><p>Attributed engagement for the selected period</p></div>
                            <label class="sr-only" for="campaign-analytics-select">Select campaign for analytics and Campaign Information</label>
                            <select id="campaign-analytics-select" class="campaign-analytics-select" aria-label="Select campaign for analytics and Campaign Information">
                                <option value="all">All campaigns</option>
                                @foreach ($campaigns as $analyticsCampaign)
                                    <option value="{{ $analyticsCampaign->id }}" data-name="{{ $analyticsCampaign->name }}" data-id="{{ substr($analyticsCampaign->id, 0, 8) }}" data-offer="{{ $analyticsCampaign->send_register['offer'] ?? '—' }}" data-isp="{{ $analyticsCampaign->send_register['isp'] ?? '—' }}" data-server="{{ implode(', ', $analyticsCampaign->send_register['servers'] ?? []) ?: '—' }}" data-send-type="{{ strtoupper($analyticsCampaign->send_register['send_type'] ?? '—') }}" data-send-state="{{ strtoupper(str_replace('_', ' ', $analyticsCampaign->send_run?->state ?? $analyticsCampaign->status)) }}" data-total-send="{{ $analyticsCampaign->send_register['total_send'] ?? '—' }}" data-fraction-ip="{{ $analyticsCampaign->send_register['fraction_ip'] ?? '—' }}" data-sleep-email="{{ $analyticsCampaign->send_register['sleep_email'] ?? '—' }}" data-sleep-ip="{{ $analyticsCampaign->send_register['sleep_ip'] ?? '—' }}" data-seed-every="{{ $analyticsCampaign->send_register['seed_every'] ?? '—' }}" data-send-type-input="campaign-send-type-{{ $analyticsCampaign->id }}" data-send-type-editable="{{ $analyticsCampaign->kind === 'marketing' && ($analyticsCampaign->send_register['configured'] ?? false) ? '1' : '0' }}" data-created="{{ $analyticsCampaign->created_at?->format('M j, Y') ?? '—' }}">{{ $analyticsCampaign->name }}</option>
                                @endforeach
                            </select>
                        </header>
                        <div class="campaign-analytics-period"><i class="far fa-calendar-alt" aria-hidden="true"></i><span>{{ $from->format('M j, Y') }} – {{ $to->format('M j, Y') }}</span></div>
                        <div class="campaign-analytics-metrics" aria-live="polite">
                            <article class="campaign-analytics-metric metric-opens"><span class="metric-icon" aria-hidden="true"><i class="far fa-envelope"></i></span><span><small>Opens</small><strong data-campaign-metric="opens">—</strong></span></article>
                            <article class="campaign-analytics-metric metric-clicks"><span class="metric-icon" aria-hidden="true"><i class="fas fa-mouse-pointer"></i></span><span><small>Clicks</small><strong data-campaign-metric="clicks">—</strong></span></article>
                            <article class="campaign-analytics-metric metric-leads"><span class="metric-icon" aria-hidden="true"><i class="fas fa-users"></i></span><span><small>Leads</small><strong data-campaign-metric="leads">—</strong></span></article>
                            <article class="campaign-analytics-metric metric-sent" title="Recipient attempts accepted by the SMTP transport"><span class="metric-icon" aria-hidden="true"><i class="fas fa-paper-plane"></i></span><span><small>Sent</small><strong data-campaign-metric="sent">—</strong></span></article>
                            <article class="campaign-analytics-metric metric-deliveries" title="Delivery feedback events imported from PMTA"><span class="metric-icon" aria-hidden="true"><i class="fas fa-inbox"></i></span><span><small>Delivered</small><strong data-campaign-metric="deliveries">—</strong></span></article>
                            <article class="campaign-analytics-metric metric-unsubscribes"><span class="metric-icon" aria-hidden="true"><i class="fas fa-user-minus"></i></span><span><small>Unsubs</small><strong data-campaign-metric="unsubscribes">—</strong></span></article>
                            <article class="campaign-analytics-metric metric-bounces"><span class="metric-icon" aria-hidden="true"><i class="fas fa-exclamation-triangle"></i></span><span><small>Bounces</small><strong data-campaign-metric="bounces">—</strong></span></article>
                            <article class="campaign-analytics-metric metric-soft-bounces"><span class="metric-icon" aria-hidden="true"><i class="fas fa-layer-group"></i></span><span><small>Soft Bounces</small><strong data-campaign-metric="soft_bounces">—</strong></span></article>
                        </div>
                        <div class="campaign-chart-heading">
                            <h3>Engagement Over Time</h3>
                            <div class="campaign-chart-tabs" role="group" aria-label="Engagement chart metric">
                                <button type="button" data-campaign-series="opens" aria-pressed="true">Opens</button>
                                <button type="button" data-campaign-series="clicks" aria-pressed="false">Clicks</button>
                                <button type="button" data-campaign-series="leads" aria-pressed="false">Leads</button>
                            </div>
                        </div>
                        <div class="campaign-engagement-chart" data-campaign-engagement-chart role="img" aria-label="Campaign opens over time"></div>
                        <p class="campaign-analytics-status" data-campaign-analytics-status role="status" aria-live="polite">Loading campaign activity…</p>
                        <p class="campaign-analytics-coverage">Sent = SMTP accepted. Delivered and bounce counts use imported PMTA feedback; engagement is unique tracked activity.</p>
                    </section>

                    <section class="campaign-intelligence-card campaign-information-card" aria-labelledby="campaign-information-title">
                        <header class="campaign-intelligence-heading campaign-information-heading">
                            <span class="campaign-intelligence-heading-icon" aria-hidden="true"><i class="fas fa-info-circle"></i></span>
                            <div><h2 id="campaign-information-title" data-campaign-info-title>Campaign Information</h2><p data-campaign-info-name>All campaigns</p></div>
                        </header>
                        <dl class="campaign-information-grid" data-campaign-information-details hidden>
                            <div><dt><i class="fas fa-fingerprint" aria-hidden="true"></i>Campaign ID</dt><dd data-campaign-info="id">All</dd></div>
                            <div><dt><i class="fas fa-calendar-alt" aria-hidden="true"></i>Created</dt><dd data-campaign-info="created">—</dd></div>
                            <div><dt><i class="fas fa-tag" aria-hidden="true"></i>Offer</dt><dd data-campaign-info="offer">—</dd></div>
                            <div><dt><i class="fas fa-paper-plane" aria-hidden="true"></i>Send type</dt><dd><span data-campaign-info="send-type">—</span><select class="campaign-information-send-type" data-campaign-info-send-type aria-label="Send type for selected campaign" hidden disabled>
                                @foreach (['delay' => 'DELAY', 'delayp' => 'DELAYP', 'delayps' => 'DELAYPS', 'hs' => 'HS', 'hsp' => 'HSP', 'hsps' => 'HSPS', 'pickup' => 'PICKUP', 'pickups' => 'PICKUPS'] as $sendType => $sendTypeLabel)
                                    <option value="{{ $sendType }}">{{ $sendTypeLabel }}</option>
                                @endforeach
                            </select></dd></div>
                            <div><dt><i class="fas fa-globe" aria-hidden="true"></i>ISP</dt><dd data-campaign-info="isp">—</dd></div>
                            <div><dt><i class="fas fa-server" aria-hidden="true"></i>Server</dt><dd data-campaign-info="server">—</dd></div>
                            <div><dt><i class="fas fa-users" aria-hidden="true"></i>Total to send</dt><dd data-campaign-info="total-send">—</dd></div>
                            <div><dt><i class="fas fa-layer-group" aria-hidden="true"></i>IP fraction</dt><dd data-campaign-info="fraction-ip">—</dd></div>
                            <div><dt><i class="far fa-clock" aria-hidden="true"></i>Email wait (s)</dt><dd data-campaign-info="sleep-email">—</dd></div>
                            <div><dt><i class="far fa-clock" aria-hidden="true"></i>IP wait (s)</dt><dd data-campaign-info="sleep-ip">—</dd></div>
                            <div><dt><i class="fas fa-seedling" aria-hidden="true"></i>Seed every</dt><dd data-campaign-info="seed-every">—</dd></div>
                            <div><dt><i class="fas fa-wave-square" aria-hidden="true"></i>Latest status</dt><dd data-campaign-info="send-state">—</dd></div>
                        </dl>
                        <dl class="campaign-information-grid campaign-aggregate-grid" data-campaign-aggregate-summary>
                            <div><dt><i class="fas fa-paper-plane" aria-hidden="true"></i>Sent (SMTP accepted)</dt><dd data-campaign-aggregate-metric="sent">—</dd></div>
                            <div><dt><i class="fas fa-inbox" aria-hidden="true"></i>Delivered (PMTA)</dt><dd data-campaign-aggregate-metric="deliveries">—</dd></div>
                            <div><dt><i class="far fa-envelope" aria-hidden="true"></i>Opens</dt><dd data-campaign-aggregate-metric="opens">—</dd></div>
                            <div><dt><i class="fas fa-mouse-pointer" aria-hidden="true"></i>Clicks</dt><dd data-campaign-aggregate-metric="clicks">—</dd></div>
                            <div><dt><i class="fas fa-user-minus" aria-hidden="true"></i>Unsubscribes</dt><dd data-campaign-aggregate-metric="unsubscribes">—</dd></div>
                            <div><dt><i class="fas fa-users" aria-hidden="true"></i>Leads</dt><dd data-campaign-aggregate-metric="leads">—</dd></div>
                            <div><dt><i class="fas fa-exclamation-triangle" aria-hidden="true"></i>Bounces</dt><dd data-campaign-aggregate-metric="bounces">—</dd></div>
                            <div><dt><i class="fas fa-layer-group" aria-hidden="true"></i>Soft bounces</dt><dd data-campaign-aggregate-metric="soft_bounces">—</dd></div>
                        </dl>
                    </section>
                </aside>
            </div>
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

    <dialog id="campaign-stop-dialog" aria-labelledby="campaign-stop-title" aria-describedby="campaign-stop-description" class="campaign-status-dialog">
        <h2 id="campaign-stop-title">Stop campaign send</h2>
        <p id="campaign-stop-description"></p>
        <form id="campaign-stop-form" method="POST" action="">
            @csrf
            <div class="d-flex justify-content-end">
                <button type="button" class="btn btn-alt-secondary mr-2" id="campaign-stop-cancel">Keep sending</button>
                <button type="submit" class="btn btn-danger" id="campaign-stop-confirm">Stop sending</button>
            </div>
        </form>
    </dialog>

    <dialog id="campaign-action-dialog" aria-labelledby="campaign-action-title" aria-describedby="campaign-action-description" class="campaign-status-dialog">
        <h2 id="campaign-action-title"></h2>
        <p id="campaign-action-description"></p>
        <div class="d-flex justify-content-end">
            <button type="button" class="btn btn-alt-secondary mr-2" id="campaign-action-cancel">Cancel</button>
            <button type="button" class="btn btn-primary" id="campaign-action-confirm">Continue</button>
        </div>
    </dialog>

    <dialog id="campaign-bulk-dialog" aria-labelledby="campaign-bulk-title" aria-describedby="campaign-bulk-description" class="campaign-status-dialog">
        <h2 id="campaign-bulk-title">Disable selected campaigns</h2>
        <p id="campaign-bulk-description">The selected drafts will be removed from the active campaign register. Stop any active send before disabling its campaign.</p>
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
    <link rel="stylesheet" href="{{ asset('console-assets/vendor/daterangepicker/daterangepicker.css') }}">
    <style>
        body.command-os-shell .campaign-register-page-header { position: relative; isolation: isolate; overflow: hidden; min-height: 124px; background: linear-gradient(105deg, color-mix(in srgb, var(--co-purple) 9%, var(--co-surface)), var(--co-surface) 52%, color-mix(in srgb, var(--co-blue) 8%, var(--co-surface))); }
        body.command-os-shell .campaign-register-page-header::before { position: absolute; z-index: -1; inset: -65% -5% -55% 28%; border-radius: 50%; background: radial-gradient(ellipse at 52% 50%, color-mix(in srgb, var(--co-purple-bright) 22%, transparent), transparent 57%), radial-gradient(ellipse at 73% 54%, color-mix(in srgb, var(--co-blue) 22%, transparent), transparent 48%); content: ""; animation: campaign-hero-glow 9s ease-in-out infinite alternate; }
        body.command-os-shell .campaign-register-page-header::after { position: absolute; z-index: -1; right: -5%; bottom: -42px; left: 30%; height: 94px; border-top: 1px solid color-mix(in srgb, var(--co-purple) 18%, transparent); border-radius: 50% 50% 0 0; background: linear-gradient(180deg, color-mix(in srgb, var(--co-purple) 7%, transparent), transparent 78%); content: ""; transform: rotate(-2deg); animation: campaign-hero-wave 12s ease-in-out infinite alternate; }
        .campaign-hero-illustration { position: absolute; z-index: -1; top: 23px; right: 17%; display: grid; width: 94px; height: 72px; place-items: center; color: color-mix(in srgb, var(--co-purple) 60%, var(--co-blue)); filter: drop-shadow(0 12px 13px color-mix(in srgb, var(--co-purple) 28%, transparent)); opacity: .42; transform: rotate(-15deg); }
        .campaign-hero-illustration i { font-size: 58px; }
        @keyframes campaign-hero-glow { from { transform: translateX(-1.5%) scale(.98); } to { transform: translateX(1.5%) scale(1.03); } }
        @keyframes campaign-hero-wave { from { transform: translateX(-1%) rotate(-2deg); } to { transform: translateX(1%) rotate(1deg); } }
        .campaign-workspace-grid { display: grid; grid-template-columns: minmax(0, 2.05fr) minmax(350px, 1fr); align-items: start; gap: 14px; }
        .campaign-register-main { min-width: 0; }
        .campaign-intelligence { display: grid; gap: 12px; min-width: 0; }
        .campaign-overview { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 9px; margin: 1px 0 13px; }
        .campaign-overview-card { display: flex; align-items: center; gap: 9px; min-width: 0; min-height: 83px; padding: 10px; border: 1px solid var(--co-border); border-radius: 13px; background: linear-gradient(145deg, color-mix(in srgb, var(--co-purple) 4%, var(--co-surface)), var(--co-surface)); box-shadow: 0 5px 16px color-mix(in srgb, var(--co-purple) 7%, transparent); }
        .campaign-overview-icon { display: grid; flex: 0 0 38px; width: 38px; height: 38px; place-items: center; border: 1px solid color-mix(in srgb, currentColor 14%, transparent); border-radius: 50%; color: var(--co-purple); background: color-mix(in srgb, var(--co-purple) 11%, var(--co-surface)); font-size: 15px; }
        .campaign-overview-copy { display: grid; gap: 1px; min-width: 0; }
        .campaign-overview-copy > span { overflow: hidden; color: var(--co-muted); font-size: 9px; font-weight: 750; text-overflow: ellipsis; white-space: nowrap; }
        .campaign-overview-copy strong { color: var(--co-ink); font-size: 20px; font-weight: 800; line-height: 1.15; font-variant-numeric: tabular-nums; }
        .campaign-overview-copy small { overflow: hidden; color: var(--co-subtle); font-size: 8px; text-overflow: ellipsis; white-space: nowrap; }
        .campaign-overview-active .campaign-overview-icon { color: #0ea66d; background: color-mix(in srgb, #16bd82 12%, var(--co-surface)); }
        .campaign-overview-paused .campaign-overview-icon { color: #964cf3; background: color-mix(in srgb, #964cf3 11%, var(--co-surface)); }
        .campaign-overview-completed .campaign-overview-icon { color: #168bf0; background: color-mix(in srgb, #168bf0 11%, var(--co-surface)); }
        .campaign-overview-issues .campaign-overview-icon { color: #ec4869; background: color-mix(in srgb, #ec4869 11%, var(--co-surface)); }
        .campaign-intelligence-card { overflow: hidden; border: 1px solid var(--co-border); border-radius: 15px; color: var(--co-ink); background: linear-gradient(145deg, var(--co-surface), color-mix(in srgb, var(--co-purple) 3%, var(--co-surface))); box-shadow: var(--co-shadow); }
        .campaign-intelligence-heading { display: flex; align-items: center; gap: 9px; min-width: 0; padding: 12px 13px 10px; border-bottom: 1px solid var(--co-border); }
        .campaign-intelligence-heading-icon { display: grid; flex: 0 0 32px; width: 32px; height: 32px; place-items: center; border: 1px solid color-mix(in srgb, var(--co-purple) 18%, var(--co-border)); border-radius: 10px; color: var(--co-purple); background: color-mix(in srgb, var(--co-purple) 9%, var(--co-surface)); font-size: 14px; }
        .campaign-intelligence-heading > div { min-width: 0; }
        .campaign-intelligence-heading h2 { margin: 0; color: var(--co-ink); font-size: 13px; font-weight: 800; letter-spacing: -.02em; }
        .campaign-intelligence-heading p { overflow: hidden; margin: 3px 0 0; color: var(--co-muted); font-size: 8px; text-overflow: ellipsis; white-space: nowrap; }
        .campaign-analytics-select { flex: 0 1 145px; min-width: 94px; height: 32px; margin-left: auto; padding: 0 8px; border: 1px solid var(--co-border-strong); border-radius: 9px; color: var(--co-ink); background: var(--co-surface); font-size: 9px; }
        .campaign-analytics-period { display: flex; align-items: center; gap: 7px; margin: 10px 12px; color: var(--co-muted); font-size: 9px; }
        .campaign-analytics-period i { color: var(--co-purple); }
        .campaign-analytics-metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 7px; padding: 0 11px 11px; }
        .campaign-analytics-metric { display: flex; align-items: center; gap: 7px; min-width: 0; min-height: 58px; padding: 8px; border: 1px solid color-mix(in srgb, var(--co-border) 86%, transparent); border-radius: 11px; background: color-mix(in srgb, var(--co-surface) 92%, var(--co-purple) 8%); }
        .campaign-analytics-metric .metric-icon { display: grid; flex: 0 0 30px; width: 30px; height: 30px; place-items: center; border-radius: 50%; color: #803cfa; background: color-mix(in srgb, #9a55ff 13%, var(--co-surface)); font-size: 12px; }
        .campaign-analytics-metric > span:last-child { display: grid; min-width: 0; }
        .campaign-analytics-metric small { color: var(--co-muted); font-size: 8px; font-weight: 700; white-space: nowrap; }
        .campaign-analytics-metric strong { overflow: hidden; color: var(--co-ink); font-size: 15px; font-weight: 800; text-overflow: ellipsis; font-variant-numeric: tabular-nums; }
        .campaign-analytics-metric.metric-clicks .metric-icon { color: #168bef; background: color-mix(in srgb, #168bef 12%, var(--co-surface)); }
        .campaign-analytics-metric.metric-leads .metric-icon { color: #9a42ec; background: color-mix(in srgb, #9a42ec 12%, var(--co-surface)); }
        .campaign-analytics-metric.metric-unsubscribes .metric-icon { color: #d34395; background: color-mix(in srgb, #d34395 12%, var(--co-surface)); }
        .campaign-analytics-metric.metric-bounces .metric-icon { color: #e84a69; background: color-mix(in srgb, #e84a69 12%, var(--co-surface)); }
        .campaign-analytics-metric.metric-soft-bounces .metric-icon { color: #824be6; background: color-mix(in srgb, #824be6 12%, var(--co-surface)); }
        .campaign-analytics-metric.metric-sent .metric-icon { color: #168bef; background: color-mix(in srgb, #168bef 12%, var(--co-surface)); }
        .campaign-analytics-metric.metric-deliveries .metric-icon { color: #19a974; background: color-mix(in srgb, #19a974 12%, var(--co-surface)); }
        .campaign-chart-heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; padding: 0 12px 8px; }
        .campaign-chart-heading h3 { margin: 0; color: var(--co-ink); font-size: 11px; font-weight: 800; }
        .campaign-chart-tabs { display: inline-flex; gap: 3px; padding: 3px; border: 1px solid var(--co-border); border-radius: 10px; background: var(--co-surface-muted); }
        .campaign-chart-tabs button { min-height: 25px; padding: 0 9px; border: 0; border-radius: 7px; color: var(--co-muted); background: transparent; font-size: 8px; font-weight: 750; cursor: pointer; }
        .campaign-chart-tabs button[aria-pressed="true"] { color: #fff; background: linear-gradient(110deg, var(--co-purple-bright), var(--co-purple)); box-shadow: 0 3px 8px color-mix(in srgb, var(--co-purple) 22%, transparent); }
        .campaign-chart-tabs button:focus-visible,
        .campaign-analytics-select:focus-visible { outline: 2px solid var(--co-purple); outline-offset: 2px; }
        .campaign-engagement-chart { width: 100%; height: 188px; }
        .campaign-analytics-status { min-height: 15px; margin: -2px 12px 5px; color: var(--co-muted); font-size: 8px; }
        .campaign-analytics-status:empty { display: none; }
        .campaign-analytics-coverage { margin: 0; padding: 9px 12px 11px; border-top: 1px solid var(--co-border); color: var(--co-subtle); font-size: 8px; line-height: 1.5; }
        .campaign-information-heading { border-bottom: 0; }
        .campaign-information-heading p { color: var(--co-purple); font-weight: 750; }
        .campaign-information-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 12px; margin: 0; padding: 0 13px 13px; }
        .campaign-information-grid[hidden] { display: none !important; }
        .campaign-information-grid > div { display: grid; grid-template-columns: minmax(78px, .8fr) minmax(0, 1.2fr); align-items: center; gap: 6px; min-width: 0; padding: 9px 0; border-top: 1px solid color-mix(in srgb, var(--co-border) 76%, transparent); }
        .campaign-information-grid dt { display: flex; align-items: center; gap: 6px; min-width: 0; color: var(--co-muted); font-size: 8px; font-weight: 700; }
        .campaign-information-grid dt i { color: var(--co-purple); }
        .campaign-information-grid dd { overflow: hidden; margin: 0; color: var(--co-ink); font-size: 8px; font-weight: 750; text-overflow: ellipsis; white-space: nowrap; }
        .campaign-information-grid dd [hidden] { display: none !important; }
        .campaign-information-send-type { width: 100%; min-width: 0; height: 27px; padding: 0 7px; border: 1px solid var(--co-border-strong); border-radius: 8px; color: var(--co-ink); background: var(--co-surface); font: inherit; font-weight: 750; }
        .campaign-information-send-type:focus-visible { outline: 2px solid var(--co-purple); outline-offset: 2px; }
        body.command-os-shell .campaign-register-page {
            margin: 0 0 18px;
            overflow: hidden;
            border: 1px solid var(--co-border);
            border-radius: 17px;
            color: var(--co-ink);
            background: var(--co-surface);
            box-shadow: var(--co-shadow);
            --campaign-action-danger: #cf4054;
            --campaign-action-success: #15845e;
        }
        html[data-command-theme='dark'] body.command-os-shell .campaign-register-page {
            --campaign-action-danger: #ff8497;
            --campaign-action-success: #62ddb1;
        }
        body.command-os-shell .campaign-register-page-header {
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            min-height: 88px;
            padding: 15px 20px;
            border-bottom: 1px solid var(--co-border);
            background: linear-gradient(105deg, color-mix(in srgb, var(--co-purple) 8%, var(--co-surface)), var(--co-surface) 48%, color-mix(in srgb, var(--co-blue) 6%, var(--co-surface)));
        }
        .campaign-register-heading { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .campaign-register-heading-icon {
            display: grid;
            flex: 0 0 46px;
            width: 46px;
            height: 46px;
            place-items: center;
            border: 1px solid color-mix(in srgb, var(--co-purple) 24%, var(--co-border));
            border-radius: 14px;
            color: #fff;
            background: linear-gradient(145deg, var(--co-purple-bright), var(--co-purple));
            box-shadow: 0 7px 18px color-mix(in srgb, var(--co-purple) 24%, transparent);
            font-size: 18px;
        }
        .campaign-register-heading-copy { min-width: 0; }
        .campaign-register-eyebrow { color: var(--co-purple); font-size: 9px; font-weight: 800; letter-spacing: .14em; }
        body.command-os-shell .campaign-register-page .campaign-register-heading-copy .block-title {
            margin: 2px 0 3px;
            color: var(--co-ink);
            font-size: 20px;
            font-weight: 780;
            letter-spacing: -.04em;
            line-height: 1.15;
        }
        .campaign-register-heading-copy p { margin: 0; color: var(--co-muted); font-size: 10px; }
        .campaign-register-page .block-options { flex: 0 0 auto; gap: 12px; margin: 0; }
        .campaign-register-page .block-options form { margin: 0; }
        .campaign-register-page .custom-control-label { color: var(--co-muted); font-size: 9px; font-weight: 750; letter-spacing: .06em; white-space: nowrap; }
        .campaign-register-page .custom-control-label::before { border-color: var(--co-border-strong); background: var(--co-surface-muted); box-shadow: none; }
        .campaign-register-page .custom-control-input:checked ~ .custom-control-label::before { border-color: var(--co-purple); background-color: var(--co-purple); }
        .campaign-register-page .btn-block-option { display: grid; width: 34px; height: 34px; place-items: center; border: 1px solid var(--co-border); border-radius: 10px; color: var(--co-muted); background: var(--co-surface); }
        .campaign-register-page .btn-block-option:hover { border-color: var(--co-purple); color: var(--co-purple); }
        body.command-os-shell .campaign-register-page > .block-content { padding: 16px 18px 18px; }
        .campaign-register-toolbar {
            gap: 10px 14px;
            margin: 0 0 14px !important;
            padding: 10px;
            border: 1px solid var(--co-border);
            border-radius: 13px;
            background: linear-gradient(110deg, color-mix(in srgb, var(--co-purple) 4%, var(--co-surface-muted)), var(--co-surface-muted));
        }
        .campaign-register-actions { flex: 0 1 auto; flex-wrap: wrap; gap: 7px; min-width: 0; }
        .campaign-register-actions form { margin: 0 !important; }
        .campaign-register-actions .form-control,
        .campaign-register-filters .form-control {
            height: 35px;
            border: 1px solid var(--co-border-strong);
            border-radius: 9px;
            color: var(--co-ink);
            background-color: var(--co-surface);
            box-shadow: none;
            font-size: 10px;
        }
        .campaign-register-actions .form-control:focus,
        .campaign-register-filters .form-control:focus { border-color: var(--co-purple); box-shadow: 0 0 0 3px color-mix(in srgb, var(--co-purple) 12%, transparent); }
        .campaign-register-actions .form-control { min-width: 126px; }
        .campaign-register-page .campaign-register-create,
        .campaign-register-page .campaign-register-active,
        .campaign-register-page .campaign-register-icon-action,
        .campaign-register-page .campaign-register-filters > button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 35px;
            border-radius: 9px;
            font-size: 10px;
            font-weight: 700;
            text-decoration: none;
        }
        .campaign-register-page .campaign-register-create { gap: 7px; padding: 0 13px; border: 1px solid var(--co-purple); color: #fff; background: linear-gradient(105deg, var(--co-purple-bright), var(--co-purple)); box-shadow: 0 4px 10px color-mix(in srgb, var(--co-purple) 20%, transparent); }
        .campaign-register-page .campaign-register-create:hover { color: #fff; filter: brightness(1.04); transform: translateY(-1px); }
        .campaign-register-page .campaign-register-active { padding: 0 10px; border: 1px solid var(--co-border-strong); }
        .campaign-register-page .campaign-register-active.btn-alt-secondary { color: var(--co-ink); background: var(--co-surface); }
        .campaign-register-page .campaign-register-active.btn-primary { border-color: var(--co-purple); color: #fff; background: var(--co-purple); }
        .campaign-register-page .campaign-register-icon-action { width: 35px; padding: 0; border: 1px solid var(--co-border-strong); color: var(--co-muted); background: var(--co-surface); }
        .campaign-register-page .campaign-register-icon-action:hover:not(:disabled) { border-color: var(--co-purple); color: var(--co-purple); }
        .campaign-register-page .campaign-register-icon-action:disabled { opacity: .45; }
        .campaign-register-filters { display: flex; flex: 1 1 560px; align-items: center; justify-content: flex-end; gap: 7px; min-width: 0; margin: 0 !important; }
        .campaign-register-filters > label:not(.sr-only) { margin: 0 !important; color: var(--co-muted); font-size: 10px; font-weight: 700; }
        .campaign-register-filters .campaign-date-range { width: 190px; margin: 0 !important; }
        .campaign-register-filters .campaign-search-control { flex: 0 1 220px; min-width: 130px; }
        .campaign-register-filters input[type="search"] { width: 100%; max-width: none; padding-right: 30px; }
        .campaign-search-clear { color: var(--co-muted); }
        .campaign-register-page .campaign-register-filters > button { width: 35px; padding: 0; border: 1px solid var(--co-border-strong); color: var(--co-muted); background: var(--co-surface); }
        .campaign-register-page .campaign-register-filters > button:hover { border-color: var(--co-purple); color: var(--co-purple); }
        .campaign-register-test-link { display: inline-flex; align-items: center; gap: 6px; padding: 7px 9px; border-radius: 8px; color: var(--co-purple); background: color-mix(in srgb, var(--co-purple) 7%, var(--co-surface)); font-size: 10px; font-weight: 700; white-space: nowrap; }
        .campaign-register-test-link:hover { color: var(--co-purple-bright); text-decoration: none; }
        .campaign-register-page .alert { border-radius: 11px; }
        .campaign-register-table { font-size: .8rem; min-width: 1080px; }
        .campaign-register-table-wrap { overflow: auto; border: 1px solid var(--co-border); border-radius: 13px; background: var(--co-surface); box-shadow: var(--co-shadow); scrollbar-color: var(--co-border-strong) transparent; }
        .campaign-register-table { margin: 0; border-collapse: separate; border-spacing: 0; color: var(--co-ink); font-size: 10px; }
        .campaign-register-table th { position: sticky; top: 0; z-index: 2; padding: 11px 9px; border-top: 0; border-bottom: 1px solid var(--co-border); color: var(--co-muted); background: var(--co-surface-muted); font-size: 8px; font-weight: 800; letter-spacing: .07em; white-space: nowrap; }
        .campaign-register-table td { padding: 10px 9px; border-top: 1px solid color-mix(in srgb, var(--co-border) 76%, transparent); color: var(--co-ink); vertical-align: middle; }
        .campaign-register-table tbody tr:first-child td { border-top: 0; }
        .campaign-register-table tbody tr:hover td { background: color-mix(in srgb, var(--co-purple) 4%, var(--co-surface)); }
        .campaign-register-table [data-campaign-column="campaign"] { min-width: 138px; }
        .campaign-register-table [data-campaign-column="offer"] { min-width: 220px; max-width: 300px; line-height: 1.4; }
        .campaign-register-table [data-campaign-column="campaign"] a { color: var(--co-purple); font-size: 10px; font-weight: 800; }
        .campaign-register-table [data-campaign-column="campaign"] .small { display: block; max-width: 170px; margin-top: 3px; color: var(--co-muted) !important; font-size: 9px; line-height: 1.4; }
        .campaign-register-table [data-campaign-column="actions"] { min-width: 142px; }
        .campaign-register-row-actions { display: grid; grid-template-columns: repeat(4, 30px); gap: 4px; justify-content: start; width: max-content; max-width: 100%; }
        .campaign-register-page .campaign-register-row-actions .campaign-register-icon-action { display: inline-flex; align-items: center; justify-content: center; width: 30px; min-width: 30px; height: 30px; min-height: 30px; margin: 0; padding: 0; border: 1px solid color-mix(in srgb, var(--co-purple) 24%, var(--co-border)); border-radius: 9px; color: var(--co-purple); background: linear-gradient(145deg, color-mix(in srgb, var(--co-purple) 13%, var(--co-surface)), color-mix(in srgb, var(--co-blue) 9%, var(--co-surface))); box-shadow: 0 2px 6px color-mix(in srgb, var(--co-purple) 12%, transparent); cursor: pointer; text-decoration: none; transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease, background .16s ease; }
        .campaign-register-page .campaign-register-row-actions .campaign-register-icon-action i { color: currentColor; font-size: 12px; line-height: 1; }
        .campaign-register-page .campaign-register-row-actions .campaign-register-icon-action:hover:not(:disabled) { border-color: color-mix(in srgb, var(--co-purple) 54%, var(--co-border)); color: var(--co-purple-bright); background: linear-gradient(145deg, color-mix(in srgb, var(--co-purple) 22%, var(--co-surface)), color-mix(in srgb, var(--co-blue) 16%, var(--co-surface))); box-shadow: 0 4px 10px color-mix(in srgb, var(--co-purple) 20%, transparent); transform: translateY(-1px); }
        .campaign-register-page .campaign-register-row-actions .campaign-register-icon-action:focus-visible { outline: 3px solid color-mix(in srgb, var(--co-purple) 32%, transparent); outline-offset: 2px; }
        .campaign-register-page .campaign-register-row-actions .campaign-register-icon-action:disabled { cursor: not-allowed; opacity: .48; box-shadow: none; }
        .campaign-register-page .campaign-register-row-actions .campaign-register-icon-action-danger { color: var(--campaign-action-danger); background: linear-gradient(145deg, color-mix(in srgb, var(--campaign-action-danger) 12%, var(--co-surface)), color-mix(in srgb, var(--campaign-action-danger) 7%, var(--co-surface))); border-color: color-mix(in srgb, var(--campaign-action-danger) 28%, var(--co-border)); }
        .campaign-register-page .campaign-register-row-actions .campaign-register-icon-action-danger:hover:not(:disabled) { color: var(--campaign-action-danger); background: linear-gradient(145deg, color-mix(in srgb, var(--campaign-action-danger) 19%, var(--co-surface)), color-mix(in srgb, var(--campaign-action-danger) 12%, var(--co-surface))); border-color: color-mix(in srgb, var(--campaign-action-danger) 50%, var(--co-border)); }
        .campaign-register-page .campaign-register-row-actions .campaign-register-icon-action-success { color: var(--campaign-action-success); background: linear-gradient(145deg, color-mix(in srgb, var(--campaign-action-success) 12%, var(--co-surface)), color-mix(in srgb, var(--co-blue) 7%, var(--co-surface))); border-color: color-mix(in srgb, var(--campaign-action-success) 28%, var(--co-border)); }
        .campaign-register-page .campaign-register-row-actions .campaign-register-icon-action-success:hover:not(:disabled) { color: var(--campaign-action-success); background: linear-gradient(145deg, color-mix(in srgb, var(--campaign-action-success) 19%, var(--co-surface)), color-mix(in srgb, var(--co-blue) 12%, var(--co-surface))); border-color: color-mix(in srgb, var(--campaign-action-success) 50%, var(--co-border)); }
        @media (prefers-reduced-motion: reduce) {
            .campaign-register-page .campaign-register-row-actions .campaign-register-icon-action { transition: none; }
            .campaign-register-page .campaign-register-row-actions .campaign-register-icon-action:hover:not(:disabled) { transform: none; }
        }
        .campaign-register-table [data-campaign-column="send"],
        .campaign-register-table [data-campaign-column="progress"] { min-width: 160px; }
        .campaign-register-table th[data-campaign-column="send"],
        .campaign-register-table td[data-campaign-column="send"] { position: sticky; right: 0; z-index: 1; background: var(--co-surface); box-shadow: -8px 0 12px color-mix(in srgb, var(--co-ink) 6%, transparent); }
        .campaign-register-table th[data-campaign-column="send"] { z-index: 3; background: var(--co-surface-muted); }
        .campaign-register-table tbody tr:hover td[data-campaign-column="send"] { background: color-mix(in srgb, var(--co-purple) 4%, var(--co-surface)); }
        .campaign-register-table .form-control { min-width: 94px; height: 30px; border: 1px solid var(--co-border); border-radius: 8px; color: var(--co-ink); background: var(--co-surface); font-size: 9px; }
        .campaign-register-table .badge { border-radius: 20px; font-size: 8px; font-weight: 750; letter-spacing: .025em; }
        .campaign-register-table .badge-primary { color: var(--co-purple); background: color-mix(in srgb, var(--co-purple) 12%, var(--co-surface)); }
        .campaign-register-table .badge-secondary { color: var(--co-muted); background: color-mix(in srgb, var(--co-muted) 12%, var(--co-surface)); }
        .campaign-register-table .badge-warning { color: #a65d00; background: #fff2d8; }
        .campaign-register-table .badge-success { color: #08784e; background: #def8ec; }
        .campaign-register-table .badge-danger { color: #b52c49; background: #ffe5eb; }
        .campaign-register-table .progress { overflow: hidden; border-radius: 20px; background: var(--co-surface-muted); }
        .campaign-register-table .progress-bar { background: linear-gradient(90deg, var(--co-blue), var(--co-purple-bright)); }
        .campaign-register-scroll-note { display: none; margin: 9px 2px 0; color: var(--co-subtle); font-size: 9px; }
        .campaign-statistics-dropdown { padding: 12px; border: 1px solid var(--co-border); border-top: 0; color: var(--co-ink); background: var(--co-surface-muted); }
        .campaign-statistics-table { font-size: .78rem; margin-bottom: 0; table-layout: fixed; width: 100%; }
        .campaign-statistics-table thead th { border-top: 0; color: var(--co-muted); font-size: 8px; font-weight: 800; padding: .55rem .6rem .35rem; white-space: nowrap; }
        .campaign-statistics-table td { color: var(--co-ink); font-variant-numeric: tabular-nums; padding: .35rem .6rem .55rem; }
        .campaign-statistics-empty { color: var(--co-muted); padding: .65rem .6rem; }
        .campaign-register-toolbar .btn[disabled] { cursor: not-allowed; opacity: .55; }
        .campaign-register-filters .campaign-search-control { position: relative; }
        .campaign-search-clear { align-items: center; background: transparent; border: 0; color: #77818a; display: inline-flex; height: 100%; justify-content: center; padding: 0 .45rem; position: absolute; right: 0; top: 0; }
        .campaign-search-clear[hidden] { display: none; }
        .campaign-search-clear:focus-visible { outline: 2px solid var(--co-purple); outline-offset: 2px; }
        .campaign-status-dialog,
        .campaign-clone-dialog { border: 1px solid var(--co-border-strong); border-radius: 16px; color: var(--co-ink); background: var(--co-surface); box-shadow: 0 24px 80px rgb(13 20 48 / 25%); }
        .campaign-status-dialog { max-width: 32rem; padding: 1.4rem; width: min(92vw, 32rem); }
        .campaign-status-dialog::backdrop,
        .campaign-clone-dialog::backdrop { background: rgb(8 13 31 / 58%); backdrop-filter: blur(4px); }
        .campaign-status-dialog h2 { margin: 0 0 .7rem; color: var(--co-ink); font-size: 17px; font-weight: 760; letter-spacing: -.025em; }
        .campaign-status-dialog p { color: var(--co-muted); font-size: 12px; line-height: 1.6; }
        .campaign-status-dialog .btn { border-radius: 9px; font-size: 11px; font-weight: 700; }
        .campaign-clone-dialog { max-height: min(90vh, 56rem); max-width: 56rem; overflow: auto; padding: 1.25rem; width: min(94vw, 56rem); }
        .campaign-clone-dialog-header { align-items: center; border-bottom: 1px solid var(--co-border); display: flex; justify-content: space-between; margin: -0.25rem 0 1rem; padding-bottom: .7rem; }
        .campaign-clone-dialog-header h2 { color: var(--co-ink); font-size: 15px; font-weight: 760; margin: 0; }
        .campaign-clone-form-content .form-group { margin-bottom: 1rem; }
        /* Keep every campaign overlay inside the Command OS visual system. */
        .campaign-status-dialog,
        .campaign-clone-dialog {
            max-height: min(90dvh, 56rem);
            overscroll-behavior: contain;
            scrollbar-color: var(--co-border-strong) transparent;
        }
        .campaign-status-dialog[open],
        .campaign-clone-dialog[open] { animation: campaign-dialog-enter .18s cubic-bezier(.2,.8,.2,1) both; }
        @keyframes campaign-dialog-enter {
            from { opacity: 0; transform: translateY(8px) scale(.985); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .campaign-status-dialog::backdrop,
        .campaign-clone-dialog::backdrop { background: rgb(8 13 31 / 64%); backdrop-filter: blur(6px); }
        .campaign-status-dialog form > .d-flex,
        .campaign-status-dialog > .d-flex { gap: 8px; margin-top: 1.15rem; }
        .campaign-status-dialog .btn-alt-secondary,
        .campaign-clone-dialog .btn-alt-secondary {
            border: 1px solid var(--co-border-strong);
            color: var(--co-ink);
            background: var(--co-surface-muted);
        }
        .campaign-status-dialog .btn-primary,
        .campaign-clone-dialog #clone-submit {
            border-color: var(--co-purple);
            color: #fff;
            background: linear-gradient(110deg, var(--co-purple-bright), var(--co-purple));
            box-shadow: 0 5px 14px color-mix(in srgb, var(--co-purple) 22%, transparent);
        }
        .campaign-status-dialog .btn-danger { border-color: #d43e60; color: #fff; background: #d43e60; }
        .campaign-status-dialog .btn:focus-visible,
        .campaign-clone-dialog .btn:focus-visible { outline: 3px solid color-mix(in srgb, var(--co-purple) 35%, transparent); outline-offset: 2px; }
        .campaign-clone-dialog { padding: clamp(16px, 2.4vw, 26px); }
        .campaign-clone-dialog-header {
            position: sticky;
            z-index: 2;
            top: calc(-1 * clamp(16px, 2.4vw, 26px));
            margin: calc(-1 * clamp(16px, 2.4vw, 26px)) calc(-1 * clamp(16px, 2.4vw, 26px)) 18px;
            padding: 18px clamp(16px, 2.4vw, 26px) 14px;
            background: linear-gradient(105deg, color-mix(in srgb, var(--co-purple) 9%, var(--co-surface)), var(--co-surface) 72%);
            backdrop-filter: blur(16px);
        }
        .campaign-clone-dialog-header h2 { display: flex; align-items: center; gap: 9px; font-size: 16px; letter-spacing: -.025em; }
        .campaign-clone-dialog-header h2::before {
            display: grid;
            width: 34px;
            height: 34px;
            place-items: center;
            border-radius: 10px;
            color: #fff;
            background: linear-gradient(145deg, var(--co-purple-bright), var(--co-purple));
            content: "⧉";
            font-size: 17px;
        }
        .campaign-clone-dialog-header .btn { display: grid; width: 34px; height: 34px; place-items: center; padding: 0; border-radius: 10px; }
        .campaign-clone-form-content > .alert { margin-bottom: 16px; padding: 12px 14px; border: 1px solid var(--co-border); border-radius: 11px; color: var(--co-muted); background: var(--co-surface-muted); font-size: 11px; line-height: 1.55; }
        .campaign-clone-form-content > .alert-info { border-color: color-mix(in srgb, var(--co-blue) 24%, var(--co-border)); color: var(--co-ink); background: color-mix(in srgb, var(--co-blue) 7%, var(--co-surface)); }
        .campaign-clone-form-content > .alert-danger { border-color: color-mix(in srgb, #d43e60 30%, var(--co-border)); background: color-mix(in srgb, #d43e60 7%, var(--co-surface)); }
        .campaign-clone-form-content form { margin: 0; }
        .campaign-clone-form-content label { margin-bottom: 6px; color: var(--co-muted); font-size: 10px; font-weight: 750; }
        .campaign-clone-form-content .form-control,
        .campaign-clone-form-content .bootstrap-select > .dropdown-toggle {
            min-height: 40px;
            border: 1px solid var(--co-border-strong);
            border-radius: 10px;
            color: var(--co-ink);
            background-color: var(--co-surface);
            box-shadow: none;
            font-size: 11px;
        }
        .campaign-clone-form-content .form-control:focus,
        .campaign-clone-form-content .bootstrap-select > .dropdown-toggle:focus,
        .campaign-clone-form-content .bootstrap-select.show > .dropdown-toggle {
            border-color: var(--co-purple);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--co-purple) 12%, transparent);
        }
        .campaign-clone-form-content .bootstrap-select { width: 100% !important; }
        .campaign-clone-form-content .bootstrap-select .dropdown-menu {
            z-index: 11020;
            overflow: hidden;
            border: 1px solid var(--co-border-strong);
            border-radius: 11px;
            color: var(--co-ink);
            background: var(--co-surface);
            box-shadow: 0 14px 38px rgb(11 18 43 / 20%);
        }
        .campaign-clone-form-content .bootstrap-select .dropdown-menu .inner { scrollbar-color: var(--co-border-strong) transparent; }
        .campaign-clone-form-content .bootstrap-select .dropdown-menu li a { padding: 8px 12px; color: var(--co-ink); font-size: 10px; }
        .campaign-clone-form-content .bootstrap-select .dropdown-menu li a:hover,
        .campaign-clone-form-content .bootstrap-select .dropdown-menu li.selected a { color: var(--co-purple); background: color-mix(in srgb, var(--co-purple) 9%, var(--co-surface)); }
        .campaign-clone-form-content .form-text { color: var(--co-muted) !important; font-size: 9px; line-height: 1.5; }
        .campaign-clone-form-content #clone-recipient-estimate { color: var(--co-purple); font-variant-numeric: tabular-nums; }
        .campaign-clone-form-content #clone-status { min-height: 18px; margin: 6px 0 12px; }
        .campaign-clone-form-content #clone-progress .progress { height: 9px; overflow: hidden; border-radius: 99px; background: var(--co-surface-muted); }
        .campaign-clone-form-content #clone-progress .progress-bar { border-radius: inherit; background: linear-gradient(90deg, var(--co-blue), var(--co-purple-bright)); }
        .campaign-clone-form-content form > .d-flex { gap: 8px; padding-top: 12px; border-top: 1px solid var(--co-border); }
        .campaign-clone-form-content form > .d-flex .btn { min-height: 38px; padding-inline: 16px; border-radius: 10px; font-size: 10px; font-weight: 750; }
        .campaign-register-page .campaign-register-filters select.form-control,
        .campaign-register-page .campaign-register-actions select.form-control { appearance: auto; cursor: pointer; }
        .campaign-statistics-dropdown {
            padding: 14px;
            border: 1px solid var(--co-border);
            border-radius: 0 0 12px 12px;
            color: var(--co-ink);
            background: linear-gradient(110deg, color-mix(in srgb, var(--co-purple) 5%, var(--co-surface-muted)), var(--co-surface-muted));
        }
        .campaign-statistics-table { overflow: hidden; border: 1px solid var(--co-border); border-radius: 10px; background: var(--co-surface); }
        .campaign-statistics-table thead th { background: var(--co-surface-muted); }
        #campaign-auto-test-feedback { border-radius: 11px; box-shadow: var(--co-shadow); font-size: 11px; }
        /* The date range picker is attached directly to body by its vendor plugin. */
        body.command-os-shell .daterangepicker {
            z-index: 11010;
            overflow: hidden;
            border: 1px solid var(--co-border-strong);
            border-radius: 14px;
            color: var(--co-ink);
            background: var(--co-surface);
            box-shadow: 0 18px 54px rgb(11 18 43 / 24%);
            font-family: Inter, 'Segoe UI', Arial, sans-serif;
            font-size: 11px;
        }
        body.command-os-shell .daterangepicker:before { border-bottom-color: var(--co-border-strong); }
        body.command-os-shell .daterangepicker:after { border-bottom-color: var(--co-surface); }
        body.command-os-shell .daterangepicker .calendar-table { border: 0; border-radius: 9px; background: var(--co-surface); }
        body.command-os-shell .daterangepicker .calendar-table th,
        body.command-os-shell .daterangepicker .calendar-table td { min-width: 32px; height: 30px; border-radius: 8px; color: var(--co-ink); font-size: 10px; }
        body.command-os-shell .daterangepicker .calendar-table th { color: var(--co-muted); font-weight: 750; }
        body.command-os-shell .daterangepicker .calendar-table th.month { color: var(--co-ink); font-size: 11px; font-weight: 800; }
        body.command-os-shell .daterangepicker .calendar-table th.available span { border-color: var(--co-muted); }
        body.command-os-shell .daterangepicker .calendar-table th.available:hover,
        body.command-os-shell .daterangepicker td.available:hover { color: var(--co-purple); background: color-mix(in srgb, var(--co-purple) 9%, var(--co-surface)); }
        body.command-os-shell .daterangepicker td.off,
        body.command-os-shell .daterangepicker td.off.in-range,
        body.command-os-shell .daterangepicker td.off.start-date,
        body.command-os-shell .daterangepicker td.off.end-date { color: var(--co-subtle); background: transparent; opacity: .55; }
        body.command-os-shell .daterangepicker td.in-range { color: var(--co-purple); background: color-mix(in srgb, var(--co-purple) 11%, var(--co-surface)); }
        body.command-os-shell .daterangepicker td.active,
        body.command-os-shell .daterangepicker td.active:hover { color: #fff; background: linear-gradient(135deg, var(--co-purple-bright), var(--co-purple)); box-shadow: 0 3px 9px color-mix(in srgb, var(--co-purple) 25%, transparent); }
        body.command-os-shell .daterangepicker .ranges { padding: 8px; }
        body.command-os-shell .daterangepicker .ranges ul { width: 142px; }
        body.command-os-shell .daterangepicker .ranges li { margin: 2px 0; padding: 9px 10px; border-radius: 8px; color: var(--co-muted); font-size: 10px; font-weight: 650; }
        body.command-os-shell .daterangepicker .ranges li:hover { color: var(--co-purple); background: color-mix(in srgb, var(--co-purple) 8%, var(--co-surface)); }
        body.command-os-shell .daterangepicker .ranges li.active { color: #fff; background: linear-gradient(105deg, var(--co-purple-bright), var(--co-purple)); }
        body.command-os-shell .daterangepicker .drp-calendar.left { border-left-color: var(--co-border); }
        body.command-os-shell .daterangepicker .drp-buttons { border-top-color: var(--co-border); color: var(--co-muted); }
        body.command-os-shell .daterangepicker .drp-selected { font-size: 9px; }
        body.command-os-shell .daterangepicker .drp-buttons .btn { margin-left: 6px; padding: 7px 11px; border: 1px solid var(--co-border-strong); border-radius: 8px; color: var(--co-ink); background: var(--co-surface-muted); font-size: 10px; }
        body.command-os-shell .daterangepicker .drp-buttons .applyBtn { border-color: var(--co-purple); color: #fff; background: linear-gradient(105deg, var(--co-purple-bright), var(--co-purple)); }
        html[data-command-theme='dark'] body.command-os-shell .daterangepicker .calendar-table .next span,
        html[data-command-theme='dark'] body.command-os-shell .daterangepicker .calendar-table .prev span { border-color: var(--co-muted); }
        html[data-command-theme='dark'] body.command-os-shell .daterangepicker .ranges li:hover { background: color-mix(in srgb, var(--co-purple) 16%, var(--co-surface)); }
        @media (max-width: 767.98px) {
            .campaign-status-dialog { padding: 18px; }
            .campaign-clone-dialog { width: min(96vw, 38rem); }
            body.command-os-shell .daterangepicker { position: fixed !important; top: 50% !important; left: 50% !important; right: auto !important; width: min(94vw, 350px); max-height: 86dvh; overflow: auto; transform: translate(-50%, -50%); }
            body.command-os-shell .daterangepicker:before,
            body.command-os-shell .daterangepicker:after { display: none; }
            body.command-os-shell .daterangepicker .ranges,
            body.command-os-shell .daterangepicker .drp-calendar { float: none; width: 100%; max-width: none; }
            body.command-os-shell .daterangepicker .ranges ul { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 3px; width: 100%; }
            body.command-os-shell .daterangepicker .drp-calendar.left { padding: 8px; }
            body.command-os-shell .daterangepicker .drp-calendar.right { padding: 0 8px 8px; }
            body.command-os-shell .daterangepicker.show-calendar .ranges { margin-top: 0; }
        }
        @media (prefers-reduced-motion: reduce) {
            .campaign-status-dialog[open], .campaign-clone-dialog[open] { animation: none; }
        }
        html[data-command-theme='dark'] .campaign-register-table .badge-warning { color: #ffd591; background: rgb(209 126 20 / 22%); }
        html[data-command-theme='dark'] .campaign-register-table .badge-success { color: #75e4b4; background: rgb(25 173 116 / 20%); }
        html[data-command-theme='dark'] .campaign-register-table .badge-danger { color: #ff9eae; background: rgb(220 71 102 / 20%); }
        @media (max-width: 1299.98px) {
            .campaign-workspace-grid { grid-template-columns: minmax(0, 1fr); }
            .campaign-intelligence { grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; }
            .campaign-analytics-card { grid-row: span 2; }
        }
        @media (max-width: 767.98px) {
            .campaign-register-page .campaign-register-page-header { min-height: 115px; }
            .campaign-hero-illustration { top: 20px; right: 7%; transform: rotate(-15deg) scale(.8); }
            .campaign-workspace-grid { gap: 12px; }
            .campaign-overview { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .campaign-overview-card { min-height: 74px; }
            .campaign-overview-card:last-child { grid-column: 1 / -1; }
            .campaign-intelligence { grid-template-columns: minmax(0, 1fr); }
            .campaign-analytics-card { grid-row: auto; }
            .campaign-information-grid { gap: 0 10px; }
        }
        @media (prefers-reduced-motion: reduce) {
            body.command-os-shell .campaign-register-page-header::before,
            body.command-os-shell .campaign-register-page-header::after { animation: none; }
        }
        @media (max-width: 1100px) {
            .campaign-register-toolbar { align-items: stretch !important; }
            .campaign-register-actions { flex: 1 1 100%; }
            .campaign-register-filters { flex: 1 1 100%; justify-content: flex-start; }
        }
        @media (max-width: 767.98px) {
            body.command-os-shell .campaign-register-page-header { align-items: flex-start; flex-direction: column; padding: 14px; }
            .campaign-register-page .block-options { justify-content: space-between; width: 100%; }
            body.command-os-shell .campaign-register-page > .block-content { padding: 12px; }
            .campaign-register-toolbar { padding: 9px; }
            .campaign-register-actions { width: 100%; }
            .campaign-register-actions .form-control { min-width: 116px; }
            .campaign-register-filters { align-items: center; width: 100%; }
            .campaign-register-filters > label:not(.sr-only) { flex: 0 0 100%; }
            .campaign-register-filters .campaign-date-range { flex: 1 1 100%; width: 100%; }
            .campaign-register-filters .campaign-search-control { flex: 1 1 calc(100% - 46px); min-width: 0; }
            .campaign-register-filters input[type="search"] { width: 100%; }
            .campaign-register-test-link { flex: 1 1 100%; justify-content: center; }
            .campaign-register-scroll-note { display: flex; align-items: center; gap: 6px; }
        }
        @media (max-width: 767.98px) {
            .campaign-register-table th,
            .campaign-register-table th[data-campaign-column="send"],
            .campaign-register-table td[data-campaign-column="send"] { position: static; z-index: auto; box-shadow: none; }
        }
        @media (max-width: 420px) {
            .campaign-register-heading-icon { flex-basis: 40px; width: 40px; height: 40px; }
            body.command-os-shell .campaign-register-page .campaign-register-heading-copy .block-title { font-size: 18px; }
            .campaign-register-actions { gap: 6px; }
            .campaign-register-create { flex: 1 1 100%; }
            .campaign-register-active { flex: 1 1 auto; }
        }
    </style>
@endpush

@push('scripts')
    @vite('resources/js/campaign-register.ts')
    <script src="{{ asset('console-assets/vendor/daterangepicker/daterangepicker.js') }}?v=3.1.0"></script>
    <script src="{{ asset('console-assets/analytics-date-range.js') }}?v={{ substr(sha1_file(public_path('console-assets/analytics-date-range.js')), 0, 12) }}" defer></script>
    <script src="{{ asset('console-assets/campaign-register-controls.js') }}?v={{ substr(sha1_file(public_path('console-assets/campaign-register-controls.js')), 0, 12) }}" defer></script>
    <script src="{{ asset('console-assets/campaign-clone-modal.js') }}?v={{ substr(sha1_file(public_path('console-assets/campaign-clone-modal.js')), 0, 12) }}" defer></script>
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
            const selectableRows = rowSelections.filter(input => !input.disabled);
            const autoTestForms = Array.from(document.querySelectorAll('[data-campaign-auto-test-form]'));
            const autoTestFeedback = document.getElementById('campaign-auto-test-feedback');
            const liveSendProgress = Array.from(document.querySelectorAll('[data-campaign-send-progress]'));
            const snapshotProgressRows = Array.from(document.querySelectorAll('[data-campaign-snapshot-progress]'));
            const stopDialog = document.getElementById('campaign-stop-dialog');
            const stopForm = document.getElementById('campaign-stop-form');
            const stopCancel = document.getElementById('campaign-stop-cancel');
            const stopConfirm = document.getElementById('campaign-stop-confirm');
            const actionDialog = document.getElementById('campaign-action-dialog');
            const actionCancel = document.getElementById('campaign-action-cancel');
            const actionConfirm = document.getElementById('campaign-action-confirm');
            const confirmCampaignAction = (title, description, label) => new Promise(resolve => {
                if (actionDialog.open) {
                    resolve(false);
                    return;
                }
                document.getElementById('campaign-action-title').textContent = title;
                document.getElementById('campaign-action-description').textContent = description;
                actionConfirm.textContent = label;
                let confirmed = false;
                actionConfirm.onclick = () => { confirmed = true; actionDialog.close(); };
                actionCancel.onclick = () => actionDialog.close();
                actionDialog.addEventListener('close', () => resolve(confirmed), {once: true});
                actionDialog.showModal();
                actionCancel.focus();
            });

            document.querySelectorAll('[data-campaign-stop-open]').forEach(button => {
                button.addEventListener('click', () => {
                    stopForm.action = button.dataset.stopUrl;
                    document.getElementById('campaign-stop-description').textContent = `Stop sending “${button.dataset.campaignName}”? No new recipients will start. A message already being submitted to SMTP may finish.`;
                    stopDialog.showModal();
                    stopCancel.focus();
                });
            });
            stopCancel.addEventListener('click', () => stopDialog.close());
            stopForm.addEventListener('submit', () => {
                stopConfirm.disabled = true;
                stopConfirm.setAttribute('aria-busy', 'true');
                stopConfirm.textContent = 'Stopping…';
            });

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
            const currencyFormat = new Intl.NumberFormat(undefined, {style: 'currency', currency: 'USD', minimumFractionDigits: 0, maximumFractionDigits: 2});
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
                        const next = cell.querySelector('[data-send-next]');
                        progress.setAttribute('aria-valuenow', String(result.percent));
                        bar.style.width = `${result.percent}%`;
                        state.textContent = result.state.replaceAll('_', ' ').toUpperCase();
                        summary.textContent = `${result.processed} / ${result.total} · ${result.accepted} accepted · ${result.rejected} rejected · ${result.suppressed} suppressed · ${result.uncertain} uncertain`;
                        if (next) {
                            const dueAt = result.next_batch_at ? new Date(result.next_batch_at) : null;
                            if (result.state === 'planned') {
                                next.textContent = 'Waiting for send worker…';
                            } else if (result.state === 'running' && dueAt && !Number.isNaN(dueAt.getTime()) && dueAt.getTime() > Date.now()) {
                                const remaining = Math.ceil((dueAt.getTime() - Date.now()) / 1000);
                                next.textContent = `Next recipient in about ${remaining} seconds (${dueAt.toLocaleTimeString()}).`;
                            } else if (result.state === 'running' && Number(next.dataset.sleepEmail) > 0) {
                                next.textContent = `Pacing: ${next.dataset.sleepEmail} seconds between recipients.`;
                            } else {
                                next.textContent = result.state === 'running' ? 'Processing next batch…' : '';
                            }
                        }
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
                const count = selectableRows.filter(input => input.checked).length;
                bulkButton.disabled = count === 0;
                selectedCount.textContent = `${count} campaign${count === 1 ? '' : 's'} selected`;
                selectedCount.hidden = count === 0;
                selectAll.checked = count > 0 && count === selectableRows.length;
                selectAll.indeterminate = count > 0 && count < selectableRows.length;
                selectAll.disabled = selectableRows.length === 0;
            };

            rowSelections.forEach(input => input.addEventListener('change', updateSelection));
            document.querySelectorAll('[data-campaign-send-form]').forEach(form => form.addEventListener('submit', async event => {
                event.preventDefault();
                const confirmed = await confirmCampaignAction('Start campaign send', 'Send this saved campaign to its selected, eligible recipients now? The saved sleep values are seconds between recipients and IP groups.', 'Start sending');
                if (!confirmed) {
                    return;
                }
                const button = form.querySelector('button[type="submit"]');
                if (button) {
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                    button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>QUEUING';
                }
                form.submit();
            }));
            autoTestForms.forEach(form => form.addEventListener('submit', async event => {
                event.preventDefault();
                const ipCount = Number(form.dataset.ipCount || 0);
                if (ipCount > 100 && !await confirmCampaignAction('Test campaign on many IPs', `This test will use ${ipCount} selected IPs. Continue?`, 'Run test')) {
                    return;
                }

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
                selectableRows.forEach(input => { input.checked = selectAll.checked; });
                updateSelection();
                document.dispatchEvent(new CustomEvent('campaign-row-selection-change'));
            });

            document.querySelectorAll('.campaign-status-open').forEach(button => {
                button.addEventListener('click', () => {
                    const nextActive = button.dataset.nextActive === '1';
                    statusForm.action = button.dataset.statusUrl;
                    statusValue.value = nextActive ? '1' : '0';
                    statusDescription.textContent = nextActive
                        ? `Restore “${button.dataset.campaignName}” to the active campaign list?`
                        : `Disable “${button.dataset.campaignName}”? It will be excluded from the active campaign list. Stop any active send first.`;
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
                if (selectableRows.every(input => !input.checked)) {
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
