@extends('layouts.dms-admin')

@section('title', 'GLOBAL TESTS - DMS')

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

    <div id="global-tests-page"
         data-test-url="{{ route('console.global-tests.test', $workspace->id) }}"
         data-catalog-url="{{ route('console.global-tests.catalog', $workspace->id) }}"
         data-encode-url="{{ route('console.global-tests.encoding', $workspace->id) }}"
         data-address-store-url="{{ route('console.campaign-test-addresses.store', $workspace->id) }}"
         data-address-delete-base="{{ url('/app/workspaces/'.$workspace->id.'/campaign-test-addresses') }}"
         data-header-base="{{ url('/app/workspaces/'.$workspace->id.'/campaign-headers') }}"
         data-pmta-admin-url="{{ route('console.pmta-admin.index', $workspace->id) }}"
         data-pmta-admin-port="{{ $pmtaAdminPort }}">
        <section class="block block-rounded" id="global-tests-block">
            <div class="block-header">
                <h3 class="block-title">GLOBAL TESTS</h3>
                <div class="block-options">
                    <a class="btn btn-sm btn-alt-secondary" href="{{ route('console.global-tests.histories', $workspace->id) }}">
                        <i class="fas fa-history mr-1" aria-hidden="true"></i>Histories
                    </a>
                    <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
                </div>
            </div>
            <div class="block-content block-content-full">
                @unless ($senderConfigured)
                    <div class="alert alert-warning" role="status">
                        AlphaLink’s Global Test sender is not configured. The form is ready, but sending stays unavailable until an AlphaLink sender is installed.
                    </div>
                @endunless

                <div class="alert alert-primary alert-dismissible fade show text-center" role="note">
                    <strong>USABLE TAGS:</strong>
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
                    <button type="button" class="global-tag-help" id="show-global-tags" title="Show tag meanings" aria-label="Show tag meanings"><i class="fas fa-question-circle" aria-hidden="true"></i></button>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close tag guide"><span aria-hidden="true">&times;</span></button>
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
                            <button id="send-global-test" class="btn btn-primary" type="submit"><i class="fas fa-vial mr-1" aria-hidden="true"></i>SEND TEST</button>
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
            <p><code>[rnda_10]</code> random alphanumeric string of length 10. Other DMS forms include <code>[rndn_N]</code>, <code>[rndl_N]</code>, <code>[rndu_N]</code>, <code>[rnds_N]</code>, <code>[rndlu_N]</code>, <code>[rndln_N]</code> and <code>[rndun_N]</code>. <code>[text:a|b|c]</code> chooses one listed value.</p>
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
        #global-test-form .ace_editor { height: 30rem; width: 100%; }
        .global-test-editor { min-height: 17rem; resize: vertical; font-family: var(--font-family-monospace, monospace); }
        .global-icon-button, .global-tag-button, .global-tag-help { border: 0; background: transparent; color: #216eb4; cursor: pointer; padding: .1rem .25rem; }
        .global-tag-button { color: #204e94; font-family: var(--font-family-monospace, monospace); font-size: .82rem; }
        .global-icon-button:focus-visible, .global-tag-button:focus-visible, .global-tag-help:focus-visible { outline: 2px solid #216eb4; outline-offset: 2px; }
        .global-ip-picker { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); gap: .5rem; align-items: center; }
        .global-ip-list { min-width: 0; }
        .global-ip-list select { min-height: 20rem; }
        .global-ip-actions { display: flex; flex-direction: column; gap: .25rem; }
        .global-ip-actions .btn { min-width: 2.5rem; }
        .global-test-output { max-height: 24rem; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; background: #f6f7f8; border: 1px solid #d8dee4; padding: .75rem; }
        .global-quick-nav { position: fixed; right: 0; top: 50%; transform: translateY(-50%); z-index: 1100; }
        .global-quick-nav-trigger { background: #4f78d7; border: 0; color: #fff; font-size: 1.2rem; min-height: 3rem; min-width: 3rem; }
        .global-quick-nav-menu { background: #4f78d7; box-shadow: 0 .25rem .8rem rgb(0 0 0 / 25%); padding: .35rem; }
        .global-quick-nav-menu button { background: transparent; border: 0; color: #fff; display: flex; gap: .6rem; align-items: center; min-height: 2.25rem; padding: .4rem .5rem; text-align: left; width: 100%; white-space: nowrap; }
        .global-quick-nav-menu button:hover, .global-quick-nav-menu button:focus-visible { background: rgb(255 255 255 / 18%); }
        #global-test-context-menu[hidden] { display: none !important; }
        #global-test-context-menu { position: fixed; z-index: 1110; min-width: 12rem; padding: .3rem 0; background: #fff; box-shadow: 0 2px 8px rgb(0 0 0 / 32%); }
        #global-test-context-menu button { display: flex; width: 100%; align-items: center; justify-content: space-between; gap: 1rem; border: 0; background: transparent; color: #253044; padding: .5rem .75rem; text-align: left; white-space: nowrap; }
        #global-test-context-menu button:hover, #global-test-context-menu button:focus-visible { background: #edf2ff; outline: 0; }
        #global-test-context-menu i { color: #3182ce; }
        .global-dialog { border: 0; border-radius: .25rem; box-shadow: 0 .5rem 1.5rem rgb(0 0 0 / 28%); max-height: min(88vh, 56rem); max-width: min(44rem, calc(100% - 2rem)); overflow: auto; padding: 1.25rem; width: calc(100% - 2rem); }
        .global-dialog::backdrop { background: rgb(0 0 0 / 50%); }
        .global-dialog h2 { font-size: 1.2rem; margin: 0 2rem .8rem 0; }
        .global-dialog-close { position: absolute; right: 1rem; top: .9rem; }
        .global-dialog-actions { display: flex; gap: .5rem; justify-content: flex-end; margin-top: 1rem; }
        .global-preview-dialog { height: 80vh; max-width: min(70rem, calc(100% - 2rem)); }
        .global-preview-dialog iframe { border: 1px solid #d8dee4; height: calc(100% - 6rem); width: 100%; }
        .global-tag-reference { line-height: 1.65; }
        .global-encoding-tabs { border-bottom: 1px solid #d8dee4; display: flex; gap: .35rem; margin-bottom: 1rem; }
        .global-encoding-tabs button[aria-selected="true"] { border-bottom: 2px solid #216eb4; color: #216eb4; }
        .global-encoding-tabs button { background: transparent; border: 0; padding: .5rem .75rem; }
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
    <script src="{{ asset('dms-reference/vendor/ace/ace.js') }}" charset="utf-8"></script>
    <script src="{{ asset('dms-reference/vendor/ace/ext-language_tools.js') }}"></script>
    <script src="{{ asset('dms-reference/global-tests.js') }}?v={{ substr(sha1_file(public_path('dms-reference/global-tests.js')), 0, 12) }}" defer></script>
@endpush
