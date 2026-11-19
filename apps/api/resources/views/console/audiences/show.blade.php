@extends('layouts.admin-shell')

@section('title', $audienceList->name)

@push('styles')
    <style>
        .audience-snapshot-page { display: grid; gap: .85rem; }
        .audience-snapshot-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .9rem 1.1rem; border: 1px solid var(--co-border); border-left: 4px solid var(--co-purple); border-radius: 13px; background: var(--co-surface); box-shadow: var(--co-shadow); }
        .audience-snapshot-identity { display: flex; align-items: center; gap: .8rem; min-width: 0; }
        .audience-snapshot-icon { display: grid; flex: 0 0 40px; width: 40px; height: 40px; place-items: center; border-radius: 12px; background: color-mix(in srgb, var(--co-purple) 12%, var(--co-surface)); color: var(--co-purple); font-size: 1rem; }
        .audience-snapshot-title { margin: 0; color: var(--co-ink); font-size: clamp(1.1rem, 1.6vw, 1.4rem); font-weight: 750; line-height: 1.2; overflow-wrap: anywhere; }
        .audience-snapshot-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .25rem .7rem; margin-top: .25rem; color: var(--co-muted); font-size: .73rem; }
        .audience-snapshot-meta > span + span::before { margin-right: .7rem; color: var(--co-purple); content: '·'; }
        .audience-snapshot-description-copy { max-width: 820px; margin: .3rem 0 0; color: var(--co-muted); font-size: .72rem; line-height: 1.4; overflow-wrap: anywhere; }
        .audience-snapshot-actions { display: flex; flex: 0 0 auto; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: .5rem; }
        .audience-snapshot-panel { min-width: 0; padding: 0; border: 1px solid var(--co-border); border-radius: 13px; background: var(--co-surface); box-shadow: var(--co-shadow); }
        .audience-snapshot-panel-heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .65rem; padding: .85rem 1rem .75rem; border-bottom: 1px solid var(--co-border); }
        .audience-snapshot-panel-heading h2 { margin: 0; color: var(--co-ink); font-size: .9rem; font-weight: 800; letter-spacing: .035em; }
        .audience-snapshot-panel-heading p { max-width: 760px; margin: .25rem 0 0; color: var(--co-muted); font-size: .73rem; line-height: 1.45; }
        .audience-recipient-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .7rem; padding: .7rem 1rem; border-bottom: 1px solid var(--co-border); }
        .audience-recipient-controls { display: flex; flex-wrap: wrap; align-items: center; gap: .45rem; }
        .audience-recipient-page-size-label { display: inline-flex; align-items: center; gap: .4rem; color: var(--co-muted); font-size: .75rem; font-weight: 650; white-space: nowrap; }
        .audience-recipient-page-size, .audience-recipient-type { min-height: 36px; padding-top: .3rem; padding-bottom: .3rem; font-size: .76rem; }
        .audience-recipient-page-size { width: auto; min-width: 104px; }
        .audience-recipient-type { width: auto; min-width: 142px; }
        .audience-recipient-search-box { position: relative; width: min(100%, 310px); }
        .audience-recipient-search-box > .fas { position: absolute; top: 50%; left: .75rem; transform: translateY(-50%); color: var(--co-muted); pointer-events: none; }
        .audience-recipient-search { min-height: 36px; padding: .35rem 2.25rem .35rem 2rem; font-size: .77rem; }
        .audience-recipient-clear { position: absolute; top: 50%; right: .2rem; display: grid; width: 30px; height: 30px; transform: translateY(-50%); place-items: center; border: 0; border-radius: 7px; background: transparent; color: var(--co-muted); cursor: pointer; }
        .audience-recipient-clear:hover { background: color-mix(in srgb, var(--co-purple) 10%, transparent); color: var(--co-purple); }
        .audience-recipient-toolbar :focus-visible, .audience-recipient-sort:focus-visible, .audience-grid-refresh:focus-visible { outline: 3px solid color-mix(in srgb, var(--co-purple) 45%, transparent); outline-offset: 2px; }
        .audience-grid-refresh { display: inline-grid; width: 36px; height: 36px; place-items: center; border: 1px solid var(--co-border); border-radius: 8px; background: var(--co-surface); color: var(--co-purple); text-decoration: none; transition: background .15s ease, border-color .15s ease; }
        .audience-grid-refresh:hover { border-color: var(--co-purple); background: color-mix(in srgb, var(--co-purple) 7%, var(--co-surface)); color: var(--co-purple); }
        .audience-recipient-hint { flex-basis: 100%; margin: -.15rem 0 0; color: var(--co-muted); font-size: .68rem; line-height: 1.4; }
        .audience-recipient-results { margin: 0; padding: .45rem 1rem; border-bottom: 1px solid var(--co-border); color: var(--co-muted); font-size: .7rem; font-variant-numeric: tabular-nums; }
        .audience-recipient-table-wrap { width: 100%; overflow-x: auto; border-radius: 0 0 12px 12px; scrollbar-gutter: stable; }
        .audience-recipient-table { min-width: 1540px; margin: 0; border-collapse: separate; border-spacing: 0; font-size: .74rem; }
        .audience-recipient-table thead th { position: relative; padding: .62rem .65rem; border-top: 0; border-bottom: 1px solid var(--co-border); background: color-mix(in srgb, var(--co-purple) 4%, var(--co-surface)); color: var(--co-muted); font-size: .64rem; font-weight: 800; letter-spacing: .065em; white-space: nowrap; text-transform: uppercase; }
        .audience-recipient-table tbody td { padding: .56rem .65rem; border-top: 0; border-bottom: 1px solid color-mix(in srgb, var(--co-border) 78%, transparent); color: var(--co-ink); vertical-align: middle; }
        .audience-recipient-table tbody tr:nth-child(even) td { background: color-mix(in srgb, var(--co-purple) 2.5%, var(--co-surface)); }
        .audience-recipient-table tbody tr:hover td { background: color-mix(in srgb, var(--co-purple) 7%, var(--co-surface)); }
        .audience-recipient-table .audience-col-id { width: 92px; }
        .audience-recipient-table .audience-col-email { min-width: 230px; }
        .audience-recipient-table .audience-col-first, .audience-recipient-table .audience-col-last { min-width: 132px; }
        .audience-recipient-table .audience-col-country, .audience-recipient-table .audience-col-vertical { min-width: 118px; }
        .audience-recipient-table .audience-col-age, .audience-recipient-table .audience-col-logs { width: 76px; }
        .audience-recipient-table .audience-col-sex, .audience-recipient-table .audience-col-type { min-width: 88px; }
        .audience-recipient-table .audience-col-created { min-width: 165px; }
        .audience-recipient-table .audience-col-custom { min-width: 225px; }
        .audience-recipient-email { max-width: 260px; color: var(--co-ink); font-size: .73rem; overflow-wrap: anywhere; }
        .audience-recipient-id, .audience-recipient-number, .audience-recipient-time { color: var(--co-muted); font-family: var(--co-font-mono, ui-monospace, monospace); font-size: .69rem; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .audience-recipient-sort { display: inline-flex; align-items: center; gap: .35rem; padding: .1rem 0; border: 0; background: transparent; color: inherit; font: inherit; letter-spacing: inherit; text-transform: inherit; cursor: pointer; }
        .audience-recipient-sort:hover, .audience-recipient-sort[aria-pressed="true"] { color: var(--co-purple); }
        .audience-recipient-sort .fas { color: var(--co-purple); font-size: .65rem; }
        .audience-recipient-sort:focus-visible { outline-offset: 3px; }
        .audience-recipient-custom-tags { display: grid; gap: .18rem; min-width: 190px; }
        .audience-recipient-custom-tag { display: flex; flex-wrap: wrap; gap: .2rem .35rem; color: var(--co-ink); font-size: .68rem; line-height: 1.35; overflow-wrap: anywhere; }
        .audience-recipient-custom-tag-key { color: var(--co-purple); font-weight: 750; }
        .audience-recipient-row[hidden], .audience-recipient-no-results[hidden] { display: none !important; }
        .audience-recipient-empty { padding: 2.25rem 1rem; text-align: center; }
        .audience-recipient-empty-icon { display: grid; width: 42px; height: 42px; margin: 0 auto .7rem; place-items: center; border-radius: 12px; background: color-mix(in srgb, var(--co-purple) 10%, var(--co-surface)); color: var(--co-purple); }
        .audience-recipient-empty h3 { margin: 0 0 .35rem; color: var(--co-ink); font-size: .9rem; font-weight: 750; }
        .audience-recipient-empty p { margin: 0; color: var(--co-muted); font-size: .76rem; }
        .audience-snapshot-footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .65rem; padding: .7rem 1rem; }
        .audience-snapshot-footer > small { color: var(--co-muted); font-size: .7rem; font-variant-numeric: tabular-nums; }
        .audience-snapshot-footer .pagination { margin: 0; }
        .audience-snapshot-events { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; align-items: center; gap: .8rem; padding: 0 1rem 1rem; }
        .audience-snapshot-event-source, .audience-snapshot-event-time { color: var(--co-muted); font-size: .74rem; }
        @media (max-width: 767.98px) {
            .audience-snapshot-header { align-items: flex-start; flex-direction: column; padding: .8rem; }
            .audience-snapshot-actions { justify-content: flex-start; }
            .audience-snapshot-panel-heading, .audience-recipient-toolbar { padding-right: .75rem; padding-left: .75rem; }
            .audience-recipient-toolbar { align-items: stretch; }
            .audience-recipient-controls { flex: 1 1 100%; }
            .audience-recipient-search-box { width: 100%; }
            .audience-recipient-search { width: 100%; }
            .audience-recipient-results { padding-right: .75rem; padding-left: .75rem; }
            .audience-recipient-table { min-width: 1540px; }
            .audience-snapshot-footer { align-items: flex-start; padding-right: .75rem; padding-left: .75rem; }
            .audience-snapshot-events { grid-template-columns: minmax(0, 1fr) auto; padding-right: .75rem; padding-left: .75rem; }
            .audience-snapshot-event-time { grid-column: 1 / -1; }
        }
        @media (max-width: 480px) {
            .audience-snapshot-identity { align-items: flex-start; gap: .65rem; }
            .audience-recipient-controls { gap: .35rem; }
            .audience-recipient-page-size-label { gap: .25rem; }
            .audience-recipient-page-size { min-width: 92px; }
            .audience-recipient-type { min-width: 125px; }
        }
    </style>
