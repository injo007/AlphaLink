<div id="analytics-report-results" aria-busy="false">
    @if ($kind === 'isp-stats')
        @include('console.analytics.partials.isp-stats-charts')
    @endif

    @if ($rows->isEmpty())
        <div class="command-table-empty-state py-5 text-center" role="status">
            <div class="command-empty-state-icon mb-3" style="font-size: 2.2rem; color: #8b5cf6; opacity: 0.85;">
                <i class="fas fa-inbox"></i>
            </div>
            <h4 class="font-w600 text-ink mb-1">No Matching Activity Recorded</h4>
            <p class="text-muted font-size-sm mb-0">No matching campaign events, Drop visitor events, or attributed campaign leads are recorded for this date range.</p>
        </div>
    @else
        <div class="table-responsive all-stats-table-wrap">
            <table class="table table-sm table-hover table-striped w-100 all-stats-table">
                <caption class="sr-only">{{ $title }} for the selected date range</caption>
                <thead>
                    <tr>
                        @foreach ($headers as $header)
                            <th scope="col" class="{{ in_array($header, ['COUNT', 'LEAD REVENUE', 'DELIVERIES', 'SOFT BOUNCES', 'HARD BOUNCES', 'OPENS', 'CLICKS', 'LEADS', 'UNSUBSCRIBES', 'BOTS', 'REVENUE']) ? 'text-right' : '' }}">{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            @if ($kind === 'isp-stats')
                                <td><span class="badge badge-light border text-purple font-w700">{{ $row['period'] }}</span></td>
                                <td class="font-w600">{{ $row['isp'] }}</td>
                                <td class="text-right font-w600" style="font-family: var(--font-mono, monospace);">{{ number_format($row['deliveries']) }}</td>
                                <td class="text-right font-w600" style="font-family: var(--font-mono, monospace);">{{ number_format($row['opens']) }}</td>
                                <td class="text-right font-w600 text-primary" style="font-family: var(--font-mono, monospace);">{{ number_format($row['clicks']) }}</td>
                                <td class="text-right font-w600 text-success" style="font-family: var(--font-mono, monospace);">{{ number_format($row['leads']) }}</td>
                                <td class="text-right font-w600 text-warning" style="font-family: var(--font-mono, monospace);">{{ number_format($row['unsubscribes']) }}</td>
                                <td class="text-right font-w600 text-success" style="font-family: var(--font-mono, monospace);">
                                    ${{ number_format($row['lead_revenue'], 2) }}
                                </td>
                            @else
                                <td class="font-w600 text-purple">{{ $row['mailer'] }}</td>
                                <td class="text-right font-w600" style="font-family: var(--font-mono, monospace);">{{ number_format((int) $row['deliveries']) }}</td>
                                <td class="text-right font-w600 text-muted" style="font-family: var(--font-mono, monospace);">{{ number_format((int) $row['soft_bounces']) }}</td>
                                <td class="text-right font-w600 text-danger" style="font-family: var(--font-mono, monospace);">{{ number_format((int) $row['hard_bounces']) }}</td>
                                <td class="text-right font-w600" style="font-family: var(--font-mono, monospace);">{{ number_format((int) $row['opens']) }}</td>
                                <td class="text-right font-w600 text-primary" style="font-family: var(--font-mono, monospace);">{{ number_format((int) $row['clicks']) }}</td>
                                <td class="text-right font-w600 text-success" style="font-family: var(--font-mono, monospace);">{{ number_format((int) $row['leads']) }}</td>
                                <td class="text-right font-w600 text-warning" style="font-family: var(--font-mono, monospace);">{{ number_format((int) $row['unsubscribes']) }}</td>
                                <td class="text-right font-w600 text-muted" style="font-family: var(--font-mono, monospace);">{{ number_format((int) $row['bots']) }}</td>
                                <td class="text-right font-w600 text-success" style="font-family: var(--font-mono, monospace);">
                                    ${{ number_format((float) $row['lead_revenue'], 2) }}
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 pt-2 border-top all-stats-pagination" aria-label="Report pagination">
            <small class="text-muted">Showing {{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }} rows</small>
            {{ $rows->links() }}
        </div>
    @endif

    @if (!empty($coverage) && $kind !== 'all-stats')
        <div class="command-os-info-banner mt-3 py-2 px-3 font-size-xs" role="note">
            <div class="command-os-info-banner-icon mr-2"><i class="fas fa-info-circle"></i></div>
            <div class="command-os-info-banner-content text-muted"><strong>Data coverage:</strong> {{ $coverage }}</div>
        </div>
    @endif
</div>
