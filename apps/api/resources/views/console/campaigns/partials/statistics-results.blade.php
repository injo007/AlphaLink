<div id="analytics-report-results" aria-busy="false">
    <div class="row row-deck mb-3" aria-label="Filtered campaign totals">
        @foreach ([['DELIVERIES', 'deliveries'], ['SOFT BOUNCES', 'soft_bounces'], ['HARD BOUNCES', 'hard_bounces'], ['OPENS', 'opens'], ['CLICKS', 'clicks'], ['LEADS', 'leads'], ['CONVERSIONS', 'conversions'], ['UNSUBS', 'unsubscribes']] as [$label, $key])
            <div class="col-6 col-xl-3"><div class="block block-rounded block-link-shadow"><div class="block-content block-content-full"><div class="font-size-h3 font-w600">{{ number_format($summary[$key]) }}</div><div class="font-size-sm text-muted">{{ $label }}</div></div></div></div>
        @endforeach
    </div>

    @if ($rows->isEmpty())
        <div class="alert alert-info" role="status">No campaign activity or attributed leads match these filters.</div>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover table-striped table-bordered table-vcenter">
                <caption class="sr-only">Campaign delivery, bounce, engagement, and attributed lead statistics grouped by campaign, list, ISP, list type, and date</caption>
                <thead><tr>
                    <th scope="col">DATE</th><th scope="col">CAMPAIGN</th><th scope="col">MAILER</th><th scope="col">ISP</th><th scope="col">LIST</th><th scope="col">LIST TYPE</th>
                    <th scope="col" class="text-right">DELIVERIES</th><th scope="col" class="text-right">SOFT BOUNCES</th><th scope="col" class="text-right">HARD BOUNCES</th><th scope="col" class="text-right">OPENS</th><th scope="col" class="text-right">CLICKS</th><th scope="col" class="text-right">LEADS</th><th scope="col" class="text-right">CONVERSIONS</th><th scope="col" class="text-right">EARNS</th><th scope="col" class="text-right">UNSUBS</th><th scope="col" class="text-right">BOTS</th>
                </tr></thead>
                <tbody>@foreach ($rows as $row)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($row['event_date'])->format('Y-m-d') }}</td><td>{{ $row['campaign'] }}</td><td>{{ $row['mailer'] }}</td><td>{{ $row['isp'] }}</td><td>{{ $row['audience_list'] }}</td><td>{{ strtoupper($row['list_type']) }}</td>
                        <td class="text-right">{{ number_format($row['deliveries']) }}</td><td class="text-right">{{ number_format($row['soft_bounces']) }}</td><td class="text-right">{{ number_format($row['hard_bounces']) }}</td><td class="text-right">{{ number_format($row['opens']) }}</td><td class="text-right">{{ number_format($row['clicks']) }}</td><td class="text-right">{{ number_format($row['leads']) }}</td><td class="text-right">{{ number_format($row['conversions']) }}</td><td class="text-right">${{ number_format($row['lead_revenue'], 2) }}</td><td class="text-right">{{ number_format($row['unsubscribes']) }}</td><td class="text-right">{{ number_format($row['bots']) }}</td>
                    </tr>
                @endforeach</tbody>
            </table>
        </div>
        <div class="d-flex flex-wrap justify-content-between align-items-center mt-2" aria-label="Campaign statistics pagination">
            <small class="text-muted">Showing {{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }} rows · Leads revenue: ${{ number_format($summary['lead_revenue'], 2) }}</small>
            {{ $rows->links() }}
        </div>
    @endif
</div>
