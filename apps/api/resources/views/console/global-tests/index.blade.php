@extends('layouts.admin-shell')

@section('title', 'GLOBAL TESTS')

@section('content')
    <div class="global-quick-nav" id="global-quick-nav">
        <button class="global-quick-nav-trigger" id="quick-nav-toggle" type="button" aria-label="Open quick navigation" aria-expanded="false" aria-controls="global-quick-nav-menu">
            <i class="fas fa-bars" aria-hidden="true"></i>
        </button>
        <div class="global-quick-nav-menu" id="global-quick-nav-menu" hidden>
            <button type="button" data-quick-action="send"><i class="fas fa-vial" aria-hidden="true"></i><span>SEND TEST</span></button>
            <button type="button" data-quick-action="pmta-admin"><i class="fas fa-grip-horizontal" aria-hidden="true"></i><span>PMTA ADMIN</span></button>
            <button type="button" data-quick-action="pmta-panel"><i class="fas fa-server" aria-hidden="true"></i><span>PMTA PANEL</span></button>
        </div>
    </div>
    <div id="global-test-context-menu" role="menu" aria-label="Global Test actions" hidden>
        <button type="button" role="menuitem" data-quick-action="send"><span>SEND TEST</span><i class="fas fa-vial" aria-hidden="true"></i></button>
        <button type="button" role="menuitem" data-quick-action="pmta-admin"><span>PMTA ADMIN</span><i class="fas fa-grip-horizontal" aria-hidden="true"></i></button>
        <button type="button" role="menuitem" data-quick-action="pmta-panel"><span>PMTA PANEL</span><i class="fas fa-server" aria-hidden="true"></i></button>
    </div>

    <div class="command-os-report-page campaign-create-page" id="global-tests-page"
         data-test-url="{{ route('console.global-tests.test', $workspace->id) }}"
         data-catalog-url="{{ route('console.global-tests.catalog', $workspace->id) }}"
         data-encode-url="{{ route('console.global-tests.encoding', $workspace->id) }}"
         data-address-store-url="{{ route('console.campaign-test-addresses.store', $workspace->id) }}"
         data-address-delete-base="{{ url('/app/workspaces/'.$workspace->id.'/campaign-test-addresses') }}"
         data-header-base="{{ url('/app/workspaces/'.$workspace->id.'/campaign-headers') }}"
         data-pmta-admin-url="{{ route('console.pmta-admin.index', $workspace->id) }}"
         data-pmta-admin-port="{{ $pmtaAdminPort }}">

        <!-- 1. Hero Header -->
        <div class="campaign-create-hero mb-3">
            <div class="campaign-create-hero-icon" aria-hidden="true">
                <i class="fas fa-vial"></i>
            </div>
            <div class="campaign-create-hero-copy">
                <span class="campaign-register-eyebrow">OPERATIONS TOOLKIT // LIVE DELIVERABILITY VALIDATION</span>
                <h1>Global Tests</h1>
                <p>Dispatch live deliverability test emails across configured MTA servers, test addresses, and message headers.</p>
            </div>
        </div>

        <!-- 2. Stage Track -->
        <nav class="campaign-stage-track mb-4" aria-label="Global Test stages">
            <ol>
                <li class="is-complete" data-step="1"><span><span class="step-badge">1</span><strong>Setup</strong></span><small>Recipients &amp; path</small></li>
                <li class="is-current" data-step="2"><span><span class="step-badge">2</span><strong>Headers &amp; Tags</strong></span><small>MIME &amp; tokens</small></li>
                <li data-step="3"><span><span class="step-badge">3</span><strong>Content</strong></span><small>HTML &amp; test body</small></li>
                <li data-step="4"><span><span class="step-badge">4</span><strong>Delivery</strong></span><small>Servers &amp; dispatch</small></li>
            </ol>
        </nav>

        <!-- 3. Two-Column Composer Layout -->
        <div class="campaign-create-layout">
            <main class="campaign-create-main">
                <section class="command-os-table-card p-4" id="global-tests-block">
                    <div>
                        @unless ($senderConfigured)
                            <div class="alert alert-warning mb-3" role="status" style="border-radius: 12px;">
                                <i class="fas fa-exclamation-triangle mr-1"></i> AlphaLink’s Global Test sender is not configured. The form is ready, but sending stays unavailable until an AlphaLink sender is installed.
                            </div>
                        @endunless

                        <div class="d-flex align-items-center flex-wrap p-3 mb-4" role="note" style="background: rgba(139, 92, 246, 0.05); border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.15)); border-radius: 12px; gap: 6px;">
                            <strong class="mr-2" style="font-size: 0.75rem; letter-spacing: 0.05em; color: var(--command-os-text, #1e293b);"><i class="fas fa-tags mr-1 text-purple"></i> USABLE TAGS:</strong>
                            <button type="button" class="global-tag-button" data-insert-tag="[server]">[server]</button>
                            <button type="button" class="global-tag-button" data-insert-tag="[main_domain]">[main_domain]</button>
                            <button type="button" class="global-tag-button" data-insert-tag="[ip]">[ip]</button>
                            <button type="button" class="global-tag-button" data-insert-tag="[domain]">[domain]</button>
                            <button type="button" class="global-tag-button" data-insert-tag="[rdns]">[rdns]</button>
                            <button type="button" class="global-tag-button" data-insert-tag="[to]">[to]</button>
                            <button type="button" class="global-tag-button" data-insert-tag="[date]">[date]</button>
                            <button type="button" class="global-tag-button" data-insert-tag="[name]">[name]</button>
                            <button type="button" class="global-tag-button" data-insert-tag="[rnd]">[rnd]</button>
                            <button type="button" class="global-tag-button" data-insert-tag="[rnda_10]">[rnda_10]</button>
                            <button type="button" class="global-tag-button" data-insert-tag="[text:a|b|c]">[text:a|b|c]</button>
                            <button type="button" class="global-tag-help ml-auto" id="show-global-tags" title="Show tag meanings" aria-label="Show tag meanings"><i class="fas fa-question-circle text-purple" aria-hidden="true"></i></button>
                        </div>

                        <form id="global-test-form" method="post" action="{{ route('console.global-tests.test', $workspace->id) }}" novalidate>
                    @csrf
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="return_path">Return Path:</label>
                            <input id="return_path" type="text" class="form-control" value="[rnd]@[domain]" autocomplete="off">
                        </div>
                        <div class="form-group col-md-4">
                            <label for="saved_emails">Saved Emails:</label>
                            <div class="input-group">
                                <select id="saved_emails" class="form-control" multiple size="1" aria-describedby="saved-emails-help">
                                    @foreach ($savedAddresses as $address)
                                        <option value="{{ $address->email }}">{{ $address->email }}</option>
                                    @endforeach
                                </select>
                                <div class="input-group-append">
                                    <button id="manage-emails" class="btn btn-success" type="button" title="Manage saved email addresses" aria-label="Manage saved email addresses">
                                        <i class="fas fa-plus" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>
                            <small class="form-text text-muted" id="saved-emails-help">Select saved addresses to copy them into Email Test.</small>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="email_test">Email Test:</label>
                            <textarea id="email_test" class="form-control" rows="2" placeholder="One address per line" autocomplete="email"></textarea>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="saved_headers">Saved Headers:</label>
                            <div class="input-group">
                                <select id="saved_headers" class="form-control" aria-label="Select a saved header">
                                    <option value="">Select Header</option>
                                    @foreach ($headers as $header)
                                        <option value="{{ $header->id }}">{{ $header->name }}</option>
                                    @endforeach
                                </select>
                                <div class="input-group-append">
                                    <button id="save-header" class="btn btn-success" type="button" title="Save Header" aria-label="Save Header"><i class="fas fa-save" aria-hidden="true"></i></button>
                                    <button id="delete-header" class="btn btn-danger" type="button" title="Delete Header" aria-label="Delete Header"><i class="fas fa-times" aria-hidden="true"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="header">Header:
                                <button class="global-icon-button" id="shuffle-header" type="button" title="Shuffle Header" aria-label="Shuffle header lines"><i class="fas fa-random" aria-hidden="true"></i></button>
                                <button class="global-icon-button" id="open-encoding" type="button" title="Encoding" aria-label="Open encoding tools"><i class="fas fa-pencil-ruler" aria-hidden="true"></i></button>
                            </label>
                            <textarea id="header" class="form-control global-test-editor" rows="10" spellcheck="false">{{ $defaultHeader }}</textarea>
                        </div>
                        <div class="form-group col-md-6">
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label for="domain">Domain:</label>
                                    <select id="domain" class="form-control">
                                        <option value="">Select Domain</option>
                                        @foreach ($domains as $domain)
                                            <option value="{{ $domain['value'] }}">{{ $domain['name'] }} — {{ $domain['value'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="negative">Negative:</label>
                                    <select id="negative" class="form-control">
                                        <option value="">Select Negative</option>
                                        @foreach ($negatives as $negative)
                                            <option value="{{ $negative->id }}">{{ $negative->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <label for="body">Body:
                                <button class="global-icon-button" id="preview-body" type="button" title="Preview" aria-label="Preview message body"><i class="fas fa-eye" aria-hidden="true"></i></button>
                            </label>
                            <textarea id="body" class="form-control global-test-editor" rows="10" spellcheck="false" placeholder="BODY"></textarea>
                        </div>
                    </div>

                    <div class="form-row align-items-start">
                        <div class="form-group col-lg-7">
                            <div class="form-row">
                                <div class="form-group col-sm-3">
                                    <label for="option">Option:</label>
                                    <select id="option" class="form-control">
                                        <option value="ip" selected>IP</option>
                                        <option value="vmta">VMTA</option>
                                        <option value="smtp">SMTP</option>
                                    </select>
                                </div>
                                <div class="form-group col-sm-3">
                                    <label for="type">Type Ip:</label>
                                    <select id="type" class="form-control">
                                        <option value="all">ALL</option>
                                        <option value="ipv4" selected>IPV4</option>
                                        <option value="ipv6">IPV6</option>
                                    </select>
                                </div>
                                <div class="form-group col-sm-6">
                                    <label for="servers">Servers:
                                        <button class="global-icon-button" id="reload-servers" type="button" title="Reload Servers" aria-label="Reload server list"><i class="fas fa-sync" aria-hidden="true"></i></button>
                                        <button class="global-icon-button" id="toggle-server-search" type="button" title="Search Servers" aria-label="Search servers"><i class="fas fa-search" aria-hidden="true"></i></button>
                                    </label>
                                    <input id="server-search" class="form-control form-control-sm mb-1" type="search" placeholder="Filter servers" aria-label="Filter servers" hidden>
                                    <select id="servers" class="form-control" multiple size="5" aria-label="Select one or more servers">
                                        @foreach ($servers as $server)
                                            <option value="{{ $server['id'] }}" data-ip="{{ $server['ip'] }}">{{ $server['name'] }} | {{ $server['ip'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="global-ip-picker">
                                <div class="global-ip-list">
                                    <label for="ips-available">IPs</label>
                                    <div class="input-group input-group-sm mb-1">
                                        <input id="ips-available-filter" class="form-control" type="search" placeholder="Filter" aria-label="Filter available IPs">
                                        <div class="input-group-append"><button type="button" class="btn btn-alt-secondary global-clear-filter" data-clear="ips-available-filter" aria-label="Clear available IP filter" title="Clear filter">&times;</button></div>
                                    </div>
                                    <select id="ips-available" class="form-control" multiple size="12" aria-label="Available IPs or MTAs"></select>
                                </div>
                                <div class="global-ip-actions" aria-label="Move IP selections">
                                    <button id="move-all-right" class="btn btn-sm btn-alt-secondary" type="button" title="Move all to selected" aria-label="Move all to selected">&raquo;</button>
                                    <button id="move-right" class="btn btn-sm btn-alt-secondary" type="button" title="Move selected to selected" aria-label="Move selected to selected">&rsaquo;</button>
                                    <button id="move-left" class="btn btn-sm btn-alt-secondary" type="button" title="Remove selected IPs" aria-label="Remove selected IPs">&lsaquo;</button>
                                    <button id="move-all-left" class="btn btn-sm btn-alt-secondary" type="button" title="Remove all selected IPs" aria-label="Remove all selected IPs">&laquo;</button>
                                </div>
                                <div class="global-ip-list">
                                    <label for="ips-selected">Selected IPs</label>
                                    <div class="input-group input-group-sm mb-1">
                                        <input id="ips-selected-filter" class="form-control" type="search" placeholder="Filter" aria-label="Filter selected IPs">
                                        <div class="input-group-append"><button type="button" class="btn btn-alt-secondary global-clear-filter" data-clear="ips-selected-filter" aria-label="Clear selected IP filter" title="Clear filter">&times;</button></div>
                                    </div>
                                    <select id="ips-selected" class="form-control" multiple size="12" aria-label="Selected IPs or MTAs"></select>
                                </div>
                            </div>
                        </div>

                        <div class="form-group col-lg-5">
                            <div class="block block-transparent mb-3">
                                <div class="block-header p-0">
                                    <h4 class="block-title">PLACEHOLDERS</h4>
                                    <div class="block-options">
                                        <div class="custom-control custom-switch custom-control-inline">
                                            <input type="checkbox" class="custom-control-input" id="placeholder_combination">
                                            <label class="custom-control-label" for="placeholder_combination">COMBINATION</label>
                                        </div>
                                        <div class="custom-control custom-switch custom-control-inline">
                                            <input type="checkbox" class="custom-control-input" id="placeholder_all_combination">
                                            <label class="custom-control-label" for="placeholder_all_combination">ALL COMBINATION</label>
                                        </div>
                                        <button type="button" class="btn-block-option" id="toggle-placeholders" aria-label="Collapse placeholders"><i class="si si-arrow-down" aria-hidden="true"></i></button>
                                    </div>
                                </div>
                                <div class="block-content p-0" id="placeholder-content">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover" id="placeholders">
                                            <thead><tr><th scope="col">PLACEHOLDER VALUES</th><th scope="col">ROTATE EVERY</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                                            <tbody>
                                                <tr class="placeholder-row">
                                                    <td><textarea class="form-control form-control-sm placeholder-data" rows="2" aria-label="Placeholder values, one per line"></textarea></td>
                                                    <td><input class="form-control form-control-sm placeholder-rotate" type="number" min="1" value="1" aria-label="Rotate every"></td>
                                                    <td><button class="btn btn-sm btn-alt-danger remove-placeholder" type="button" aria-label="Remove placeholder row" title="Remove row"><i class="fas fa-times" aria-hidden="true"></i></button></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <button id="add-placeholder" class="btn btn-sm btn-alt-secondary" type="button"><i class="fas fa-plus mr-1" aria-hidden="true"></i>Add Placeholder</button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="repeat">Repeat Test:</label>
                                <input id="repeat" type="number" min="1" class="form-control" value="1">
                            </div>
                            <button id="send-global-test" class="btn-command-primary w-100" type="submit" style="height: 40px;"><i class="fas fa-vial mr-1" aria-hidden="true"></i> SEND TEST</button>
                        </div>
                    </div>
                </form>

                <div id="global-test-status" class="alert alert-info mt-3" role="status" aria-live="polite" hidden></div>
                <section id="global-test-output-panel" class="mt-3" hidden aria-labelledby="global-test-output-title">
                    <h4 id="global-test-output-title">Latest sender output</h4>
                    <pre id="global-test-output" class="global-test-output"></pre>
                    <a id="global-test-log-link" href="#" hidden>Open saved history</a>
                </section>
                    </div>
                </section>
            </main>

            <!-- 4. Summary & Quick Action Sidebar -->
            <aside class="campaign-create-summary" id="global-test-summary">
                <section class="campaign-summary-card">
                    <div class="campaign-summary-heading">
                        <span class="campaign-summary-icon" aria-hidden="true"><i class="fas fa-vial"></i></span>
                        <div><h2>Test Summary</h2><p>Review deliverability parameters</p></div>
                    </div>
                    <ul class="campaign-summary-checklist">
                        <li><span><i class="fas fa-at mr-1 text-purple"></i> Return Path</span><strong id="global-summary-path">Configured</strong></li>
                        <li><span><i class="fas fa-paper-plane mr-1 text-purple"></i> Email Test</span><strong id="global-summary-emails">Ready</strong></li>
                        <li><span><i class="fas fa-server mr-1 text-purple"></i> Servers &amp; IPs</span><strong id="global-summary-routes">Selected</strong></li>
                        <li><span><i class="fas fa-envelope mr-1 text-purple"></i> Headers</span><strong id="global-summary-headers">Ready</strong></li>
                        <li><span><i class="fas fa-code mr-1 text-purple"></i> HTML Body</span><strong id="global-summary-body">Ready</strong></li>
                    </ul>
                </section>

                <section class="campaign-summary-card">
                    <h2>Quick Stats</h2>
                    <div class="campaign-summary-stats">
                        <div><i class="fas fa-paper-plane" aria-hidden="true"></i><strong id="global-quick-recipients">1</strong><span>Recipients</span></div>
                        <div><i class="fas fa-server" aria-hidden="true"></i><strong id="global-quick-servers">{{ count($servers) }}</strong><span>Servers</span></div>
                        <div><i class="fas fa-network-wired" aria-hidden="true"></i><strong id="global-quick-vmtas">0</strong><span>VMTAs</span></div>
                    </div>
                </section>

                <section class="campaign-summary-card campaign-readiness-card">
                    <div class="campaign-readiness-heading"><h2>Test Readiness</h2><strong id="global-readiness-pct">100%</strong></div>
                    <div class="campaign-readiness-track" role="progressbar" aria-label="Global test readiness" aria-valuemin="0" aria-valuemax="100" aria-valuenow="100">
                        <span id="global-readiness-bar" style="width: 100%;"></span>
                    </div>
                    <small id="global-readiness-desc">Deliverability dispatch engine ready</small>
                </section>

                <section class="campaign-summary-actions">
                    <p>Dispatch live deliverability test emails across target servers.</p>
                    <button class="btn btn-primary campaign-create-submit" id="global-summary-submit-btn" type="button" onclick="document.getElementById('send-global-test').click()">
                        <i class="fas fa-vial mr-1"></i> SEND GLOBAL TEST
                    </button>
                    <a class="btn btn-alt-secondary w-100 mt-2" href="{{ route('console.global-tests.histories', $workspace->id) }}" style="border-radius: 10px; font-weight: 700; font-size: 0.8125rem;">
                        <i class="fas fa-history mr-1"></i> View Test Histories
                    </a>
                </section>
            </aside>
        </div>
    </div>

    <dialog id="global-email-dialog" class="global-dialog" aria-labelledby="global-email-title">
        <form method="dialog" class="global-dialog-close"><button class="close" aria-label="Close saved email manager">&times;</button></form>
        <h2 id="global-email-title">Saved email addresses</h2>
        <p>Addresses are private to your AlphaLink user and workspace.</p>
        <form id="save-email-form">
            <label for="new-test-email">Add email address</label>
            <div class="input-group mb-3"><input id="new-test-email" name="email" type="email" class="form-control" required maxlength="255"><div class="input-group-append"><button class="btn btn-success" type="submit">Add address</button></div></div>
        </form>
        <ul id="saved-email-list" class="list-group">
            @foreach ($savedAddresses as $address)
                <li class="list-group-item d-flex justify-content-between align-items-center" data-address-id="{{ $address->id }}"><span>{{ $address->email }}</span><button class="btn btn-sm btn-alt-danger remove-saved-email" type="button" aria-label="Delete {{ $address->email }}"><i class="fas fa-trash" aria-hidden="true"></i></button></li>
            @endforeach
        </ul>
        <div class="global-dialog-actions"><button class="btn btn-alt-secondary" type="button" data-dialog-close>Close</button></div>
    </dialog>

    <dialog id="global-header-name-dialog" class="global-dialog" aria-labelledby="global-header-name-title">
        <form method="dialog" class="global-dialog-close"><button class="close" aria-label="Close save header dialog">&times;</button></form>
        <h2 id="global-header-name-title">Save header</h2>
        <label for="new-header-name">Header name</label>
        <input id="new-header-name" class="form-control" maxlength="255" required>
        <div class="global-dialog-actions"><button class="btn btn-alt-secondary" type="button" data-dialog-close>Cancel</button><button class="btn btn-primary" type="button" id="confirm-save-header">Save Header</button></div>
    </dialog>

    <dialog id="global-confirm-dialog" class="global-dialog" aria-labelledby="global-confirm-title">
        <h2 id="global-confirm-title">Delete saved header?</h2>
        <p id="global-confirm-copy">This removes the selected header from your workspace.</p>
        <div class="global-dialog-actions"><button class="btn btn-alt-secondary" type="button" data-dialog-close>Cancel</button><button class="btn btn-danger" type="button" id="confirm-delete-header">Delete Header</button></div>
    </dialog>

    <dialog id="global-preview-dialog" class="global-dialog global-preview-dialog" aria-labelledby="global-preview-title">
        <form method="dialog" class="global-dialog-close"><button class="close" aria-label="Close preview">&times;</button></form>
        <h2 id="global-preview-title">Message body preview</h2>
        <iframe id="global-preview-frame" title="Message body preview" sandbox="" referrerpolicy="no-referrer"></iframe>
        <div class="global-dialog-actions"><button class="btn btn-alt-secondary" type="button" data-dialog-close>Close</button></div>
    </dialog>

    <dialog id="global-tags-dialog" class="global-dialog" aria-labelledby="global-tags-title">
        <form method="dialog" class="global-dialog-close"><button class="close" aria-label="Close tag reference">&times;</button></form>
        <h2 id="global-tags-title">Message tags</h2>
        <div class="global-tag-reference">
            <p><code>[ip]</code> IP · <code>[server]</code> server name · <code>[main_domain]</code> server domain · <code>[domain]</code> selected IP domain · <code>[rdns]</code> reverse DNS · <code>[to]</code> recipient · <code>[date]</code> current date · <code>[name]</code> recipient name · <code>[rnd]</code> random text.</p>
            <p><code>[from]</code> random From · <code>[subject]</code> random Subject · <code>[ids]</code> server ID · <code>[idd]</code> domain ID · <code>[idi]</code> IP ID · <code>[ide]</code> email ID · <code>[negative]</code> negative file · <code>[smtp]</code> SMTP username · <code>[smtp_name]</code> SMTP name.</p>
            <p><code>[rnda_10]</code> random alphanumeric string of length 10. Other random string tags include <code>[rndn_N]</code>, <code>[rndl_N]</code>, <code>[rndu_N]</code>, <code>[rnds_N]</code>, <code>[rndlu_N]</code>, <code>[rndln_N]</code> and <code>[rndun_N]</code>. <code>[text:a|b|c]</code> chooses one listed value.</p>
            <p>Boundary forms include <code>[bndn_N]</code>, <code>[bnda_N]</code>, <code>[bndl_N]</code>, <code>[bndu_N]</code>, <code>[bnds_N]</code>, <code>[bndlu_N]</code>, <code>[bndln_N]</code> and <code>[bndun_N]</code>. Placeholder rows below map to <code>[placeholder1]</code>, <code>[placeholder2]</code>, and subsequent numbered tags.</p>
            <p><code>[base64:text]</code> Base64 · <code>[printable:text]</code> quoted printable · <code>[boundary]</code> and <code>[bnd]</code> static boundary text · <code>[placeholder1]</code>, <code>[placeholder2]</code> and so on use the values configured below.</p>
        </div>
        <div class="global-dialog-actions"><button class="btn btn-alt-secondary" type="button" data-dialog-close>Close</button></div>
    </dialog>

    <dialog id="global-encoding-dialog" class="global-dialog global-encoding-dialog" aria-labelledby="global-encoding-title">
        <form method="dialog" class="global-dialog-close"><button class="close" aria-label="Close encoding tools">&times;</button></form>
        <h2 id="global-encoding-title">Encoding</h2>
        <div class="global-encoding-tabs" role="tablist" aria-label="Encoding tools">
            <button type="button" role="tab" aria-selected="true" aria-controls="global-encoding-string" id="global-encoding-string-tab">STRING</button>
            <button type="button" role="tab" aria-selected="false" aria-controls="global-encoding-ip" id="global-encoding-ip-tab">IP</button>
        </div>
        <section id="global-encoding-string" role="tabpanel" aria-labelledby="global-encoding-string-tab">
            <div class="form-row align-items-end">
                <div class="form-group col-md-6"><label for="encoding-text">Your String</label><input id="encoding-text" class="form-control"></div>
                <div class="form-group col-md-2"><label for="encoding-schema">Schema</label><select id="encoding-schema" class="form-control"><option value="Q">Quoted-Printable</option><option value="HQ">Hex-Quoted-Printable</option><option value="B">Base64</option></select></div>
                <div class="form-group col-md-2"><label for="encoding-charset">Charset</label><select id="encoding-charset" class="form-control"><option>UTF-8</option><option>UTF-7</option><option>ISO-8859-1</option><option>ISO-8859-15</option><option>windows-1252</option><option>US-ASCII</option><option>CP1256</option></select></div>
                <div class="form-group col-md-2"><button id="run-string-encoding" class="btn btn-primary" type="button">ENCODE</button></div>
            </div>
            <label for="encoding-result">Result</label><textarea id="encoding-result" class="form-control" rows="5" readonly></textarea>
        </section>
        <section id="global-encoding-ip" role="tabpanel" aria-labelledby="global-encoding-ip-tab" hidden>
            <label for="encoding-ip">IP</label><div class="input-group mb-3"><input id="encoding-ip" class="form-control" placeholder="IPv4 address"><div class="input-group-append"><button id="run-ip-encoding" class="btn btn-primary" type="button">ENCODE</button></div></div>
            <label for="encoding-ip-result">Result</label><textarea id="encoding-ip-result" class="form-control" rows="7" readonly></textarea>
        </section>
        <div class="global-dialog-actions"><button class="btn btn-alt-secondary" type="button" data-dialog-close>Close</button></div>
    </dialog>
@endsection

@push('styles')
    <style>
        #global-test-form .ace_editor { height: 30rem; width: 100%; border-radius: 10px; }
        .global-test-editor { min-height: 17rem; resize: vertical; font-family: 'JetBrains Mono', monospace; font-size: 0.8125rem; }
        .global-icon-button { border: 0; background: transparent; color: #8b5cf6; cursor: pointer; padding: .1rem .35rem; }
        .global-tag-button {
            border: 1px solid rgba(139, 92, 246, 0.25);
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
            cursor: pointer;
            padding: 2px 8px;
            border-radius: 6px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.15s ease;
        }
        .global-tag-button:hover {
            background: linear-gradient(135deg, #8b5cf6, #6366f1);
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(139, 92, 246, 0.35);
        }
        .global-tag-help { border: 0; background: transparent; cursor: pointer; padding: .1rem .25rem; }
        .global-icon-button:focus-visible, .global-tag-button:focus-visible, .global-tag-help:focus-visible { outline: 2px solid #8b5cf6; outline-offset: 2px; }
        .global-ip-picker { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); gap: .75rem; align-items: center; }
        .global-ip-list { min-width: 0; }
        .global-ip-list select { min-height: 20rem; border-radius: 8px; }
        .global-ip-actions { display: flex; flex-direction: column; gap: .35rem; }
        .global-ip-actions .btn { min-width: 2.5rem; }
        .global-test-output { max-height: 24rem; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; background: var(--command-os-card-bg, #ffffff); border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.2)); border-radius: 10px; padding: 1rem; }
        .global-quick-nav { position: fixed; right: 0; top: 50%; transform: translateY(-50%); z-index: 1100; }
        .global-quick-nav-trigger { background: linear-gradient(135deg, #8b5cf6, #6366f1); border: 0; color: #fff; font-size: 1.2rem; min-height: 3rem; min-width: 3rem; border-radius: 8px 0 0 8px; box-shadow: -2px 0 10px rgba(139, 92, 246, 0.35); }
        .global-quick-nav-menu { background: var(--command-os-card-bg, #ffffff); border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.25)); box-shadow: 0 .25rem 1.2rem rgba(0, 0, 0, 0.25); padding: .5rem; border-radius: 10px 0 0 10px; }
        .global-quick-nav-menu button { background: transparent; border: 0; color: var(--command-os-text, #1e293b); display: flex; gap: .6rem; align-items: center; min-height: 2.25rem; padding: .4rem .65rem; text-align: left; width: 100%; white-space: nowrap; border-radius: 6px; font-weight: 600; font-size: 0.8125rem; }
        .global-quick-nav-menu button:hover, .global-quick-nav-menu button:focus-visible { background: rgba(139, 92, 246, 0.12); color: #8b5cf6; }
        #global-test-context-menu[hidden] { display: none !important; }
        #global-test-context-menu { position: fixed; z-index: 1110; min-width: 12rem; padding: .3rem 0; background: var(--command-os-card-bg, #ffffff); border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.25)); border-radius: 10px; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25); }
        #global-test-context-menu button { display: flex; width: 100%; align-items: center; justify-content: space-between; gap: 1rem; border: 0; background: transparent; color: var(--command-os-text, #1e293b); padding: .5rem .75rem; text-align: left; white-space: nowrap; font-size: 0.8125rem; font-weight: 600; }
        #global-test-context-menu button:hover, #global-test-context-menu button:focus-visible { background: rgba(139, 92, 246, 0.1); color: #8b5cf6; outline: 0; }
        #global-test-context-menu i { color: #8b5cf6; }
        .global-dialog { border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.25)); border-radius: 16px; box-shadow: 0 .5rem 2rem rgba(0, 0, 0, 0.35); max-height: min(88vh, 56rem); max-width: min(44rem, calc(100% - 2rem)); overflow: auto; padding: 1.5rem; width: calc(100% - 2rem); background: var(--command-os-card-bg, #ffffff); }
        .global-dialog::backdrop { background: rgba(10, 14, 40, 0.7); backdrop-filter: blur(6px); }
        .global-dialog h2 { font-size: 1.125rem; margin: 0 2rem .8rem 0; font-weight: 700; color: var(--command-os-text, #1e293b); }
        .global-dialog-close { position: absolute; right: 1rem; top: .9rem; }
        .global-dialog-actions { display: flex; gap: .5rem; justify-content: flex-end; margin-top: 1rem; }
        .global-preview-dialog { height: 80vh; max-width: min(70rem, calc(100% - 2rem)); }
        .global-preview-dialog iframe { border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.2)); height: calc(100% - 6rem); width: 100%; border-radius: 8px; }
        .global-tag-reference { line-height: 1.65; }
        .global-encoding-tabs { border-bottom: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.15)); display: flex; gap: .35rem; margin-bottom: 1rem; }
        .global-encoding-tabs button[aria-selected="true"] { border-bottom: 2px solid #8b5cf6; color: #8b5cf6; font-weight: 700; }
        .global-encoding-tabs button { background: transparent; border: 0; padding: .5rem .75rem; color: var(--command-os-text-muted, #64748b); }
        html[data-command-theme='dark'] .global-test-output { background: #090d28; color: #e2e8f0; border-color: rgba(139, 92, 246, 0.25); }
        html[data-command-theme='dark'] .global-dialog { background: #0d1238; color: #f8fafc; border-color: rgba(139, 92, 246, 0.3); }
        html[data-command-theme='dark'] .global-quick-nav-menu, html[data-command-theme='dark'] #global-test-context-menu { background: #0d1238; border-color: rgba(139, 92, 246, 0.3); }
        @media (max-width: 767.98px) {
            .global-ip-picker { grid-template-columns: 1fr; }
            .global-ip-actions { flex-direction: row; justify-content: center; order: 2; }
            .global-ip-picker .global-ip-list:last-child { order: 3; }
            .global-ip-list select { min-height: 12rem; }
            .global-test-editor { min-height: 13rem; }
            #global-test-form .ace_editor { height: 20rem; }
        }
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('console-assets/vendor/ace/ace.js') }}" charset="utf-8"></script>
    <script src="{{ asset('console-assets/vendor/ace/ext-language_tools.js') }}"></script>
    <script src="{{ asset('console-assets/global-tests.js') }}?v={{ substr(sha1_file(public_path('console-assets/global-tests.js')), 0, 12) }}" defer></script>
@endpush
