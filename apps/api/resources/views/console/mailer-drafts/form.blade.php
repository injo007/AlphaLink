@extends('layouts.admin-shell')

@php
    $isDrop = $kind === 'drop';
    $isWarmup = $kind === 'warmup';
    $dropSendTypes = ['delay' => 'DELAY', 'delayp' => 'DELAYP (parallel)', 'hs' => 'HS', 'hsp' => 'HSP (parallel)', 'pickup' => 'PICKUP (parallel)'];
    $warmupSendTypes = ['delay' => 'DELAY', 'delayp' => 'DELAYP (parallel)', 'delayps' => 'DELAYPS (parallel)', 'hs' => 'HS', 'hsp' => 'HSP (parallel)', 'hsps' => 'HSPS (parallel by server)', 'pickup' => 'PICKUP (parallel)', 'pickups' => 'PICKUPS (parallel by server)'];
    $setup = (array) old('send_setup', $content['send_setup'] ?? []);
    $selectedSegments = array_map('strval', old('send_setup.audience_segments', $setup['audience_segments'] ?? []));
    $selectedEmailTypes = array_map('strval', old('send_setup.email_types', $setup['email_types'] ?? []));
    $selectedServers = array_map('strval', old('send_setup.server_ids', $setup['server_ids'] ?? []));
    $selectedIps = array_map('strval', old('send_setup.ip_ids', $setup['ip_ids'] ?? []));
    $selectedEmailTestAddresses = old('email_test_addresses', $content['email_test_addresses'] ?? []);
    if (! is_array($selectedEmailTestAddresses)) {
        $selectedEmailTestAddresses = preg_split('/[\r\n,;]+/', (string) $selectedEmailTestAddresses, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $selectedEmailTestAddresses = array_map(fn ($address): string => trim((string) $address), $selectedEmailTestAddresses);
    $emailTestText = old('email_test_addresses_text', implode("\n", $content['email_test_addresses'] ?? []));
    $placeholderRows = collect($setup['placeholders'] ?? [])->map(fn (array $row): array => [
        'data_text' => implode("\n", $row['data'] ?? []),
        'rotate_every' => $row['rotate_every'] ?? 1,
    ])->all();
    $countryCatalog = function_exists('get_countries_iso2') ? get_countries_iso2() : [];
    $selectedOfferCountry = strtoupper((string) old('send_setup.offer_country', $setup['offer_country'] ?? 'ALL'));
    $selectedCountries = collect(old('countries', $content['countries'] ?? (filled($setup['country'] ?? null) && strtoupper((string) $setup['country']) !== 'ALL' ? [$setup['country']] : [])))
        ->map(fn ($country): string => strtoupper((string) $country))->unique()->values()->all();
    $availableCountryCodes = collect(array_keys($countryCatalog))
        ->merge(collect($sendOptions['offers'])->flatMap(fn (array $offer): array => (array) ($offer['geo_target'] ?? [])))
        ->merge(collect($sendOptions['segments'])->pluck('country'))
        ->merge($selectedCountries)
        ->push($selectedOfferCountry)
        ->filter(fn ($country): bool => filled($country) && strtoupper((string) $country) !== 'ALL')
        ->map(fn ($country): string => strtoupper((string) $country))->unique()->sort()->values();
    $selectedSponsor = old('send_setup.sponsor_id', $setup['sponsor_id'] ?? '');
    $selectedOffer = old('send_setup.offer_id', $setup['offer_id'] ?? '');
    $selectedIsp = old('send_setup.isp_id', $setup['isp_id'] ?? '');
    $selectedRoute = old('send_setup.route_option', $setup['route_option'] ?? 'ip');
    $selectedIpType = old('send_setup.ip_type', $setup['ip_type'] ?? 'all');
    $pmtaPanelTargets = collect($sendOptions['servers'])
        ->mapWithKeys(fn (array $server): array => [(string) $server['id'] => $server['pmta_panel_display_url'] ?? $server['pmta_panel_url'] ?? null])
        ->filter(fn ($url): bool => filled($url))
        ->all();
    $selectedPanelTargets = collect($selectedServers)
        ->map(fn (string $serverId): ?string => $pmtaPanelTargets[$serverId] ?? null)
        ->filter(fn ($url): bool => filled($url))
        ->values();
    $body = old('html_body', $content['html_body'] ?? '');
    $header = old('header', $content['header'] ?? ($isDrop
        ? "MIME-version: 1.0\nContent-type: text/html\nTo: [to]\nfrom: [from] <info@[domain]>\nSubject: [subject]\nDate: [date]"
        : "MIME-version: 1.0\nContent-type: text/html\nTo: [to]\nfrom: [rnd] <info@[domain]>\nSubject: [ip]_[rnda_5]_[domain]\nDate: [date]"));
@endphp

@section('title', $isDrop ? ($draft ? 'Edit Drop' : 'New Drop') : ($draft ? 'Edit Warmup' : 'New Warmup'))

@section('content')
@if ($isWarmup)
<div class="warmup-composer-shell">
@endif
<div class="quick-nav" aria-label="{{ $isDrop ? 'Drop actions' : 'Warmup actions' }}">
    <button class="quick-nav-trigger" type="button" aria-label="Open page actions" aria-expanded="false" aria-controls="mailer-page-actions"><span></span></button>
    <ul id="mailer-page-actions" role="menu" aria-hidden="true">
        @if ($draft)
            <li><a href="#" data-save-draft><span>SAVE {{ $isDrop ? 'DROP' : 'WARMUP' }}</span><i class="fas fa-save"></i></a></li>
        @endif
        @if ($isDrop)
            <li><a href="{{ route('console.drops.drafts.create', $workspace->id) }}"><span>NEW DROP DRAFT</span><i class="fas fa-plus"></i></a></li>
        @else
            <li><a href="{{ route('console.warmups.create', $workspace->id) }}"><span>CREATE WARMUP</span><i class="fas fa-plus"></i></a></li>
        @endif
        @if ($draft)
            <li><a href="#" data-send-action title="Queue this saved {{ $isDrop ? 'Drop' : 'Warmup' }} using the saved recipient selection"><span>{{ $isDrop ? 'SEND DROP' : 'CREATE & SEND WARMUP' }}</span><i class="fas fa-paper-plane"></i></a></li>
        @else
            <li><a href="#" class="text-muted" aria-disabled="true" title="Save this draft before sending"><span>{{ $isDrop ? 'SEND DROP' : 'CREATE & SEND WARMUP' }} — SAVE DRAFT FIRST</span><i class="fas fa-paper-plane"></i></a></li>
        @endif
        @if ($draft)
            <li><a href="#" data-test-action title="Send a PMTA test to the saved Email Test addresses"><span>TEST</span><i class="fas fa-vial"></i></a></li>
        @else
            <li><a href="#" class="text-muted" aria-disabled="true" title="Save this draft before testing"><span>TEST — SAVE DRAFT FIRST</span><i class="fas fa-vial"></i></a></li>
        @endif
        @if ($isDrop)
            <li><a href="#" class="text-muted" aria-disabled="true" title="No verified Drop scheduling workflow is connected"><span>SCHEDULE — UNAVAILABLE</span><i class="fas fa-calendar-alt"></i></a></li>
        @endif
        <li><a href="{{ route('clean.console.pmta-admin.index', ['server_ids' => $selectedServers]) }}" data-pmta-admin-link target="_blank" rel="noopener noreferrer"><span>PMTA ADMIN</span><i class="fas fa-grip-horizontal"></i></a></li>
        <li><a href="{{ $selectedPanelTargets->first() ?? '#' }}" data-pmta-panel-link data-pmta-panel-targets='@json($pmtaPanelTargets)' data-selected-panel-urls='@json($selectedPanelTargets)' target="_blank" rel="noopener noreferrer"><span>PMTA PANEL</span><i class="fas fa-server"></i></a></li>
        <li><a href="#" id="clear-mailer-params"><span>CLEAR PARAMS</span><i class="fas fa-trash"></i></a></li>
        <li><a href="{{ $indexUrl }}"><span>BACK TO {{ $isDrop ? 'DROP DRAFTS' : 'MY WARMUPS' }}</span><i class="fas fa-arrow-left"></i></a></li>
    </ul>
    <span class="quick-nav-bg"></span>
</div>
<div class="quick-nav-overlay"></div>
<ul id="mailer-draft-context-menu" class="mailer-draft-context-menu" role="menu" aria-label="{{ $isDrop ? 'Drop' : 'Warmup' }} page actions" hidden></ul>
@if ($draft)
    <form id="mailer-draft-test-action" method="post" action="{{ route('console.campaigns.auto-test', [$workspace->getKey(), $draft->getKey()]) }}" hidden>
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        <input type="hidden" name="send_type" value="{{ $isWarmup ? ($content['send_type'] ?? $setup['send_type'] ?? 'delay') : ($setup['send_type'] ?? $content['send_type'] ?? 'delay') }}">
    </form>
    <form id="mailer-draft-send-action" method="post" action="{{ $sendUrl }}" hidden>
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
    </form>
@endif

<section class="block block-rounded {{ $isWarmup ? 'warmup-composer-page' : '' }}" id="mailer-composer" data-options-url="{{ $optionsUrl }}" data-kind="{{ $kind }}">
    <div class="block-header {{ $isWarmup ? 'warmup-composer-header' : '' }}">
        @if ($isWarmup)
            <div class="warmup-composer-heading">
                <span class="warmup-composer-heading-icon" aria-hidden="true"><i class="fas fa-fire"></i></span>
                <div>
                    <span class="warmup-composer-eyebrow">{{ $draft ? 'EDIT WARMUP' : 'NEW WARMUP' }}</span>
                    <h1>{{ $draft ? 'Edit Warmup' : 'New Warmup' }}</h1>
                    <p>Configure your message, seed audience, and send pacing.</p>
                </div>
            </div>
        @else
            <h3 class="block-title">DROP : {{ $draft?->getKey() ?? 'NEW' }} - TYPE: {{ strtoupper($setup['send_type'] ?? 'DRAFT') }} - PROCESSED: NO</h3>
        @endif
        <div class="block-options d-flex align-items-center" style="gap:.5rem">
            <a class="btn btn-sm btn-alt-secondary {{ $isWarmup ? 'warmup-composer-back' : '' }}" href="{{ $indexUrl }}" aria-label="{{ $isDrop ? 'Back to Drop Drafts' : 'Back to My Warmups' }}">
                <i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>{{ $isDrop ? 'DROP DRAFTS' : 'MY WARMUPS' }}
            </a>
            <span class="badge badge-secondary {{ $isWarmup ? 'warmup-composer-draft-badge' : '' }}">DRAFT</span>
            <button type="button" class="btn-block-option {{ $isWarmup ? 'warmup-composer-fullscreen' : '' }}" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle fullscreen"><i class="si si-size-fullscreen" aria-hidden="true"></i></button>
        </div>
    </div>
    <div class="block-content block-content-full">
        <p class="small text-muted mb-3 {{ $isWarmup ? 'warmup-composer-note' : '' }}" role="note">AlphaLink draft · changes are saved as a revision. Save the latest revision before sending a test to the saved Email Test addresses.</p>
        @if (session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert"><strong>Review these fields:</strong><ul class="mb-0 mt-2">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @if ($isWarmup)
            <nav class="warmup-composer-section-nav" aria-label="Jump to warmup section">
                <a href="#warmup-test-setup"><span class="warmup-composer-step-number">01</span><span class="warmup-composer-step-copy"><strong>Test setup</strong><small>Return path and recipients</small></span><i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                <a href="#warmup-message-content"><span class="warmup-composer-step-number">02</span><span class="warmup-composer-step-copy"><strong>Message content</strong><small>Headers, body, placeholders</small></span><i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                <a href="#warmup-sending-pacing"><span class="warmup-composer-step-number">03</span><span class="warmup-composer-step-copy"><strong>Sending &amp; pacing</strong><small>Servers, seeds, rate limits</small></span><i class="fas fa-arrow-right" aria-hidden="true"></i></a>
            </nav>
        @endif

        <form id="mailer-draft-form" method="post" action="{{ $draft ? $updateUrl : $storeUrl }}">
            @csrf
            @if ($draft)
                @method('put')
                <input type="hidden" name="source_revision_id" value="{{ $draft->latestRevision?->id }}">
            @endif
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            @include('console.mailer-drafts.partials.tags')
            @if ($isDrop)
                <input type="hidden" id="mailer-draft-name" name="name" value="{{ old('name', $draft?->name ?? 'New Drop') }}">
                <div class="form-row">
                    <div class="form-group col-sm-12 col-md-4"><label for="drop-sponsor">Sponsors:</label><select id="drop-sponsor" name="send_setup[sponsor_id]" class="form-control selectpicker" data-live-search="true"><option value="">Select Sponsor</option>@foreach ($sendOptions['sponsors'] as $sponsor)<option value="{{ $sponsor['id'] }}" @selected((string) $selectedSponsor === (string) $sponsor['id'])>{{ $sponsor['name'] }}</option>@endforeach</select></div>
                    <div class="form-group col-sm-12 col-md-2"><label for="drop-offer-country">Offers Country:</label><select id="drop-offer-country" name="send_setup[offer_country]" class="form-control selectpicker"><option value="ALL" @selected($selectedOfferCountry === 'ALL')>ALL</option>@foreach ($availableCountryCodes as $code)<option value="{{ $code }}" @selected($selectedOfferCountry === $code)>{{ $code }}{{ isset($countryCatalog[$code]) ? ' — '.$countryCatalog[$code] : '' }}</option>@endforeach</select></div>
                    <div class="form-group col-sm-12 col-md-6"><label for="drop-offer">Offers:</label><select id="drop-offer" name="send_setup[offer_id]" class="form-control selectpicker"><option value="">Select Offer</option>@foreach ($sendOptions['offers'] as $offer)<option value="{{ $offer['id'] }}" @selected((string) $selectedOffer === (string) $offer['id'])>({{ $offer['offer_id'] }}) {{ $offer['name'] }} — {{ $offer['vertical'] }}</option>@endforeach</select></div>
                </div>
            @else
                <input type="hidden" id="mailer-draft-name" name="name" value="{{ old('name', $draft?->name ?? 'New Warmup') }}">
            @endif

            @if ($isWarmup)
                <section id="warmup-test-setup" class="warmup-composer-panel warmup-composer-test-panel" aria-labelledby="warmup-test-title">
                    <header class="warmup-composer-panel-heading"><span class="warmup-composer-panel-icon" aria-hidden="true"><i class="fas fa-vial"></i></span><span><strong id="warmup-test-title">Test setup</strong><small>Choose a return path and saved test recipients.</small></span></header>
            @endif
            <div class="form-row {{ $isWarmup ? 'warmup-message-fields' : '' }}">
                <div class="form-group col-sm-12 col-md-4"><label for="return_path">Return Path:</label><input id="return_path" name="return_path" type="text" class="form-control" maxlength="320" placeholder="Return Path" value="{{ old('return_path', $content['return_path'] ?? '[rnd]@[domain]') }}"></div>
                <div class="form-group col-sm-12 col-md-4"><label for="saved-emails">Saved Emails:</label><div class="input-group"><select id="saved-emails" class="form-control selectpicker" multiple data-actions-box="true" data-live-search="true" data-selected-text-format="count > 1" data-size="5" title="Select Emails">@foreach ($savedEmails as $savedEmail)<option value="{{ $savedEmail->email }}" @selected(in_array($savedEmail->email, $selectedEmailTestAddresses, true))>{{ $savedEmail->email }}</option>@endforeach</select><div class="input-group-append"><button type="button" id="add-saved-email" class="btn btn-success" title="Add saved email to test addresses"><i class="fas fa-plus"></i></button></div></div></div>
                <div class="form-group col-sm-12 col-md-4"><label for="email-test-addresses">{{ $isDrop ? 'Emails Test' : 'Email Test' }}:</label><textarea id="email-test-addresses" name="email_test_addresses_text" rows="1" class="form-control" placeholder="Email Test">{{ $emailTestText }}</textarea></div>
            </div>
            @if ($isWarmup)
                </section>
                <section id="warmup-message-content" class="warmup-composer-panel warmup-composer-content-panel" aria-labelledby="warmup-content-title">
                    <header class="warmup-composer-panel-heading"><span class="warmup-composer-panel-icon" aria-hidden="true"><i class="fas fa-envelope-open-text"></i></span><span><strong id="warmup-content-title">Message content</strong><small>Set sender details, headers, body, and placeholders.</small></span></header>
            @endif

            @unless ($isWarmup)<hr>@endunless
            @if ($isDrop)
                <div class="form-row">
                    @include('console.campaigns.partials.header-library', ['workspace' => $workspace, 'textareaId' => 'mailer-header', 'idPrefix' => 'mailer'])
                    <div class="col-md-6"><div class="form-row"><div class="form-group col-sm-12 col-md-6"><label for="mailer-domain">Domains:</label><select id="mailer-domain" name="send_setup[domain]" class="form-control selectpicker" data-allow-clear="true"><option value="">Select Domain</option>@foreach ($sendOptions['campaign_domains'] as $domainOption)<option value="{{ $domainOption['value'] }}" @selected((string) old('send_setup.domain', $setup['domain'] ?? '') === (string) $domainOption['value'])>{{ $domainOption['name'] }}{{ $domainOption['id'] ?? '' }} - {{ $domainOption['dns'] }}</option>@endforeach</select></div><div class="form-group col-sm-12 col-md-6"><label for="mailer-negative">Negatives:</label><select id="mailer-negative" name="send_setup[negative_id]" class="form-control selectpicker" data-allow-clear="true"><option value="">Select Negative</option>@foreach ($sendOptions['negatives'] as $negative)<option value="{{ $negative['id'] }}" @selected((string) ($setup['negative_id'] ?? '') === (string) $negative['id'])>{{ $negative['name'] }}</option>@endforeach</select></div></div></div>
                </div>
            @else
                <div class="form-row">
                    <div class="col-sm-12 col-md-6">
                        @include('console.campaigns.partials.header-library', ['workspace' => $workspace, 'textareaId' => 'mailer-header', 'idPrefix' => 'mailer', 'containerClass' => 'form-group col-md-12'])
                        <label for="mailer-header-editor">Header:
                            <button type="button" class="btn btn-link btn-sm p-0" id="mailer-header-shuffle" title="Shuffle header lines" aria-label="Shuffle header lines"><i class="fas fa-random"></i></button>
                            <button type="button" class="btn btn-link btn-sm p-0 disabled" title="Header encoding is not available on draft pages" aria-label="Header encoding unavailable" aria-disabled="true" tabindex="-1"><i class="fas fa-pencil-ruler"></i></button>
                        </label>
                        <div id="mailer-header-editor" class="mailer-ace-editor" role="textbox" aria-multiline="true" aria-label="Header editor" tabindex="0"></div><textarea id="mailer-header" name="header" class="form-control resize-none" rows="10" spellcheck="false">{{ $header }}</textarea>
                    </div>
                    <div class="col-sm-12 col-md-6">
                        <div class="form-row"><div class="form-group col-sm-12 col-md-6"><label for="mailer-domain">Domain:</label><select id="mailer-domain" name="domain" class="form-control selectpicker" data-allow-clear="true"><option value="">Select Domain</option>@foreach ($sendOptions['campaign_domains'] as $domainOption)<option value="{{ $domainOption['value'] }}" @selected((string) old('domain', $content['domain'] ?? '') === (string) $domainOption['value'])>{{ $domainOption['name'] }}{{ $domainOption['id'] ?? '' }} - {{ $domainOption['dns'] }}</option>@endforeach</select></div><div class="form-group col-sm-12 col-md-6"><label for="mailer-negative">Negative:</label><select id="mailer-negative" name="negative_id" class="form-control selectpicker" data-allow-clear="true"><option value="">Select Negative</option>@foreach ($sendOptions['negatives'] as $negative)<option value="{{ $negative['id'] }}" @selected((string) ($content['send_setup']['negative_id'] ?? '') === (string) $negative['id'])>{{ $negative['name'] }}</option>@endforeach</select></div></div>
                        <div class="form-group"><label for="mailer-body">Body:
                            <button type="button" id="mailer-body-preview-open" class="btn btn-link btn-sm p-0" title="Preview HTML" aria-controls="mailer-body-preview"><i class="fas fa-eye"></i></button>
                        </label><div id="mailer-body-editor" class="mailer-ace-editor" role="textbox" aria-multiline="true" aria-label="HTML body editor" tabindex="0"></div><textarea id="mailer-body" name="html_body" class="form-control resize-none" rows="10" spellcheck="false">{{ $body }}</textarea></div>
                    </div>
                </div>
            @endif

            @if ($isDrop)
                <div class="form-row">
                    <div class="form-group col-md-4"><label for="drop-from">From:</label><select class="form-control selectpicker" id="drop-from" name="send_setup[from_asset_ids][]" multiple data-live-search="true" data-actions-box="true" data-size="5">@foreach (collect($sendOptions['offers'])->firstWhere('id', (int) $selectedOffer)['assets'] ?? [] as $asset)@if ($asset['type'] === 'from')<option value="{{ $asset['id'] }}" @selected(in_array((string) $asset['id'], array_map('strval', $setup['from_asset_ids'] ?? []), true))>{{ $asset['value'] }}</option>@endif @endforeach</select><input type="hidden" name="from" value="{{ old('from', $content['from'] ?? '') }}"></div>
                    <div class="form-group col-md-4"><label for="drop-subject-values">Subjects:</label><select class="form-control selectpicker" id="drop-subject-values" name="send_setup[subject_asset_ids][]" multiple data-live-search="true" data-actions-box="true" data-size="5">@foreach (collect($sendOptions['offers'])->firstWhere('id', (int) $selectedOffer)['assets'] ?? [] as $asset)@if ($asset['type'] === 'subject')<option value="{{ $asset['id'] }}" @selected(in_array((string) $asset['id'], array_map('strval', $setup['subject_asset_ids'] ?? []), true))>{{ $asset['value'] }}</option>@endif @endforeach</select><input type="hidden" name="subject" value="{{ old('subject', $content['subject'] ?? 'Drop') }}"></div>
                    <div class="form-group col-md-4"><label for="drop-creative">Creatives:</label><select class="form-control selectpicker" id="drop-creative" name="send_setup[creative_asset_id]"><option value="">Select Creative</option>@foreach (collect($sendOptions['offers'])->firstWhere('id', (int) $selectedOffer)['assets'] ?? [] as $asset)@if ($asset['type'] === 'creative')<option value="{{ $asset['id'] }}" @selected((string) ($setup['creative_asset_id'] ?? '') === (string) $asset['id'])>{{ $asset['value'] }}</option>@endif @endforeach</select></div>
                </div>
            @endif

            @if ($isDrop)
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="mailer-header-editor">Header:
                        <button type="button" class="btn btn-link btn-sm p-0" id="mailer-header-shuffle" title="Shuffle header lines" aria-label="Shuffle header lines"><i class="fas fa-random"></i></button>
                        <button type="button" class="btn btn-link btn-sm p-0 disabled" id="mailer-header-encode" title="Header encoding is not available on this draft page" aria-label="Header encoding unavailable" aria-disabled="true" tabindex="-1"><i class="fas fa-pencil-ruler"></i></button>
                    </label>
                    <div id="mailer-header-editor" class="mailer-ace-editor" role="textbox" aria-multiline="true" aria-label="Header editor" tabindex="0"></div><textarea id="mailer-header" name="header" class="form-control resize-none" rows="20" spellcheck="false">{{ $header }}</textarea>
                </div>
                <div class="form-group col-md-6">
                    <label for="mailer-body-editor">Body:
                        <button type="button" id="mailer-body-preview-open" class="btn btn-link btn-sm p-0" title="Preview HTML" aria-controls="mailer-body-preview"><i class="fas fa-eye"></i></button>
                    </label>
                    <div class="d-flex align-items-center mb-2"><div class="custom-control custom-switch"><input class="custom-control-input" type="checkbox" id="mailer-map-body" name="map_body" value="1" @checked(old('map_body', $content['map_body'] ?? false))><label class="custom-control-label" for="mailer-map-body">MAP BODY</label></div><button type="button" id="mailer-insert-open-pixel" class="btn btn-link btn-sm p-0 ml-2" title="Insert open tracking pixel" aria-label="Insert open tracking pixel"><i class="fas fa-bullseye"></i></button></div>
                    <div id="mailer-body-editor" class="mailer-ace-editor" role="textbox" aria-multiline="true" aria-label="HTML body editor" tabindex="0"></div><textarea id="mailer-body" name="html_body" class="form-control resize-none" rows="20" spellcheck="false">{{ $body }}</textarea>
                    <small class="form-text text-muted">HTML is saved without rewriting domain tags or external URLs. MAP BODY is stored as a draft preference.</small>
                </div>
            </div>
            @endif
            <dialog id="mailer-body-preview" class="mailer-preview-dialog" aria-labelledby="mailer-preview-title"><div class="mailer-preview-header"><h4 id="mailer-preview-title" class="h5 mb-0">HTML Preview</h4><button type="button" class="btn btn-sm btn-alt-secondary" id="mailer-body-preview-close" aria-label="Close preview"><i class="fas fa-times"></i></button></div><iframe id="mailer-body-preview-frame" title="HTML body preview" sandbox=""></iframe></dialog>

            <div class="form-row">
                @include('console.campaigns.partials.placeholder-editor', [
                    'idPrefix' => 'mailer',
                    'namePrefix' => $isDrop ? 'send_setup[placeholder_inputs]' : 'placeholder_inputs',
                    'combinationName' => $isDrop ? 'send_setup[placeholder_combination]' : 'placeholder_combination',
                    'allCombinationName' => $isDrop ? 'send_setup[placeholder_all_combination]' : 'placeholder_all_combination',
                    'rows' => old($isDrop ? 'send_setup.placeholder_inputs' : 'placeholder_inputs', $placeholderRows),
                    'combination' => old($isDrop ? 'send_setup.placeholder_combination' : 'placeholder_combination', $setup['placeholder_combination'] ?? false),
                    'allCombination' => old($isDrop ? 'send_setup.placeholder_all_combination' : 'placeholder_all_combination', $setup['placeholder_all_combination'] ?? false),
                ])
            </div>
            @if ($isWarmup)
                </section>
                <section id="warmup-sending-pacing" class="warmup-composer-panel warmup-composer-delivery-panel" aria-labelledby="warmup-delivery-title">
                    <header class="warmup-composer-panel-heading"><span class="warmup-composer-panel-icon" aria-hidden="true"><i class="fas fa-route"></i></span><span><strong id="warmup-delivery-title">Sending &amp; pacing</strong><small>Choose the route, seed source, delivery mode, and rate limits.</small></span></header>
            @endif

            @if ($isDrop)
                <div class="form-row align-items-start">
                    <div class="col-sm-12 col-md-7">
                        <div class="form-row"><div class="form-group col-sm-2"><label for="send-route">Option:</label><select id="send-route" name="send_setup[route_option]" class="form-control selectpicker"><option value="ip" @selected($selectedRoute === 'ip')>IP</option><option value="vmta" @selected($selectedRoute === 'vmta')>VMTA</option><option value="smtp" @selected($selectedRoute === 'smtp')>SMTP</option></select></div><div class="form-group col-sm-2"><label for="send-ip-type">Type IP:</label><select id="send-ip-type" name="send_setup[ip_type]" class="form-control selectpicker"><option value="all" @selected($selectedIpType === 'all')>ALL</option><option value="ipv4" @selected($selectedIpType === 'ipv4')>IPV4</option><option value="ipv6" @selected($selectedIpType === 'ipv6')>IPV6</option></select></div><div class="form-group col-sm-8"><label for="send-servers">Servers: <button type="button" id="reload-send-options" class="btn btn-link btn-sm p-0" title="Reload servers" aria-label="Reload servers"><i class="fas fa-sync"></i></button></label><select id="send-servers" name="send_setup[server_ids][]" class="form-control selectpicker" multiple data-live-search="true" data-actions-box="true" data-selected-text-format="count > 5" data-size="6">@foreach ($sendOptions['servers'] as $server)<option value="{{ $server['id'] }}" @selected(in_array((string) $server['id'], $selectedServers, true))>{{ $server['name'] }}{{ $server['id'] }} | {{ $server['ip'] }}</option>@endforeach</select></div></div>
                        @include('console.mailer-drafts.partials.send-ips', ['inputName' => 'send_setup[ip_ids][]'])
                    </div>
                    <div class="col-sm-12 col-md-5">
                        <div class="form-row"><div class="form-group col-sm-12 col-md-4"><label for="drop-isp">ISPs:</label><select id="drop-isp" name="send_setup[isp_id]" class="form-control selectpicker" data-live-search="true" data-allow-clear="true"><option value="">Select ISP</option>@foreach ($sendOptions['isps'] as $isp)<option value="{{ $isp['id'] }}" @selected((string) $selectedIsp === (string) $isp['id'])>{{ $isp['name'] }}</option>@endforeach</select></div><div class="form-group col-sm-12 col-md-4"><label for="drop-country">Countries:</label><select id="drop-country" name="countries[]" class="form-control selectpicker" multiple data-live-search="true" data-actions-box="true" data-selected-text-format="count > 5" data-size="6" title="Select Countries">@foreach ($availableCountryCodes as $code)<option value="{{ $code }}" @selected(in_array($code, $selectedCountries, true))>{{ $code }}</option>@endforeach</select></div><div class="form-group col-sm-12 col-md-4"><label for="drop-filter">Filters:</label><select id="drop-filter" name="send_setup[email_types][]" class="form-control selectpicker" multiple data-live-search="true" data-actions-box="true" data-selected-text-format="count > 3" data-size="6" title="Select Filters">@foreach (\App\Models\ContactListMembership::CAMPAIGN_TYPES as $emailType)<option value="{{ $emailType }}" @selected(in_array($emailType, $selectedEmailTypes, true))>{{ strtoupper($emailType) }}</option>@endforeach</select></div></div>
                        <div class="form-group"><div id="drop-list-box" class="block block-bordered" style="height:400px"><div class="block-header"><span>Lists: <span id="drop-list-selection-count" class="text-primary">{{ count($selectedSegments) }} List(s) Selected</span></span><span><button type="button" class="btn btn-link btn-sm p-0" id="refresh-drop-lists" title="Refresh lists" aria-label="Refresh lists"><i class="fas fa-sync-alt"></i></button><button type="button" class="btn btn-link btn-sm p-0 ml-1" id="select-drop-lists" title="Select all lists" aria-label="Select all lists"><i class="fas fa-check"></i></button><button type="button" class="btn btn-link btn-sm p-0 ml-1" id="clear-drop-lists" title="Deselect all lists" aria-label="Deselect all lists"><i class="fas fa-times"></i></button></span></div><div class="block-content pt-2" style="height:350px;overflow:auto"><div id="drop-segments" class="custom-control custom-checkbox">@foreach ($sendOptions['segments'] as $segment)<div class="custom-control custom-checkbox mb-1"><input id="drop-segment-{{ \Illuminate\Support\Str::slug($segment['id']) }}" class="custom-control-input drop-segment-option" type="checkbox" name="send_setup[audience_segments][]" value="{{ $segment['id'] }}" data-country="{{ strtoupper($segment['country']) }}" data-recipient-count="{{ $segment['recipient_count'] }}" @checked(in_array((string) $segment['id'], $selectedSegments, true))><label class="custom-control-label" for="drop-segment-{{ \Illuminate\Support\Str::slug($segment['id']) }}">{{ $segment['name'] }} — {{ strtoupper($segment['email_type']) }} — {{ $segment['country'] }} · {{ number_format($segment['recipient_count']) }}</label></div>@endforeach</div></div></div></div>
                        <div class="form-row"><div class="form-group col-sm-12 col-md-8"><label for="drop-send-type">Send Mode:</label><select id="drop-send-type" name="send_setup[send_type]" class="form-control">@foreach ($dropSendTypes as $type => $label)<option value="{{ $type }}" @selected(($setup['send_type'] ?? 'delay') === $type)>{{ $label }}</option>@endforeach</select></div><div class="form-group col-sm-12 col-md-4"><label for="drop-duplicate-data">Duplicate Data:</label><input id="drop-duplicate-data" name="send_setup[duplicate_data]" type="number" min="1" max="1000" class="form-control" value="{{ old('send_setup.duplicate_data', $setup['duplicate_data'] ?? 1) }}"></div></div>
                        <div class="form-row">
                            @foreach ([['start_from', 'Start', 0], ['total_send', 'Count', 0], ['test_after', 'Test After', 1000], ['batch', 'Batch', 1000], ['delay', 'Delay (MS)', 1000], ['bounce_limit', 'Bounce Limit', 1000]] as [$field, $label, $default])<div class="form-group col-sm-12 col-md-4"><label for="drop-{{ $field }}">{{ $label }}:</label><input id="drop-{{ $field }}" name="send_setup[{{ $field }}]" type="number" min="0" max="2147483647" class="form-control" value="{{ old('send_setup.'.$field, $setup[$field] ?? $default) }}"></div>@endforeach
                        </div>
                    </div>
                </div>
            @else
                <div class="form-row align-items-start">
                    <div class="col-sm-12 col-md-8">
                        <div class="form-row"><div class="form-group col-sm-2"><label for="send-route">Option:</label><select id="send-route" name="route_option" class="form-control selectpicker"><option value="ip" @selected($selectedRoute === 'ip')>IP</option><option value="vmta" @selected($selectedRoute === 'vmta')>VMTA</option><option value="smtp" @selected($selectedRoute === 'smtp')>SMTP</option></select></div><div class="form-group col-sm-2"><label for="send-ip-type">Type IP:</label><select id="send-ip-type" name="ip_type" class="form-control selectpicker"><option value="all" @selected($selectedIpType === 'all')>ALL</option><option value="ipv4" @selected($selectedIpType === 'ipv4')>IPV4</option><option value="ipv6" @selected($selectedIpType === 'ipv6')>IPV6</option></select></div><div class="form-group col-sm-8"><label for="send-servers">Servers: <button type="button" id="reload-send-options" class="btn btn-link btn-sm p-0" title="Reload servers" aria-label="Reload servers"><i class="fas fa-sync"></i></button></label><select id="send-servers" name="server_ids[]" class="form-control selectpicker" multiple data-live-search="true" data-actions-box="true" data-selected-text-format="count > 5" data-size="6">@foreach ($sendOptions['servers'] as $server)<option value="{{ $server['id'] }}" @selected(in_array((string) $server['id'], $selectedServers, true))>{{ $server['name'] }}{{ $server['id'] }} | {{ $server['ip'] }}</option>@endforeach</select></div></div>
                        @include('console.mailer-drafts.partials.send-ips', ['inputName' => 'ip_ids[]'])
                    </div>
                    <div class="col-sm-12 col-md-4">
                        <div class="form-group"><label for="warmup-seeds">Seeds: <span id="warmup-seed-total">{{ number_format($seedLists->whereIn('id', old('seed_list_ids', $content['seed_list_ids'] ?? []))->sum('emails_count')) }}</span></label><select id="warmup-seeds" name="seed_list_ids[]" class="form-control selectpicker" multiple data-live-search="true" data-actions-box="true" data-selected-text-format="count > 5" data-size="6">@foreach ($seedLists as $seedList)<option value="{{ $seedList->id }}" data-count="{{ $seedList->emails_count }}" @selected(in_array((int) $seedList->id, array_map('intval', old('seed_list_ids', $content['seed_list_ids'] ?? [])), true))>ID: {{ $seedList->id }}_{{ $seedList->name }} | TOTAL: {{ number_format($seedList->emails_count) }}</option>@endforeach</select><small class="form-text text-muted"><a href="{{ $seedListsUrl }}">Manage seed lists</a></small></div>
                        <div class="form-group"><label for="warmup-seed-accounts" class="sr-only">Seed accounts</label><textarea id="warmup-seed-accounts" name="seed_accounts_text" rows="10" class="form-control resize-none" placeholder="Seed accounts">{{ old('seed_accounts_text', $content['seed_accounts_text'] ?? '') }}</textarea></div>
                        @if ($draft)<div class="form-group"><label for="warmup-start-from">Start From:</label><input id="warmup-start-from" name="start_from" type="number" min="0" class="form-control" value="{{ old('start_from', $content['start_from'] ?? 0) }}"></div>@endif
                        <div class="form-group"><label for="warmup-send-type">Send Type:</label><select id="warmup-send-type" name="send_type" class="form-control selectpicker">@foreach ($warmupSendTypes as $type => $label)<option value="{{ $type }}" @selected(old('send_type', $content['send_type'] ?? 'delay') === $type)>{{ $label }}</option>@endforeach</select></div>
                        <div class="form-row">
                            <div class="col-sm-12 col-md-6">@foreach ([['total_send', 'Total Send', 1], ['sleep_email', 'Sleep Btw Email (MS)', 0], ['seed_every', 'Test After', 0]] as [$field, $label, $default])<div class="form-group"><label for="warmup-{{ $field }}">{{ $label }}:</label><input id="warmup-{{ $field }}" name="{{ $field }}" type="number" min="0" max="2147483647" class="form-control" value="{{ old($field, $content[$field] ?? $default) }}"></div>@endforeach</div>
                            <div class="col-sm-12 col-md-6">@foreach ([['fraction_ip', 'Fraction IP', 1], ['sleep_ip', 'Sleep Btw IP (MS)', 0], ['bounce_limit', 'Bounce Limit', 1000]] as [$field, $label, $default])<div class="form-group"><label for="warmup-{{ $field }}">{{ $label }}:</label><input id="warmup-{{ $field }}" name="{{ $field }}" type="number" min="0" max="2147483647" class="form-control" value="{{ old($field, $content[$field] ?? $default) }}"></div>@endforeach</div>
                        </div>
                    </div>
                </div>
            @endif

            @if ($isWarmup)
                </section>
            @endif
            <div class="d-flex flex-wrap align-items-center mt-2 {{ $isWarmup ? 'warmup-composer-actions' : '' }}" style="gap:.75rem">
                <button class="btn btn-primary {{ $isWarmup ? 'warmup-composer-save' : '' }}" id="save-mailer-draft" type="submit">{{ $draft ? 'SAVE NEW REVISION' : ($isDrop ? 'SAVE DROP DRAFT' : 'CREATE WARMUP') }}</button>
                <a class="btn btn-alt-secondary {{ $isWarmup ? 'warmup-composer-cancel' : '' }}" href="{{ $indexUrl }}">CANCEL</a>
                <span class="text-muted small">Save draft</span>
            </div>
        </form>
    </div>
</section>
@if ($isWarmup)
</div>
@endif
@endsection

@push('styles')
<style>
    .mailer-ace-editor { border: 1px solid #d1d8e0; display: none; width: 100%; }
    .mailer-preview-dialog { border: 0; border-radius: .25rem; box-shadow: 0 .5rem 2rem rgba(0,0,0,.28); height: min(85vh, 800px); max-height: 90vh; max-width: min(1100px, calc(100vw - 2rem)); padding: 0; width: 100%; }
    .mailer-preview-dialog::backdrop { background: rgba(0,0,0,.58); }
    .mailer-preview-header { align-items: center; border-bottom: 1px solid #e4e7ed; display: flex; justify-content: space-between; padding: .75rem 1rem; }
    .mailer-preview-dialog iframe { border: 0; height: calc(100% - 3.5rem); width: 100%; }
    .mailer-tags-dialog { border: 0; border-radius: .25rem; box-shadow: 0 .5rem 2rem rgba(0,0,0,.28); max-height: 90vh; max-width: min(1140px, calc(100vw - 2rem)); overflow: auto; padding: 0; width: min(1140px, calc(100vw - 2rem)); }
    .mailer-tags-dialog::backdrop { background: rgba(0,0,0,.58); }
    .mailer-ip-selector select { min-height: 13rem; }
    .mailer-ip-selector-actions { align-items: center; display: flex; flex-direction: column; gap: .35rem; justify-content: center; padding-bottom: .35rem; }
    .mailer-ip-selector-actions .btn { min-width: 2.7rem; }
    .warmup-composer-shell { --warmup-ink: var(--co-ink); --warmup-muted: var(--co-muted); --warmup-border: var(--co-border); --warmup-purple: var(--co-purple); --warmup-purple-bright: var(--co-purple-bright); color: var(--warmup-ink); min-width: 0; }
    .warmup-composer-shell .quick-nav-trigger { align-items: center; background: linear-gradient(145deg, #b64eff, #7028f4 72%); border: 1px solid rgb(255 255 255 / 86%); border-radius: 15px; box-shadow: 0 8px 22px rgb(104 44 225 / 30%), inset 0 1px 0 rgb(255 255 255 / 40%); color: #fff; display: grid; place-items: center; }
    .warmup-composer-shell #mailer-page-actions { background: linear-gradient(145deg, var(--co-surface), var(--co-surface-muted)); border: 1px solid var(--co-border); border-radius: 16px; box-shadow: 0 18px 48px color-mix(in srgb, var(--co-ink) 19%, transparent); color: var(--co-ink); }
    .warmup-composer-shell #mailer-page-actions a { align-items: center; border-radius: 10px; color: var(--co-ink); display: flex; font-size: 10px; font-weight: 760; gap: 10px; min-height: 38px; padding: 6px 9px; text-decoration: none; }
    .warmup-composer-shell #mailer-page-actions a:hover, .warmup-composer-shell #mailer-page-actions a:focus-visible { background: color-mix(in srgb, var(--co-purple) 10%, var(--co-surface)); color: var(--co-purple); outline: 0; }
    .warmup-composer-shell #mailer-page-actions a i { color: var(--co-purple); }
    .warmup-composer-page { background: var(--co-surface); border: 1px solid var(--co-border); border-radius: 17px; box-shadow: var(--co-shadow); color: var(--warmup-ink); margin-bottom: 20px; overflow: visible; }
    .warmup-composer-page > .warmup-composer-header { align-items: center; background: linear-gradient(105deg, color-mix(in srgb, var(--co-purple) 9%, var(--co-surface)), var(--co-surface) 52%, color-mix(in srgb, var(--co-blue) 8%, var(--co-surface))); border-bottom: 1px solid var(--co-border); border-radius: 16px 16px 0 0; gap: 16px; min-height: 106px; overflow: hidden; padding: 17px 20px; position: relative; }
    .warmup-composer-header::after { background: radial-gradient(ellipse at 80% 10%, color-mix(in srgb, var(--co-purple-bright) 16%, transparent), transparent 45%); content: ""; inset: 0; pointer-events: none; position: absolute; }
    .warmup-composer-heading { align-items: center; display: flex; gap: 13px; min-width: 0; position: relative; z-index: 1; }
    .warmup-composer-heading-icon { align-items: center; background: linear-gradient(145deg, var(--co-purple-bright), var(--co-purple)); border: 1px solid color-mix(in srgb, var(--co-purple) 25%, var(--co-border)); border-radius: 14px; box-shadow: 0 7px 18px color-mix(in srgb, var(--co-purple) 25%, transparent); color: #fff; display: grid; flex: 0 0 46px; font-size: 18px; height: 46px; justify-items: center; width: 46px; }
    .warmup-composer-eyebrow { color: var(--co-purple); display: block; font-size: 9px; font-weight: 820; letter-spacing: .14em; }
    .warmup-composer-heading h1 { color: var(--co-ink); font-size: clamp(20px, 2vw, 25px); font-weight: 800; letter-spacing: -.04em; line-height: 1.15; margin: 2px 0 3px; }
    .warmup-composer-heading p { color: var(--co-muted); font-size: 10px; margin: 0; }
    .warmup-composer-page .block-options { display: flex !important; flex: 0 0 auto; flex-wrap: nowrap; gap: 8px !important; justify-content: flex-end; margin: 0; position: relative; z-index: 1; }
    .warmup-composer-page .warmup-composer-back, .warmup-composer-page .warmup-composer-cancel { align-items: center; border: 1px solid var(--co-border-strong); border-radius: 9px; color: var(--co-ink); display: inline-flex; font-size: 9px; font-weight: 750; justify-content: center; min-height: 34px; padding: 0 10px; text-decoration: none; }
    .warmup-composer-page .warmup-composer-back:hover, .warmup-composer-page .warmup-composer-cancel:hover { border-color: var(--co-purple); color: var(--co-purple); }
    .warmup-composer-page .warmup-composer-draft-badge { background: color-mix(in srgb, var(--co-purple) 11%, var(--co-surface)); border: 1px solid color-mix(in srgb, var(--co-purple) 19%, var(--co-border)); border-radius: 20px; color: var(--co-purple); font-size: 8px; font-weight: 800; letter-spacing: .06em; padding: 6px 9px; }
    .warmup-composer-page .warmup-composer-fullscreen { align-items: center; background: var(--co-surface); border: 1px solid var(--co-border); border-radius: 9px; color: var(--co-muted); display: grid; height: 34px; justify-items: center; width: 34px; }
    .warmup-composer-page .warmup-composer-fullscreen:hover { border-color: var(--co-purple); color: var(--co-purple); }
    .warmup-composer-page > .block-content { padding: 16px 18px 20px; }
    .warmup-composer-note { align-items: center; background: color-mix(in srgb, var(--co-blue) 5%, var(--co-surface)); border: 1px solid color-mix(in srgb, var(--co-blue) 16%, var(--co-border)); border-radius: 10px; color: var(--co-muted) !important; display: flex; font-size: 10px !important; min-height: 38px; padding: 8px 11px; }
    .warmup-composer-section-nav { display: grid; gap: 8px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin: -3px 0 13px; }
    .warmup-composer-section-nav a { align-items: center; background: linear-gradient(125deg, color-mix(in srgb, var(--co-purple) 5%, var(--co-surface)), var(--co-surface)); border: 1px solid var(--co-border); border-radius: 11px; color: var(--co-ink); display: flex; gap: 9px; min-height: 57px; min-width: 0; padding: 8px 10px; text-decoration: none; transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease; }
    .warmup-composer-section-nav a:hover, .warmup-composer-section-nav a:focus-visible { border-color: color-mix(in srgb, var(--co-purple) 55%, var(--co-border)); box-shadow: 0 4px 13px color-mix(in srgb, var(--co-purple) 10%, transparent); color: var(--co-ink); outline: 0; transform: translateY(-1px); }
    .warmup-composer-step-number { align-items: center; background: color-mix(in srgb, var(--co-purple) 10%, var(--co-surface)); border: 1px solid color-mix(in srgb, var(--co-purple) 19%, var(--co-border)); border-radius: 9px; color: var(--co-purple); display: inline-flex; flex: 0 0 32px; font-size: 9px; font-weight: 850; height: 32px; justify-content: center; letter-spacing: .04em; }
    .warmup-composer-step-copy { display: grid; gap: 2px; min-width: 0; }
    .warmup-composer-step-copy strong { color: var(--co-ink); font-size: 10px; font-weight: 790; line-height: 1.2; }
    .warmup-composer-step-copy small { color: var(--co-muted); font-size: 8px; line-height: 1.25; }
    .warmup-composer-section-nav a > i { color: var(--co-purple); font-size: 9px; margin-left: auto; }
    .warmup-composer-page #warmup-test-setup, .warmup-composer-page #warmup-message-content, .warmup-composer-page #warmup-sending-pacing { scroll-margin-top: 76px; }
    .warmup-composer-panel:target { border-color: color-mix(in srgb, var(--co-purple) 42%, var(--co-border)); box-shadow: 0 0 0 3px color-mix(in srgb, var(--co-purple) 8%, transparent), var(--co-shadow); }
    .warmup-composer-panel { background: linear-gradient(145deg, color-mix(in srgb, var(--co-purple) 3%, var(--co-surface)), var(--co-surface)); border: 1px solid var(--co-border); border-radius: 14px; box-shadow: 0 5px 16px color-mix(in srgb, var(--co-purple) 5%, transparent); margin: 0 0 13px; padding: 13px 14px 7px; }
    .warmup-composer-panel-heading { align-items: center; border-bottom: 1px solid color-mix(in srgb, var(--co-purple) 13%, var(--co-border)); display: flex; gap: 9px; margin: -13px -14px 13px; min-height: 55px; padding: 9px 14px; }
    .warmup-composer-panel-icon { align-items: center; background: color-mix(in srgb, var(--co-purple) 10%, var(--co-surface)); border: 1px solid color-mix(in srgb, var(--co-purple) 15%, var(--co-border)); border-radius: 10px; color: var(--co-purple); display: grid; flex: 0 0 32px; height: 32px; justify-items: center; width: 32px; }
    .warmup-composer-panel-heading > span:last-child { display: grid; gap: 2px; min-width: 0; }
    .warmup-composer-panel-heading strong { color: var(--co-ink); font-size: 12px; font-weight: 800; }
    .warmup-composer-panel-heading small { color: var(--co-muted); font-size: 9px; }
    .warmup-composer-page .form-group { margin-bottom: 9px; min-width: 0; }
    .warmup-composer-page label { color: var(--co-ink); font-size: 10px; font-weight: 720; margin-bottom: 5px; }
    .warmup-composer-page .form-control, .warmup-composer-page .bootstrap-select > .dropdown-toggle { background-color: var(--co-surface); border: 1px solid var(--co-border-strong); border-radius: 8px; color: var(--co-ink); font-size: 11px; min-height: 34px; }
    .warmup-composer-page .form-control:not(textarea):not([multiple]):not(.bootstrap-select) { height: 34px; }
    .warmup-composer-page .form-control:focus, .warmup-composer-page .bootstrap-select > .dropdown-toggle:focus { border-color: var(--co-purple); box-shadow: 0 0 0 3px color-mix(in srgb, var(--co-purple) 12%, transparent); }
    .warmup-composer-page textarea.form-control { line-height: 1.5; min-height: 42px; padding: 8px 10px; resize: none; }
    .warmup-composer-page .bootstrap-select { max-width: 100%; width: 100% !important; }
    .warmup-composer-page .bootstrap-select.form-control { background: transparent !important; border: 0 !important; box-shadow: none !important; height: auto; min-height: 0; padding: 0 !important; }
    .warmup-composer-page .bootstrap-select > .dropdown-toggle { align-items: center !important; display: flex !important; height: 34px !important; justify-content: space-between !important; line-height: 1.2 !important; min-height: 34px !important; min-width: 0; padding: 0 10px !important; text-align: left; width: 100% !important; }
    .warmup-composer-page .bootstrap-select > .dropdown-toggle .filter-option, .warmup-composer-page .bootstrap-select > .dropdown-toggle .filter-option-inner, .warmup-composer-page .bootstrap-select > .dropdown-toggle .filter-option-inner-inner { min-width: 0; }
    .warmup-composer-page .bootstrap-select > .dropdown-toggle .filter-option { align-items: center; display: flex; height: 100%; }
    .warmup-composer-page .bootstrap-select > .dropdown-toggle .filter-option-inner-inner { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .warmup-composer-page .bootstrap-select .dropdown-menu { background: var(--co-surface); border: 1px solid var(--co-border); border-radius: 10px; box-shadow: 0 12px 32px color-mix(in srgb, var(--co-ink) 16%, transparent); color: var(--co-ink); max-width: min(calc(100vw - 20px), 620px); z-index: 1085; }
    .warmup-composer-page .bootstrap-select .dropdown-menu li a { color: var(--co-ink); font-size: 11px; }
    .warmup-composer-page .bootstrap-select .dropdown-menu li a:hover, .warmup-composer-page .bootstrap-select .dropdown-menu li.selected a { background: color-mix(in srgb, var(--co-purple) 10%, var(--co-surface)); color: var(--co-purple); }
    .warmup-composer-page .input-group > .bootstrap-select.form-control { flex: 1 1 0; min-width: 0; }
    .warmup-composer-page .input-group-append .btn { align-items: center; border: 1px solid color-mix(in srgb, #1aa477 35%, var(--co-border)); border-radius: 9px; display: inline-flex; height: 34px; justify-content: center; margin-left: 6px; width: 36px; }
    .warmup-composer-page .campaign-header-library .input-group { align-items: flex-start; display: flex; gap: 6px; }
    .warmup-composer-page .campaign-header-library .input-group > .form-control { flex: 1 1 0; min-width: 0; }
    .warmup-composer-page .campaign-header-library .input-group > .input-group-append { display: flex; flex: 0 0 auto; gap: 5px; margin-left: 0; }
    .warmup-composer-page .campaign-icon-button { align-items: center; background: linear-gradient(145deg, color-mix(in srgb, var(--co-purple) 10%, var(--co-surface)), color-mix(in srgb, var(--co-blue) 8%, var(--co-surface))); border: 1px solid color-mix(in srgb, var(--co-purple) 20%, var(--co-border)); border-radius: 9px; box-shadow: 0 2px 6px color-mix(in srgb, var(--co-ink) 8%, transparent); color: var(--co-purple); display: inline-flex; flex: 0 0 36px; height: 34px; justify-content: center; padding: 0; transition: background .16s ease, border-color .16s ease, box-shadow .16s ease, transform .16s ease; width: 36px; }
    .warmup-composer-page .campaign-icon-button:hover:not(:disabled) { border-color: var(--co-purple); box-shadow: 0 0 0 3px color-mix(in srgb, var(--co-purple) 12%, transparent); color: var(--co-purple); transform: translateY(-1px); }
    .warmup-composer-page .campaign-icon-button.is-save { background: linear-gradient(145deg, #e8fbf5, #ddf6ee); border-color: #b8e8d9; color: #138465; }
    .warmup-composer-page .campaign-icon-button.is-delete { background: linear-gradient(145deg, #fff0f2, #ffe8eb); border-color: #f4c4cd; color: #bd4054; }
    .warmup-composer-page .campaign-icon-button:disabled { box-shadow: none; cursor: not-allowed; opacity: .48; transform: none; }
    .warmup-composer-page #add-saved-email { background: linear-gradient(145deg, #e8fbf5, #ddf6ee); border-color: #b8e8d9; color: #138465; flex: 0 0 36px; margin-left: 0; width: 36px; }
    .warmup-composer-page #add-saved-email:hover { background: linear-gradient(145deg, #dff7ef, #d0f0e5); box-shadow: 0 0 0 3px rgb(25 169 116 / 12%); }
    .warmup-composer-page .campaign-header-library [data-header-status] { color: var(--co-muted); font-size: 9px; line-height: 1.4; }
    html[data-command-theme='dark'] .warmup-composer-page .campaign-icon-button { background: linear-gradient(145deg, #28244b, #202947); border-color: #514477; color: #d8c1ff; }
    html[data-command-theme='dark'] .warmup-composer-page .campaign-icon-button.is-save { background: linear-gradient(145deg, #183d3b, #163333); border-color: #2e6960; color: #83e2c4; }
    html[data-command-theme='dark'] .warmup-composer-page .campaign-icon-button.is-delete { background: linear-gradient(145deg, #482936, #3b2635); border-color: #704454; color: #ff9aaa; }
    html[data-command-theme='dark'] .warmup-composer-page #add-saved-email { background: linear-gradient(145deg, #183d3b, #163333); border-color: #2e6960; color: #83e2c4; }
    .warmup-composer-page .mailer-ace-editor, .warmup-composer-page textarea[name="header"], .warmup-composer-page textarea[name="html_body"] { border: 1px solid var(--co-border); border-radius: 9px; }
    .warmup-composer-page textarea[name="header"], .warmup-composer-page textarea[name="html_body"] { background: color-mix(in srgb, var(--co-surface-muted) 58%, var(--co-surface)); font-family: var(--co-font-mono, ui-monospace, SFMono-Regular, Consolas, monospace); font-size: 10px; min-height: 220px; }
    .warmup-composer-page .mailer-ace-editor { overflow: hidden; }
    .warmup-composer-page .mailer-ip-selector { background: color-mix(in srgb, var(--co-purple) 3%, var(--co-surface)); border: 1px solid var(--co-border); border-radius: 13px; padding: 12px; }
    .warmup-composer-page .mailer-ip-selector select { background: var(--co-surface); border: 1px solid var(--co-border-strong); border-radius: 9px; color: var(--co-ink); min-height: 300px; width: 100%; }
    .warmup-composer-page .mailer-ip-selector-actions .btn { align-items: center; background: var(--co-surface); border: 1px solid var(--co-border); border-radius: 9px; color: var(--co-purple); display: inline-flex; height: 32px; justify-content: center; min-width: 34px; }
    .warmup-composer-page #warmup-seed-accounts { min-height: 220px; }
    .warmup-composer-page .alert[aria-label="Usable message tags"] { align-items: center; background: linear-gradient(105deg, color-mix(in srgb, var(--co-purple) 7%, var(--co-surface)), color-mix(in srgb, var(--co-blue) 5%, var(--co-surface))); border: 1px solid color-mix(in srgb, var(--co-purple) 18%, var(--co-border)); border-radius: 12px; color: var(--co-ink); display: flex; flex-wrap: wrap; gap: 5px 9px; justify-content: flex-start; margin: 0 0 16px; padding: 12px 14px; text-align: left !important; }
    .warmup-composer-page .alert[aria-label="Usable message tags"] strong { color: var(--co-purple); flex: 0 0 auto; font-size: 9px; font-weight: 850; letter-spacing: .06em; }
    .warmup-composer-page .alert[aria-label="Usable message tags"] span { color: var(--co-muted); font-family: var(--co-font-mono, ui-monospace, SFMono-Regular, Consolas, monospace); font-size: 9px; line-height: 1.7; overflow-wrap: anywhere; }
    .warmup-composer-page .alert[aria-label="Usable message tags"] span.d-block { flex: 1 1 100%; }
    .warmup-composer-page .alert[aria-label="Usable message tags"] .btn-link { align-items: center; background: color-mix(in srgb, var(--co-purple) 8%, var(--co-surface)); border: 1px solid color-mix(in srgb, var(--co-purple) 18%, var(--co-border)); border-radius: 8px; color: var(--co-purple); display: inline-flex; height: 27px; justify-content: center; margin-left: auto; padding: 0; width: 27px; }
    .warmup-composer-page .alert[aria-label="Usable message tags"] .btn-link:hover { background: color-mix(in srgb, var(--co-purple) 15%, var(--co-surface)); }
    .warmup-composer-page [data-campaign-placeholder-editor] { background: linear-gradient(145deg, color-mix(in srgb, var(--co-purple) 4%, var(--co-surface)), var(--co-surface)); border: 1px solid var(--co-border); border-radius: 13px; box-shadow: 0 4px 13px color-mix(in srgb, var(--co-purple) 6%, transparent); margin: 0; overflow: hidden; }
    .warmup-composer-page [data-campaign-placeholder-editor] > .block-header { align-items: center; background: linear-gradient(105deg, color-mix(in srgb, var(--co-purple) 8%, var(--co-surface-muted)), color-mix(in srgb, var(--co-blue) 4%, var(--co-surface))); border-bottom: 1px solid var(--co-border); display: flex; flex-wrap: wrap; gap: 10px 14px; justify-content: space-between; min-height: 58px; padding: 9px 12px; }
    .warmup-composer-page [data-campaign-placeholder-editor] .campaign-placeholder-heading { min-width: 180px; }
    .warmup-composer-page [data-campaign-placeholder-editor] .campaign-placeholder-heading .block-title { align-items: center; color: var(--co-ink); display: flex; font-size: 12px; font-weight: 820; letter-spacing: -.01em; margin: 0 0 2px; }
    .warmup-composer-page [data-campaign-placeholder-editor] .campaign-placeholder-heading p { color: var(--co-muted); font-size: 9px; line-height: 1.4; margin: 0; }
    .warmup-composer-page .campaign-placeholder-toolbar { align-items: center; display: flex; flex: 0 1 auto; flex-wrap: wrap; gap: 7px; justify-content: flex-end; margin: 0; }
    .warmup-composer-page .campaign-placeholder-toolbar [data-placeholder-add] { align-items: center; background: linear-gradient(140deg, var(--co-purple), var(--co-purple-bright)); border: 1px solid var(--co-purple); border-radius: 9px; box-shadow: 0 3px 9px color-mix(in srgb, var(--co-purple) 20%, transparent); color: #fff; display: inline-flex; font-size: 10px; font-weight: 750; gap: 7px; justify-content: center; line-height: 1.25; min-height: 36px; padding: 5px 12px; }
    .warmup-composer-page .campaign-placeholder-toolbar [data-placeholder-add]:hover { box-shadow: 0 5px 13px color-mix(in srgb, var(--co-purple) 28%, transparent); transform: translateY(-1px); }
    .warmup-composer-page .campaign-mode-toggle { align-items: center; background: linear-gradient(145deg, var(--co-surface), var(--co-surface-muted)); border: 1px solid var(--co-border); border-radius: 9px; color: var(--co-muted); cursor: pointer; display: inline-flex; flex: 0 1 auto; gap: 7px; margin: 0; min-height: 36px; padding: 6px 9px; position: relative; }
    .warmup-composer-page .campaign-mode-toggle:focus-within { border-color: var(--co-purple); box-shadow: 0 0 0 3px color-mix(in srgb, var(--co-purple) 12%, transparent); }
    .warmup-composer-page .campaign-mode-input { clip-path: inset(50%); height: 1px; margin: 0; overflow: hidden; position: absolute; white-space: nowrap; width: 1px; }
    .warmup-composer-page .campaign-mode-track { background: #b9c1d2; border-radius: 999px; box-shadow: inset 0 1px 2px rgb(28 38 65 / 14%); flex: 0 0 32px; height: 19px; position: relative; transition: background .16s ease; width: 32px; }
    .warmup-composer-page .campaign-mode-track::after { background: #fff; border-radius: 50%; box-shadow: 0 1px 3px rgb(28 38 65 / 24%); content: ''; height: 13px; left: 3px; position: absolute; top: 3px; transition: transform .16s ease; width: 13px; }
    .warmup-composer-page .campaign-mode-input:checked + .campaign-mode-track { background: var(--co-purple); }
    .warmup-composer-page .campaign-mode-input:checked + .campaign-mode-track::after { transform: translateX(13px); }
    .warmup-composer-page .campaign-mode-input:focus-visible + .campaign-mode-track { outline: 2px solid var(--co-purple); outline-offset: 3px; }
    .warmup-composer-page .campaign-mode-label { color: inherit; font-size: 9px; font-weight: 800; line-height: 1.2; text-transform: uppercase; white-space: nowrap; }
    .warmup-composer-page .campaign-placeholder-mode .campaign-field-icon { color: var(--co-purple); flex: 0 0 12px; margin: 0; width: 12px; }
    .warmup-composer-page [data-campaign-placeholder-editor] > .block-content { padding: 11px 12px 12px; }
    .warmup-composer-page [data-campaign-placeholder-editor] .table { border-collapse: separate; border-spacing: 0; margin-bottom: 0; }
    .warmup-composer-page [data-campaign-placeholder-editor] .table thead th { background: var(--co-surface-muted); border-bottom: 1px solid var(--co-border); border-top: 0; color: var(--co-muted); font-size: 9px; font-weight: 800; letter-spacing: .045em; padding: 9px 8px; vertical-align: middle; }
    .warmup-composer-page [data-campaign-placeholder-editor] .table thead th:nth-child(1) { width: 56%; }
    .warmup-composer-page [data-campaign-placeholder-editor] .table thead th:nth-child(2) { width: 29%; }
    .warmup-composer-page [data-campaign-placeholder-editor] .table thead th:nth-child(3) { width: 15%; }
    .warmup-composer-page [data-campaign-placeholder-editor] .table tbody td { background: color-mix(in srgb, var(--co-surface) 90%, var(--co-purple) 10%); border-top: 1px solid color-mix(in srgb, var(--co-border) 78%, transparent); padding: 8px 7px; vertical-align: middle; }
    .warmup-composer-page [data-campaign-placeholder-editor] .table tbody tr:hover td { background: color-mix(in srgb, var(--co-purple) 5%, var(--co-surface)); }
    .warmup-composer-page [data-campaign-placeholder-editor] .table tbody tr:first-child td { border-top: 0; }
    .warmup-composer-page [data-campaign-placeholder-editor] textarea.form-control { min-height: 54px; resize: vertical; }
    .warmup-composer-page [data-campaign-placeholder-editor] .form-control { background: var(--co-surface); border-color: var(--co-border-strong); border-radius: 8px; }
    .warmup-composer-page [data-campaign-placeholder-editor] [data-placeholder-remove] { align-items: center; background: color-mix(in srgb, #e94560 8%, var(--co-surface)); border: 1px solid color-mix(in srgb, #e94560 24%, var(--co-border)); border-radius: 9px; color: #c43d50; display: inline-flex; height: 34px; justify-content: center; transition: background .16s ease, transform .16s ease; width: 36px; }
    .warmup-composer-page [data-campaign-placeholder-editor] [data-placeholder-remove]:hover { background: color-mix(in srgb, #e94560 14%, var(--co-surface)); transform: translateY(-1px); }
    .warmup-composer-page [data-campaign-placeholder-editor] .table-responsive:not(:has(tbody tr)) { display: none; }
    .warmup-composer-page [data-campaign-placeholder-editor] .text-muted { font-size: 9px; line-height: 1.5; }
    html[data-command-theme='dark'] .warmup-composer-page [data-campaign-placeholder-editor] .campaign-mode-toggle { background: linear-gradient(145deg, var(--co-surface-muted), var(--co-surface)); color: var(--co-ink); }
    html[data-command-theme='dark'] .warmup-composer-page [data-campaign-placeholder-editor] .campaign-mode-track { background: #64708d; }
    html[data-command-theme='dark'] .warmup-composer-page [data-campaign-placeholder-editor] .campaign-mode-input:checked + .campaign-mode-track { background: var(--co-purple); }
    html[data-command-theme='dark'] .warmup-composer-page [data-campaign-placeholder-editor] .table tbody td { background: color-mix(in srgb, var(--co-surface) 92%, var(--co-purple) 8%); }
    html[data-command-theme='dark'] .warmup-composer-page [data-campaign-placeholder-editor] .table tbody tr:hover td { background: color-mix(in srgb, var(--co-purple) 12%, var(--co-surface)); }
    .warmup-composer-page .form-text, .warmup-composer-page .text-muted { color: var(--co-muted) !important; font-size: 9px; line-height: 1.45; }
    .warmup-composer-page .form-text a { color: var(--co-purple); font-weight: 750; }
    .warmup-composer-page .warmup-composer-actions { background: linear-gradient(110deg, color-mix(in srgb, var(--co-purple) 5%, var(--co-surface)), color-mix(in srgb, var(--co-blue) 4%, var(--co-surface))); border: 1px solid var(--co-border); border-radius: 12px; margin-top: 4px !important; padding: 11px; }
    .warmup-composer-page .warmup-composer-save { background: linear-gradient(105deg, var(--co-purple-bright), var(--co-purple)); border: 1px solid var(--co-purple); border-radius: 9px; box-shadow: 0 5px 13px color-mix(in srgb, var(--co-purple) 23%, transparent); color: #fff; font-size: 10px; font-weight: 800; min-height: 38px; padding: 0 15px; }
    .warmup-composer-page .warmup-composer-save:hover { color: #fff; filter: brightness(1.05); transform: translateY(-1px); }
    .warmup-composer-page dialog { background: var(--co-surface); border: 1px solid var(--co-border); border-radius: 16px; color: var(--co-ink); box-shadow: 0 24px 70px color-mix(in srgb, var(--co-ink) 25%, transparent); }
    .warmup-composer-page dialog::backdrop { background: rgb(19 13 48 / 54%); backdrop-filter: blur(4px); }
    /* Warmup controls use one consistent surface, including bootstrap-select's generated button. */
    .warmup-composer-page { min-width: 0; width: 100%; }
    .warmup-composer-page > .block-content, .warmup-composer-page form, .warmup-composer-panel, .warmup-composer-panel .form-row, .warmup-composer-panel .form-group { min-width: 0; overflow: visible; }
    .warmup-composer-page .form-group { margin-bottom: 13px; }
    .warmup-composer-page label { font-size: 11px; font-weight: 760; letter-spacing: .005em; margin-bottom: 6px; }
    .warmup-composer-page .form-control,
    .warmup-composer-page .bootstrap-select > .dropdown-toggle {
        background-color: color-mix(in srgb, var(--co-surface-muted) 46%, var(--co-surface));
        border-color: var(--co-border-strong);
        border-radius: 9px;
        box-shadow: none;
        color: var(--co-ink);
        font-size: 12px;
        min-height: 40px;
        transition: background-color .16s ease, border-color .16s ease, box-shadow .16s ease;
    }
    .warmup-composer-page .form-control:not(textarea):not([multiple]):not(.bootstrap-select) { height: 40px; }
    .warmup-composer-page .form-control:hover:not(:disabled):not(:focus),
    .warmup-composer-page .bootstrap-select > .dropdown-toggle:hover { background-color: var(--co-surface); border-color: color-mix(in srgb, var(--co-purple) 34%, var(--co-border-strong)); }
    .warmup-composer-page .form-control:focus,
    .warmup-composer-page .bootstrap-select > .dropdown-toggle:focus,
    .warmup-composer-page .bootstrap-select > .dropdown-toggle:focus-visible {
        background-color: var(--co-surface);
        border-color: var(--co-purple);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--co-purple) 13%, transparent);
        outline: 0;
    }
    .warmup-composer-page .form-control:disabled { background-color: var(--co-surface-muted); color: var(--co-muted); cursor: not-allowed; opacity: .72; }
    .warmup-composer-page select.form-control:not(.selectpicker):not([multiple]) {
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23715be8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
        background-position: right 11px center;
        background-repeat: no-repeat;
        padding-right: 34px;
    }
    .warmup-composer-page .bootstrap-select { position: relative; }
    .warmup-composer-page .bootstrap-select > .dropdown-toggle {
        background-image: none;
        border: 1px solid var(--co-border-strong);
        height: 40px !important;
        min-height: 40px !important;
        padding: 0 11px !important;
    }
    .warmup-composer-page .bootstrap-select > .dropdown-toggle .filter-option { align-items: center; display: flex; }
    .warmup-composer-page .bootstrap-select > .dropdown-toggle .filter-option-inner-inner { line-height: 1.35; }
    .warmup-composer-page .bootstrap-select > .dropdown-toggle .bs-caret { align-items: center; color: var(--co-purple); display: inline-flex; margin-left: 8px; }
    .warmup-composer-page .bootstrap-select.show,
    .warmup-composer-page .bootstrap-select.open { position: relative; z-index: 1120; }
    .warmup-composer-page .bootstrap-select > .dropdown-menu:not(.inner) {
        background: var(--co-surface);
        border: 1px solid var(--co-border);
        border-radius: 12px !important;
        box-shadow: 0 16px 36px color-mix(in srgb, var(--co-ink) 19%, transparent);
        color: var(--co-ink);
        max-width: min(calc(100vw - 24px), 620px);
        min-width: 100%;
        overflow: hidden;
        padding: 6px !important;
        z-index: 1125 !important;
    }
    html[data-command-theme] body.command-os-shell .warmup-composer-page .bootstrap-select > .dropdown-menu:not(.inner) {
        border-radius: 12px !important;
        padding: 6px !important;
        z-index: 1125 !important;
    }
    .warmup-composer-page .bootstrap-select .dropdown-menu.inner {
        background: transparent !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        color: inherit;
        max-width: none;
        animation: none !important;
        margin: 0;
        padding: 0 !important;
        z-index: auto !important;
    }
    html[data-command-theme] body.command-os-shell .warmup-composer-page .bootstrap-select .dropdown-menu.inner {
        animation: none !important;
        background: transparent !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        padding: 0 !important;
        z-index: auto !important;
    }
    .warmup-composer-page .bootstrap-select .dropdown-menu li a {
        align-items: center;
        border-radius: 7px;
        color: var(--co-ink);
        display: flex;
        font-size: 12px;
        line-height: 1.35;
        min-height: 34px;
        padding: 7px 10px;
        white-space: normal;
    }
    .warmup-composer-page .bootstrap-select .dropdown-menu li a:hover,
    .warmup-composer-page .bootstrap-select .dropdown-menu li.selected a,
    .warmup-composer-page .bootstrap-select .dropdown-menu li.active a { background: color-mix(in srgb, var(--co-purple) 9%, var(--co-surface)); color: var(--co-purple); }
    .warmup-composer-page .bootstrap-select .bs-searchbox { padding: 2px 2px 7px; }
    .warmup-composer-page .bootstrap-select .bs-searchbox .form-control { background: var(--co-surface-muted); border: 1px solid var(--co-border); border-radius: 8px; font-size: 12px; min-height: 36px; }
    .warmup-composer-page .bootstrap-select .bs-actionsbox { padding: 2px 2px 7px; }
    .warmup-composer-page .bootstrap-select .bs-actionsbox .btn-group { display: flex; gap: 6px; width: 100%; }
    .warmup-composer-page .bootstrap-select .bs-actionsbox .btn { background: color-mix(in srgb, var(--co-purple) 7%, var(--co-surface)); border: 1px solid color-mix(in srgb, var(--co-purple) 22%, var(--co-border)); border-radius: 7px !important; color: var(--co-purple); flex: 1 1 0; font-size: 10px; font-weight: 760; min-height: 31px; }
    .warmup-composer-page .input-group { align-items: center; gap: 7px; }
    .warmup-composer-page .input-group > .bootstrap-select.form-control { min-height: 0; }
    .warmup-composer-page .campaign-header-library .input-group { gap: 7px; }
    .warmup-composer-page .input-group-append { align-items: center; display: flex; gap: 6px; }
    .warmup-composer-page .input-group-append .btn,
    .warmup-composer-page .campaign-icon-button,
    .warmup-composer-page #add-saved-email { border-radius: 9px; height: 40px; width: 40px; }
    .warmup-composer-page .btn { align-items: center; border-radius: 9px; display: inline-flex; font-size: 11px; font-weight: 760; gap: 7px; justify-content: center; line-height: 1.2; min-height: 38px; transition: background-color .16s ease, border-color .16s ease, box-shadow .16s ease, color .16s ease, transform .16s ease; }
    .warmup-composer-page .btn:focus-visible { box-shadow: 0 0 0 3px color-mix(in srgb, var(--co-purple) 15%, transparent); outline: 0; }
    .warmup-composer-page .btn:not(:disabled):hover { transform: translateY(-1px); }
    .warmup-composer-page .btn:disabled { cursor: not-allowed; opacity: .52; transform: none; }
    .warmup-composer-page .btn-link { align-items: center; background: color-mix(in srgb, var(--co-purple) 7%, var(--co-surface)); border: 1px solid color-mix(in srgb, var(--co-purple) 18%, var(--co-border)); border-radius: 8px; color: var(--co-purple); display: inline-flex; height: 28px; justify-content: center; margin: 0 0 0 4px; min-height: 28px; padding: 0; text-decoration: none; vertical-align: middle; width: 28px; }
    .warmup-composer-page .btn-link:hover { background: color-mix(in srgb, var(--co-purple) 13%, var(--co-surface)); color: var(--co-purple); }
    .warmup-composer-page .campaign-icon-button { box-shadow: 0 2px 7px color-mix(in srgb, var(--co-ink) 8%, transparent); }
    .warmup-composer-page #mailer-header-shuffle,
    .warmup-composer-page #mailer-insert-open-pixel,
    .warmup-composer-page #mailer-body-preview-open,
    .warmup-composer-page #reload-send-options { height: 29px; min-height: 29px; width: 29px; }
    .warmup-composer-page .mailer-ip-selector { background: linear-gradient(145deg, color-mix(in srgb, var(--co-purple) 4%, var(--co-surface)), var(--co-surface)); border-radius: 14px; box-shadow: inset 0 1px 0 rgb(255 255 255 / 65%); padding: 13px; }
    .warmup-composer-page .mailer-ip-selector select { height: 300px; min-height: 300px; padding: 8px; }
    .warmup-composer-page .mailer-ip-selector select option { border-radius: 5px; padding: 5px 6px; }
    .warmup-composer-page .mailer-ip-selector select option:checked { background: color-mix(in srgb, var(--co-purple) 16%, var(--co-surface)); color: var(--co-purple); }
    .warmup-composer-page .mailer-ip-selector-actions { gap: 7px; }
    .warmup-composer-page .mailer-ip-selector-actions .btn { background: var(--co-surface); border-color: var(--co-border); box-shadow: 0 2px 6px color-mix(in srgb, var(--co-ink) 7%, transparent); color: var(--co-purple); height: 36px; min-height: 36px; min-width: 38px; }
    .warmup-composer-page .warmup-composer-save { box-shadow: 0 6px 16px color-mix(in srgb, var(--co-purple) 25%, transparent); font-size: 12px; min-height: 42px; }
    .warmup-composer-page .warmup-composer-cancel { min-height: 40px; }
    .warmup-composer-page dialog { margin: auto; max-height: min(86vh, 760px); }
    .warmup-composer-page .mailer-preview-dialog { border: 1px solid var(--co-border); display: none; height: min(82vh, 760px); max-height: 86vh; max-width: min(1120px, calc(100vw - 28px)); overflow: hidden; width: min(1120px, calc(100vw - 28px)); }
    .warmup-composer-page .mailer-preview-dialog[open] { display: flex; flex-direction: column; }
    .warmup-composer-page .mailer-preview-header { background: linear-gradient(105deg, color-mix(in srgb, var(--co-purple) 7%, var(--co-surface)), var(--co-surface)); border-bottom: 1px solid var(--co-border); flex: 0 0 auto; min-height: 60px; padding: 12px 16px; }
    .warmup-composer-page .mailer-preview-header h4,
    .warmup-composer-page #mailer-tags-title { color: var(--co-ink); font-size: 15px; font-weight: 820; letter-spacing: -.02em; }
    .warmup-composer-page .mailer-preview-header .btn { background: color-mix(in srgb, var(--co-purple) 7%, var(--co-surface)); border: 1px solid var(--co-border); color: var(--co-ink); height: 34px; min-height: 34px; width: 36px; }
    .warmup-composer-page .mailer-preview-dialog iframe { flex: 1 1 auto; height: auto; min-height: 0; }
    .warmup-composer-page .mailer-tags-dialog { max-width: min(1140px, calc(100vw - 28px)); overflow: auto; padding: 0; width: min(1140px, calc(100vw - 28px)); }
    .warmup-composer-page .mailer-tags-dialog > .p-3 { padding: clamp(16px, 3vw, 28px) !important; }
    .warmup-composer-page .mailer-tags-dialog h4 { color: var(--co-ink); font-size: 12px; font-weight: 800; margin: 18px 0 8px; }
    .warmup-composer-page .mailer-tags-dialog p { color: var(--co-muted); font-size: 11px; line-height: 1.65; }
    .warmup-composer-page .mailer-tags-dialog code { background: color-mix(in srgb, var(--co-purple) 9%, var(--co-surface)); border: 1px solid color-mix(in srgb, var(--co-purple) 16%, var(--co-border)); border-radius: 5px; color: var(--co-purple); padding: 2px 4px; white-space: normal; }
    .warmup-composer-page .mailer-tags-dialog #mailer-tags-close { background: var(--co-surface); border: 1px solid var(--co-border); color: var(--co-ink); min-height: 34px; }
    html[data-command-theme='dark'] .warmup-composer-page .form-control,
    html[data-command-theme='dark'] .warmup-composer-page .bootstrap-select > .dropdown-toggle { background-color: color-mix(in srgb, var(--co-surface-muted) 54%, var(--co-surface)); }
    html[data-command-theme='dark'] .warmup-composer-page .form-control:focus,
    html[data-command-theme='dark'] .warmup-composer-page .bootstrap-select > .dropdown-toggle:focus { background-color: var(--co-surface); }
    html[data-command-theme='dark'] .warmup-composer-page .mailer-ip-selector { box-shadow: inset 0 1px 0 rgb(255 255 255 / 3%); }
    @media (max-width: 1050px) {
        .warmup-composer-page .form-row.align-items-start > [class*="col-"] { flex: 0 0 100%; max-width: 100%; }
    }
    @media (max-width: 767.98px) {
        .warmup-composer-page > .warmup-composer-header { align-items: flex-start; flex-direction: column; padding: 14px; }
        .warmup-composer-page .block-options { flex: 1 1 auto; flex-wrap: wrap; justify-content: flex-start; width: 100%; }
        .warmup-composer-page > .block-content { padding: 12px; }
        .warmup-composer-section-nav { gap: 6px; grid-template-columns: minmax(0, 1fr); margin-bottom: 12px; }
        .warmup-composer-section-nav a { gap: 8px; min-height: 48px; padding: 7px 9px; }
        .warmup-composer-step-number { border-radius: 8px; flex-basis: 27px; height: 27px; font-size: 8px; }
        .warmup-composer-step-copy strong { font-size: 10px; }
        .warmup-composer-step-copy small { font-size: 8px; }
        .warmup-composer-section-nav a > i { display: block; }
        .warmup-composer-panel { padding: 11px 10px 5px; }
        .warmup-composer-panel-heading { margin: -11px -10px 11px; padding: 8px 10px; }
        .warmup-composer-page [data-campaign-placeholder-editor] > .block-header { align-items: flex-start; }
        .warmup-composer-page [data-campaign-placeholder-editor] .campaign-placeholder-heading { flex: 1 1 100%; }
        .warmup-composer-page .campaign-placeholder-toolbar { justify-content: flex-start; width: 100%; }
        .warmup-composer-page .campaign-placeholder-toolbar .campaign-mode-label { font-size: 8px; }
        .warmup-composer-page [data-campaign-placeholder-editor] .table { min-width: 510px; }
        .warmup-composer-page .form-row > [class*="col-"] { min-width: 0; }
        .warmup-composer-page .form-row .form-group.col-sm-2 { flex: 0 0 50%; max-width: 50%; }
        .warmup-composer-page .form-row .form-group.col-sm-8 { flex: 0 0 100%; max-width: 100%; }
        .warmup-composer-page .form-row .form-group.col-sm-12 { flex-basis: 100%; max-width: 100%; }
        .warmup-composer-page .mailer-ip-selector select { min-height: 300px; }
        .warmup-composer-page .mailer-ip-selector-actions { flex-direction: row; padding: 8px 0; }
        .warmup-composer-page .mailer-ip-selector select { height: 300px; min-height: 300px; }
        .warmup-composer-page .input-group-append .btn,
        .warmup-composer-page .campaign-icon-button,
        .warmup-composer-page #add-saved-email { height: 38px; width: 38px; }
        .warmup-composer-page .mailer-tags-dialog .row > [class*="col-"] { flex: 0 0 100%; max-width: 100%; }
        .warmup-composer-page textarea[name="header"], .warmup-composer-page textarea[name="html_body"] { min-height: 190px; }
    }
    @media (max-width: 575.98px) {
        .warmup-composer-page [data-campaign-placeholder-editor] .table-responsive { overflow: visible; }
        .warmup-composer-page [data-campaign-placeholder-editor] .table { display: block; min-width: 0; width: 100%; }
        .warmup-composer-page [data-campaign-placeholder-editor] .table thead { display: none; }
        .warmup-composer-page [data-campaign-placeholder-editor] .table tbody { display: grid; gap: 9px; }
        .warmup-composer-page [data-campaign-placeholder-editor] .table tbody tr { display: grid; grid-template-columns: minmax(0, 1fr) minmax(90px, .55fr); gap: 9px; padding: 10px; border: 1px solid var(--co-border); border-radius: 10px; background: var(--co-surface); }
        .warmup-composer-page [data-campaign-placeholder-editor] .table tbody td { display: flex; flex-direction: column; gap: 5px; min-width: 0; padding: 0; border: 0 !important; background: transparent !important; }
        .warmup-composer-page [data-campaign-placeholder-editor] .table tbody td::before { color: var(--co-muted); content: attr(data-label); font-size: 8px; font-weight: 800; letter-spacing: .045em; text-transform: uppercase; }
        .warmup-composer-page [data-campaign-placeholder-editor] .table tbody td:first-child { grid-column: 1 / -1; }
        .warmup-composer-page [data-campaign-placeholder-editor] .table tbody td:last-child { align-items: flex-end; justify-content: flex-end; }
        .warmup-composer-page [data-campaign-placeholder-editor] .table tbody td:last-child::before { align-self: flex-start; }
        .warmup-composer-page [data-campaign-placeholder-editor] .table tbody td [data-placeholder-remove] { width: 38px; }
        .warmup-composer-page .campaign-placeholder-toolbar { gap: 6px; }
        .warmup-composer-page .campaign-placeholder-toolbar .campaign-mode-toggle { flex: 1 1 auto; justify-content: center; }
        .warmup-composer-page .campaign-placeholder-toolbar [data-placeholder-add] { flex: 1 1 100%; }
    }
    @media (max-width: 390px) {
        .warmup-composer-section-nav { gap: 5px; }
        .warmup-composer-section-nav a { align-items: center; flex-direction: row; min-height: 46px; }
        .warmup-composer-step-copy strong { font-size: 10px; }
        .warmup-composer-step-copy small { display: block; }
    }
    @media (prefers-reduced-motion: reduce) {
        .warmup-composer-shell *, .warmup-composer-shell *::before, .warmup-composer-shell *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; animation-duration: .01ms !important; }
    }
    @media (max-width: 767.98px) { .mailer-ip-selector-actions { flex-direction: row; padding: .5rem 0; } }
</style>
@endpush

@push('scripts')
<script src="{{ asset('console-assets/vendor/ace/ace.js') }}"></script>
<script src="{{ asset('console-assets/vendor/ace/ext-language_tools.js') }}"></script>
<script src="{{ asset('alphalink/campaign-header-library.js') }}" defer></script>
<script>
(() => {
    const root = document.getElementById('mailer-composer');
    const form = document.getElementById('mailer-draft-form');
    if (!root || !form) return;
    const isDrop = root.dataset.kind === 'drop';
    const byId = id => document.getElementById(id);
    const multiValues = element => Array.from(element?.selectedOptions || []).map(option => String(option.value));
    const pmtaAdminLink = document.querySelector('[data-pmta-admin-link]');
    const pmtaAdminBaseUrl = pmtaAdminLink?.getAttribute('href');
    const pmtaPanelLink = document.querySelector('[data-pmta-panel-link]');
    let pmtaPanelTargets = {};
    try {
        pmtaPanelTargets = JSON.parse(pmtaPanelLink?.dataset.pmtaPanelTargets || '{}');
    } catch (_) {
        pmtaPanelTargets = {};
    }
    const syncPmtaAdminUrl = () => {
        if (!pmtaAdminLink || !pmtaAdminBaseUrl) return;
        const url = new URL(pmtaAdminBaseUrl, window.location.href);
        Array.from(url.searchParams.keys())
            .filter(key => key === 'server_ids' || key.startsWith('server_ids['))
            .forEach(key => url.searchParams.delete(key));
        multiValues(byId('send-servers')).forEach(id => url.searchParams.append('server_ids[]', id));
        pmtaAdminLink.href = url.toString();
    };
    const syncPmtaPanelUrls = () => {
        if (!pmtaPanelLink) return;
        const urls = multiValues(byId('send-servers'))
            .map(id => pmtaPanelTargets[id])
            .filter(url => typeof url === 'string' && url.length > 0);
        pmtaPanelLink.href = urls[0] || '#';
        pmtaPanelLink.dataset.selectedPanelUrls = JSON.stringify(urls);
    };
    const openPmtaAdmin = () => {
        if (!pmtaAdminLink || multiValues(byId('send-servers')).length === 0) return;
        syncPmtaAdminUrl();
        window.open(pmtaAdminLink.href, '_blank', 'noopener,noreferrer');
    };
    const openPmtaPanels = () => {
        syncPmtaPanelUrls();
        const urls = JSON.parse(pmtaPanelLink?.dataset.selectedPanelUrls || '[]');
        urls.forEach(url => window.open(url, '_blank', 'noopener,noreferrer'));
    };
    pmtaAdminLink?.addEventListener('click', event => {
        event.preventDefault();
        openPmtaAdmin();
    });
    pmtaPanelLink?.addEventListener('click', event => {
        event.preventDefault();
        openPmtaPanels();
    });
    if (window.ace) {
        const aceBase = @json(asset('console-assets/vendor/ace'));
        window.ace.config.set('basePath', aceBase);
        window.ace.config.set('modePath', `${aceBase}/`);
        window.ace.config.set('themePath', `${aceBase}/`);
    }
    const createMailerEditor = (editorId, sourceId, mode, lines) => {
        const element = byId(editorId);
        const source = byId(sourceId);
        if (!window.ace || !element || !source) return null;
        const editor = window.ace.edit(element);
        editor.setTheme('ace/theme/github');
        editor.session.setMode(`ace/mode/${mode}`);
        editor.setAutoScrollEditorIntoView(false);
        editor.setShowPrintMargin(false);
        editor.$blockScrolling = Infinity;
        editor.setFontSize(13);
        editor.setOptions({
            minLines: lines,
            maxLines: lines,
            enableBasicAutocompletion: true,
            enableSnippets: mode === 'html',
            enableLiveAutocompletion: true,
            indentedSoftWrap: false,
            wrap: true,
            showGutter: false,
        });
        editor.session.setValue(source.value, -1);
        editor.on('change', () => { source.value = editor.session.getValue(); });
        source.addEventListener('input', () => {
            if (editor.getValue() !== source.value) editor.session.setValue(source.value, -1);
        });
        element.style.height = `${lines * 16}px`;
        element.style.display = 'block';
        source.hidden = true;
        return editor;
    };
    const headerEditor = createMailerEditor('mailer-header-editor', 'mailer-header', 'text', 30);
    const bodyEditor = createMailerEditor('mailer-body-editor', 'mailer-body', 'html', 30);
    const readEditor = (editor, source) => editor?.getValue() ?? source?.value ?? '';
    form.addEventListener('submit', () => {
        if (headerEditor) byId('mailer-header').value = headerEditor.getValue();
        if (bodyEditor) byId('mailer-body').value = bodyEditor.getValue();
    });
    form.addEventListener('reset', () => window.setTimeout(() => {
        if (headerEditor) headerEditor.session.setValue(byId('mailer-header').value, -1);
        if (bodyEditor) bodyEditor.session.setValue(byId('mailer-body').value, -1);
    }, 0));
    const selectedSegmentValues = () => new Set(Array.from(byId('drop-segments')?.querySelectorAll('.drop-segment-option:checked') || []).map(input => String(input.value)));
    const syncSegmentCount = () => {
        const selected = Array.from(byId('drop-segments')?.querySelectorAll('.drop-segment-option:checked') || []);
        const recipients = selected.reduce((total, input) => total + Number(input.dataset.recipientCount || 0), 0);
        const count = byId('drop-list-selection-count');
        if (count) count.textContent = `${selected.length} List(s), ${recipients.toLocaleString()} Email(s) Selected`;
    };
    let latestSegments = [];
    const renderSegments = (rows, selected = selectedSegmentValues()) => {
        const container = byId('drop-segments');
        if (!container) return;
        container.replaceChildren();
        latestSegments = rows;
        if (!rows.length) {
            const empty = document.createElement('p');
            empty.className = 'text-muted mb-0';
            empty.textContent = 'No matching action lists are available.';
            container.append(empty);
            syncSegmentCount();
            return;
        }
        const countryFilter = new Set(multiValues(byId('drop-country')).map(country => country.toUpperCase()));
        const emailTypeFilter = new Set(multiValues(byId('drop-filter')));
        rows.forEach((row, index) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'custom-control custom-checkbox mb-1';
            wrapper.hidden = (countryFilter.size > 0 && !countryFilter.has(String(row.country || '').toUpperCase()))
                || (emailTypeFilter.size > 0 && !emailTypeFilter.has(String(row.email_type)));
            const input = document.createElement('input');
            input.className = 'custom-control-input drop-segment-option';
            input.type = 'checkbox';
            input.name = 'send_setup[audience_segments][]';
            input.value = String(row.id);
            input.id = `drop-segment-${index}`;
            input.dataset.country = String(row.country || '').toUpperCase();
            input.dataset.recipientCount = String(row.recipient_count || 0);
            input.checked = selected.has(String(row.id));
            const label = document.createElement('label');
            label.className = 'custom-control-label';
            label.htmlFor = input.id;
            label.textContent = `${row.name} — ${String(row.email_type).toUpperCase()} — ${row.country} · ${Number(row.recipient_count).toLocaleString()}`;
            wrapper.append(input, label);
            container.append(wrapper);
        });
        syncSegmentCount();
    };
    const selectFill = (element, rows, getValue, getLabel, selected = []) => {
        if (!element) return;
        const previous = new Set(selected.length ? selected.map(String) : multiValues(element));
        const first = element.options[0];
        const blank = first && first.value === '' ? first.cloneNode(true) : null;
        element.replaceChildren();
        if (blank) element.add(blank);
        for (const row of rows || []) {
            const option = new Option(getLabel(row), String(getValue(row)));
            option.selected = previous.has(String(option.value));
            if (row.server_id !== undefined) option.dataset.serverId = String(row.server_id);
            element.add(option);
        }
        if (window.jQuery?.fn?.selectpicker && element.classList.contains('selectpicker')) window.jQuery(element).selectpicker('refresh');
        if (element.id === 'send-servers') {
            syncPmtaAdminUrl();
            syncPmtaPanelUrls();
        }
    };
    const renderIpChoices = rows => {
        const values = byId('send-ips');
        const available = byId('available-send-ips');
        const selected = byId('selected-send-ips');
        if (!values || !available || !selected) return;
        const selectedIds = new Set(multiValues(values));
        values.replaceChildren();
        available.replaceChildren();
        selected.replaceChildren();
        (rows || []).forEach(row => {
            const id = String(row.id);
            const label = `${row.ip} | ${String(row.type).toUpperCase()} | ${row.dns || 'no DNS'} — server ${row.server_id}`;
            const isSelected = selectedIds.has(id);
            const storedOption = new Option(label, id, false, isSelected);
            storedOption.dataset.serverId = String(row.server_id);
            values.add(storedOption);
            const visibleOption = new Option(label, id);
            visibleOption.dataset.serverId = String(row.server_id);
            (isSelected ? selected : available).add(visibleOption);
        });
        const count = byId('selected-send-ips-count');
        if (count) count.textContent = String(selected.options.length);
        const empty = byId('send-ips-empty');
        if (empty) empty.hidden = (rows || []).length > 0;
    };
    const syncSelectedIpValues = () => {
        const selected = byId('selected-send-ips');
        const values = byId('send-ips');
        if (!selected || !values) return;
        const selectedIds = new Set(Array.from(selected.options).map(option => String(option.value)));
        Array.from(values.options).forEach(option => { option.selected = selectedIds.has(String(option.value)); });
        const count = byId('selected-send-ips-count');
        if (count) count.textContent = String(selected.options.length);
    };
    const moveIpOptions = (sourceId, targetId, moveAll = false) => {
        const source = byId(sourceId);
        const target = byId(targetId);
        if (!source || !target) return;
        const moving = moveAll ? Array.from(source.options) : Array.from(source.selectedOptions);
        moving.forEach(option => {
            const moved = new Option(option.textContent, option.value);
            moved.dataset.serverId = option.dataset.serverId || '';
            target.add(moved);
            option.remove();
        });
        syncSelectedIpValues();
    };
    const refresh = async () => {
        const params = new URLSearchParams();
        const sponsor = byId('drop-sponsor');
        const isp = byId('drop-isp');
        if (sponsor?.value) params.set('sponsor_id', sponsor.value);
        if (isp?.value) params.set('isp_id', isp.value);
        const route = byId('send-route')?.value || 'ip';
        params.set('route', route);
        params.set('ip_type', byId('send-ip-type')?.value || 'all');
        multiValues(byId('send-servers')).forEach(id => params.append('server_ids[]', id));
        try {
            const response = await fetch(`${root.dataset.optionsUrl}?${params}`, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            if (!response.ok) return;
            const data = (await response.json()).data;
            if (isDrop) {
                const countrySelect = byId('drop-offer-country');
                if (countrySelect) {
                    const current = countrySelect.value;
                    const countries = Array.from(new Set((data.offers || []).flatMap(row => row.geo_target || []).map(code => String(code).toUpperCase()))).sort();
                    countrySelect.replaceChildren(new Option('ALL', 'ALL'));
                    countries.forEach(code => countrySelect.add(new Option(code, code)));
                    countrySelect.value = countries.includes(current) ? current : 'ALL';
                    if (window.jQuery?.fn?.selectpicker) window.jQuery(countrySelect).selectpicker('refresh');
                }
                const listCountrySelect = byId('drop-country');
                if (listCountrySelect) {
                    const current = new Set(multiValues(listCountrySelect).map(code => code.toUpperCase()));
                    const countries = Array.from(new Set((data.segments || []).map(row => String(row.country || '').toUpperCase()).filter(Boolean))).sort();
                    listCountrySelect.replaceChildren();
                    countries.forEach(code => {
                        const option = new Option(code, code);
                        option.selected = current.has(code);
                        listCountrySelect.add(option);
                    });
                    if (window.jQuery?.fn?.selectpicker) window.jQuery(listCountrySelect).selectpicker('refresh');
                }
                const selectedOfferCountry = String(countrySelect?.value || 'ALL').toUpperCase();
                const visibleOffers = (data.offers || []).filter(row => selectedOfferCountry === 'ALL' || (row.geo_target || []).map(code => String(code).toUpperCase()).includes(selectedOfferCountry));
                selectFill(byId('drop-offer'), visibleOffers, row => row.id, row => `(${row.offer_id}) ${row.name} — ${row.vertical}`);
                selectFill(byId('drop-isp'), data.isps || [], row => row.id, row => row.name);
                renderSegments(data.segments || []);
                const offer = visibleOffers.find(row => String(row.id) === String(byId('drop-offer')?.value));
                const assets = offer?.assets || [];
                selectFill(byId('drop-from'), assets.filter(row => row.type === 'from'), row => row.id, row => row.value, multiValues(byId('drop-from')));
                selectFill(byId('drop-subject-values'), assets.filter(row => row.type === 'subject'), row => row.id, row => row.value, multiValues(byId('drop-subject-values')));
                const creative = byId('drop-creative');
                const selectedCreative = creative?.value;
                selectFill(creative, assets.filter(row => row.type === 'creative'), row => row.id, row => row.value, selectedCreative ? [selectedCreative] : []);
            }
            selectFill(byId('send-servers'), data.servers || [], row => row.id, row => `${row.name} | ${row.ip}`, multiValues(byId('send-servers')));
            renderIpChoices(data.ips || []);
            const domain = byId('mailer-domain');
            if (domain) {
                const current = domain.value;
                selectFill(domain, data.campaign_domains || [], row => row.value, row => `${row.name}${row.id ?? ''} - ${row.dns}`, current ? [current] : []);
            }
            const negative = byId('mailer-negative');
            if (negative) {
                const current = negative.value;
                selectFill(negative, data.negatives || [], row => row.id, row => row.name, current ? [current] : []);
            }
        } catch (_) {
            // Keep the last successfully loaded workspace catalog visible.
        }
    };
    [byId('drop-offer-country'), byId('drop-isp'), byId('drop-country'), byId('send-route'), byId('send-ip-type')]
        .filter(Boolean).forEach(element => element.addEventListener('change', refresh));
    byId('drop-sponsor')?.addEventListener('change', () => {
        const country = byId('drop-offer-country');
        if (country) { country.value = 'ALL'; if (window.jQuery?.fn?.selectpicker) window.jQuery(country).selectpicker('refresh'); }
        refresh();
    });
    byId('drop-offer')?.addEventListener('change', () => {
        ['drop-from', 'drop-subject-values', 'drop-creative'].forEach(id => {
            const element = byId(id);
            if (!element) return;
            Array.from(element.options).forEach(option => { option.selected = false; });
            if (window.jQuery?.fn?.selectpicker && element.classList.contains('selectpicker')) window.jQuery(element).selectpicker('refresh');
        });
        refresh();
    });
    byId('reload-send-options')?.addEventListener('click', refresh);
    byId('send-servers')?.addEventListener('change', () => {
        syncPmtaAdminUrl();
        syncPmtaPanelUrls();
        refresh();
    });
    byId('mailer-ip-add')?.addEventListener('click', () => moveIpOptions('available-send-ips', 'selected-send-ips'));
    byId('mailer-ip-add-all')?.addEventListener('click', () => moveIpOptions('available-send-ips', 'selected-send-ips', true));
    byId('mailer-ip-remove')?.addEventListener('click', () => moveIpOptions('selected-send-ips', 'available-send-ips'));
    byId('mailer-ip-remove-all')?.addEventListener('click', () => moveIpOptions('selected-send-ips', 'available-send-ips', true));
    byId('drop-segments')?.addEventListener('change', syncSegmentCount);
    byId('refresh-drop-lists')?.addEventListener('click', refresh);
    byId('drop-filter')?.addEventListener('change', () => renderSegments(latestSegments));
    byId('select-drop-lists')?.addEventListener('click', () => {
        byId('drop-segments')?.querySelectorAll('.drop-segment-option').forEach(input => { if (!input.closest('[hidden]')) input.checked = true; });
        syncSegmentCount();
    });
    byId('clear-drop-lists')?.addEventListener('click', () => {
        byId('drop-segments')?.querySelectorAll('.drop-segment-option').forEach(input => { input.checked = false; });
        syncSegmentCount();
    });
    if (window.jQuery?.fn?.selectpicker) window.jQuery(form).find('.selectpicker').selectpicker();
    syncPmtaAdminUrl();
    syncPmtaPanelUrls();
    refresh();

    byId('add-saved-email')?.addEventListener('click', () => {
        const selected = multiValues(byId('saved-emails'));
        const textarea = byId('email-test-addresses');
        const existing = new Set((textarea.value || '').split(/[\r\n,;]+/).map(value => value.trim().toLowerCase()).filter(Boolean));
        selected.forEach(value => { if (!existing.has(value.toLowerCase())) { textarea.value += `${textarea.value.trim() ? '\n' : ''}${value}`; existing.add(value.toLowerCase()); } });
    });
    byId('mailer-header-shuffle')?.addEventListener('click', () => {
        const header = byId('mailer-header');
        const lines = readEditor(headerEditor, header).split(/\r?\n/);
        const fields = lines.filter(line => line.trim() && !/^(MIME-version|Content-type|To|Date):/i.test(line));
        for (let i = fields.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [fields[i], fields[j]] = [fields[j], fields[i]]; }
        let index = 0;
        const shuffled = lines.map(line => /^(from|subject):/i.test(line.trim()) ? (fields[index++] || line) : line).join('\n');
        if (headerEditor) headerEditor.session.setValue(shuffled);
        else header.value = shuffled;
    });
    byId('mailer-insert-open-pixel')?.addEventListener('click', () => {
        const body = byId('mailer-body');
        const pixel = '<img src="[domain]/[rndUN_20]o[token]" width="1" height="1" style="display:block;">';
        if (bodyEditor) {
            bodyEditor.insert(pixel);
            bodyEditor.focus();
            return;
        }
        const start = body.selectionStart ?? body.value.length;
        const end = body.selectionEnd ?? start;
        body.setRangeText(pixel, start, end, 'end');
        body.focus();
    });
    const preview = byId('mailer-body-preview');
    byId('mailer-body-preview-open')?.addEventListener('click', () => {
        byId('mailer-body-preview-frame').srcdoc = readEditor(bodyEditor, byId('mailer-body'));
        preview.showModal();
    });
    byId('mailer-body-preview-close')?.addEventListener('click', () => preview.close());
    const tagsDialog = byId('mailer-tags-dialog');
    byId('mailer-tags-help')?.addEventListener('click', () => tagsDialog?.showModal());
    byId('mailer-tags-close')?.addEventListener('click', () => tagsDialog?.close());
    tagsDialog?.addEventListener('click', event => { if (event.target === tagsDialog) tagsDialog.close(); });
    document.querySelectorAll('[aria-disabled="true"]').forEach(element => element.addEventListener('click', event => event.preventDefault()));
    byId('clear-mailer-params')?.addEventListener('click', event => { event.preventDefault(); form.reset(); if (window.jQuery?.fn?.selectpicker) window.jQuery(form).find('.selectpicker').selectpicker('refresh'); refresh(); });
    document.querySelector('[data-save-draft]')?.addEventListener('click', event => { event.preventDefault(); form.requestSubmit(); });
    document.addEventListener('click', event => {
        const testAction = event.target.closest('[data-test-action]');
        if (!testAction) return;
        event.preventDefault();
        const testForm = byId('mailer-draft-test-action');
        if (!testForm) return;
        const key = testForm.querySelector('[name="idempotency_key"]');
        if (key && window.crypto?.randomUUID) key.value = window.crypto.randomUUID();
        testForm.requestSubmit();
    });
    document.addEventListener('click', event => {
        const sendAction = event.target.closest('[data-send-action]');
        if (!sendAction) return;
        event.preventDefault();
        const sendForm = byId('mailer-draft-send-action');
        if (!sendForm) return;
        const key = sendForm.querySelector('[name="idempotency_key"]');
        if (key && window.crypto?.randomUUID) key.value = window.crypto.randomUUID();
        sendForm.requestSubmit();
    });

    const bindDraftNavigationFallback = () => {
        const quickNav = document.querySelector('.quick-nav');
        const trigger = quickNav?.querySelector('.quick-nav-trigger');
        const actionList = byId('mailer-page-actions');
        const overlay = document.querySelector('.quick-nav-overlay');
        const contextMenu = byId('mailer-draft-context-menu');
        if (!quickNav || !trigger || !actionList || !overlay || !contextMenu || quickNav.dataset.alphalinkNavigationBound === 'true') {
            return;
        }

        const setOpen = open => {
            contextMenu.hidden = true;
            contextMenu.replaceChildren();
            quickNav.classList.toggle('nav-is-visible', open);
            trigger.setAttribute('aria-expanded', String(open));
            trigger.setAttribute('aria-label', open ? 'Close page actions' : 'Open page actions');
            actionList.setAttribute('aria-hidden', String(!open));
        };
        actionList.querySelectorAll(':scope > li > a').forEach(link => {
            link.setAttribute('role', 'menuitem');
            link.tabIndex = -1;
        });
        trigger.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            setOpen(!quickNav.classList.contains('nav-is-visible'));
            if (quickNav.classList.contains('nav-is-visible')) {
                actionList.querySelector('[role="menuitem"]')?.focus({ preventScroll: true });
            }
        });
        overlay.addEventListener('click', () => setOpen(false));
        document.addEventListener('click', event => {
            if (!quickNav.contains(event.target) && !contextMenu.contains(event.target)) {
                setOpen(false);
            }
        });
        document.addEventListener('contextmenu', event => {
            if (!root.contains(event.target) || event.target.closest('input, textarea, select, button, a, [contenteditable="true"], .ace_editor, .bootstrap-select')) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            setOpen(false);
            const items = [...actionList.querySelectorAll(':scope > li > a')].map(source => {
                const link = source.cloneNode(true);
                const originalId = link.id;
                link.removeAttribute('id');
                link.setAttribute('role', 'menuitem');
                link.tabIndex = 0;
                if (originalId === 'clear-mailer-params') {
                    link.dataset.contextAction = 'clear-params';
                }
                return link;
            });
            const fragment = document.createDocumentFragment();
            items.forEach(link => {
                const item = document.createElement('li');
                item.append(link);
                fragment.append(item);
            });
            contextMenu.replaceChildren(fragment);
            contextMenu.hidden = false;
            const bounds = contextMenu.getBoundingClientRect();
            const maxX = Math.max(4, document.documentElement.clientWidth - bounds.width - 4);
            const maxY = Math.max(4, document.documentElement.clientHeight - bounds.height - 4);
            contextMenu.style.left = `${Math.max(4, Math.min(event.clientX, maxX))}px`;
            contextMenu.style.top = `${Math.max(4, Math.min(event.clientY, maxY))}px`;
            contextMenu.querySelector('[role="menuitem"]')?.focus({ preventScroll: true });
        });
        contextMenu.addEventListener('click', event => {
            const link = event.target.closest('a');
            if (!link) {
                return;
            }
            if (link.getAttribute('aria-disabled') === 'true') {
                event.preventDefault();
                return;
            }
            if (link.hasAttribute('data-save-draft')) {
                event.preventDefault();
                setOpen(false);
                actionList.querySelector('[data-save-draft]')?.click();
                return;
            }
            if (link.dataset.contextAction === 'clear-params') {
                event.preventDefault();
                setOpen(false);
                actionList.querySelector('#clear-mailer-params')?.click();
                return;
            }
            if (link.hasAttribute('data-pmta-admin-link')) {
                event.preventDefault();
                openPmtaAdmin();
                setOpen(false);
                return;
            }
            if (link.hasAttribute('data-pmta-panel-link')) {
                event.preventDefault();
                openPmtaPanels();
                setOpen(false);
                return;
            }
            setOpen(false);
        });
        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape') {
                return;
            }
            if (!contextMenu.hidden) {
                setOpen(false);
                trigger.focus({ preventScroll: true });
            } else if (quickNav.classList.contains('nav-is-visible')) {
                setOpen(false);
                trigger.focus({ preventScroll: true });
            }
        });
        quickNav.dataset.alphalinkNavigationBound = 'true';
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindDraftNavigationFallback, { once: true });
    } else {
        bindDraftNavigationFallback();
    }
})();
</script>
<script src="{{ asset('alphalink/mailer-draft-navigation.js') }}?v={{ substr(sha1_file(public_path('alphalink/mailer-draft-navigation.js')), 0, 12) }}" defer></script>
@endpush

@push('styles')
    <style>
        .quick-nav-trigger { background: transparent; border: 0; cursor: pointer; padding: 0; }
        .quick-nav-trigger:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
        .mailer-draft-context-menu { background: #fff; border: 1px solid #d5dce1; border-radius: .2rem; box-shadow: 0 .25rem .8rem rgb(0 0 0 / 25%); list-style: none; margin: 0; max-height: calc(100vh - 8px); min-width: 13rem; overflow-y: auto; padding: .3rem 0; position: fixed; z-index: 10110; }
        .mailer-draft-context-menu[hidden] { display: none !important; }
        .mailer-draft-context-menu a { align-items: center; color: #253044; display: flex; justify-content: space-between; gap: 1rem; padding: .5rem .75rem; text-align: left; text-decoration: none; white-space: nowrap; }
        .mailer-draft-context-menu a:hover, .mailer-draft-context-menu a:focus-visible { background: #edf2ff; outline: 0; }
        .mailer-draft-context-menu a[aria-disabled="true"] { color: #8993a3; cursor: not-allowed; }
        .mailer-draft-context-menu i { color: #5179d6; }
    </style>
@endpush
