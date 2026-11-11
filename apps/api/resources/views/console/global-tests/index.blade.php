@extends('layouts.admin-shell')

@section('title', 'Global Tests')

@section('content')
    <div id="global-quick-nav" class="global-floating-actions">
        <button class="global-quick-nav-trigger" id="quick-nav-toggle" type="button" aria-label="Open quick navigation" aria-expanded="false" aria-controls="global-quick-nav-menu">
            <i class="fas fa-bars" aria-hidden="true"></i>
        </button>
        <div class="global-quick-nav-menu" id="global-quick-nav-menu" role="menu" aria-label="Global Test actions" hidden>
            <div class="campaign-menu-heading">
                <span>GLOBAL TEST CONTROL</span>
                <i class="fas fa-bolt" aria-hidden="true"></i>
            </div>
            <button type="button" role="menuitem" data-quick-action="send"><i class="fas fa-vial" aria-hidden="true"></i><span>SEND TEST</span></button>
            <button type="button" role="menuitem" data-quick-action="pmta-admin"><i class="fas fa-grip-horizontal" aria-hidden="true"></i><span>PMTA ADMIN</span></button>
            <button type="button" role="menuitem" data-quick-action="pmta-panel"><i class="fas fa-server" aria-hidden="true"></i><span>PMTA PANEL</span></button>
        </div>
    </div>
    <div id="global-test-context-menu" role="menu" aria-label="Global Test actions" hidden>
        <div class="campaign-menu-heading">
            <span>GLOBAL TEST CONTROL</span>
            <i class="fas fa-bolt" aria-hidden="true"></i>
        </div>
        <button type="button" role="menuitem" data-quick-action="send"><span>SEND TEST</span><i class="fas fa-vial" aria-hidden="true"></i></button>
        <button type="button" role="menuitem" data-quick-action="pmta-admin"><span>PMTA ADMIN</span><i class="fas fa-grip-horizontal" aria-hidden="true"></i></button>
        <button type="button" role="menuitem" data-quick-action="pmta-panel"><span>PMTA PANEL</span><i class="fas fa-server" aria-hidden="true"></i></button>
    </div>

    <div class="campaign-create-page" id="global-tests-page"
         aria-label="GLOBAL TESTS"
         style="--campaign-hero-art: url('{{ asset('console-assets/campaign-composer-atmosphere.png') }}')"
         data-test-url="{{ route('console.global-tests.test', $workspace->id) }}"
         data-catalog-url="{{ route('console.global-tests.catalog', $workspace->id) }}"
         data-encode-url="{{ route('console.global-tests.encoding', $workspace->id) }}"
         data-address-store-url="{{ route('console.campaign-test-addresses.store', $workspace->id) }}"
         data-address-delete-base="{{ url('/app/workspaces/'.$workspace->id.'/campaign-test-addresses') }}"
         data-header-base="{{ url('/app/workspaces/'.$workspace->id.'/campaign-headers') }}"
         data-pmta-admin-url="{{ route('console.pmta-admin.index', $workspace->id) }}"
         data-pmta-admin-port="{{ $pmtaAdminPort }}"
         data-max-messages="{{ config('global-tests.max_messages', 100) }}"
         data-sender-configured="{{ $senderConfigured ? 'true' : 'false' }}"
         data-can-send="{{ in_array((string) $workspace->pivot->role, ['owner', 'admin', 'operator'], true) ? 'true' : 'false' }}">

        <header class="campaign-create-hero">
            <div class="campaign-create-hero-icon" aria-hidden="true"><i class="fas fa-vial"></i></div>
            <div class="campaign-create-hero-copy">
                <h1 id="global-tests-page-title">Global Tests</h1>
                <p>Dispatch live deliverability test emails across configured MTA servers, test addresses, and message headers.</p>
            </div>
        </header>

        <div class="d-flex align-items-center justify-content-between flex-wrap mb-2" style="gap: 8px 16px;">
            <a href="{{ route('console.global-tests.histories', $workspace->id) }}" class="small font-w600 global-history-link">Open global test histories</a>
            <span class="small text-muted">
                <span class="global-status-dot {{ $senderConfigured ? 'is-ready' : 'is-unavailable' }}" aria-hidden="true"></span>
                {{ $senderConfigured ? 'PMTA transport ready' : 'PMTA transport unavailable' }} · Max {{ config('global-tests.max_messages', 100) }} messages
            </span>
        </div>

        <nav class="campaign-stage-track" aria-label="Global Test stages">
            <ol>
                <li class="is-current" aria-current="step" data-step="1" data-jump-target="#global-section-setup" tabindex="0" role="button"><span class="campaign-stage-number">1</span><span><strong>Setup</strong><small>Recipients &amp; path</small></span></li>
                <li data-step="2" data-jump-target="#global-section-editors" tabindex="0" role="button"><span class="campaign-stage-number">2</span><span><strong>Configuration</strong><small>Headers &amp; content</small></span></li>
                <li data-step="3" data-jump-target="#global-section-sending" tabindex="0" role="button"><span class="campaign-stage-number">3</span><span><strong>Sending</strong><small>Routes &amp; placeholders</small></span></li>
                <li data-step="4" data-jump-target="#global-section-dispatch" tabindex="0" role="button"><span class="campaign-stage-number">4</span><span><strong>Review</strong><small>Summary &amp; send</small></span></li>
            </ol>
        </nav>

        <div class="campaign-create-layout">
            <div class="campaign-create-main" id="global-tests-block">
                @unless ($senderConfigured)
                    <div class="alert alert-warning mb-3" role="status">
                        <i class="fas fa-exclamation-triangle mr-1" aria-hidden="true"></i>
                        {{ count($servers) === 0 ? 'No active, installed PMTA servers are available in this workspace yet.' : 'AlphaLink’s Global Test sender port is not configured. Sending stays unavailable until a PMTA SMTP listener port is set.' }}
                    </div>
                @endunless

                <div class="alert alert-primary alert-dismissible py-2" id="campaign-usable-tags" role="note">
                    <strong><i class="fas fa-code campaign-field-icon" aria-hidden="true"></i>USABLE TAGS:</strong>
                    <span class="campaign-usable-tag-list">
                        @foreach (['[server]', '[main_domain]', '[ip]', '[domain]', '[rdns]', '[to]', '[date]', '[name]', '[rnd]', '[smtp]', '[smtp_name]', '[rnda_10]', '[text:a|b|c]', '[negative]', '[placeholder1]'] as $tag)
                            <button type="button" class="global-tag-button" data-insert-tag="{{ $tag }}" title="Insert {{ $tag }}">{{ $tag }}</button>
                        @endforeach
                    </span>
                    <button type="button" class="btn btn-link btn-sm p-0 ml-1" id="show-global-tags" title="SHOW EXAMPLE" aria-label="Show tag examples"><i class="fas fa-question-circle" aria-hidden="true"></i></button>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>

                <form id="global-test-form" method="post" action="{{ route('console.global-tests.test', $workspace->id) }}" novalidate>
                    @csrf

                    <!-- Card 1: Email Configuration (Exact 1:1 Edit Campaign structure & aligned columns) -->
                    <div class="block block-rounded campaign-email-card" id="global-section-setup">
                        <div class="block-header">
                            <h3 class="block-title"><i class="fas fa-envelope" aria-hidden="true"></i> Email Configuration</h3>
                            <span class="campaign-section-caption">Configure return path, headers, and email content.</span>
                        </div>
                        <div class="block-content">
                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label for="return_path"><i class="fas fa-paper-plane campaign-field-icon" aria-hidden="true"></i>Return path:</label>
                                    <input id="return_path" type="text" maxlength="320" class="form-control" value="[rnd]@[domain]" autocomplete="off">
                                </div>
                                <div class="form-group col-md-4" id="campaign-saved-emails"
                                     data-store-url="{{ route('console.campaign-test-addresses.store', $workspace->id) }}"
                                     data-delete-url-template="{{ route('console.campaign-test-addresses.destroy', [$workspace->id, '__ADDRESS_ID__']) }}">
                                    <label for="saved_emails"><i class="fas fa-envelope campaign-field-icon" aria-hidden="true"></i>Saved Emails:</label>
                                    <div class="input-group">
                                        <select id="saved_emails" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-selected-text-format="count > 2" title="Select Emails" multiple>
                                            @foreach ($savedAddresses as $address)
                                                <option value="{{ $address->email }}" data-address-id="{{ $address->id }}">{{ $address->email }}</option>
                                            @endforeach
                                        </select>
                                        <div class="input-group-append">
                                            <button id="manage-emails" type="button" class="campaign-icon-button is-add" aria-label="Manage saved email addresses" title="Add or delete saved email addresses" aria-expanded="false" aria-controls="saved-email-manager"><i class="fas fa-plus" aria-hidden="true"></i></button>
                                        </div>
                                    </div>
                                    <div id="saved-email-manager" class="mt-2" hidden>
                                        <label for="saved-email-new"><i class="fas fa-plus-square campaign-field-icon" aria-hidden="true"></i>Add a test address</label>
                                        <div class="input-group">
                                            <input id="saved-email-new" type="email" maxlength="255" class="form-control" placeholder="name@domain.com">
                                            <div class="input-group-append">
                                                <button id="saved-email-add" type="button" class="campaign-icon-button is-add" title="Add test address" aria-label="Add test address"><i class="fas fa-plus" aria-hidden="true"></i></button>
                                                <button id="saved-email-remove" type="button" class="campaign-icon-button is-delete" title="Delete selected saved addresses" aria-label="Delete selected saved addresses"><i class="fas fa-trash-alt" aria-hidden="true"></i></button>
                                            </div>
                                        </div>
                                        <div id="saved-email-status" role="status" aria-live="polite" class="small mt-1"></div>
                                    </div>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="email_test"><i class="fas fa-envelope campaign-field-icon" aria-hidden="true"></i>Email Test addresses:</label>
                                    <textarea id="email_test" rows="1" class="form-control" placeholder="One address per line" autocomplete="email"></textarea>
                                </div>
                            </div>

                            <div class="form-row mt-2">
                                <div class="form-group col-sm-12 col-md-4 campaign-header-library">
                                    <label for="saved_headers"><i class="fas fa-layer-group campaign-field-icon" aria-hidden="true"></i>Saved Headers:</label>
                                    <div class="input-group">
                                        <select id="saved_headers" class="form-control selectpicker" data-live-search="true" title="Select Header" aria-label="Select saved header">
                                            <option value="">Select Header</option>
                                            @foreach ($headers as $header)
                                                <option value="{{ $header->id }}">{{ $header->name }}</option>
                                            @endforeach
                                        </select>
                                        <div class="input-group-append">
                                            <button id="save-header" type="button" class="campaign-icon-button is-save" title="Save Header" aria-label="Save Header"><i class="fa fa-save" aria-hidden="true"></i></button>
                                            <button id="delete-header" type="button" class="campaign-icon-button is-delete" title="Delete Header" aria-label="Delete Header" disabled><i class="fas fa-trash-alt" aria-hidden="true"></i></button>
                                        </div>
                                    </div>
                                    <div id="saved-header-status" class="small text-muted mt-1" role="status" aria-live="polite"></div>
                                </div>
                                <div class="form-group col-sm-12 col-md-4">
                                    <label for="domain"><i class="fas fa-globe campaign-field-icon" aria-hidden="true"></i>Domain:</label>
                                    <select id="domain" class="form-control selectpicker" data-live-search="true" data-allow-clear="true" title="Select Domain">
                                        <option value="">Select Domain</option>
                                        @foreach ($domains as $domain)
                                            <option value="{{ $domain['value'] }}">{{ $domain['name'] }} — {{ $domain['value'] }}</option>
                                        @endforeach
                                    </select>
                                    <small id="global-domain-route-hint" class="form-text text-muted" hidden></small>
                                </div>
                                <div class="form-group col-sm-12 col-md-4">
                                    <label for="negative"><i class="fas fa-file-alt campaign-field-icon" aria-hidden="true"></i>Negative token file:</label>
                                    <select id="negative" class="form-control selectpicker" data-live-search="true" data-allow-clear="true" title="Select token file">
                                        <option value="">Select token file</option>
                                        @foreach ($negatives as $negative)
                                            <option value="{{ $negative->id }}">{{ $negative->name }}</option>
                                        @endforeach
                                    </select>
                                    <small class="form-text text-muted">Used only when the message contains <code>[negative]</code>.</small>
                                </div>
                            </div>

                            <div class="form-row mt-2" id="global-section-editors">
                                <div class="form-group col-md-6 campaign-editor-column">
                                    <div class="campaign-editor-heading">
                                        <label class="campaign-editor-title" for="header"><i class="fas fa-heading campaign-field-icon" aria-hidden="true"></i>Header</label>
                                        <div class="campaign-editor-tools" role="group" aria-label="Header tools">
                                            <button type="button" class="campaign-editor-icon-button" id="shuffle-header" title="Shuffle header lines" aria-label="Shuffle header lines"><i class="fas fa-random" aria-hidden="true"></i></button>
                                            <button type="button" class="campaign-editor-icon-button" id="open-encoding" title="Encoding tools" aria-label="Encoding tools"><i class="fas fa-pencil-ruler" aria-hidden="true"></i></button>
                                            <button type="button" class="campaign-editor-icon-button" id="global-header-use-smtp" title="Put the selected Workspace SMTP account in the From address" aria-label="Use selected SMTP account as From"><i class="fas fa-plug" aria-hidden="true"></i></button>
                                        </div>
                                    </div>
                                    <textarea id="header" rows="20" class="form-control global-test-editor" spellcheck="false" required>{{ $defaultHeader }}</textarea>
                                </div>
                                <div class="form-group col-md-6 campaign-editor-column">
                                    <div class="campaign-editor-heading campaign-editor-heading-body">
                                        <label class="campaign-editor-title" for="body"><i class="fas fa-code campaign-field-icon" aria-hidden="true"></i>Body</label>
                                        <div class="campaign-editor-tools" role="group" aria-label="Body tools">
                                            <button type="button" class="campaign-editor-icon-button" id="preview-body" title="Preview body" aria-label="Preview body"><i class="fas fa-eye" aria-hidden="true"></i></button>
                                        </div>
                                    </div>
                                    <textarea id="body" rows="20" class="form-control global-test-editor" spellcheck="false"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    </section>

                    <!-- Card 2: Placeholders & Sending (Exact 1:1 Edit Campaign structure + closable Placeholders) -->
                    <div class="block block-rounded campaign-delivery-card" id="global-section-sending">
                        <div class="block-header">
                            <h3 class="block-title"><i class="fas fa-paper-plane" aria-hidden="true"></i> Placeholders &amp; Sending</h3>
                            <span class="campaign-section-caption">Define message variations, routes, and delivery rules.</span>
                        </div>
                        <div class="block-content">
                            <div class="form-row">
                                <div class="col-12">
                                    <div class="block block-rounded" data-campaign-placeholder-editor id="global-placeholders-card">
                                        <div class="block-header">
                                            <div class="campaign-placeholder-heading">
                                                <h3 class="block-title"><i class="fas fa-code campaign-field-icon" aria-hidden="true"></i><span>MESSAGE PLACEHOLDERS</span></h3>
                                                <p>Set token values and how they rotate through this test run.</p>
                                            </div>
                                            <div class="block-options d-flex align-items-center campaign-placeholder-toolbar" role="group" aria-label="Placeholder settings">
                                                <button class="btn btn-sm btn-alt-primary" id="add-placeholder" type="button" data-placeholder-add><i class="fa fa-plus" aria-hidden="true"></i><span>Add placeholder</span></button>
                                                <label class="campaign-placeholder-mode campaign-mode-toggle" for="placeholder_combination">
                                                    <input type="checkbox" class="campaign-mode-input" id="placeholder_combination" data-placeholder-combination>
                                                    <span class="campaign-mode-track" aria-hidden="true"></span>
                                                    <i class="fas fa-random campaign-field-icon" aria-hidden="true"></i><span class="campaign-mode-label">Combination</span>
                                                </label>
                                                <label class="campaign-placeholder-mode campaign-mode-toggle" for="placeholder_all_combination">
                                                    <input type="checkbox" class="campaign-mode-input" id="placeholder_all_combination" data-placeholder-all-combination>
                                                    <span class="campaign-mode-track" aria-hidden="true"></span>
                                                    <i class="fas fa-layer-group campaign-field-icon" aria-hidden="true"></i><span class="campaign-mode-label">All combinations</span>
                                                </label>
                                                <button type="button" class="campaign-icon-button is-delete" id="clear-placeholders" title="Clear and close placeholders" aria-label="Clear and close placeholders" hidden><i class="fas fa-trash-alt" aria-hidden="true"></i></button>
                                                <button type="button" class="campaign-editor-icon-button" id="toggle-placeholders" aria-expanded="false" aria-controls="placeholder-content" title="Open placeholders" aria-label="Open placeholders"><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
                                            </div>
                                        </div>
                                        <div class="block-content" id="placeholder-content" hidden>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-hover mb-2" id="placeholders">
                                                    <thead><tr><th scope="col"><i class="fas fa-code campaign-field-icon" aria-hidden="true"></i>PLACEHOLDER VALUES</th><th scope="col"><i class="fas fa-sync-alt campaign-field-icon" aria-hidden="true"></i>ROTATE EVERY</th><th scope="col" class="text-center"><i class="fas fa-sliders-h campaign-field-icon" aria-hidden="true"></i>ACTION</th></tr></thead>
                                                    <tbody data-placeholder-rows></tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div id="campaign-routing">
                                <div class="row">
                                    <div class="col-sm-12 col-md-8">
                                        <div class="form-row campaign-route-controls-row">
                                            <div class="form-group col-sm-6 col-md-2">
                                                <label for="option"><i class="fas fa-sitemap campaign-field-icon" aria-hidden="true"></i>Option:</label>
                                                <select id="option" class="form-control selectpicker" data-size="3">
                                                    <option value="ip" selected>IP</option>
                                                    <option value="vmta">VMTA</option>
                                                    <option value="smtp">SMTP</option>
                                                </select>
                                            </div>
                                            <div class="form-group col-sm-6 col-md-2">
                                                <label for="type"><i class="fas fa-shield-alt campaign-field-icon" aria-hidden="true"></i>Type IP:</label>
                                                <select id="type" class="form-control selectpicker" data-size="3">
                                                    <option value="all">ALL</option>
                                                    <option value="ipv4" selected>IPv4</option>
                                                    <option value="ipv6">IPv6</option>
                                                </select>
                                            </div>
                                            <div class="form-group col-sm-12 col-md-8">
                                                <label for="servers">
                                                    <i class="fas fa-server campaign-field-icon" aria-hidden="true"></i>Servers:
                                                    <button type="button" class="btn btn-link btn-sm p-0" id="reload-servers" title="Reload servers and IPs" aria-label="Reload servers and IPs"><i class="fas fa-sync-alt" aria-hidden="true"></i></button>
                                                    <button type="button" class="btn btn-link btn-sm p-0" id="toggle-server-search" title="Search servers" aria-label="Search servers"><i class="fas fa-search" aria-hidden="true"></i></button>
                                                </label>
                                                <select id="servers" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 3" title="Select Servers" multiple>
                                                    @foreach ($servers as $server)
                                                        <option value="{{ $server['id'] }}" data-ip="{{ $server['ip'] }}" data-panel-url="{{ $server['pmta_panel_url'] }}" data-monitor-url="{{ $server['pmta_panel_display_url'] ?? $server['pmta_panel_url'] }}">{{ $server['name'] }} | {{ $server['ip'] }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <div class="campaign-route-pools-heading">
                                                <div><i class="fas fa-share-alt campaign-field-icon" aria-hidden="true"></i><span>Delivery routes</span><small>Choose the outbound route or accounts for this test run.</small></div>
                                                <span class="campaign-route-kind" id="send-route-choice-heading">IPs</span>
                                            </div>

                                            <div class="campaign-route-picker">
                                                <div class="campaign-route-selector">
                                                    <div class="campaign-route-pane-heading">
                                                        <label for="ips-available"><span id="send-ips-available-label">Available IPs</span><span class="campaign-route-count" id="send-ips-available-count">0</span></label>
                                                        <button type="button" class="campaign-editor-icon-button" data-copy-ip-list="available" title="Copy available routes" aria-label="Copy available routes"><i class="fas fa-copy" aria-hidden="true"></i></button>
                                                    </div>
                                                    <input class="form-control form-control-sm campaign-route-filter" id="ips-available-filter" type="search" placeholder="Filter available ips" aria-label="Filter available IPs">
                                                    <select id="ips-available" class="form-control" multiple size="10" aria-label="Available IPs"></select>
                                                </div>

                                                <div class="campaign-route-transfer-controls" role="group" aria-label="Move routes between lists">
                                                    <button id="move-all-right" type="button" class="btn btn-outline-secondary btn-sm mb-1" data-ip-move="all-right" aria-label="Move all IPs to selected" title="Move all to selected"><i class="fas fa-angle-double-right" aria-hidden="true"></i></button>
                                                    <button id="move-right" type="button" class="btn btn-outline-secondary btn-sm mb-1" data-ip-move="right" aria-label="Move selected IPs to selected" title="Move selected to selected"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
                                                    <button id="move-left" type="button" class="btn btn-outline-secondary btn-sm mb-1" data-ip-move="left" aria-label="Move IPs back to available" title="Move selected back"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
                                                    <button id="move-all-left" type="button" class="btn btn-outline-secondary btn-sm" data-ip-move="all-left" aria-label="Move all IPs back to available" title="Move all back"><i class="fas fa-angle-double-left" aria-hidden="true"></i></button>
                                                </div>

                                                <div class="campaign-route-selector">
                                                    <div class="campaign-route-pane-heading">
                                                        <label for="ips-selected"><span id="send-ips-selected-label">Selected IPs</span><span class="campaign-route-count" id="send-ips-selected-count">0</span></label>
                                                        <button type="button" class="campaign-editor-icon-button" data-copy-ip-list="selected" title="Copy selected routes" aria-label="Copy selected routes"><i class="fas fa-copy" aria-hidden="true"></i></button>
                                                    </div>
                                                    <input class="form-control form-control-sm campaign-route-filter" id="ips-selected-filter" type="search" placeholder="Filter selected ips" aria-label="Filter selected IPs">
                                                    <select id="ips-selected" class="form-control" multiple size="10" aria-label="Selected IPs"></select>
                                                </div>
                                            </div>

                                            <details class="campaign-create-help campaign-route-help">
                                                <summary title="Route behavior"><i class="fas fa-info-circle campaign-field-icon" aria-hidden="true"></i><span class="sr-only">Route behavior</span></summary>
                                                <small class="form-text text-info" id="send-route-behavior">Option IP sends through the selected source-IP route.</small>
                                            </details>
                                        </div>

                                        <div id="global-pmta-panels" class="row" aria-live="polite" hidden></div>
                                    </div>

                                    <!-- Right Parameters Panel (Clean aligned grid matching Edit Campaign parameters column — no duplicate CTA button!) -->
                                    <div class="col-sm-12 col-md-4" id="global-section-dispatch">
                                        <div class="row">
                                            <div class="form-group col-6">
                                                <label for="repeat"><i class="fas fa-redo campaign-field-icon" aria-hidden="true"></i>Repeat Test:</label>
                                                <input id="repeat" type="number" min="1" class="form-control" value="1">
                                            </div>
                                            <div class="form-group col-6">
                                                <label for="global-projected-messages-field"><i class="fas fa-calculator campaign-field-icon" aria-hidden="true"></i>Total Messages:</label>
                                                <input id="global-projected-messages-field" type="text" class="form-control" value="0 / {{ config('global-tests.max_messages', 100) }}" readonly>
                                            </div>
                                        </div>
                                        <div id="global-send-estimate-box" class="global-tests-send-estimate mt-2">
                                            <small>Projected run: <strong id="global-projected-messages">0</strong> / {{ config('global-tests.max_messages', 100) }} messages (Recipients × Routes × Repeat).</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    </section>
                </form>

                <!-- Live Status & Output Console -->
                <div id="global-test-status" class="alert alert-info mt-3" role="status" aria-live="polite" hidden></div>
                <section id="global-test-output-panel" class="block block-rounded p-3 mt-3" hidden aria-labelledby="global-test-output-title">
                    <div class="d-flex justify-content-between align-items-center flex-wrap mb-2" style="gap: 8px;">
                        <h4 id="global-test-output-title" class="h6 font-w700 mb-0"><i class="fas fa-terminal mr-2 text-purple" aria-hidden="true"></i> Latest Sender Output</h4>
                        <a id="global-test-log-link" href="#" class="small font-w700" hidden>Open Saved History <i class="fas fa-external-link-alt ml-1" aria-hidden="true"></i></a>
                    </div>
                    <pre id="global-test-output" class="global-test-output mb-0"></pre>
                </section>
            </div>

            <!-- Right Sticky Summary Sidebar (Matches Edit Campaign 1:1 with single Send Global Test CTA) -->
            <aside class="campaign-create-summary" id="global-test-summary">
                <section class="campaign-summary-card">
                    <div class="campaign-summary-heading">
                        <span class="campaign-summary-icon" aria-hidden="true"><i class="fas fa-chart-bar"></i></span>
                        <div><h2>Test Summary</h2><p>Review deliverability setup.</p></div>
                    </div>
                    <ul class="campaign-summary-checklist">
                        <li><span><i class="fas fa-at" aria-hidden="true"></i> Return Path</span><strong id="global-summary-path" class="is-ready">Configured</strong></li>
                        <li><span><i class="fas fa-paper-plane" aria-hidden="true"></i> Email Test</span><strong id="global-summary-emails" class="is-pending">Required</strong></li>
                        <li><span><i class="fas fa-heading" aria-hidden="true"></i> Headers</span><strong id="global-summary-headers" class="is-ready">Ready</strong></li>
                        <li><span><i class="fas fa-layer-group" aria-hidden="true"></i> Placeholders</span><strong id="global-summary-placeholders" class="is-optional">Closed</strong></li>
                        <li><span><i class="fas fa-share-alt" aria-hidden="true"></i> Sending</span><strong id="global-summary-routes" class="is-pending">Not selected</strong></li>
                        <li><span><i class="fas fa-code" aria-hidden="true"></i> HTML Body</span><strong id="global-summary-body" class="is-optional">Optional</strong></li>
                    </ul>
                </section>

                <section class="campaign-summary-card">
                    <h2>Quick Stats</h2>
                    <div class="campaign-summary-stats">
                        <div><i class="fas fa-paper-plane" aria-hidden="true"></i><strong id="global-quick-recipients">0</strong><span>Recipients</span></div>
                        <div><i class="fas fa-server" aria-hidden="true"></i><strong id="global-quick-servers">0</strong><span>Servers</span></div>
                        <div><i class="fas fa-network-wired" aria-hidden="true"></i><strong id="global-quick-vmtas">0</strong><span id="global-quick-vmtas-label">Routes</span></div>
                    </div>
                </section>

                <section class="campaign-summary-card campaign-readiness-card">
                    <div class="campaign-readiness-heading"><h2>Test Readiness</h2><strong id="global-readiness-pct">50%</strong></div>
                    <div class="campaign-readiness-track" role="progressbar" aria-label="Global test readiness" aria-valuemin="0" aria-valuemax="100" aria-valuenow="50">
                        <span id="global-readiness-bar" style="width: 50%;"></span>
                    </div>
                    <small id="global-readiness-desc">2 of 4 setup checks complete</small>
                </section>

                <section class="campaign-summary-actions">
                    <p>Dispatches live test emails directly via PMTA. Results are recorded in Global Test Histories.</p>
                    <button class="btn btn-primary campaign-create-submit" id="send-global-test" type="submit" form="global-test-form" {{ $senderConfigured && in_array((string) $workspace->pivot->role, ['owner', 'admin', 'operator'], true) ? '' : 'disabled' }}>
                        <i class="fas fa-paper-plane" aria-hidden="true"></i> Send Global Test
                    </button>
                    <a class="btn btn-outline-secondary campaign-cancel-link" href="{{ route('console.global-tests.histories', $workspace->id) }}">View Test Histories</a>
                </section>
            </aside>
        </div>
    </div>

    <!-- Modals matching Edit Campaign Dialog System -->
    <dialog id="global-server-search-dialog" class="global-dialog" aria-labelledby="global-server-search-title">
        <div class="p-3">
            <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h5 mb-0" id="global-server-search-title">Server Search</h3><button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close aria-label="Close server search">×</button></div>
            <label for="global-server-search-input">Servers</label>
            <textarea class="form-control mb-3" id="global-server-search-input" rows="7" placeholder="Server name or IP address"></textarea>
            <div class="d-flex justify-content-end" style="gap: 8px;">
                <button type="button" class="btn btn-alt-secondary" data-dialog-close>Cancel</button>
                <button type="button" class="btn btn-primary" id="global-server-search-apply">Select</button>
            </div>
        </div>
    </dialog>

    <dialog id="global-header-name-dialog" class="global-dialog" aria-labelledby="global-header-name-title">
        <div class="p-3">
            <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h5 mb-0" id="global-header-name-title">Save Header</h3><button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close aria-label="Close save header dialog">×</button></div>
            <label for="new-header-name">Header name</label>
            <input id="new-header-name" class="form-control mb-3" maxlength="255" required placeholder="e.g., Standard HTML MIME">
            <div class="d-flex justify-content-end" style="gap: 8px;">
                <button class="btn btn-alt-secondary" type="button" data-dialog-close>Cancel</button>
                <button class="btn btn-primary" type="button" id="confirm-save-header">Save Header</button>
            </div>
        </div>
    </dialog>

    <dialog id="global-confirm-dialog" class="global-dialog" aria-labelledby="global-confirm-title">
        <div class="p-3">
            <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h5 mb-0" id="global-confirm-title">Delete Saved Header?</h3><button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close aria-label="Close confirm dialog">×</button></div>
            <p id="global-confirm-copy" class="mb-3">This removes the selected header from your workspace.</p>
            <div class="d-flex justify-content-end" style="gap: 8px;">
                <button class="btn btn-alt-secondary" type="button" data-dialog-close>Cancel</button>
                <button class="btn btn-danger" type="button" id="confirm-delete-header">Delete Header</button>
            </div>
        </div>
    </dialog>

    <dialog id="global-preview-dialog" class="global-dialog global-preview-dialog" aria-labelledby="global-preview-title">
        <div class="p-3" style="height: 100%; display: flex; flex-direction: column;">
            <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h5 mb-0" id="global-preview-title">HTML Preview</h3><button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close aria-label="Close preview">×</button></div>
            <iframe id="global-preview-frame" title="Message body preview" sandbox="" referrerpolicy="no-referrer" style="flex: 1;"></iframe>
            <div class="d-flex justify-content-end mt-3"><button class="btn btn-alt-secondary" type="button" data-dialog-close>Close</button></div>
        </div>
    </dialog>

    <dialog id="global-tags-dialog" class="global-dialog" aria-labelledby="global-tags-title">
        <div class="p-3">
            <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h5 mb-0" id="global-tags-title">Usable Tags Example</h3><button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close aria-label="Close tag examples">×</button></div>
            <div class="global-tag-reference small">
                <p><code>[ip]</code> IP · <code>[server]</code> server name · <code>[main_domain]</code> server domain · <code>[domain]</code> selected IP domain · <code>[rdns]</code> reverse DNS · <code>[to]</code> recipient · <code>[date]</code> current date · <code>[name]</code> recipient name · <code>[rnd]</code> random text.</p>
                <p><code>[rnda_10]</code> random alphanumeric string · <code>[text:a|b|c]</code> chooses one listed value · <code>[negative]</code> Negative Token File · <code>[smtp]</code> SMTP username · <code>[smtp_name]</code> SMTP label.</p>
                <p class="mb-0"><code>[placeholder1]</code>, <code>[placeholder2]</code> rotate through configured placeholder rows.</p>
            </div>
            <div class="d-flex justify-content-end mt-3"><button class="btn btn-alt-secondary" type="button" data-dialog-close>Close</button></div>
        </div>
    </dialog>

    <dialog id="global-encoding-dialog" class="global-dialog global-encoding-dialog" aria-labelledby="global-encoding-title">
        <div class="p-3">
            <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h5 mb-0" id="global-encoding-title">Encoding</h3><button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close aria-label="Close encoding tools">×</button></div>
            <ul class="nav nav-tabs mb-3" role="tablist" aria-label="Encoding tools">
                <li class="nav-item"><button class="nav-link active" type="button" role="tab" aria-selected="true" aria-controls="global-encoding-string" id="global-encoding-string-tab">STRING</button></li>
                <li class="nav-item"><button class="nav-link" type="button" role="tab" aria-selected="false" aria-controls="global-encoding-ip" id="global-encoding-ip-tab">IP</button></li>
            </ul>
            <section id="global-encoding-string" role="tabpanel" aria-labelledby="global-encoding-string-tab">
                <div class="form-row align-items-end">
                    <div class="form-group col-12 col-md-5"><label for="encoding-text">Your String</label><input id="encoding-text" class="form-control"></div>
                    <div class="form-group col-6 col-md-3"><label for="encoding-schema">Schema</label><select id="encoding-schema" class="form-control"><option value="Q">Quoted-Printable</option><option value="HQ">Hex-Quoted-Printable</option><option value="B">Base64</option></select></div>
                    <div class="form-group col-6 col-md-2"><label for="encoding-charset">Charset</label><select id="encoding-charset" class="form-control"><option>UTF-8</option><option>UTF-7</option><option>ISO-8859-1</option><option>ISO-8859-15</option><option>windows-1252</option><option>US-ASCII</option><option>CP1256</option></select></div>
                    <div class="form-group col-12 col-md-2"><button id="run-string-encoding" class="btn btn-primary w-100" type="button">ENCODE</button></div>
                </div>
                <label for="encoding-result">Result</label><textarea id="encoding-result" class="form-control" rows="4" readonly></textarea>
            </section>
            <section id="global-encoding-ip" role="tabpanel" aria-labelledby="global-encoding-ip-tab" hidden>
                <label for="encoding-ip">IP</label>
                <div class="input-group mb-3"><input id="encoding-ip" class="form-control" placeholder="IPv4 address"><div class="input-group-append"><button id="run-ip-encoding" class="btn btn-primary" type="button">ENCODE</button></div></div>
                <label for="encoding-ip-result">Result</label><textarea id="encoding-ip-result" class="form-control" rows="6" readonly></textarea>
            </section>
            <div class="d-flex justify-content-end mt-3"><button class="btn btn-alt-secondary" type="button" data-dialog-close>Close</button></div>
        </div>
    </dialog>
@endsection

@push('styles')
    <style>
        /* Direct 1:1 Edit Campaign Composer Visual System with Precision Baseline Alignment */
        body.command-os-campaign-composer {
            --campaign-ink: #101746;
            --campaign-muted: #667092;
            --campaign-border: #dce0f4;
            --campaign-surface: #fff;
            --campaign-wash: #f8f7ff;
            --campaign-canvas: #faf9ff;
            --campaign-purple: #792bfa;
            --campaign-blue: #238dff;
        }
        html[data-command-theme='dark'] body.command-os-campaign-composer {
            --campaign-ink: #f1f3ff;
            --campaign-muted: #abb6d8;
            --campaign-border: #26365f;
            --campaign-surface: #111d3b;
            --campaign-wash: #152342;
            --campaign-canvas: #0b1530;
            --campaign-purple: #bd72ff;
            --campaign-blue: #3ca9ff;
        }
        .campaign-create-page {
            color: var(--campaign-ink);
            font-family: Inter, 'Segoe UI', Arial, sans-serif;
            isolation: isolate;
            min-width: 0;
            position: relative;
        }
        .campaign-create-hero {
            align-items: center;
            background-color: transparent;
            background-image: linear-gradient(90deg, rgb(255 255 255 / 92%) 0%, rgb(255 255 255 / 74%) 34%, rgb(255 255 255 / 20%) 100%), var(--campaign-hero-art);
            background-position: center 46%;
            background-size: cover;
            display: flex;
            gap: 16px;
            margin: 0 0 8px;
            min-height: 78px;
            overflow: hidden;
            padding: 8px 4px;
        }
        .campaign-create-hero-icon {
            align-items: center;
            background: linear-gradient(145deg, #c45bff, #7027f4 72%);
            border: 1px solid rgb(255 255 255 / 72%);
            border-radius: 17px;
            box-shadow: 0 8px 20px rgb(112 39 244 / 28%);
            color: #fff;
            display: flex;
            flex: 0 0 58px;
            font-size: 25px;
            height: 58px;
            justify-content: center;
        }
        .campaign-create-hero-copy { min-width: 0; }
        .campaign-create-hero h1 { color: #101443; font-size: clamp(23px, 1.8vw, 29px); font-weight: 800; letter-spacing: -.045em; line-height: 1.08; margin: 0; }
        .campaign-create-hero-copy > p:last-child { color: #303c70; font-size: 13px; margin: 4px 0 0; }
        .global-history-link { color: var(--campaign-purple); text-decoration: none; }
        .global-history-link:hover { text-decoration: underline; color: var(--campaign-purple); }
        .global-status-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; margin-right: 4px; vertical-align: middle; }
        .global-status-dot.is-ready { background: #10b981; }
        .global-status-dot.is-unavailable { background: #f97316; }

        /* Stage Track matching Edit Campaign 1:1 */
        .campaign-stage-track {
            margin: 0 0 14px;
            overflow: hidden;
            border: 1px solid #d9dcf3;
            border-radius: 17px;
            background: linear-gradient(105deg, #fff 0%, #fbfaff 53%, #f3f1ff 100%);
            box-shadow: 0 5px 16px rgb(63 67 140 / 6%);
        }
        .campaign-stage-track ol { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); list-style: none; margin: 0; padding: 0; }
        .campaign-stage-track li { align-items: center; color: #26315e; cursor: pointer; display: flex; gap: 11px; min-height: 56px; padding: 8px 17px; position: relative; z-index: 1; }
        .campaign-stage-track li + li { padding-left: 25px; }
        .campaign-stage-track li:not(:last-child)::after { border-bottom: 28px solid transparent; border-left: 14px solid #f0effb; border-top: 28px solid transparent; content: ''; height: 0; position: absolute; right: -13px; top: 0; width: 0; z-index: 2; }
        .campaign-stage-number { align-items: center; background: #fff; border: 1px solid #c9ccef; border-radius: 50%; color: #15204d; display: inline-flex; flex: 0 0 36px; font-size: 16px; font-weight: 750; height: 36px; justify-content: center; }
        .campaign-stage-track li strong, .campaign-stage-track li small { display: block; line-height: 1.2; }
        .campaign-stage-track li strong { font-size: 13px; font-weight: 780; }
        .campaign-stage-track li small { color: #687293; font-size: 11px; margin-top: 2px; }
        .campaign-stage-track li.is-current { background: linear-gradient(110deg, #c65aff, #762df4 75%); color: #fff; z-index: 4; }
        .campaign-stage-track li.is-current::after { border-left-color: #762df4; }
        .campaign-stage-track li.is-current .campaign-stage-number { background: rgb(255 255 255 / 20%); border-color: rgb(255 255 255 / 75%); color: #fff; }
        .campaign-stage-track li.is-current small { color: rgb(255 255 255 / 88%); }

        /* Composer Grid */
        .campaign-create-layout { display: grid; gap: 16px; grid-template-columns: minmax(0, 1fr) 310px; align-items: start; }
        .campaign-create-main { min-width: 0; }
        .campaign-create-summary { position: sticky; top: 56px; min-width: 0; }
        .campaign-create-page .campaign-email-card,
        .campaign-create-page .campaign-delivery-card {
            background: linear-gradient(145deg, #fff 0%, #fcfbff 62%, #f8f7ff 100%);
            border: 1px solid var(--campaign-border);
            border-radius: 15px;
            box-shadow: 0 6px 18px rgb(78 64 164 / 7%);
            margin-bottom: 12px;
            overflow: visible;
        }
        .campaign-create-page .campaign-email-card > .block-header,
        .campaign-create-page .campaign-delivery-card > .block-header {
            align-items: center;
            background-image: linear-gradient(135deg, transparent 72%, rgb(143 75 247 / 5%) 72%, rgb(143 75 247 / 5%) 80%, transparent 80%, transparent 84%, rgb(143 75 247 / 8%) 84%, rgb(143 75 247 / 8%) 92%, transparent 92%);
            border-bottom: 1px solid #f0eff8;
            border-radius: 15px 15px 0 0;
            display: flex;
            flex-wrap: wrap;
            gap: 4px 12px;
            min-height: 42px;
            padding: 8px 14px;
        }
        .campaign-create-page .block-title { color: var(--campaign-ink); font-size: 15px; font-weight: 790; letter-spacing: -.025em; margin: 0; }
        .campaign-create-page .block-title > i { color: var(--campaign-purple); margin-right: 7px; }
        .campaign-section-caption { color: var(--campaign-muted); font-size: 11px; }
        .campaign-create-page .campaign-email-card > .block-content { padding: 10px 14px 14px; }
        .campaign-create-page .campaign-delivery-card > .block-content { padding: 10px 14px 14px; }

        /* Precision Baseline Label Alignment across ALL Columns */
        body.command-os-campaign-composer .campaign-create-page label {
            align-items: center;
            color: var(--campaign-ink);
            display: flex;
            font-size: 11px !important;
            font-weight: 700 !important;
            gap: 4px;
            letter-spacing: normal !important;
            margin-bottom: 4px !important;
            min-height: 25px;
            text-transform: none !important;
        }
        .campaign-create-page .campaign-field-icon { color: var(--campaign-purple); display: inline-block; font-size: 11px; margin-right: 2px; text-align: center; width: 13px; }
        .campaign-create-page .form-group { margin-bottom: 6px; min-width: 0; }
        .campaign-create-page .form-control:not(textarea):not([multiple]):not(.bootstrap-select) {
            background-color: #fff; border: 1px solid #cfd4ed; border-radius: 7px; color: #151d43; font-size: 12px; height: 36px; min-height: 36px; padding: 4px 10px;
        }
        .campaign-create-page .campaign-email-card #email_test { height: 36px; min-height: 36px; max-height: 140px; padding: 6px 10px; border: 1px solid #cfd4ed; border-radius: 7px; font-size: 12px; resize: vertical; }
        .campaign-create-page .bootstrap-select.form-control { background: transparent !important; border: 0 !important; box-shadow: none !important; height: auto; min-height: 0; padding: 0 !important; width: 100% !important; }
        .campaign-create-page .bootstrap-select > .dropdown-toggle {
            align-items: center !important; background: linear-gradient(145deg, var(--campaign-surface), var(--campaign-wash)); border: 1px solid var(--campaign-border) !important;
            border-radius: 7px !important; box-shadow: none !important; color: var(--campaign-ink); display: flex !important; font-size: 12px; height: 36px !important;
            justify-content: space-between !important; min-height: 36px !important; padding: 0 10px !important; width: 100% !important;
        }
        .campaign-create-page .bootstrap-select .dropdown-menu,
        body.command-os-campaign-composer .campaign-select.bs-container > .dropdown-menu {
            background: linear-gradient(145deg, var(--campaign-surface), var(--campaign-wash)) !important; border: 1px solid var(--campaign-border) !important;
            border-radius: 14px !important; box-shadow: 0 16px 40px rgb(34 25 76 / 19%) !important; overflow: hidden !important; padding: 6px !important;
        }
        .campaign-select.bs-container .dropdown-menu li a { border-radius: 8px; color: var(--campaign-ink); font-size: 11px; margin: 1px 0; padding: 7px 10px; }
        .campaign-select.bs-container .dropdown-menu li a:hover,
        .campaign-select.bs-container .dropdown-menu li.active a,
        .campaign-select.bs-container .dropdown-menu li.selected a { background: #f1eaff; color: #6123c8; }
        .campaign-select.bs-container .bs-searchbox .form-control { background: var(--campaign-surface); border: 1px solid var(--campaign-border) !important; border-radius: 8px; color: var(--campaign-ink); min-height: 36px; }
        .campaign-select.bs-container .bs-actionsbox .btn-group > .btn { background: var(--campaign-wash); border: 1px solid var(--campaign-border); border-radius: 8px !important; color: var(--campaign-ink); font-size: 11px; font-weight: 700; margin: 0 3px; }
        .campaign-create-page .campaign-header-library .input-group,
        .campaign-create-page #campaign-saved-emails .input-group { display: flex; flex-wrap: nowrap; gap: 6px; }
        .campaign-create-page .campaign-header-library .input-group > .bootstrap-select.form-control,
        .campaign-create-page #campaign-saved-emails .input-group > .bootstrap-select.form-control { flex: 1 1 0; min-width: 0; width: 1% !important; }
        .campaign-create-page .campaign-header-library .input-group > .input-group-append,
        .campaign-create-page #campaign-saved-emails .input-group > .input-group-append { display: flex; flex: 0 0 auto; gap: 5px; }
        .campaign-create-page .campaign-icon-button {
            align-items: center; background: linear-gradient(145deg, #f0e7ff, #e8eaff); border: 1px solid #d9c8fb; border-radius: 9px;
            box-shadow: 0 2px 6px rgb(79 56 145 / 10%); color: #7135d4; cursor: pointer; display: inline-flex; flex: 0 0 36px; height: 36px; justify-content: center; padding: 0; width: 36px;
        }
        .campaign-create-page .campaign-icon-button.is-add { background: linear-gradient(145deg, #e8fbf5, #ddf6ee); border-color: #b8e8d9; color: #138465; }
        .campaign-create-page .campaign-icon-button.is-delete { background: linear-gradient(145deg, #fff0f2, #ffe8eb); border-color: #f4c4cd; color: #bd4054; }
        .campaign-create-page .campaign-icon-button:disabled { opacity: .48; cursor: not-allowed; box-shadow: none; }
        .campaign-create-page label .btn-link,
        .campaign-create-page #show-global-tags { align-items: center; background: linear-gradient(145deg, #fff, #f2efff); border: 1px solid #e0d8f4; border-radius: 8px; color: #7137dc; display: inline-flex; height: 24px; justify-content: center; margin: 0 0 0 3px; padding: 0; width: 24px; }

        /* Single-line Usable Tags Banner */
        .campaign-create-page #campaign-usable-tags {
            align-items: center; background: linear-gradient(100deg, #fff, #f6f3ff); border: 1px solid #ddd7ff; border-radius: 12px;
            color: #5f32d5; display: flex; flex-wrap: wrap; font-size: 10px; gap: 4px 7px; justify-content: flex-start; margin: 0 0 12px; padding: 8px 12px !important;
        }
        .campaign-create-page #campaign-usable-tags > .campaign-usable-tag-list { display: inline-flex; flex-wrap: wrap; gap: 2px; line-height: 1.35; }
        .global-tag-button { background: transparent; border: 0; border-radius: 5px; color: #5f32d5; cursor: pointer; font-family: ui-monospace, monospace; font-size: 10.5px; font-weight: 700; padding: 1px 4px; }
        .global-tag-button:hover { background: rgb(139 92 246 / 13%); color: #4c1d95; }
        .campaign-create-page #campaign-usable-tags .close { margin-left: auto; position: static; padding: 0 4px; }

        /* Equal-height Editors matching Edit Campaign */
        .campaign-editor-heading { align-items: center; display: flex; flex-wrap: wrap; gap: 8px 12px; justify-content: space-between; margin: 0 0 7px; min-height: 36px; }
        .campaign-editor-title { align-items: center; color: var(--campaign-ink); display: inline-flex; font-size: 12px !important; font-weight: 800 !important; gap: 6px; margin: 0 !important; min-height: 0 !important; }
        .campaign-editor-tools { align-items: center; display: flex; gap: 6px; }
        .campaign-editor-icon-button { align-items: center; background: linear-gradient(145deg, #fff, #f2efff); border: 1px solid #ddd5f4; border-radius: 10px; box-shadow: 0 2px 5px rgb(57 43 109 / 6%); color: #7137dc; cursor: pointer; display: inline-flex; height: 34px; justify-content: center; width: 34px; }
        .campaign-editor-icon-button:hover { background: #eee8ff; border-color: #bba4ee; color: #5921bc; transform: translateY(-1px); }
        #global-test-form .ace_editor, .global-test-editor {
            height: 360px !important; min-height: 320px; width: 100%; padding: 0 !important; overflow: hidden;
            background: #fcfbff; border: 1px solid #cfd4ed; border-radius: 8px; font-family: 'SFMono-Regular', Consolas, monospace; font-size: 12px;
        }

        /* Placeholders matching Edit Campaign 1:1 + Closable */
        .campaign-delivery-card [data-campaign-placeholder-editor] { background: linear-gradient(145deg, #fff, #faf8ff); border: 1px solid #e4ddfa; border-radius: 13px; box-shadow: 0 4px 14px rgb(75 55 139 / 6%); overflow: hidden; }
        .campaign-delivery-card [data-campaign-placeholder-editor] > .block-header { align-items: center; background: linear-gradient(105deg, #f7f3ff, #f8faff); border-bottom: 1px solid #ebe5fb; display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; min-height: 48px; padding: 8px 12px; }
        .campaign-delivery-card [data-campaign-placeholder-editor] > .block-content { padding: 10px 12px 12px; }
        .campaign-delivery-card [data-campaign-placeholder-editor] > .block-content[hidden] { display: none !important; }
        .campaign-delivery-card [data-campaign-placeholder-editor] .table-responsive:not(:has(tbody tr)) { display: none; }
        .campaign-placeholder-heading .block-title { font-size: 12px; font-weight: 850; margin: 0 0 2px; }
        .campaign-placeholder-heading p { color: var(--campaign-muted); font-size: 10px; margin: 0; }
        .campaign-placeholder-toolbar { flex-wrap: wrap; gap: 8px; justify-content: flex-end; margin: 0; }
        .campaign-placeholder-toolbar [data-placeholder-add] { align-items: center; background: linear-gradient(140deg, #7f3ef2, #a24ef7); border: 1px solid #7633e7; border-radius: 9px; box-shadow: 0 3px 9px rgb(111 48 222 / 20%); color: #fff; display: inline-flex; font-size: 11px; font-weight: 750; gap: 7px; min-height: 36px; padding: 5px 13px; cursor: pointer; }
        .campaign-mode-toggle { align-items: center; background: linear-gradient(145deg, #fff, #f8f6ff); border: 1px solid #e2ddf0; border-radius: 10px; color: #4f5875; cursor: pointer; display: inline-flex; gap: 8px; margin: 0 !important; min-height: 36px !important; padding: 6px 10px; position: relative; }
        .campaign-mode-input { position: absolute; opacity: 0; width: 1px; height: 1px; }
        .campaign-mode-track { background: #c4cad7; border-radius: 999px; height: 20px; position: relative; width: 34px; transition: background .16s ease; }
        .campaign-mode-track::after { background: #fff; border-radius: 50%; content: ''; height: 14px; left: 3px; position: absolute; top: 3px; transition: transform .16s ease; width: 14px; }
        .campaign-mode-input:checked + .campaign-mode-track { background: #8543ed; }
        .campaign-mode-input:checked + .campaign-mode-track::after { transform: translateX(14px); }
        .campaign-mode-label { font-size: 10px; font-weight: 800; text-transform: uppercase; white-space: nowrap; }
        .campaign-create-page [data-campaign-placeholder-editor] .table { border-collapse: separate; border-spacing: 0; margin-bottom: 0; }
        .campaign-create-page [data-campaign-placeholder-editor] .table thead th { background: #f4f3fb; border-bottom: 1px solid #e4e5f1; border-top: 0; color: #555d78; font-size: 10px; font-weight: 800; letter-spacing: .045em; padding: 10px; vertical-align: middle; }
        .campaign-create-page [data-campaign-placeholder-editor] .table thead th:nth-child(1) { width: 56%; }
        .campaign-create-page [data-campaign-placeholder-editor] .table thead th:nth-child(2) { width: 29%; }
        .campaign-create-page [data-campaign-placeholder-editor] .table thead th:nth-child(3) { width: 15%; }
        .campaign-create-page [data-campaign-placeholder-editor] .table tbody td { background: rgb(255 255 255 / 78%); border-top: 1px solid #ececf5; padding: 8px 7px; vertical-align: middle; }
        .global-placeholder-token-chip { display: inline-block; background: #f1eaff; border: 1px solid #dfd3ff; border-radius: 6px; color: #642ec6; font-family: ui-monospace, monospace; font-size: 10px; font-weight: 800; margin-bottom: 4px; padding: 1px 7px; }

        /* Routing Section matching Edit Campaign 1:1 */
        #campaign-routing { padding-top: 10px; }
        .campaign-route-pools-heading { align-items: center; display: flex; gap: 12px; justify-content: space-between; margin: 6px 0 2px; }
        .campaign-route-pools-heading > div { align-items: center; display: flex; flex: 1 1 auto; flex-wrap: wrap; gap: 5px 8px; min-width: 0; }
        .campaign-route-pools-heading > div > span { color: var(--campaign-ink); font-size: 12px; font-weight: 800; }
        .campaign-route-pools-heading small { color: var(--campaign-muted); font-size: 10px; }
        .campaign-route-kind { background: #f1eaff; border: 1px solid #e2d6ff; border-radius: 999px; color: #6631c7; flex: 0 0 auto; font-size: 10px; font-weight: 800; padding: 4px 9px; white-space: nowrap; }
        .campaign-route-picker { display: grid !important; gap: 12px; grid-template-columns: minmax(0, 1fr) 52px minmax(0, 1fr); margin: 8px 0 10px; }
        .campaign-route-selector { background: linear-gradient(145deg, #fff, #faf9ff); border: 1px solid #e1def1; border-radius: 14px; box-shadow: 0 4px 12px rgb(58 45 111 / 5%); min-width: 0; padding: 12px; }
        .campaign-route-pane-heading { align-items: center; display: flex; gap: 7px; justify-content: space-between; margin-bottom: 8px; min-height: 30px; }
        .campaign-route-pane-heading label { align-items: center; display: flex; flex: 1 1 auto; font-size: 11px !important; font-weight: 800 !important; gap: 7px; margin: 0 !important; min-height: 0 !important; min-width: 0; }
        .campaign-route-count { align-items: center; background: #f0eaff; border: 1px solid #e5d9ff; border-radius: 999px; color: #642ec6; display: inline-flex; flex: 0 0 auto; font-size: 10px; font-weight: 800; justify-content: center; min-height: 23px; min-width: 26px; padding: 0 7px; }
        .campaign-route-filter { height: 36px !important; margin-bottom: 8px !important; min-height: 36px !important; }
        #ips-available, #ips-selected { background: linear-gradient(180deg, #fff, #fafaff); border: 1px solid #dce0f0; border-radius: 10px; box-sizing: border-box; height: 320px !important; min-height: 320px !important; overflow: auto; padding: 7px; width: 100%; font-size: 12px; }
        #ips-available option, #ips-selected option { border-radius: 6px; color: #242c4b; line-height: 1.55; padding: 8px 9px; }
        .campaign-route-transfer-controls { align-content: center; display: grid !important; gap: 7px; grid-template-columns: minmax(0, 1fr); width: 100%; }
        .campaign-route-transfer-controls [data-ip-move] { align-items: center; background: linear-gradient(145deg, #fff, #f3efff); border: 1px solid #ddd3f8; border-radius: 11px; box-shadow: 0 2px 7px rgb(59 44 117 / 8%); color: #6d35d5; cursor: pointer; display: inline-flex; font-size: 16px; height: 44px; justify-content: center; margin: 0 !important; min-width: 44px; padding: 0 4px; width: 100%; }
        .campaign-route-transfer-controls [data-ip-move]:hover:not(:disabled) { background: #eee7ff; transform: translateY(-1px); }
        .campaign-route-transfer-controls [data-ip-move]:disabled { cursor: not-allowed; opacity: .45; box-shadow: none; }
        #send-route-behavior { background: #f7f5ff; border-left: 3px solid #9a5aff; border-radius: 0 7px 7px 0; display: block; margin-top: 8px; padding: 7px 9px; }
        .global-tests-send-estimate { background: #f7f5ff; border: 1px solid #e1def1; border-radius: 10px; color: var(--campaign-ink); padding: 9px 11px; }
        .global-tests-send-estimate.is-over-limit { background: #fff1f2; border-color: #fda4af; color: #9f1239; }

        /* Summary Sidebar matching Edit Campaign 1:1 */
        .campaign-summary-card { background: var(--campaign-surface); border: 1px solid var(--campaign-border); border-radius: 15px; box-shadow: 0 5px 18px rgb(63 67 140 / 7%); margin-bottom: 10px; padding: 13px; }
        .campaign-summary-heading { align-items: center; border-bottom: 1px solid #efedfa; display: flex; gap: 10px; padding-bottom: 10px; }
        .campaign-summary-icon { align-items: center; background: linear-gradient(145deg, #efe7ff, #e1e9ff); border-radius: 11px; color: #7b34f4; display: inline-flex; flex: 0 0 38px; font-size: 17px; height: 38px; justify-content: center; }
        .campaign-summary-card h2 { color: var(--campaign-ink); font-size: 15px; font-weight: 790; letter-spacing: -.025em; margin: 0; }
        .campaign-summary-heading p { color: var(--campaign-muted); font-size: 10px; line-height: 1.35; margin: 3px 0 0; }
        .campaign-summary-checklist { list-style: none; margin: 6px 0 0; padding: 0; }
        .campaign-summary-checklist li { align-items: center; border-bottom: 1px solid #f0eff8; display: flex; justify-content: space-between; gap: 8px; min-height: 33px; }
        .campaign-summary-checklist li:last-child { border-bottom: 0; }
        .campaign-summary-checklist li > span { color: var(--campaign-ink); font-size: 11px; font-weight: 680; white-space: nowrap; }
        .campaign-summary-checklist li > span i { color: #7941ee; margin-right: 6px; width: 15px; }
        .campaign-summary-checklist strong { color: #8a90a8; font-size: 10px; font-weight: 690; text-align: right; }
        .campaign-summary-checklist strong.is-ready { color: #15975a; }
        .campaign-summary-checklist strong.is-pending { color: #dc8b20; }
        .campaign-summary-checklist strong.is-optional { color: #7b66bb; }
        .campaign-summary-stats { display: grid; gap: 7px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: 10px; }
        .campaign-summary-stats > div { align-items: center; background: linear-gradient(150deg, #fff, #f8f7ff); border: 1px solid #e2e1f3; border-radius: 11px; display: flex; flex-direction: column; min-height: 78px; justify-content: center; padding: 7px 3px; text-align: center; }
        .campaign-summary-stats i { color: #8035f4; font-size: 16px; margin-bottom: 3px; }
        .campaign-summary-stats strong { color: var(--campaign-ink); font-size: 18px; font-variant-numeric: tabular-nums; font-weight: 800; line-height: 1.05; }
        .campaign-summary-stats span { color: var(--campaign-muted); font-size: 9px; margin-top: 3px; }
        .campaign-readiness-heading { align-items: center; display: flex; justify-content: space-between; gap: 8px; }
        .campaign-readiness-heading strong { color: #7535ee; font-size: 12px; }
        .campaign-readiness-track { background: #eceafd; border-radius: 99px; height: 10px; margin: 10px 0 6px; overflow: hidden; }
        .campaign-readiness-track span { background: linear-gradient(90deg, #c04eff, #6d37f8); border-radius: inherit; display: block; height: 100%; transition: width .2s ease; width: 0; }
        .campaign-readiness-card small { color: var(--campaign-muted); font-size: 10px; }
        .campaign-summary-actions { background: linear-gradient(145deg, #fff, #f6f3ff); border: 1px solid #ded9f8; border-radius: 15px; box-shadow: 0 6px 18px rgb(77 56 169 / 8%); padding: 12px; }
        .campaign-summary-actions p { color: var(--campaign-muted); font-size: 10px; line-height: 1.45; margin: 0 0 10px; }
        .campaign-create-submit { background: linear-gradient(100deg, #b34eff, #6e2df1); border: 0; border-radius: 11px; box-shadow: 0 7px 16px rgb(112 42 242 / 22%); color: #fff; font-size: 14px; font-weight: 780; min-height: 46px; width: 100%; }
        .campaign-create-submit:hover:not(:disabled) { background: linear-gradient(100deg, #a53df5, #5e22dd); color: #fff; }
        .campaign-create-submit:disabled { opacity: .52; cursor: not-allowed; box-shadow: none; }
        .campaign-cancel-link { border-color: #d9d8ed; border-radius: 9px; color: var(--campaign-ink); display: block; font-size: 12px; font-weight: 700; margin-top: 8px; padding: 8px; text-align: center; }

        /* Floating Tools & Context Menu */
        #global-quick-nav { position: fixed; bottom: 20px; right: max(18px, env(safe-area-inset-right)); z-index: 1100; }
        .global-quick-nav-trigger { background: linear-gradient(145deg, #b64eff, #7028f4 72%); border: 1px solid rgba(255, 255, 255, .86); border-radius: 16px; box-shadow: 0 8px 22px rgb(104 44 225 / 34%); color: #fff; display: grid; height: 48px; place-items: center; width: 48px; cursor: pointer; }
        .global-quick-nav-menu { position: fixed; right: max(18px, env(safe-area-inset-right)); bottom: 78px; width: 228px; padding: 7px; background: linear-gradient(145deg, var(--campaign-surface), var(--campaign-wash)); border: 1px solid var(--campaign-border); border-radius: 18px; box-shadow: 0 18px 48px rgb(35 23 88 / 24%); }
        .global-quick-nav-menu[hidden], #global-test-context-menu[hidden] { display: none !important; }
        #global-test-context-menu { position: fixed; z-index: 1110; width: 228px; padding: 8px; background: linear-gradient(145deg, var(--campaign-surface), var(--campaign-wash)); border: 1px solid var(--campaign-border); border-radius: 18px; box-shadow: 0 20px 52px rgb(35 23 88 / 26%); }
        .global-quick-nav-menu .campaign-menu-heading, #global-test-context-menu .campaign-menu-heading { border-bottom: 1px solid rgb(127 91 205 / 14%); color: #7851ce; display: flex; font-size: 9px; font-weight: 800; justify-content: space-between; letter-spacing: .13em; margin: 0 4px 5px; padding: 5px 7px 9px; }
        .global-quick-nav-menu button, #global-test-context-menu button { align-items: center; background: transparent; border: 0; border-radius: 11px; color: var(--campaign-ink); cursor: pointer; display: flex; font-size: 10px; font-weight: 760; gap: 10px; min-height: 40px; padding: 6px 8px; width: 100%; text-align: left; }
        .global-quick-nav-menu button:hover, #global-test-context-menu button:hover { background: linear-gradient(100deg, rgb(142 73 250 / 13%), rgb(106 75 235 / 6%)); color: #6123c8; }
        .global-quick-nav-menu button i, #global-test-context-menu button i { align-items: center; background: linear-gradient(145deg, #f0e6ff, #e7edff); border: 1px solid rgb(139 83 236 / 14%); border-radius: 9px; color: #7837ef; display: inline-flex; height: 30px; justify-content: center; width: 30px; }

        /* Output & Modals */
        .global-test-output { max-height: 24rem; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; background: var(--campaign-wash); border: 1px solid var(--campaign-border); border-radius: 10px; padding: 1rem; font-size: 12px; }
        dialog.global-dialog { background: linear-gradient(145deg, var(--campaign-surface), var(--campaign-wash)); border: 1px solid var(--campaign-border); border-radius: 20px; box-shadow: 0 26px 72px rgb(31 21 75 / 30%), 0 4px 14px rgb(33 23 78 / 12%); color: var(--campaign-ink); max-height: min(88dvh, 900px); max-width: calc(100vw - 28px); overflow: auto; overscroll-behavior: contain; padding: 0; width: min(720px, calc(100vw - 28px)); }
        dialog.global-dialog::backdrop { background: rgb(19 13 48 / 54%); backdrop-filter: blur(5px); }
        dialog.global-dialog > .p-3 { padding: clamp(16px, 2.2vw, 26px) !important; }
        dialog.global-dialog .d-flex.justify-content-between.align-items-center { background: linear-gradient(100deg, rgb(139 79 247 / 10%), rgb(81 150 255 / 5%)); border-bottom: 1px solid rgb(126 105 190 / 15%); margin: calc(clamp(16px, 2.2vw, 26px) * -1) calc(clamp(16px, 2.2vw, 26px) * -1) 18px !important; padding: 15px clamp(16px, 2.2vw, 26px); }
        dialog.global-dialog .nav-tabs { border-bottom-color: var(--campaign-border); gap: 5px; }
        dialog.global-dialog .nav-tabs .nav-link { border: 1px solid transparent; border-radius: 9px 9px 0 0; color: var(--campaign-muted); font-size: 10px; font-weight: 800; }
        dialog.global-dialog .nav-tabs .nav-link.active { background: var(--campaign-surface); border-color: var(--campaign-border) var(--campaign-border) var(--campaign-surface); color: var(--campaign-purple); }
        dialog.global-dialog .btn-primary { background: linear-gradient(100deg, #a946ff, #672de8); border: 0; border-radius: 10px; font-weight: 750; }
        .global-preview-dialog { height: 80vh; max-width: min(70rem, calc(100vw - 24px)) !important; width: min(70rem, calc(100vw - 24px)) !important; }
        .global-preview-dialog iframe { border: 1px solid var(--campaign-border); border-radius: 10px; width: 100%; background: #ffffff; }

        /* Dark Mode */
        html[data-command-theme='dark'] .campaign-create-hero { background-color: #101b38; background-image: linear-gradient(90deg, rgb(9 17 38 / 87%) 0%, rgb(9 17 38 / 65%) 35%, rgb(9 17 38 / 27%) 100%), var(--campaign-hero-art); }
        html[data-command-theme='dark'] .campaign-create-hero h1, html[data-command-theme='dark'] .campaign-create-hero-copy > p:last-child { color: #f3f4ff; }
        html[data-command-theme='dark'] .campaign-stage-track { background: linear-gradient(105deg, #101936 0%, #141f42 54%, #1a244f 100%); border-color: #283a69; }
        html[data-command-theme='dark'] .campaign-stage-track li { color: #eef1ff; }
        html[data-command-theme='dark'] .campaign-stage-track li + li { border-color: #26365f; }
        html[data-command-theme='dark'] .campaign-stage-track li:not(:last-child)::after { border-left-color: #172447; }
        html[data-command-theme='dark'] .campaign-stage-track li.is-current::after { border-left-color: #762df4; }
        html[data-command-theme='dark'] .campaign-stage-track li small { color: #95a4cc; }
        html[data-command-theme='dark'] .campaign-stage-number { background: #18254a; border-color: #384c82; color: #eef2ff; }
        html[data-command-theme='dark'] .campaign-create-page .campaign-email-card,
        html[data-command-theme='dark'] .campaign-create-page .campaign-delivery-card,
        html[data-command-theme='dark'] .campaign-create-page .campaign-route-selector,
        html[data-command-theme='dark'] .campaign-create-page [data-campaign-placeholder-editor],
        html[data-command-theme='dark'] .campaign-summary-card,
        html[data-command-theme='dark'] .campaign-summary-stats > div,
        html[data-command-theme='dark'] .campaign-summary-actions { background: linear-gradient(145deg, #111d3b, #142140) !important; border-color: #26365f !important; }
        html[data-command-theme='dark'] .campaign-create-page .campaign-email-card > .block-header,
        html[data-command-theme='dark'] .campaign-create-page .campaign-delivery-card > .block-header,
        html[data-command-theme='dark'] .campaign-create-page [data-campaign-placeholder-editor] > .block-header,
        html[data-command-theme='dark'] .campaign-summary-heading,
        html[data-command-theme='dark'] .campaign-summary-checklist li,
        html[data-command-theme='dark'] .campaign-create-page [data-campaign-placeholder-editor] .table thead th { border-color: #26365f !important; background: #162345 !important; color: #e9edff; }
        html[data-command-theme='dark'] .campaign-create-page [data-campaign-placeholder-editor] .table tbody td { background: #101c3a; border-color: #26365f; }
        html[data-command-theme='dark'] .global-placeholder-token-chip { background: #27254e; border-color: #4b427d; color: #d4b7ff; }
        html[data-command-theme='dark'] .campaign-create-page #campaign-usable-tags,
        html[data-command-theme='dark'] .campaign-create-page .campaign-mode-toggle,
        html[data-command-theme='dark'] .campaign-create-page .campaign-editor-icon-button,
        html[data-command-theme='dark'] .campaign-create-page .campaign-route-transfer-controls [data-ip-move],
        html[data-command-theme='dark'] .campaign-create-page label .btn-link,
        html[data-command-theme='dark'] .campaign-create-page #show-global-tags,
        html[data-command-theme='dark'] .global-quick-nav-menu,
        html[data-command-theme='dark'] #global-test-context-menu { background: #172547 !important; border-color: #3a4c79 !important; color: #d4b7ff !important; }
        html[data-command-theme='dark'] .global-tag-button { color: #d4b7ff; }
        html[data-command-theme='dark'] .campaign-create-page .campaign-icon-button { background: linear-gradient(145deg, #28244b, #202947); border-color: #514477; color: #d8c1ff; }
        html[data-command-theme='dark'] .campaign-create-page .campaign-icon-button.is-add { background: linear-gradient(145deg, #183d3b, #163333); border-color: #2e6960; color: #83e2c4; }
        html[data-command-theme='dark'] .campaign-create-page .campaign-icon-button.is-delete { background: linear-gradient(145deg, #482936, #3b2635); border-color: #704454; color: #ff9aaa; }
        html[data-command-theme='dark'] .campaign-create-page .campaign-route-kind,
        html[data-command-theme='dark'] .campaign-create-page .campaign-route-count { background: #27254e; border-color: #4b427d; color: #d4b7ff; }
        html[data-command-theme='dark'] #global-test-form .ace_editor,
        html[data-command-theme='dark'] .global-test-editor,
        html[data-command-theme='dark'] #global-test-form .ace-github,
        html[data-command-theme='dark'] #global-test-form .ace-github .ace_scroller,
        html[data-command-theme='dark'] #ips-available,
        html[data-command-theme='dark'] #ips-selected,
        html[data-command-theme='dark'] .campaign-create-page .form-control,
        html[data-command-theme='dark'] .campaign-create-page .bootstrap-select > .dropdown-toggle,
        html[data-command-theme='dark'] .campaign-select.bs-container .dropdown-menu { background: #0c1731 !important; border-color: #334574 !important; color: #eff2ff !important; }
        html[data-command-theme='dark'] #ips-available option, html[data-command-theme='dark'] #ips-selected option { color: #eff2ff; }
        html[data-command-theme='dark'] .campaign-select.bs-container .dropdown-menu li a { color: #eff2ff; }
        html[data-command-theme='dark'] .campaign-select.bs-container .dropdown-menu li a:hover,
        html[data-command-theme='dark'] .campaign-select.bs-container .dropdown-menu li.selected a { background: #27254e; color: #eadcff; }
        html[data-command-theme='dark'] #global-test-form .ace-github { color: #e2e8f0 !important; }
        html[data-command-theme='dark'] #global-test-form .ace-github .ace_keyword,
        html[data-command-theme='dark'] #global-test-form .ace-github .ace_meta.ace_tag { color: #93c5fd !important; }
        html[data-command-theme='dark'] #global-test-form .ace-github .ace_string { color: #86efac !important; }
        html[data-command-theme='dark'] #global-test-form .ace-github .ace_variable,
        html[data-command-theme='dark'] #global-test-form .ace-github .ace_constant { color: #c4b5fd !important; }
        html[data-command-theme='dark'] .global-tests-send-estimate, html[data-command-theme='dark'] #send-route-behavior { background: #0c1731; border-color: #334574; color: #cbd5e1 !important; }
        html[data-command-theme='dark'] .global-tests-send-estimate.is-over-limit { background: #3b1723; border-color: #881337; color: #fecdd3; }
        html[data-command-theme='dark'] dialog.global-dialog { background: #0c1731; border-color: #334574; color: #eff2ff; }

        /* Exact Edit Campaign Responsive Breakpoints */
        @media (max-width: 1120px) {
            .campaign-create-layout { grid-template-columns: minmax(0, 1fr); }
            .campaign-create-summary { display: grid; gap: 10px; grid-template-columns: repeat(2, minmax(0, 1fr)); position: static; }
            .campaign-create-summary > * { margin-bottom: 0; }
        }
        @media (max-width: 991.98px) {
            .campaign-create-page .form-row > .col-md-4 { flex: 0 0 50%; max-width: 50%; }
            .campaign-create-page #campaign-routing > .row > .col-md-8,
            .campaign-create-page #campaign-routing > .row > .col-md-4 { flex: 0 0 100%; max-width: 100%; }
        }
        @media (max-width: 767.98px) {
            .campaign-stage-track ol { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .campaign-stage-track li:not(:last-child)::after { display: none; }
            .campaign-stage-track li + li { padding-left: 17px; }
            .campaign-stage-track li:nth-child(3), .campaign-stage-track li:nth-child(4) { border-top: 1px solid #e6e6f4; }
            .campaign-create-summary { grid-template-columns: minmax(0, 1fr); }
            .campaign-create-page .block-header { align-items: flex-start; flex-direction: column; }
            .campaign-placeholder-toolbar { width: 100%; justify-content: flex-start; }
            .campaign-route-picker { grid-template-columns: minmax(0, 1fr); }
            .campaign-route-transfer-controls { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .campaign-route-transfer-controls [data-ip-move] { height: 44px; min-height: 44px; }
            .campaign-create-page .form-row > [class*='col-'] { flex: 0 0 100%; max-width: 100%; }
            #global-test-form .ace_editor, .global-test-editor { height: 280px !important; min-height: 260px; }
        }
        @media (max-width: 480px) { .global-tests-overview { grid-template-columns: 1fr; } .global-tests-overview-cap { grid-column: auto; } .global-tests-hero-main { gap: .75rem; } .global-tests-hero-icon { flex-basis: 2.8rem; height: 2.8rem; } .global-tests-hero p { font-size: .8rem; } }
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('console-assets/vendor/ace/ace.js') }}" charset="utf-8"></script>
    <script src="{{ asset('console-assets/vendor/ace/ext-language_tools.js') }}"></script>
    <script src="{{ asset('console-assets/global-tests.js') }}?v={{ substr(sha1_file(public_path('console-assets/global-tests.js')), 0, 12) }}" defer></script>
@endpush
