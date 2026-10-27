@php
    $ispChartMetrics = [
        'opens' => ['title' => 'OPENS BY MAILER', 'format' => 'count'],
        'clicks' => ['title' => 'CLICKS BY MAILER', 'format' => 'count'],
        'leads' => ['title' => 'LEADS BY MAILER', 'format' => 'count'],
        'unsubscribes' => ['title' => 'UNSUBSCRIBES BY MAILER', 'format' => 'count'],
        'lead_revenue' => ['title' => 'ATTRIBUTED LEAD REVENUE BY MAILER', 'format' => 'currency'],
    ];
@endphp

<section class="isp-stats-visuals mb-3" aria-labelledby="isp-stats-visuals-title">
    <div class="block block-rounded mb-3">
        <div class="block-header block-header-default">
            <h3 class="block-title" id="isp-stats-visuals-title">ENGAGEMENT BY PERIOD</h3>
        </div>
        <div class="block-content block-content-full">
            <div class="isp-stats-chart" data-isp-stats-chart="overview" aria-label="Engagement totals by period">
                <p class="text-center text-muted mb-0" data-isp-chart-empty role="status">NO DATA AVAILABLE</p>
            </div>
            <div class="table-responsive mt-3">
                <table class="table table-sm table-hover table-bordered mb-0" data-isp-sortable>
                    <caption class="sr-only">Engagement totals by period</caption>
                    <thead><tr>
                        @foreach (['PERIOD', 'OPENS', 'CLICKS', 'LEADS', 'UNSUBSCRIBES', 'ATTRIBUTED LEAD REVENUE'] as $column => $label)
                            <th scope="col"><button type="button" class="isp-sort-trigger" data-isp-sort-column="{{ $column }}" data-sort-type="{{ $column === 0 ? 'text' : 'number' }}" aria-label="Sort by {{ $label }}">{{ $label }} <span class="isp-sort-indicator" aria-hidden="true">↕</span></button></th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @forelse ($chartData['labels'] as $index => $label)
                            @php($totals = $chartData['totals'][$index])
                            <tr><th scope="row">{{ $label }}</th><td class="text-right" data-sort-value="{{ $totals['opens'] }}">{{ number_format($totals['opens']) }}</td><td class="text-right" data-sort-value="{{ $totals['clicks'] }}">{{ number_format($totals['clicks']) }}</td><td class="text-right" data-sort-value="{{ $totals['leads'] }}">{{ number_format($totals['leads']) }}</td><td class="text-right" data-sort-value="{{ $totals['unsubscribes'] }}">{{ number_format($totals['unsubscribes']) }}</td><td class="text-right" data-sort-value="{{ $totals['lead_revenue'] }}">${{ number_format($totals['lead_revenue'], 2) }}</td></tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted">No engagement or attributed campaign leads are recorded for this ISP and date range.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row">
        @foreach ($ispChartMetrics as $metric => $config)
            <div class="col-12 mb-3">
                <section class="block block-rounded mb-0" aria-labelledby="isp-stats-{{ $metric }}-title">
                    <div class="block-header block-header-default">
                        <h3 class="block-title" id="isp-stats-{{ $metric }}-title">{{ $config['title'] }}</h3>
                    </div>
                    <div class="block-content block-content-full">
                        <div class="isp-stats-chart" data-isp-stats-chart="{{ $metric }}" aria-label="{{ strtolower($config['title']) }} trend">
                            <p class="text-center text-muted mb-0" data-isp-chart-empty role="status">NO DATA AVAILABLE</p>
                        </div>
                        <div class="table-responsive mt-3">
                <table class="table table-sm table-hover table-bordered mb-0" data-isp-sortable>
                    <caption class="sr-only">{{ $config['title'] }} by period</caption>
                                <thead><tr>
                                    <th scope="col"><button type="button" class="isp-sort-trigger" data-isp-sort-column="0" data-sort-type="text" aria-label="Sort by Mailer">MAILER <span class="isp-sort-indicator" aria-hidden="true">↕</span></button></th>
                                    @foreach ($chartData['labels'] as $column => $label)
                                        <th scope="col"><button type="button" class="isp-sort-trigger" data-isp-sort-column="{{ $column + 1 }}" data-sort-type="number" aria-label="Sort by {{ $label }}">{{ $label }} <span class="isp-sort-indicator" aria-hidden="true">↕</span></button></th>
                                    @endforeach
                                </tr></thead>
                                <tbody>
                                    @forelse ($chartData['mailers'] as $mailer)
                                        <tr>
                                            <th scope="row">{{ $mailer['label'] }}</th>
                                            @foreach ($mailer['values'][$metric] as $value)
                                                <td class="text-right" data-sort-value="{{ $value }}">{{ $config['format'] === 'currency' ? '$'.number_format((float) $value, 2) : number_format((int) $value) }}</td>
                                            @endforeach
                                        </tr>
                                    @empty
                                        <tr><td colspan="{{ max(1, count($chartData['labels']) + 1) }}" class="text-center text-muted">No mailer data is recorded for this ISP and date range.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>
        @endforeach
    </div>

    <script type="application/json" data-isp-stats-chart-data>{!! json_encode($chartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
</section>
