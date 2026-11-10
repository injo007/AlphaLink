<section class="campaign-log-config" aria-labelledby="campaign-log-config-title">
    <header class="campaign-log-config-heading">
        @if (request()->boolean('fragment'))
            <button class="campaign-log-config-back" type="button" data-campaign-history-back><i class="fas fa-arrow-left" aria-hidden="true"></i> History</button>
        @else
            <a class="campaign-log-config-back" href="{{ route('console.campaign-logs.history', array_merge([$workspace->id, $campaign->id, $history], $operatorCampaignViewQuery)) }}"><i class="fas fa-arrow-left" aria-hidden="true"></i> History</a>
        @endif
        <div>
            <span class="campaign-log-modal-eyebrow">{{ strtoupper($event['type']) }} CONFIGURATION</span>
            <h2 id="campaign-log-config-title" tabindex="-1">{{ $campaign->name }}</h2>
            <p>{{ $event['recorded_at'] ? \Illuminate\Support\Carbon::parse($event['recorded_at'])->format('Y-m-d H:i:s') : 'Time unavailable' }} · {{ $event['actor'] }}@if ($event['revision']) · Revision {{ $event['revision'] }}@endif</p>
        </div>
        <span class="campaign-log-modal-status">{{ strtoupper(str_replace('_', ' ', (string) ($event['status'] ?? '—'))) }}</span>
    </header>

    @unless ($configuration['available'])
        <div class="campaign-log-config-unavailable" role="status"><i class="fas fa-info-circle" aria-hidden="true"></i> This record predates the saved configuration snapshot. Configuration details are unavailable for this event.</div>
    @endunless

    <div class="campaign-log-config-grid">
        <section class="campaign-log-config-card">
            <h3><i class="fas fa-server" aria-hidden="true"></i> Sending route</h3>
            <dl>
                <div><dt>Route</dt><dd>{{ $configuration['route'] !== '' ? $configuration['route'] : '—' }}</dd></div>
                <div><dt>Send type</dt><dd>{{ $event['send_type'] ?? ($configuration['send_type'] !== '' ? $configuration['send_type'] : '—') }}</dd></div>
                <div><dt>Servers</dt><dd>@forelse ($configuration['servers'] as $server)<span class="campaign-log-value-chip">{{ $server }}</span>@empty — @endforelse<small class="campaign-log-server-note">{{ $configuration['server_note'] }}</small></dd></div>
                @if ($configuration['ip_ids']->isNotEmpty())
                    <div><dt>Selected IP / route IDs</dt><dd>@foreach ($configuration['ip_ids'] as $ip)<span class="campaign-log-value-chip">{{ $ip }}</span>@endforeach</dd></div>
                @endif
            </dl>
            @if ($configuration['route_targets']->isNotEmpty())
                <div class="campaign-log-route-list">
                    <h4>Resolved routes</h4>
                    @foreach ($configuration['route_targets'] as $route)
                        <div><strong>{{ $route['name'] }}</strong><span>PMTA {{ $route['pmta_name'] }} · {{ $route['domain'] }}</span></div>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="campaign-log-config-card">
            <h3><i class="fas fa-sliders-h" aria-hidden="true"></i> Send settings</h3>
            @if (count($configuration['settings']))
                <dl>@foreach ($configuration['settings'] as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach</dl>
            @else
                <p class="campaign-log-config-muted">No additional send settings were captured.</p>
            @endif
            <dl><div><dt>Return path</dt><dd>{{ $configuration['return_path'] ?: '—' }}</dd></div></dl>
        </section>
    </div>

    <section class="campaign-log-config-card campaign-log-config-message">
        <h3><i class="fas fa-envelope" aria-hidden="true"></i> Message configuration</h3>
        <h4>Header</h4>
        <pre>{{ $configuration['header'] !== '' ? $configuration['header'] : 'No header captured.' }}</pre>
        <h4>Body</h4>
        <pre>{{ $configuration['body'] !== '' ? $configuration['body'] : 'No body captured.' }}</pre>
    </section>
</section>
