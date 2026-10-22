@extends('layouts.dms-admin')

@section('title', 'Bulk Install - DMS')

@push('styles')
    <style>
        #main-container > #main_content { padding: 1rem 1rem; }
        .bulk-install-row-value { min-width: 8rem; }
        .bulk-install-row-value[readonly] { border-color: transparent; background: transparent; }
        .bulk-install-domain { min-width: 15rem; }
        .bulk-install-apply { white-space: nowrap; }
        .bulk-install-remove { margin-left: .35rem; }
    </style>
@endpush

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Bulk Install Server</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <div class="row push">
                <div class="col-sm-12">
                    <div class="form-group">
                        <div class="custom-control custom-control-inline" style="padding-left: 0">
                            <label class="sr-only" for="pmta_version">PowerMTA version</label>
                            <select id="pmta_version" class="form-control" style="height: 30px;">
                                <option value="40" selected>PMTA 4.0</option>
                                <option value="45">PMTA 4.5</option>
                                <option value="50">PMTA 5.0</option>
                            </select>
                        </div>
                        @foreach (['tracking' => 'TRACKING', 'proxy' => 'PROXY', 'imap' => 'IMAP', 'spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC', 'https' => 'HTTPS'] as $option => $label)
                            <div class="custom-control custom-switch custom-control-inline">
                                <input type="checkbox" class="custom-control-input" id="{{ $option }}" name="{{ $option }}">
                                <label class="custom-control-label" for="{{ $option }}">{{ $label }}</label>
                            </div>
                        @endforeach
                    </div>
                    <div class="table-responsive">
                        <table id="servers" class="table table-sm table-hover">
                            <thead class="thead-light">
                                <tr>
                                    <th>SERVER NAME</th>
                                    <th>SERVER ID</th>
                                    <th>SERVER IP</th>
                                    <th>SELECTED DOMAIN</th>
                                    <th>APPLY</th>
                                    <th aria-label="Row actions"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($servers as $server)
                                    <tr data-server-row>
                                        <td><input class="form-control form-control-sm bulk-install-row-value" value="{{ $server->name }}" readonly aria-label="Server name"></td>
                                        <td><input class="form-control form-control-sm bulk-install-row-value" value="{{ $server->id }}" readonly aria-label="Server ID"></td>
                                        <td><input class="form-control form-control-sm bulk-install-row-value" value="{{ $server->ip_address }}" readonly aria-label="Server IP"></td>
                                        <td>
                                            <select class="form-control form-control-sm bulk-install-domain" data-domain-select aria-label="Selected domain for {{ $server->name }}">
                                                <option value=""></option>
                                                @foreach ($domains as $domain)
                                                    <option value="{{ $domain['id'] }}">{{ $domain['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="bulk-install-apply">
                                            <input type="checkbox" class="form-control-sm" data-apply-domain aria-label="Apply this domain to all servers">
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-alt-danger bulk-install-remove" data-remove-row aria-label="Remove server from bulk installation"><i class="fa fa-times" aria-hidden="true"></i></button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center">This Grid Is Empty</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="form-group text-right">
                        <button type="button" class="btn btn-primary" id="install" disabled aria-describedby="install-disabled-reason" title="Remote installation is not enabled in AlphaLink.">INSTALL</button>
                        <span id="install-disabled-reason" class="sr-only">
                            Remote installation remains disabled until the DMS installer, packages, license, rollback, and health checks are implemented and validated.
                            @if ($missingUsername) Link the DMS username before evaluating server-name eligibility. @endif
                            @if ($domains->isEmpty()) No eligible non-owned domain from an active provider is available. @endif
                        </span>
                    </div>
                    <div class="form-group d-none" id="container_log_result">
                        <h2 class="content-heading border-bottom mb-4 pb-2">Installation Logs</h2>
                        <code id="log_result" style="white-space: pre-wrap;"></code>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const rows = () => Array.from(document.querySelectorAll('[data-server-row]'));

            document.addEventListener('click', (event) => {
                const remove = event.target.closest('[data-remove-row]');
                if (remove) {
                    remove.closest('[data-server-row]')?.remove();
                }
            });

            document.addEventListener('click', (event) => {
                const apply = event.target.closest('[data-apply-domain]');
                if (!apply) return;

                event.preventDefault();
                const row = apply.closest('[data-server-row]');
                const source = row?.querySelector('[data-domain-select]');
                if (!source || source.selectedIndex < 1) return;

                const selected = source.value;
                rows().forEach((candidate) => {
                    const target = candidate.querySelector('[data-domain-select]');
                    if (target) target.value = selected;
                });
            });
        })();
    </script>
@endpush
