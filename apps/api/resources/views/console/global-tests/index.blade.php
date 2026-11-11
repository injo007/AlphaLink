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

    <div class="global-tests-workbench" id="global-tests-page"
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

        <header class="global-tests-hero">
            <div class="global-tests-hero-main">
                <span class="global-tests-hero-icon" aria-hidden="true"><i class="fas fa-vial"></i></span>
                <div>
                    <span class="global-tests-eyebrow">DELIVERY LAB <span aria-hidden="true">/</span> DIRECT PMTA VALIDATION</span>
                    <h1>Global Tests</h1>
                    <p>Send controlled test messages through selected PMTA routes and inspect the relay result.</p>
                </div>
            </div>
            <div class="global-tests-hero-actions">
                <a class="global-tests-history-link" href="{{ route('console.global-tests.histories', $workspace->id) }}"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Test history</a>
                <span class="global-tests-engine {{ $senderConfigured ? 'is-ready' : 'is-unavailable' }}" role="status">
                    <span aria-hidden="true"></span>{{ $senderConfigured ? 'PMTA transport ready' : 'PMTA transport unavailable' }}
                </span>
            </div>
        </header>

        <section class="global-tests-overview" aria-label="Global Test overview">
            <div class="global-tests-overview-card"><span class="global-tests-overview-icon"><i class="fas fa-server" aria-hidden="true"></i></span><div><small>ACTIVE ROUTES</small><strong id="global-quick-servers">{{ count($servers) }}</strong><span>Installed servers</span></div></div>
            <div class="global-tests-overview-card"><span class="global-tests-overview-icon"><i class="fas fa-at" aria-hidden="true"></i></span><div><small>TEST RECIPIENTS</small><strong id="global-quick-recipients">0</strong><span>Entered addresses</span></div></div>
            <div class="global-tests-overview-card"><span class="global-tests-overview-icon"><i class="fas fa-paper-plane" aria-hidden="true"></i></span><div><small>PROJECTED MESSAGES</small><strong id="global-projected-messages">0</strong><span>Recipient × route × repeat</span></div></div>
            <div class="global-tests-overview-cap"><i class="fas fa-shield-halved" aria-hidden="true"></i><span>Each run is limited to <strong>{{ config('global-tests.max_messages', 100) }}</strong> messages.</span></div>
        </section>

        <nav class="global-tests-jump-nav" aria-label="Global Test sections">
            <a href="#global-recipients"><span>01</span> Recipients</a>
            <a href="#global-message"><span>02</span> Message</a>
            <a href="#global-routes"><span>03</span> Delivery routes</a>
            <a href="{{ route('console.global-tests.histories', $workspace->id) }}"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Run history</a>
        </nav>

        <main class="global-tests-main" id="global-tests-block">
                <section class="global-tests-config-card">
                    <div class="global-tests-card-heading">
                        <div><span>TEST CONFIGURATION</span><h2>Prepare a controlled delivery check</h2></div>
                        <a href="{{ route('console.global-tests.histories', $workspace->id) }}">View past runs <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                    </div>
                    <div>
                        @unless ($senderConfigured)
                            <div class="global-tests-notice" role="status">
                                <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                                <span>{{ count($servers) === 0 ? 'No active, installed PMTA servers are available in this workspace.' : 'Configure a valid PMTA SMTP listener port for this workspace before running tests.' }}</span>
                            </div>
                        @endunless

                        <div class="global-tag-strip" role="note">
                            <strong><i class="fas fa-tags" aria-hidden="true"></i> MESSAGE TAGS</strong>
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
                            <button type="button" class="global-tag-help" id="show-global-tags" title="Show tag meanings" aria-label="Show tag meanings"><i class="fas fa-circle-question" aria-hidden="true"></i></button>
                        </div>

                        <form id="global-test-form" method="post" action="{{ route('console.global-tests.test', $workspace->id) }}" novalidate>
                    @csrf
                    <section class="global-tests-form-section" id="global-recipients" aria-labelledby="global-recipients-title">
                        <div class="global-tests-section-heading"><span>01</span><div><h3 id="global-recipients-title">Recipients &amp; envelope</h3><p>Choose where the test goes and which envelope sender it uses.</p></div></div>
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
                    </section>

                    <section class="global-tests-form-section" id="global-message" aria-labelledby="global-message-title">
                        <div class="global-tests-section-heading"><span>02</span><div><h3 id="global-message-title">Message content</h3><p>Set the header, message body, domain tags, and optional suppression token file.</p></div></div>
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
                                    <label for="negative">Negative token file:</label>
                                    <select id="negative" class="form-control">
                                        <option value="">Select token file</option>
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
                    </section>

                    <section class="global-tests-form-section" id="global-routes" aria-labelledby="global-routes-title">
                        <div class="global-tests-section-heading"><span>03</span><div><h3 id="global-routes-title">Delivery routes</h3><p>Pick active PMTA servers and the IP, VMTA, or SMTP routes to exercise.</p></div></div>
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
                                        <div class="input-group-append"><button type="button" class="global-action-icon global-clear-filter" data-clear="ips-available-filter" aria-label="Clear available IP filter" title="Clear filter">&times;</button></div>
                                    </div>
                                    <select id="ips-available" class="form-control" multiple size="12" aria-label="Available IPs or MTAs"></select>
                                </div>
                                <div class="global-ip-actions" aria-label="Move IP selections">
                                    <button id="move-all-right" class="global-action-icon" type="button" title="Move all to selected" aria-label="Move all to selected">&raquo;</button>
                                    <button id="move-right" class="global-action-icon" type="button" title="Move selected to selected" aria-label="Move selected to selected">&rsaquo;</button>
                                    <button id="move-left" class="global-action-icon" type="button" title="Remove selected IPs" aria-label="Remove selected IPs">&lsaquo;</button>
                                    <button id="move-all-left" class="global-action-icon" type="button" title="Remove all selected IPs" aria-label="Remove all selected IPs">&laquo;</button>
                                </div>
                                <div class="global-ip-list">
                                    <label for="ips-selected">Selected IPs</label>
                                    <div class="input-group input-group-sm mb-1">
                                        <input id="ips-selected-filter" class="form-control" type="search" placeholder="Filter" aria-label="Filter selected IPs">
                                        <div class="input-group-append"><button type="button" class="global-action-icon global-clear-filter" data-clear="ips-selected-filter" aria-label="Clear selected IP filter" title="Clear filter">&times;</button></div>
                                    </div>
                                    <select id="ips-selected" class="form-control" multiple size="12" aria-label="Selected IPs or MTAs"></select>
                                </div>
                            </div>
                        </div>

                        <div class="form-group col-lg-5">
                            <div class="global-placeholder-panel">
                                <div class="global-placeholder-heading">
                                    <div class="global-placeholder-title">
                                        <span>CONTENT ROTATION</span>
                                        <h4>Message placeholders</h4>
                                        <p>Rotate test values in order as messages are generated.</p>
                                    </div>
                                    <div class="global-placeholder-toolbar">
                                        <label class="global-placeholder-toggle" for="placeholder_combination">
                                            <input type="checkbox" id="placeholder_combination">
                                            <span>Combination</span>
                                        </label>
                                        <label class="global-placeholder-toggle" for="placeholder_all_combination">
                                            <input type="checkbox" id="placeholder_all_combination">
                                            <span>All combinations</span>
                                        </label>
                                        <button type="button" class="global-icon-button" id="toggle-placeholders" aria-label="Collapse placeholders" aria-expanded="true"><i class="fas fa-chevron-up" aria-hidden="true"></i></button>
                                    </div>
                                </div>
                                <div class="global-placeholder-content" id="placeholder-content">
                                    <div class="global-placeholder-table-wrap">
                                        <table id="placeholders" class="global-placeholder-table">
                                            <thead><tr><th scope="col">Placeholder values <span>One value per line</span></th><th scope="col">Rotate every</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                                            <tbody>
                                                <tr class="placeholder-row">
                                                    <td><textarea class="form-control placeholder-data" rows="2" aria-label="Placeholder values, one per line"></textarea></td>
                                                    <td><input class="form-control placeholder-rotate" type="number" min="1" value="1" aria-label="Rotate every"></td>
                                                    <td><button class="global-icon-button global-icon-danger remove-placeholder" type="button" aria-label="Remove placeholder row" title="Remove row"><i class="fas fa-times" aria-hidden="true"></i></button></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <button id="add-placeholder" class="global-action-button global-action-button-primary" type="button"><i class="fas fa-plus" aria-hidden="true"></i>Add placeholder</button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="repeat">Repeat Test:</label>
                                <input id="repeat" type="number" min="1" class="form-control" value="1">
                            </div>
                            <p class="global-tests-send-estimate"><i class="fas fa-circle-info" aria-hidden="true"></i> This run will send <strong id="global-send-estimate">0</strong> message(s), up to the {{ config('global-tests.max_messages', 100) }} message limit.</p>
                            <button id="send-global-test" class="global-tests-send-button" type="submit" {{ $senderConfigured && in_array((string) $workspace->pivot->role, ['owner', 'admin', 'operator'], true) ? '' : 'disabled' }}><i class="fas fa-vial" aria-hidden="true"></i> Send global test</button>
                        </div>
                    </div>
                    </section>
                </form>

                <div id="global-test-status" class="alert alert-info mt-3" role="status" aria-live="polite" hidden></div>
                <section id="global-test-output-panel" class="mt-3" hidden aria-labelledby="global-test-output-title">
                    <h4 id="global-test-output-title">Latest PMTA run result</h4>
                    <pre id="global-test-output" class="global-test-output"></pre>
                    <a id="global-test-log-link" href="#" hidden>Open saved history</a>
                </section>
                    </div>
                </section>
            </main>
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
            <p><code>[from]</code> random From · <code>[subject]</code> random Subject · <code>[ids]</code> server ID · <code>[idd]</code> domain ID · <code>[idi]</code> IP ID · <code>[ide]</code> email ID · <code>[negative]</code> Negative Token File · <code>[smtp]</code> SMTP username · <code>[smtp_name]</code> SMTP name.</p>
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
        .global-tests-workbench { --gt-ink: #172554; --gt-muted: #64748b; --gt-line: #dce3f2; --gt-surface: #fff; --gt-soft: #f7f8fc; --gt-accent: #7c3aed; --gt-accent-soft: #f1eaff; color: var(--gt-ink); display: grid; gap: 1rem; min-width: 0; }
        .global-tests-hero { align-items: center; background: radial-gradient(ellipse at 83% 8%, rgba(124, 58, 237, .18), transparent 36%), linear-gradient(120deg, #fff 18%, #f2edff 100%); border: 1px solid #e2d8ff; border-radius: 1.15rem; box-shadow: 0 12px 35px rgba(66, 44, 122, .08); display: flex; gap: 1.5rem; justify-content: space-between; min-width: 0; overflow: hidden; padding: clamp(1.25rem, 2.5vw, 2rem); position: relative; }
        .global-tests-hero-main { align-items: center; display: flex; gap: 1rem; min-width: 0; }
        .global-tests-hero-icon { align-items: center; background: linear-gradient(145deg, #8b5cf6, #6d28d9); border-radius: 1rem; box-shadow: 0 10px 25px rgba(124, 58, 237, .23); color: #fff; display: inline-flex; flex: 0 0 3.25rem; font-size: 1.25rem; height: 3.25rem; justify-content: center; }
        .global-tests-eyebrow, .global-tests-card-heading > div > span { color: #7c3aed; display: block; font-size: .67rem; font-weight: 800; letter-spacing: .14em; margin-bottom: .35rem; }
        .global-tests-eyebrow span { color: #a78bfa; padding: 0 .2rem; }
        .global-tests-hero h1 { color: #111b4a; font-size: clamp(1.65rem, 2.6vw, 2.2rem); font-weight: 800; letter-spacing: -.04em; line-height: 1.1; margin: 0; }
        .global-tests-hero p { color: #5b6683; font-size: .9rem; margin: .45rem 0 0; max-width: 44rem; }
        .global-tests-hero-actions { align-items: flex-end; display: flex; flex-direction: column; flex: 0 0 auto; gap: .7rem; }
        .global-tests-history-link { align-items: center; background: #fff; border: 1px solid #dfd5fb; border-radius: .65rem; color: #5b21b6; display: inline-flex; font-size: .83rem; font-weight: 700; gap: .5rem; padding: .65rem .85rem; text-decoration: none; }
        .global-tests-history-link:hover { background: #f7f2ff; color: #4c1d95; text-decoration: none; }
        .global-tests-engine { align-items: center; color: #475569; display: inline-flex; font-size: .7rem; font-weight: 750; gap: .45rem; letter-spacing: .04em; text-transform: uppercase; }
        .global-tests-engine > span { background: #94a3b8; border-radius: 50%; height: .5rem; width: .5rem; }
        .global-tests-engine.is-ready > span { background: #16a34a; box-shadow: 0 0 0 3px rgba(22, 163, 74, .12); }
        .global-tests-engine.is-unavailable > span { background: #ef4444; box-shadow: 0 0 0 3px rgba(239, 68, 68, .12); }
        .global-tests-overview { display: grid; gap: .75rem; grid-template-columns: repeat(3, minmax(0, 1fr)) minmax(15rem, 1.25fr); }
        .global-tests-overview-card, .global-tests-overview-cap { align-items: center; background: var(--gt-surface); border: 1px solid var(--gt-line); border-radius: .9rem; box-shadow: 0 5px 16px rgba(31, 41, 90, .035); display: flex; gap: .8rem; min-height: 5rem; min-width: 0; padding: .85rem 1rem; }
        .global-tests-overview-icon { align-items: center; background: var(--gt-accent-soft); border: 1px solid #e5d8ff; border-radius: .75rem; color: var(--gt-accent); display: inline-flex; flex: 0 0 2.5rem; height: 2.5rem; justify-content: center; }
        .global-tests-overview-card > div { display: grid; gap: .05rem; min-width: 0; }
        .global-tests-overview-card small { color: #64748b; font-size: .61rem; font-weight: 800; letter-spacing: .09em; }
        .global-tests-overview-card strong { color: #172554; font-size: 1.25rem; font-weight: 800; line-height: 1.1; }
        .global-tests-overview-card span:last-child { color: #76829c; font-size: .7rem; }
        .global-tests-overview-cap { background: #f7f4ff; border-color: #e5d8ff; color: #5b21b6; font-size: .8rem; }
        .global-tests-overview-cap > i { font-size: 1rem; }
        .global-tests-jump-nav { align-items: center; background: #fff; border: 1px solid var(--gt-line); border-radius: .8rem; display: flex; flex-wrap: wrap; gap: .25rem; padding: .45rem; }
        .global-tests-jump-nav a { align-items: center; border-radius: .55rem; color: #475569; display: inline-flex; font-size: .77rem; font-weight: 700; gap: .45rem; padding: .6rem .75rem; text-decoration: none; }
        .global-tests-jump-nav a:hover { background: #f4efff; color: #6d28d9; }
        .global-tests-jump-nav a span { color: #8b5cf6; font-size: .64rem; font-weight: 800; }
        .global-tests-jump-nav a:last-child { margin-left: auto; }
        .global-tests-main { min-width: 0; }
        .global-tests-config-card { background: var(--gt-surface); border: 1px solid var(--gt-line); border-radius: 1rem; box-shadow: 0 9px 25px rgba(31, 41, 90, .045); min-width: 0; overflow: hidden; padding: clamp(1rem, 2vw, 1.5rem); }
        .global-tests-card-heading { align-items: center; border-bottom: 1px solid #e7eaf3; display: flex; gap: 1rem; justify-content: space-between; margin: calc(-1 * clamp(1rem, 2vw, 1.5rem)) calc(-1 * clamp(1rem, 2vw, 1.5rem)) 1rem; padding: 1.15rem clamp(1rem, 2vw, 1.5rem); }
        .global-tests-card-heading h2 { color: #18234d; font-size: 1.05rem; font-weight: 800; letter-spacing: -.02em; margin: 0; }
        .global-tests-card-heading > a { color: #6d28d9; font-size: .78rem; font-weight: 700; text-decoration: none; white-space: nowrap; }
        .global-tests-notice { align-items: center; background: #fff7ed; border: 1px solid #fed7aa; border-radius: .7rem; color: #9a3412; display: flex; gap: .65rem; margin-bottom: 1rem; padding: .8rem 1rem; }
        .global-tag-strip { align-items: center; background: #f8f6ff; border: 1px solid #e6dcff; border-radius: .8rem; display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: 1rem; padding: .7rem .8rem; }
        .global-tag-strip > strong { align-items: center; color: #4c1d95; display: inline-flex; font-size: .66rem; gap: .4rem; letter-spacing: .08em; margin-right: .2rem; }
        .global-tag-button { background: #fff; border: 1px solid #ded2fa; border-radius: .45rem; color: #5b21b6; cursor: pointer; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .7rem; font-weight: 650; padding: .28rem .48rem; transition: background .15s ease, border-color .15s ease; }
        .global-tag-button:hover { background: #eee7ff; border-color: #b99aff; }
        .global-tag-help, .global-icon-button { align-items: center; background: #fff; border: 1px solid #ded2fa; border-radius: .48rem; color: #6d28d9; cursor: pointer; display: inline-flex; height: 1.9rem; justify-content: center; margin-left: .18rem; padding: .25rem .45rem; }
        .global-icon-button:hover, .global-tag-help:hover { background: #f3edff; }
        .global-tests-form-section { background: #fff; border: 1px solid #e6eaf2; border-radius: .85rem; margin-top: .85rem; padding: clamp(.9rem, 1.65vw, 1.3rem); scroll-margin-top: 1rem; }
        .global-tests-section-heading { align-items: center; border-bottom: 1px solid #edf0f5; display: flex; gap: .75rem; margin-bottom: 1rem; padding-bottom: .8rem; }
        .global-tests-section-heading > span { align-items: center; background: #f1eaff; border: 1px solid #e1d2ff; border-radius: .6rem; color: #6d28d9; display: inline-flex; flex: 0 0 2.2rem; font-size: .7rem; font-weight: 850; height: 2.2rem; justify-content: center; }
        .global-tests-section-heading h3 { color: #1e2a56; font-size: .92rem; font-weight: 800; margin: 0; }
        .global-tests-section-heading p { color: #71809d; font-size: .73rem; margin: .15rem 0 0; }
        #global-test-form .form-group { min-width: 0; }
        #global-test-form label, .global-tests-form-section label { color: #475569; font-size: .72rem; font-weight: 750; margin-bottom: .38rem; }
        #global-test-form .form-control { background-color: #fff; border: 1px solid #cfd8e8; border-radius: .55rem; box-shadow: none; color: #172554; font-size: .82rem; min-height: 2.55rem; }
        #global-test-form .form-control:focus { border-color: #8b5cf6; box-shadow: 0 0 0 .18rem rgba(139, 92, 246, .14); }
        #global-test-form select[multiple] { padding: .35rem; }
        #global-test-form select[multiple] option { border-radius: .3rem; padding: .35rem .45rem; }
        #global-test-form .form-text { color: #72809b !important; font-size: .68rem; }
        #global-test-form .input-group .form-control { min-width: 0; }
        #global-test-form .input-group-append .btn { align-items: center; border-radius: .5rem; display: inline-flex; height: 2.55rem; justify-content: center; min-width: 2.55rem; }
        #global-test-form .ace_editor { border: 1px solid #cfd8e8; border-radius: .7rem; height: 24rem; width: 100%; }
        .global-test-editor { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; min-height: 15rem; resize: vertical; }
        .global-ip-picker { align-items: stretch; display: grid; gap: .7rem; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); }
        .global-ip-list { background: #f8f9fc; border: 1px solid #e0e6f0; border-radius: .7rem; min-width: 0; padding: .7rem; }
        .global-ip-list > label { display: block; font-size: .68rem; letter-spacing: .06em; text-transform: uppercase; }
        .global-ip-list select { height: 20rem; min-height: 20rem; }
        .global-ip-actions { align-self: center; display: flex; flex-direction: column; gap: .35rem; }
        .global-action-icon { align-items: center; background: #fff; border: 1px solid #d8cafa; border-radius: .5rem; color: #6d28d9; cursor: pointer; display: inline-flex; font-size: 1rem; font-weight: 800; height: 2.25rem; justify-content: center; min-width: 2.25rem; padding: .3rem; transition: background .15s ease, transform .15s ease; }
        .global-action-icon:hover { background: #f3edff; transform: translateY(-1px); }
        .global-icon-danger { background: #fff5f5; border-color: #fecaca; color: #b91c1c; }
        .global-icon-danger:hover { background: #fee2e2; }
        .global-action-button { align-items: center; border: 1px solid transparent; border-radius: .55rem; cursor: pointer; display: inline-flex; font-size: .76rem; font-weight: 800; gap: .45rem; justify-content: center; min-height: 2.35rem; padding: .5rem .75rem; transition: filter .15s ease, transform .15s ease; }
        .global-action-button:hover { filter: brightness(1.04); transform: translateY(-1px); }
        .global-action-button-primary { background: linear-gradient(110deg, #7c3aed, #6d28d9); box-shadow: 0 5px 12px rgba(109, 40, 217, .16); color: #fff; }
        .global-ip-actions .global-action-icon { min-width: 2.35rem; }
        .global-clear-filter { border-radius: 0 .5rem .5rem 0; height: 2.55rem; }
        .global-placeholder-panel { background: linear-gradient(135deg, #fbfaff, #f7f9ff); border: 1px solid #ded8f0; border-radius: .9rem; margin-bottom: 1rem; overflow: hidden; }
        .global-placeholder-heading { align-items: center; border-bottom: 1px solid #e6e1f0; display: flex; flex-wrap: wrap; gap: .85rem; justify-content: space-between; padding: .9rem; }
        .global-placeholder-title > span { color: #7c3aed; display: block; font-size: .58rem; font-weight: 850; letter-spacing: .1em; margin-bottom: .15rem; }
        .global-placeholder-title h4 { color: #1e2a56; font-size: .84rem; font-weight: 850; margin: 0; }
        .global-placeholder-title p { color: #71809d; font-size: .65rem; margin: .15rem 0 0; }
        .global-placeholder-toolbar { align-items: center; display: flex; flex-wrap: wrap; gap: .35rem; }
        .global-placeholder-toggle { align-items: center; background: #fff; border: 1px solid #e0d9ef; border-radius: .55rem; color: #4b5563; cursor: pointer; display: inline-flex; font-size: .63rem; font-weight: 800; gap: .38rem; min-height: 2.2rem; padding: .35rem .5rem; text-transform: uppercase; }
        .global-placeholder-toggle input { accent-color: #7c3aed; height: 1rem; margin: 0; width: 1rem; }
        .global-placeholder-content { padding: .8rem .9rem .9rem; }
        .global-placeholder-content[hidden] { display: none !important; }
        .global-placeholder-table-wrap { max-width: 100%; overflow-x: auto; }
        .global-placeholder-table { border-collapse: separate; border-spacing: 0 .45rem; margin: -.45rem 0 .5rem; min-width: 23rem; table-layout: fixed; width: 100%; }
        .global-placeholder-table th { color: #66738b; font-size: .61rem; font-weight: 850; letter-spacing: .06em; padding: .15rem .4rem .3rem; text-align: left; text-transform: uppercase; }
        .global-placeholder-table th:first-child { width: 56%; }
        .global-placeholder-table th:nth-child(2) { width: 34%; }
        .global-placeholder-table th:last-child { width: 10%; }
        .global-placeholder-table th span { color: #8792a7; font-size: .56rem; font-weight: 600; letter-spacing: 0; text-transform: none; }
        .global-placeholder-table td { padding: 0 .2rem; vertical-align: middle; }
        .global-placeholder-table td:first-child { padding-left: 0; }
        .global-placeholder-table td:last-child { padding-right: 0; text-align: right; }
        .global-placeholder-table .form-control { background: #fff; border: 1px solid #d6deeb; border-radius: .55rem; font-size: .74rem; min-height: 2.45rem; }
        .global-placeholder-table textarea.form-control { min-height: 3.35rem; resize: vertical; }
        .global-tests-send-estimate { align-items: flex-start; background: #f4f7fb; border: 1px solid #e1e7f0; border-radius: .65rem; color: #52617c; display: flex; font-size: .72rem; gap: .4rem; line-height: 1.45; margin: 1rem 0 .7rem; padding: .7rem; }
        .global-tests-send-estimate i { color: #7c3aed; margin-top: .08rem; }
        .global-tests-send-button { align-items: center; background: linear-gradient(110deg, #7c3aed, #6d28d9); border: 1px solid #6d28d9; border-radius: .65rem; box-shadow: 0 8px 18px rgba(109, 40, 217, .18); color: #fff; display: flex; font-size: .82rem; font-weight: 800; gap: .55rem; justify-content: center; min-height: 2.85rem; padding: .65rem 1rem; transition: filter .15s ease, transform .15s ease; width: 100%; }
        .global-tests-send-button:hover:not(:disabled) { filter: brightness(1.06); transform: translateY(-1px); }
        .global-tests-send-button:disabled { cursor: not-allowed; filter: grayscale(.25); opacity: .55; }
        .global-test-output { background: #111827; border: 1px solid #26334a; border-radius: .65rem; color: #d1fae5; max-height: 24rem; overflow: auto; overflow-wrap: anywhere; padding: 1rem; white-space: pre-wrap; }
        #global-test-output-panel h4 { color: #1e2a56; font-size: .9rem; font-weight: 800; }
        #global-test-log-link { color: #6d28d9; font-size: .78rem; font-weight: 700; }
        .global-quick-nav { position: fixed; right: 0; top: 50%; transform: translateY(-50%); z-index: 1100; }
        .global-quick-nav-trigger { background: linear-gradient(135deg, #8b5cf6, #6d28d9); border: 0; border-radius: .8rem 0 0 .8rem; box-shadow: -2px 0 12px rgba(109, 40, 217, .25); color: #fff; font-size: 1.1rem; min-height: 3rem; min-width: 3rem; }
        .global-quick-nav-menu, #global-test-context-menu { background: #fff; border: 1px solid #dfe3ef; box-shadow: 0 .5rem 1.5rem rgba(15, 23, 42, .16); }
        .global-quick-nav-menu { border-radius: .8rem 0 0 .8rem; padding: .45rem; }
        .global-quick-nav-menu button, #global-test-context-menu button { align-items: center; background: transparent; border: 0; border-radius: .45rem; color: #334155; display: flex; font-size: .77rem; font-weight: 700; gap: .6rem; min-height: 2.3rem; padding: .45rem .65rem; text-align: left; white-space: nowrap; width: 100%; }
        .global-quick-nav-menu button:hover, #global-test-context-menu button:hover { background: #f3edff; color: #6d28d9; }
        #global-test-context-menu[hidden] { display: none !important; }
        #global-test-context-menu { border-radius: .65rem; min-width: 12rem; padding: .35rem; position: fixed; z-index: 1110; }
        #global-test-context-menu button { justify-content: space-between; }
        #global-test-context-menu i { color: #7c3aed; }
        .global-dialog { background: #fff; border: 1px solid #ded7ef; border-radius: 1rem; box-shadow: 0 1.25rem 3rem rgba(15, 23, 42, .25); max-height: min(88vh, 56rem); max-width: min(44rem, calc(100% - 2rem)); overflow: auto; padding: 1.4rem; width: calc(100% - 2rem); }
        .global-dialog::backdrop { backdrop-filter: blur(4px); background: rgba(15, 23, 42, .52); }
        .global-dialog h2 { color: #1e2a56; font-size: 1.1rem; font-weight: 800; margin: 0 2rem .8rem 0; }
        .global-dialog-close { position: absolute; right: 1rem; top: .9rem; }
        .global-dialog-actions { display: flex; gap: .5rem; justify-content: flex-end; margin-top: 1rem; }
        .global-preview-dialog { height: 80vh; max-width: min(70rem, calc(100% - 2rem)); }
        .global-preview-dialog iframe { border: 1px solid #dfe3ef; border-radius: .6rem; height: calc(100% - 6rem); width: 100%; }
        .global-tag-reference { line-height: 1.65; }
        .global-encoding-tabs { border-bottom: 1px solid #e4e8f0; display: flex; gap: .35rem; margin-bottom: 1rem; }
        .global-encoding-tabs button { background: transparent; border: 0; color: #64748b; padding: .5rem .75rem; }
        .global-encoding-tabs button[aria-selected="true"] { border-bottom: 2px solid #7c3aed; color: #6d28d9; font-weight: 800; }
        .global-icon-button:focus-visible, .global-tag-button:focus-visible, .global-tag-help:focus-visible, .global-tests-send-button:focus-visible { outline: 2px solid #7c3aed; outline-offset: 2px; }
        html[data-command-theme='dark'] .global-tests-workbench { --gt-ink: #e5e7eb; --gt-muted: #94a3b8; --gt-line: #29344e; --gt-surface: #111a30; --gt-soft: #17223d; --gt-accent: #a78bfa; --gt-accent-soft: #2c2251; color: var(--gt-ink); }
        html[data-command-theme='dark'] .global-tests-hero { background: radial-gradient(ellipse at 83% 8%, rgba(139, 92, 246, .18), transparent 36%), linear-gradient(120deg, #111a30, #201b3d); border-color: #332657; }
        html[data-command-theme='dark'] .global-tests-hero h1, html[data-command-theme='dark'] .global-tests-card-heading h2, html[data-command-theme='dark'] .global-tests-section-heading h3, html[data-command-theme='dark'] #global-test-output-panel h4 { color: #f1f5f9; }
        html[data-command-theme='dark'] .global-tests-hero p, html[data-command-theme='dark'] .global-tests-engine { color: #aab7d0; }
        html[data-command-theme='dark'] .global-tests-history-link, html[data-command-theme='dark'] .global-tests-jump-nav, html[data-command-theme='dark'] .global-tests-config-card, html[data-command-theme='dark'] .global-tests-form-section, html[data-command-theme='dark'] .global-tests-overview-card, html[data-command-theme='dark'] .global-tests-overview-cap { background: #111a30; border-color: #29344e; color: #cbd5e1; }
        html[data-command-theme='dark'] .global-tests-overview-card strong { color: #f8fafc; }
        html[data-command-theme='dark'] .global-tests-jump-nav a, html[data-command-theme='dark'] #global-test-form label { color: #cbd5e1; }
        html[data-command-theme='dark'] .global-tests-section-heading, html[data-command-theme='dark'] .global-tests-card-heading { border-color: #29344e; }
        html[data-command-theme='dark'] #global-test-form .form-control, html[data-command-theme='dark'] .global-icon-button, html[data-command-theme='dark'] .global-tag-help, html[data-command-theme='dark'] .global-tag-button { background: #17223d; border-color: #394764; color: #e2e8f0; }
        html[data-command-theme='dark'] .global-ip-list { background: #151f37; border-color: #29344e; }
        html[data-command-theme='dark'] .global-placeholder-panel { background: linear-gradient(135deg, #111a30, #171e37); border-color: #29344e; }
        html[data-command-theme='dark'] .global-placeholder-heading { border-color: #29344e; }
        html[data-command-theme='dark'] .global-placeholder-title h4 { color: #f1f5f9; }
        html[data-command-theme='dark'] .global-placeholder-title p, html[data-command-theme='dark'] .global-placeholder-table th { color: #94a3b8; }
        html[data-command-theme='dark'] .global-placeholder-toggle, html[data-command-theme='dark'] .global-action-icon { background: #17223d; border-color: #394764; color: #ddd6fe; }
        html[data-command-theme='dark'] .global-placeholder-table .form-control { background: #17223d; border-color: #394764; color: #e2e8f0; }
        html[data-command-theme='dark'] .global-icon-danger { background: #3a1d2b; border-color: #73314a; color: #fda4af; }
        html[data-command-theme='dark'] .global-tests-send-estimate { background: #17223d; border-color: #29344e; color: #cbd5e1; }
        html[data-command-theme='dark'] .global-dialog { background: #111a30; border-color: #394764; color: #e2e8f0; }
        html[data-command-theme='dark'] .global-dialog h2 { color: #f1f5f9; }
        html[data-command-theme='dark'] .global-quick-nav-menu, html[data-command-theme='dark'] #global-test-context-menu { background: #111a30; border-color: #394764; }
        html[data-command-theme='dark'] .global-quick-nav-menu button, html[data-command-theme='dark'] #global-test-context-menu button { color: #e2e8f0; }
        @media (max-width: 991.98px) { .global-tests-overview { grid-template-columns: repeat(2, minmax(0, 1fr)); } .global-tests-overview-cap { grid-column: span 2; } }
        @media (max-width: 767.98px) {
            .global-tests-hero, .global-tests-hero-main { align-items: flex-start; }
            .global-tests-hero { flex-direction: column; }
            .global-tests-hero-actions { align-items: flex-start; flex-direction: row; flex-wrap: wrap; }
            .global-tests-overview { grid-template-columns: 1fr 1fr; }
            .global-tests-overview-cap { grid-column: 1 / -1; }
            .global-tests-jump-nav a:last-child { margin-left: 0; }
            .global-tests-card-heading { align-items: flex-start; flex-direction: column; }
            .global-placeholder-heading { align-items: flex-start; flex-direction: column; }
            .global-placeholder-toolbar { width: 100%; }
            .global-placeholder-toggle { flex: 1 1 8rem; justify-content: center; }
            .global-tests-form-section .form-row { margin-left: -.35rem; margin-right: -.35rem; }
            .global-tests-form-section [class*="col-"] { padding-left: .35rem; padding-right: .35rem; }
            .global-ip-picker { grid-template-columns: 1fr; }
            .global-ip-actions { flex-direction: row; justify-content: center; }
            .global-ip-list select { height: 13rem; min-height: 13rem; }
            .global-test-editor { min-height: 13rem; }
            #global-test-form .ace_editor { height: 18rem; }
        }
        @media (max-width: 480px) { .global-tests-overview { grid-template-columns: 1fr; } .global-tests-overview-cap { grid-column: auto; } .global-tests-hero-main { gap: .75rem; } .global-tests-hero-icon { flex-basis: 2.8rem; height: 2.8rem; } .global-tests-hero p { font-size: .8rem; } }
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('console-assets/vendor/ace/ace.js') }}" charset="utf-8"></script>
    <script src="{{ asset('console-assets/vendor/ace/ext-language_tools.js') }}"></script>
    <script src="{{ asset('console-assets/global-tests.js') }}?v={{ substr(sha1_file(public_path('console-assets/global-tests.js')), 0, 12) }}" defer></script>
@endpush
