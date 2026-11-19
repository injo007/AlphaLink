@extends('layouts.admin-shell')

@section('title', $audienceList->name)

@push('styles')
    <style>
        .audience-snapshot-page { display: grid; gap: 1rem; }
        .audience-snapshot-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1.25rem 1.5rem; border: 1px solid var(--co-border); border-left: 4px solid var(--co-purple); border-radius: 16px; background: var(--co-surface); box-shadow: var(--co-shadow); }
        .audience-snapshot-identity { display: flex; align-items: center; gap: 1rem; min-width: 0; }
        .audience-snapshot-icon { display: grid; flex: 0 0 48px; width: 48px; height: 48px; place-items: center; border-radius: 14px; background: color-mix(in srgb, var(--co-purple) 12%, var(--co-surface)); color: var(--co-purple); font-size: 1.1rem; }
        .audience-snapshot-title { margin: 0; color: var(--co-ink); font-size: clamp(1.15rem, 2vw, 1.55rem); font-weight: 750; line-height: 1.2; overflow-wrap: anywhere; }
        .audience-snapshot-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem .75rem; margin-top: .35rem; color: var(--co-muted); font-size: .78rem; }
        .audience-snapshot-actions { display: flex; flex: 0 0 auto; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: .6rem; }
        .audience-snapshot-summary { display: grid; grid-template-columns: minmax(210px, .7fr) minmax(0, 1.8fr); gap: 1rem; }
        .audience-snapshot-stat, .audience-snapshot-description { min-width: 0; padding: 1.15rem 1.35rem; border: 1px solid var(--co-border); border-radius: 15px; background: var(--co-surface); box-shadow: var(--co-shadow); }
        .audience-snapshot-stat-label, .audience-snapshot-description-label { display: flex; align-items: center; gap: .5rem; margin: 0; color: var(--co-muted); font-size: .72rem; font-weight: 750; letter-spacing: .07em; text-transform: uppercase; }
        .audience-snapshot-stat-value { display: block; margin: .4rem 0 .2rem; color: var(--co-ink); font-size: clamp(1.65rem, 3vw, 2.1rem); font-variant-numeric: tabular-nums; font-weight: 800; line-height: 1; }
        .audience-snapshot-description-copy { margin: .55rem 0 0; color: var(--co-ink); font-size: .9rem; line-height: 1.55; overflow-wrap: anywhere; }
        .audience-snapshot-panel { min-width: 0; padding: 1.1rem 1.25rem; border: 1px solid var(--co-border); border-radius: 16px; background: var(--co-surface); box-shadow: var(--co-shadow); }
        .audience-snapshot-panel-heading { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: .8rem; margin-bottom: 1rem; padding-bottom: .85rem; border-bottom: 1px solid var(--co-border); }
        .audience-snapshot-panel-heading h2 { margin: 0; color: var(--co-ink); font-size: .95rem; font-weight: 800; letter-spacing: .035em; }
        .audience-snapshot-panel-heading p { max-width: 720px; margin: .3rem 0 0; color: var(--co-muted); font-size: .78rem; line-height: 1.5; }
        .audience-recipient-toolbar { display: grid; grid-template-columns: minmax(240px, 1.8fr) minmax(145px, 1fr) minmax(120px, .7fr) auto; align-items: end; gap: .75rem; margin-bottom: .8rem; }
        .audience-recipient-field { display: grid; min-width: 0; gap: .35rem; }
        .audience-recipient-field label { margin: 0; color: var(--co-ink); font-size: .72rem; font-weight: 750; }
        .audience-recipient-search-box { position: relative; }
        .audience-recipient-search-box > .fas { position: absolute; top: 50%; left: .85rem; transform: translateY(-50%); color: var(--co-muted); pointer-events: none; }
        .audience-recipient-search { min-height: 42px; padding-left: 2.3rem; }
        .audience-recipient-clear { position: absolute; top: 50%; right: .4rem; width: 32px; height: 32px; transform: translateY(-50%); border: 0; border-radius: 8px; background: transparent; color: var(--co-muted); cursor: pointer; }
        .audience-recipient-clear:hover { background: color-mix(in srgb, var(--co-purple) 10%, transparent); color: var(--co-purple); }
        .audience-recipient-clear:focus-visible, .audience-recipient-search:focus-visible, .audience-recipient-page-size:focus-visible { outline: 3px solid color-mix(in srgb, var(--co-purple) 45%, transparent); outline-offset: 2px; }
        .audience-recipient-page-size { min-height: 42px; }
        .audience-recipient-type { min-height: 42px; }
        .audience-recipient-hint { grid-column: 1 / -1; margin: -.1rem 0 0; color: var(--co-muted); font-size: .72rem; line-height: 1.45; }
        .audience-recipient-results { margin: 0 0 .65rem; padding: .45rem .65rem; border-radius: 8px; background: color-mix(in srgb, var(--co-purple) 5%, var(--co-surface)); color: var(--co-muted); font-size: .73rem; }
        .audience-recipient-sort { display: inline-flex; align-items: center; gap: .4rem; padding: .15rem 0; border: 0; background: transparent; color: inherit; font: inherit; letter-spacing: inherit; text-transform: inherit; cursor: pointer; }
        .audience-recipient-sort:hover, .audience-recipient-sort[aria-pressed="true"] { color: var(--co-purple); }
        .audience-recipient-sort:focus-visible, .audience-recipient-reset:focus-visible { outline: 3px solid color-mix(in srgb, var(--co-purple) 45%, transparent); outline-offset: 3px; }
        .audience-recipient-sort .fas { color: var(--co-purple); font-size: .7rem; }
        .audience-recipient-reset { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; padding: .55rem .8rem; border: 1px solid var(--co-border); border-radius: .45rem; background: var(--co-surface); color: var(--co-muted); font-size: .75rem; font-weight: 700; text-decoration: none; white-space: nowrap; }
        .audience-recipient-reset:hover { border-color: var(--co-purple); color: var(--co-purple); }
        .audience-recipient-table-wrap { width: 100%; overflow-x: auto; border: 1px solid var(--co-border); border-radius: 11px; }
        .audience-recipient-table { min-width: 640px; margin: 0; }
        .audience-recipient-table thead th { padding: .75rem .85rem; color: var(--co-muted); font-size: .68rem; font-weight: 800; letter-spacing: .07em; white-space: nowrap; }
        .audience-recipient-table tbody td { padding: .8rem .85rem; vertical-align: middle; }
        .audience-recipient-email { max-width: 300px; color: var(--co-ink); font-size: .78rem; overflow-wrap: anywhere; }
        .audience-recipient-tags { display: flex; flex-wrap: wrap; gap: .35rem; max-width: 720px; }
        .audience-recipient-tag { display: inline-flex; flex-wrap: wrap; gap: .2rem .35rem; max-width: 100%; padding: .25rem .5rem; border: 1px solid color-mix(in srgb, var(--co-purple) 18%, var(--co-border)); border-radius: 7px; background: color-mix(in srgb, var(--co-purple) 5%, var(--co-surface)); color: var(--co-ink); font-size: .68rem; line-height: 1.35; overflow-wrap: anywhere; }
        .audience-recipient-tag-key { color: var(--co-purple); font-weight: 750; }
        .audience-recipient-tag-value { overflow-wrap: anywhere; }
        .audience-recipient-row[hidden], .audience-recipient-no-results[hidden] { display: none !important; }
        .audience-recipient-empty { padding: 2.5rem 1rem; text-align: center; }
        .audience-recipient-empty-icon { display: grid; width: 46px; height: 46px; margin: 0 auto .8rem; place-items: center; border-radius: 14px; background: color-mix(in srgb, var(--co-purple) 10%, var(--co-surface)); color: var(--co-purple); }
        .audience-recipient-empty h3 { margin: 0 0 .35rem; color: var(--co-ink); font-size: .95rem; font-weight: 750; }
        .audience-recipient-empty p { margin: 0; color: var(--co-muted); font-size: .8rem; }
        .audience-snapshot-footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; padding-top: .9rem; }
        .audience-snapshot-events { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; align-items: center; gap: .8rem; }
        .audience-snapshot-event-source, .audience-snapshot-event-time { color: var(--co-muted); font-size: .74rem; }
        @media (max-width: 767.98px) {
            .audience-snapshot-header { align-items: flex-start; flex-direction: column; padding: 1rem; }
            .audience-snapshot-actions { justify-content: flex-start; }
            .audience-snapshot-summary { grid-template-columns: 1fr; }
            .audience-snapshot-panel { padding: .9rem; }
            .audience-recipient-toolbar { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .audience-recipient-search-field, .audience-recipient-hint { grid-column: 1 / -1; }
            .audience-snapshot-events { grid-template-columns: minmax(0, 1fr) auto; }
            .audience-snapshot-event-time { grid-column: 1 / -1; }
        }
        @media (max-width: 480px) {
            .audience-snapshot-identity { align-items: flex-start; gap: .7rem; }
            .audience-snapshot-icon { flex-basis: 40px; width: 40px; height: 40px; }
            .audience-recipient-toolbar { grid-template-columns: 1fr; }
            .audience-recipient-search-field, .audience-recipient-hint { grid-column: auto; }
        }
    </style>
@endpush

@section('content')
    <div class="command-os-entity-page audience-snapshot-page">
        <header class="audience-snapshot-header" aria-labelledby="audience-show-title">
            <div class="audience-snapshot-identity">
                <span class="audience-snapshot-icon" aria-hidden="true"><i class="fas fa-address-book"></i></span>
                <div class="min-w-0">
                    <p class="command-os-report-eyebrow mb-1">AUDIENCE LIST</p>
                    <h1 id="audience-show-title" class="audience-snapshot-title">{{ $audienceList->name }}</h1>
                    <div class="audience-snapshot-meta">
                        <span class="font-mono">{{ $audienceList->slug }}</span>
                        <span>Created {{ $audienceList->created_at?->format('M j, Y') }}</span>
                    </div>
                </div>
            </div>
            <div class="audience-snapshot-actions">
                <span class="badge-command-pill {{ $audienceList->status === 'active' ? 'status-ready' : '' }}">{{ ucfirst(str_replace('_', ' ', $audienceList->status)) }}</span>
                <a class="btn-command-action" href="{{ route('console.audiences.index', $workspace->id) }}">
                    <i class="fas fa-arrow-left mr-1" aria-hidden="true"></i> Back to lists
                </a>
            </div>
        </header>

        <section class="audience-snapshot-summary" aria-label="List overview">
            <div class="audience-snapshot-stat">
                <p class="audience-snapshot-stat-label"><i class="fas fa-users" aria-hidden="true"></i> Active recipients</p>
                <strong class="audience-snapshot-stat-value">{{ number_format($audienceList->active_members_count) }}</strong>
                <small class="text-muted">Currently in this list</small>
            </div>
            <div class="audience-snapshot-description">
                <p class="audience-snapshot-description-label"><i class="fas fa-align-left" aria-hidden="true"></i> About this list</p>
                <p class="audience-snapshot-description-copy">{{ $audienceList->description ?: 'No description has been added to this list.' }}</p>
            </div>
        </section>

        @if ($canViewContacts)
            <section class="audience-snapshot-panel" aria-labelledby="audience-recipients-title">
                <div class="audience-snapshot-panel-heading">
                    <div>
                        <h2 id="audience-recipients-title"><i class="fas fa-address-card mr-2 text-purple" aria-hidden="true"></i>Recipients</h2>
                        <p>Search, filter, or sort recipients on the current page. Addresses and imported profile tags are read-only and visible to workspace owners and admins.</p>
                    </div>
                    <span class="badge-command-pill">{{ number_format($contactRows->total()) }} active</span>
                </div>

                @if ($contactRows->isEmpty())
                    <div class="audience-recipient-empty">
                        <span class="audience-recipient-empty-icon" aria-hidden="true"><i class="fas fa-inbox"></i></span>
                        <h3>No active recipients yet</h3>
                        <p>Import recipients into this list to see their email addresses and tags here.</p>
                    </div>
                @else
                    <form id="audience-recipient-filter" class="audience-recipient-toolbar" method="get" action="{{ route('console.audiences.show', [$workspace->id, $audienceList->id]) }}">
                        <div class="audience-recipient-field audience-recipient-search-field">
                            <label for="audience-recipient-search">Search this page</label>
                            <div class="audience-recipient-search-box">
                                <i class="fas fa-search" aria-hidden="true"></i>
                                <input
                                    id="audience-recipient-search"
                                    class="form-control audience-recipient-search"
                                    type="search"
                                    name="q"
                                    value="{{ $search }}"
                                    maxlength="120"
                                    autocomplete="off"
                                    placeholder="Email, type, or any imported tag"
                                    aria-describedby="audience-recipient-search-help audience-recipient-results"
                                >
                                <button id="audience-recipient-clear" class="audience-recipient-clear" type="button" aria-label="Clear recipient search" @if ($search === '') hidden @endif>
                                    <i class="fas fa-times" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div class="audience-recipient-field">
                            <label for="audience-recipient-type">Recipient type</label>
                            <select id="audience-recipient-type" class="form-control audience-recipient-type" name="type">
                                <option value="all" @selected($typeFilter === 'all')>All types</option>
                                @foreach (\App\Models\ContactListMembership::CAMPAIGN_TYPES as $emailType)
                                    <option value="{{ $emailType }}" @selected($typeFilter === $emailType)>{{ ucfirst($emailType) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="audience-recipient-field">
                            <label for="audience-recipient-page-size">Rows per page</label>
                            <select id="audience-recipient-page-size" class="form-control audience-recipient-page-size" name="per_page" data-current="{{ $perPage }}">
                                @foreach ([25, 50, 100, 200] as $pageSize)
                                    <option value="{{ $pageSize }}" @selected($perPage === $pageSize)>{{ $pageSize }} rows</option>
                                @endforeach
                            </select>
                        </div>
                        <input type="hidden" name="sort" value="{{ $sortColumn }}">
                        <input type="hidden" name="direction" value="{{ $sortDirection }}">
                        <button class="btn-command-action" type="submit"><i class="fas fa-search mr-1" aria-hidden="true"></i> Search</button>
                        <a class="audience-recipient-reset" href="{{ route('console.audiences.show', [$workspace->id, $audienceList->id]) }}">Reset filters</a>
                        <p id="audience-recipient-search-help" class="audience-recipient-hint">Search, type filters, and sorting apply to recipients on this page. Use pagination to browse the full list; recipient profile data is protected and not searched across the full list.</p>
                    </form>

                    <div id="audience-recipient-results" class="audience-recipient-results" role="status" aria-live="polite" data-total="{{ $contactRows->total() }}">
                        Showing {{ $contactRows->count() }} loaded recipients. {{ number_format($contactRows->total()) }} active recipients in this list.
                    </div>

                    <div class="audience-recipient-table-wrap">
                        <table id="audience-recipient-table" class="table command-os-table table-hover audience-recipient-table" data-sort-column="{{ $sortColumn }}" data-sort-direction="{{ $sortDirection }}">
                            <thead>
                                <tr>
                                    <th scope="col" aria-sort="none"><button class="audience-recipient-sort" type="button" data-sort-key="recipient" aria-pressed="false"><span>Recipient</span><i class="fas fa-sort" aria-hidden="true"></i></button></th>
                                    <th scope="col" aria-sort="none"><button class="audience-recipient-sort" type="button" data-sort-key="type" aria-pressed="false"><span>Type</span><i class="fas fa-sort" aria-hidden="true"></i></button></th>
                                    <th scope="col" aria-sort="none"><button class="audience-recipient-sort" type="button" data-sort-key="details" aria-pressed="false"><span>Imported details</span><i class="fas fa-sort" aria-hidden="true"></i></button></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($contactRows as $membership)
                                    @php
                                        $profile = array_replace((array) ($membership->contact?->profile ?? []), (array) ($membership->profile ?? []));
                                        $customTags = is_array($profile['attributes'] ?? null) ? $profile['attributes'] : [];
                                        unset($profile['attributes']);
                                        $searchableValues = [$membership->contact?->email, $membership->email_type];
                                        foreach ([$profile, $customTags] as $tagGroup) {
                                            foreach ($tagGroup as $key => $value) {
                                                $searchableValues[] = (string) $key;
                                                $searchableValues[] = is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                            }
                                        }
                                        $searchableValues = array_filter($searchableValues, static fn ($value): bool => $value !== null && $value !== '');
                                    @endphp
                                    <tr class="audience-recipient-row" data-search="{{ implode(' ', $searchableValues) }}" data-recipient="{{ mb_strtolower($membership->contact?->email ?? '') }}" data-type="{{ mb_strtolower($membership->email_type) }}" data-details="{{ implode(' ', array_slice($searchableValues, 2)) }}">
                                        <td><span class="audience-recipient-email font-mono">{{ $membership->contact?->email ?? '—' }}</span></td>
                                        <td><span class="badge-command-pill">{{ ucfirst($membership->email_type) }}</span></td>
                                        <td>
                                            @if ($profile === [] && $customTags === [])
                                                <span class="text-muted font-size-xs">No imported tags</span>
                                            @else
                                                <div class="audience-recipient-tags">
                                                    @foreach ($profile as $key => $value)
                                                        @if ($value !== null && $value !== '')
                                                            <span class="audience-recipient-tag"><span class="audience-recipient-tag-key">{{ $key }}</span><span aria-hidden="true">·</span><span class="audience-recipient-tag-value">{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</span></span>
                                                        @endif
                                                    @endforeach
                                                    @foreach ($customTags as $key => $value)
                                                        @if ($value !== null && $value !== '')
                                                            <span class="audience-recipient-tag"><span class="audience-recipient-tag-key">{{ $key }}</span><span aria-hidden="true">·</span><span class="audience-recipient-tag-value">{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</span></span>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                <tr id="audience-recipient-no-results" class="audience-recipient-no-results" hidden>
                                    <td colspan="3">
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
            let sortColumn = table.dataset.sortColumn || '';
            let sortDirection = table.dataset.sortDirection === 'desc' ? 'desc' : 'asc';

            const sortRows = () => {
                if (!sortColumn) return;
                const sortedRows = [...rows].sort((firstRow, secondRow) => {
                    const firstValue = normalize(firstRow.dataset[sortColumn]);
                    const secondValue = normalize(secondRow.dataset[sortColumn]);
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
                    ? `Showing ${visibleRows} of ${rows.length} loaded recipients. ${total} active recipients in this list.`
                    : `Showing ${rows.length} loaded recipients. ${total} active recipients in this list.`;

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
