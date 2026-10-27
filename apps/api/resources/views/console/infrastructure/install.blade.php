@extends('layouts.dms-admin')

@section('title', 'Install Server — DMS')

@push('styles')
    <style>
        #single-install-ips .single-install-ip-value[readonly] { border-color: transparent; background: transparent; }
        #single-install-ips .single-install-subdomain { min-width: 12rem; }
        #single-install-ips .single-install-domain { min-width: 13rem; }
        #single-install-ips .single-install-remove { white-space: nowrap; }
    </style>
@endpush

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Install Server</h3>
            <div class="block-options">
                <a class="btn btn-sm btn-alt-secondary" href="{{ route('console.infrastructure.install-settings.edit', $workspace->id) }}">Installation settings</a>
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            @if ($servers->isEmpty())
                <div class="alert alert-info" role="status">No active, uninstalled servers are available for installation.</div>
            @elseif ($domains->isEmpty())
                <div class="alert alert-info" role="status">No active free or new sending domains with an active provider are available.</div>
            @endif

            <form id="single-install-form"
                  data-load-url="{{ route('console.infrastructure.install.load-ips', $workspace->id) }}"
                  data-install-url="{{ route('console.infrastructure.install.runs.store', $workspace->id) }}"
                  data-log-url="{{ route('console.infrastructure.bulk-install.logs', ['workspace' => $workspace->id, 'runId' => '__token__']) }}">
                <div class="row push">
                    <div class="col-sm-12 offset-md-2 col-md-8">
                        <div class="form-group">
                            <label for="single-server">Servers:</label>
                            <select class="form-control" id="single-server" required @disabled($servers->isEmpty() || $domains->isEmpty())>
                                <option value="">Select Server ...</option>
                                @foreach ($servers as $server)
                                    <option value="{{ $server->id }}">{{ $server->name }}{{ $server->id }} | {{ $server->ip_address }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="single-domains">Domains:</label>
                            <select class="form-control" id="single-domains" multiple required @disabled($servers->isEmpty() || $domains->isEmpty())>
                                @foreach ($domains as $domain)
                                    <option value="{{ $domain['id'] }}">{{ $domain['label'] }}</option>
                                @endforeach
                            </select>
                            <small class="form-text text-muted">Select domains in the order they should receive IP groups.</small>
                        </div>
                        <div class="form-group">
                            <div class="custom-control custom-switch custom-control-primary mb-1">
                                <input type="checkbox" class="custom-control-input" id="single-with-ipv6" checked>
                                <label class="custom-control-label" for="single-with-ipv6">WITH IPV6</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <button class="btn btn-primary btn-block" id="single-load-ips" type="button" @disabled($servers->isEmpty() || $domains->isEmpty())>LOAD IPS</button>
                        </div>
                    </div>
                </div>

                <div class="row push d-none" id="single-install-holder">
                    <div class="col-sm-12">
                        <div class="form-group">
                            <div class="custom-control custom-control-inline" style="padding-left: 0">
                                <label class="sr-only" for="pmta_version">PowerMTA version</label>
                                <select id="pmta_version" class="form-control" style="height: 30px;">
                                    <option value="40">PMTA 4.0</option>
                                    <option value="45" selected>PMTA 4.5</option>
                                    <option value="50">PMTA 5.0</option>
                                </select>
                            </div>
                            @foreach (['tracking' => 'TRACKING', 'https' => 'HTTPS', 'proxy' => 'PROXY', 'imap' => 'IMAP', 'spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC'] as $option => $label)
                                <div class="custom-control custom-switch custom-control-inline">
                                    <input type="checkbox" class="custom-control-input" id="{{ $option }}" @checked(in_array($option, ['tracking', 'imap', 'spf', 'dkim', 'dmarc'], true))>
                                    <label class="custom-control-label" for="{{ $option }}">{{ $label }}</label>
                                </div>
                            @endforeach
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover" id="single-install-ips">
                                <thead class="thead-light">
                                    <tr>
                                        <th>IP</th>
                                        <th>TYPE</th>
                                        <th>MAIN</th>
                                        <th>SUB DOMAIN</th>
                                        <th>DOMAIN</th>
                                        <th aria-label="Row actions"></th>
                                    </tr>
                                </thead>
                                <tbody id="single-install-ip-rows"></tbody>
                            </table>
                        </div>
                        <div class="form-group text-right">
                            <button class="btn btn-warning" id="single-config-ips" type="button" disabled aria-haspopup="dialog" aria-controls="single-install-ip-config-dialog">CONFIG IPS</button>
                            <button class="btn btn-primary" id="single-install-submit" type="submit" disabled>INSTALL</button>
                        </div>
                        <div class="alert alert-warning d-none" id="single-install-config-ips-note" role="status">CONFIG IPS changes server network interfaces. Permanent mode replaces IPv4 alias files and restarts networking.</div>
                        <div class="alert alert-danger d-none" id="single-install-error" role="alert" aria-live="polite"></div>
                        <div class="form-group d-none mt-4" id="single-install-logs">
                            <h2 class="content-heading border-bottom mb-4 pb-2">Installation Logs</h2>
                            <code id="single-install-log" style="white-space: pre-wrap;" aria-live="polite"></code>
                        </div>
                    </div>
                </div>
            </form>
            @include('console.infrastructure.partials.server-ip-config-dialog', [
                'idPrefix' => 'single-install',
                'configUrlTemplate' => route('console.infrastructure.install.config-ips', [$workspace->id, '__SERVER__']),
                'serverSelector' => '#single-server',
                'logSelector' => '#single-install-log',
                'refreshSelector' => '#single-load-ips',
            ])
        </div>
    </div>
@endsection

@push('scripts')
    <script type="module" src="/alphalink/infrastructure/single-install.js?v={{ filemtime(public_path('alphalink/infrastructure/single-install.js')) }}"></script>
    <script type="module" src="/alphalink/infrastructure/server-ip-config-dialog.js"></script>
@endpush
