@php
    $selectedIsp = (string) old('send_setup.isp_id', $sendSetup['isp_id'] ?? '');
    $selectedCountry = strtoupper((string) old('send_setup.country', $sendSetup['country'] ?? 'ALL'));
    $selectedSegments = array_map('strval', (array) old('send_setup.audience_segments', $selectedSegments));
    $selectedOrder = (string) old('send_setup.order_by', $sendSetup['order_by'] ?? 'default');
    $selectedStart = (string) old('send_setup.start_from', $sendSetup['start_from'] ?? 0);
    $segments = collect($sendOptions['segments'] ?? []);
    $segmentIds = $segments->pluck('id')->map(fn ($id): string => (string) $id)->all();
    $selectedSegments = array_values(array_intersect($selectedSegments, $segmentIds));
    $selectedRecipientEstimate = (int) $segments
        ->filter(fn (array $segment): bool => in_array((string) $segment['id'], $selectedSegments, true))
        ->sum('recipient_count');
@endphp

<div class="campaign-clone-form-content">
    @if ($errors->any())
        <div class="alert alert-danger" role="alert" tabindex="-1">
            <p class="mb-1">Review the campaign details below:</p>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="alert alert-info" role="status">
        Choose the ISP, country and lists for the cloned campaign. Its saved content and sending setup are copied. The offer suppression file is checked while the new recipient list is prepared. Cloning does not start a send.
    </div>

    <form method="POST" action="{{ route('console.campaigns.clone.store', [$workspace->id, $source->id]) }}" id="campaign-clone-form" data-options-url="{{ $sendOptionsUrl }}" data-cancel-url="{{ route('console.campaigns.index', $workspace->id) }}" data-sponsor-id="{{ $sendSetup['sponsor_id'] }}" data-offer-country="{{ $sendSetup['offer_country'] }}" data-route-option="{{ $sendSetup['route_option'] }}" data-ip-type="{{ $sendSetup['ip_type'] }}" data-server-ids="{{ json_encode(array_map('strval', $sendSetup['server_ids'])) }}">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
        <input type="hidden" name="source_revision_id" value="{{ $sourceRevision->id }}">

        <div class="form-group">
            <label for="clone-name">Campaign name</label>
            <input id="clone-name" name="name" type="text" maxlength="160" class="form-control" value="{{ old('name', 'Copy of '.$source->name) }}" required>
        </div>

        <div class="form-row">
            <div class="form-group col-md-6">
                <label for="clone-isp">ISP</label>
                <select id="clone-isp" name="send_setup[isp_id]" class="form-control selectpicker" data-live-search="true" title="Select ISP" required>
                    @foreach ($sendOptions['isps'] ?? [] as $isp)
                        <option value="{{ $isp['id'] }}" data-countries="{{ json_encode($isp['tracked_countries'] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) }}" @selected($selectedIsp === (string) $isp['id'])>{{ $isp['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group col-md-6">
                <label for="clone-country">Country</label>
                <select id="clone-country" name="send_setup[country]" class="form-control selectpicker" data-live-search="true" title="Select country" required>
                    <option value="ALL" @selected($selectedCountry === 'ALL')>ALL</option>
                    @foreach (($sendOptions['isps'] ?? []) as $isp)
                        @if ((string) $isp['id'] === $selectedIsp)
                            @foreach (($isp['tracked_countries'] ?? []) as $country)
                                <option value="{{ strtoupper($country) }}" @selected($selectedCountry === strtoupper($country))>{{ strtoupper($country) }}</option>
                            @endforeach
                        @endif
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-group">
            <label for="clone-segments">Lists</label>
            <select id="clone-segments" name="send_setup[audience_segments][]" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="8" data-selected-text-format="count > 3" title="Select lists" multiple required>
                @foreach ($segments as $segment)
                    @php($segmentId = (string) $segment['id'])
                    <option value="{{ $segmentId }}" data-recipient-count="{{ $segment['recipient_count'] }}" @selected(in_array($segmentId, $selectedSegments, true))>{{ $segment['name'] }} · {{ strtoupper($segment['email_type']) }} · {{ number_format($segment['recipient_count']) }}</option>
                @endforeach
            </select>
            <small class="form-text text-muted">Selected list members before suppression and duplicate removal: <strong id="clone-recipient-estimate">{{ number_format($selectedRecipientEstimate) }}</strong></small>
            @if ($segments->isEmpty())
                <small class="form-text text-warning">No eligible lists are available for this ISP and country. Check active lists with current members.</small>
            @endif
        </div>

        <div class="form-row">
            <div class="form-group col-md-6">
                <label for="clone-order">Order by</label>
                <select id="clone-order" name="send_setup[order_by]" class="form-control selectpicker" title="Order by" required>
                    <option value="default" @selected($selectedOrder === 'default')>DEFAULT</option>
                    <option value="asc" @selected($selectedOrder === 'asc')>ASC</option>
                    <option value="desc" @selected($selectedOrder === 'desc')>DESC</option>
                    <option value="random" @selected($selectedOrder === 'random')>RANDOM</option>
                </select>
            </div>
            <div class="form-group col-md-6">
                <label for="clone-start-from">Start from</label>
                <input id="clone-start-from" name="send_setup[start_from]" type="number" min="0" class="form-control" value="{{ $selectedStart }}" required>
            </div>
        </div>

        <p id="clone-status" class="small text-muted" role="status" aria-live="polite">Select the lists for the new campaign.</p>
        <div id="clone-progress" class="mb-3" hidden>
            <div class="progress" role="progressbar" aria-label="Clone suppression and recipient preparation" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <div id="clone-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
            </div>
            <small id="clone-progress-label" class="form-text text-muted"></small>
        </div>
        <div class="d-flex justify-content-end">
            <button type="button" class="btn btn-alt-secondary mr-2" data-campaign-clone-cancel>Cancel</button>
            <button id="clone-submit" class="btn btn-primary" type="submit" @disabled($segments->isEmpty() || count($selectedSegments) === 0)>CLONE CAMPAIGN</button>
        </div>
    </form>
</div>
