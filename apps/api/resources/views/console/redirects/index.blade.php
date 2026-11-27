@extends('layouts.admin-shell')

@section('title', 'Redirects & Rotators')

@push('styles')
    <style>
        .redirects-page {
            color: var(--co-ink, #17203e);
            min-width: 0;
            width: 100%;
        }

        .redirects-shell {
            background: var(--co-surface, #fff);
            border: 1px solid var(--co-border, #dfe3f1);
            border-radius: 1rem;
            box-shadow: 0 8px 26px rgb(29 39 84 / 5%);
            min-width: 0;
            overflow: hidden;
        }

        .redirects-hero {
            align-items: center;
            background: linear-gradient(115deg, color-mix(in srgb, var(--co-purple, #793df6) 8%, var(--co-surface, #fff)), var(--co-surface, #fff) 62%);
            border-bottom: 1px solid var(--co-border, #dfe3f1);
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: space-between;
            padding: clamp(1rem, 2.2vw, 1.55rem);
        }

        .redirects-heading { align-items: center; display: flex; gap: .9rem; min-width: 0; }
        .redirects-mark { align-items: center; background: color-mix(in srgb, var(--co-purple, #793df6) 12%, var(--co-surface, #fff)); border: 1px solid color-mix(in srgb, var(--co-purple, #793df6) 22%, var(--co-border, #dfe3f1)); border-radius: .85rem; color: var(--co-purple, #793df6); display: inline-flex; flex: 0 0 2.8rem; height: 2.8rem; justify-content: center; }
        .redirects-eyebrow { color: var(--co-purple, #793df6); display: block; font-size: .66rem; font-weight: 800; letter-spacing: .12em; margin-bottom: .2rem; text-transform: uppercase; }
        .redirects-heading h1 { color: var(--co-ink, #17203e); font-size: clamp(1.25rem, 2vw, 1.6rem); font-weight: 800; letter-spacing: -.025em; margin: 0; }
        .redirects-heading p { color: var(--co-muted, #5f6b89); font-size: .84rem; margin: .22rem 0 0; max-width: 54rem; }
        .redirects-meta { align-items: center; background: var(--co-surface-muted, #f7f8fd); border: 1px solid var(--co-border, #dfe3f1); border-radius: .7rem; color: var(--co-muted, #5f6b89); display: inline-flex; font-size: .72rem; font-weight: 750; gap: .45rem; padding: .55rem .75rem; white-space: nowrap; }
        .redirects-meta i { color: var(--co-purple, #793df6); }
        .redirects-content { display: grid; gap: 1rem; min-width: 0; padding: clamp(.9rem, 1.8vw, 1.35rem); }
        .redirects-metrics { display: grid; gap: .75rem; grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .redirects-metric { background: var(--co-surface, #fff); border: 1px solid var(--co-border, #dfe3f1); border-radius: .8rem; display: flex; gap: .8rem; min-width: 0; padding: .95rem; }
        .redirects-metric-icon { align-items: center; background: color-mix(in srgb, var(--metric-color, var(--co-purple, #793df6)) 10%, var(--co-surface, #fff)); border-radius: .65rem; color: var(--metric-color, var(--co-purple, #793df6)); display: inline-flex; flex: 0 0 2.45rem; height: 2.45rem; justify-content: center; }
        .redirects-metric small { color: var(--co-muted, #5f6b89); display: block; font-size: .68rem; font-weight: 800; letter-spacing: .055em; text-transform: uppercase; }
        .redirects-metric strong { color: var(--co-ink, #17203e); display: block; font-size: 1.25rem; font-variant-numeric: tabular-nums; line-height: 1.25; margin-top: .12rem; }
        .redirects-metric span { color: var(--co-muted, #5f6b89); display: block; font-size: .72rem; margin-top: .15rem; }
        .redirects-note { align-items: flex-start; background: color-mix(in srgb, var(--co-blue, #277bff) 7%, var(--co-surface, #fff)); border: 1px solid color-mix(in srgb, var(--co-blue, #277bff) 22%, var(--co-border, #dfe3f1)); border-radius: .75rem; color: var(--co-ink, #17203e); display: flex; gap: .65rem; padding: .85rem 1rem; }
        .redirects-note i { color: var(--co-blue, #277bff); margin-top: .15rem; }
        .redirects-note p { font-size: .78rem; line-height: 1.55; margin: 0; }
        .redirects-note strong { font-weight: 800; }
        .redirects-section { background: var(--co-surface, #fff); border: 1px solid var(--co-border, #dfe3f1); border-radius: .85rem; min-width: 0; overflow: hidden; }
        .redirects-section-head { align-items: center; border-bottom: 1px solid var(--co-border, #dfe3f1); display: flex; flex-wrap: wrap; gap: .6rem; justify-content: space-between; padding: .95rem 1rem; }
        .redirects-section-head h2 { color: var(--co-ink, #17203e); font-size: .86rem; font-weight: 800; letter-spacing: .045em; margin: 0; text-transform: uppercase; }
        .redirects-section-head p { color: var(--co-muted, #5f6b89); font-size: .74rem; margin: .2rem 0 0; }
        .redirects-count { background: color-mix(in srgb, var(--co-purple, #793df6) 9%, var(--co-surface, #fff)); border: 1px solid color-mix(in srgb, var(--co-purple, #793df6) 18%, var(--co-border, #dfe3f1)); border-radius: 999px; color: var(--co-purple, #793df6); font-size: .7rem; font-weight: 800; padding: .3rem .55rem; white-space: nowrap; }
        .redirects-filter-card { background: var(--co-surface-muted, #f7f8fd); border: 1px solid var(--co-border, #dfe3f1); border-radius: .8rem; padding: .85rem; }
        .redirects-filter-chips { align-items: center; display: flex; flex-wrap: wrap; gap: .45rem; margin-bottom: .8rem; }
        .redirects-filter-chip { align-items: center; background: var(--co-surface, #fff); border: 1px solid var(--co-border, #dfe3f1); border-radius: .6rem; color: var(--co-ink, #17203e); display: inline-flex; font-size: .72rem; font-weight: 750; gap: .4rem; min-height: 2.25rem; padding: .35rem .65rem; text-decoration: none; transition: background-color .15s ease, border-color .15s ease, color .15s ease; }
        .redirects-filter-chip:hover, .redirects-filter-chip.is-active { background: color-mix(in srgb, var(--co-purple, #793df6) 9%, var(--co-surface, #fff)); border-color: color-mix(in srgb, var(--co-purple, #793df6) 35%, var(--co-border, #dfe3f1)); color: var(--co-purple, #793df6); text-decoration: none; }
        .redirects-filter-chip:focus-visible, .redirects-clear-search:focus-visible, .redirects-filter-form button:focus-visible, .redirects-filter-form input:focus-visible, .redirects-filter-form select:focus-visible { outline: 3px solid color-mix(in srgb, var(--co-purple, #793df6) 38%, transparent); outline-offset: 2px; }
        .redirects-filter-chip-count { background: var(--co-surface-muted, #f7f8fd); border-radius: 999px; font-variant-numeric: tabular-nums; min-width: 1.35rem; padding: .12rem .35rem; text-align: center; }
        .redirects-filter-form { align-items: end; display: grid; gap: .65rem; grid-template-columns: minmax(14rem, 1.8fr) minmax(12rem, 1fr) minmax(11rem, .9fr) auto auto; }
        .redirects-filter-field { min-width: 0; }
        .redirects-filter-field label { color: var(--co-muted, #5f6b89); display: block; font-size: .66rem; font-weight: 800; letter-spacing: .05em; margin-bottom: .3rem; text-transform: uppercase; }
        .redirects-filter-form input, .redirects-filter-form select { background: var(--co-surface, #fff); border: 1px solid var(--co-border-strong, #d3d8eb); border-radius: .55rem; color: var(--co-ink, #17203e); font: inherit; font-size: .78rem; height: 2.55rem; min-width: 0; padding: 0 .65rem; width: 100%; }
        .redirects-search-wrap { position: relative; }
        .redirects-search-wrap input { padding-right: 2.4rem; }
        .redirects-clear-search { align-items: center; background: transparent; border: 0; border-radius: .4rem; color: var(--co-muted, #5f6b89); display: inline-flex; height: 2rem; justify-content: center; position: absolute; right: .25rem; text-decoration: none; top: .275rem; width: 2rem; }
        .redirects-clear-search:hover { background: var(--co-surface-muted, #f7f8fd); color: var(--co-purple, #793df6); }
        .redirects-filter-button { align-items: center; background: var(--co-purple, #793df6); border: 1px solid var(--co-purple, #793df6); border-radius: .55rem; color: #fff; cursor: pointer; display: inline-flex; font-size: .76rem; font-weight: 800; gap: .4rem; height: 2.55rem; justify-content: center; padding: 0 .8rem; white-space: nowrap; }
        .redirects-filter-button:hover { filter: brightness(1.06); }
        .redirects-reset-link { align-items: center; border: 1px solid var(--co-border-strong, #d3d8eb); border-radius: .55rem; color: var(--co-muted, #5f6b89); display: inline-flex; font-size: .74rem; font-weight: 750; height: 2.55rem; justify-content: center; padding: 0 .7rem; text-decoration: none; white-space: nowrap; }
        .redirects-reset-link:hover { background: var(--co-surface, #fff); border-color: var(--co-purple, #793df6); color: var(--co-purple, #793df6); text-decoration: none; }
        .redirects-result-line { align-items: center; color: var(--co-muted, #5f6b89); display: flex; flex-wrap: wrap; font-size: .72rem; gap: .3rem .65rem; justify-content: space-between; padding: .65rem .9rem; }
        .redirects-result-line strong { color: var(--co-ink, #17203e); font-variant-numeric: tabular-nums; }
        .redirects-table-wrap { max-width: 100%; overflow-x: auto; overscroll-behavior-x: contain; }
        .redirects-table { border-collapse: collapse; margin: 0; min-width: 760px; width: 100%; }
        .redirects-table th { background: var(--co-surface-muted, #f7f8fd); border-bottom: 1px solid var(--co-border-strong, #d3d8eb); color: var(--co-muted, #5f6b89); font-size: .65rem; font-weight: 800; letter-spacing: .055em; padding: .7rem .75rem; text-align: left; text-transform: uppercase; white-space: nowrap; }
        .redirects-table td { border-bottom: 1px solid var(--co-border, #dfe3f1); color: var(--co-ink, #17203e); font-size: .75rem; padding: .7rem .75rem; vertical-align: middle; }
        .redirects-table tbody tr:last-child td { border-bottom: 0; }
        .redirects-table tbody tr:hover { background: color-mix(in srgb, var(--co-purple, #793df6) 3%, var(--co-surface, #fff)); }
        .redirects-domain-cell { min-width: 10rem; }
        .redirects-domain-name { align-items: center; color: var(--co-purple, #793df6); display: inline-flex; font-family: var(--font-mono, ui-monospace, monospace); font-size: .78rem; font-weight: 800; gap: .45rem; overflow-wrap: anywhere; }
        .redirects-domain-name i { flex: 0 0 auto; font-size: .78rem; }
        .redirects-host { font-family: var(--font-mono, ui-monospace, monospace); font-size: .71rem; overflow-wrap: anywhere; }
        .redirects-server-name { display: block; font-weight: 750; overflow-wrap: anywhere; }
        .redirects-server-ip { color: var(--co-muted, #5f6b89); display: block; font-family: var(--font-mono, ui-monospace, monospace); font-size: .68rem; margin-top: .18rem; }
        .redirects-state { align-items: center; color: var(--co-muted, #5f6b89); display: inline-flex; font-size: .71rem; font-weight: 700; gap: .35rem; white-space: nowrap; }
        .redirects-state.is-on { color: #147b55; }
        .redirects-state.is-off { color: var(--co-muted, #5f6b89); }
        .redirects-state i { font-size: .75rem; }
        .redirects-status { align-items: center; border: 1px solid var(--co-border, #dfe3f1); border-radius: 999px; display: inline-flex; font-size: .66rem; font-weight: 800; gap: .35rem; line-height: 1.3; padding: .3rem .55rem; white-space: nowrap; }
        .redirects-status.is-ready { background: color-mix(in srgb, #1ca572 9%, var(--co-surface, #fff)); border-color: color-mix(in srgb, #1ca572 25%, var(--co-border, #dfe3f1)); color: #147b55; }
        .redirects-status.is-pending { background: color-mix(in srgb, #d38b22 10%, var(--co-surface, #fff)); border-color: color-mix(in srgb, #d38b22 28%, var(--co-border, #dfe3f1)); color: #8d5716; }
        .redirects-status.is-muted { background: var(--co-surface-muted, #f7f8fd); color: var(--co-muted, #5f6b89); }
        .redirects-retention { color: var(--co-muted, #5f6b89); font-size: .7rem; white-space: nowrap; }
        .redirects-table-empty { color: var(--co-muted, #5f6b89); padding: 2rem 1rem !important; text-align: center; }
        .redirects-table-empty i { color: var(--co-purple, #793df6); display: block; font-size: 1.2rem; margin-bottom: .5rem; }
        .redirects-empty { color: var(--co-muted, #5f6b89); font-size: .8rem; padding: 1.5rem 1rem; text-align: center; }
        .redirects-empty i { color: color-mix(in srgb, var(--co-purple, #793df6) 65%, var(--co-muted, #5f6b89)); display: block; font-size: 1.2rem; margin-bottom: .5rem; }
        .redirects-pagination { align-items: center; border-top: 1px solid var(--co-border, #dfe3f1); display: flex; flex-wrap: wrap; gap: .6rem; justify-content: space-between; padding: .7rem .9rem; }
        .redirects-pagination-summary { color: var(--co-muted, #5f6b89); font-size: .72rem; font-variant-numeric: tabular-nums; }
        .redirects-page-nav { align-items: center; display: flex; flex-wrap: wrap; gap: .4rem; }
        .redirects-page-link { align-items: center; background: var(--co-surface, #fff); border: 1px solid var(--co-border, #dfe3f1); border-radius: .5rem; color: var(--co-ink, #17203e); display: inline-flex; font-size: .72rem; font-weight: 750; gap: .35rem; justify-content: center; min-height: 2.15rem; padding: .35rem .6rem; text-decoration: none; }
        .redirects-page-link:hover { background: color-mix(in srgb, var(--co-purple, #793df6) 7%, var(--co-surface, #fff)); border-color: color-mix(in srgb, var(--co-purple, #793df6) 35%, var(--co-border, #dfe3f1)); color: var(--co-purple, #793df6); text-decoration: none; }
        .redirects-page-link:focus-visible { outline: 3px solid color-mix(in srgb, var(--co-purple, #793df6) 38%, transparent); outline-offset: 2px; }
        .redirects-page-link.is-disabled { color: var(--co-muted, #5f6b89); cursor: not-allowed; opacity: .58; }
        @media (max-width: 1120px) { .redirects-filter-form { grid-template-columns: repeat(2, minmax(0, 1fr)); } .redirects-filter-search { grid-column: 1 / -1; } }
        @media (max-width: 980px) { .redirects-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 760px) {
            .redirects-hero { align-items: flex-start; }
            .redirects-content { padding: .75rem; }
            .redirects-table-wrap { overflow: visible; }
            .redirects-table { display: block; min-width: 0; }
            .redirects-table thead { border: 0; clip: rect(0, 0, 0, 0); clip-path: inset(50%); height: 1px; margin: -1px; overflow: hidden; padding: 0; position: absolute; white-space: nowrap; width: 1px; }
            .redirects-table tbody { display: grid; gap: .65rem; padding: .65rem; }
            .redirects-table tbody tr { background: var(--co-surface, #fff); border: 1px solid var(--co-border, #dfe3f1); border-radius: .7rem; display: grid; gap: .35rem .5rem; grid-template-columns: repeat(2, minmax(0, 1fr)); min-width: 0; padding: .55rem; }
            .redirects-table tbody tr:hover { background: var(--co-surface, #fff); }
            .redirects-table td { align-items: flex-start; border: 0; display: flex; flex-direction: column; gap: .2rem; min-width: 0; overflow-wrap: anywhere; padding: .35rem !important; }
            .redirects-table td::before { color: var(--co-muted, #5f6b89); content: attr(data-label); font-size: .61rem; font-weight: 800; letter-spacing: .045em; text-transform: uppercase; }
            .redirects-table td.redirects-domain-cell { grid-column: 1 / -1; }
            .redirects-table .redirects-table-empty { align-items: center; display: block; grid-column: 1 / -1; text-align: center; }
        }
        @media (max-width: 560px) {
            .redirects-heading { align-items: flex-start; }
            .redirects-meta { margin-left: 3.7rem; }
            .redirects-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .redirects-metric { gap: .55rem; padding: .7rem; }
            .redirects-metric-icon { flex-basis: 2rem; height: 2rem; }
            .redirects-metric strong { font-size: 1.1rem; }
            .redirects-metric span { font-size: .67rem; }
            .redirects-filter-form { grid-template-columns: 1fr; }
            .redirects-filter-search { grid-column: auto; }
            .redirects-filter-button, .redirects-reset-link { width: 100%; }
            .redirects-table tbody tr { grid-template-columns: minmax(0, 1fr); }
            .redirects-table td.redirects-domain-cell { grid-column: auto; }
            .redirects-pagination { align-items: flex-start; flex-direction: column; }
        }
        @media (prefers-reduced-motion: reduce) { .redirects-page *, .redirects-page *::before, .redirects-page *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; } }
        html[data-command-theme='dark'] .redirects-status.is-ready { background: color-mix(in srgb, #21c58b 16%, var(--co-surface, #111d3b)); color: #8df0c5; }
        html[data-command-theme='dark'] .redirects-status.is-pending { background: color-mix(in srgb, #e5a43e 16%, var(--co-surface, #111d3b)); color: #ffd38a; }
        html[data-command-theme='dark'] .redirects-state.is-on { color: #8df0c5; }
    </style>
@endpush

@section('content')
    <main class="redirects-page" aria-labelledby="redirects-title">
        <div class="redirects-shell">
            <header class="redirects-hero">
                <div class="redirects-heading">
                    <span class="redirects-mark" aria-hidden="true"><i class="fas fa-link"></i></span>
                    <div>
                        <span class="redirects-eyebrow">Campaign Studio · Workspace redirects</span>
                        <h1 id="redirects-title">Redirects &amp; Rotators</h1>
                <p>Find installed domains quickly and check their tracking status and recent click activity.</p>
                    </div>
                </div>
                <span class="redirects-meta"><i class="fas fa-shield-alt" aria-hidden="true"></i> Workspace-scoped · read only</span>
            </header>

            <div class="redirects-content">
                <section class="redirects-metrics" aria-label="Redirect summary">
                    <article class="redirects-metric">
                        <span class="redirects-metric-icon" style="--metric-color:#15966b" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                        <div><small>Tracking endpoints ready</small><strong>{{ number_format($readyEndpointCount) }}</strong><span>Installed domain endpoints with tracking HTTPS ready</span></div>
                    </article>
                    <article class="redirects-metric">
                        <span class="redirects-metric-icon" style="--metric-color:#277bff" aria-hidden="true"><i class="fas fa-mouse-pointer"></i></span>
                        <div><small>Click events · 24 hours</small><strong>{{ number_format($clicksLast24Hours) }}</strong><span>Recorded campaign click requests</span></div>
                    </article>
                    <article class="redirects-metric">
                        <span class="redirects-metric-icon" style="--metric-color:#793df6" aria-hidden="true"><i class="fas fa-route"></i></span>
                        <div><small>Campaign fallback</small><strong>Signed</strong><span>AlphaLink handles clicks when a server tracker is unavailable</span></div>
                    </article>
                    <article class="redirects-metric">
                        <span class="redirects-metric-icon" style="--metric-color:#d88916" aria-hidden="true"><i class="fas fa-history"></i></span>
                        <div><small>Removed server retention</small><strong>{{ number_format($retainedEndpointCount) }}</strong><span>Domain endpoints retained for 30 days after removal</span></div>
                    </article>
                </section>

                <aside class="redirects-note" aria-label="Redirect behavior">
                    <i class="fas fa-info-circle" aria-hidden="true"></i>
                    <p><strong>How campaign links work:</strong> sends use a saved domain tracking hostname only when tracking and HTTPS are enabled. Otherwise, the signed AlphaLink click route resolves the offer saved with the recipient snapshot. Removed-server endpoints remain listed for 30 days; campaign recipient snapshots and click history are preserved. Destination rotation is not configured.</p>
                </aside>

                <section class="redirects-section" aria-labelledby="tracking-endpoints-title">
                    @php
                        $redirectFilterBase = array_filter([
                            'search' => $filters['search'],
                            'server' => $filters['server'],
                        ], fn ($value) => $value !== '');
                        $clearSearchQuery = array_filter([
                            'server' => $filters['server'],
                            'status' => $filters['status'],
                        ], fn ($value) => $value !== '');
                        $hasRedirectFilters = $filters['search'] !== '' || $filters['server'] !== '' || $filters['status'] !== '';
                    @endphp
                    <header class="redirects-section-head">
                        <div><h2 id="tracking-endpoints-title">Installed domain endpoints</h2><p>Each installed domain is listed separately, even when several domains share one server IP.</p></div>
                        <span class="redirects-count">{{ number_format($trackingEndpointCount) }} total</span>
                    </header>
                    <div class="redirects-filter-card">
                        <nav class="redirects-filter-chips" aria-label="Quick endpoint filters">
                            <a class="redirects-filter-chip {{ $filters['status'] === '' ? 'is-active' : '' }}" href="{{ route('console.redirects.index', array_merge(['workspace' => $workspace->id], $redirectFilterBase)) }}" @if ($filters['status'] === '') aria-current="page" @endif>
                                <i class="fas fa-globe" aria-hidden="true"></i>All domains <span class="redirects-filter-chip-count">{{ number_format($filterCounts['all']) }}</span>
                            </a>
                            <a class="redirects-filter-chip {{ $filters['status'] === 'ready' ? 'is-active' : '' }}" href="{{ route('console.redirects.index', array_merge(['workspace' => $workspace->id], $redirectFilterBase, ['status' => 'ready'])) }}" @if ($filters['status'] === 'ready') aria-current="page" @endif>
                                <i class="fas fa-check-circle" aria-hidden="true"></i>Ready <span class="redirects-filter-chip-count">{{ number_format($filterCounts['ready']) }}</span>
                            </a>
                            <a class="redirects-filter-chip {{ $filters['status'] === 'attention' ? 'is-active' : '' }}" href="{{ route('console.redirects.index', array_merge(['workspace' => $workspace->id], $redirectFilterBase, ['status' => 'attention'])) }}" @if ($filters['status'] === 'attention') aria-current="page" @endif>
                                <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>Needs attention <span class="redirects-filter-chip-count">{{ number_format($filterCounts['attention']) }}</span>
                            </a>
                            <a class="redirects-filter-chip {{ $filters['status'] === 'retained' ? 'is-active' : '' }}" href="{{ route('console.redirects.index', array_merge(['workspace' => $workspace->id], $redirectFilterBase, ['status' => 'retained'])) }}" @if ($filters['status'] === 'retained') aria-current="page" @endif>
                                <i class="fas fa-history" aria-hidden="true"></i>Retained <span class="redirects-filter-chip-count">{{ number_format($filterCounts['retained']) }}</span>
                            </a>
                        </nav>

                        <form class="redirects-filter-form" method="get" action="{{ route('console.redirects.index', $workspace->id) }}" aria-label="Filter installed domains">
                            <div class="redirects-filter-field redirects-filter-search">
                                <label for="redirect-domain-search">Find a domain</label>
                                <div class="redirects-search-wrap">
                                    <input id="redirect-domain-search" type="search" name="search" value="{{ $filters['search'] }}" placeholder="Domain, tracking host, server, or IP" autocomplete="off">
                                    @if ($filters['search'] !== '')
                                        <a class="redirects-clear-search" href="{{ route('console.redirects.index', array_merge(['workspace' => $workspace->id], $clearSearchQuery)) }}" aria-label="Clear domain search" title="Clear search"><i class="fas fa-times" aria-hidden="true"></i></a>
                                    @endif
                                </div>
                            </div>
                            <div class="redirects-filter-field">
                                <label for="redirect-server-filter">Server</label>
                                <select id="redirect-server-filter" name="server">
                                    <option value="">All servers</option>
                                    @foreach ($serverOptions as $option)
                                        <option value="{{ $option['id'] }}" @selected($filters['server'] === $option['id'])>{{ $option['name'] }} · {{ $option['ip_address'] }} · {{ $option['endpoint_count'] }} {{ \Illuminate\Support\Str::plural('domain', $option['endpoint_count']) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="redirects-filter-field">
                                <label for="redirect-status-filter">Endpoint status</label>
                                <select id="redirect-status-filter" name="status">
                                    <option value="">All statuses</option>
                                    <option value="ready" @selected($filters['status'] === 'ready')>Ready</option>
                                    <option value="attention" @selected($filters['status'] === 'attention')>Needs attention</option>
                                    <option value="tracking_off" @selected($filters['status'] === 'tracking_off')>Tracking off</option>
                                    <option value="https_required" @selected($filters['status'] === 'https_required')>HTTPS required</option>
                                    <option value="dns_required" @selected($filters['status'] === 'dns_required')>Tracking host needs DNS</option>
                                    <option value="active" @selected($filters['status'] === 'active')>Active server</option>
                                    <option value="inactive" @selected($filters['status'] === 'inactive')>Inactive server</option>
                                    <option value="retained" @selected($filters['status'] === 'retained')>Retained after removal</option>
                                </select>
                            </div>
                            <button class="redirects-filter-button" type="submit"><i class="fas fa-filter" aria-hidden="true"></i>Apply filters</button>
                            @if ($hasRedirectFilters)
                                <a class="redirects-reset-link" href="{{ route('console.redirects.index', $workspace->id) }}">Clear all</a>
                            @endif
                        </form>
                    </div>

                    <div class="redirects-result-line" aria-live="polite">
                        <span>
                            @if ($trackingServers->total() > 0)
                                Showing <strong>{{ number_format($trackingServers->firstItem()) }}–{{ number_format($trackingServers->lastItem()) }}</strong> of <strong>{{ number_format($trackingServers->total()) }}</strong> matching endpoints
                            @else
                                Showing <strong>0</strong> matching endpoints
                            @endif
                        </span>
                        @if ($filters['search'] !== '')
                            <span>Search: <strong>{{ $filters['search'] }}</strong></span>
                        @endif
                    </div>

                    @if ($trackingEndpointCount === 0)
                        <div class="redirects-empty"><i class="fas fa-globe" aria-hidden="true"></i>No installed domain endpoints are available in this workspace yet.</div>
                    @elseif ($trackingServers->isEmpty())
                        <div class="redirects-empty"><i class="fas fa-filter" aria-hidden="true"></i>No domains match these filters. Try a different search or clear the filters.</div>
                    @else
                        <div class="redirects-table-wrap" role="region" aria-label="Installed domain endpoints" tabindex="0">
                            <table class="redirects-table">
                                <thead><tr><th scope="col">Installed domain</th><th scope="col">Tracking host</th><th scope="col">Server / IP</th><th scope="col">Tracking</th><th scope="col">HTTPS</th><th scope="col">Endpoint status</th><th scope="col">Server / retention</th></tr></thead>
                                <tbody>
                                    @foreach ($trackingServers as $server)
                                        <tr>
                                            <td class="redirects-domain-cell" data-label="Installed domain"><span class="redirects-domain-name"><i class="fas fa-globe" aria-hidden="true"></i>{{ $server['domain'] ?? 'Legacy server host' }}</span></td>
                                            <td class="redirects-host" data-label="Tracking host">{{ $server['tracking_host'] ?? 'Not configured' }}</td>
                                            <td data-label="Server / IP"><span class="redirects-server-name">{{ $server['name'] }}</span><span class="redirects-server-ip">{{ $server['ip_address'] }}</span></td>
                                            <td data-label="Tracking"><span class="redirects-state {{ $server['tracking_enabled'] ? 'is-on' : 'is-off' }}"><i class="fas {{ $server['tracking_enabled'] ? 'fa-toggle-on' : 'fa-toggle-off' }}" aria-hidden="true"></i>{{ $server['tracking_enabled'] ? 'Enabled' : 'Disabled' }}</span></td>
                                            <td data-label="HTTPS"><span class="redirects-state {{ $server['https_enabled'] ? 'is-on' : 'is-off' }}"><i class="fas {{ $server['https_enabled'] ? 'fa-lock' : 'fa-lock-open' }}" aria-hidden="true"></i>{{ $server['https_enabled'] ? 'Enabled' : 'Disabled' }}</span></td>
                                            <td data-label="Endpoint status"><span class="redirects-status {{ $server['ready'] ? 'is-ready' : ($server['retained'] ? 'is-muted' : 'is-pending') }}"><i class="fas {{ $server['ready'] ? 'fa-check-circle' : ($server['retained'] ? 'fa-history' : 'fa-exclamation-triangle') }}" aria-hidden="true"></i>{{ $server['status'] }}</span></td>
                                            <td data-label="Server / retention">@if ($server['retained'])<span class="redirects-status is-pending"><i class="fas fa-hourglass-half" aria-hidden="true"></i>Until {{ $server['retained_until']->format('Y-m-d') }}</span>@else<span class="redirects-retention"><i class="fas {{ $server['is_active'] ? 'fa-check-circle' : 'fa-pause-circle' }}" aria-hidden="true"></i> {{ $server['is_active'] ? 'Active server' : 'Inactive server' }}</span>@endif</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($trackingServers->hasPages())
                            <footer class="redirects-pagination">
                                <span class="redirects-pagination-summary">Page {{ $trackingServers->currentPage() }} of {{ $trackingServers->lastPage() }} · 25 endpoints per page</span>
                                <nav class="redirects-page-nav" aria-label="Domain endpoint pages">
                                    @if ($trackingServers->onFirstPage())
                                        <span class="redirects-page-link is-disabled" aria-disabled="true"><i class="fas fa-angle-left" aria-hidden="true"></i>Previous</span>
                                    @else
                                        <a class="redirects-page-link" href="{{ $trackingServers->previousPageUrl() }}"><i class="fas fa-angle-left" aria-hidden="true"></i>Previous</a>
                                    @endif
                                    @if ($trackingServers->hasMorePages())
                                        <a class="redirects-page-link" href="{{ $trackingServers->nextPageUrl() }}">Next<i class="fas fa-angle-right" aria-hidden="true"></i></a>
                                    @else
                                        <span class="redirects-page-link is-disabled" aria-disabled="true">Next<i class="fas fa-angle-right" aria-hidden="true"></i></span>
                                    @endif
                                </nav>
                            </footer>
                        @endif
                    @endif
                </section>

                <section class="redirects-section" aria-labelledby="recent-redirects-title">
                    <header class="redirects-section-head">
                        <div><h2 id="recent-redirects-title">Recent redirect activity</h2><p>Campaign clicks received in the last 24 hours.</p></div>
                        <span class="redirects-count">{{ $recentClicks->count() }} shown</span>
                    </header>
                    @if ($recentClicks->isEmpty())
                        <div class="redirects-empty"><i class="fas fa-route" aria-hidden="true"></i>No campaign click events have been recorded in the last 24 hours.</div>
                    @else
                        <div class="redirects-table-wrap" role="region" aria-label="Recent campaign click events" tabindex="0">
                            <table class="redirects-table">
                                <thead><tr><th scope="col">Campaign</th><th scope="col">ISP</th><th scope="col">Received at</th><th scope="col">Result</th></tr></thead>
                                <tbody>
                                    @foreach ($recentClicks as $click)
                                        <tr>
                                            <td data-label="Campaign">{{ $click->campaign_name }}</td>
                                            <td data-label="ISP">{{ $click->isp_name ?: '—' }}</td>
                                            <td data-label="Received at">{{ \Illuminate\Support\Carbon::parse($click->created_at)->timezone(config('app.timezone'))->format('Y-m-d H:i:s') }}</td>
                                            <td data-label="Result"><span class="redirects-status is-ready"><i class="fas fa-check-circle" aria-hidden="true"></i>Recorded</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            </div>
        </div>
    </main>
@endsection
