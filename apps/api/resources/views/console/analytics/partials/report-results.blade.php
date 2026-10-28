<div id="analytics-report-results" aria-busy="false">
    @if ($kind === 'isp-stats')
        @include('console.analytics.partials.isp-stats-charts')
    @endif

    @if ($rows->isEmpty())
        <div class="alert alert-info" role="status">No matching campaign events, Drop visitor events, or attributed campaign leads are recorded for this date range.</div>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover table-striped table-bordered">
                <caption class="sr-only">{{ $title }} for the selected date range</caption>
                <thead>
                    <tr>@foreach ($headers as $header)<th scope="col">{{ $header }}</th>@endforeach</tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            @if ($kind === 'isp-stats')
                                <td>{{ $row['period'] }}</td>
                                <td>{{ $row['isp'] }}</td>
                                <td class="text-right">{{ number_format($row['deliveries']) }}</td>
                                <td class="text-right">{{ number_format($row['opens']) }}</td>
                                <td class="text-right">{{ number_format($row['clicks']) }}</td>
                                <td class="text-right">{{ number_format($row['leads']) }}</td>
                                <td class="text-right">{{ number_format($row['unsubscribes']) }}</td>
                                <td class="text-right">${{ number_format($row['lead_revenue'], 2) }}</td>
                            @else
                                <td>{{ $row['mailer'] }}</td>
                                <td class="text-right">{{ number_format((int) $row['deliveries']) }}</td>
                                <td class="text-right">{{ number_format((int) $row['soft_bounces']) }}</td>
                                <td class="text-right">{{ number_format((int) $row['hard_bounces']) }}</td>
                                <td class="text-right">{{ number_format((int) $row['opens']) }}</td>
                                <td class="text-right">{{ number_format((int) $row['clicks']) }}</td>
                                <td class="text-right">{{ number_format((int) $row['leads']) }}</td>
                                <td class="text-right">{{ number_format((int) $row['unsubscribes']) }}</td>
                                <td class="text-right">{{ number_format((int) $row['bots']) }}</td>
                                <td class="text-right">${{ number_format((float) $row['lead_revenue'], 2) }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="d-flex flex-wrap justify-content-between align-items-center mt-2" aria-label="Report pagination">
            <small class="text-muted">Showing {{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }} rows</small>
            {{ $rows->links() }}
        </div>
    @endif

    <div class="alert alert-warning mb-0" role="note"><strong>Data coverage:</strong> {{ $coverage }}</div>
</div>
