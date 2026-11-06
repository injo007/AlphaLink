@extends('layouts.admin-shell')

@section('title', 'Campaign Visitors Logs')

@section('content')
<main id="campaign-visitors"
    class="campaign-visitors-page"
    data-table-url="{{ route('console.campaigns.visitors', [$workspace->id, $campaign->id]) }}"
    data-initial-search="{{ $search }}"
    data-initial-start="{{ $tableState['start'] }}"
    data-initial-length="{{ $tableState['length'] }}"
    data-initial-order-index="{{ $tableState['orderIndex'] }}"
    data-initial-order-direction="{{ $tableState['orderDirection'] }}">
    <header class="campaign-visitors-hero">
        <div class="campaign-visitors-hero-copy">
            <span class="campaign-visitors-eyebrow"><i class="fas fa-chart-line" aria-hidden="true"></i> ENGAGEMENT INTELLIGENCE</span>
            <h1>Campaign visitors</h1>
            <p>Explore recorded activity and audience signals for this campaign.</p>
        </div>
        <div class="campaign-visitors-hero-actions">
            <span class="campaign-visitors-id"><i class="fas fa-fingerprint" aria-hidden="true"></i><span>CAMPAIGN</span><strong title="{{ $campaign->id }}">{{ substr($campaign->id, 0, 8) }}</strong></span>
            <a class="campaign-visitors-back" href="{{ route('console.campaigns.index', $workspace->id) }}"><i class="fas fa-arrow-left" aria-hidden="true"></i><span>All campaigns</span></a>
        </div>
    </header>

    <section class="campaign-visitors-metrics" aria-label="Campaign visitor totals">
        @foreach ([['OPENS', 'opens', 'fa-envelope-open', 'violet'], ['CLICKS', 'clicks', 'fa-mouse-pointer', 'blue'], ['LEADS', 'leads', 'fa-user-plus', 'cyan'], ['LEAD REVENUE', 'revenue', 'fa-dollar-sign', 'green'], ['UNSUBSCRIBES', 'unsubscribes', 'fa-user-minus', 'amber'], ['BOTS', 'bots', 'fa-robot', 'rose'], ['PROXY VISITS', 'proxies', 'fa-user-secret', 'indigo'], ['HOSTING VISITS', 'hosting', 'fa-server', 'slate']] as [$label, $key, $icon, $tone])
            <article class="campaign-visitors-metric campaign-visitors-metric--{{ $tone }}">
                <span class="campaign-visitors-metric-icon" aria-hidden="true"><i class="fas {{ $icon }}"></i></span>
                <div class="campaign-visitors-metric-copy">
                    <span class="campaign-visitors-metric-label">{{ $label }}</span>
                    <strong>{{ $key === 'revenue' ? '$'.number_format($totals[$key], 2) : number_format($totals[$key]) }}</strong>
                </div>
            </article>
        @endforeach
    </section>

    <section id="campaign-visitors-map-block" class="campaign-visitors-map-card block-mode-hidden" aria-labelledby="campaign-visitors-map-title">
        <div class="campaign-visitors-map-header">
            <div class="campaign-visitors-section-heading">
                <span class="campaign-visitors-section-icon campaign-visitors-section-icon--map" aria-hidden="true"><i class="fas fa-globe-americas"></i></span>
                <div><span class="campaign-visitors-section-kicker">GEOGRAPHIC VIEW</span><h2 id="campaign-visitors-map-title">Visitor activity by country</h2><p>Country-level event coverage where available.</p></div>
            </div>
            <button type="button" id="campaign-visitors-map-toggle" class="campaign-visitors-map-toggle" aria-label="Toggle campaign visitor map" aria-expanded="false">
                <span>View map</span><i class="si si-arrow-down" aria-hidden="true"></i>
            </button>
        </div>
        <div class="campaign-visitors-map-content">
            <div id="campaign-visitors-map" role="img" aria-label="Visitor counts by country"></div>
            <div id="campaign-visitors-map-empty" class="campaign-visitors-map-empty" role="status" hidden><i class="fas fa-map-marked-alt" aria-hidden="true"></i><span>No country-enriched visitor records are available for this campaign.</span></div>
        </div>
    </section>

    <aside class="campaign-visitors-coverage" role="note">
        <span class="campaign-visitors-coverage-icon" aria-hidden="true"><i class="fas fa-info-circle"></i></span>
        <div><strong>About these records</strong><p><strong>Data coverage:</strong> this log combines stored campaign open, click, unsubscribe, and bot events with imported campaign leads. Proxy and hosting flags come from the configured IP-API Pro lookup; older events and country-only fallback lookups show unknown. Native per-send configuration records are not available in AlphaLink yet.</p></div>
    </aside>

    <section class="campaign-visitors-table-card" aria-labelledby="campaign-visitors-table-title">
        <div class="campaign-visitors-table-header">
            <div class="campaign-visitors-section-heading">
                <span class="campaign-visitors-section-icon campaign-visitors-section-icon--table" aria-hidden="true"><i class="fas fa-list-ul"></i></span>
                <div><span class="campaign-visitors-section-kicker">ACTIVITY STREAM</span><h2 id="campaign-visitors-table-title">Visitors log</h2><p>Search, sort, and inspect recorded campaign events.</p></div>
            </div>
            <div class="campaign-visitors-log-status"><span class="campaign-visitors-status-dot" aria-hidden="true"></span> Event records</div>
        </div>
        <div class="campaign-visitors-table-content">
            <div class="campaign-visitors-tools">
                <div class="campaign-visitors-search-wrap">
                    <label for="campaign-visitors-search"><i class="fas fa-search" aria-hidden="true"></i> Search visitor records</label>
                    <div class="campaign-visitors-search-control">
                        <input id="campaign-visitors-search" type="search" maxlength="200" value="{{ $search }}" placeholder="Search type, send, contact, revision, or IP">
                        <button id="campaign-visitors-search-clear" type="button" aria-label="Clear visitor search" @disabled($search === '')><i class="fas fa-times" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div id="campaign-visitors-result-count" class="campaign-visitors-result-count" role="status" aria-live="polite"></div>
            </div>
            <div id="campaign-visitors-error" class="campaign-visitors-error" role="alert" hidden></div>
            <div class="campaign-visitors-table-scroll">
                <table id="campaign-visitors-table" class="campaign-visitors-table">
                    <caption class="sr-only">Campaign open, click, lead, unsubscribe, bot, proxy, and hosting records</caption>
                </table>
            </div>
        </div>
    </section>
