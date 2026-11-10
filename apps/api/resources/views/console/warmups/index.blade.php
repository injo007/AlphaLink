@extends('layouts.admin-shell')

@section('title', 'Warmups')

@section('content')
<section class="block block-rounded campaign-register-page warmup-register-page" id="warmup-register">
    <div class="block-header campaign-register-page-header warmup-register-page-header">
        <div class="campaign-register-heading">
            <span class="campaign-register-heading-icon warmup-register-heading-icon" aria-hidden="true"><i class="fas fa-fire"></i></span>
            <div class="campaign-register-heading-copy">
                <span class="campaign-register-eyebrow">WARMUP OPERATIONS // SEED INFRASTRUCTURE</span>
                <h1 class="block-title">My Warmups</h1>
                <p>Review warmup schedules, seed audiences, delivery pacing, and reputation progress.</p>
            </div>
        </div>
        <span class="campaign-hero-illustration" aria-hidden="true"><i class="fas fa-fire"></i></span>
        <div class="block-options d-flex align-items-center">
            <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle warmup register fullscreen">
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
                <strong>Warmup send was not started.</strong>
                <ul class="mb-0 mt-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="campaign-overview warmup-register-overview" aria-label="Warmup overview">
            <article class="campaign-overview-card campaign-overview-total">
                <span class="campaign-overview-icon" aria-hidden="true"><i class="fas fa-fire"></i></span>
                <span class="campaign-overview-copy">
                    <span>Total Warmups</span>
                    <strong data-warmup-overview-value="total">{{ number_format($drafts->total()) }}</strong>
                    <small>In selected date range</small>
                </span>
            </article>
            <article class="campaign-overview-card campaign-overview-active" title="Active warmups currently queued or running.">
                <span class="campaign-overview-icon" aria-hidden="true"><i class="fas fa-play"></i></span>
                <span class="campaign-overview-copy">
                    <span>Active Sends</span>
                    <strong data-warmup-overview-value="active">{{ number_format($activeSends) }}</strong>
                    <small>Queued or running</small>
                </span>
            </article>
            <article class="campaign-overview-card campaign-overview-paused">
                <span class="campaign-overview-icon" aria-hidden="true"><i class="fas fa-file-alt"></i></span>
                <span class="campaign-overview-copy">
                    <span>Drafts</span>
                    <strong data-warmup-overview-value="drafts">{{ number_format(max(0, $drafts->total() - $activeSends)) }}</strong>
                    <small>Configured drafts</small>
                </span>
            </article>
            <article class="campaign-overview-card campaign-overview-completed" title="Warmups with a completed send cycle.">
                <span class="campaign-overview-icon" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                <span class="campaign-overview-copy">
                    <span>Completed</span>
                    <strong data-warmup-overview-value="completed">{{ number_format($drafts->filter(fn($d) => optional($d->warmup_send_run)->state === 'completed')->count()) }}</strong>
                    <small>Completed cycles</small>
                </span>
            </article>
            <article class="campaign-overview-card campaign-overview-issues" title="Total seed accounts connected across warmups.">
                <span class="campaign-overview-icon" aria-hidden="true"><i class="fas fa-seedling"></i></span>
                <span class="campaign-overview-copy">
                    <span>Seed Audience</span>
                    <strong data-warmup-overview-value="seeds">{{ number_format($drafts->sum('warmup_seed_count')) }}</strong>
                    <small>Monitored inboxes</small>
                </span>
            </article>
        </section>

        <div class="d-flex flex-wrap align-items-center justify-content-between mb-2 campaign-register-toolbar warmup-register-toolbar">
            <div class="d-flex align-items-center campaign-register-actions">
                <form method="GET" action="{{ route('console.warmups.index', $workspace->id) }}" class="form-inline mr-2" aria-label="Warmup rows per page">
                    <input type="hidden" name="from" value="{{ $from }}">
                    <input type="hidden" name="to" value="{{ $to }}">
                    @if ($query !== '')
                        <input type="hidden" name="q" value="{{ $query }}">
                    @endif
                    <label class="sr-only" for="warmup-per-page">Rows per page</label>
                    <select id="warmup-per-page" name="per_page" class="form-control form-control-sm" onchange="this.form.submit()" aria-label="Show warmup rows">
                        @foreach ([25, 50, 100] as $pageSize)
                            <option value="{{ $pageSize }}" @selected($perPage === $pageSize)>Show {{ $pageSize }} rows</option>
                        @endforeach
                    </select>
                </form>
                <a class="btn btn-sm campaign-register-create warmup-register-create" href="{{ $createUrl }}" title="Create new warmup"><i class="fas fa-plus" aria-hidden="true"></i><span>New Warmup</span></a>
                <a class="btn btn-sm campaign-register-active btn-alt-secondary" href="{{ route('console.warmups.index', $workspace->id) }}" title="Active warmup sends"><i class="fas fa-fire mr-1" aria-hidden="true"></i>Active sends <span class="badge badge-light ml-1">{{ $activeSends }}</span></a>
                <a class="btn btn-sm btn-alt-secondary campaign-register-icon-action" href="{{ request()->fullUrl() }}" title="Reload warmup register" aria-label="Reload warmup register"><i class="fas fa-sync-alt" aria-hidden="true"></i></a>
            </div>
            <form id="warmup-filter-form" method="GET" action="{{ route('console.warmups.index', $workspace->id) }}" class="form-inline mb-2 campaign-register-filters warmup-register-filters" aria-label="Filter warmups" data-analytics-date-range-form data-storage-key="warmups" data-min-date="none" novalidate>
                <input type="hidden" name="per_page" value="{{ $perPage }}">
                <div class="form-group mr-2 mb-0">
                    <label for="analytics-dates" class="sr-only">Date range</label>
                    <div class="input-group input-group-sm">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fas fa-calendar-alt" aria-hidden="true"></i></span>
                        </div>
                        <input id="analytics-dates" type="text" class="form-control form-control-sm warmup-date-range" value="{{ $from }} - {{ $to }}" readonly aria-label="Choose warmup date range">
                        <input type="hidden" name="from" value="{{ $from }}">
                        <input type="hidden" name="to" value="{{ $to }}">
                    </div>
                </div>
                <div class="form-group mr-2 mb-0 warmup-search-control">
                    <label class="sr-only" for="warmup-search">Search warmups</label>
                    <input class="form-control form-control-sm" type="search" id="warmup-search" name="q" value="{{ $query }}" placeholder="Search warmups...">
                </div>
                <button class="btn btn-sm btn-alt-primary" type="submit" aria-label="Search warmups"><i class="fas fa-search" aria-hidden="true"></i></button>
            </form>
        </div>

        <div class="table-responsive campaign-register-table-wrap warmup-register-table-wrap">
            <table id="warmups" class="table table-sm table-hover campaign-register-table warmup-register-table">
                <caption class="sr-only">Saved Warmup drafts in this workspace</caption>
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">USER</th>
                        <th scope="col">SEEDS</th>
                        <th scope="col">SERVER</th>
                        <th scope="col">TOTAL SEND</th>
                        <th scope="col">FRACTION IP</th>
                        <th scope="col">SLEEP EMAIL</th>
                        <th scope="col">SLEEP IP</th>
                        <th scope="col">SEED</th>
                        <th scope="col">STATUS</th>
                        <th scope="col">PROGRESS</th>
                        <th scope="col">TYPE</th>
                        <th scope="col">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($drafts as $draft)
                    @php($config = $draft->warmup_config ?? [])
                    @php($sendRun = $draft->warmup_send_run)
                    @php($processed = $sendRun ? (int) $sendRun->accepted_count + (int) $sendRun->rejected_count + (int) $sendRun->uncertain_count : 0)
                    @php($recipientCount = (int) ($sendRun->recipient_count ?? 0))
                    @php($progressPercent = $recipientCount > 0 ? min(100, (int) floor($processed * 100 / $recipientCount)) : 0)
                    @php($runState = $sendRun ? $sendRun->state : 'draft')
                    <tr>
                        <th scope="row" data-label="ID">
                            <a class="campaign-row-number" href="{{ route('console.warmups.edit', [$workspace->id, $draft->id]) }}" title="Edit warmup {{ $draft->name }}">#{{ substr((string) $draft->id, 0, 8) }}</a>
                            <small class="d-block mt-1 text-truncate" style="max-width: 140px;" title="{{ $draft->name }}">{{ $draft->name }}</small>
                        </th>
                        <td data-label="User"><span class="badge badge-light border text-muted"><i class="fas fa-user mr-1 text-purple"></i>{{ auth()->user()->username }}</span></td>
                        <td data-label="Seeds"><span class="font-w600 text-purple font-size-sm"><i class="fas fa-seedling mr-1"></i>{{ number_format((int) ($draft->warmup_seed_count ?? 0)) }}</span></td>
                        <td data-label="Server">
                            @if ($draft->warmup_servers->isNotEmpty())
                                <span class="badge badge-primary"><i class="fas fa-server mr-1"></i>{{ $draft->warmup_servers->count() > 1 ? 'GROUPED : '.$draft->warmup_servers->count() : $draft->warmup_servers->first() }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td data-label="Total send"><span class="font-mono">{{ $config['total_send'] ?? '—' }}</span></td>
                        <td data-label="Fraction IP"><span class="font-mono">{{ $config['fraction_ip'] ?? '—' }}</span></td>
                        <td data-label="Sleep email"><span class="font-mono">{{ $config['sleep_email'] ?? '—' }}</span></td>
                        <td data-label="Sleep IP"><span class="font-mono">{{ $config['sleep_ip'] ?? '—' }}</span></td>
                        <td data-label="Seed"><span class="font-mono">{{ $config['seed_every'] ?? '—' }}</span></td>
                        <td data-label="Status">
                            <span class="warmup-status-pill warmup-status-{{ $runState }}">
                                <span class="status-indicator"></span>
                                {{ strtoupper($runState) }}
                            </span>
                        </td>
                        <td data-label="Progress" class="warmup-register-progress">
                            @if ($sendRun)
                                <div class="warmup-register-progress-label">
                                    <strong>{{ number_format($processed) }} / {{ number_format($recipientCount) }}</strong>
                                    <span>{{ $progressPercent }}%</span>
                                </div>
                                <div class="warmup-register-progress-track" role="progressbar" aria-label="Warmup send progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progressPercent }}" aria-valuetext="{{ number_format($processed) }} of {{ number_format($recipientCount) }} recipients processed">
                                    <span style="width: {{ $progressPercent }}%"></span>
                                </div>
                                <small>Accepted {{ number_format((int) $sendRun->accepted_count) }} · rejected {{ number_format((int) $sendRun->rejected_count) }} · uncertain {{ number_format((int) $sendRun->uncertain_count) }}</small>
                            @else
                                <span class="badge badge-secondary"><i class="fas fa-clock mr-1"></i>No send run yet</span>
                            @endif
                        </td>
                        <td data-label="Type"><span class="badge badge-light border">{{ strtoupper((string) ($config['send_type'] ?? 'DELAY')) }}</span></td>
                        <td data-label="Actions" class="text-nowrap warmup-register-actions">
                            <div class="campaign-register-row-actions warmup-register-row-actions">
                                <a class="campaign-register-icon-action" href="{{ route('console.warmups.edit', [$workspace->id, $draft->id]) }}" title="Edit Warmup" aria-label="Edit Warmup"><i class="fas fa-edit" aria-hidden="true"></i></a>
                                <a class="campaign-register-icon-action warmup-action-test" href="{{ route('console.warmups.edit', [$workspace->id, $draft->id]) }}" title="Open Warmup composer to test" aria-label="Test Warmup"><i class="far fa-paper-plane" aria-hidden="true"></i></a>
                                @if ($sendRun && in_array($sendRun->state, ['planned', 'running'], true))
                                    <form method="post" action="{{ route('console.warmups.send-stop', [$workspace->id, $draft->id, $sendRun->id]) }}" class="d-inline">
                                        @csrf
                                        <button class="campaign-register-icon-action campaign-register-icon-action-danger" type="submit" title="Stop Warmup send" aria-label="Stop Warmup send"><i class="fas fa-stop" aria-hidden="true"></i></button>
                                    </form>
                                @else
                                    <form method="post" action="{{ route('console.warmups.send', [$workspace->id, $draft->id]) }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                        <button class="campaign-register-icon-action campaign-register-icon-action-success" type="submit" title="Queue Warmup send" aria-label="Send Warmup"><i class="fas fa-play" aria-hidden="true"></i></button>
                                    </form>
                                @endif
                                <button class="campaign-register-icon-action" type="button" disabled title="Resend requires a completed send" aria-label="Resend unavailable"><i class="fas fa-redo" aria-hidden="true"></i></button>
                                <button class="campaign-register-icon-action" type="button" disabled title="Warmup schedule is not connected" aria-label="Warmup schedule unavailable"><i class="fas fa-calendar-alt" aria-hidden="true"></i></button>
                                <button class="campaign-register-icon-action" type="button" disabled title="No Warmup server process is attached" aria-label="Warmup PMTA unavailable"><i class="fas fa-server" aria-hidden="true"></i></button>
                                <button class="campaign-register-icon-action campaign-register-icon-action-danger" type="button" disabled title="Warmup removal is not connected" aria-label="Warmup removal unavailable"><i class="fas fa-trash-alt" aria-hidden="true"></i></button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="13" class="text-center py-5 text-muted">
                            <div class="py-3">
                                <i class="fas fa-fire fa-2x text-muted mb-3 opacity-50"></i>
                                <p class="mb-2 font-w600">No warmups found in this date range</p>
                                <a class="btn btn-sm btn-alt-primary" href="{{ $createUrl }}"><i class="fas fa-plus mr-1"></i>Create your first warmup</a>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="d-flex flex-wrap justify-content-between align-items-center warmup-register-footer">
            <small class="text-muted">Showing {{ $drafts->firstItem() ?? 0 }} to {{ $drafts->lastItem() ?? 0 }} of {{ $drafts->total() }} entries.</small>
            <small class="warmup-register-scroll-hint"><i class="fas fa-arrows-alt-h mr-1" aria-hidden="true"></i> Scroll horizontally to view remaining columns.</small>
            {{ $drafts->links() }}
        </div>
    </div>
</section>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('console-assets/vendor/daterangepicker/daterangepicker.css') }}">
    <style>
        .warmup-register-page { --warmup-danger: #ce4560; --warmup-success: #14825d; margin: 0 0 18px; overflow: hidden; border: 1px solid var(--co-border); border-radius: 17px; color: var(--co-ink); background: var(--co-surface); box-shadow: var(--co-shadow); }
        .warmup-register-page-header { align-items: center; justify-content: space-between; gap: 18px; min-height: 110px; overflow: hidden; padding: 15px 20px; position: relative; isolation: isolate; border-bottom: 1px solid var(--co-border); background: linear-gradient(105deg, color-mix(in srgb, var(--co-purple) 8%, var(--co-surface)), var(--co-surface) 48%, color-mix(in srgb, var(--co-blue) 6%, var(--co-surface))); }
        .warmup-register-page-header::before { position: absolute; z-index: -1; inset: -65% -5% -55% 28%; border-radius: 50%; background: radial-gradient(ellipse at 52% 50%, color-mix(in srgb, var(--co-purple-bright) 22%, transparent), transparent 57%), radial-gradient(ellipse at 73% 54%, color-mix(in srgb, var(--co-blue) 22%, transparent), transparent 48%); content: ""; }
        .warmup-register-page-header .campaign-hero-illustration { position: absolute; z-index: -1; top: 18px; right: 14%; display: grid; width: 94px; height: 72px; place-items: center; color: color-mix(in srgb, var(--co-purple) 50%, var(--co-blue)); filter: drop-shadow(0 12px 13px color-mix(in srgb, var(--co-purple) 28%, transparent)); opacity: .32; transform: rotate(-15deg); }
        .warmup-register-page-header .campaign-hero-illustration i { font-size: 58px; }
        .warmup-register-page .campaign-register-heading { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .warmup-register-page .campaign-register-heading-icon { display: grid; flex: 0 0 46px; width: 46px; height: 46px; place-items: center; border: 1px solid color-mix(in srgb, var(--co-purple) 24%, var(--co-border)); border-radius: 14px; color: #fff; background: linear-gradient(145deg, var(--co-purple-bright), var(--co-purple)); box-shadow: 0 7px 18px color-mix(in srgb, var(--co-purple) 24%, transparent); font-size: 18px; }
        .warmup-register-page .campaign-register-heading-copy { min-width: 0; }
        .warmup-register-page .campaign-register-eyebrow { color: var(--co-purple); font-size: 9px; font-weight: 800; letter-spacing: .14em; }
        .warmup-register-page .campaign-register-heading-copy p { margin: 0; color: var(--co-muted); font-size: 10px; }
        .warmup-register-page .block-title { margin: 2px 0 3px; color: var(--co-ink); font-size: 20px; font-weight: 780; letter-spacing: -.04em; line-height: 1.15; text-transform: none; }
        .warmup-register-page .block-options { gap: 12px; margin: 0; }
        .warmup-register-page .block-options .btn-block-option { display: grid; width: 34px; height: 34px; place-items: center; border: 1px solid var(--co-border); border-radius: 10px; color: var(--co-muted); background: var(--co-surface); }
        .warmup-register-page .block-options .btn-block-option:hover { border-color: var(--co-purple); color: var(--co-purple); }
        .warmup-register-page > .block-content { padding: 16px 18px 18px; }

        /* Overview KPI Cards */
        .warmup-register-overview { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 9px; margin: 1px 0 13px; }
        .warmup-register-overview .campaign-overview-card { display: flex; align-items: center; gap: 9px; min-width: 0; min-height: 83px; padding: 10px; border: 1px solid var(--co-border); border-radius: 13px; background: linear-gradient(145deg, color-mix(in srgb, var(--co-purple) 4%, var(--co-surface)), var(--co-surface)); box-shadow: 0 5px 16px color-mix(in srgb, var(--co-purple) 7%, transparent); transition: transform 0.16s ease, border-color 0.16s ease; }
        .warmup-register-overview .campaign-overview-card:hover { transform: translateY(-1px); border-color: color-mix(in srgb, var(--co-purple) 35%, var(--co-border)); }
        .warmup-register-overview .campaign-overview-icon { display: grid; flex: 0 0 38px; width: 38px; height: 38px; place-items: center; border: 1px solid color-mix(in srgb, currentColor 14%, transparent); border-radius: 50%; color: var(--co-purple); background: color-mix(in srgb, var(--co-purple) 11%, var(--co-surface)); font-size: 15px; }
        .warmup-register-overview .campaign-overview-copy { display: grid; gap: 1px; min-width: 0; }
        .warmup-register-overview .campaign-overview-copy > span { overflow: hidden; color: var(--co-muted); font-size: 9px; font-weight: 750; text-overflow: ellipsis; white-space: nowrap; }
        .warmup-register-overview .campaign-overview-copy strong { color: var(--co-ink); font-size: 20px; font-weight: 800; line-height: 1.15; font-variant-numeric: tabular-nums; }
        .warmup-register-overview .campaign-overview-copy small { overflow: hidden; color: var(--co-subtle); font-size: 8px; text-overflow: ellipsis; white-space: nowrap; }
        .warmup-register-overview .campaign-overview-active .campaign-overview-icon { color: #0ea66d; background: color-mix(in srgb, #16bd82 12%, var(--co-surface)); }
        .warmup-register-overview .campaign-overview-paused .campaign-overview-icon { color: #964cf3; background: color-mix(in srgb, #964cf3 11%, var(--co-surface)); }
        .warmup-register-overview .campaign-overview-completed .campaign-overview-icon { color: #168bf0; background: color-mix(in srgb, #168bf0 11%, var(--co-surface)); }
        .warmup-register-overview .campaign-overview-issues .campaign-overview-icon { color: #eab308; background: color-mix(in srgb, #eab308 12%, var(--co-surface)); }

        /* Toolbar */
        .warmup-register-toolbar { gap: 10px 14px; margin: 0 0 14px !important; padding: 10px; border: 1px solid var(--co-border); border-radius: 13px; background: linear-gradient(110deg, color-mix(in srgb, var(--co-purple) 4%, var(--co-surface-muted)), var(--co-surface-muted)); }
        .warmup-register-toolbar .campaign-register-actions { flex: 0 1 auto; flex-wrap: wrap; gap: 7px; min-width: 0; }
        .warmup-register-toolbar .campaign-register-actions form { margin: 0 !important; }
        .warmup-register-toolbar .form-control { height: 35px; border: 1px solid var(--co-border-strong); border-radius: 9px; color: var(--co-ink); background-color: var(--co-surface); box-shadow: none; font-size: 10px; }
        .warmup-register-toolbar .form-control:focus { border-color: var(--co-purple); box-shadow: 0 0 0 3px color-mix(in srgb, var(--co-purple) 12%, transparent); }
        .warmup-register-toolbar select.form-control { min-width: 126px; }
        .warmup-register-toolbar .campaign-register-create { display: inline-flex; align-items: center; justify-content: center; gap: 7px; min-height: 35px; padding: 0 13px; border: 1px solid var(--co-purple); border-radius: 9px; color: #fff !important; background: linear-gradient(105deg, var(--co-purple-bright), var(--co-purple)); box-shadow: 0 4px 10px color-mix(in srgb, var(--co-purple) 20%, transparent); font-size: 10px; font-weight: 750; text-decoration: none; }
        .warmup-register-toolbar .campaign-register-create:hover { filter: brightness(1.04); transform: translateY(-1px); }
        .warmup-register-toolbar .campaign-register-active { display: inline-flex; align-items: center; justify-content: center; min-height: 35px; padding: 0 10px; border: 1px solid var(--co-border-strong); border-radius: 9px; color: var(--co-ink); background: var(--co-surface); font-size: 10px; font-weight: 700; text-decoration: none; }
        .warmup-register-toolbar .campaign-register-icon-action { display: inline-flex; align-items: center; justify-content: center; width: 35px; height: 35px; padding: 0; border: 1px solid var(--co-border-strong); border-radius: 9px; color: var(--co-muted); background: var(--co-surface); text-decoration: none; }
        .warmup-register-toolbar .campaign-register-icon-action:hover { border-color: var(--co-purple); color: var(--co-purple); }
        .warmup-register-filters { display: flex; flex: 1 1 560px; align-items: center; justify-content: flex-end; gap: 7px; min-width: 0; margin: 0 !important; }
        .warmup-register-filters .warmup-date-range { width: 190px; margin: 0 !important; }
        .warmup-register-filters .warmup-search-control { flex: 0 1 220px; min-width: 130px; }
        .warmup-register-filters input[type="search"] { width: 100%; max-width: none; }
        .warmup-register-filters > button { width: 35px; height: 35px; padding: 0; border: 1px solid var(--co-border-strong); border-radius: 9px; color: var(--co-muted); background: var(--co-surface); }
        .warmup-register-filters > button:hover { border-color: var(--co-purple); color: var(--co-purple); }

        /* Table Card */
        .warmup-register-table-wrap { max-width: 100%; overflow: auto; border: 1px solid var(--co-border); border-radius: 13px; background: var(--co-surface); box-shadow: var(--co-shadow); -webkit-overflow-scrolling: touch; scrollbar-color: var(--co-border-strong) transparent; scrollbar-width: thin; }
        .warmup-register-table { width: 100%; min-width: 1360px; margin: 0; border-collapse: separate; border-spacing: 0; color: var(--co-ink); font-size: 10px; table-layout: fixed; }
        .warmup-register-table th:nth-child(1), .warmup-register-table td:nth-child(1) { width: 130px; }
        .warmup-register-table th:nth-child(2), .warmup-register-table td:nth-child(2) { width: 85px; }
        .warmup-register-table th:nth-child(3), .warmup-register-table td:nth-child(3) { width: 75px; }
        .warmup-register-table th:nth-child(4), .warmup-register-table td:nth-child(4) { width: 110px; }
        .warmup-register-table th:nth-child(5), .warmup-register-table td:nth-child(5) { width: 90px; }
        .warmup-register-table th:nth-child(6), .warmup-register-table td:nth-child(6) { width: 85px; }
        .warmup-register-table th:nth-child(7), .warmup-register-table td:nth-child(7) { width: 95px; }
        .warmup-register-table th:nth-child(8), .warmup-register-table td:nth-child(8) { width: 85px; }
        .warmup-register-table th:nth-child(9), .warmup-register-table td:nth-child(9) { width: 75px; }
        .warmup-register-table th:nth-child(10), .warmup-register-table td:nth-child(10) { width: 105px; }
        .warmup-register-table th:nth-child(11), .warmup-register-table td:nth-child(11) { width: 180px; }
        .warmup-register-table th:nth-child(12), .warmup-register-table td:nth-child(12) { width: 75px; }
        .warmup-register-table th:nth-child(13), .warmup-register-table td:nth-child(13) { width: 180px; }
        .warmup-register-table th { position: sticky; top: 0; z-index: 2; padding: 11px 9px; border-top: 0; border-bottom: 1px solid var(--co-border); color: var(--co-muted); background: var(--co-surface-muted); font-size: 8px; font-weight: 800; letter-spacing: .07em; white-space: nowrap; }
        .warmup-register-table thead th:first-child { left: 0; z-index: 3; box-shadow: 5px 0 10px color-mix(in srgb, var(--co-ink) 6%, transparent); }
        .warmup-register-table tbody th[scope="row"] { left: 0; position: sticky; z-index: 2; background: var(--co-surface); box-shadow: 5px 0 10px color-mix(in srgb, var(--co-ink) 6%, transparent); }
        .warmup-register-table td { padding: 9px; border-top: 1px solid color-mix(in srgb, var(--co-border) 76%, transparent); color: var(--co-ink); vertical-align: middle; }
        .warmup-register-table tbody tr:first-child td { border-top: 0; }
        .warmup-register-table tbody tr:hover td, .warmup-register-table tbody tr:hover th[scope="row"] { background: color-mix(in srgb, var(--co-purple) 4%, var(--co-surface)); }
        .warmup-register-table .campaign-row-number { display: inline-flex; min-width: 28px; height: 24px; align-items: center; justify-content: center; padding: 0 6px; border: 1px solid color-mix(in srgb, var(--co-purple) 25%, var(--co-border)); border-radius: 8px; color: var(--co-purple) !important; background: color-mix(in srgb, var(--co-purple) 9%, var(--co-surface)); font-weight: 800; font-variant-numeric: tabular-nums; text-decoration: none; }
        .warmup-register-table .campaign-row-number:hover { border-color: var(--co-purple); background: color-mix(in srgb, var(--co-purple) 15%, var(--co-surface)); }
        .warmup-register-table .font-mono { font-family: var(--co-font-mono, ui-monospace, SFMono-Regular, Consolas, monospace); font-size: 9px; font-weight: 600; color: var(--co-ink); }

        /* Status Pills */
        .warmup-status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 3px 8px; border-radius: 9999px; font-size: 8px; font-weight: 800; letter-spacing: 0.04em; white-space: nowrap; }
        .warmup-status-pill .status-indicator { width: 6px; height: 6px; border-radius: 50%; }
        .warmup-status-draft { background: rgba(148, 163, 184, 0.14); color: #64748b; border: 1px solid rgba(148, 163, 184, 0.3); }
        .warmup-status-draft .status-indicator { background: #94a3b8; }
        .warmup-status-planned, .warmup-status-running { background: rgba(139, 92, 246, 0.15); color: #8b5cf6; border: 1px solid rgba(139, 92, 246, 0.35); }
        .warmup-status-planned .status-indicator, .warmup-status-running .status-indicator { background: #8b5cf6; box-shadow: 0 0 6px #8b5cf6; animation: status-pulse 1.8s infinite; }
        .warmup-status-completed { background: rgba(16, 185, 129, 0.14); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.35); }
        .warmup-status-completed .status-indicator { background: #10b981; }
        .warmup-status-failed, .warmup-status-error { background: rgba(239, 68, 68, 0.14); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.35); }
        .warmup-status-failed .status-indicator, .warmup-status-error .status-indicator { background: #ef4444; }
        @keyframes status-pulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(0.85); } }

        /* Progress */
        .warmup-register-table .warmup-register-progress-label { align-items: baseline; display: flex; gap: 8px; justify-content: space-between; }
        .warmup-register-table .warmup-register-progress-label strong { color: var(--co-ink); font-size: 10px; font-weight: 800; white-space: nowrap; }
        .warmup-register-table .warmup-register-progress-label span { color: var(--co-purple); font-size: 9px; font-weight: 800; font-variant-numeric: tabular-nums; }
        .warmup-register-progress-track { height: 6px; margin: 5px 0 4px; overflow: hidden; border-radius: 99px; background: color-mix(in srgb, var(--co-purple) 10%, var(--co-surface-muted)); }
        .warmup-register-progress-track > span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, var(--co-purple-bright), var(--co-purple)); transition: width .25s ease; }
        .warmup-register-table .warmup-register-progress small { display: block; color: var(--co-muted); font-size: 8px; line-height: 1.45; }

        /* Action Buttons */
        .warmup-register-row-actions { display: flex; align-items: center; gap: 4px; flex-wrap: nowrap; }
        .warmup-register-row-actions .campaign-register-icon-action { display: inline-flex; align-items: center; justify-content: center; width: 28px; min-width: 28px; height: 28px; min-height: 28px; padding: 0; border: 1px solid color-mix(in srgb, var(--co-purple) 24%, var(--co-border)); border-radius: 8px; color: var(--co-purple); background: linear-gradient(145deg, color-mix(in srgb, var(--co-purple) 13%, var(--co-surface)), color-mix(in srgb, var(--co-blue) 9%, var(--co-surface))); box-shadow: 0 2px 6px color-mix(in srgb, var(--co-purple) 12%, transparent); text-decoration: none; transition: all 0.16s ease; font-size: 11px; }
        .warmup-register-row-actions .campaign-register-icon-action:hover:not(:disabled) { border-color: var(--co-purple); color: var(--co-purple-bright); transform: translateY(-1px); box-shadow: 0 4px 10px color-mix(in srgb, var(--co-purple) 22%, transparent); }
        .warmup-register-row-actions .campaign-register-icon-action:disabled { opacity: 0.38; cursor: not-allowed; box-shadow: none; }
        .warmup-register-row-actions .warmup-action-test { color: #0284c7; background: linear-gradient(145deg, rgba(2, 132, 199, 0.12), rgba(2, 132, 199, 0.06)); border-color: rgba(2, 132, 199, 0.3); }
        .warmup-register-row-actions .campaign-register-icon-action-success { color: #10b981; background: linear-gradient(145deg, rgba(16, 185, 129, 0.14), rgba(16, 185, 129, 0.06)); border-color: rgba(16, 185, 129, 0.35); }
        .warmup-register-row-actions .campaign-register-icon-action-danger { color: #ef4444; background: linear-gradient(145deg, rgba(239, 68, 68, 0.14), rgba(239, 68, 68, 0.06)); border-color: rgba(239, 68, 68, 0.35); }

        .warmup-register-footer { gap: 10px; padding-top: 12px; color: var(--co-muted); }
        .warmup-register-scroll-hint { align-items: center; display: inline-flex; gap: 6px; color: var(--co-muted); font-size: 9px; }

        @media (max-width: 991.98px) {
            .warmup-register-overview { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .warmup-register-overview .campaign-overview-card:last-child { grid-column: 1 / -1; }
            .warmup-register-table-wrap { overflow: visible; border: 0; background: transparent; box-shadow: none; }
            .warmup-register-table { display: block; min-width: 0; table-layout: auto; }
            .warmup-register-table thead { display: none; }
            .warmup-register-table tbody { display: grid; gap: 10px; }
            .warmup-register-table tbody tr { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); min-width: 0; overflow: hidden; border: 1px solid var(--co-border); border-radius: 12px; background: var(--co-surface); box-shadow: 0 4px 14px color-mix(in srgb, var(--co-purple) 7%, transparent); }
            .warmup-register-table tbody th[scope="row"] { position: static; display: flex; align-items: center; justify-content: space-between; grid-column: 1 / -1; gap: 10px; width: auto; min-width: 0; padding: 12px; border: 0; border-bottom: 1px solid var(--co-border); background: color-mix(in srgb, var(--co-purple) 5%, var(--co-surface)); box-shadow: none; text-align: left; }
            .warmup-register-table td { display: grid; grid-template-columns: minmax(74px, .7fr) minmax(0, 1fr); align-items: start; gap: 8px; min-width: 0; padding: 9px 10px; border: 0 !important; border-bottom: 1px solid color-mix(in srgb, var(--co-border) 64%, transparent) !important; }
            .warmup-register-table td::before { color: var(--co-muted); content: attr(data-label); font-size: 9px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
            .warmup-register-table .warmup-register-progress, .warmup-register-table .warmup-register-actions { grid-column: 1 / -1; }
            .warmup-register-table .warmup-register-actions { border-bottom: 0 !important; }
            .warmup-register-row-actions { flex-wrap: wrap; }
            .warmup-register-scroll-hint { display: none; }
        }
        @media (max-width: 767.98px) {
            .warmup-register-page-header { align-items: flex-start; flex-direction: column; padding: 14px; }
            .warmup-register-page .campaign-register-heading { align-items: flex-start; }
            .warmup-register-page .block-options { justify-content: space-between; width: 100%; }
            .warmup-register-overview { grid-template-columns: 1fr; }
            .warmup-register-toolbar { align-items: stretch; }
            .warmup-register-filters { flex: 1 1 100%; justify-content: flex-start; }
            .warmup-register-filters .warmup-date-range { flex: 1 1 12rem; width: auto; }
            .warmup-register-filters .warmup-search-control { flex: 1 1 10rem; }
            .warmup-register-footer { flex-direction: column; align-items: flex-start !important; }
        }
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('console-assets/vendor/daterangepicker/daterangepicker.js') }}?v=3.1.0"></script>
    <script src="{{ asset('console-assets/analytics-date-range.js') }}?v={{ substr(sha1_file(public_path('console-assets/analytics-date-range.js')), 0, 12) }}" defer></script>
@endpush
