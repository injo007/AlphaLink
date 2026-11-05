@extends('layouts.admin-shell')

@php
    $isDrop = $kind === 'drop';
    $dropSendTypes = ['delay' => 'DELAY', 'delayp' => 'DELAYP (parallel)', 'hs' => 'HS', 'hsp' => 'HSP (parallel)', 'pickup' => 'PICKUP (parallel)'];
    $warmupSendTypes = ['delay' => 'DELAY', 'delayp' => 'DELAYP (parallel)', 'delayps' => 'DELAYPS (parallel)', 'hs' => 'HS', 'hsp' => 'HSP (parallel)', 'hsps' => 'HSPS (parallel by server)', 'pickup' => 'PICKUP (parallel)', 'pickups' => 'PICKUPS (parallel by server)'];
    $setup = (array) old('send_setup', $content['send_setup'] ?? []);
    $selectedSegments = array_map('strval', old('send_setup.audience_segments', $setup['audience_segments'] ?? []));
    $selectedEmailTypes = array_map('strval', old('send_setup.email_types', $setup['email_types'] ?? []));
    $selectedServers = array_map('strval', old('send_setup.server_ids', $setup['server_ids'] ?? []));
    $selectedIps = array_map('strval', old('send_setup.ip_ids', $setup['ip_ids'] ?? []));
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
    $selectedPanelServer = collect($sendOptions['servers'])->firstWhere('id', $selectedServers[0] ?? null);
    $body = old('html_body', $content['html_body'] ?? '');
    $header = old('header', $content['header'] ?? ($isDrop
        ? "MIME-version: 1.0\nContent-type: text/html\nTo: [to]\nfrom: [from] <info@[domain]>\nSubject: [subject]\nDate: [date]"
        : "MIME-version: 1.0\nContent-type: text/html\nTo: [to]\nfrom: [rnd] <info@[domain]>\nSubject: [ip]_[rnda_5]_[domain]\nDate: [date]"));
@endphp

@section('title', $isDrop ? ($draft ? 'Recent Drop' : 'New Drop') : ($draft ? 'Edit Warmup' : 'New Warmup'))

@section('content')
<div class="quick-nav" aria-label="{{ $isDrop ? 'Drop actions' : 'Warmup actions' }}">
    <a class="quick-nav-trigger" href="#" aria-label="Open page actions"><span></span></a>
    <ul>
        @if ($draft)
            <li><a href="#" data-save-draft><span>SAVE {{ $isDrop ? 'DROP' : 'WARMUP' }}</span><i class="fas fa-save"></i></a></li>
        @endif
        @if ($isDrop)
            <li><a href="{{ route('console.drops.drafts.create', $workspace->id) }}"><span>NEW DROP DRAFT</span><i class="fas fa-plus"></i></a></li>
        @else
            <li><a href="{{ route('console.warmups.create', $workspace->id) }}"><span>CREATE WARMUP</span><i class="fas fa-plus"></i></a></li>
        @endif
        <li><a href="#" class="text-muted" aria-disabled="true" title="Drop and Warmup execution is not connected in AlphaLink"><span>{{ $isDrop ? 'SEND DROP' : 'CREATE & SEND WARMUP' }} — UNAVAILABLE</span><i class="fas fa-paper-plane"></i></a></li>
        <li><a href="#" class="text-muted" aria-disabled="true" title="No verified Drop or Warmup test workflow is connected"><span>TEST — UNAVAILABLE</span><i class="fas fa-vial"></i></a></li>
        @if ($isDrop)
            <li><a href="#" class="text-muted" aria-disabled="true" title="No verified Drop scheduling workflow is connected"><span>SCHEDULE — UNAVAILABLE</span><i class="fas fa-calendar-alt"></i></a></li>
        @endif
        <li><a href="{{ route('clean.console.pmta-admin.index') }}"><span>PMTA ADMIN</span><i class="fas fa-grip-horizontal"></i></a></li>
        @if ($selectedPanelServer && filled($selectedPanelServer['pmta_panel_display_url'] ?? $selectedPanelServer['pmta_panel_url'] ?? null))
            <li><a href="{{ $selectedPanelServer['pmta_panel_display_url'] ?? $selectedPanelServer['pmta_panel_url'] }}" target="_blank" rel="noopener noreferrer"><span>PMTA PANEL — {{ $selectedPanelServer['name'] }}</span><i class="fas fa-server"></i></a></li>
        @else
            <li><a href="#" class="text-muted" aria-disabled="true" title="Select a server to open its read-only PMTA panel"><span>PMTA PANEL — SELECT A SERVER</span><i class="fas fa-server"></i></a></li>
        @endif
        <li><a href="#" id="clear-mailer-params"><span>CLEAR PARAMS</span><i class="fas fa-trash"></i></a></li>
        <li><a href="{{ $indexUrl }}"><span>BACK TO {{ $isDrop ? 'RECENT DROP' : 'MY WARMUPS' }}</span><i class="fas fa-arrow-left"></i></a></li>
    </ul>
    <span class="quick-nav-bg"></span>
