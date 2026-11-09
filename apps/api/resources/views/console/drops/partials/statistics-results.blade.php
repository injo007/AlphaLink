<div id="analytics-report-results" aria-busy="false">
    <div class="command-os-all-stats-overview mb-3">
        @php
            $metricIcons = [
                'OPENS' => ['fas fa-envelope-open', 'records'],
                'CLICKS' => ['fas fa-mouse-pointer', 'events'],
                'LEADS' => ['fas fa-funnel-dollar', 'leads'],
                'UNSUBSCRIBES' => ['fas fa-user-minus', 'scope'],
                'BOTS' => ['fas fa-robot', 'pivot'],
                'LEAD REVENUE' => ['fas fa-dollar-sign', 'leads'],
            ];
        @endphp
        @foreach ([
            ['label' => 'OPENS', 'value' => number_format($summary['opens'])],
            ['label' => 'CLICKS', 'value' => number_format($summary['clicks'])],
            ['label' => 'LEADS', 'value' => number_format($summary['leads'])],
            ['label' => 'UNSUBSCRIBES', 'value' => number_format($summary['unsubscribes'])],
            ['label' => 'BOTS', 'value' => number_format($summary['bots'])],
            ['label' => 'LEAD REVENUE', 'value' => '$'.number_format($summary['lead_revenue'], 2)],
        ] as $metric)
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-{{ $metricIcons[$metric['label']][1] ?? 'records' }}" aria-hidden="true">
                    <i class="{{ $metricIcons[$metric['label']][0] ?? 'fas fa-chart-line' }}"></i>
                </span>
                <span class="all-stats-overview-copy">
                    <span>{{ $metric['label'] }}</span>
                    <strong>{{ $metric['value'] }}</strong>
                    <small>Summary metric</small>
                </span>
            </article>
        @endforeach
    </div>

    <div id="drop-statistics-table-wrap">
        <div class="table-responsive">
            <table class="table table-sm table-hover table-striped w-100" aria-label="Aggregated Drop event statistics">
                <thead>
                    <tr>
                        <th scope="col">DATE</th>
                        <th scope="col">DROP</th>
                        <th scope="col">MAILER</th>
                        <th scope="col">ISP</th>
                        <th scope="col">LIST</th>
                        <th scope="col">LIST TYPE</th>
                        <th scope="col">GEO</th>
                        <th scope="col" class="text-right">OPENS</th>
                        <th scope="col" class="text-right">CLICKS</th>
                        <th scope="col" class="text-right">LEADS</th>
                        <th scope="col" class="text-right">UNSUBS</th>
                        <th scope="col" class="text-right">BOTS</th>
                        <th scope="col" class="text-right">REVENUE</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row['at'] }}</td>
                            <td><span class="font-w600 text-purple">#{{ $row['drop_id'] }}</span> <span class="badge badge-light border text-purple font-w700 font-size-xs">{{ strtoupper($row['drop_type']) }}</span></td>
                            <td class="font-w600">{{ $row['user'] }}</td>
                            <td>{{ $row['isp'] }}</td>
                            <td>{{ $row['list'] }}</td>
                            <td><span class="badge badge-light border font-w600">{{ $row['list_type'] }}</span></td>
                            <td><span class="badge badge-secondary px-2 py-1 font-w700">{{ $row['country'] }}</span></td>
                            <td class="text-right font-w600" style="font-family: var(--font-mono, monospace);">{{ number_format($row['opens']) }}</td>
                            <td class="text-right font-w600 text-primary" style="font-family: var(--font-mono, monospace);">{{ number_format($row['clicks']) }}</td>
                            <td class="text-right font-w600 text-success" style="font-family: var(--font-mono, monospace);">{{ number_format($row['leads']) }}</td>
                            <td class="text-right font-w600 text-warning" style="font-family: var(--font-mono, monospace);">{{ number_format($row['unsubscribes']) }}</td>
                            <td class="text-right font-w600 text-muted" style="font-family: var(--font-mono, monospace);">{{ number_format($row['bots']) }}</td>
                            <td class="text-right font-w600 text-success" style="font-family: var(--font-mono, monospace);">${{ number_format($row['lead_revenue'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="13" class="text-muted text-center py-5">
                                <i class="fas fa-inbox fa-2x mb-2 d-block text-purple opacity-50"></i>
                                No matching Drop events found for this filter selection.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                <div class="text-muted font-size-sm">
                    Showing {{ $rows->firstItem() }} to {{ $rows->lastItem() }} of {{ $rows->total() }} records
                </div>
                <div>
                    {{ $rows->links() }}
                </div>
            </div>
        @endif
    </div>
</div>
