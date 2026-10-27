@extends('layouts.dms-admin')

@section('title', 'Clone Campaign')

@section('content')
    @php
        $selectedIsp = (string) old('send_setup.isp_id', $sendSetup['isp_id'] ?? '');
        $selectedCountry = strtoupper((string) old('send_setup.country', $sendSetup['country'] ?? 'ALL'));
        $selectedSegments = array_map('strval', (array) old('send_setup.audience_segments', $selectedSegments));
        $selectedOrder = (string) old('send_setup.order_by', $sendSetup['order_by'] ?? 'default');
        $selectedStart = (string) old('send_setup.start_from', $sendSetup['start_from'] ?? 0);
        $segments = collect($sendOptions['segments'] ?? []);
        $segmentIds = $segments->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $selectedSegments = array_values(array_intersect($selectedSegments, $segmentIds));
    @endphp

    <h2 class="content-heading">CLONE CAMPAIGN</h2>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert" tabindex="-1">
            <p class="mb-1">Review the clone choices below:</p>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="alert alert-info" role="status">
        DMS Clone requires a live, active offer, then builds a fresh suppression result from the chosen ISP, lists, order, and start position. AlphaLink maps DMS lists to its active audience segments and stores a fresh point-in-time recipient snapshot. The result is a draft; the native DMS sender is not available in AlphaLink.
    </div>

    <form method="POST" action="{{ route('console.campaigns.clone.store', [$workspace->id, $source->id]) }}" id="campaign-clone-form" data-options-url="{{ $sendOptionsUrl }}">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
        <input type="hidden" name="source_revision_id" value="{{ $sourceRevision->id }}">

        <div class="block block-rounded">
            <div class="block-header">
                <h3 class="block-title">Source campaign</h3>
                <div class="block-options"><a href="{{ route('console.campaigns.show', [$workspace->id, $source->id]) }}">{{ $source->name }}</a></div>
            </div>
            <div class="block-content block-content-full">
                <div class="form-group">
                    <label for="clone-name">New campaign name</label>
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
                    <label for="clone-segments">Lists / audience segments</label>
                    <select id="clone-segments" name="send_setup[audience_segments][]" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="8" data-selected-text-format="count > 3" title="Select audience segments" multiple required>
                        @foreach ($segments as $segment)
                            @php($segmentId = (string) $segment['id'])
                            <option value="{{ $segmentId }}" data-recipient-count="{{ $segment['recipient_count'] }}" @selected(in_array($segmentId, $selectedSegments, true))>{{ $segment['name'] }} · {{ strtoupper($segment['email_type']) }} · {{ number_format($segment['recipient_count']) }}</option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">Selected membership count before suppression and duplicate removal: <strong id="clone-recipient-estimate">0</strong></small>
                    @if ($segments->isEmpty())
                        <small class="form-text text-warning">No eligible segments are available for this ISP and country. Check active, unlocked lists with current members.</small>
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

                <p id="clone-status" class="small text-muted" role="status">Choose the ISP, country, and audience segments for the new draft.</p>
                <button id="clone-submit" class="btn btn-info btn-block" type="submit" @disabled($segments->isEmpty())>CLONE AS DRAFT</button>
            </div>
        </div>
    </form>

    <p><a href="{{ route('console.campaigns.show', [$workspace->id, $source->id]) }}">Back to source campaign</a></p>
@endsection

@section('js_after')
    <script>
        (() => {
            const form = document.getElementById('campaign-clone-form');
            const isp = document.getElementById('clone-isp');
            const country = document.getElementById('clone-country');
            const segments = document.getElementById('clone-segments');
            const estimate = document.getElementById('clone-recipient-estimate');
            const status = document.getElementById('clone-status');
            const submit = document.getElementById('clone-submit');
            if (!form || !isp || !country || !segments || !estimate || !status || !submit) return;

            const refreshPicker = (element) => {
                if (window.jQuery && window.jQuery.fn.selectpicker) window.jQuery(element).selectpicker('refresh');
            };

            const updateEstimate = () => {
                const total = Array.from(segments.selectedOptions).reduce((sum, option) => sum + Number(option.dataset.recipientCount || 0), 0);
                estimate.textContent = new Intl.NumberFormat().format(total);
                submit.disabled = segments.selectedOptions.length === 0;
            };

            const refreshCountries = () => {
                const selected = isp.selectedOptions[0];
                let countries = [];
                try {
                    countries = JSON.parse(selected?.dataset.countries || '[]');
                } catch (error) {
                    countries = [];
                }
                const previous = country.value;
                country.replaceChildren(new Option('ALL', 'ALL'));
                for (const code of countries) country.add(new Option(String(code).toUpperCase(), String(code).toUpperCase()));
                country.value = countries.map((code) => String(code).toUpperCase()).includes(previous) ? previous : 'ALL';
                refreshPicker(country);
            };

            const loadSegments = async () => {
                const params = new URLSearchParams({
                    sponsor_id: @json((string) $sendSetup['sponsor_id']),
                    offer_country: @json((string) $sendSetup['offer_country']),
                    isp_id: isp.value,
                    country: country.value,
                    route: @json((string) $sendSetup['route_option']),
                    ip_type: @json((string) $sendSetup['ip_type']),
                });
                @foreach (($sendSetup['server_ids'] ?? []) as $serverId)
                    params.append('server_ids[]', @json((string) $serverId));
                @endforeach
                submit.disabled = true;
                status.textContent = 'Loading eligible list segments…';
                segments.replaceChildren();
                refreshPicker(segments);
                try {
                    const response = await fetch(`${form.dataset.optionsUrl}?${params.toString()}`, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    if (!response.ok) throw new Error(`Request failed with status ${response.status}`);
                    const payload = await response.json();
                    for (const segment of payload.data.segments || []) {
                        const label = `${segment.name} · ${segment.email_type.toUpperCase()} · ${new Intl.NumberFormat().format(segment.recipient_count)}`;
                        const option = new Option(label, String(segment.id));
                        option.dataset.recipientCount = String(segment.recipient_count);
                        segments.add(option);
                    }
                    refreshPicker(segments);
                    status.textContent = segments.options.length
                        ? 'Select one or more eligible segments. The server rechecks every selection and rebuilds the suppression snapshot when you clone.'
                        : 'No eligible segments are available for this ISP and country.';
                } catch (error) {
                    status.textContent = 'List segments could not be refreshed. Reload the page and try again.';
                }
                updateEstimate();
            };

            isp.addEventListener('change', () => {
                refreshCountries();
                loadSegments();
            });
            country.addEventListener('change', loadSegments);
            segments.addEventListener('change', updateEstimate);
            updateEstimate();
        })();
    </script>
@endsection