</div>
<div class="quick-nav-overlay"></div>

<section class="block block-rounded" id="mailer-composer" data-options-url="{{ $optionsUrl }}" data-kind="{{ $kind }}">
    <div class="block-header">
        <h3 class="block-title">
            @if ($isDrop)
                DROP : {{ $draft?->getKey() ?? 'NEW' }} - TYPE: {{ strtoupper($setup['send_type'] ?? 'DRAFT') }} - PROCESSED: NO
            @else
                {{ $draft ? 'EDIT' : 'NEW' }} WARMUP
            @endif
        </h3>
        <div class="block-options d-flex align-items-center" style="gap:.5rem">
            <span class="badge badge-secondary">DRAFT</span>
            <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle fullscreen"></button>
        </div>
    </div>
    <div class="block-content block-content-full">
        <p class="small text-muted mb-3" role="note">AlphaLink draft · changes are saved as a revision. Send, test, and schedule are not available here.</p>
        @if (session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert"><strong>Review these fields:</strong><ul class="mb-0 mt-2">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
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

            <div class="form-row">
                <div class="form-group col-sm-12 col-md-4"><label for="return_path">Return Path:</label><input id="return_path" name="return_path" type="text" class="form-control" maxlength="320" placeholder="Return Path" value="{{ old('return_path', $content['return_path'] ?? '[rnd]@[domain]') }}"></div>
                <div class="form-group col-sm-12 col-md-4"><label for="saved-emails">Saved Emails:</label><div class="input-group"><select id="saved-emails" class="form-control selectpicker" multiple data-actions-box="true" data-live-search="true" data-selected-text-format="count > 1" data-size="5" title="Select Emails">@foreach ($savedEmails as $savedEmail)<option value="{{ $savedEmail->email }}" @selected(in_array($savedEmail->email, old('email_test_addresses', $content['email_test_addresses'] ?? []), true))>{{ $savedEmail->email }}</option>@endforeach</select><div class="input-group-append"><button type="button" id="add-saved-email" class="btn btn-success" title="Add saved email to test addresses"><i class="fas fa-plus"></i></button></div></div></div>
                <div class="form-group col-sm-12 col-md-4"><label for="email-test-addresses">{{ $isDrop ? 'Emails Test' : 'Email Test' }}:</label><textarea id="email-test-addresses" name="email_test_addresses_text" rows="1" class="form-control" placeholder="Email Test">{{ $emailTestText }}</textarea></div>
            </div>

            <hr>
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
                        <div class="form-group"><label for="warmup-send-type">Send Type:</label><select id="warmup-send-type" name="send_type" class="form-control">@foreach ($warmupSendTypes as $type => $label)<option value="{{ $type }}" @selected(old('send_type', $content['send_type'] ?? 'delay') === $type)>{{ $label }}</option>@endforeach</select></div>
                        <div class="form-row">
                            <div class="col-sm-12 col-md-6">@foreach ([['total_send', 'Total Send', 1], ['sleep_email', 'Sleep Btw Email (MS)', 0], ['seed_every', 'Test After', 0]] as [$field, $label, $default])<div class="form-group"><label for="warmup-{{ $field }}">{{ $label }}:</label><input id="warmup-{{ $field }}" name="{{ $field }}" type="number" min="0" max="2147483647" class="form-control" value="{{ old($field, $content[$field] ?? $default) }}"></div>@endforeach</div>
                            <div class="col-sm-12 col-md-6">@foreach ([['fraction_ip', 'Fraction IP', 1], ['sleep_ip', 'Sleep Btw IP (MS)', 0], ['bounce_limit', 'Bounce Limit', 1000]] as [$field, $label, $default])<div class="form-group"><label for="warmup-{{ $field }}">{{ $label }}:</label><input id="warmup-{{ $field }}" name="{{ $field }}" type="number" min="0" max="2147483647" class="form-control" value="{{ old($field, $content[$field] ?? $default) }}"></div>@endforeach</div>
                        </div>
                    </div>
                </div>
            @endif

            <div class="d-flex flex-wrap align-items-center mt-2" style="gap:.75rem">
                <button class="btn btn-primary" id="save-mailer-draft" type="submit">{{ $draft ? 'SAVE NEW REVISION' : ($isDrop ? 'SAVE DROP DRAFT' : 'CREATE WARMUP') }}</button>
                <a class="btn btn-alt-secondary" href="{{ $indexUrl }}">CANCEL</a>
                <span class="text-muted small">Save draft</span>
            </div>
        </form>
    </div>
</section>
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
    byId('send-servers')?.addEventListener('change', refresh);
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
})();
</script>
@endpush
