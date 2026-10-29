@extends('layouts.dms-admin')

@section('title', 'App Settings - DMS')

@section('content')
    @php
        $ipLists = [];
        foreach (['dev_ips', 'production_ips', 'rdp_ips'] as $field) {
            $value = old($field, $settings[$field]);
            $ipLists[$field] = is_array($value)
                ? $value
                : (is_string($value) && trim($value) !== '' ? array_map('trim', explode(',', $value)) : []);
        }
    @endphp
    <div class="block block-rounded">
        <ul class="nav nav-tabs nav-tabs-block align-items-center" data-toggle="tabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" href="#settings">GENERAL SETTINGS</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#pmta_domains">PMTA DOMAINS SETTINGS</a>
            </li>
            <li class="nav-item ml-auto">
                <div class="block-options pl-3 pr-2">
                    <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
                </div>
            </li>
        </ul>
        <div class="block-content tab-content p-4">
            <div class="tab-pane active" id="settings" role="tabpanel">
                @if (session('status'))
                    <div class="alert alert-success" role="status">{{ session('status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="alert alert-danger" role="alert">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('console.infrastructure.install-settings.update', $workspace->id) }}" method="POST">
                    @csrf
                    <div class="row">
                        <div class="col-sm-12 col-md-3">
                            <div class="form-group">
                                <label for="mini_app_key">TRACKING APP KEY:</label>
                                <input id="mini_app_key" name="mini_app_key" class="form-control" value="" autocomplete="off" placeholder="{{ $secretConfigured['mini_app_key'] ? 'Saved; leave blank to keep' : '' }}" aria-describedby="mini-app-key-help">
                                <small id="mini-app-key-help" class="form-text text-muted">{{ $secretConfigured['mini_app_key'] ? 'Saved encrypted. Enter a new value to replace it.' : 'Required only when TRACKING is selected.' }}</small>
                            </div>
                        </div>
                        <div class="col-sm-12 col-md-3">
                            <div class="form-group">
                                <label for="dkim_key">APP DKIM KEY:</label>
                                <input id="dkim_key" name="dkim_key" class="form-control" value="" autocomplete="off" placeholder="{{ $secretConfigured['dkim_key'] ? 'Saved; leave blank to keep' : '' }}" aria-describedby="dkim-key-help">
                                <small id="dkim-key-help" class="form-text text-muted">{{ $secretConfigured['dkim_key'] ? 'Saved encrypted. Enter a new value to replace it.' : 'Required only when DKIM is selected.' }}</small>
                            </div>
                        </div>
                        <div class="col-sm-12 col-md-6">
                            <div class="form-group">
                                <label for="apply_firewall">APPLY FIREWALL:</label>
                                <select id="apply_firewall" name="apply_firewall" class="form-control">
                                    <option value="1" @selected((string) old('apply_firewall', (int) $settings['apply_firewall']) === '1')>YES</option>
                                    <option value="0" @selected((string) old('apply_firewall', (int) $settings['apply_firewall']) === '0')>NO</option>
                                </select>
                            </div>
                            @foreach (['dev_ips' => 'DEV IPS', 'production_ips' => 'PRODUCTION IPS', 'rdp_ips' => 'RDP IPS'] as $field => $label)
                                <div class="form-group">
                                    <label for="{{ $field }}">{{ $label }}:</label>
                                    <select id="{{ $field }}" name="{{ $field }}[]" class="form-control installer-ip-list" multiple>
                                        @foreach ($ipLists[$field] as $ip)
                                            <option value="{{ $ip }}" selected>{{ $ip }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-12 col-md-3">
                            <p>PMTA SETTINGS:</p>
                        </div>
                        <div class="col-sm-12 col-md-3">
                            <div class="form-group">
                                <label for="pmta_port">PMTA PORT:</label>
                                <input id="pmta_port" type="number" name="pmta_port" class="form-control" value="{{ old('pmta_port', $settings['pmta_port']) }}" required>
                            </div>
                        </div>
                        <div class="col-sm-12 col-md-3">
                            <div class="form-group">
                                <label for="pmta_admin_port">PMTA WEB MONITOR PUBLIC PORT:</label>
                                <input id="pmta_admin_port" type="number" name="pmta_admin_port" class="form-control" value="{{ old('pmta_admin_port', $settings['pmta_admin_port']) }}" required>
                            </div>
                        </div>
                        <div class="col-sm-12 col-md-3">
                            <div class="form-group">
                                <label for="allow_all_monitor">SHOW PMTA MONITOR TO ALL:</label>
                                <select id="allow_all_monitor" name="allow_all_monitor" class="form-control">
                                    <option value="1" @selected((string) old('allow_all_monitor', (int) $settings['allow_all_monitor']) === '1')>YES</option>
                                    <option value="0" @selected((string) old('allow_all_monitor', (int) $settings['allow_all_monitor']) === '0')>NO</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <small class="form-text text-muted">PMTA PORT is the mail listener. PMTA WEB MONITOR PUBLIC PORT is the only browser-facing monitor port. PMTA 4.5/5.0 use a private backend managed by AlphaLink behind Nginx; do not open a second public port. PMTA 4.0 uses this port directly. SHOW PMTA MONITOR TO ALL opens the read-only monitor only. If DEV IPS and PRODUCTION IPS are both empty, installation enables this public monitor automatically; PMTA administrator commands remain restricted to AlphaLink's relay source/local proxy. Saving settings does not rewrite a server that is already installed.</small>
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-12 col-md-3">
                            <p>PROXY SETTINGS:</p>
                        </div>
                        <div class="col-sm-12 col-md-3">
                            <div class="form-group">
                                <label for="proxy_port">PROXY PORT:</label>
                                <input id="proxy_port" type="number" name="proxy_port" class="form-control" value="{{ old('proxy_port', $settings['proxy_port']) }}" required>
                            </div>
                        </div>
                        <div class="col-sm-12 col-md-3">
                            <div class="form-group">
                                <label for="proxy_username">PROXY USERNAME:</label>
                                <input id="proxy_username" name="proxy_username" class="form-control" placeholder="{{ $secretConfigured['proxy_username'] ? 'Saved; blank keeps current' : 'optional' }}" value="" autocomplete="off">
                            </div>
                        </div>
                        <div class="col-sm-12 col-md-3">
                            <div class="form-group">
                                <label for="proxy_password">PROXY PASSWORD:</label>
                                <input id="proxy_password" name="proxy_password" class="form-control" placeholder="{{ $secretConfigured['proxy_password'] ? 'Saved; blank keeps current' : 'optional' }}" value="" autocomplete="off">
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-12 col-md-3">
                            <p>WEBMAIL SETTINGS:</p>
                        </div>
                        <div class="col-sm-12 col-md-3">
                            <div class="form-group">
                                <label for="webmail_username">WEBMAIL USERNAME:</label>
                                <input id="webmail_username" name="webmail_username" class="form-control" value="" autocomplete="off" placeholder="{{ $secretConfigured['webmail_username'] ? 'Saved; leave blank to keep' : '' }}" aria-describedby="webmail-username-help">
                                <small id="webmail-username-help" class="form-text text-muted">{{ $secretConfigured['webmail_username'] ? 'Saved encrypted. Enter a new value to replace it.' : 'Required only when IMAP is selected.' }}</small>
                            </div>
                        </div>
                        <div class="col-sm-12 col-md-3">
                            <div class="form-group">
                                <label for="webmail_password">WEBMAIL PASSWORD:</label>
                                <input id="webmail_password" name="webmail_password" class="form-control" value="" autocomplete="off" placeholder="{{ $secretConfigured['webmail_password'] ? 'Saved; leave blank to keep' : '' }}" aria-describedby="webmail-password-help">
                                <small id="webmail-password-help" class="form-text text-muted">{{ $secretConfigured['webmail_password'] ? 'Saved encrypted. Enter a new value to replace it.' : 'Required only when IMAP is selected.' }}</small>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-12 text-md-right">
                            <button type="submit" class="btn btn-primary">SAVE SETTINGS</button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="tab-pane" id="pmta_domains" role="tabpanel">
                <div class="form-group">
                    <div class="input-group">
                        <select id="pmta_version" class="form-control">
                            <option value="40" selected>PMTA 4.0</option>
                            <option value="45">PMTA 4.5</option>
                            <option value="50">PMTA 5.0</option>
                        </select>
                        <div class="input-group-append">
                            <button id="load_pmta_domain_data" class="btn btn-primary" type="button">LOAD SETTINGS</button>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="pmta_domain_data">PMTA DOMAINS SETTINGS</label>
                    <textarea id="pmta_domain_data" class="form-control" rows="50"></textarea>
                </div>
                <div class="form-group text-md-right">
                    <button id="save_pmta_domain_data" class="btn btn-primary" type="button">SAVE SETTINGS</button>
                </div>
                <div id="pmta-domain-status" class="alert d-none" role="status" aria-live="polite"></div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://unpkg.com/select2@4.0.13/dist/js/select2.min.js"></script>
    <script>
        $(function () {
            const ipPlaceholders = {
                dev_ips: 'ADD DEV IPS',
                production_ips: 'ADD PRODUCTION IPS',
                rdp_ips: 'ADD RDP IPS'
            };
            $('.installer-ip-list').each(function () {
                $(this).select2({allowClear: true, placeholder: ipPlaceholders[this.id], tags: true});
            });

            const status = $('#pmta-domain-status');
            const endpoint = @json(route('console.infrastructure.install-settings.domains.load', $workspace->id));
            const saveEndpoint = @json(route('console.infrastructure.install-settings.domains.update', $workspace->id));
            const csrf = @json(csrf_token());

            const showStatus = (message, success) => {
                status.removeClass('d-none alert-success alert-danger').addClass(success ? 'alert-success' : 'alert-danger').text(message);
            };

            $('#load_pmta_domain_data').on('click', async function () {
                const button = $(this);
                button.prop('disabled', true);
                status.addClass('d-none');
                try {
                    const url = new URL(endpoint, window.location.origin);
                    url.searchParams.set('pmta_version', $('#pmta_version').val());
                    const response = await fetch(url, {headers: {'Accept': 'application/json'}});
                    const result = await response.json();
                    if (!response.ok) {
                        throw new Error(result.message || 'Could not load PMTA settings.');
                    }
                    $('#pmta_domain_data').val(result.data || '');
                } catch (error) {
                    showStatus(error.message || 'Could not load PMTA settings.', false);
                } finally {
                    button.prop('disabled', false);
                }
            });

            $('#save_pmta_domain_data').on('click', async function () {
                const button = $(this);
                button.prop('disabled', true);
                status.addClass('d-none');
                try {
                    const response = await fetch(saveEndpoint, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf
                        },
                        body: JSON.stringify({
                            pmta_version: $('#pmta_version').val(),
                            data: $('#pmta_domain_data').val()
                        })
                    });
                    const result = await response.json();
                    if (!response.ok) {
                        throw new Error(result.message || 'Could not save PMTA settings.');
                    }
                    showStatus(result.data || 'Edited Successfully', true);
                } catch (error) {
                    showStatus(error.message || 'Could not save PMTA settings.', false);
                } finally {
                    button.prop('disabled', false);
                }
            });
        });
    </script>
@endpush
