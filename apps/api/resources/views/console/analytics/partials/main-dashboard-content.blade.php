<div id="main-dashboard-refresh-region" data-main-dashboard-refresh data-refresh-url="{{ route('console.analytics.index', ['workspace' => $workspace->id, 'fragment' => 1]) }}" data-refresh-interval="300000">
    <h2 class="content-heading">MAIN DASHBOARD <small class="text-muted">LAST 30 DAYS</small></h2>

    <div class="row row-deck analytics-dms-summary" aria-label="Today, DMS YTD, and month-to-date dashboard metrics">
        <div class="col-sm-6 col-xl-3">
            <div class="block block-rounded d-flex flex-column">
                <div class="block-content block-content-full flex-grow-1 d-flex justify-content-between align-items-center">
                    <dl class="mb-0"><dt class="font-size-h2 font-w500" title="Imported PMTA delivery records">{{ number_format($dashboardHighlights['deliveries']['today']) }}</dt><dd class="text-muted mb-0">TODAY DELIVERIES</dd></dl>
                    <div class="item item-rounded bg-body"><i class="fas fa-paper-plane font-size-h3 text-primary" aria-hidden="true"></i></div>
                </div>
                <div class="block-content block-content-full block-content-sm bg-body-light font-size-sm d-flex justify-content-between">
                    <span class="text-muted">SOFT: {{ number_format($dashboardHighlights['deliveries']['soft_bounces']) }}</span><span class="text-muted">HARD: {{ number_format($dashboardHighlights['deliveries']['hard_bounces']) }}</span>
                </div>
            </div>
        </div>
        @foreach ([['OPENS', 'opens', 'fa-envelope-open-text'], ['CLICKS', 'clicks', 'fa-mouse-pointer']] as [$label, $key, $icon])
            <div class="col-sm-6 col-xl-3">
                <div class="block block-rounded d-flex flex-column">
                    <div class="block-content block-content-full flex-grow-1 d-flex justify-content-between align-items-center">
                        <dl class="mb-0"><dt class="font-size-h2 font-w500">{{ number_format($dashboardHighlights[$key]['today']) }}</dt><dd class="text-muted mb-0">TODAY {{ $label }}</dd></dl>
                        <div class="item item-rounded bg-body"><i class="fas {{ $icon }} font-size-h3 text-primary" aria-hidden="true"></i></div>
                    </div>
                    <div class="block-content block-content-full block-content-sm bg-body-light font-size-sm d-flex justify-content-between">
                        <span class="text-muted">YTD: {{ number_format($dashboardHighlights[$key]['ytd']) }}</span>
                        <span class="text-muted">MTD: {{ number_format($dashboardHighlights[$key]['mtd']) }}</span>
                    </div>
                </div>
            </div>
        @endforeach
        <div class="col-sm-6 col-xl-3">
            <div class="block block-rounded d-flex flex-column">
                <div class="block-content block-content-full flex-grow-1 d-flex justify-content-between align-items-center">
                    <dl class="mb-0"><dt class="font-size-h2 font-w500">$<span>{{ number_format($dashboardHighlights['revenue']['today'], 2) }}</span></dt><dd class="text-muted mb-0">TODAY EARNS</dd></dl>
                    <div class="item item-rounded bg-body"><i class="fas fa-dollar-sign font-size-h3 text-primary" aria-hidden="true"></i></div>
                </div>
                <div class="block-content block-content-full block-content-sm bg-body-light font-size-sm d-flex justify-content-between">
                    <span class="text-muted">YTD: ${{ number_format($dashboardHighlights['revenue']['ytd'], 2) }}</span>
                    <span class="text-muted">MTD: ${{ number_format($dashboardHighlights['revenue']['mtd'], 2) }}</span>
                </div>
                <div class="block-content block-content-full block-content-sm pt-0 text-muted small">Attributed lead revenue only</div>
            </div>
        </div>
    </div>

    <div class="row row-deck analytics-dms-metrics" aria-label="Campaign and Drop engagement totals for the last 30 days">
        @foreach ([['OPENS', 'opens', 'fa-envelope-open-text'], ['CLICKS', 'clicks', 'fa-mouse-pointer'], ['LEADS', 'leads', 'fa-bullseye'], ['UNSUBSCRIBES', 'unsubscribes', 'fa-user-minus']] as [$label, $key, $icon])
            <div class="col-sm-6 col-xl-3">
                <div class="block block-rounded d-flex flex-column">
                    <div class="block-content block-content-full flex-grow-1 d-flex justify-content-between align-items-center">
                        <dl class="mb-0"><dt class="font-size-h2 font-w500">{{ number_format($eventTotals[$key]) }}</dt><dd class="text-muted mb-0">{{ $label }}</dd></dl>
                        <div class="item item-rounded bg-body"><i class="fas {{ $icon }} font-size-h3 text-primary" aria-hidden="true"></i></div>
                    </div>
                    <div class="block-content block-content-full block-content-sm bg-body-light font-size-sm text-muted">
                        {{ $key === 'leads' ? '$'.number_format($eventTotals['lead_revenue'], 2).' recorded campaign and Drop revenue' : 'Campaign and Drop visitor events' }}
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row row-deck" aria-label="Month-to-date attributed lead revenue breakdowns">
        @foreach ([
            ['sponsors', 'MTD REVENUE BY AFFILIATE'],
            ['isps', 'MTD REVENUE BY ISP'],
            ['mailers', 'MTD REVENUE BY MAILER'],
            ['offers', 'MTD TOP 10 OFFERS'],
        ] as [$key, $title])
            <div class="col-sm-12 col-md-6">
                <div class="block block-rounded">
                    <div class="block-header block-header-default"><h3 class="block-title">{{ $title }}</h3></div>
                    <div class="block-content block-content-full p-2">
                        @if ($mtdLeadRevenue[$key] === [])
                            <div class="text-muted p-2" role="status">No attributed lead revenue is recorded this month.</div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm table-hover table-striped mb-0">
                                    <caption class="sr-only">{{ $title }} from attributed campaign and Drop leads</caption>
                                    <thead><tr><th scope="col">{{ $key === 'sponsors' ? 'AFFILIATE' : rtrim(strtoupper($key), 'S') }}</th><th scope="col" class="text-right">TODAY</th><th scope="col" class="text-right">MTD</th></tr></thead>
                                    <tbody>
                                        @foreach ($mtdLeadRevenue[$key] as $row)
                                            <tr><td>{{ $row['label'] }}</td><td class="text-right">${{ number_format($row['today'], 2) }}</td><td class="text-right">${{ number_format($row['mtd'], 2) }}</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                        <small class="text-muted">Attributed lead revenue only.</small>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row row-deck analytics-chart-panels" aria-label="Month-to-date actions and revenue charts">
        <div class="col-sm-12 col-xl-6">
            @include('console.analytics.partials.line-chart', [
                'chartId' => 'dashboard-mtd-actions',
                'title' => 'MTD ACTIONS STATS',
                'description' => 'Daily PMTA deliveries and bounce totals, campaign and Drop opens, clicks, and unsubscribes. Leads are an AlphaLink measure.',
                'chart' => $dashboardCharts['mtd_actions'],
                'currency' => false,
            ])
        </div>
        <div class="col-sm-12 col-xl-6">
            @include('console.analytics.partials.line-chart', [
                'chartId' => 'dashboard-mtd-revenue',
                'title' => 'MTD REVENUE STATS',
                'description' => 'Daily revenue attributed to recorded campaign and Drop leads for the current month.',
                'chart' => $dashboardCharts['mtd_revenue'],
                'currency' => true,
            ])
        </div>
        <div class="col-sm-12 col-xl-6">
            @include('console.analytics.partials.line-chart', [
                'chartId' => 'dashboard-ytd-revenue',
                'title' => 'YTD REVENUE STATS',
                'description' => 'Monthly revenue attributed to recorded campaign and Drop leads in the current year.',
                'chart' => $dashboardCharts['ytd_revenue'],
                'currency' => true,
            ])
        </div>
    </div>

    <div class="alert alert-warning" role="note"><strong>Chart coverage:</strong> the MTD chart includes locally imported PMTA deliveries and combined bounce totals. Scheduled host-file sync has not been verified against a live server. Revenue charts use attributed lead revenue and do not represent PMTA earnings.</div>

    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">CAMPAIGN &amp; DROP ENGAGEMENT — DAILY EVENTS</h3>
            <div class="block-options"><a class="btn btn-sm btn-alt-primary" href="{{ route('console.drops.monitoring', $workspace->id) }}">DROP MONITORING</a></div>
        </div>
        <div class="block-content block-content-full">
            @if ($eventTotals['opens'] + $eventTotals['clicks'] + $eventTotals['leads'] + $eventTotals['unsubscribes'] === 0)
                <div class="alert alert-info mb-0" role="status"><strong>No campaign or Drop engagement recorded.</strong> Open, click, lead, and unsubscribe activity will appear here after visitor events are stored.</div>
            @else
                <div class="analytics-legend mb-2" aria-label="Chart legend">
                    <span><i class="legend-open" aria-hidden="true"></i>Opens</span><span><i class="legend-click" aria-hidden="true"></i>Clicks</span><span><i class="legend-lead" aria-hidden="true"></i>Leads</span><span><i class="legend-unsubscribe" aria-hidden="true"></i>Unsubscribes</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover table-striped table-bordered analytics-table">
                        <caption class="sr-only">Daily campaign and Drop opens, clicks, leads, and unsubscribes for the last 30 days</caption>
                        <thead><tr><th scope="col">DATE</th><th scope="col">EVENTS</th><th scope="col">OPENS</th><th scope="col">CLICKS</th><th scope="col">LEADS</th><th scope="col">UNSUBSCRIBES</th><th scope="col">LEAD REVENUE</th></tr></thead>
                        <tbody>
                            @foreach ($dailyRows as $row)
                                @php($total = $row['opens'] + $row['clicks'] + $row['leads'] + $row['unsubscribes'])
                                @php($scale = 100 / $maxDailyEvents)
                                <tr>
                                    <td>{{ \Illuminate\Support\Carbon::parse($row['at'])->format('M j, Y') }}</td>
                                    <td><div class="analytics-bar" role="img" aria-label="{{ number_format($total) }} total events"><span class="legend-open" style="width: {{ min(100, $row['opens'] * $scale) }}%"></span><span class="legend-click" style="width: {{ min(100, $row['clicks'] * $scale) }}%"></span><span class="legend-lead" style="width: {{ min(100, $row['leads'] * $scale) }}%"></span><span class="legend-unsubscribe" style="width: {{ min(100, $row['unsubscribes'] * $scale) }}%"></span></div></td>
                                    <td class="text-right">{{ number_format($row['opens']) }}</td><td class="text-right">{{ number_format($row['clicks']) }}</td><td class="text-right">{{ number_format($row['leads']) }}</td><td class="text-right">{{ number_format($row['unsubscribes']) }}</td><td class="text-right">${{ number_format($row['lead_revenue'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">LATEST AUDIENCE-LIST STATES</h3>
            <div class="block-options"><a class="btn btn-sm btn-alt-primary" href="{{ route('console.infrastructure.list-history.index', $workspace->id) }}">LIST HISTORY</a></div>
        </div>
        <div class="block-content block-content-full">
            @if (! $hasAudienceSnapshots)
                <div class="alert alert-info mb-0" role="status">No list snapshots recorded. Counts appear after a list is imported or its scheduled history calculation runs.</div>
            @else
                <div class="table-responsive"><table class="table table-sm table-hover table-striped table-bordered mb-1">
                    <thead><tr><th scope="col">FRESH</th><th scope="col">DELIVERY</th><th scope="col">OPEN</th><th scope="col">CLICK</th><th scope="col">LEAD</th><th scope="col">UNSUBSCRIBE</th></tr></thead>
                    <tbody><tr>@foreach (['fresh', 'delivery', 'open', 'click', 'lead', 'unsubscribe'] as $type)<td class="text-right">{{ number_format($snapshotCounts[$type]) }}</td>@endforeach</tr></tbody>
                </table></div>
                <small class="text-muted">These are current membership-state snapshots, not unique event totals.</small>
            @endif
        </div>
    </div>

    <div class="alert alert-warning" role="note"><strong>Data coverage:</strong> stored campaign and Drop engagement, attributed campaign leads, and locally imported PMTA delivery/bounce records are included. PMTA feedback sync is implemented; live scheduled/Horizon execution and DMS seed identity mapping remain unverified. DMS labels the previous day's count as YTD in its Main Dashboard source; AlphaLink mirrors that calculation. Earnings here reflect attributed campaign and Drop lead revenue only.</div>
</div>