</main>
@endsection

@push('styles')
<style>
#campaign-visitors {
    --cv-page: var(--co-page, #f5f6fb);
    --cv-surface: var(--co-surface, #fff);
    --cv-soft: var(--co-surface-muted, #f7f8fd);
    --cv-border: var(--co-border, #dfe3f1);
    --cv-border-strong: var(--co-border-strong, #d3d8eb);
    --cv-ink: var(--co-ink, #17203e);
    --cv-muted: var(--co-muted, #5f6b89);
    --cv-purple: var(--co-purple, #793df6);
    --cv-blue: var(--co-blue, #277bff);
    --cv-shadow: var(--co-shadow, 0 4px 20px rgb(45 54 115 / 5%));
    color: var(--cv-ink);
    min-width: 0;
    padding: clamp(4px, .7vw, 12px) 0 34px;
}
#campaign-visitors *, #campaign-visitors *::before, #campaign-visitors *::after { box-sizing: border-box; }
#campaign-visitors .campaign-visitors-hero {
    position: relative; display:flex; align-items:center; justify-content:space-between; gap:24px;
    overflow:hidden; margin:0 0 20px; padding:clamp(20px, 2.5vw, 30px); border:1px solid var(--cv-border);
    border-radius:22px; background:radial-gradient(ellipse at 91% 9%, rgb(151 99 255 / 12%), transparent 36%), linear-gradient(110deg,var(--cv-surface),var(--cv-soft)); box-shadow:var(--cv-shadow);
}
#campaign-visitors .campaign-visitors-hero::after { content:""; position:absolute; right:-52px; bottom:-148px; width:300px; height:240px; border:1px solid rgb(121 61 246 / 12%); border-radius:50%; box-shadow:0 0 0 24px rgb(121 61 246 / 3%),0 0 0 50px rgb(121 61 246 / 2%); pointer-events:none; }
#campaign-visitors .campaign-visitors-hero-copy, #campaign-visitors .campaign-visitors-hero-actions { position:relative; z-index:1; }
#campaign-visitors .campaign-visitors-eyebrow, #campaign-visitors .campaign-visitors-section-kicker { display:block; margin-bottom:6px; color:var(--cv-purple); font-size:10px; font-weight:800; letter-spacing:.14em; }
#campaign-visitors .campaign-visitors-eyebrow i { margin-right:6px; }
#campaign-visitors .campaign-visitors-hero h1 { margin:0; color:var(--cv-ink); font-size:clamp(24px,2.5vw,34px); font-weight:760; letter-spacing:-.045em; line-height:1.1; }
#campaign-visitors .campaign-visitors-hero p { margin:8px 0 0; color:var(--cv-muted); font-size:13px; }
#campaign-visitors .campaign-visitors-hero-actions { display:flex; align-items:center; gap:10px; }
#campaign-visitors .campaign-visitors-id { display:grid; grid-template-columns:26px auto; align-items:center; column-gap:8px; padding:9px 12px; border:1px solid var(--cv-border); border-radius:13px; background:var(--cv-surface); }
#campaign-visitors .campaign-visitors-id > i { grid-row:span 2; color:var(--cv-purple); font-size:16px; }
#campaign-visitors .campaign-visitors-id span { color:var(--cv-muted); font-size:9px; font-weight:800; letter-spacing:.1em; }
#campaign-visitors .campaign-visitors-id strong { color:var(--cv-ink); font-size:12px; font-variant-numeric:tabular-nums; letter-spacing:.04em; }
#campaign-visitors .campaign-visitors-back { display:inline-flex; align-items:center; gap:8px; min-height:42px; padding:0 14px; border:1px solid rgb(121 61 246 / 28%); border-radius:13px; color:var(--cv-purple); background:rgb(121 61 246 / 6%); font-size:12px; font-weight:700; text-decoration:none; transition:background .18s ease,transform .18s ease; }
#campaign-visitors .campaign-visitors-back:hover { color:var(--cv-purple); background:rgb(121 61 246 / 11%); transform:translateY(-1px); }
#campaign-visitors .campaign-visitors-back:focus-visible, #campaign-visitors button:focus-visible, #campaign-visitors input:focus-visible, #campaign-visitors .dataTables_paginate a:focus-visible { outline:2px solid var(--cv-purple); outline-offset:2px; }
#campaign-visitors .campaign-visitors-metrics { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:18px; }
#campaign-visitors .campaign-visitors-metric { --metric:#793df6; --metric-bg:rgb(121 61 246 / 9%); position:relative; display:flex; align-items:center; gap:13px; min-width:0; min-height:88px; padding:15px 17px; overflow:hidden; border:1px solid var(--cv-border); border-radius:17px; background:var(--cv-surface); box-shadow:var(--cv-shadow); }
#campaign-visitors .campaign-visitors-metric::before { content:""; position:absolute; top:0; left:0; width:100%; height:2px; background:linear-gradient(90deg,var(--metric),transparent 76%); opacity:.7; }
#campaign-visitors .campaign-visitors-metric-icon { display:grid; flex:none; width:44px; height:44px; place-items:center; border:1px solid color-mix(in srgb,var(--metric) 19%,transparent); border-radius:14px; color:var(--metric); background:var(--metric-bg); font-size:17px; }
#campaign-visitors .campaign-visitors-metric-copy { display:grid; gap:4px; min-width:0; }
#campaign-visitors .campaign-visitors-metric-label { color:var(--cv-muted); font-size:10px; font-weight:750; letter-spacing:.08em; line-height:1.2; }
#campaign-visitors .campaign-visitors-metric-copy strong { color:var(--cv-ink); font-size:clamp(19px,1.8vw,25px); font-variant-numeric:tabular-nums; font-weight:760; letter-spacing:-.035em; line-height:1.15; white-space:nowrap; }
#campaign-visitors .campaign-visitors-metric--blue { --metric:#277bff; --metric-bg:rgb(39 123 255 / 9%); }
#campaign-visitors .campaign-visitors-metric--cyan { --metric:#00a9c7; --metric-bg:rgb(0 169 199 / 9%); }
#campaign-visitors .campaign-visitors-metric--green { --metric:#0caa70; --metric-bg:rgb(12 170 112 / 9%); }
#campaign-visitors .campaign-visitors-metric--amber { --metric:#d88a14; --metric-bg:rgb(216 138 20 / 10%); }
#campaign-visitors .campaign-visitors-metric--rose { --metric:#df4e85; --metric-bg:rgb(223 78 133 / 9%); }
#campaign-visitors .campaign-visitors-metric--indigo { --metric:#5b63e8; --metric-bg:rgb(91 99 232 / 9%); }
#campaign-visitors .campaign-visitors-metric--slate { --metric:#71809d; --metric-bg:rgb(113 128 157 / 10%); }
#campaign-visitors .campaign-visitors-map-card, #campaign-visitors .campaign-visitors-table-card { overflow:hidden; margin-bottom:16px; border:1px solid var(--cv-border); border-radius:19px; background:var(--cv-surface); box-shadow:var(--cv-shadow); }
#campaign-visitors .campaign-visitors-map-header, #campaign-visitors .campaign-visitors-table-header { display:flex; align-items:center; justify-content:space-between; gap:18px; padding:18px 21px; border-bottom:1px solid var(--cv-border); background:linear-gradient(100deg,var(--cv-surface),var(--cv-soft)); }
#campaign-visitors .campaign-visitors-section-heading { display:flex; align-items:center; gap:13px; min-width:0; }
#campaign-visitors .campaign-visitors-section-icon { display:grid; flex:none; width:42px; height:42px; place-items:center; border-radius:14px; color:#fff; background:linear-gradient(145deg,#a452ff,#6f3cf0); box-shadow:0 7px 18px rgb(121 61 246 / 24%); font-size:17px; }
#campaign-visitors .campaign-visitors-section-icon--map { background:linear-gradient(145deg,#318aff,#27c4dc); box-shadow:0 7px 18px rgb(39 123 255 / 20%); }
#campaign-visitors .campaign-visitors-section-heading h2 { margin:0; color:var(--cv-ink); font-size:16px; font-weight:760; letter-spacing:-.025em; }
#campaign-visitors .campaign-visitors-section-heading p { margin:4px 0 0; color:var(--cv-muted); font-size:11px; }
#campaign-visitors .campaign-visitors-section-kicker { margin:0 0 3px; font-size:9px; }
#campaign-visitors .campaign-visitors-map-toggle { display:inline-flex; align-items:center; gap:10px; min-height:38px; padding:0 12px; border:1px solid var(--cv-border-strong); border-radius:11px; color:var(--cv-ink); background:var(--cv-surface); font-size:11px; font-weight:700; cursor:pointer; }
#campaign-visitors .campaign-visitors-map-toggle:hover { color:var(--cv-purple); border-color:var(--cv-purple); }
#campaign-visitors .campaign-visitors-map-toggle i { color:var(--cv-purple); }
#campaign-visitors .campaign-visitors-map-content { padding:10px 16px 16px; }
#campaign-visitors .campaign-visitors-map-content #campaign-visitors-map { width:100%; min-height:390px; }
#campaign-visitors .campaign-visitors-map-content #campaign-visitors-map[hidden] { display:none; }
#campaign-visitors .campaign-visitors-map-empty { display:flex; align-items:center; justify-content:center; gap:10px; min-height:170px; padding:20px; border:1px dashed var(--cv-border-strong); border-radius:14px; color:var(--cv-muted); background:var(--cv-soft); font-size:13px; text-align:center; }
#campaign-visitors .campaign-visitors-map-empty i { color:var(--cv-blue); font-size:20px; }
#campaign-visitors .campaign-visitors-coverage { display:flex; align-items:flex-start; gap:13px; margin:0 0 17px; padding:15px 18px; border:1px solid rgb(39 123 255 / 20%); border-radius:15px; color:var(--cv-ink); background:linear-gradient(110deg,rgb(39 123 255 / 6%),var(--cv-surface)); }
#campaign-visitors .campaign-visitors-coverage-icon { display:grid; flex:none; width:34px; height:34px; place-items:center; border-radius:11px; color:var(--cv-blue); background:rgb(39 123 255 / 11%); }
#campaign-visitors .campaign-visitors-coverage strong { display:block; margin-bottom:4px; color:var(--cv-ink); font-size:12px; }
#campaign-visitors .campaign-visitors-coverage p { margin:0; color:var(--cv-muted); font-size:11px; line-height:1.55; }
#campaign-visitors .campaign-visitors-coverage p strong { display:inline; margin:0; color:var(--cv-ink); font-size:inherit; }
#campaign-visitors .campaign-visitors-log-status { display:inline-flex; align-items:center; gap:8px; padding:7px 10px; border:1px solid var(--cv-border); border-radius:999px; color:var(--cv-muted); background:var(--cv-surface); font-size:10px; font-weight:700; white-space:nowrap; }
#campaign-visitors .campaign-visitors-status-dot { width:7px; height:7px; border-radius:50%; background:#16b981; box-shadow:0 0 0 3px rgb(22 185 129 / 12%); }
#campaign-visitors .campaign-visitors-table-content { padding:18px 20px 20px; }
#campaign-visitors .campaign-visitors-tools { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; margin-bottom:14px; }
#campaign-visitors .campaign-visitors-search-wrap { width:min(100%,480px); }
#campaign-visitors .campaign-visitors-search-wrap label { display:block; margin:0 0 7px; color:var(--cv-muted); font-size:11px; font-weight:700; }
#campaign-visitors .campaign-visitors-search-wrap label i { margin-right:5px; color:var(--cv-purple); }
#campaign-visitors .campaign-visitors-search-control { display:flex; min-height:42px; overflow:hidden; border:1px solid var(--cv-border-strong); border-radius:12px; background:var(--cv-surface); transition:border-color .16s ease,box-shadow .16s ease; }
#campaign-visitors .campaign-visitors-search-control:focus-within { border-color:var(--cv-purple); box-shadow:0 0 0 3px rgb(121 61 246 / 10%); }
#campaign-visitors .campaign-visitors-search-control input { width:100%; min-width:0; padding:0 13px; border:0; outline:0; color:var(--cv-ink); background:transparent; font-size:12px; }
#campaign-visitors .campaign-visitors-search-control input::placeholder { color:var(--cv-muted); opacity:.75; }
#campaign-visitors .campaign-visitors-search-control button { display:grid; flex:none; width:42px; place-items:center; border:0; border-left:1px solid var(--cv-border); color:var(--cv-muted); background:var(--cv-soft); cursor:pointer; }
#campaign-visitors .campaign-visitors-search-control button:disabled { opacity:.45; cursor:default; }
#campaign-visitors .campaign-visitors-result-count { color:var(--cv-muted); font-size:11px; text-align:right; }
#campaign-visitors .campaign-visitors-error { margin:0 0 12px; padding:11px 13px; border:1px solid rgb(220 53 69 / 24%); border-radius:12px; color:#bc3543; background:rgb(220 53 69 / 7%); font-size:12px; }
#campaign-visitors .campaign-visitors-table-scroll { width:100%; overflow-x:auto; overscroll-behavior-inline:contain; border:1px solid var(--cv-border); border-radius:13px; scrollbar-color:var(--cv-border-strong) transparent; }
#campaign-visitors table.campaign-visitors-table { width:100%; min-width:1330px; margin:0; border-collapse:separate; border-spacing:0; color:var(--cv-ink); font-size:11px; }
#campaign-visitors table.campaign-visitors-table thead th { position:relative; padding:12px 11px; border:0; border-bottom:1px solid var(--cv-border-strong); color:var(--cv-muted); background:var(--cv-soft); font-size:9px; font-weight:800; letter-spacing:.07em; text-align:left; text-transform:uppercase; white-space:nowrap; }
#campaign-visitors table.campaign-visitors-table thead th:first-child { padding-left:15px; }
#campaign-visitors table.campaign-visitors-table thead th.sorting, #campaign-visitors table.campaign-visitors-table thead th.sorting_asc, #campaign-visitors table.campaign-visitors-table thead th.sorting_desc { padding-right:25px; }
#campaign-visitors table.campaign-visitors-table tbody td { padding:11px; border:0; border-bottom:1px solid var(--cv-border); color:var(--cv-ink); background:var(--cv-surface); white-space:nowrap; vertical-align:middle; }
#campaign-visitors table.campaign-visitors-table tbody tr:last-child td { border-bottom:0; }
#campaign-visitors table.campaign-visitors-table tbody tr:hover td { background:var(--cv-soft); }
#campaign-visitors table.campaign-visitors-table tbody td:first-child { padding-left:15px; color:var(--cv-purple); font-weight:750; font-variant-numeric:tabular-nums; }
#campaign-visitors .dataTables_wrapper { color:var(--cv-muted); font-size:11px; }
#campaign-visitors .dataTables_wrapper .table-tools { display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin-bottom:10px; }
#campaign-visitors .dataTables_wrapper .dt-buttons { display:flex; flex-wrap:wrap; gap:7px; }
#campaign-visitors .dataTables_wrapper .dt-buttons .btn { min-height:34px; padding:6px 10px; border:1px solid var(--cv-border-strong); border-radius:10px !important; color:var(--cv-ink); background:var(--cv-surface); font-size:10px; font-weight:700; box-shadow:none; }
#campaign-visitors .dataTables_wrapper .dt-buttons .btn:hover { border-color:var(--cv-purple); color:var(--cv-purple); background:var(--cv-soft); }
#campaign-visitors .dataTables_wrapper .table-footer { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 2px 0; }
#campaign-visitors .dataTables_wrapper .dataTables_info { padding:0; color:var(--cv-muted); font-size:10px; }
#campaign-visitors .dataTables_wrapper .dataTables_paginate { padding:0; }
#campaign-visitors .dataTables_wrapper .dataTables_paginate .paginate_button { min-width:31px; margin:0 2px; padding:6px 9px; border:1px solid var(--cv-border) !important; border-radius:8px !important; color:var(--cv-ink) !important; background:var(--cv-surface) !important; font-size:10px; }
#campaign-visitors .dataTables_wrapper .dataTables_paginate .paginate_button.current { border-color:var(--cv-purple) !important; color:#fff !important; background:var(--cv-purple) !important; }
#campaign-visitors .dataTables_wrapper .dataTables_paginate .paginate_button:hover { border-color:var(--cv-purple) !important; color:var(--cv-purple) !important; background:var(--cv-soft) !important; }
#campaign-visitors .dataTables_wrapper .dataTables_paginate .paginate_button.disabled { opacity:.45; }
#campaign-visitors .dataTables_wrapper .dataTables_empty { padding:30px 16px !important; color:var(--cv-muted) !important; text-align:center !important; white-space:normal !important; }
#campaign-visitors .dataTables_processing { color:var(--cv-purple) !important; background:var(--cv-surface) !important; }
#campaign-visitors #campaign-visitors-map-block.block-mode-hidden .campaign-visitors-map-content { display:none; }
html[data-command-theme='dark'] #campaign-visitors .campaign-visitors-hero { background:radial-gradient(ellipse at 91% 9%,rgb(161 63 255 / 16%),transparent 36%),linear-gradient(110deg,var(--cv-surface),var(--cv-soft)); }
html[data-command-theme='dark'] #campaign-visitors .campaign-visitors-coverage { border-color:rgb(24 168 255 / 23%); background:linear-gradient(110deg,rgb(24 168 255 / 9%),var(--cv-surface)); }
html[data-command-theme='dark'] #campaign-visitors .campaign-visitors-map-toggle:hover { color:var(--cv-purple); }
@media (max-width: 1100px) {
    #campaign-visitors .campaign-visitors-metrics { grid-template-columns:repeat(2,minmax(0,1fr)); }
}
@media (max-width: 680px) {
    #campaign-visitors { padding-top:4px; }
    #campaign-visitors .campaign-visitors-hero { align-items:flex-start; flex-direction:column; gap:15px; border-radius:17px; }
    #campaign-visitors .campaign-visitors-hero-actions { width:100%; justify-content:space-between; }
    #campaign-visitors .campaign-visitors-metrics { gap:9px; }
    #campaign-visitors .campaign-visitors-metric { min-height:76px; gap:9px; padding:11px 10px; border-radius:14px; }
    #campaign-visitors .campaign-visitors-metric-icon { width:36px; height:36px; border-radius:11px; font-size:14px; }
    #campaign-visitors .campaign-visitors-metric-label { font-size:8px; }
    #campaign-visitors .campaign-visitors-metric-copy strong { font-size:clamp(16px,5vw,21px); }
    #campaign-visitors .campaign-visitors-map-header, #campaign-visitors .campaign-visitors-table-header { align-items:flex-start; padding:15px 13px; }
    #campaign-visitors .campaign-visitors-section-heading { gap:9px; }
    #campaign-visitors .campaign-visitors-section-icon { width:36px; height:36px; border-radius:12px; font-size:15px; }
    #campaign-visitors .campaign-visitors-section-heading h2 { font-size:14px; }
    #campaign-visitors .campaign-visitors-section-heading p { font-size:10px; }
    #campaign-visitors .campaign-visitors-map-toggle { min-height:34px; gap:6px; padding:0 8px; font-size:10px; }
    #campaign-visitors .campaign-visitors-map-content #campaign-visitors-map { min-height:300px; }
    #campaign-visitors .campaign-visitors-coverage { gap:9px; padding:12px; }
    #campaign-visitors .campaign-visitors-coverage-icon { width:30px; height:30px; }
    #campaign-visitors .campaign-visitors-log-status { display:none; }
    #campaign-visitors .campaign-visitors-table-content { padding:14px 11px; }
    #campaign-visitors .campaign-visitors-tools { align-items:stretch; flex-direction:column; gap:8px; }
    #campaign-visitors .campaign-visitors-search-wrap { width:100%; }
    #campaign-visitors .campaign-visitors-result-count { text-align:left; }
    #campaign-visitors .dataTables_wrapper .table-footer { align-items:flex-start; flex-direction:column; }
    #campaign-visitors .dataTables_wrapper .dataTables_paginate { width:100%; overflow-x:auto; white-space:nowrap; }
}
@media (prefers-reduced-motion: reduce) {
    #campaign-visitors *, #campaign-visitors *::before, #campaign-visitors *::after { scroll-behavior:auto !important; transition:none !important; }
}
</style>
@endpush

@push('scripts')
<script src="https://www.gstatic.com/charts/loader.js"></script>
<script src="{{ asset('console-assets/campaign-visitors.js') }}?v={{ substr(sha1_file(public_path('console-assets/campaign-visitors.js')), 0, 12) }}"></script>
@endpush
