@extends('layouts.dms-admin')

@section('title', isset($editingCampaign) ? 'Edit Campaign' : 'Create Campaign')

@section('content')
    @php
        $editingCampaign = $editingCampaign ?? null;
        $preparingCampaign = $preparingCampaign ?? null;
        $savedContent = $editingCampaign?->latestRevision?->payload ?? [];
        $savedSetup = $savedContent['send_setup'] ?? null;
        $selectedOfferAssets = $editingCampaign
            ? ($savedOfferAssets ?? [])
            : ($sendOptions['offers']->firstWhere('id', (int) old('send_setup.offer_id', 0))['assets'] ?? []);
        $defaultDmsHeader = "MIME-version: 1.0\nContent-type: text/html\nTo: [to]\nfrom: [from] <info@[domain]>\nSubject: [subject]\nDate: [date]";
        $setup = [
            'sponsor_id' => (string) old('send_setup.sponsor_id', $savedSetup['sponsor_id'] ?? ''),
            'offer_id' => (string) old('send_setup.offer_id', $savedSetup['offer_id'] ?? ''),
            'negative_id' => (string) old('send_setup.negative_id', $savedSetup['negative_id'] ?? ''),
            'offer_country' => strtoupper((string) old('send_setup.offer_country', $savedSetup['offer_country'] ?? 'ALL')),
            'isp_id' => (string) old('send_setup.isp_id', $savedSetup['isp_id'] ?? ''),
            'country' => strtoupper((string) old('send_setup.country', $savedSetup['country'] ?? 'ALL')),
            'audience_segments' => array_map('strval', (array) old('send_setup.audience_segments', $savedSetup['audience_segments'] ?? [])),
            'from_asset_ids' => array_map('strval', (array) old('send_setup.from_asset_ids', $savedSetup['from_asset_ids'] ?? [])),
            'subject_asset_ids' => array_map('strval', (array) old('send_setup.subject_asset_ids', $savedSetup['subject_asset_ids'] ?? [])),
            'creative_asset_id' => (string) old('send_setup.creative_asset_id', $savedSetup['creative_asset_id'] ?? ''),
            'html_asset_id' => (string) old('send_setup.html_asset_id', $savedSetup['html_asset_id'] ?? ''),
            'order_by' => (string) old('send_setup.order_by', $savedSetup['order_by'] ?? 'default'),
            'start_from' => (string) old('send_setup.start_from', $savedSetup['start_from'] ?? '0'),
            'route_option' => (string) old('send_setup.route_option', $savedSetup['route_option'] ?? 'ip'),
            'ip_type' => (string) old('send_setup.ip_type', $savedSetup['ip_type'] ?? 'ipv4'),
            'server_ids' => array_map('strval', (array) old('send_setup.server_ids', $savedSetup['server_ids'] ?? [])),
            'ip_ids' => array_map('strval', (array) old('send_setup.ip_ids', $savedSetup['ip_ids'] ?? [])),
            'send_type' => (string) old('send_setup.send_type', $savedSetup['send_type'] ?? 'delay'),
            'total_send' => (string) old('send_setup.total_send', $savedSetup['parameters']['total_send'] ?? '1'),
            'fraction_ip' => (string) old('send_setup.fraction_ip', $savedSetup['parameters']['fraction_ip'] ?? '1'),
            'sleep_email' => (string) old('send_setup.sleep_email', $savedSetup['parameters']['sleep_email'] ?? '0'),
            'sleep_ip' => (string) old('send_setup.sleep_ip', $savedSetup['parameters']['sleep_ip'] ?? '0'),
            'seed_every' => (string) old('send_setup.seed_every', $savedSetup['parameters']['seed_every'] ?? '0'),
            'bounce_limit' => (string) old('send_setup.bounce_limit', $savedSetup['parameters']['bounce_limit'] ?? '1000'),
            'duplicate_data' => (string) old('send_setup.duplicate_data', $savedSetup['parameters']['duplicate_data'] ?? '1'),
        ];
    @endphp

    <h2 class="content-heading">{{ $editingCampaign ? 'EDIT CAMPAIGN ' . $editingCampaign->id : 'CREATE CAMPAIGN' }}</h2>

    @if (session('status'))
        <div class="alert alert-success" role="status" aria-live="polite">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger" role="alert" tabindex="-1">
            <p class="mb-1">Review the campaign fields below:</p>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div id="campaign-snapshot-progress" class="block block-rounded border mb-4" role="status" aria-live="polite" @if (!$preparingCampaign) hidden @endif
         @if ($preparingCampaign) data-progress-url="{{ route('console.campaigns.snapshot-progress', [$workspace->id, $preparingCampaign->id]) }}" data-campaign-url="{{ route('console.campaigns.show', [$workspace->id, $preparingCampaign->id]) }}" data-edit-url="{{ route('console.campaigns.edit', [$workspace->id, $preparingCampaign->id]) }}" @endif>
        <div class="block-header block-header-default">
            <h3 class="block-title">SUPPRESSION AND RECIPIENT PREPARATION</h3>
            <span id="campaign-snapshot-percent" class="badge badge-primary">0%</span>
        </div>
        <div class="block-content">
            <p id="campaign-snapshot-stage" class="mb-2">Waiting for preparation status…</p>
            <div class="progress" style="height: 1.25rem" role="progressbar" aria-label="Campaign suppression and recipient preparation" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <div id="campaign-snapshot-bar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%">0%</div>
            </div>
            <div class="d-flex justify-content-between mt-2">
                <small id="campaign-snapshot-detail" class="text-muted">The campaign composer stays open while the selected lists are processed.</small>
                <a id="campaign-snapshot-open" class="small" href="#" hidden>Open saved campaign</a>
            </div>
            <div id="campaign-snapshot-error" class="alert alert-danger mt-3 mb-0" role="alert" hidden></div>
            <a id="campaign-snapshot-retry" class="btn btn-sm btn-outline-primary mt-3" href="#" hidden>Review saved campaign and retry preparation</a>
        </div>
    </div>

    @php
        $campaignMenuItems = $editingCampaign
            ? [
                ['action' => 'edit', 'label' => 'EDIT CAMPAIGN', 'icon' => 'fas fa-edit'],
                ['action' => 'test', 'label' => 'TEST CAMPAIGN', 'icon' => 'fas fa-vial'],
                ['action' => 'pmta-admin', 'label' => 'PMTA ADMIN', 'icon' => 'fas fa-grip-horizontal'],
                ['action' => 'pmta-panel', 'label' => 'PMTA PANEL', 'icon' => 'fas fa-server'],
            ]
            : [
                ['action' => 'create', 'label' => 'CREATE CAMPAIGN', 'icon' => 'fas fa-plus'],
                ['action' => 'create-send', 'label' => 'CREATE & SEND CAMPAIGN', 'icon' => 'fas fa-paper-plane', 'unavailable' => true],
                ['action' => 'test', 'label' => 'TEST CAMPAIGN', 'icon' => 'fas fa-vial'],
                ['action' => 'pmta-admin', 'label' => 'PMTA ADMIN', 'icon' => 'fas fa-grip-horizontal'],
                ['action' => 'pmta-panel', 'label' => 'PMTA PANEL', 'icon' => 'fas fa-server'],
            ];
    @endphp

        <style>
            #campaign-action-backdrop { position: fixed; inset: 0; z-index: 1090; background: rgba(0, 0, 0, .82); }
            #campaign-snapshot-progress[hidden], #campaign-snapshot-open[hidden], #campaign-snapshot-retry[hidden], #campaign-new-campaign[hidden] { display: none !important; }
            #campaign-floating-actions { position: fixed; top: 45%; right: 0; z-index: 1100; }
            #campaign-actions-trigger { width: 36px; height: 36px; border: 0; color: #fff; background: #527bd8; font-size: 18px; box-shadow: 0 2px 6px rgba(0, 0, 0, .25); }
            #campaign-actions-panel { position: fixed; top: calc(45% + 42px); right: 0; width: min(218px, calc(100vw - 4px)); padding: 0; background: transparent; box-shadow: none; }
            #campaign-actions-panel[hidden], #campaign-action-backdrop[hidden], #campaign-context-menu[hidden] { display: none !important; }
            #campaign-actions-panel button { position: relative; display: flex; width: 100%; min-height: 36px; align-items: center; justify-content: flex-end; border: 0; background: transparent; color: #fff; padding: 6px 46px 6px 8px; text-align: right; text-shadow: 0 1px 2px #111; white-space: nowrap; }
            #campaign-context-menu button { display: flex; width: 100%; align-items: center; justify-content: space-between; gap: 16px; border: 0; background: transparent; color: #253044; padding: 8px 12px; text-align: left; white-space: nowrap; }
            #campaign-actions-panel button:hover, #campaign-actions-panel button:focus { background: rgba(82, 123, 216, .28); color: #fff; outline: 0; }
            #campaign-context-menu button:hover, #campaign-context-menu button:focus { background: #edf2ff; outline: 0; }
            #campaign-actions-panel i { position: absolute; top: 0; right: 0; display: grid; width: 36px; height: 36px; place-items: center; color: #fff; background: #527bd8; text-shadow: none; }
            #campaign-context-menu i { color: #3182ce; }
            #campaign-actions-panel [aria-disabled="true"], #campaign-context-menu [aria-disabled="true"] { color: #687386; cursor: help; }
            #campaign-actions-panel [aria-disabled="true"] { color: #e0e4ec; }
            #campaign-actions-panel [aria-disabled="true"] i { background: #72809a; }
            #campaign-actions-panel [aria-disabled="true"]:hover { background: rgba(82, 123, 216, .18); }
            #campaign-context-menu [aria-disabled="true"]:hover { background: #f5f6f8; }
            #campaign-context-menu { position: fixed; z-index: 1110; min-width: 190px; padding: 5px 0; background: #fff; box-shadow: 0 2px 8px rgba(0, 0, 0, .32); }
            #campaign-action-status { position: sticky; top: .25rem; z-index: 1120; }
            #campaign-action-status[hidden] { display: none !important; }
        </style>
        <form method="POST" action="{{ $editingCampaign ? route('console.campaigns.revisions.store', [$workspace->id, $editingCampaign->id]) : route('console.campaigns.store', $workspace->id) }}" id="campaign-create-form" data-options-url="{{ $sendOptionsUrl }}" novalidate>
        <div id="campaign-action-backdrop" hidden></div>
        <div id="campaign-floating-actions" data-pmta-admin-url="{{ route('console.pmta-admin.index', $workspace->id) }}" data-editing="{{ $editingCampaign ? 'true' : 'false' }}">
            <button type="button" id="campaign-actions-trigger" aria-label="Open campaign actions" aria-haspopup="menu" aria-expanded="false"><i class="fas fa-bars" aria-hidden="true"></i></button>
            <div id="campaign-actions-panel" role="menu" aria-label="Campaign actions" hidden>
                @foreach ($campaignMenuItems as $item)
                    <button type="button" role="menuitem" data-campaign-menu-action="{{ $item['action'] }}" @if ($item['action'] === 'test') data-test-url="{{ route('console.campaigns.manual-test', $workspace->id) }}" @endif @if ($item['unavailable'] ?? false) aria-disabled="true" title="Unavailable until the DMS native campaign sender and lifecycle are implemented and tested." @endif>
                        {{ $item['label'] }} <i class="{{ $item['icon'] }}" aria-hidden="true"></i>
                    </button>
                @endforeach
            </div>
        </div>
        <div id="campaign-context-menu" role="menu" aria-label="Campaign actions" hidden>
            @foreach ($campaignMenuItems as $item)
                <button type="button" role="menuitem" data-campaign-menu-action="{{ $item['action'] }}" @if ($item['action'] === 'test') data-test-url="{{ route('console.campaigns.manual-test', $workspace->id) }}" @endif @if ($item['unavailable'] ?? false) aria-disabled="true" title="Unavailable until the DMS native campaign sender and lifecycle are implemented and tested." @endif>
                    {{ $item['label'] }} <i class="{{ $item['icon'] }}" aria-hidden="true"></i>
                </button>
            @endforeach
        </div>
        <div id="campaign-action-status" class="alert alert-info mb-0" role="status" aria-live="polite" hidden></div>
        <div id="campaign-submit-error" class="alert alert-danger mb-3" role="alert" hidden></div>
        @if ($editingCampaign)
            <a id="campaign-test-log-link" class="d-inline-block mt-2" href="{{ route('console.campaigns.show', [$workspace->id, $editingCampaign->id]) }}#campaign-test-logs" hidden>Open campaign test logs</a>
        @endif

        @csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
        @if ($editingCampaign)
            <input type="hidden" name="expected_revision_id" value="{{ $editingCampaign->latestRevision->id }}">
            <input type="hidden" name="test_campaign_id" value="{{ $editingCampaign->id }}">
        @endif
        @unless ($editingCampaign)
        <input type="hidden" name="name" value="{{ old('name', 'Campaign '.strtoupper(substr($idempotencyKey, 0, 8))) }}">
        <input type="hidden" name="kind" value="marketing">
        @endunless

        @unless ($editingCampaign)
        <div id="campaign-targeting">
            <div class="row">
                <div class="col-sm-12 col-md-6">
                    <div class="block block-rounded">
                        <div class="block-header"><h3 class="block-title">Select Offer</h3></div>
                        <div class="block-content">
                            <div class="form-group">
                                <label for="send-sponsor">Sponsor:</label>
                                <select id="send-sponsor" name="send_setup[sponsor_id]" class="form-control selectpicker" data-live-search="true" data-allow-clear="true" title="Select Sponsor" required>
                                    <option value="">Select Sponsor</option>
                                    @foreach ($sendOptions['sponsors'] as $sponsor)
                                        <option value="{{ $sponsor['id'] }}" @selected($setup['sponsor_id'] === (string) $sponsor['id'])>{{ $sponsor['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="send-offer-country">Offer Countries:</label>
                                <select id="send-offer-country" name="send_setup[offer_country]" class="form-control selectpicker" data-live-search="true" data-size="6" title="Select Countries" required>
                                    <option value="ALL" @selected($setup['offer_country'] === 'ALL')>ALL</option>
                                    @foreach ($countries as $code => $countryName)
                                        <option value="{{ $code }}" @selected($setup['offer_country'] === $code)>{{ $countryName }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group mb-0">
                                <label for="send-offer">Offers:</label>
                                <select id="send-offer" name="send_setup[offer_id]" class="form-control selectpicker" data-live-search="true" data-size="6" title="Select Offer" required>
                                    <option value="">Select Offer</option>
                                    @foreach ($sendOptions['offers'] as $offer)
                                        <option value="{{ $offer['id'] }}" @selected($setup['offer_id'] === (string) $offer['id'])>({{ $offer['offer_id'] }}) {{ $offer['name'] }} — {{ $offer['vertical'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-sm-12 col-md-6">
                    <div class="block block-rounded">
                        <div class="block-header"><h3 class="block-title">Select List</h3></div>
                        <div class="block-content">
                            <div class="row">
                                <div class="col-sm-12 col-md-6">
                                    <div class="form-group">
                                        <label for="send-isp">ISPs:</label>
                                        <select id="send-isp" name="send_setup[isp_id]" class="form-control selectpicker" data-live-search="true" data-allow-clear="true" title="Select ISP" required>
                                            <option value="">Select ISP</option>
                                            @foreach ($sendOptions['isps'] as $isp)
                                                <option value="{{ $isp['id'] }}" @selected($setup['isp_id'] === (string) $isp['id'])>{{ $isp['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="col-sm-12 col-md-6">
                                    <div class="form-group">
                                        <label for="send-country">Country:</label>
                                        <select id="send-country" name="send_setup[country]" class="form-control selectpicker" data-live-search="true" data-allow-clear="false" data-size="6" title="Select Country" required>
                                            <option value="ALL" @selected($setup['country'] === 'ALL')>ALL</option>
                                            @foreach ($countries as $code => $countryName)
                                                <option value="{{ $code }}" @selected($setup['country'] === $code)>{{ $countryName }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="send-lists">Lists / email segments:</label>
                                <select id="send-lists" name="send_setup[audience_segments][]" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 3" title="Select Lists" multiple required>
                                    @foreach ($sendOptions['segments'] as $segment)
                                        <option value="{{ $segment['id'] }}" @selected(in_array((string) $segment['id'], $setup['audience_segments'], true))>{{ $segment['name'] }} — {{ $segment['country'] }} — {{ strtoupper($segment['email_type']) }} — {{ number_format($segment['recipient_count']) }}</option>
                                    @endforeach
                                </select>
                                <small class="form-text text-muted">Select unlocked DMS email types. Saving automatically matches the selected offer’s saved suppression file against every selected List/email type and leaves matches out of the campaign snapshot. Drops → <a href="{{ route('console.drops.suppression', $workspace->id) }}">Suppression</a> separately applies that file to the chosen ISP/List memberships for reusable Offer/List exclusions; you do not need to run it first for campaign creation. Workspace suppressions and duplicate addresses are also excluded.</small>
                            </div>
                            <div class="form-row mb-0">
                                <div class="form-group col-sm-6">
                                    <label for="send-order-by">Order By:</label>
                                    <select id="send-order-by" name="send_setup[order_by]" class="form-control" required>
                                        @foreach (['default' => 'DEFAULT', 'asc' => 'ASC', 'desc' => 'DESC', 'random' => 'RANDOM'] as $value => $label)
                                            <option value="{{ $value }}" @selected($setup['order_by'] === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group col-sm-6">
                                    <label for="send-start-from">Start From:</label>
                                    <input id="send-start-from" name="send_setup[start_from]" type="number" min="0" class="form-control" value="{{ $setup['start_from'] }}" required>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @endunless
        <div id="campaign-data" tabindex="-1">
        <div class="alert alert-primary alert-dismissible text-center py-2" id="campaign-usable-tags">
            <strong>USABLE TAGS:</strong>
            [server] [main_domain] [ip] [domain] [rdns] [to] [date] [name] [rnd]
            [rnd(N|A|L|U|S|LU|LN|NU)_NUMBER] [text:a|b|c]
            <button type="button" class="btn btn-link btn-sm p-0 ml-1" id="campaign-tags-help" title="SHOW EXAMPLE" aria-label="Show tag examples"><i class="fas fa-question-circle" aria-hidden="true"></i></button>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">×</span></button>
        </div>
        <div class="block block-rounded">
            <div class="block-content">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="campaign-return-path">Return path:</label>
                        <input id="campaign-return-path" name="return_path" type="text" maxlength="320" class="form-control" value="{{ old('return_path', $savedContent['return_path'] ?? '[rnd]@[domain]') }}">
                    </div>
                    <div class="form-group col-md-4" id="campaign-saved-emails" data-store-url="{{ route('console.campaign-test-addresses.store', $workspace->id) }}" data-delete-url-template="{{ route('console.campaign-test-addresses.destroy', [$workspace->id, '__ADDRESS_ID__']) }}">
                        <label for="saved-email-addresses">Saved Emails:</label>
                        <div class="input-group">
                            <select id="saved-email-addresses" class="form-control selectpicker" data-live-search="true" data-actions-box="true" title="Select Emails" multiple>
                                @foreach ($savedEmailAddresses as $savedAddress)
                                    <option value="{{ $savedAddress->email }}" data-address-id="{{ $savedAddress->id }}">{{ $savedAddress->email }}</option>
                                @endforeach
                            </select>
                            <div class="input-group-append"><button id="saved-email-manage" type="button" class="btn btn-success" aria-label="Manage saved email addresses" title="Manage saved email addresses"><i class="fas fa-plus" aria-hidden="true"></i></button></div>
                        </div>
                        <div id="saved-email-manager" class="mt-2" hidden>
                            <label for="saved-email-new">Add a test address</label>
                            <div class="input-group"><input id="saved-email-new" type="email" maxlength="255" class="form-control"><div class="input-group-append"><button id="saved-email-add" type="button" class="btn btn-success">Add</button><button id="saved-email-remove" type="button" class="btn btn-outline-danger">Delete selected</button></div></div>
                            <div id="saved-email-status" role="status" aria-live="polite" class="small mt-1"></div>
                        </div>
                    </div>
                    <div class="form-group col-md-4">
                        <label for="campaign-email-tests">Email Test addresses:</label>
                        <textarea id="campaign-email-tests" name="email_test_addresses" rows="2" class="form-control" autocomplete="off">{{ old('email_test_addresses', implode("\n", $savedContent['email_tests'] ?? [])) }}</textarea>
                    </div>
                </div>
                <div class="form-row">
                    @include('console.campaigns.partials.header-library', ['workspace' => $workspace, 'textareaId' => 'campaign-dms-header', 'idPrefix' => 'campaign'])
                </div>
                <div class="form-row">
                    <div class="form-group col-sm-12 col-md-3">
                        <label for="campaign-domain">Domain:</label>
                        <select id="campaign-domain" name="domain" class="form-control selectpicker" data-live-search="true" data-allow-clear="true" title="Select Domain">
                            <option value="">Select Domain</option>
                            @foreach ($sendOptions['campaign_domains'] as $domain)
                                <option value="{{ $domain['value'] }}" @selected(old('domain', $savedSetup['domain'] ?? '') === $domain['value'])>{{ $domain['name'] }} — {{ $domain['dns'] }}</option>
                            @endforeach
                        </select>
                        <small id="campaign-domain-route-hint" class="form-text text-muted" hidden></small>
                    </div>
                    <div class="form-group col-sm-12 col-md-3">
                        <label for="campaign-negative">Negative:</label>
                        <select id="campaign-negative" name="send_setup[negative_id]" class="form-control selectpicker" data-live-search="true" data-allow-clear="true" title="Select Negative">
                            <option value="">Select Negative</option>
                            @foreach ($sendOptions['negatives'] as $negative)
                                <option value="{{ $negative['id'] }}" @selected($setup['negative_id'] === (string) $negative['id'])>{{ $negative['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-sm-12 col-md-3">
                        <label for="send-creative-asset">Creatives:</label>
                        <select id="send-creative-asset" name="send_setup[creative_asset_id]" class="form-control selectpicker" data-live-search="true" title="Select Creative">
                            <option value="">Select Creative</option>
                            @foreach ($selectedOfferAssets as $asset)
                                @if ($asset['type'] === 'creative')
                                    <option value="{{ $asset['id'] }}" data-asset-value="{{ $asset['value'] }}" data-public-url="{{ $asset['public_url'] ?? '' }}" data-unsubscribe-value="{{ collect($selectedOfferAssets)->firstWhere('type', 'unsubscribe')['value'] ?? '' }}" @selected($setup['creative_asset_id'] === (string) $asset['id'])>{{ $asset['value'] }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-sm-12 col-md-3">
                        <label for="send-html-asset">Html:</label>
                        <select id="send-html-asset" name="send_setup[html_asset_id]" class="form-control selectpicker" data-live-search="true" title="Select HTML">
                            <option value="">Select HTML</option>
                            @foreach ($selectedOfferAssets as $asset)
                                @if ($asset['type'] === 'html')
                                    <option value="{{ $asset['id'] }}" data-asset-value="{{ $asset['value'] }}" @selected($setup['html_asset_id'] === (string) $asset['id'])>{{ \Illuminate\Support\Str::limit($asset['value'], 50) }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-sm-12 col-md-6">
                        <label for="send-from-assets">From values (optional):
                            <button type="button" class="btn btn-link btn-sm p-0" data-copy-selected="send-from-assets" title="Copy selected From values" aria-label="Copy selected From values"><i class="fas fa-copy" aria-hidden="true"></i></button>
                        </label>
                        <select id="send-from-assets" name="send_setup[from_asset_ids][]" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 2" title="Select optional From values" multiple>
                            @foreach (($sendOptions['offers']->firstWhere('id', (int) $setup['offer_id'])['assets'] ?? []) as $asset)
                                @if ($asset['type'] === 'from')
                                    <option value="{{ $asset['id'] }}" @selected(in_array((string) $asset['id'], $setup['from_asset_ids'], true))>{{ $asset['value'] }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-sm-12 col-md-6">
                        <label for="send-subject-assets">Subjects (optional):
                            <button type="button" class="btn btn-link btn-sm p-0" data-copy-selected="send-subject-assets" title="Copy selected subjects" aria-label="Copy selected subjects"><i class="fas fa-copy" aria-hidden="true"></i></button>
                        </label>
                        <select id="send-subject-assets" name="send_setup[subject_asset_ids][]" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 2" title="Select optional subjects" multiple>
                            @foreach (($sendOptions['offers']->firstWhere('id', (int) $setup['offer_id'])['assets'] ?? []) as $asset)
                                @if ($asset['type'] === 'subject')
                                    <option value="{{ $asset['id'] }}" @selected(in_array((string) $asset['id'], $setup['subject_asset_ids'], true))>{{ $asset['value'] }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <small class="form-text text-muted col-12">Optional rotation values. Leave these blank to use the literal From and Subject values in the Header.</small>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="campaign-dms-header">Header:
                            <button type="button" class="btn btn-link btn-sm p-0" id="campaign-header-shuffle" title="Shuffle header lines" aria-label="Shuffle header lines"><i class="fas fa-random" aria-hidden="true"></i></button>
                            <button type="button" class="btn btn-link btn-sm p-0" id="campaign-header-encode" title="Encoding tools" aria-label="Encoding tools"><i class="fas fa-pencil-ruler" aria-hidden="true"></i></button>
                        </label>
                        <textarea id="campaign-dms-header" name="header" rows="30" class="form-control campaign-header-editor" spellcheck="false" required>{{ old('header', $savedContent['header'] ?? $defaultDmsHeader) }}</textarea>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="campaign-dms-body">Body:
                            <button type="button" id="campaign-body-preview-button" class="btn btn-link btn-sm p-0" title="Preview body" aria-controls="campaign-body-preview" aria-expanded="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
                            @if ($editingCampaign)
                                <button type="button" id="campaign-body-generate-links" class="btn btn-link btn-sm p-0" data-generate-url="{{ route('console.campaigns.generate-body', [$workspace->id, $editingCampaign->id]) }}" title="Generate tracking links" aria-label="Generate tracking links"><i class="fas fa-link" aria-hidden="true"></i></button>
                            @endif
                            <button type="button" id="campaign-shortlink-open" class="btn btn-link btn-sm p-0" title="Shortlink tools" aria-label="Shortlink tools"><i class="fas fa-unlink" aria-hidden="true"></i></button>
                        </label>
                        <div class="custom-control custom-switch custom-control-inline ml-2">
                            <input id="campaign-body-map" type="checkbox" class="custom-control-input">
                            <label for="campaign-body-map" class="custom-control-label">MAP BODY</label>
                        </div>
                        <textarea id="campaign-dms-body" name="body" rows="28" class="form-control" spellcheck="false">{{ old('body', $savedContent['body'] ?? '') }}</textarea>
                        <div id="campaign-body-preview" hidden class="mt-2"><iframe id="campaign-body-preview-frame" title="Campaign body preview" sandbox="" style="width:100%;min-height:360px;border:1px solid #d7dce3"></iframe></div>
                    </div>
                </div>
                <div class="form-row">
                    @include('console.campaigns.partials.placeholder-editor', [
                        'idPrefix' => 'campaign',
                        'rows' => old('send_setup.placeholder_inputs', collect($savedSetup['placeholders'] ?? [])->map(fn (array $p): array => ['data_text' => implode("\n", $p['data'] ?? []), 'rotate_every' => $p['rotate_every'] ?? 1])->all()),
                        'combination' => old('send_setup.placeholder_combination', $savedSetup['placeholder_combination'] ?? false),
                        'allCombination' => old('send_setup.placeholder_all_combination', $savedSetup['placeholder_all_combination'] ?? false),
                        'showHelp' => false,
                    ])
                </div>
                <div id="campaign-routing">
                    <div class="row">
                        <div class="col-sm-12 col-md-8">
                            <div class="form-row">
                                <div class="form-group col-sm-6 col-md-2">
                                    <label for="send-route">Option:</label>
                                    <select id="send-route" name="send_setup[route_option]" class="form-control" required>
                                        <option value="ip" @selected($setup['route_option'] === 'ip')>IP</option>
                                        <option value="vmta" @selected($setup['route_option'] === 'vmta')>VMTA</option>
                                        <option value="smtp" @selected($setup['route_option'] === 'smtp')>SMTP</option>
                                    </select>
                                </div>
                                <div class="form-group col-sm-6 col-md-2">
                                    <label for="send-ip-type">Type IP:</label>
                                    <select id="send-ip-type" name="send_setup[ip_type]" class="form-control" required>
                                        <option value="all" @selected($setup['ip_type'] === 'all')>ALL</option>
                                        <option value="ipv4" @selected($setup['ip_type'] === 'ipv4')>IPv4</option>
                                        <option value="ipv6" @selected($setup['ip_type'] === 'ipv6')>IPv6</option>
                                    </select>
                                </div>
                                <div class="form-group col-sm-12 col-md-8">
                                    <label for="send-servers">Servers:
                                        <button type="button" class="btn btn-link btn-sm p-0" id="campaign-servers-reload" title="Reload servers and IPs" aria-label="Reload servers and IPs"><i class="fas fa-sync-alt" aria-hidden="true"></i></button>
                                        <button type="button" class="btn btn-link btn-sm p-0" id="campaign-servers-search" title="Search servers" aria-label="Search servers"><i class="fas fa-search" aria-hidden="true"></i></button>
                                    </label>
                                    <select id="send-servers" name="send_setup[server_ids][]" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 3" title="Select Servers" multiple required>
                                        @foreach ($sendOptions['servers'] as $server)
                                            <option value="{{ $server['id'] }}" data-panel-url="{{ $server['pmta_panel_url'] }}" @selected(in_array((string) $server['id'], $setup['server_ids'], true))>{{ $server['name'] }} | {{ $server['ip'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="send-ips-selected"><span id="send-route-choice-heading">{{ match ($setup['route_option']) { 'smtp' => 'SMTP routes', 'vmta' => 'VMTAs', default => 'IPs' } }}</span>:
                                    <button type="button" class="btn btn-link btn-sm p-0" data-copy-ip-list="available" title="Copy available IPs" aria-label="Copy available IPs"><i class="fas fa-copy" aria-hidden="true"></i></button>
                                    <button type="button" class="btn btn-link btn-sm p-0" data-copy-ip-list="selected" title="Copy selected IPs" aria-label="Copy selected IPs"><i class="fas fa-copy" aria-hidden="true"></i></button>
                                </label>
                                <select id="send-ips" name="send_setup[ip_ids][]" class="sr-only" aria-hidden="true" tabindex="-1" multiple>
                                    @foreach ($sendOptions['ips'] as $ip)
                                        @php
                                            $routeChoiceLabel = match ($setup['route_option']) {
                                                'vmta' => implode(' | ', array_filter([$ip['ip'], $ip['dns'] ?? '', $ip['name'] ?? ''], static fn ($value) => filled($value))),
                                                'smtp' => implode(' | ', array_filter([$ip['ip'], $ip['username'] ?? '', $ip['name'] ?? '', $ip['dns'] ?? ''], static fn ($value) => filled($value))),
                                                default => implode(',', array_filter([$ip['ip'], $ip['dns'] ?? ''], static fn ($value) => filled($value))),
                                            };
                                        @endphp
                                        <option value="{{ $ip['id'] }}" data-route="{{ $setup['route_option'] }}" data-route-choice-id="{{ $ip['id'] }}" data-server-id="{{ $ip['server_id'] }}" data-managed-server-ip-id="{{ $ip['managed_server_ip_id'] ?? '' }}" data-pmta-name="{{ $ip['pmta_name'] ?? '' }}" data-username="{{ $ip['username'] ?? '' }}" data-name="{{ $ip['name'] ?? '' }}" data-ip="{{ $ip['ip'] }}" data-dns="{{ $ip['dns'] ?? '' }}" data-vmta-domain="{{ $ip['vmta_domain'] ?? '' }}" @selected(in_array((string) $ip['id'], $setup['ip_ids'], true))>{{ $routeChoiceLabel }}</option>
                                    @endforeach
                                </select>
                                <div class="row">
                                    <div class="col-md-5">
                                        <label class="small mb-1" for="send-ips-available"><span id="send-ips-available-label">Available {{ match ($setup['route_option']) { 'smtp' => 'SMTP routes', 'vmta' => 'VMTAs', default => 'IPs' } }}</span></label>
                                        <input class="form-control form-control-sm mb-1" id="send-ips-available-filter" type="search" placeholder="Filter available IPs" aria-label="Filter available IPs">
                                        <select id="send-ips-available" class="form-control" multiple size="10" aria-label="Available {{ match ($setup['route_option']) { 'smtp' => 'SMTP routes', 'vmta' => 'VMTAs', default => 'IPs' } }}" aria-describedby="send-ips-transfer-help"></select>
                                    </div>
                                    <div class="col-md-2 d-flex flex-column justify-content-center align-items-center">
                                        <button type="button" class="btn btn-outline-secondary btn-sm mb-1" data-ip-move="all-right" aria-label="Move all IPs to selected">»</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm mb-1" data-ip-move="right" aria-label="Move selected IPs to selected">›</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm mb-1" data-ip-move="left" aria-label="Move IPs back to available">‹</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-ip-move="all-left" aria-label="Move all IPs back to available">«</button>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="small mb-1" for="send-ips-selected"><span id="send-ips-selected-label">Selected {{ match ($setup['route_option']) { 'smtp' => 'SMTP routes', 'vmta' => 'VMTAs', default => 'IPs' } }}</span> (<span id="send-ips-selected-count">0</span>)</label>
                                        <input class="form-control form-control-sm mb-1" id="send-ips-selected-filter" type="search" placeholder="Filter selected IPs" aria-label="Filter selected IPs">
                                        <select id="send-ips-selected" class="form-control" multiple size="10" aria-label="Selected {{ match ($setup['route_option']) { 'smtp' => 'SMTP routes', 'vmta' => 'VMTAs', default => 'IPs' } }}" aria-describedby="send-ips-transfer-help"></select>
                                    </div>
                                </div>
                                <small id="send-ips-transfer-help" class="form-text text-muted">Double-click an entry to move it between lists. You can also select entries and use the arrow buttons.</small>
                                <small class="form-text text-muted">Campaign Save requires at least one selected IP, VMTA, or SMTP route. Test Campaign uses the saved route on this revision.</small>
                                <small class="form-text text-info">To use <code>[smtp]</code> or <code>[smtp_name]</code>, set Option to SMTP and select an SMTP route. IP routing selects the PMTA source IP and leaves those SMTP account tags empty. AlphaLink still submits the message to the selected server’s PMTA listener.</small>
                            </div>
                            <div id="campaign-pmta-panels" class="row" aria-live="polite">
                                @foreach ($sendOptions['servers'] as $server)
                                    @if (in_array((string) $server['id'], $setup['server_ids'], true))
                                        <div class="col-sm-6 col-lg-4"><a href="{{ $server['pmta_panel_url'] }}" target="_blank" rel="noopener noreferrer">Open PMTA panel — {{ $server['name'] }}:{{ $sendOptions['settings']['pmta_admin_port'] }}</a></div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                        <div class="col-sm-12 col-md-4">
                            <div class="row">
                                @if ($editingCampaign)
                                    @php
                                        $sendCount = \App\Models\CampaignRecipientSnapshot::query()->where('campaign_id', $editingCampaign->id)->max('recipient_count') ?? 0;
                                    @endphp
                                    <div class="form-group col-12">
                                        <label for="campaign-start-progress">Start From: <span class="text-info">(TOTAL: {{ number_format($sendCount) }})</span></label>
                                        <div class="input-group">
                                            <input id="campaign-start-progress" class="form-control" type="number" min="0" max="{{ $sendCount }}" value="{{ old('send_progress', $editingCampaign->send_progress ?? ($savedSetup['start_from'] ?? 0)) }}">
                                            <div class="input-group-append"><button type="button" class="btn btn-info" id="campaign-start-progress-change" data-progress-url="{{ route('console.campaigns.progress', [$workspace->id, $editingCampaign->id]) }}">CHANGE</button></div>
                                        </div>
                                        <small id="campaign-start-progress-status" class="form-text text-muted" role="status" aria-live="polite"></small>
                                    </div>
                                @endif
                                <div class="form-group col-6">
                                    <label for="send-type">Send Type:</label>
                                    <select id="send-type" name="send_setup[send_type]" class="form-control" required>
                                        @foreach (['delay' => 'DELAY', 'delayp' => 'DELAYP (parallel)', 'delayps' => 'DELAYPS (parallel)', 'hs' => 'HS', 'hsp' => 'HSP (parallel)', 'hsps' => 'HSPS (parallel by server)', 'pickup' => 'PICKUP (parallel)', 'pickups' => 'PICKUPS (parallel by server)'] as $value => $label)
                                            <option value="{{ $value }}" @selected($setup['send_type'] === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @foreach ([
                                    'duplicate_data' => ['Duplicate Data', 1],
                                    'total_send' => ['Total Send', 1],
                                    'fraction_ip' => ['Fraction IP', 1],
                                    'sleep_email' => ['Sleep Between Emails (ms)', 0],
                                    'sleep_ip' => ['Sleep Between IPs (ms)', 0],
                                    'seed_every' => ['Test After', 0],
                                    'bounce_limit' => ['Bounce Limit', 0],
                                ] as $field => [$label, $minimum])
                                    <div class="form-group col-6">
                                        <label for="send-{{ $field }}">{{ $label }}:</label>
                                        <input id="send-{{ $field }}" name="send_setup[{{ $field }}]" type="number" min="{{ $minimum }}" class="form-control" value="{{ $setup[$field] }}" required>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end mb-4">
            <span class="align-self-center text-muted small mr-3">{{ $editingCampaign ? 'Editing preserves the existing audience snapshot and returns to Campaigns.' : 'Saving filters and snapshots recipients. It does not send.' }}</span>
            <a class="btn btn-alt-secondary mr-2" href="{{ route('console.campaigns.index', $workspace->id) }}">Cancel</a>
            <button class="btn btn-primary" id="campaign-save-button" type="submit">{{ $editingCampaign ? 'Edit Campaign' : 'Create Campaign' }}</button>
            <a class="btn btn-primary ml-2" id="campaign-new-campaign" href="{{ route('console.campaigns.create', $workspace->id) }}" hidden>Start a new campaign</a>
        </div>
        <dialog id="campaign-encoding-dialog" data-encode-url="{{ route('console.campaign-tools.encode', $workspace->id) }}" aria-labelledby="campaign-encoding-title">
            <div class="p-3" style="min-width:min(720px,90vw)">
                <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h5 mb-0" id="campaign-encoding-title">Encoding</h3><button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close="campaign-encoding-dialog" aria-label="Close encoding tools">×</button></div>
                <ul class="nav nav-tabs" role="tablist"><li class="nav-item"><button type="button" class="nav-link active" data-encoding-tab="string">STRING</button></li><li class="nav-item"><button type="button" class="nav-link" data-encoding-tab="ip">IP</button></li></ul>
                <div class="pt-3" data-encoding-panel="string">
                    <div class="form-row"><div class="form-group col-md-6"><label for="campaign-encoding-text">Your String</label><input class="form-control" id="campaign-encoding-text"></div><div class="form-group col-md-3"><label for="campaign-encoding-schema">Schema</label><select class="form-control" id="campaign-encoding-schema"><option value="Q">Quoted-Printable</option><option value="HQ">Hex-Quoted-Printable</option><option value="B">Base64</option></select></div><div class="form-group col-md-3"><label for="campaign-encoding-charset">Charset</label><select class="form-control" id="campaign-encoding-charset"><option>UTF-8</option><option>UTF-7</option><option>ISO-8859-1</option><option>ISO-8859-15</option><option>windows-1252</option><option>US-ASCII</option><option>CP1256</option></select></div></div>
                    <button type="button" class="btn btn-primary mb-2" id="campaign-encoding-run">ENCODE</button><label for="campaign-encoding-result">Result</label><textarea class="form-control" id="campaign-encoding-result" rows="4" readonly></textarea>
                </div>
                <div class="pt-3" data-encoding-panel="ip" hidden><div class="form-group"><label for="campaign-encoding-ip">IP</label><input class="form-control" id="campaign-encoding-ip" placeholder="192.0.2.1"></div><button type="button" class="btn btn-primary mb-2" id="campaign-encoding-ip-run">ENCODE</button><label for="campaign-encoding-ip-result">Result</label><textarea class="form-control" id="campaign-encoding-ip-result" rows="7" readonly></textarea></div>
            </div>
        </dialog>
        <dialog id="campaign-shortlink-dialog" data-shortlink-url-template="{{ route('console.campaign-tools.shortlinks', [$workspace->id, '__PROVIDER__']) }}" aria-labelledby="campaign-shortlink-title">
            <div class="p-3" style="min-width:min(760px,90vw)">
                <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h5 mb-0" id="campaign-shortlink-title">SHORTLINKS</h3><button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close="campaign-shortlink-dialog" aria-label="Close short-link tools">×</button></div>
                <ul class="nav nav-tabs"><li class="nav-item"><button type="button" class="nav-link active" data-shortlink-provider="tinyurl">TINYURL</button></li><li class="nav-item"><button type="button" class="nav-link" data-shortlink-provider="isgd">ISGD</button></li></ul>
                <label class="mt-3" for="campaign-shortlink-input">LINKS:</label><textarea class="form-control" id="campaign-shortlink-input" rows="5" placeholder="One http or https link per line"></textarea>
                <button type="button" class="btn btn-primary btn-block mt-2" id="campaign-shortlink-generate">GENERATE LINKS</button>
                <div id="campaign-shortlink-status" class="small mt-2" role="status" aria-live="polite"></div>
                <div class="table-responsive mt-2"><table class="table table-striped table-sm"><thead><tr><th>LINK</th><th>SHORTLINK</th></tr></thead><tbody id="campaign-shortlink-results"></tbody></table></div>
            </div>
        </dialog>
        <dialog id="campaign-tags-dialog" data-campaign-dialog-width="1140px" style="box-sizing:border-box;border:0;border-radius:.35rem;padding:0;max-width:92vw;max-height:90vh;overflow:auto;overflow-x:hidden;width:min(1140px,92vw)" aria-labelledby="campaign-tags-title">
            <div class="p-3" style="box-sizing:border-box;width:100%">
                <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h5 mb-0" id="campaign-tags-title">TAGS</h3><button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close="campaign-tags-dialog" aria-label="Close tag examples">×</button></div>
                <div class="row"><div class="col-md-6">
                    <h4 class="h6">Message and server tags</h4>
                    <p><code>[ip]</code> IP · <code>[server]</code> Server name · <code>[main_domain]</code> Server domain · <code>[domain]</code> IP domain with TLD · <code>[domain_sld]</code> IP domain without the final TLD · <code>[rdns]</code> IP reverse DNS</p>
                    <p><code>[from]</code> Random From · <code>[subject]</code> Random Subject · <code>[ids]</code> Server ID · <code>[idd]</code> Domain ID · <code>[idi]</code> IP ID · <code>[ide]</code> Email ID · <code>[to]</code> Destination email · <code>[negative]</code> Negative file · <code>[name]</code> Email username · <code>[date]</code> Current date</p>
                    <p><code>[rnd]</code> 18 random characters · <code>[text:a|b|c]</code> Choose one value · <code>[base64:text]</code> Base64 encode · <code>[printable:text]</code> Quoted-printable encode</p>
                    <p><code>[boundary]</code> 30 static characters · <code>[bnd]</code> 30 static characters · <code>[smtp]</code> SMTP username · <code>[smtp_name]</code> SMTP name · <code>[tag]</code> Tag reply extractor · <code>#[token]</code> Track redirect/unsubscribe short links</p>
                    <h4 class="h6">Random tags</h4>
                    <p><code>[rnd]</code> generates 18 random characters. Replace <code>N</code> in the other tags with the desired length:</p>
                    <ul class="mb-3">
                        <li><code>[rndn_N]</code> digits (0–9)</li>
                        <li><code>[rnda_N]</code> letters and digits</li>
                        <li><code>[rndl_N]</code> lowercase letters · <code>[rndu_N]</code> uppercase letters</li>
                        <li><code>[rnds_N]</code> symbols · <code>[rndlu_N]</code> lowercase and uppercase letters</li>
                        <li><code>[rndln_N]</code> lowercase letters and digits · <code>[rndun_N]</code> uppercase letters and digits</li>
                    </ul>
                    <h4 class="h6">Language random tags</h4>
                    <ul class="mb-0">
                        <li><code>[Ingreek_N]</code> Greek · <code>[Ingemojicons_N]</code> / <code>[Ingemoticons_N]</code> emoticons</li>
                        <li><code>[Ingdevanagari_N]</code> Devanagari · <code>[Ingthai_N]</code> Thai · <code>[Ingarabic_N]</code> Arabic</li>
                        <li><code>[Inglatin_N]</code> Latin A–Z/a–z · <code>[Ingchinese_N]</code> CJK ideographs</li>
                        <li><code>[Inghiragana_N]</code> Hiragana · <code>[Ingcyrrilic_N]</code> Cyrillic</li>
                        <li><code>[Ingarmenian_N]</code> Armenian · <code>[Inggeorgian_N]</code> Georgian</li>
                    </ul>
                </div><div class="col-md-6">
                    <h4 class="h6">Boundary tags</h4>
                    <p>Replace <code>N</code> with the desired fixed length:</p>
                    <ul class="mb-3">
                        <li><code>[bndn_N]</code> digits (0–9)</li>
                        <li><code>[bnda_N]</code> letters and digits</li>
                        <li><code>[bndl_N]</code> lowercase letters · <code>[bndu_N]</code> uppercase letters</li>
                        <li><code>[bnds_N]</code> symbols · <code>[bndlu_N]</code> lowercase and uppercase letters</li>
                        <li><code>[bndln_N]</code> lowercase letters and digits · <code>[bndun_N]</code> uppercase letters and digits</li>
                    </ul>
                    <h4 class="h6">Placeholders</h4>
                    <p><code>[placeholder1]</code><br><code>[placeholder2]</code><br>Continue with the row number. Values follow the corresponding Placeholders row and its rotation interval.</p>
                    <h4 class="h6">Campaign Test behavior</h4>
                    <p>The DMS help describes <code>[boundary]</code> and <code>[bnd]</code> as 30 static characters. The inspected DMS PHP helper receives <code>[boundary]</code> from its caller and defaults <code>[bnd]</code> to an 18-character cached value; AlphaLink preserves the inspected helper behavior.</p>
                    <p>Campaign Test resolves <code>[smtp]</code> and <code>[smtp_name]</code> from a selected SMTP route; they are empty for IP/VMTA routes. <code>[tag]</code> is replaced with an empty value because the DMS native sender's reply-tag source is not available in this test path.</p>
                    <p>Campaign Test replaces <code>[token]</code> with a test token; the complete DMS native sender's redirect/unsubscribe URL expansion is not available in AlphaLink's current test path. Campaign Test does not require sender-domain DNS verification.</p>
                    <p class="mb-0">For a selected VMTA, AlphaLink resolves <code>[domain]</code> to its pushed domain and applies that domain to the Domain field and body URL hosts. With multiple selected VMTAs, Campaign Test uses the selected server's own VMTA domain.</p>
                </div></div>
            </div>
        </dialog>
        <dialog id="campaign-server-search-dialog" aria-labelledby="campaign-server-search-title">
            <div class="p-3" style="min-width:min(520px,90vw)">
                <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h5 mb-0" id="campaign-server-search-title">Search Servers</h3><button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close="campaign-server-search-dialog" aria-label="Close server search">×</button></div>
                <label for="campaign-server-search-input">Server names or IP addresses, one per line</label>
                <textarea class="form-control" id="campaign-server-search-input" rows="7" placeholder="Server name or IP address"></textarea>
                <div class="d-flex justify-content-end mt-3"><button type="button" class="btn btn-alt-secondary mr-2" data-dialog-close="campaign-server-search-dialog">Cancel</button><button type="button" class="btn btn-primary" id="campaign-server-search-apply">Select Servers</button></div>
            </div>
        </dialog>
        </div>
    </form>

    <div id="campaign-options-status" class="alert d-none" role="status" aria-live="polite"></div>
    <button type="button" id="campaign-options-retry" class="btn btn-alt-secondary d-none mb-4">Retry loading choices</button>
@endsection

@push('scripts')
    <script src="{{ asset('alphalink/campaign-header-library.js') }}" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const container = document.getElementById('campaign-saved-emails');
            const saved = document.getElementById('saved-email-addresses');
            const manager = document.getElementById('saved-email-manager');
            const status = document.getElementById('saved-email-status');
            const address = document.getElementById('saved-email-new');
            const tests = document.getElementById('campaign-email-tests');
            const csrf = document.querySelector('#campaign-create-form input[name="_token"]')?.value;
            const refresh = () => { if (window.jQuery?.fn.selectpicker) window.jQuery(saved).selectpicker('refresh'); };
            saved.addEventListener('change', () => { tests.value = Array.from(saved.selectedOptions).map(option => option.value).join('\n'); });
            document.getElementById('saved-email-manage').addEventListener('click', () => { manager.hidden = !manager.hidden; if (!manager.hidden) address.focus(); });
            document.getElementById('saved-email-add').addEventListener('click', async () => {
                if (!address.value.trim()) { status.textContent = 'Enter an email address.'; return; }
                const response = await fetch(container.dataset.storeUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({email: address.value.trim()}) });
                if (!response.ok) { status.textContent = 'Could not save the address. Check its format and try again.'; return; }
                const result = await response.json();
                const option = new Option(result.data.email, result.data.email);
                option.dataset.addressId = result.data.id;
                saved.add(option); refresh(); address.value = ''; status.textContent = 'Address saved.';
            });
            document.getElementById('saved-email-remove').addEventListener('click', async () => {
                const selected = Array.from(saved.selectedOptions);
                if (!selected.length) { status.textContent = 'Select a saved address to delete.'; return; }
                for (const option of selected) {
                    const url = container.dataset.deleteUrlTemplate.replace('__ADDRESS_ID__', option.dataset.addressId);
                    const response = await fetch(url, { method: 'DELETE', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf } });
                    if (!response.ok) { status.textContent = 'Could not delete the selected address.'; return; }
                    option.remove();
                }
                refresh(); tests.value = Array.from(saved.selectedOptions).map(option => option.value).join('\n'); status.textContent = 'Selected addresses deleted.';
            });
        });
    </script>
    <script>
        (() => {
            const form = document.getElementById('campaign-create-form');
            if (!form) return;
            const trigger = document.getElementById('campaign-actions-trigger');
            const floatingPanel = document.getElementById('campaign-actions-panel');
            const contextPanel = document.getElementById('campaign-context-menu');
            const backdrop = document.getElementById('campaign-action-backdrop');
            const actionStatus = document.getElementById('campaign-action-status');
            const servers = document.getElementById('send-servers');
            const floatingActions = document.getElementById('campaign-floating-actions');
            const contextArea = document.getElementById('campaign-data');
            let contextTarget = null;
            const closeMenus = () => {
                floatingPanel.hidden = true;
                contextPanel.hidden = true;
                backdrop.hidden = true;
                trigger.setAttribute('aria-expanded', 'false');
                trigger.setAttribute('aria-label', 'Open campaign actions');
                trigger.querySelector('i')?.classList.replace('fa-times', 'fa-bars');
            };
            const contextFocusTarget = (target) => {
                const focusableTarget = target instanceof Element
                    ? target.closest('a[href], button:not([disabled]):not([aria-disabled="true"]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [contenteditable="true"], [tabindex]:not([tabindex="-1"])')
                    : null;

                return focusableTarget ?? contextArea;
            };
            const openFloatingMenu = () => {
                actionStatus.hidden = true;
                contextPanel.hidden = true;
                floatingPanel.hidden = false;
                backdrop.hidden = false;
                trigger.setAttribute('aria-expanded', 'true');
                trigger.setAttribute('aria-label', 'Close campaign actions');
                trigger.querySelector('i')?.classList.replace('fa-bars', 'fa-times');
                const viewportHeight = document.documentElement.clientHeight || window.innerHeight;
                const triggerBottom = trigger.getBoundingClientRect().bottom;
                const panelHeight = floatingPanel.scrollHeight;
                floatingPanel.style.top = `${Math.max(4, Math.min(triggerBottom + 6, viewportHeight - panelHeight - 4))}px`;
                floatingPanel.querySelector('[role="menuitem"]')?.focus();
            };
            const openContextMenu = (event) => {
                closeMenus();
                actionStatus.hidden = true;
                contextTarget = contextFocusTarget(event.target);
                contextPanel.hidden = false;
                const bounds = contextPanel.getBoundingClientRect();
                const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
                const viewportHeight = document.documentElement.clientHeight || window.innerHeight;
                contextPanel.style.left = `${Math.max(4, Math.min(event.clientX, viewportWidth - bounds.width - 4))}px`;
                contextPanel.style.top = `${Math.max(4, Math.min(event.clientY, viewportHeight - bounds.height - 4))}px`;
                contextPanel.querySelector('[role="menuitem"]')?.focus();
            };
            const announce = (message, tone = 'info') => {
                actionStatus.className = `alert alert-${tone} mb-0`;
                actionStatus.textContent = message;
                actionStatus.hidden = false;
            };
            const testLogLink = document.getElementById('campaign-test-log-link');
            const runCampaignTest = async (item) => {
                closeMenus();
                const testItems = Array.from(document.querySelectorAll('[data-campaign-menu-action="test"]'));
                const originalMarkup = item.innerHTML;
                testItems.forEach((button) => {
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                });
                item.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i><span class="sr-only">Testing campaign</span>';
                announce('Campaign Test is running. This page will stay open while PMTA responds.', 'info');
                actionStatus.scrollIntoView({ block: 'nearest' });
                if (testLogLink) testLogLink.hidden = true;

                try {
                    const formData = new FormData(form);
                    const response = await fetch(item.dataset.testUrl, {
                        method: 'POST',
                        body: formData,
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    const result = await response.json().catch(() => ({}));
                    if (result.next_idempotency_key) {
                        const idempotencyInput = form.querySelector('input[name="idempotency_key"]');
                        if (idempotencyInput) idempotencyInput.value = result.next_idempotency_key;
                    }
                    if (!response.ok) {
                        const validationError = Object.values(result.errors || {}).flat()[0];
                        const message = result.data || result.message || validationError || 'Campaign Test failed.';
                        announce(message, result.outcome === 'outcome_unknown' || result.outcome === 'partially_accepted' ? 'warning' : 'danger');
                        window.AlphaLinkToast?.show(message, 'danger');
                    } else {
                        const message = result.data || 'Campaign Test completed.';
                        const accepted = result.outcome === 'accepted_by_transport';
                        announce(accepted ? `${message} The attempt is saved in Campaign test logs.` : message, accepted ? 'success' : 'warning');
                        window.AlphaLinkToast?.show(message, accepted ? 'success' : 'warning');
                    }
                    if (testLogLink && result.attempt_id) testLogLink.hidden = false;
                } catch (error) {
                    const message = error instanceof Error ? error.message : 'AlphaLink could not complete the campaign test request.';
                    announce(message, 'danger');
                    window.AlphaLinkToast?.show(message, 'danger');
                } finally {
                    testItems.forEach((button) => {
                        button.disabled = false;
                        button.removeAttribute('aria-busy');
                    });
                    item.innerHTML = originalMarkup;
                }
            };
            const selectedServerOptions = () => Array.from(servers?.selectedOptions ?? []).filter((option) => option.value);
            const activateAction = (action) => {
                closeMenus();
                actionStatus.hidden = true;
                if (action === 'create' || action === 'edit') {
                    form.requestSubmit(document.getElementById('campaign-save-button'));
                    return;
                }
                if (action === 'create-send') {
                    announce('Create & Send is unavailable until the DMS native campaign sender, validation and send lifecycle are implemented and tested.', 'warning');
                    return;
                }
                const selected = selectedServerOptions();
                if (selected.length === 0) {
                    announce('Select at least one server first.', 'warning');
                    servers?.focus();
                    return;
                }
                if (action === 'pmta-admin') {
                    const query = new URLSearchParams();
                    selected.forEach((option) => query.append('server_ids[]', option.value));
                    const opened = window.open(`${floatingActions.dataset.pmtaAdminUrl}?${query.toString()}`, '_blank');
                    if (opened) opened.opener = null;
                    if (!opened) announce('The PMTA Admin page was blocked by the browser. Allow pop-ups for AlphaLink.', 'warning');
                    return;
                }
                if (action === 'pmta-panel') {
                    let opened = 0;
                    selected.forEach((option) => {
                        if (option.dataset.panelUrl) {
                            const panel = window.open(option.dataset.panelUrl, '_blank');
                            if (panel) {
                                panel.opener = null;
                                opened++;
                            }
                        }
                    });
                    if (opened === 0) announce('No PMTA panel URL is available for the selected servers.', 'warning');
                }
            };

            trigger.addEventListener('click', () => floatingPanel.hidden ? openFloatingMenu() : closeMenus());
            backdrop.addEventListener('click', closeMenus);
            contextArea.addEventListener('contextmenu', (event) => {
                event.preventDefault();
                openContextMenu(event);
            });
            document.addEventListener('click', (event) => {
                if (!contextPanel.hidden && !contextPanel.contains(event.target)) closeMenus();
            });
            document.addEventListener('keydown', (event) => {
                const activeMenu = floatingPanel.hidden ? (contextPanel.hidden ? null : contextPanel) : floatingPanel;
                if (event.key === 'Escape' && activeMenu) {
                    const returnFocusTarget = activeMenu === contextPanel ? contextTarget : trigger;
                    closeMenus();
                    returnFocusTarget?.focus({preventScroll: true});
                    return;
                }
                if (activeMenu && ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
                    const items = Array.from(activeMenu.querySelectorAll('[role="menuitem"]'));
                    const currentIndex = items.indexOf(document.activeElement);
                    const nextIndex = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (currentIndex + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
                    event.preventDefault();
                    items[nextIndex]?.focus();
                }
            });
            document.querySelectorAll('[data-campaign-menu-action]').forEach((item) => {
                item.addEventListener('click', (event) => {
                    if (item.dataset.campaignMenuAction === 'test') {
                        event.preventDefault();
                        event.stopPropagation();
                        void runCampaignTest(item);
                        return;
                    }
                    activateAction(item.dataset.campaignMenuAction);
                });
            });
            const previewButton = document.getElementById('campaign-body-preview-button');
            const preview = document.getElementById('campaign-body-preview');
            const header = document.getElementById('campaign-dms-header');
            const body = document.getElementById('campaign-dms-body');
            const globalStatus = document.getElementById('campaign-options-status');
            const csrf = form.querySelector('input[name="_token"]')?.value ?? '';
            const setToolStatus = (message, tone = 'info') => {
                if (!globalStatus) return;
                globalStatus.className = message ? `alert alert-${tone}` : 'alert d-none';
                globalStatus.textContent = message;
            };
            const closeDialog = (dialog) => {
                if (!dialog) return;
                const fallback = dialog.dataset.campaignDialogFallback === 'true';
                if (fallback) {
                    dialog.removeAttribute('open');
                    dialog.removeAttribute('aria-modal');
                    dialog.removeAttribute('style');
                    dialog.removeAttribute('data-campaign-dialog-fallback');
                    document.querySelectorAll('[data-campaign-dialog-backdrop]').forEach((backdrop) => {
                        if (backdrop.dataset.campaignDialogBackdrop === dialog.id) backdrop.remove();
                    });
                } else if (typeof dialog.close === 'function' && dialog.open) {
                    dialog.close();
                }
                const openerId = dialog.dataset.openerId;
                if (openerId) document.getElementById(openerId)?.focus();
            };
            const showDialog = (dialogId) => {
                const dialog = document.getElementById(dialogId);
                if (!dialog) return;
                dialog.dataset.openerId = document.activeElement?.id ?? '';
                if (typeof dialog.showModal === 'function') {
                    dialog.showModal();
                    return;
                }

                const backdrop = document.createElement('div');
                backdrop.dataset.campaignDialogBackdrop = dialogId;
                backdrop.setAttribute('aria-hidden', 'true');
                Object.assign(backdrop.style, {
                    background: 'rgb(52 58 64 / 45%)',
                    inset: '0',
                    position: 'fixed',
                    zIndex: '1050',
                });
                backdrop.addEventListener('click', () => closeDialog(dialog));
                document.body.append(backdrop);
                dialog.dataset.campaignDialogFallback = 'true';
                dialog.setAttribute('open', '');
                dialog.setAttribute('aria-modal', 'true');
                Object.assign(dialog.style, {
                    background: 'white',
                    border: '0',
                    borderRadius: '.25rem',
                    boxShadow: '0 8px 28px rgb(52 58 64 / 22%)',
                    display: 'block',
                    left: '50%',
                    margin: '0',
                    maxHeight: '90vh',
                    maxWidth: '90vw',
                    overflow: 'auto',
                    position: 'fixed',
                    top: '50%',
                    transform: 'translate(-50%, -50%)',
                    width: dialog.dataset.campaignDialogWidth || '760px',
                    zIndex: '1051',
                });
                dialog.querySelector('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])')?.focus();
            };
            document.addEventListener('keydown', (event) => {
                const dialog = Array.from(document.querySelectorAll('dialog[open]')).find((candidate) => candidate.dataset.campaignDialogFallback === 'true');
                if (!dialog) return;
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeDialog(dialog);
                    return;
                }
                if (event.key !== 'Tab') return;
                const focusable = Array.from(dialog.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [href], [tabindex]:not([tabindex="-1"])'))
                    .filter((element) => !element.hidden && element.getAttribute('aria-hidden') !== 'true');
                if (!focusable.length) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            });
            const copyText = async (text) => {
                if (!text) return false;
                try {
                    await navigator.clipboard.writeText(text);
                    return true;
                } catch {
                    const temporary = document.createElement('textarea');
                    temporary.value = text;
                    temporary.style.position = 'fixed';
                    temporary.style.opacity = '0';
                    document.body.append(temporary);
                    temporary.select();
                    const copied = document.execCommand('copy');
                    temporary.remove();
                    return copied;
                }
            };
            previewButton.addEventListener('click', () => {
                preview.hidden = !preview.hidden;
                previewButton.setAttribute('aria-expanded', String(!preview.hidden));
                if (!preview.hidden) document.getElementById('campaign-body-preview-frame').srcdoc = body.value;
            });
            document.getElementById('campaign-header-shuffle').addEventListener('click', () => {
                const lines = header.value.replace(/\r\n/g, '\n').split('\n');
                for (let index = lines.length - 1; index > 0; index--) {
                    const swap = Math.floor(Math.random() * (index + 1));
                    [lines[index], lines[swap]] = [lines[swap], lines[index]];
                }
                header.value = lines.join('\n');
                setToolStatus('Header lines shuffled.');
            });
            document.getElementById('campaign-header-encode').addEventListener('click', () => showDialog('campaign-encoding-dialog'));
            document.getElementById('campaign-shortlink-open').addEventListener('click', () => showDialog('campaign-shortlink-dialog'));
            document.getElementById('campaign-tags-help').addEventListener('click', () => showDialog('campaign-tags-dialog'));
            document.querySelectorAll('[data-dialog-close]').forEach((button) => {
                button.addEventListener('click', () => closeDialog(document.getElementById(button.dataset.dialogClose)));
            });
            document.querySelectorAll('[data-copy-selected]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const values = Array.from(document.getElementById(button.dataset.copySelected).selectedOptions).map((option) => option.textContent.trim());
                    setToolStatus(await copyText(values.join('\n')) ? 'Selected values copied.' : 'Select at least one value to copy.', values.length ? 'success' : 'warning');
                });
            });
            document.querySelectorAll('[data-encoding-tab]').forEach((button) => {
                button.addEventListener('click', () => {
                    document.querySelectorAll('[data-encoding-tab]').forEach((tab) => tab.classList.toggle('active', tab === button));
                    document.querySelectorAll('[data-encoding-panel]').forEach((panel) => { panel.hidden = panel.dataset.encodingPanel !== button.dataset.encodingTab; });
                });
            });
            document.getElementById('campaign-encoding-run').addEventListener('click', async () => {
                const dialog = document.getElementById('campaign-encoding-dialog');
                const response = await fetch(dialog.dataset.encodeUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                    body: JSON.stringify({string: document.getElementById('campaign-encoding-text').value, schema: document.getElementById('campaign-encoding-schema').value, charset: document.getElementById('campaign-encoding-charset').value}),
                });
                if (!response.ok) { document.getElementById('campaign-encoding-result').value = 'Encoding failed. Check the text and selected charset.'; return; }
                document.getElementById('campaign-encoding-result').value = await response.json();
            });
            document.getElementById('campaign-encoding-ip-run').addEventListener('click', () => {
                const input = document.getElementById('campaign-encoding-ip').value.trim();
                const octets = input.split('.').map(Number);
                if (octets.length !== 4 || octets.some((part, index) => !/^\d+$/.test(input.split('.')[index]) || part < 0 || part > 255)) {
                    document.getElementById('campaign-encoding-ip-result').value = 'Enter a valid IPv4 address.';
                    return;
                }
                const pad = (part, radix, width) => part.toString(radix).padStart(width, '0');
                const integer = octets.reduce((value, part) => value * 256 + part, 0);
                const hex = octets.map((part) => pad(part, 16, 2)).join('').toUpperCase();
                document.getElementById('campaign-encoding-ip-result').value = [
                    `Dotted Decimal IP : ${input}`,
                    `Octal IP : ${octets.map((part) => pad(part, 8, 4)).join('.')}`,
                    `Integer IP : ${integer}`,
                    `Hexadecimal IP : 0x${hex}`,
                    `OctalHexa IP : ${pad(octets[0], 8, 4)}.${pad(octets[1], 8, 4)}.0x${pad(octets[2], 16, 2).toUpperCase()}${pad(octets[3], 16, 2).toUpperCase()}`,
                    `DecimalHexa IP : ${octets[0]}.${octets[1]}.0x${pad(octets[2], 16, 2).toUpperCase()}${pad(octets[3], 16, 2).toUpperCase()}`,
                    `DecimalOctal IP : ${octets[0]}.${octets[1]}.${pad(octets[2], 8, 4)}.${pad(octets[3], 8, 4)}`,
                ].join('\n');
            });
            let shortlinkProvider = 'tinyurl';
            document.querySelectorAll('[data-shortlink-provider]').forEach((button) => {
                button.addEventListener('click', () => {
                    shortlinkProvider = button.dataset.shortlinkProvider;
                    document.querySelectorAll('[data-shortlink-provider]').forEach((tab) => tab.classList.toggle('active', tab === button));
                });
            });
            document.getElementById('campaign-shortlink-generate').addEventListener('click', async () => {
                const dialog = document.getElementById('campaign-shortlink-dialog');
                const links = [...new Set(document.getElementById('campaign-shortlink-input').value.split(/\r?\n/).map((link) => link.trim()).filter(Boolean))];
                const status = document.getElementById('campaign-shortlink-status');
                const results = document.getElementById('campaign-shortlink-results');
                results.replaceChildren();
                if (!links.length) { status.textContent = 'Enter at least one link.'; return; }
                status.textContent = 'Generating links…';
                const url = dialog.dataset.shortlinkUrlTemplate.replace('__PROVIDER__', shortlinkProvider);
                const response = await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify({links})});
                const data = await response.json().catch(() => ({}));
                if (!response.ok) { status.textContent = data.message || 'The short-link provider request failed.'; return; }
                data.forEach((result) => {
                    const row = document.createElement('tr');
                    [result.link, result.shortlink].forEach((value) => { const cell = document.createElement('td'); cell.textContent = value; row.append(cell); });
                    results.append(row);
                });
                status.textContent = `${data.length} short link${data.length === 1 ? '' : 's'} created.`;
            });
            const ipSource = document.getElementById('send-ips');
            const availableIps = document.getElementById('send-ips-available');
            const chosenIps = document.getElementById('send-ips-selected');
            const routeChoiceNames = {ip: 'IPs', vmta: 'VMTAs', smtp: 'SMTP routes'};
            const syncRouteChoiceLabels = () => {
                const route = document.getElementById('send-route')?.value || 'ip';
                const routeName = routeChoiceNames[route] || 'IPs';
                document.getElementById('send-route-choice-heading').textContent = routeName;
                document.getElementById('send-ips-available-label').textContent = `Available ${routeName}`;
                document.getElementById('send-ips-selected-label').textContent = `Selected ${routeName}`;
                availableIps.setAttribute('aria-label', `Available ${routeName}`);
                chosenIps.setAttribute('aria-label', `Selected ${routeName}`);
                document.getElementById('send-ips-available-filter').placeholder = `Filter available ${routeName.toLowerCase()}`;
                document.getElementById('send-ips-selected-filter').placeholder = `Filter selected ${routeName.toLowerCase()}`;
                const moveLabels = {
                    'all-right': `Move all ${routeName} to selected`,
                    right: `Move selected ${routeName} to selected`,
                    left: `Move selected ${routeName} back to available`,
                    'all-left': `Move all ${routeName} back to available`,
                };
                document.querySelectorAll('[data-ip-move]').forEach((button) => {
                    button.setAttribute('aria-label', moveLabels[button.dataset.ipMove]);
                });
            };
            const renderIpLists = () => {
                if (!ipSource || !availableIps || !chosenIps) return;
                const selectedIds = new Set(Array.from(ipSource.options).filter((option) => option.selected).map((option) => option.value));
                const availableFilter = document.getElementById('send-ips-available-filter').value.toLowerCase();
                const chosenFilter = document.getElementById('send-ips-selected-filter').value.toLowerCase();
                availableIps.replaceChildren();
                chosenIps.replaceChildren();
                Array.from(ipSource.options).forEach((source) => {
                    const visibleText = source.textContent.toLowerCase();
                    const target = selectedIds.has(source.value) ? chosenIps : availableIps;
                    const filter = target === chosenIps ? chosenFilter : availableFilter;
                    if (filter && !visibleText.includes(filter)) return;
                    target.add(new Option(source.textContent, source.value));
                });
                document.getElementById('send-ips-selected-count').textContent = String(selectedIds.size);
            };
            const setIpSelection = (mode) => {
                const selectedIds = new Set(Array.from(ipSource.options).filter((option) => option.selected).map((option) => option.value));
                const move = (options, toSelected) => options.forEach((option) => toSelected ? selectedIds.add(option.value) : selectedIds.delete(option.value));
                if (mode === 'right') move(Array.from(availableIps.selectedOptions), true);
                if (mode === 'left') move(Array.from(chosenIps.selectedOptions), false);
                if (mode === 'all-right') move(Array.from(ipSource.options).filter((option) => !selectedIds.has(option.value)), true);
                if (mode === 'all-left') selectedIds.clear();
                Array.from(ipSource.options).forEach((option) => { option.selected = selectedIds.has(option.value); });
                renderIpLists();
                ipSource.dispatchEvent(new Event('change', {bubbles: true}));
            };
            document.querySelectorAll('[data-ip-move]').forEach((button) => button.addEventListener('click', () => setIpSelection(button.dataset.ipMove)));
            availableIps.addEventListener('dblclick', () => setIpSelection('right'));
            chosenIps.addEventListener('dblclick', () => setIpSelection('left'));
            document.getElementById('send-ips-available-filter').addEventListener('input', renderIpLists);
            document.getElementById('send-ips-selected-filter').addEventListener('input', renderIpLists);
            document.getElementById('send-route')?.addEventListener('change', syncRouteChoiceLabels);
            syncRouteChoiceLabels();
            document.querySelectorAll('[data-copy-ip-list]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const selectedIds = new Set(Array.from(ipSource.options).filter((option) => option.selected).map((option) => option.value));
                    const options = Array.from(ipSource.options).filter((option) => button.dataset.copyIpList === 'selected' ? selectedIds.has(option.value) : !selectedIds.has(option.value));
                    setToolStatus(await copyText(options.map((option) => option.dataset.ip || option.textContent.split(/[|,]/)[0]).join('\n')) ? 'IP addresses copied.' : 'There are no IP addresses to copy.', options.length ? 'success' : 'warning');
                });
            });
            renderIpLists();
            const progressButton = document.getElementById('campaign-start-progress-change');
            progressButton?.addEventListener('click', async () => {
                const status = document.getElementById('campaign-start-progress-status');
                status.textContent = 'Saving progress…';
                const response = await fetch(progressButton.dataset.progressUrl, {
                    method: 'PUT', credentials: 'same-origin',
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                    body: JSON.stringify({send_progress: document.getElementById('campaign-start-progress').value}),
                });
                const result = await response.json().catch(() => ({}));
                status.textContent = response.ok ? `Progress updated (${result.send_progress} of ${result.send_count}).` : (result.message || 'Progress could not be updated. Check the maximum shown above.');
            });
            document.getElementById('campaign-servers-search').addEventListener('click', () => {
                showDialog('campaign-server-search-dialog');
            });
            document.getElementById('campaign-server-search-apply').addEventListener('click', () => {
                const queries = [...new Set(document.getElementById('campaign-server-search-input').value.split(/\r?\n/).map((query) => query.trim()).filter(Boolean))];
                if (!queries.length) {
                    closeDialog(document.getElementById('campaign-server-search-dialog'));
                    return;
                }
                const serverSelect = document.getElementById('send-servers');
                const matches = new Set();
                queries.forEach((query) => {
                    const normalized = query.toLowerCase();
                    Array.from(serverSelect.options).forEach((option) => {
                        const [serverName = '', ...ipParts] = option.textContent.split('|');
                        const ipAddress = ipParts.join('|').trim();
                        if (serverName.trim().toLowerCase() === normalized || ipAddress.includes(query)) {
                            matches.add(option);
                        }
                    });
                });
                Array.from(serverSelect.options).forEach((option) => { option.selected = matches.has(option); });
                serverSelect.dispatchEvent(new Event('change', {bubbles: true}));
                closeDialog(document.getElementById('campaign-server-search-dialog'));
                if (!matches.size) { setToolStatus('No matching servers found.', 'warning'); return; }
                setToolStatus(`${matches.size} matching server${matches.size === 1 ? '' : 's'} selected.`);
            });
            document.getElementById('campaign-body-generate-links')?.addEventListener('click', async (event) => {
                const button = event.currentTarget;
                if (!body.value.trim()) { setToolStatus('Body is empty.', 'warning'); return; }
                button.disabled = true;
                try {
                    const response = await fetch(button.dataset.generateUrl, {
                        method: 'POST', credentials: 'same-origin',
                        headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                        body: JSON.stringify({body: body.value}),
                    });
                    const result = await response.json().catch(() => ({}));
                    if (!response.ok) { setToolStatus(result.message || 'Tracking links could not be generated.', 'danger'); return; }
                    body.value = result.data;
                    setToolStatus('Tracking token and random tags generated for this campaign.');
                } finally {
                    button.disabled = false;
                }
            });
            const campaignDomainField = document.getElementById('campaign-domain');
            const campaignRouteField = document.getElementById('send-route');
            const campaignIpsField = document.getElementById('send-ips');
            const campaignDomainHint = document.getElementById('campaign-domain-route-hint');
            const selectedCampaignDomain = () => campaignDomainField.value || '[domain]';
            const refreshCampaignDomainOptions = (records) => {
                const selected = campaignDomainField.value;
                const domains = new Map((records || []).map((record) => [String(record.value), record]));
                campaignDomainField.replaceChildren(new Option('Select Domain', ''));
                domains.forEach((record, value) => campaignDomainField.add(new Option(`${record.name} — ${record.dns}`, value)));
                campaignDomainField.value = domains.has(selected) ? selected : '';
                if (window.jQuery?.fn.selectpicker) window.jQuery(campaignDomainField).selectpicker('refresh');
            };
            const applyCampaignDomainToBody = (domain) => {
                body.value = body.value.replace(/(https?:\/\/)[^/\s"'<>]+/gi, `$1${domain}`);
            };
            const syncCampaignDomainToRoute = () => {
                const route = campaignRouteField?.value;
                const selectedChoices = Array.from(campaignIpsField?.selectedOptions ?? []);
                if (route === 'vmta') {
                    const selectedServerIds = new Set(Array.from(document.getElementById('send-servers')?.selectedOptions ?? []).map((option) => String(option.value)));
                    const vmtaChoices = selectedChoices.length
                        ? selectedChoices
                        : Array.from(campaignIpsField?.options ?? []).filter((option) => option.dataset.route === 'vmta' && selectedServerIds.has(String(option.dataset.serverId)));
                    const domain = String(vmtaChoices[0]?.dataset.vmtaDomain || '').trim();
                    if (!domain) {
                        campaignDomainField.value = '';
                        if (window.jQuery?.fn.selectpicker) window.jQuery(campaignDomainField).selectpicker('refresh');
                        campaignDomainHint.hidden = false;
                        campaignDomainHint.textContent = vmtaChoices.length
                            ? 'The selected VMTA has no configured domain. Choose or push a VMTA with a domain before saving.'
                            : 'Select a VMTA for the chosen server to use its configured domain.';
                        applyCampaignDomainToBody('[domain]');
                        return;
                    }
                    campaignDomainHint.hidden = false;
                    campaignDomainHint.textContent = vmtaChoices.some((option) => option.dataset.vmtaDomain !== domain)
                        ? `Showing ${domain}; Campaign Test resolves [domain] from each selected VMTA.`
                        : `Using the selected VMTA domain ${domain}.`;
                    if (!Array.from(campaignDomainField.options).some((option) => option.value === domain)) {
                        campaignDomainField.add(new Option(`VMTA — ${domain}`, domain));
                    }
                    campaignDomainField.value = domain;
                    if (window.jQuery?.fn.selectpicker) window.jQuery(campaignDomainField).selectpicker('refresh');
                    applyCampaignDomainToBody(domain);
                    return;
                }
                campaignDomainHint.hidden = true;
                if (route === 'ip') {
                    const domain = selectedChoices.find((option) => option.dataset.dns)?.dataset.dns || '';
                    if (domain && Array.from(campaignDomainField.options).some((option) => option.value === domain)) {
                        campaignDomainField.value = domain;
                        if (window.jQuery?.fn.selectpicker) window.jQuery(campaignDomainField).selectpicker('refresh');
                        applyCampaignDomainToBody(domain);
                    }
                }
            };
            const selectedCreative = () => document.getElementById('send-creative-asset').selectedOptions[0];
            const publicAssetUrl = (option) => {
                const value = option?.dataset.publicUrl || option?.dataset.assetValue || 'NA';
                return /^https?:\/\//i.test(value) ? value : `http://${selectedCampaignDomain()}/${value}`;
            };
            const applySelectedHtml = () => {
                const option = document.getElementById('send-html-asset').selectedOptions[0];
                if (option?.value && option.dataset.assetValue) body.value = option.dataset.assetValue.replaceAll('[domain]', selectedCampaignDomain());
            };
            const applySelectedCreative = () => {
                const option = selectedCreative();
                if (!option?.value) return;
                const source = publicAssetUrl(option);
                body.value = body.value.replace(/src="[^"]*"/i, `src="${source}"`);
            };
            document.getElementById('send-creative-asset').addEventListener('change', applySelectedCreative);
            document.getElementById('send-html-asset').addEventListener('change', applySelectedHtml);
            campaignDomainField.addEventListener('change', () => {
                if (campaignRouteField?.value === 'vmta') {
                    syncCampaignDomainToRoute();
                    return;
                }
                applyCampaignDomainToBody(selectedCampaignDomain());
            });
            document.getElementById('campaign-body-map').addEventListener('change', async (event) => {
                const creative = document.getElementById('send-creative-asset').selectedOptions[0];
                if (!creative?.value) { event.target.checked = false; setToolStatus('Please select a creative first.', 'warning'); return; }
                const domain = selectedCampaignDomain();
                const source = publicAssetUrl(creative);
                const unsubscribeValue = creative.dataset.unsubscribeValue || 'NA';
                const unsubscribe = /^https?:\/\//i.test(unsubscribeValue) ? unsubscribeValue : `http://${domain}/${unsubscribeValue}`;
                const dimensions = (url) => new Promise((resolve) => {
                    const image = new Image();
                    image.onload = () => resolve([image.naturalWidth || image.width, image.naturalHeight || image.height]);
                    image.onerror = () => resolve([0, 0]);
                    image.src = url;
                });
                const [creativeWidth, creativeHeight] = event.target.checked ? await dimensions(source) : [0, 0];
                const [unsubscribeWidth, unsubscribeHeight] = event.target.checked ? await dimensions(unsubscribe) : [0, 0];
                const bodyStart = '<html>\n<body>\n<center>\n<a href="http://' + domain + '/[rndUN_10]r[token]">\n';
                const trackingEnd = '<img src="http://' + domain + '/[rndUN_10]o[token]">\n</center>\n</body>\n</html>\n';
                const imageTag = event.target.checked
                    ? `<img src="${source}" usemap="#r[ide]" style="width: ${creativeWidth}px; height: ${creativeHeight}px" />\n<br/>\n<map name="r[ide]">\n<area shape="rect" coords="0,0,2000,2000" target="_blank" href="http://${domain}/[rndUN_10]r[token]"/>\n</map>\n<img src="${unsubscribe}" usemap="#u[ide]" style="width: ${unsubscribeWidth}px; height: ${unsubscribeHeight}px"/>\n<br/>\n<map name="u[ide]">\n<area shape="rect" coords="0,0,2000,2000" target="_blank" href="http://${domain}/[rndUN_10]u[token]"/>\n</map>\n`
                    : `<img src="${source}" />\n</a><br>\n<a href="http://${domain}/[rndUN_10]u[token]">\n<img src="${unsubscribe}" />\n</a><br>\n`;
                body.value = bodyStart + (event.target.checked ? '<h2 style="font-size:35px; color:#324ab2">TITLE</h2>\n</a>\n<br/>\n' : '<font size="4">TTILE</font><br>\n') + imageTag + trackingEnd;
            });
            const headerField = document.getElementById('campaign-dms-header');
            const fromAssets = document.getElementById('send-from-assets');
            const subjectAssets = document.getElementById('send-subject-assets');
            fromAssets?.addEventListener('change', () => {
                const values = Array.from(fromAssets.selectedOptions).map((option) => option.textContent.trim());
                const replacement = values.length === 1 ? `From: ${values[0]} <` : 'From: [from] <';
                headerField.value = headerField.value.replace(/from.*:.*</gi, replacement);
            });
            subjectAssets?.addEventListener('change', () => {
                const values = Array.from(subjectAssets.selectedOptions).map((option) => option.textContent.trim());
                const replacement = values.length === 1 ? `Subject: ${values[0]}` : 'Subject: [subject]';
                headerField.value = headerField.value.replace(/subject.*:.*/gi, replacement);
            });
            campaignIpsField?.addEventListener('change', syncCampaignDomainToRoute);

            if (@json((bool) $editingCampaign)) {
                if (window.jQuery && window.jQuery.fn.selectpicker) window.jQuery('.selectpicker').selectpicker();
                const servers = document.getElementById('send-servers');
                const ips = document.getElementById('send-ips');
                const route = document.getElementById('send-route');
                const type = document.getElementById('send-ip-type');
                const panels = document.getElementById('campaign-pmta-panels');
                const refresh = (element) => { if (window.jQuery?.fn.selectpicker) window.jQuery(element).selectpicker('refresh'); };
                const routeChoiceIdentity = (option) => [
                    option.dataset.route || '',
                    option.dataset.serverId || '',
                    option.dataset.routeChoiceId || option.value,
                    option.dataset.managedServerIpId || '',
                    option.dataset.pmtaName || '',
                    option.dataset.username || '',
                    option.dataset.name || '',
                    option.dataset.vmtaDomain || '',
                ].join('|');
                const routeChoiceLabel = (ip, routeOption) => {
                    if (routeOption === 'vmta') return [ip.ip, ip.dns, ip.name].filter((value) => value !== null && value !== undefined && String(value) !== '').join(' | ');
                    if (routeOption === 'smtp') return [ip.ip, ip.username, ip.name, ip.dns].filter((value) => value !== null && value !== undefined && String(value) !== '').join(' | ');
                    return [ip.ip, ip.dns].filter((value) => value !== null && value !== undefined && String(value) !== '').join(',');
                };
                let loadedRoute = route.value;
                const reloadIps = async () => {
                    const requestedRoute = route.value;
                    const selectedServerIds = new Set(Array.from(servers.selectedOptions).map((option) => String(option.value)));
                    const query = new URLSearchParams({route: requestedRoute, ip_type: type.value});
                    selectedServerIds.forEach((serverId) => query.append('server_ids[]', serverId));
                    const response = await fetch(`${form.dataset.optionsUrl}?${query}`, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
                    if (!response.ok) return;
                    const result = (await response.json()).data;
                    const selected = requestedRoute === loadedRoute
                        ? new Set(Array.from(ips.selectedOptions)
                            .filter((option) => selectedServerIds.has(String(option.dataset.serverId)))
                            .map(routeChoiceIdentity))
                        : new Set();
                    ips.replaceChildren();
                    result.ips.forEach(ip => {
                        const option = new Option(routeChoiceLabel(ip, requestedRoute), ip.id);
                        option.dataset.route = requestedRoute;
                        option.dataset.routeChoiceId = String(ip.id);
                        option.dataset.serverId = String(ip.server_id);
                        option.dataset.managedServerIpId = String(ip.managed_server_ip_id || '');
                        option.dataset.pmtaName = String(ip.pmta_name || '');
                    option.dataset.username = String(ip.username || '');
                    option.dataset.name = String(ip.name || '');
                    option.dataset.ip = String(ip.ip || '');
                    option.dataset.dns = String(ip.dns || '');
                    option.dataset.vmtaDomain = String(ip.vmta_domain || '');
                    option.selected = selected.has(routeChoiceIdentity(option));
                        ips.add(option);
                    });
                    loadedRoute = requestedRoute;
                    refresh(ips);
                    renderIpLists();
                    refreshCampaignDomainOptions(result.campaign_domains);
                    syncCampaignDomainToRoute();
                    panels.replaceChildren();
                    const selectedServers = new Set(Array.from(servers.selectedOptions).map(option => option.value));
                    result.servers.filter(server => selectedServers.has(String(server.id))).forEach(server => {
                        const link = document.createElement('a');
                        link.href = server.pmta_panel_url; link.target = '_blank'; link.rel = 'noopener noreferrer';
                        link.className = 'mr-3'; link.textContent = `Open PMTA panel — ${server.name}`;
                        panels.append(link);
                    });
                };
                syncCampaignDomainToRoute();
                [servers, route, type].forEach(element => element.addEventListener('change', () => { reloadIps().catch(() => {}); }));
                document.getElementById('campaign-servers-reload').addEventListener('click', () => {
                    reloadIps().then(() => setToolStatus('Servers and IPs reloaded.')).catch(() => setToolStatus('Servers and IPs could not be reloaded.', 'danger'));
                });
                return;
            }

            const url = form.dataset.optionsUrl;
            const saveButton = document.getElementById('campaign-save-button');
            const status = document.getElementById('campaign-options-status');
            const retry = document.getElementById('campaign-options-retry');
            const panels = document.getElementById('campaign-pmta-panels');
            const snapshotProgress = document.getElementById('campaign-snapshot-progress');
            const snapshotStage = document.getElementById('campaign-snapshot-stage');
            const snapshotPercent = document.getElementById('campaign-snapshot-percent');
            const snapshotBar = document.getElementById('campaign-snapshot-bar');
            const snapshotDetail = document.getElementById('campaign-snapshot-detail');
            const snapshotOpen = document.getElementById('campaign-snapshot-open');
            const snapshotError = document.getElementById('campaign-snapshot-error');
            const snapshotRetry = document.getElementById('campaign-snapshot-retry');
            const submitError = document.getElementById('campaign-submit-error');
            const newCampaignLink = document.getElementById('campaign-new-campaign');
            const stageLabels = {
                queued: 'Waiting for a background worker',
                suppression_file: 'Applying offer suppression file',
                counting_audience: 'Counting selected list recipients',
                audience: 'Filtering selected recipients and removing duplicates',
                writing_snapshot: 'Saving the campaign recipient snapshot',
                complete: 'Suppression and recipient preparation complete',
                failed: 'Campaign preparation failed',
            };
            let snapshotPollTimer = null;
            let snapshotPollInFlight = false;
            let campaignSaved = Boolean(snapshotProgress.dataset.progressUrl);
            const ids = {
                sponsor: 'send-sponsor',
                offerCountry: 'send-offer-country',
                offer: 'send-offer',
                fromAssets: 'send-from-assets',
                subjectAssets: 'send-subject-assets',
                creativeAsset: 'send-creative-asset',
                htmlAsset: 'send-html-asset',
                isp: 'send-isp',
                country: 'send-country',
                lists: 'send-lists',
                route: 'send-route',
                ipType: 'send-ip-type',
                servers: 'send-servers',
                ips: 'send-ips',
            };
            let controller = null;
            let sequence = 0;
            let optionsReady = true;

            const select = (key) => document.getElementById(ids[key]);
            const selectedValues = (element) => Array.from(element.selectedOptions).map((option) => option.value).filter(Boolean);
            const dmsBody = document.getElementById('campaign-dms-body');
            const campaignDomain = document.getElementById('campaign-domain');
            let lastOfferId = select('offer').value;
            const pickerRefresh = (element) => {
                if (window.jQuery && window.jQuery.fn.selectpicker) window.jQuery(element).selectpicker('refresh');
            };
            const selectedDomain = () => campaignDomain.value || '[domain]';
            const applyHtmlAsset = () => {
                const option = select('htmlAsset').selectedOptions[0];
                if (!option?.value) return false;
                dmsBody.value = (option.dataset.assetValue || '').replaceAll('[domain]', selectedDomain());
                return true;
            };
            const applyCreativeAsset = () => {
                const option = select('creativeAsset').selectedOptions[0];
                if (!option?.value) return;
                const creativePath = option.dataset.assetValue || 'NA';
                const creativeUrl = /^https?:\/\//i.test(creativePath) ? creativePath : 'http://' + selectedDomain() + '/' + creativePath;
                dmsBody.value = dmsBody.value.replace(/src="[^"]*"/i, 'src="' + creativeUrl + '"');
            };
            const assetImageUrl = (asset) => /^https?:\/\//i.test(String(asset || '')) ? String(asset) : 'http://[domain]/' + (asset || 'NA');
            const defaultDmsBody = (creative, unsubscribe) => [
                '<html>',
                '<body>',
                '<center>',
                '<a href="http://[domain]/[rndUN_10]r[token]">',
                '<font size="4">TTILE</font><br>',
                '<img src="' + assetImageUrl(creative) + '" />',
                '</a><br>',
                '<a href="http://[domain]/[rndUN_10]u[token]">',
                '<img src="' + assetImageUrl(unsubscribe) + '" />',
                '</a><br>',
                '<img src="http://[domain]/[rndUN_10]o[token]" />',
                '</center>',
                '</body>',
                '</html>',
                '',
            ].join('\n');
            const setStatus = (message = '', tone = 'info') => {
                status.className = message ? `alert alert-${tone}` : 'alert d-none';
                status.textContent = message;
            };
            const getFilters = () => ({
                sponsor_id: select('sponsor').value,
                offer_country: select('offerCountry').value || 'ALL',
                isp_id: select('isp').value,
                country: select('country').value || 'ALL',
                server_ids: selectedValues(select('servers')),
                route: select('route').value || 'ip',
                ip_type: select('ipType').value || 'all',
            });
            let lastPaintedRoute = select('route').value || 'ip';
            const fill = (key, records, getValue, getLabel, selected, placeholder) => {
                const element = select(key);
                const previous = selected ?? (element.multiple ? selectedValues(element) : element.value);
                const wanted = new Set(Array.isArray(previous) ? previous.map(String) : [String(previous || '')]);
                element.replaceChildren();
                if (!element.multiple) {
                    const empty = document.createElement('option');
                    empty.value = '';
                    empty.textContent = placeholder;
                    element.append(empty);
                }
                let retained = 0;
                records.forEach((record) => {
                    const option = document.createElement('option');
                    option.value = String(getValue(record));
                    option.textContent = String(getLabel(record));
                    if (key === 'servers') option.dataset.panelUrl = String(record.pmta_panel_url ?? '');
                    if (key === 'ips') {
                        option.dataset.route = select('route').value || 'ip';
                        option.dataset.serverId = String(record.server_id ?? '');
                        option.dataset.ip = String(record.ip ?? '');
                        option.dataset.dns = String(record.dns ?? '');
                        option.dataset.vmtaDomain = String(record.vmta_domain ?? '');
                    }
                    if (key === 'creativeAsset') option.dataset.assetValue = String(record.public_url ?? record.value ?? '');
                    if (key === 'htmlAsset') option.dataset.assetValue = String(record.value ?? '');
                    if (wanted.has(option.value)) {
                        option.selected = true;
                        retained++;
                    }
                    element.append(option);
                });
                if (element.multiple) {
                    element.required = ['lists', 'servers', 'ips'].includes(key)
                        && !document.getElementById('campaign-targeting').hidden;
                } else if (retained === 0) {
                    element.value = '';
                }
                pickerRefresh(element);
            };
            const paintOptions = (data) => {
                const selectedRoute = select('route').value || 'ip';
                const routeChanged = selectedRoute !== lastPaintedRoute;
                const current = {
                    ...getFilters(),
                    offer_id: select('offer').value,
                    audience_segments: selectedValues(select('lists')),
                    from_asset_ids: selectedValues(select('fromAssets')),
                    subject_asset_ids: selectedValues(select('subjectAssets')),
                    creative_asset_id: select('creativeAsset').value,
                    html_asset_id: select('htmlAsset').value,
                    ip_ids: routeChanged ? [] : selectedValues(select('ips')),
                };
                lastPaintedRoute = selectedRoute;
                fill('sponsor', data.sponsors, (item) => item.id, (item) => item.name, current.sponsor_id, 'Select Sponsor');
                fill('offer', data.offers, (item) => item.id, (item) => `(${item.offer_id}) ${item.name} — ${item.vertical}`, current.offer_id, 'Select Offer');
                const selectedOffer = data.offers.find((offer) => String(offer.id) === String(select('offer').value));
                const selectedAssets = selectedOffer?.assets ?? [];
                fill('fromAssets', selectedAssets.filter((asset) => asset.type === 'from'), (asset) => asset.id, (asset) => asset.value, current.from_asset_ids, 'Select optional From values');
                fill('subjectAssets', selectedAssets.filter((asset) => asset.type === 'subject'), (asset) => asset.id, (asset) => asset.value, current.subject_asset_ids, 'Select optional subjects');
                fill('creativeAsset', selectedAssets.filter((asset) => asset.type === 'creative'), (asset) => asset.id, (asset) => asset.value, current.creative_asset_id, 'Select Creative');
                fill('htmlAsset', selectedAssets.filter((asset) => asset.type === 'html'), (asset) => asset.id, (asset) => String(asset.value).slice(0, 50) + '…', current.html_asset_id, 'Select HTML');
                const selectedOfferId = select('offer').value;
                if (selectedOffer && (selectedOfferId !== lastOfferId || !dmsBody.value.trim())) {
                    const creative = selectedAssets.find((asset) => String(asset.id) === String(select('creativeAsset').value))
                        ?? selectedAssets.find((asset) => asset.type === 'creative');
                    const unsubscribe = selectedAssets.find((asset) => asset.type === 'unsubscribe');
                    dmsBody.value = defaultDmsBody(creative?.public_url ?? creative?.value, unsubscribe?.public_url ?? unsubscribe?.value);
                    applyHtmlAsset();
                }
                lastOfferId = selectedOfferId;
                fill('isp', data.isps, (item) => item.id, (item) => item.name, current.isp_id, 'Select ISP');
                fill('lists', data.segments, (item) => item.id, (item) => `${item.name} — ${item.country} — ${String(item.email_type).toUpperCase()} — ${Number(item.recipient_count).toLocaleString()}`, current.audience_segments, 'Select Lists');
                fill('servers', data.servers, (item) => item.id, (item) => `${item.name} | ${item.ip}`, current.server_ids, 'Select Servers');
                refreshCampaignDomainOptions(data.campaign_domains);
                syncRouteChoiceLabels();
                const routeChoiceLabel = selectedRoute === 'smtp'
                    ? (item) => [item.ip, item.username, item.name, item.dns || 'no DNS'].filter((value) => value !== null && value !== undefined && String(value) !== '').join(' | ')
                    : selectedRoute === 'vmta'
                        ? (item) => [item.ip, item.dns || 'no DNS', item.name].filter((value) => value !== null && value !== undefined && String(value) !== '').join(' | ')
                        : (item) => `${item.ip} | ${String(item.type).toUpperCase()} | ${item.dns || 'no DNS'}`;
                fill('ips', data.ips, (item) => item.id, (item) => `${routeChoiceLabel(item)} (server ${item.server_id})`, current.ip_ids, `Select ${routeChoiceNames[selectedRoute] || 'IPs'}`);
                renderIpLists();
                syncCampaignDomainToRoute();

                panels.replaceChildren();
                const selectedServers = new Set(selectedValues(select('servers')));
                data.servers.filter((server) => selectedServers.has(String(server.id))).forEach((server) => {
                    const column = document.createElement('div');
                    column.className = 'col-sm-6 col-lg-4';
                    const link = document.createElement('a');
                    link.href = server.pmta_panel_url;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.textContent = `Open PMTA panel — ${server.name}:${data.settings.pmta_admin_port}`;
                    column.append(link);
                    panels.append(column);
                });

                const sponsorMissing = data.sponsors.length === 0;
                const offerMissing = current.sponsor_id && data.offers.length === 0;
                const ispMissing = current.sponsor_id && data.isps.length === 0;
                const listsMissing = current.isp_id && data.lists.length === 0;
                const serversMissing = data.servers.length === 0;
                if (sponsorMissing || offerMissing || ispMissing || listsMissing || serversMissing) {
                    const missing = [];
                    if (sponsorMissing) missing.push('active sponsor with an eligible offer');
                    if (offerMissing) missing.push('eligible offer for the selected sponsor and country');
                    if (ispMissing) missing.push('active ISP available to the selected sponsor');
                    if (listsMissing) missing.push('active list for the selected ISP and country');
                    if (serversMissing) missing.push('active installed server assigned to your user');
                    setStatus(`No ${missing.join(', ')} is available in this workspace. Check its active state, access assignment, and source data.`, 'warning');
                } else if (!current.sponsor_id) {
                    setStatus('Choose a sponsor to load its DMS-eligible offers and the ISPs assigned to that sponsor.', 'info');
                } else if (!current.isp_id) {
                    setStatus('Choose an ISP for the selected sponsor to load its active lists.', 'info');
                } else {
                    setStatus('DMS-mapped choices loaded. Saving creates the suppression-filtered recipient snapshot; native campaign send and scheduling are not available.', 'info');
                }
            };
            const loadOptions = async () => {
                const ticket = ++sequence;
                controller?.abort();
                controller = new AbortController();
                let httpErrorStatus = null;
                const query = new URLSearchParams();
                const filters = getFilters();
                Object.entries(filters).forEach(([key, value]) => {
                    if (Array.isArray(value)) value.forEach((entry) => query.append(`${key}[]`, entry));
                    else if (value !== '') query.set(key, value);
                });
                optionsReady = false;
                retry.classList.add('d-none');
                saveButton.disabled = true;
                saveButton.setAttribute('aria-busy', 'true');
                setStatus('Loading updated campaign choices…', 'info');
                try {
                    const response = await fetch(`${url}?${query.toString()}`, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        signal: controller.signal,
                    });
                    if (!response.ok) {
                        httpErrorStatus = response.status;
                        throw new Error(`Request failed with status ${response.status}`);
                    }
                    const payload = await response.json();
                    if (ticket !== sequence) return;
                    paintOptions(payload.data);
                    optionsReady = true;
                } catch (error) {
                    if (error.name === 'AbortError' || ticket !== sequence) return;
                    console.error('Campaign choices refresh failed', error);
                    optionsReady = false;
                    const statusHint = httpErrorStatus ? ` (HTTP ${httpErrorStatus})` : '';
                    setStatus(`Campaign choices could not be refreshed${statusHint}. Your current selections are still on the form; retry before saving.`, 'danger');
                    retry.classList.remove('d-none');
                } finally {
                    if (ticket === sequence) {
                        saveButton.disabled = !optionsReady || campaignSaved;
                        saveButton.removeAttribute('aria-busy');
                    }
                }
            };
            const applySnapshotProgress = (payload) => {
                const stage = String(payload.stage || (payload.ready ? 'complete' : 'queued'));
                const percent = Math.max(0, Math.min(100, Number.parseInt(payload.percent ?? 0, 10) || 0));
                const processed = Math.max(0, Number.parseInt(payload.processed ?? 0, 10) || 0);
                const total = payload.total === null || payload.total === undefined ? null : Math.max(0, Number.parseInt(payload.total, 10) || 0);
                snapshotProgress.hidden = false;
                snapshotStage.textContent = stageLabels[stage] || 'Preparing the campaign audience';
                snapshotPercent.textContent = `${percent}%`;
                snapshotBar.style.width = `${percent}%`;
                snapshotBar.textContent = `${percent}%`;
                snapshotBar.parentElement.setAttribute('aria-valuenow', String(percent));
                snapshotDetail.textContent = total > 0
                    ? `${processed.toLocaleString()} of ${total.toLocaleString()} ${stage === 'suppression_file' ? 'bytes checked' : 'records processed'}.`
                    : 'The campaign composer stays open while the selected lists are processed.';

                const complete = payload.ready === true || stage === 'complete' || payload.status === 'draft';
                const failed = payload.status === 'snapshot_failed' || stage === 'failed';
                if (complete) {
                    snapshotStage.textContent = 'Suppression and recipient preparation complete. The campaign is saved as a draft.';
                    snapshotBar.classList.remove('progress-bar-animated');
                    snapshotOpen.hidden = false;
                    snapshotOpen.textContent = 'Open saved campaign';
                    snapshotOpen.href = snapshotProgress.dataset.editUrl || snapshotProgress.dataset.campaignUrl;
                    saveButton.hidden = true;
                    newCampaignLink.hidden = false;
                    clearInterval(snapshotPollTimer);
                    snapshotPollTimer = null;
                } else if (failed) {
                    snapshotStage.textContent = 'Campaign preparation failed. Your composer is still open.';
                    snapshotBar.classList.remove('progress-bar-animated');
                    snapshotError.textContent = payload.error || 'The selected suppression file or audience could not be processed. Review the saved campaign and retry preparation.';
                    snapshotError.hidden = false;
                    snapshotRetry.hidden = false;
                    snapshotRetry.href = snapshotProgress.dataset.campaignUrl;
                    saveButton.hidden = true;
                    newCampaignLink.hidden = false;
                    clearInterval(snapshotPollTimer);
                    snapshotPollTimer = null;
                }
            };
            const pollSnapshotProgress = async () => {
                if (snapshotPollInFlight || !snapshotProgress.dataset.progressUrl) return;
                snapshotPollInFlight = true;
                try {
                    const response = await fetch(snapshotProgress.dataset.progressUrl, {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                    });
                    if (!response.ok) throw new Error(`Progress request failed (${response.status}).`);
                    applySnapshotProgress(await response.json());
                } catch (error) {
                    snapshotStage.textContent = 'Preparation is still running; reconnecting to progress…';
                    snapshotDetail.textContent = error.message || 'The latest progress update could not be loaded.';
                } finally {
                    snapshotPollInFlight = false;
                }
            };
            const startSnapshotProgress = () => {
                snapshotProgress.hidden = false;
                if (snapshotPollTimer === null) snapshotPollTimer = window.setInterval(pollSnapshotProgress, 1500);
                void pollSnapshotProgress();
            };
            const submitMarketingCampaign = async () => {
                submitError.hidden = true;
                snapshotError.hidden = true;
                snapshotOpen.hidden = true;
                snapshotRetry.hidden = true;
                snapshotProgress.hidden = false;
                snapshotStage.textContent = 'Saving the campaign and starting suppression…';
                snapshotDetail.textContent = 'This page will stay open while suppression and recipient preparation run.';
                snapshotPercent.textContent = '0%';
                snapshotBar.style.width = '0%';
                snapshotBar.textContent = '0%';
                snapshotBar.classList.add('progress-bar-animated');
                saveButton.disabled = true;
                saveButton.setAttribute('aria-busy', 'true');
                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                        body: new FormData(form),
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        const details = Object.values(payload.errors || {}).flat();
                        throw new Error(details.join('\n') || payload.message || `Campaign save failed (${response.status}).`);
                    }
                    snapshotProgress.dataset.progressUrl = payload.snapshot_progress_url;
                    snapshotProgress.dataset.campaignUrl = payload.campaign_url;
                    snapshotProgress.dataset.editUrl = payload.edit_url;
                    campaignSaved = true;
                    const pageUrl = new URL(window.location.href);
                    pageUrl.searchParams.set('preparing_campaign', payload.campaign_id);
                    window.history.replaceState(window.history.state, '', pageUrl.toString());
                    startSnapshotProgress();
                } catch (error) {
                    submitError.textContent = error.message || 'The campaign could not be saved. Review the form and try again.';
                    submitError.hidden = false;
                    snapshotProgress.hidden = true;
                    saveButton.disabled = false;
                } finally {
                    saveButton.removeAttribute('aria-busy');
                }
            };
            ['sponsor', 'offerCountry', 'offer', 'isp', 'country', 'route', 'ipType', 'servers'].forEach((key) => {
                select(key).addEventListener('change', () => {
                    loadOptions();
                });
            });
            retry.addEventListener('click', loadOptions);
            document.getElementById('campaign-servers-reload').addEventListener('click', loadOptions);
            form.addEventListener('submit', (event) => {
                if (event.submitter?.hasAttribute('formnovalidate')) return;
                if (!form.reportValidity()) {
                    event.preventDefault();
                    return;
                }
                if (!optionsReady) {
                    event.preventDefault();
                    retry.focus();
                    return;
                }
                if (floatingActions.dataset.editing === 'false'
                    && form.querySelector('input[name="kind"]')?.value === 'marketing') {
                    event.preventDefault();
                    void submitMarketingCampaign();
                    return;
                }
                saveButton.disabled = true;
                saveButton.setAttribute('aria-busy', 'true');
            });

            if (window.jQuery && window.jQuery.fn.selectpicker) window.jQuery('.selectpicker').selectpicker();
            if (snapshotProgress.dataset.progressUrl) {
                campaignSaved = true;
                saveButton.disabled = true;
                saveButton.textContent = 'Draft saved';
                startSnapshotProgress();
            }
            loadOptions();
        })();
    </script>
@endpush
