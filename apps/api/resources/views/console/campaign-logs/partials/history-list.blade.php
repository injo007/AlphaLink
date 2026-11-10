@php
    $historyTitle = ['tests' => 'Test history', 'updates' => 'Update history', 'sends' => 'Send history'][$history];
    $historyIcon = ['tests' => 'fa-vial', 'updates' => 'fa-pen-to-square', 'sends' => 'fa-paper-plane'][$history];
@endphp
<section class="campaign-log-modal-list" aria-labelledby="campaign-log-modal-title">
    <header class="campaign-log-modal-heading">
        <div>
            <span class="campaign-log-modal-eyebrow">CAMPAIGN LOGS / {{ strtoupper($history) }}</span>
            <h2 id="campaign-log-modal-title" tabindex="-1"><i class="fas {{ $historyIcon }}" aria-hidden="true"></i> {{ $historyTitle }}</h2>
            <p>{{ $campaign->name }} <span class="campaign-log-modal-id">{{ strtoupper(substr((string) $campaign->id, 0, 8)) }}</span></p>
        </div>
        <span class="campaign-log-modal-count">{{ number_format($historyRows->total()) }} {{ $history }}</span>
    </header>
    <nav class="campaign-log-history-tabs" aria-label="Campaign history type">
        @foreach (['tests' => 'Tests', 'updates' => 'Updates', 'sends' => 'Sends'] as $key => $label)
            <button type="button" data-campaign-history-tab="{{ route('console.campaign-logs.history', array_merge([$workspace->id, $campaign->id, $key], array_merge($operatorCampaignViewQuery, ['fragment' => 1]))) }}" @if ($history === $key) aria-current="page" @endif>{{ $label }} <span>{{ number_format($counts[$key]) }}</span></button>
        @endforeach
    </nav>

    <div class="campaign-log-modal-table-wrap" role="region" aria-label="{{ $historyTitle }} entries" tabindex="0">
        <table class="campaign-log-modal-table">
            @if ($history === 'tests')
                <thead><tr><th scope="col">Type</th><th scope="col">Outcome</th><th scope="col">Recorded by</th><th scope="col">Created at</th><th scope="col">Configuration</th></tr></thead>
                <tbody>
                    @forelse ($historyRows as $row)
                        @php $detailUrl = route('console.campaign-logs.history-detail', array_merge([$workspace->id, $campaign->id, 'tests', $row->id], ['source' => $row->test_source_key, 'fragment' => 1], $operatorCampaignViewQuery)); @endphp
                        <tr>
                            <td>{{ $row->test_source }}</td>
                            <td><span class="campaign-log-modal-status">{{ strtoupper(str_replace('_', ' ', $row->status)) }}</span></td>
                            <td>{{ $row->actor_label }}</td>
                            <td class="text-nowrap">{{ \Illuminate\Support\Carbon::parse($row->created_at)->format('Y-m-d H:i:s') }}</td>
                            <td><button type="button" class="campaign-log-config-button" data-campaign-detail-url="{{ $detailUrl }}"><i class="fas fa-sliders-h" aria-hidden="true"></i> View config</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="campaign-log-modal-empty">No test activity is recorded for this campaign.</td></tr>
                    @endforelse
                </tbody>
            @elseif ($history === 'updates')
                <thead><tr><th scope="col">Revision</th><th scope="col">Content fingerprint</th><th scope="col">Updated by</th><th scope="col">Created at</th><th scope="col">Configuration</th></tr></thead>
                <tbody>
                    @forelse ($historyRows as $row)
                        @php $detailUrl = route('console.campaign-logs.history-detail', array_merge([$workspace->id, $campaign->id, 'updates', $row->id], array_merge($operatorCampaignViewQuery, ['fragment' => 1]))); @endphp
                        <tr>
                            <td>Revision {{ $row->revision_number }}</td>
                            <td class="campaign-log-modal-id">{{ substr($row->content_sha256, 0, 12) }}</td>
                            <td>{{ $row->actor_label }}</td>
                            <td class="text-nowrap">{{ $row->created_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                            <td><button type="button" class="campaign-log-config-button" data-campaign-detail-url="{{ $detailUrl }}"><i class="fas fa-sliders-h" aria-hidden="true"></i> View config</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="campaign-log-modal-empty">No campaign updates are recorded.</td></tr>
                    @endforelse
                </tbody>
            @else
                <thead><tr><th scope="col">Send type</th><th scope="col">Status</th><th scope="col">Accepted / rejected</th><th scope="col">Requested by</th><th scope="col">Started at</th><th scope="col">Configuration</th></tr></thead>
                <tbody>
                    @forelse ($historyRows as $row)
                        @php $detailUrl = route('console.campaign-logs.history-detail', array_merge([$workspace->id, $campaign->id, 'sends', $row->id], array_merge($operatorCampaignViewQuery, ['fragment' => 1]))); @endphp
                        <tr>
                            <td>{{ strtoupper($row->send_type) }}</td>
                            <td><span class="campaign-log-modal-status">{{ strtoupper(str_replace('_', ' ', $row->state)) }}</span></td>
                            <td>{{ number_format((int) $row->accepted_count) }} / {{ number_format((int) $row->rejected_count) }}</td>
                            <td>{{ $row->actor_label }}</td>
                            <td class="text-nowrap">{{ $row->started_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                            <td><button type="button" class="campaign-log-config-button" data-campaign-detail-url="{{ $detailUrl }}"><i class="fas fa-sliders-h" aria-hidden="true"></i> View config</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="campaign-log-modal-empty">No send runs are recorded for this campaign.</td></tr>
                    @endforelse
                </tbody>
            @endif
        </table>
    </div>

    <footer class="campaign-log-modal-footer">
        <span>Showing {{ $historyRows->count() }} of {{ $historyRows->total() }} records</span>
        {{ $historyRows->links() }}
    </footer>
</section>