@endpush

@section('content')
    <div class="command-os-entity-page audience-snapshot-page">
        <header class="audience-snapshot-header" aria-labelledby="audience-show-title">
            <div class="audience-snapshot-identity">
                <span class="audience-snapshot-icon" aria-hidden="true"><i class="fas fa-address-book"></i></span>
                <div class="min-w-0">
                    <p class="command-os-report-eyebrow mb-1">LIST · RECIPIENT SCHEDULE</p>
                    <h1 id="audience-show-title" class="audience-snapshot-title">{{ $audienceList->name }}</h1>
                    <div class="audience-snapshot-meta">
                        <span class="font-mono">{{ $audienceList->slug }}</span>
                        <span>{{ number_format($audienceList->active_members_count) }} active recipients</span>
                        <span>Created {{ $audienceList->created_at?->format('M j, Y') }}</span>
                    </div>
                    @if ($audienceList->description)
                        <p class="audience-snapshot-description-copy">{{ $audienceList->description }}</p>
                    @endif
                </div>
            </div>
            <div class="audience-snapshot-actions">
                <span class="badge-command-pill {{ $audienceList->status === 'active' ? 'status-ready' : '' }}">{{ ucfirst(str_replace('_', ' ', $audienceList->status)) }}</span>
                <a class="btn-command-action" href="{{ route('console.audiences.index', $workspace->id) }}">
                    <i class="fas fa-arrow-left mr-1" aria-hidden="true"></i> Back to lists
                </a>
            </div>
        </header>

        @if ($canViewContacts)
            <section class="audience-snapshot-panel" aria-labelledby="audience-recipients-title">
                <div class="audience-snapshot-panel-heading">
                    <div>
                        <h2 id="audience-recipients-title"><i class="fas fa-table mr-2 text-purple" aria-hidden="true"></i>Recipient data</h2>
                        <p>Imported profile fields have their own columns; additional CSV tags stay visible in Other tags. Search and sorting apply to the loaded page.</p>
                    </div>
                    <span class="badge-command-pill">{{ number_format($contactRows->total()) }} active</span>
                </div>

                @if ($contactRows->isEmpty())
                    <div class="audience-recipient-empty">
                        <span class="audience-recipient-empty-icon" aria-hidden="true"><i class="fas fa-inbox"></i></span>
                        <h3>No active recipients yet</h3>
                        <p>Import recipients into this list to see each profile field in the schedule.</p>
                    </div>
                @else
                    <form id="audience-recipient-filter" class="audience-recipient-toolbar" method="get" action="{{ route('console.audiences.show', [$workspace->id, $audienceList->id]) }}">
                        <div class="audience-recipient-controls">
                            <label class="audience-recipient-page-size-label" for="audience-recipient-page-size">Show
                                <select id="audience-recipient-page-size" class="form-control audience-recipient-page-size" name="per_page" data-current="{{ $perPage }}">
                                    @foreach ([20, 25, 50, 100, 200] as $pageSize)
                                        <option value="{{ $pageSize }}" @selected($perPage === $pageSize)>{{ $pageSize }} rows</option>
                                    @endforeach
                                </select>
                            </label>
                            <a class="audience-grid-refresh" href="{{ request()->fullUrl() }}" aria-label="Refresh recipient schedule" title="Refresh recipient schedule">
                                <i class="fas fa-sync-alt" aria-hidden="true"></i>
                            </a>
                            <label class="sr-only" for="audience-recipient-type">Filter by recipient type</label>
                            <select id="audience-recipient-type" class="form-control audience-recipient-type" name="type">
                                <option value="all" @selected($typeFilter === 'all')>All types</option>
                                @foreach (\App\Models\ContactListMembership::CAMPAIGN_TYPES as $emailType)
                                    <option value="{{ $emailType }}" @selected($typeFilter === $emailType)>{{ ucfirst($emailType) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="audience-recipient-search-box">
                            <label class="sr-only" for="audience-recipient-search">Search recipients on this page</label>
                            <i class="fas fa-search" aria-hidden="true"></i>
                            <input
                                id="audience-recipient-search"
                                class="form-control audience-recipient-search"
                                type="search"
                                name="q"
                                value="{{ $search }}"
                                maxlength="120"
                                autocomplete="off"
                                placeholder="Search this page..."
                                aria-describedby="audience-recipient-search-help audience-recipient-results"
                            >
                            <button id="audience-recipient-clear" class="audience-recipient-clear" type="button" aria-label="Clear recipient search" @if ($search === '') hidden @endif>
                                <i class="fas fa-times" aria-hidden="true"></i>
                            </button>
                        </div>
                        <input type="hidden" name="sort" value="{{ $sortColumn }}">
                        <input type="hidden" name="direction" value="{{ $sortDirection }}">
                        <a class="audience-recipient-reset" href="{{ route('console.audiences.show', [$workspace->id, $audienceList->id]) }}">Reset filters</a>
                        <p id="audience-recipient-search-help" class="audience-recipient-hint">All imported profile fields are shown. Other tags contains extra CSV columns. Search, type filtering, and sorting cover this page only because recipient profiles are protected.</p>
                    </form>

                    <div id="audience-recipient-results" class="audience-recipient-results" role="status" aria-live="polite" data-total="{{ $contactRows->total() }}">
                        Showing {{ $contactRows->count() }} of {{ number_format($contactRows->total()) }} active recipients.
                    </div>

                    <div class="audience-recipient-table-wrap" role="region" aria-label="Recipient schedule; scroll horizontally to view all columns" tabindex="0">
                        <table id="audience-recipient-table" class="table command-os-table table-hover audience-recipient-table" data-sort-column="{{ $sortColumn }}" data-sort-direction="{{ $sortDirection }}">
                            @php
                                $recipientColumns = [
                                    ['key' => 'id', 'label' => '#', 'class' => 'audience-col-id'],
                                    ['key' => 'email', 'label' => 'Email', 'class' => 'audience-col-email'],
                                    ['key' => 'first_name', 'label' => 'First name', 'class' => 'audience-col-first'],
                                    ['key' => 'last_name', 'label' => 'Last name', 'class' => 'audience-col-last'],
                                    ['key' => 'country', 'label' => 'Country', 'class' => 'audience-col-country'],
                                    ['key' => 'age', 'label' => 'Age', 'class' => 'audience-col-age'],
                                    ['key' => 'vertical', 'label' => 'Vertical', 'class' => 'audience-col-vertical'],
                                    ['key' => 'sex', 'label' => 'Sex', 'class' => 'audience-col-sex'],
                                    ['key' => 'type', 'label' => 'Type', 'class' => 'audience-col-type'],
                                    ['key' => 'logs', 'label' => 'Logs', 'class' => 'audience-col-logs'],
                                    ['key' => 'created_at', 'label' => 'Created at', 'class' => 'audience-col-created'],
                                    ['key' => 'custom_tags', 'label' => 'Other tags', 'class' => 'audience-col-custom'],
                                ];
                            @endphp
                            <thead>
                                <tr>
                                    @foreach ($recipientColumns as $column)
                                        <th scope="col" class="{{ $column['class'] }}" aria-sort="none"><button class="audience-recipient-sort" type="button" data-sort-key="{{ $column['key'] }}" aria-pressed="false"><span>{{ $column['label'] }}</span><i class="fas fa-sort" aria-hidden="true"></i></button></th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($contactRows as $membership)
                                    @php
                                        $profile = array_replace((array) ($membership->contact?->profile ?? []), (array) ($membership->profile ?? []));
                                        $customAttributes = is_array($profile['attributes'] ?? null) ? $profile['attributes'] : [];
                                        unset($profile['attributes']);
                                        $profileFields = [
                                            'first_name' => $profile['first_name'] ?? '',
                                            'last_name' => $profile['last_name'] ?? '',
                                            'country' => $profile['country'] ?? '',
                                            'age' => $profile['age'] ?? '',
                                            'vertical' => $profile['vertical'] ?? '',
                                            'sex' => $profile['sex'] ?? $profile['gender'] ?? '',
                                        ];
                                        $standardProfileKeys = ['first_name', 'last_name', 'country', 'age', 'vertical', 'sex', 'gender'];
                                        $customTags = array_replace(array_diff_key($profile, array_flip($standardProfileKeys)), $customAttributes);
                                        $customSearchValues = [];
                                        foreach ($customTags as $key => $value) {
                                            $customSearchValues[] = (string) $key;
                                            $customSearchValues[] = is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                        }
                                        $membershipId = (string) $membership->getKey();
                                        $recipientEmail = (string) ($membership->contact?->email ?? '');
                                        $logCount = (int) ($membership->list_log_count ?? 0);
                                        $createdAt = $membership->created_at ?? $membership->joined_at;
                                        $createdAtValue = $createdAt?->getTimestamp() ?? 0;
                                        $profileSearchValues = [];
                                        foreach ($profileFields as $key => $value) {
                                            $profileSearchValues[] = str_replace('_', ' ', $key);
                                            $profileSearchValues[] = is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                        }
                                        $searchableValues = array_merge([$membershipId, $recipientEmail, $membership->email_type], $profileSearchValues, ['logs', (string) $logCount, 'created at', $createdAt?->format('Y-m-d H:i:s')], $customSearchValues);
                                        $searchableValues = array_filter($searchableValues, static fn ($value): bool => $value !== null && $value !== '');
                                        $customSortValue = implode(' ', $customSearchValues);
                                    @endphp
                                    <tr class="audience-recipient-row"
                                        data-search="{{ implode(' ', $searchableValues) }}"
                                        data-id="{{ mb_strtolower($membershipId) }}"
                                        data-email="{{ mb_strtolower($recipientEmail) }}"
                                        data-first-name="{{ mb_strtolower((string) $profileFields['first_name']) }}"
                                        data-last-name="{{ mb_strtolower((string) $profileFields['last_name']) }}"
                                        data-country="{{ mb_strtolower((string) $profileFields['country']) }}"
                                        data-age="{{ (string) $profileFields['age'] }}"
                                        data-vertical="{{ mb_strtolower((string) $profileFields['vertical']) }}"
                                        data-sex="{{ mb_strtolower((string) $profileFields['sex']) }}"
                                        data-type="{{ mb_strtolower($membership->email_type) }}"
                                        data-logs="{{ $logCount }}"
                                        data-created-at="{{ $createdAtValue }}"
                                        data-custom-tags="{{ mb_strtolower($customSortValue) }}">
                                        <td class="audience-col-id"><span class="audience-recipient-id" title="{{ $membershipId }}">#{{ substr($membershipId, 0, 8) }}</span></td>
                                        <td class="audience-col-email"><span class="audience-recipient-email font-mono">{{ $recipientEmail !== '' ? $recipientEmail : '—' }}</span></td>
                                        <td class="audience-col-first">{{ $profileFields['first_name'] !== '' ? $profileFields['first_name'] : '—' }}</td>
                                        <td class="audience-col-last">{{ $profileFields['last_name'] !== '' ? $profileFields['last_name'] : '—' }}</td>
                                        <td class="audience-col-country">{{ $profileFields['country'] !== '' ? $profileFields['country'] : '—' }}</td>
                                        <td class="audience-col-age"><span class="audience-recipient-number">{{ $profileFields['age'] !== '' ? $profileFields['age'] : '—' }}</span></td>
                                        <td class="audience-col-vertical">{{ $profileFields['vertical'] !== '' ? $profileFields['vertical'] : '—' }}</td>
                                        <td class="audience-col-sex">{{ $profileFields['sex'] !== '' ? $profileFields['sex'] : '—' }}</td>
                                        <td class="audience-col-type"><span class="badge-command-pill">{{ ucfirst($membership->email_type) }}</span></td>
                                        <td class="audience-col-logs"><span class="audience-recipient-number" title="Recorded membership events for this recipient in this list">{{ $logCount }}</span></td>
                                        <td class="audience-col-created"><time class="audience-recipient-time" datetime="{{ $createdAt?->toIso8601String() }}">{{ $createdAt?->format('Y-m-d H:i:s') ?? '—' }}</time></td>
                                        <td class="audience-col-custom">
                                            @if ($customTags === [])
                                                <span class="text-muted">—</span>
                                            @else
                                                <div class="audience-recipient-custom-tags">
                                                    @foreach ($customTags as $key => $value)
                                                        @if ($value !== null && $value !== '')
                                                            <span class="audience-recipient-custom-tag"><span class="audience-recipient-custom-tag-key">{{ $key }}</span><span aria-hidden="true">·</span><span>{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</span></span>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                <tr id="audience-recipient-no-results" class="audience-recipient-no-results" hidden>
                                    <td colspan="{{ count($recipientColumns) }}">
                                        <div class="audience-recipient-empty py-4">
                                            <span class="audience-recipient-empty-icon" aria-hidden="true"><i class="fas fa-search"></i></span>
                                            <h3>No recipients match these filters</h3>
                                            <p>Adjust your search or type filter, or check another page.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="audience-snapshot-footer">
                        <small class="text-muted">Page {{ $contactRows->currentPage() }} of {{ $contactRows->lastPage() }} · {{ $contactRows->count() }} recipients loaded on this page</small>
                        {{ $contactRows->links() }}
                    </div>
                @endif
            </section>
        @else
            <div class="command-os-info-banner" role="note">
                <div class="command-os-info-banner-icon"><i class="fas fa-user-shield" aria-hidden="true"></i></div>
                <div class="command-os-info-banner-content">Recipient addresses and imported tags are restricted to workspace owners and admins.</div>
            </div>
        @endif

        <section class="audience-snapshot-panel" aria-labelledby="audience-events-title">
            <div class="audience-snapshot-panel-heading">
                <div>
                    <h2 id="audience-events-title"><i class="fas fa-history mr-2 text-purple" aria-hidden="true"></i>Recent membership activity</h2>
                    <p>The latest recorded additions and removals for this list.</p>
                </div>
                <span class="badge-command-pill">Latest 10 events</span>
            </div>

            @if ($membershipEvents->isEmpty())
                <div class="audience-recipient-empty py-4">
                    <span class="audience-recipient-empty-icon" aria-hidden="true"><i class="fas fa-history"></i></span>
                    <h3>No membership activity recorded</h3>
                    <p>New recipient additions and removals will appear here.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table command-os-table table-sm table-hover mb-0">
                        <thead>
                            <tr><th scope="col">Change</th><th scope="col">Source</th><th scope="col" class="text-right">Date and time</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($membershipEvents as $event)
                                <tr>
                                    <td>
                                        <span class="badge-command-pill {{ $event->action === 'added' ? 'status-ready' : '' }}">
                                            <i class="fas {{ $event->action === 'added' ? 'fa-plus-circle' : 'fa-minus-circle' }} mr-1" aria-hidden="true"></i>
                                            {{ ucfirst($event->action) }}
                                        </span>
                                    </td>
                                    <td><span class="audience-snapshot-event-source">{{ ucfirst(str_replace('_', ' ', $event->source)) }}</span></td>
                                    <td class="text-right"><time class="audience-snapshot-event-time" datetime="{{ $event->occurred_at?->toIso8601String() }}">{{ $event->occurred_at?->format('M j, Y · H:i:s') }}</time></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const form = document.querySelector('#audience-recipient-filter');
            if (!form) return;

            const searchInput = form.querySelector('#audience-recipient-search');
            const clearButton = form.querySelector('#audience-recipient-clear');
            const typeFilter = form.querySelector('#audience-recipient-type');
            const pageSize = form.querySelector('#audience-recipient-page-size');
            const table = document.querySelector('#audience-recipient-table');
            const tbody = table.querySelector('tbody');
            const resultStatus = document.querySelector('#audience-recipient-results');
            const noResultsRow = document.querySelector('#audience-recipient-no-results');
            const rows = Array.from(table.querySelectorAll('.audience-recipient-row'));
            const sortButtons = Array.from(table.querySelectorAll('[data-sort-key]'));
            const sortField = form.querySelector('[name="sort"]');
            const directionField = form.querySelector('[name="direction"]');
            const paginationLinks = Array.from(document.querySelectorAll('.audience-snapshot-footer .pagination a[href]'));
            const normalize = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
            const sortDataKey = (column) => column.replace(/_([a-z])/g, (_match, letter) => letter.toUpperCase());
            let sortColumn = table.dataset.sortColumn || '';
            let sortDirection = table.dataset.sortDirection === 'desc' ? 'desc' : 'asc';

            const sortRows = () => {
                if (!sortColumn) return;
                const sortedRows = [...rows].sort((firstRow, secondRow) => {
                    const dataKey = sortDataKey(sortColumn);
                    const firstValue = normalize(firstRow.dataset[dataKey]);
                    const secondValue = normalize(secondRow.dataset[dataKey]);
                    return firstValue.localeCompare(secondValue, undefined, { numeric: true }) * (sortDirection === 'desc' ? -1 : 1);
                });
                sortedRows.forEach((row) => tbody.appendChild(row));
                tbody.appendChild(noResultsRow);
            };

            const updateSortControls = () => {
                sortButtons.forEach((button) => {
                    const active = button.dataset.sortKey === sortColumn;
                    const header = button.closest('th');
                    const icon = button.querySelector('.fas');
                    header.setAttribute('aria-sort', active ? (sortDirection === 'asc' ? 'ascending' : 'descending') : 'none');
                    button.setAttribute('aria-pressed', String(active));
                    icon.className = `fas ${active ? (sortDirection === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort'}`;
                });
                sortField.value = sortColumn;
                directionField.value = sortDirection;
            };

            const updatePaginationUrls = () => {
                paginationLinks.forEach((link) => {
                    const url = new URL(link.href, window.location.href);
                    if (searchInput.value.trim()) url.searchParams.set('q', searchInput.value.trim());
                    else url.searchParams.delete('q');
                    if (typeFilter.value !== 'all') url.searchParams.set('type', typeFilter.value);
                    else url.searchParams.delete('type');
                    url.searchParams.set('per_page', pageSize.value);
                    if (sortColumn) url.searchParams.set('sort', sortColumn);
                    else url.searchParams.delete('sort');
                    url.searchParams.set('direction', sortDirection);
                    link.href = url.toString();
                });
            };

            const applySearch = (updateUrl = false) => {
                const query = searchInput.value.trim();
                const terms = normalize(query).split(/\s+/).filter(Boolean);
                let visibleRows = 0;

                rows.forEach((row) => {
                    const haystack = normalize(row.dataset.search);
                    const matchesSearch = terms.every((term) => haystack.includes(term));
                    const matchesType = typeFilter.value === 'all' || row.dataset.type === typeFilter.value;
                    const matches = matchesSearch && matchesType;
                    row.hidden = !matches;
                    if (matches) visibleRows++;
                });

                const filtering = terms.length > 0 || typeFilter.value !== 'all';
                noResultsRow.hidden = visibleRows > 0 || !filtering;
                clearButton.hidden = query.length === 0;
                const total = Number(resultStatus.dataset.total || 0).toLocaleString();
                resultStatus.textContent = filtering
                    ? `Showing ${visibleRows} of ${rows.length} recipients on this page · ${total} total active.`
                    : `Showing ${rows.length} of ${total} active recipients.`;

                if (updateUrl) {
                    const url = new URL(window.location.href);
                    if (query) url.searchParams.set('q', query);
                    else url.searchParams.delete('q');
                    if (typeFilter.value !== 'all') url.searchParams.set('type', typeFilter.value);
                    else url.searchParams.delete('type');
                    url.searchParams.set('per_page', pageSize.value);
                    if (sortColumn) url.searchParams.set('sort', sortColumn);
                    else url.searchParams.delete('sort');
                    url.searchParams.set('direction', sortDirection);
                    window.history.replaceState(window.history.state, '', url);
                    updatePaginationUrls();
                }
            };

            form.addEventListener('submit', (event) => {
                if (pageSize.value !== pageSize.dataset.current) return;
                event.preventDefault();
                applySearch(true);
            });
            searchInput.addEventListener('input', () => applySearch(true));
            typeFilter.addEventListener('change', () => applySearch(true));
            pageSize.addEventListener('change', () => form.requestSubmit());
            clearButton.addEventListener('click', () => {
                searchInput.value = '';
                applySearch(true);
                searchInput.focus();
            });
            sortButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    sortDirection = sortColumn === button.dataset.sortKey && sortDirection === 'asc' ? 'desc' : 'asc';
                    sortColumn = button.dataset.sortKey;
                    sortRows();
                    updateSortControls();
                    applySearch(true);
                });
            });
            sortRows();
            updateSortControls();
            applySearch();
            updatePaginationUrls();
        })();
    </script>
@endpush
