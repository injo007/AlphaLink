<div id="analytics-report-results" aria-busy="false">
    <div class="row mb-3">
        @foreach ([
            ['label' => 'OPENS', 'value' => number_format($summary['opens'])],
            ['label' => 'CLICKS', 'value' => number_format($summary['clicks'])],
            ['label' => 'LEADS', 'value' => number_format($summary['leads'])],
            ['label' => 'UNSUBSCRIBES', 'value' => number_format($summary['unsubscribes'])],
            ['label' => 'BOTS', 'value' => number_format($summary['bots'])],
            ['label' => 'LEAD REVENUE', 'value' => '$'.number_format($summary['lead_revenue'], 2)],
        ] as $metric)
            <div class="col-6 col-md-4 col-xl-2 mb-2">
                <div class="block block-rounded border mb-0">
                    <div class="block-content py-3">
                        <div class="font-size-sm text-muted">{{ $metric['label'] }}</div>
                        <div class="h4 mb-0">{{ $metric['value'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div id="drop-statistics-table-wrap">
        <div class="table-responsive">
        <table class="table table-sm table-vcenter table-bordered mb-2" aria-label="Aggregated Drop event statistics">
            <thead class="thead-light">
                <tr>
                    <th scope="col">DATE</th>
                    <th scope="col">DROP</th>
                    <th scope="col">MAILER</th>
                    <th scope="col">ISP</th>
                    <th scope="col">LIST</th>
                    <th scope="col">LIST TYPE</th>
                    <th scope="col">GEO</th>
                    <th scope="col">OPENS</th>
                    <th scope="col">CLICKS</th>
                    <th scope="col">LEADS</th>
                    <th scope="col">UNSUBS</th>
                    <th scope="col">BOTS</th>
                    <th scope="col">REVENUE</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>{{ $row['at'] }}</td>
                        <td>#{{ $row['drop_id'] }} <span class="text-muted">{{ strtoupper($row['drop_type']) }}</span></td>
                        <td>{{ $row['user'] }}</td>
                        <td>{{ $row['isp'] }}</td>
                        <td>{{ $row['list'] }}</td>
                        <td>{{ $row['list_type'] }}</td>
                        <td>{{ $row['country'] }}</td>
                        <td>{{ number_format($row['opens']) }}</td>
                        <td>{{ number_format($row['clicks']) }}</td>
                        <td>{{ number_format($row['leads']) }}</td>
                        <td>{{ number_format($row['unsubscribes']) }}</td>
                        <td>{{ number_format($row['bots']) }}</td>
                        <td>${{ number_format($row['lead_revenue'], 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="13" class="text-center text-muted py-4">No recorded Drop activity matches these filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>

        <div class="d-flex flex-wrap align-items-center justify-content-between">
            <div class="small text-muted mb-2">{{ number_format($rows->total()) }} grouped rows · totals above include all matching groups.</div>
            @if ($rows->hasPages())
                <div>{{ $rows->links() }}</div>
            @endif
        </div>
    </div>
</div>
