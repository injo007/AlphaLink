@extends('layouts.admin-shell')

@section('title', 'Global Tests')

@section('content')
    <div id="global-quick-nav" class="global-floating-actions">
        <button class="global-quick-nav-trigger" id="quick-nav-toggle" type="button" aria-label="Open quick navigation" aria-expanded="false" aria-controls="global-quick-nav-menu">
            <i class="fas fa-bars" aria-hidden="true"></i>
        </button>
        <div class="global-quick-nav-menu" id="global-quick-nav-menu" role="menu" aria-label="Global Test actions" hidden>
            <div class="campaign-menu-heading">
                <span>GLOBAL TEST TOOLS</span>
                <i class="fas fa-vial" aria-hidden="true"></i>
            </div>
            <button type="button" role="menuitem" data-quick-action="send"><i class="fas fa-vial" aria-hidden="true"></i><span>SEND TEST</span></button>
            <button type="button" role="menuitem" data-quick-action="pmta-admin"><i class="fas fa-grip-horizontal" aria-hidden="true"></i><span>PMTA ADMIN</span></button>
            <button type="button" role="menuitem" data-quick-action="pmta-panel"><i class="fas fa-server" aria-hidden="true"></i><span>PMTA PANEL</span></button>
        </div>
    </div>
    <div id="global-test-context-menu" role="menu" aria-label="Global Test actions" hidden>
        <div class="campaign-menu-heading">
            <span>QUICK ACTIONS</span>
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
         data-pmta-admin-port="{{ $pmtaAdminPort }}">

        <!-- 1. Hero Header -->
        <header class="campaign-create-hero">
            <div class="campaign-create-hero-icon" aria-hidden="true">
                <i class="fas fa-vial"></i>
            </div>
            <div class="campaign-create-hero-copy">
                <h1 id="global-tests-page-title">Global Tests</h1>
                <p>Dispatch live deliverability test emails across configured MTA servers, test addresses, and message headers.</p>
            </div>
        </header>

        <div class="mb-2">
            <a href="{{ route('console.global-tests.histories', $workspace->id) }}" class="small font-w600" style="color: var(--campaign-purple, #792bfa);">
                Open global test histories
            </a>
        </div>

        <!-- 2. Stage Track -->
        <nav class="campaign-stage-track mb-4" aria-label="Global Test stages">
            <ol>
                <li class="is-complete" data-step="1"><span><span class="step-badge">1</span><strong>Setup</strong></span><small>Recipients &amp; path</small></li>
                <li class="is-current" data-step="2"><span><span class="step-badge">2</span><strong>Configuration</strong></span><small>Headers &amp; content</small></li>
                <li data-step="3"><span><span class="step-badge">3</span><strong>Sending</strong></span><small>Routes &amp; placeholders</small></li>
                <li data-step="4"><span><span class="step-badge">4</span><strong>Dispatch</strong></span><small>Review &amp; send test</small></li>
            </ol>
        </nav>

        <!-- 3. Two-Column Composer Layout -->
        <div class="campaign-create-layout">
            <main class="campaign-create-main" id="global-tests-block">
                @unless ($senderConfigured)
                    <div class="alert alert-warning mb-3" role="status" style="border-radius: 12px;">
                        <i class="fas fa-exclamation-triangle mr-1"></i> AlphaLink’s Global Test sender is not configured. The form is ready, but sending stays unavailable until an AlphaLink sender is installed.
                    </div>
                @endunless

                <!-- Usable Tags Banner -->
                <div class="alert alert-primary alert-dismissible py-2 mb-3" id="campaign-usable-tags" role="note">
                    <strong><i class="fas fa-code campaign-field-icon" aria-hidden="true"></i>USABLE TAGS:</strong>
                    <div class="d-flex align-items-center flex-wrap" style="gap: 5px;">
                        @foreach (['[server]', '[main_domain]', '[ip]', '[domain]', '[rdns]', '[to]', '[date]', '[name]', '[rnd]', '[rnda_10]', '[text:a|b|c]'] as $tag)
                            <button type="button" class="global-tag-button" data-insert-tag="{{ $tag }}">{{ $tag }}</button>
                        @endforeach
                    </div>
                    <button type="button" class="btn btn-link btn-sm p-0 ml-auto" id="show-global-tags" title="Show tag meanings" aria-label="Show tag meanings">
                        <i class="fas fa-question-circle" aria-hidden="true"></i>
                    </button>
                </div>

                <form id="global-test-form" method="post" action="{{ route('console.global-tests.test', $workspace->id) }}" novalidate>
                    @csrf

                    <!-- CARD 1: EMAIL CONFIGURATION -->
                    <div class="block block-rounded campaign-email-card mb-4">
                        <div class="block-header">
                            <h3 class="block-title"><i class="fas fa-envelope" aria-hidden="true"></i> EMAIL CONFIGURATION</h3>
                            <span class="campaign-section-caption">Configure return path, headers, and email content.</span>
                        </div>
                        <div class="block-content">
                            <!-- Row 1: Return Path | Saved Emails | Email Test Addresses -->
                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label for="return_path"><i class="fas fa-paper-plane campaign-field-icon" aria-hidden="true"></i>Return path:</label>
                                    <input id="return_path" type="text" class="form-control" value="[rnd]@[domain]" autocomplete="off">
                                </div>
                                <div class="form-group col-md-4" id="campaign-saved-emails">
                                    <label for="saved_emails"><i class="fas fa-envelope campaign-field-icon" aria-hidden="true"></i>Saved Emails:</label>
                                    <div class="input-group">
                                        <select id="saved_emails" class="form-control selectpicker" data-live-search="true" data-actions-box="true" title="Select Emails" multiple aria-describedby="saved-emails-help">
                                            @foreach ($savedAddresses as $address)
                                                <option value="{{ $address->email }}">{{ $address->email }}</option>
                                            @endforeach
                                        </select>
                                        <div class="input-group-append">
                                            <button id="manage-emails" class="campaign-icon-button is-add" type="button" title="Manage saved email addresses" aria-label="Manage saved email addresses">
                                                <i class="fas fa-plus" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <small class="form-text text-muted sr-only" id="saved-emails-help">Select saved addresses to copy them into Email Test.</small>
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="email_test"><i class="fas fa-envelope-open-text campaign-field-icon" aria-hidden="true"></i>Email Test addresses:</label>
                                    <textarea id="email_test" class="form-control" rows="1" placeholder="One address per line" autocomplete="email" style="height: 36px; min-height: 36px; resize: vertical;"></textarea>
                                </div>
                            </div>

                            <!-- Row 2: Saved Headers | Domain | Negative Token File -->
                            <div class="form-row mt-1">
                                <div class="form-group col-sm-12 col-md-4 campaign-header-library">
                                    <label for="saved_headers"><i class="fas fa-bookmark campaign-field-icon" aria-hidden="true"></i>Saved Headers:</label>
                                    <div class="input-group">
                                        <select id="saved_headers" class="form-control selectpicker" data-live-search="true" title="Select Header" aria-label="Select a saved header">
                                            <option value="">Select Header</option>
                                            @foreach ($headers as $header)
                                                <option value="{{ $header->id }}">{{ $header->name }}</option>
                                            @endforeach
                                        </select>
                                        <div class="input-group-append">
                                            <button id="save-header" class="campaign-icon-button" type="button" title="Save Header" aria-label="Save Header">
                                                <i class="fas fa-save" aria-hidden="true"></i>
                                            </button>
                                            <button id="delete-header" class="campaign-icon-button is-delete" type="button" title="Delete Header" aria-label="Delete Header">
                                                <i class="fas fa-trash-alt" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group col-sm-12 col-md-4">
                                    <label for="domain"><i class="fas fa-globe campaign-field-icon" aria-hidden="true"></i>Domain:</label>
                                    <select id="domain" class="form-control selectpicker" data-live-search="true" data-allow-clear="true" title="Select Domain">
                                        <option value="">Select Domain</option>
                                        @foreach ($domains as $domain)
                                            <option value="{{ $domain['value'] }}">{{ $domain['name'] }} — {{ $domain['value'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group col-sm-12 col-md-4">
                                    <label for="negative"><i class="fas fa-file-alt campaign-field-icon" aria-hidden="true"></i>Negative token file:</label>
                                    <select id="negative" class="form-control selectpicker" data-live-search="true" data-allow-clear="true" title="Select token file">
                                        <option value="">Select token file</option>
                                        @foreach ($negatives as $negative)
                                            <option value="{{ $negative->id }}">{{ $negative->name }}</option>
                                        @endforeach
                                    </select>
                                    <small class="form-text text-muted">Used only when message contains <code>[negative]</code>.</small>
                                </div>
                            </div>

                            <!-- Row 3: Equal-Height Side-by-Side Editors (Header & Body) -->
                            <div class="form-row mt-2">
                                <div class="form-group col-md-6 campaign-editor-column">
                                    <div class="campaign-editor-heading">
                                        <label class="campaign-editor-title" for="header"><i class="fas fa-heading campaign-field-icon" aria-hidden="true"></i>Header</label>
                                        <div class="campaign-editor-tools" role="group" aria-label="Header tools">
                                            <button type="button" class="campaign-editor-icon-button" id="shuffle-header" title="Shuffle Header" aria-label="Shuffle header lines"><i class="fas fa-random" aria-hidden="true"></i></button>
                                            <button type="button" class="campaign-editor-icon-button" id="open-encoding" title="Encoding Tools" aria-label="Open encoding tools"><i class="fas fa-pencil-ruler" aria-hidden="true"></i></button>
                                        </div>
                                    </div>
                                    <textarea id="header" class="form-control global-test-editor" rows="20" spellcheck="false">{{ $defaultHeader }}</textarea>
                                </div>
                                <div class="form-group col-md-6 campaign-editor-column">
                                    <div class="campaign-editor-heading campaign-editor-heading-body">
                                        <label class="campaign-editor-title" for="body"><i class="fas fa-code campaign-field-icon" aria-hidden="true"></i>Body</label>
                                        <div class="campaign-editor-tools" role="group" aria-label="Body tools">
                                            <button type="button" class="campaign-editor-icon-button" id="preview-body" title="Preview body HTML" aria-label="Preview message body"><i class="fas fa-eye" aria-hidden="true"></i></button>
                                        </div>
                                    </div>
                                    <textarea id="body" class="form-control global-test-editor" rows="20" spellcheck="false" placeholder="<html><body>...</body></html>"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CARD 2: PLACEHOLDERS & SENDING -->
                    <div class="block block-rounded campaign-delivery-card mb-4">
                        <div class="block-header">
                            <h3 class="block-title"><i class="fas fa-paper-plane" aria-hidden="true"></i> PLACEHOLDERS &amp; SENDING</h3>
                            <span class="campaign-section-caption">Define message variations, server routes, and dispatch settings.</span>
                        </div>
                        <div class="block-content">
                            <!-- Full-width Message Placeholders Sub-Card -->
                            <div class="form-row mb-3">
                                <div class="col-12">
                                    <div class="block block-rounded mb-0" data-campaign-placeholder-editor>
                                        <div class="block-header">
                                            <div class="campaign-placeholder-heading">
                                                <h4 class="block-title"><i class="fas fa-code text-purple mr-1" aria-hidden="true"></i> MESSAGE PLACEHOLDERS</h4>
                                                <p>Set token values (mapped to <code>[placeholder1]</code>, <code>[placeholder2]</code>) and rotation frequency.</p>
                                            </div>
                                            <div class="campaign-placeholder-toolbar d-flex align-items-center">
                                                <button id="add-placeholder" type="button" data-placeholder-add>
                                                    <i class="fas fa-plus" aria-hidden="true"></i> Add placeholder
                                                </button>
                                                <label class="campaign-mode-toggle" for="placeholder_combination">
                                                    <input type="checkbox" class="campaign-mode-input" id="placeholder_combination">
                                                    <span class="campaign-mode-track" aria-hidden="true"></span>
                                                    <i class="fas fa-random campaign-field-icon" aria-hidden="true"></i>
                                                    <span class="campaign-mode-label">COMBINATION</span>
                                                </label>
                                                <label class="campaign-mode-toggle" for="placeholder_all_combination">
                                                    <input type="checkbox" class="campaign-mode-input" id="placeholder_all_combination">
                                                    <span class="campaign-mode-track" aria-hidden="true"></span>
                                                    <i class="fas fa-layer-group campaign-field-icon" aria-hidden="true"></i>
                                                    <span class="campaign-mode-label">ALL COMBINATIONS</span>
                                                </label>
                                                <button type="button" class="campaign-editor-icon-button" id="toggle-placeholders" aria-label="Collapse placeholders" title="Toggle placeholders table">
                                                    <i class="fas fa-chevron-up" aria-hidden="true"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="block-content" id="placeholder-content">
                                            <div class="table-responsive">
                                                <table class="table table-sm table-hover" id="placeholders">
                                                    <thead>
                                                        <tr>
                                                            <th scope="col">PLACEHOLDER VALUES (ONE PER LINE)</th>
                                                            <th scope="col">ROTATE EVERY</th>
                                                            <th scope="col" class="text-center"><span class="sr-only">Actions</span>ACTION</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr class="placeholder-row">
                                                            <td><textarea class="form-control form-control-sm placeholder-data" rows="2" placeholder="Value 1&#10;Value 2" aria-label="Placeholder values, one per line"></textarea></td>
                                                            <td><input class="form-control form-control-sm placeholder-rotate" type="number" min="1" value="1" aria-label="Rotate every"></td>
                                                            <td class="text-center"><button class="campaign-icon-button is-delete remove-placeholder" type="button" aria-label="Remove placeholder row" title="Remove row"><i class="fas fa-times" aria-hidden="true"></i></button></td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Routing & Parameters Row -->
                            <div id="campaign-routing">
                                <div class="row">
                                    <div class="col-sm-12 col-md-8">
                                        <div class="form-row">
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
                                                    <button class="btn btn-link btn-sm p-0" id="reload-servers" type="button" title="Reload Servers" aria-label="Reload server list"><i class="fas fa-sync-alt" aria-hidden="true"></i></button>
                                                    <button class="btn btn-link btn-sm p-0" id="toggle-server-search" type="button" title="Filter Servers" aria-label="Search servers"><i class="fas fa-search" aria-hidden="true"></i></button>
                                                </label>
                                                <input id="server-search" class="form-control form-control-sm mb-1" type="search" placeholder="Filter servers" aria-label="Filter servers" hidden>
                                                <select id="servers" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 3" title="Select Servers" multiple aria-label="Select one or more servers">
                                                    @foreach ($servers as $server)
                                                        <option value="{{ $server['id'] }}" data-ip="{{ $server['ip'] }}">{{ $server['name'] }} | {{ $server['ip'] }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>

                                        <div class="form-group mt-2">
                                            <div class="campaign-route-pools-heading">
                                                <div>
                                                    <i class="fas fa-share-alt campaign-field-icon" aria-hidden="true"></i>
                                                    <span>Delivery routes</span>
                                                    <small>Choose outbound IPs, VMTAs, or SMTP accounts for this live test.</small>
                                                </div>
                                                <span class="campaign-route-kind" id="send-route-choice-heading">IPs</span>
                                            </div>

                                            <div class="campaign-route-picker">
                                                <div class="campaign-route-selector">
                                                    <div class="campaign-route-pane-heading">
                                                        <label for="ips-available">
                                                            <span id="send-ips-available-label">Available routes</span>
                                                            <span class="campaign-route-count" id="send-ips-available-count">0</span>
                                                        </label>
                                                    </div>
                                                    <div class="input-group input-group-sm mb-2">
                                                        <input id="ips-available-filter" class="form-control campaign-route-filter mb-0" type="search" placeholder="Filter available IPs" aria-label="Filter available IPs">
                                                        <div class="input-group-append">
                                                            <button type="button" class="btn btn-outline-secondary global-clear-filter" data-clear="ips-available-filter" aria-label="Clear available IP filter" title="Clear filter" style="height: 36px;">&times;</button>
                                                        </div>
                                                    </div>
                                                    <select id="ips-available" class="form-control" multiple size="10" aria-label="Available IPs or MTAs"></select>
                                                </div>

                                                <div class="campaign-route-transfer-controls" role="group" aria-label="Move IP selections">
                                                    <button id="move-all-right" type="button" data-ip-move="all-right" title="Move all to selected" aria-label="Move all to selected"><i class="fas fa-angle-double-right" aria-hidden="true"></i></button>
                                                    <button id="move-right" type="button" data-ip-move="right" title="Move selected to selected" aria-label="Move selected to selected"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
                                                    <button id="move-left" type="button" data-ip-move="left" title="Remove selected IPs" aria-label="Remove selected IPs"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
                                                    <button id="move-all-left" type="button" data-ip-move="all-left" title="Remove all selected IPs" aria-label="Remove all selected IPs"><i class="fas fa-angle-double-left" aria-hidden="true"></i></button>
                                                </div>

                                                <div class="campaign-route-selector">
                                                    <div class="campaign-route-pane-heading">
                                                        <label for="ips-selected">
                                                            <span id="send-ips-selected-label">Selected routes</span>
                                                            <span class="campaign-route-count" id="send-ips-selected-count">0</span>
                                                        </label>
                                                    </div>
                                                    <div class="input-group input-group-sm mb-2">
                                                        <input id="ips-selected-filter" class="form-control campaign-route-filter mb-0" type="search" placeholder="Filter selected IPs" aria-label="Filter selected IPs">
                                                        <div class="input-group-append">
                                                            <button type="button" class="btn btn-outline-secondary global-clear-filter" data-clear="ips-selected-filter" aria-label="Clear selected IP filter" title="Clear filter" style="height: 36px;">&times;</button>
                                                        </div>
                                                    </div>
                                                    <select id="ips-selected" class="form-control" multiple size="10" aria-label="Selected IPs or MTAs"></select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Right Parameter Controls -->
                                    <div class="col-sm-12 col-md-4">
                                        <div class="global-params-panel">
                                            <div class="form-group mb-3">
                                                <label for="repeat"><i class="fas fa-redo campaign-field-icon" aria-hidden="true"></i>Repeat Test:</label>
                                                <input id="repeat" type="number" min="1" class="form-control" value="1">
                                                <small class="form-text text-muted">Number of iterations per selected IP/route.</small>
                                            </div>

                                            <div id="send-route-behavior" class="small text-muted mb-3">
                                                <strong>Live Dispatch:</strong> AlphaLink connects directly to the selected servers’ PMTA listeners. Output logs are saved to Global Test Histories immediately.
                                            </div>

                                            <button id="send-global-test" class="btn btn-primary campaign-create-submit" type="submit">
                                                <i class="fas fa-vial mr-1" aria-hidden="true"></i> SEND GLOBAL TEST
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- Live Status & Output Console -->
                <div id="global-test-status" class="alert alert-info mt-3" role="status" aria-live="polite" hidden style="border-radius: 12px;"></div>
                <section id="global-test-output-panel" class="block block-rounded p-4 mt-3" hidden aria-labelledby="global-test-output-title">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h4 id="global-test-output-title" class="h6 font-w700 mb-0"><i class="fas fa-terminal mr-2 text-purple"></i> Latest Sender Output</h4>
                        <a id="global-test-log-link" href="#" class="btn-command-action" style="height: 30px; padding: 0 12px; font-size: 0.75rem;" hidden>
                            Open Saved History <i class="fas fa-external-link-alt ml-1"></i>
                        </a>
                    </div>
                    <pre id="global-test-output" class="global-test-output mb-0"></pre>
                </section>
            </main>

            <!-- 4. Sticky Summary & Readiness Sidebar -->
            <aside class="campaign-create-summary" id="global-test-summary">
                <section class="campaign-summary-card">
                    <div class="campaign-summary-heading">
                        <span class="campaign-summary-icon" aria-hidden="true"><i class="fas fa-vial"></i></span>
                        <div><h2>Test Summary</h2><p>Review deliverability setup.</p></div>
                    </div>
                    <ul class="campaign-summary-checklist">
                        <li><span><i class="fas fa-at" aria-hidden="true"></i> Return Path</span><strong id="global-summary-path" class="is-ready">Configured</strong></li>
                        <li><span><i class="fas fa-paper-plane" aria-hidden="true"></i> Email Test</span><strong id="global-summary-emails" class="is-pending">Required</strong></li>
                        <li><span><i class="fas fa-server" aria-hidden="true"></i> Servers &amp; IPs</span><strong id="global-summary-routes" class="is-pending">Not selected</strong></li>
                        <li><span><i class="fas fa-heading" aria-hidden="true"></i> Headers</span><strong id="global-summary-headers" class="is-ready">Ready</strong></li>
                        <li><span><i class="fas fa-code" aria-hidden="true"></i> HTML Body</span><strong id="global-summary-body" class="is-optional">Optional</strong></li>
                    </ul>
                </section>

                <section class="campaign-summary-card">
                    <h2>Quick Stats</h2>
                    <div class="campaign-summary-stats">
                        <div><i class="fas fa-paper-plane" aria-hidden="true"></i><strong id="global-quick-recipients">0</strong><span>Recipients</span></div>
                        <div><i class="fas fa-server" aria-hidden="true"></i><strong id="global-quick-servers">0</strong><span>Servers</span></div>
                        <div><i class="fas fa-network-wired" aria-hidden="true"></i><strong id="global-quick-vmtas">0</strong><span>Routes</span></div>
                    </div>
                </section>

                <section class="campaign-summary-card campaign-readiness-card">
                    <div class="campaign-readiness-heading"><h2>Test Readiness</h2><strong id="global-readiness-pct">50%</strong></div>
                    <div class="campaign-readiness-track" role="progressbar" aria-label="Global test readiness" aria-valuemin="0" aria-valuemax="100" aria-valuenow="50">
                        <span id="global-readiness-bar" style="width: 50%;"></span>
                    </div>
                    <small id="global-readiness-desc">2 of 4 test checks complete</small>
                </section>

                <section class="campaign-summary-actions">
                    <p>Dispatch live deliverability tests across target PMTA nodes immediately.</p>
                    <button class="btn btn-primary campaign-create-submit" id="global-summary-submit-btn" type="button" onclick="document.getElementById('send-global-test').click()">
                        <i class="fas fa-vial" aria-hidden="true"></i> Send Global Test
                    </button>
                    <a class="btn btn-outline-secondary campaign-cancel-link" href="{{ route('console.global-tests.histories', $workspace->id) }}">
                        <i class="fas fa-history mr-1"></i> View Test Histories
                    </a>
                </section>
            </aside>
        </div>
    </div>

    <!-- Command OS Dialogs -->
    <dialog id="global-email-dialog" class="global-dialog" aria-labelledby="global-email-title">
        <div class="p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 id="global-email-title" class="h5 mb-0">Saved Email Addresses</h2>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close aria-label="Close saved email manager">&times;</button>
            </div>
            <p class="small text-muted">Addresses are private to your AlphaLink user and workspace.</p>
            <form id="save-email-form">
                <label for="new-test-email">Add email address</label>
                <div class="input-group mb-3">
                    <input id="new-test-email" name="email" type="email" class="form-control" required maxlength="255" placeholder="test@domain.com">
                    <div class="input-group-append"><button class="btn btn-primary" type="submit">Add address</button></div>
                </div>
            </form>
            <ul id="saved-email-list" class="list-group mb-3">
                @foreach ($savedAddresses as $address)
                    <li class="list-group-item d-flex justify-content-between align-items-center" data-address-id="{{ $address->id }}">
                        <span>{{ $address->email }}</span>
                        <button class="campaign-icon-button is-delete remove-saved-email" type="button" aria-label="Delete {{ $address->email }}"><i class="fas fa-trash-alt" aria-hidden="true"></i></button>
                    </li>
                @endforeach
            </ul>
            <div class="d-flex justify-content-end"><button class="btn btn-alt-secondary" type="button" data-dialog-close>Close</button></div>
        </div>
    </dialog>

    <dialog id="global-header-name-dialog" class="global-dialog" aria-labelledby="global-header-name-title">
        <div class="p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 id="global-header-name-title" class="h5 mb-0">Save Header Template</h2>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close aria-label="Close save header dialog">&times;</button>
            </div>
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
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 id="global-confirm-title" class="h5 mb-0">Delete Saved Header?</h2>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close aria-label="Close confirm dialog">&times;</button>
            </div>
            <p id="global-confirm-copy" class="mb-3">This removes the selected header from your workspace.</p>
            <div class="d-flex justify-content-end" style="gap: 8px;">
                <button class="btn btn-alt-secondary" type="button" data-dialog-close>Cancel</button>
                <button class="btn btn-danger" type="button" id="confirm-delete-header">Delete Header</button>
            </div>
        </div>
    </dialog>

    <dialog id="global-preview-dialog" class="global-dialog global-preview-dialog" aria-labelledby="global-preview-title">
        <div class="p-3" style="height: 100%; display: flex; flex-direction: column;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 id="global-preview-title" class="h5 mb-0">Message Body HTML Preview</h2>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close aria-label="Close preview">&times;</button>
            </div>
            <iframe id="global-preview-frame" title="Message body preview" sandbox="" referrerpolicy="no-referrer" style="flex: 1;"></iframe>
            <div class="d-flex justify-content-end mt-3"><button class="btn btn-alt-secondary" type="button" data-dialog-close>Close</button></div>
        </div>
    </dialog>

    <dialog id="global-tags-dialog" class="global-dialog" aria-labelledby="global-tags-title">
        <div class="p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 id="global-tags-title" class="h5 mb-0">Supported Message Tags</h2>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close aria-label="Close tag reference">&times;</button>
            </div>
            <div class="global-tag-reference small">
                <p><code>[ip]</code> IP · <code>[server]</code> server name · <code>[main_domain]</code> server domain · <code>[domain]</code> selected IP domain · <code>[rdns]</code> reverse DNS · <code>[to]</code> recipient · <code>[date]</code> current date · <code>[name]</code> recipient name · <code>[rnd]</code> random text.</p>
                <p><code>[from]</code> random From · <code>[subject]</code> random Subject · <code>[ids]</code> server ID · <code>[idd]</code> domain ID · <code>[idi]</code> IP ID · <code>[ide]</code> email ID · <code>[negative]</code> Negative Token File · <code>[smtp]</code> SMTP username · <code>[smtp_name]</code> SMTP name.</p>
                <p><code>[rnda_10]</code> random alphanumeric string of length 10. Other random string tags include <code>[rndn_N]</code>, <code>[rndl_N]</code>, <code>[rndu_N]</code>, <code>[rnds_N]</code>, <code>[rndlu_N]</code>, <code>[rndln_N]</code> and <code>[rndun_N]</code>. <code>[text:a|b|c]</code> chooses one listed value.</p>
                <p>Boundary forms include <code>[bndn_N]</code>, <code>[bnda_N]</code>, <code>[bndl_N]</code>, <code>[bndu_N]</code>, <code>[bnds_N]</code>, <code>[bndlu_N]</code>, <code>[bndln_N]</code> and <code>[bndun_N]</code>.</p>
                <p class="mb-0"><code>[base64:text]</code> Base64 · <code>[printable:text]</code> quoted printable · <code>[placeholder1]</code>, <code>[placeholder2]</code> map to rows below.</p>
            </div>
            <div class="d-flex justify-content-end mt-3"><button class="btn btn-alt-secondary" type="button" data-dialog-close>Close</button></div>
        </div>
    </dialog>

    <dialog id="global-encoding-dialog" class="global-dialog global-encoding-dialog" aria-labelledby="global-encoding-title">
        <div class="p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 id="global-encoding-title" class="h5 mb-0">Encoding Tools</h2>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close aria-label="Close encoding tools">&times;</button>
            </div>
            <ul class="nav nav-tabs mb-3" role="tablist" aria-label="Encoding tools">
                <li class="nav-item"><button class="nav-link active" type="button" role="tab" aria-selected="true" aria-controls="global-encoding-string" id="global-encoding-string-tab">STRING</button></li>
                <li class="nav-item"><button class="nav-link" type="button" role="tab" aria-selected="false" aria-controls="global-encoding-ip" id="global-encoding-ip-tab">IP</button></li>
            </ul>
            <section id="global-encoding-string" role="tabpanel" aria-labelledby="global-encoding-string-tab">
                <div class="form-row align-items-end">
                    <div class="form-group col-md-5"><label for="encoding-text">Your String</label><input id="encoding-text" class="form-control"></div>
                    <div class="form-group col-md-3"><label for="encoding-schema">Schema</label><select id="encoding-schema" class="form-control"><option value="Q">Quoted-Printable</option><option value="HQ">Hex-Quoted-Printable</option><option value="B">Base64</option></select></div>
                    <div class="form-group col-md-2"><label for="encoding-charset">Charset</label><select id="encoding-charset" class="form-control"><option>UTF-8</option><option>UTF-7</option><option>ISO-8859-1</option><option>ISO-8859-15</option><option>windows-1252</option><option>US-ASCII</option><option>CP1256</option></select></div>
                    <div class="form-group col-md-2"><button id="run-string-encoding" class="btn btn-primary w-100" type="button">ENCODE</button></div>
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
        /* Match Edit Campaign Composer Visual System */
        .campaign-create-page #global-tests-block > .block,
        .campaign-create-page .campaign-email-card,
        .campaign-create-page .campaign-delivery-card {
            background: linear-gradient(145deg, #fff 0%, #fcfbff 62%, #f8f7ff 100%);
            border: 1px solid var(--campaign-border, #dce0f4);
            box-shadow: 0 6px 18px rgb(78 64 164 / 7%);
            overflow: visible;
        }
        .campaign-create-page .campaign-email-card .block-header,
        .campaign-create-page .campaign-delivery-card .block-header {
            background-image: linear-gradient(135deg, transparent 72%, rgb(143 75 247 / 5%) 72%, rgb(143 75 247 / 5%) 80%, transparent 80%, transparent 84%, rgb(143 75 247 / 8%) 84%, rgb(143 75 247 / 8%) 92%, transparent 92%);
            border-bottom: 1px solid #f0eff8;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 4px 12px;
            min-height: 42px;
            padding: 8px 14px;
        }
        .campaign-create-page .block-title {
            color: var(--campaign-ink, #101746);
            font-size: 15px;
            font-weight: 790;
            letter-spacing: -.025em;
            margin: 0;
        }
        .campaign-create-page .block-title > i {
            color: var(--campaign-purple, #792bfa);
            margin-right: 7px;
        }
        .campaign-create-page .campaign-section-caption {
            color: var(--campaign-muted, #667092);
            font-size: 11px;
        }
        .campaign-create-page .block-content {
            padding: 12px 14px;
        }
        .campaign-create-page label {
            color: var(--campaign-ink, #101746);
            font-size: 11px !important;
            font-weight: 700 !important;
            margin-bottom: 4px !important;
            text-transform: none !important;
            letter-spacing: normal !important;
        }
        .campaign-create-page .campaign-field-icon {
            color: var(--campaign-purple, #792bfa);
            display: inline-block;
            font-size: 11px;
            margin-right: 5px;
            text-align: center;
            width: 13px;
        }
        .campaign-create-page .form-control:not(textarea):not([multiple]):not(.bootstrap-select) {
            height: 36px;
            min-height: 36px;
            padding: 4px 10px;
            border-radius: 7px;
            border: 1px solid #cfd4ed;
            font-size: 12px;
        }
        .campaign-create-page .bootstrap-select.form-control {
            background: transparent !important;
            border: 0 !important;
            box-shadow: none !important;
            padding: 0 !important;
        }
        .campaign-create-page .bootstrap-select > .dropdown-toggle {
            height: 36px !important;
            min-height: 36px !important;
            border: 1px solid #cfd4ed !important;
            border-radius: 7px !important;
            background: #ffffff;
            color: #151d43;
            font-size: 12px;
            padding: 0 10px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
        }
        .campaign-create-page .campaign-header-library .input-group,
        .campaign-create-page #campaign-saved-emails .input-group {
            display: flex;
            flex-wrap: nowrap;
            gap: 6px;
        }
        .campaign-create-page .campaign-header-library .input-group > .bootstrap-select.form-control,
        .campaign-create-page #campaign-saved-emails .input-group > .bootstrap-select.form-control {
            flex: 1 1 0;
            min-width: 0;
            width: 1% !important;
        }
        .campaign-create-page .campaign-icon-button {
            align-items: center;
            background: linear-gradient(145deg, #f0e7ff, #e8eaff);
            border: 1px solid #d9c8fb;
            border-radius: 9px;
            box-shadow: 0 2px 6px rgb(79 56 145 / 10%);
            color: #7135d4;
            display: inline-flex;
            flex: 0 0 36px;
            height: 36px;
            justify-content: center;
            padding: 0;
            transition: all .16s ease;
            width: 36px;
            cursor: pointer;
        }
        .campaign-create-page .campaign-icon-button:hover {
            border-color: #a77cf0;
            box-shadow: 0 0 0 3px rgb(139 74 255 / 12%);
            color: #5922b5;
            transform: translateY(-1px);
        }
        .campaign-create-page .campaign-icon-button.is-add {
            background: linear-gradient(145deg, #e8fbf5, #ddf6ee);
            border-color: #b8e8d9;
            color: #138465;
        }
        .campaign-create-page .campaign-icon-button.is-delete {
            background: linear-gradient(145deg, #fff0f2, #ffe8eb);
            border-color: #f4c4cd;
            color: #bd4054;
        }

        /* Usable Tags Bar */
        #campaign-usable-tags {
            align-items: center;
            background: linear-gradient(100deg, #fff, #f6f3ff);
            border: 1px solid #ddd7ff;
            border-radius: 12px;
            color: #5f32d5;
            display: flex;
            flex-wrap: wrap;
            font-size: 10px;
            gap: 6px 8px;
            justify-content: flex-start;
            padding: 10px 12px !important;
        }
        .global-tag-button {
            border: 1px solid rgba(139, 92, 246, 0.25);
            background: rgba(139, 92, 246, 0.08);
            color: #6d28d9;
            cursor: pointer;
            padding: 2px 8px;
            border-radius: 6px;
            font-family: ui-monospace, monospace;
            font-size: 0.72rem;
            font-weight: 700;
            transition: all 0.15s ease;
        }
        .global-tag-button:hover {
            background: linear-gradient(135deg, #8b5cf6, #6366f1);
            color: #ffffff;
        }
        #show-global-tags {
            align-items: center;
            background: linear-gradient(145deg, #fff, #f2efff);
            border: 1px solid #e0d8f4;
            border-radius: 8px;
            color: #7137dc;
            display: inline-flex;
            height: 26px;
            justify-content: center;
            width: 26px;
        }

        /* Equal-Height Crisp Editors */
        .campaign-editor-heading {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 8px 12px;
            justify-content: space-between;
            margin: 0 0 7px;
            min-height: 36px;
        }
        .campaign-editor-title {
            align-items: center;
            color: var(--campaign-ink);
            display: inline-flex;
            font-size: 12px;
            font-weight: 800;
            gap: 6px;
            margin: 0;
        }
        .campaign-editor-tools {
            align-items: center;
            display: flex;
            gap: 6px;
        }
        .campaign-editor-icon-button {
            align-items: center;
            background: linear-gradient(145deg, #fff, #f2efff);
            border: 1px solid #ddd5f4;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgb(57 43 109 / 6%);
            color: #7137dc;
            cursor: pointer;
            display: inline-flex;
            height: 34px;
            justify-content: center;
            width: 34px;
            transition: all .16s ease;
        }
        .campaign-editor-icon-button:hover {
            background: #eee8ff;
            border-color: #bba4ee;
            color: #5921bc;
            transform: translateY(-1px);
        }
        #global-test-form .ace_editor,
        .global-test-editor {
            height: 360px !important;
            min-height: 360px;
            max-height: 440px;
            width: 100%;
            border: 1px solid #cfd4ed;
            border-radius: 10px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 12px;
        }

        /* Placeholders Panel */
        .campaign-delivery-card [data-campaign-placeholder-editor] {
            background: linear-gradient(145deg, #fff, #faf8ff);
            border: 1px solid #e4ddfa;
            border-radius: 13px;
            box-shadow: 0 4px 14px rgb(75 55 139 / 6%);
            overflow: hidden;
        }
        .campaign-delivery-card [data-campaign-placeholder-editor] > .block-header {
            background: linear-gradient(105deg, #f7f3ff, #f8faff);
            border-bottom: 1px solid #ebe5fb;
            justify-content: space-between;
            min-height: 48px;
            padding: 8px 12px;
        }
        .campaign-placeholder-heading .block-title {
            font-size: 12px;
            font-weight: 850;
            margin: 0 0 2px;
        }
        .campaign-placeholder-heading p {
            color: var(--campaign-muted);
            font-size: 10px;
            margin: 0;
        }
        .campaign-placeholder-toolbar {
            gap: 8px;
            flex-wrap: wrap;
        }
        .campaign-placeholder-toolbar [data-placeholder-add] {
            align-items: center;
            background: linear-gradient(140deg, #7f3ef2, #a24ef7);
            border: 1px solid #7633e7;
            border-radius: 9px;
            box-shadow: 0 3px 9px rgb(111 48 222 / 20%);
            color: #fff;
            display: inline-flex;
            font-size: 11px;
            font-weight: 750;
            gap: 7px;
            min-height: 36px;
            padding: 5px 13px;
            cursor: pointer;
        }
        .campaign-mode-toggle {
            align-items: center;
            background: linear-gradient(145deg, #fff, #f8f6ff);
            border: 1px solid #e2ddf0;
            border-radius: 10px;
            color: #4f5875;
            cursor: pointer;
            display: inline-flex;
            gap: 8px;
            margin: 0 !important;
            min-height: 36px;
            padding: 6px 10px;
            position: relative;
        }
        .campaign-mode-input {
            position: absolute;
            opacity: 0;
            width: 1px;
            height: 1px;
        }
        .campaign-mode-track {
            background: #c4cad7;
            border-radius: 999px;
            height: 20px;
            position: relative;
            width: 34px;
            transition: background .16s ease;
        }
        .campaign-mode-track::after {
            background: #fff;
            border-radius: 50%;
            content: '';
            height: 14px;
            left: 3px;
            position: absolute;
            top: 3px;
            transition: transform .16s ease;
            width: 14px;
        }
        .campaign-mode-input:checked + .campaign-mode-track {
            background: #8543ed;
        }
        .campaign-mode-input:checked + .campaign-mode-track::after {
            transform: translateX(14px);
        }
        .campaign-mode-label {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            white-space: nowrap;
        }

        /* Route Picker Cards & Arrows */
        .campaign-route-pools-heading {
            align-items: center;
            display: flex;
            justify-content: space-between;
            margin: 6px 0 4px;
        }
        .campaign-route-pools-heading > div > span {
            font-size: 12px;
            font-weight: 800;
            color: var(--campaign-ink);
        }
        .campaign-route-pools-heading small {
            color: var(--campaign-muted);
            font-size: 10px;
            margin-left: 6px;
        }
        .campaign-route-kind {
            background: #f1eaff;
            border: 1px solid #e2d6ff;
            border-radius: 999px;
            color: #6631c7;
            font-size: 10px;
            font-weight: 800;
            padding: 4px 9px;
        }
        .campaign-route-picker {
            display: grid !important;
            gap: 12px;
            grid-template-columns: minmax(0, 1fr) 52px minmax(0, 1fr);
            margin: 8px 0 10px;
        }
        .campaign-route-selector {
            background: linear-gradient(145deg, #fff, #faf9ff);
            border: 1px solid #e1def1;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgb(58 45 111 / 5%);
            padding: 12px;
        }
        .campaign-route-pane-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .campaign-route-pane-heading label {
            display: flex;
            align-items: center;
            gap: 7px;
            margin: 0 !important;
            font-weight: 800 !important;
        }
        .campaign-route-count {
            background: #f0eaff;
            border: 1px solid #e5d9ff;
            border-radius: 999px;
            color: #642ec6;
            font-size: 10px;
            font-weight: 800;
            padding: 2px 8px;
        }
        #ips-available, #ips-selected {
            height: 300px !important;
            min-height: 300px !important;
            border: 1px solid #dce0f0;
            border-radius: 10px;
            background: #ffffff;
            padding: 6px;
            font-size: 12px;
        }
        .campaign-route-transfer-controls {
            display: grid !important;
            align-content: center;
            gap: 7px;
        }
        .campaign-route-transfer-controls button {
            align-items: center;
            background: linear-gradient(145deg, #fff, #f3efff);
            border: 1px solid #ddd3f8;
            border-radius: 11px;
            box-shadow: 0 2px 7px rgb(59 44 117 / 8%);
            color: #6d35d5;
            cursor: pointer;
            display: inline-flex;
            font-size: 16px;
            height: 44px;
            justify-content: center;
            width: 100%;
            transition: all .16s ease;
        }
        .campaign-route-transfer-controls button:hover {
            background: #eee7ff;
            transform: translateY(-1px);
        }
        .global-params-panel {
            background: var(--campaign-wash, #f8f7ff);
            border: 1px solid var(--campaign-border, #dce0f4);
            border-radius: 14px;
            padding: 14px;
        }

        /* Floating Quick Nav Trigger (Does Not Overlap Sidebar!) */
        #global-quick-nav {
            position: fixed;
            top: calc(50% - 24px);
            right: max(14px, env(safe-area-inset-right));
            z-index: 1100;
        }
        .global-quick-nav-trigger {
            background: linear-gradient(145deg, #b64eff, #7028f4 72%);
            border: 1px solid rgba(255, 255, 255, 0.86);
            border-radius: 16px;
            box-shadow: 0 8px 22px rgb(104 44 225 / 34%);
            color: #fff;
            display: grid;
            height: 48px;
            place-items: center;
            width: 48px;
            cursor: pointer;
        }
        .global-quick-nav-menu {
            position: fixed;
            right: 74px;
            top: 50%;
            transform: translateY(-50%);
            width: 228px;
            padding: 7px;
            background: linear-gradient(145deg, var(--campaign-surface, #fff), var(--campaign-wash, #f8f7ff));
            border: 1px solid var(--campaign-border, #ded9f8);
            border-radius: 18px;
            box-shadow: 0 18px 48px rgb(35 23 88 / 24%);
        }
        .global-quick-nav-menu[hidden], #global-test-context-menu[hidden] { display: none !important; }
        #global-test-context-menu {
            position: fixed;
            z-index: 1110;
            width: 228px;
            padding: 8px;
            background: linear-gradient(145deg, var(--campaign-surface, #fff), var(--campaign-wash, #f8f7ff));
            border: 1px solid var(--campaign-border, #ded9f8);
            border-radius: 18px;
            box-shadow: 0 20px 52px rgb(35 23 88 / 26%);
        }
        .global-quick-nav-menu .campaign-menu-heading,
        #global-test-context-menu .campaign-menu-heading {
            border-bottom: 1px solid rgb(127 91 205 / 14%);
            color: #7851ce;
            display: flex;
            font-size: 9px;
            font-weight: 800;
            justify-content: space-between;
            letter-spacing: .13em;
            margin: 0 4px 5px;
            padding: 5px 7px 9px;
        }
        .global-quick-nav-menu button,
        #global-test-context-menu button {
            align-items: center;
            background: transparent;
            border: 0;
            border-radius: 11px;
            color: var(--campaign-ink, #101746);
            cursor: pointer;
            display: flex;
            font-size: 10px;
            font-weight: 760;
            gap: 10px;
            min-height: 40px;
            padding: 6px 8px;
            width: 100%;
            text-align: left;
        }
        .global-quick-nav-menu button:hover,
        #global-test-context-menu button:hover {
            background: linear-gradient(100deg, rgb(142 73 250 / 13%), rgb(106 75 235 / 6%));
            color: #6123c8;
        }
        .global-quick-nav-menu button i,
        #global-test-context-menu button i {
            align-items: center;
            background: linear-gradient(145deg, #f0e6ff, #e7edff);
            border: 1px solid rgb(139 83 236 / 14%);
            border-radius: 9px;
            color: #7837ef;
            display: inline-flex;
            height: 30px;
            justify-content: center;
            width: 30px;
        }

        /* Output & Dialogs */
        .global-test-output {
            max-height: 24rem;
            overflow: auto;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            background: var(--campaign-wash, #f8fafc);
            border: 1px solid var(--campaign-border, #dce0f4);
            border-radius: 10px;
            padding: 1rem;
            font-size: 12px;
        }
        .global-preview-dialog {
            height: 80vh;
            max-width: min(70rem, calc(100% - 2rem)) !important;
            width: min(70rem, calc(100% - 2rem)) !important;
        }
        .global-preview-dialog iframe {
            border: 1px solid var(--campaign-border, #dce0f4);
            border-radius: 10px;
            width: 100%;
            background: #ffffff;
        }

        /* Dark Theme Overrides */
        html[data-command-theme='dark'] .campaign-create-page .campaign-email-card,
        html[data-command-theme='dark'] .campaign-create-page .campaign-delivery-card,
        html[data-command-theme='dark'] .campaign-create-page .campaign-route-selector,
        html[data-command-theme='dark'] .campaign-create-page [data-campaign-placeholder-editor],
        html[data-command-theme='dark'] .campaign-create-page .global-params-panel {
            background: linear-gradient(145deg, #111d3b, #152342) !important;
            border-color: #26365f !important;
        }
        html[data-command-theme='dark'] .campaign-create-page .campaign-email-card .block-header,
        html[data-command-theme='dark'] .campaign-create-page .campaign-delivery-card .block-header,
        html[data-command-theme='dark'] .campaign-create-page [data-campaign-placeholder-editor] > .block-header {
            border-bottom-color: #26365f !important;
            background: rgba(15, 23, 42, 0.4) !important;
        }
        html[data-command-theme='dark'] .campaign-create-page #campaign-usable-tags,
        html[data-command-theme='dark'] .campaign-create-page .campaign-mode-toggle,
        html[data-command-theme='dark'] .campaign-create-page .campaign-editor-icon-button,
        html[data-command-theme='dark'] .campaign-create-page .campaign-route-transfer-controls button {
            background: #141b2d !important;
            border-color: #2c3e66 !important;
            color: #e2e8f0 !important;
        }
        html[data-command-theme='dark'] #global-test-form .ace_editor,
        html[data-command-theme='dark'] .global-test-editor,
        html[data-command-theme='dark'] #ips-available,
        html[data-command-theme='dark'] #ips-selected,
        html[data-command-theme='dark'] .campaign-create-page .form-control,
        html[data-command-theme='dark'] .campaign-create-page .bootstrap-select > .dropdown-toggle {
            background: #0b1120 !important;
            border-color: #26365f !important;
            color: #f1f5f9 !important;
        }
        html[data-command-theme='dark'] .global-test-output {
            background: #090d28;
            color: #e2e8f0;
            border-color: #26365f;
        }
        @media (max-width: 767.98px) {
            .campaign-route-picker {
                grid-template-columns: 1fr !important;
            }
            .campaign-route-transfer-controls {
                grid-template-columns: repeat(4, 1fr) !important;
            }
        }
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('console-assets/vendor/ace/ace.js') }}" charset="utf-8"></script>
    <script src="{{ asset('console-assets/vendor/ace/ext-language_tools.js') }}"></script>
    <script src="{{ asset('console-assets/global-tests.js') }}?v={{ substr(sha1_file(public_path('console-assets/global-tests.js')), 0, 12) }}" defer></script>
@endpush
