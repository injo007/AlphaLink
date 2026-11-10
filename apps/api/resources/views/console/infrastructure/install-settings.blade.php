@extends('layouts.admin-shell')

@section('title', 'Installation Settings')

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

    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-sliders-h"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">PROVISIONING CONFIGURATION // GLOBAL STACK DEFAULTS</div>
                    <h1 class="command-os-report-hero-title">INSTALLATION SETTINGS</h1>
                    <p class="command-os-report-hero-desc">Global configuration keys, PMTA daemon ports, proxy credentials, and PowerMTA domain routing blueprints.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    <span class="command-os-report-badge">ENGINE PROFILE</span>
                </div>
            </div>
        </header>

        <!-- 2. Settings Card with Tabs -->
        <div class="command-os-table-card">
            <div class="p-3 border-bottom" style="border-color: var(--command-os-border, rgba(99, 102, 241, 0.15)) !important;">
                <ul class="nav nav-pills" role="tablist" style="gap: 8px;">
                    <li class="nav-item">
                        <a class="nav-link active btn-command-action" href="#settings" data-toggle="tab" role="tab" style="border-radius: 10px; font-weight: 600; font-size: 0.8125rem;">
                            <i class="fas fa-cog mr-1"></i> GENERAL SETTINGS
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn-command-action" href="#pmta_domains" data-toggle="tab" role="tab" style="border-radius: 10px; font-weight: 600; font-size: 0.8125rem;">
                            <i class="fas fa-code mr-1"></i> PMTA DOMAINS DIRECTIVES
                        </a>
                    </li>
                </ul>
            </div>

            <div class="tab-content p-4">
                <div class="tab-pane active" id="settings" role="tabpanel">
                    @if (session('status'))
                        <div class="alert alert-success mb-4" role="status" style="border-radius: 12px; font-size: 0.8125rem;">
                            <i class="fas fa-check-circle mr-1"></i> {{ session('status') }}
                        </div>
                    @endif
                    @if ($errors->any())
                        <div class="alert alert-danger mb-4" role="alert" style="border-radius: 12px; font-size: 0.8125rem;">
                            <ul class="mb-0 pl-3">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form action="{{ route('console.infrastructure.install-settings.update', $workspace->id) }}" method="POST">
                        @csrf
                        <div class="row">
                            <div class="col-sm-12 col-md-6">
                                <div class="command-form-group">
                                    <label class="command-form-label" for="tracking_ingest_key">
                                        <i class="fas fa-key mr-1 text-purple"></i> TRACKING APP KEY
                                    </label>
                                    <input id="tracking_ingest_key" name="tracking_ingest_key" class="command-form-control" value="" autocomplete="off" placeholder="{{ $secretConfigured['tracking_ingest_key'] ? 'Saved; leave blank to keep' : 'Enter tracking ingest key...' }}" aria-describedby="tracking-ingest-key-help">
                                    <small id="tracking-ingest-key-help" class="text-muted mt-1 d-block" style="font-size: 0.75rem;">{{ $secretConfigured['tracking_ingest_key'] ? 'Saved encrypted. Enter a new value to replace it.' : 'Required only when TRACKING is selected.' }}</small>
                                </div>
                            </div>
                            <div class="col-sm-12 col-md-6">
                                <div class="command-form-group">
                                    <label class="command-form-label" for="dkim_key">
                                        <i class="fas fa-signature mr-1 text-purple"></i> APP DKIM KEY
                                    </label>
                                    <input id="dkim_key" name="dkim_key" class="command-form-control" value="" autocomplete="off" placeholder="{{ $secretConfigured['dkim_key'] ? 'Saved; leave blank to keep' : 'Enter DKIM private key...' }}" aria-describedby="dkim-key-help">
                                    <small id="dkim-key-help" class="text-muted mt-1 d-block" style="font-size: 0.75rem;">{{ $secretConfigured['dkim_key'] ? 'Saved encrypted. Enter a new value to replace it.' : 'Required only when DKIM is selected.' }}</small>
                                </div>
                            </div>
                        </div>

                        <div class="row mt-2">
                            <div class="col-sm-12 col-md-4">
                                <div class="command-form-group">
                                    <label class="command-form-label" for="apply_firewall">
                                        <i class="fas fa-shield-alt mr-1 text-purple"></i> APPLY FIREWALL
                                    </label>
                                    <div class="command-select-wrapper">
                                        <select id="apply_firewall" name="apply_firewall" class="command-form-control command-select">
                                            <option value="1" @selected((string) old('apply_firewall', (int) $settings['apply_firewall']) === '1')>YES</option>
                                            <option value="0" @selected((string) old('apply_firewall', (int) $settings['apply_firewall']) === '0')>NO</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            @foreach (['dev_ips' => 'DEV IPS', 'production_ips' => 'PRODUCTION IPS', 'rdp_ips' => 'RDP IPS'] as $field => $label)
                                <div class="col-sm-12 col-md-4">
                                    <div class="command-form-group">
                                        <label class="command-form-label" for="{{ $field }}">
                                            <i class="fas fa-network-wired mr-1 text-purple"></i> {{ $label }}
                                        </label>
                                        <select id="{{ $field }}" name="{{ $field }}[]" class="command-form-control installer-ip-list" multiple>
                                            @foreach ($ipLists[$field] as $ip)
                                                <option value="{{ $ip }}" selected>{{ $ip }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="p-3 my-4" style="background: rgba(99, 102, 241, 0.03); border: 1px solid rgba(99, 102, 241, 0.12); border-radius: 14px;">
                            <h5 class="mb-3 font-w700" style="font-size: 0.875rem;"><i class="fas fa-server mr-1 text-purple"></i> POWERMTA DAEMON SETTINGS</h5>
                            <div class="row">
                                <div class="col-sm-12 col-md-4">
                                    <div class="command-form-group">
                                        <label class="command-form-label" for="pmta_port">PMTA PORT (SMTP LISTENER)</label>
                                        <input id="pmta_port" type="number" name="pmta_port" class="command-form-control" value="{{ old('pmta_port', $settings['pmta_port']) }}" required>
                                    </div>
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    <div class="command-form-group">
                                        <label class="command-form-label" for="pmta_admin_port">PMTA WEB MONITOR PUBLIC PORT</label>
                                        <input id="pmta_admin_port" type="number" name="pmta_admin_port" class="command-form-control" value="{{ old('pmta_admin_port', $settings['pmta_admin_port']) }}" required>
                                    </div>
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    <div class="command-form-group">
                                        <label class="command-form-label" for="allow_all_monitor">SHOW PMTA MONITOR TO ALL</label>
                                        <div class="command-select-wrapper">
                                            <select id="allow_all_monitor" name="allow_all_monitor" class="command-form-control command-select">
                                                <option value="1" @selected((string) old('allow_all_monitor', (int) $settings['allow_all_monitor']) === '1')>YES</option>
                                                <option value="0" @selected((string) old('allow_all_monitor', (int) $settings['allow_all_monitor']) === '0')>NO</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <small class="text-muted mt-2 d-block" style="font-size: 0.75rem;">PMTA PORT is the mail listener. PMTA WEB MONITOR PUBLIC PORT is the only browser-facing monitor port. PMTA 4.5/5.0 use a private backend managed behind Nginx reverse proxy.</small>
                        </div>

                        <div class="p-3 my-4" style="background: rgba(99, 102, 241, 0.03); border: 1px solid rgba(99, 102, 241, 0.12); border-radius: 14px;">
                            <h5 class="mb-3 font-w700" style="font-size: 0.875rem;"><i class="fas fa-random mr-1 text-purple"></i> PROXY & WEBMAIL CREDENTIALS</h5>
                            <div class="row">
                                <div class="col-sm-12 col-md-4">
                                    <div class="command-form-group">
                                        <label class="command-form-label" for="proxy_port">PROXY PORT</label>
                                        <input id="proxy_port" type="number" name="proxy_port" class="command-form-control" value="{{ old('proxy_port', $settings['proxy_port']) }}" required>
                                    </div>
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    <div class="command-form-group">
                                        <label class="command-form-label" for="proxy_username">PROXY USERNAME</label>
                                        <input id="proxy_username" name="proxy_username" class="command-form-control" placeholder="{{ $secretConfigured['proxy_username'] ? 'Saved; blank keeps current' : 'optional' }}" value="" autocomplete="off">
                                    </div>
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    <div class="command-form-group">
                                        <label class="command-form-label" for="proxy_password">PROXY PASSWORD</label>
                                        <input id="proxy_password" name="proxy_password" class="command-form-control" placeholder="{{ $secretConfigured['proxy_password'] ? 'Saved; blank keeps current' : 'optional' }}" value="" autocomplete="off">
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-2">
                                <div class="col-sm-12 col-md-6">
                                    <div class="command-form-group">
                                        <label class="command-form-label" for="webmail_username">WEBMAIL USERNAME</label>
                                        <input id="webmail_username" name="webmail_username" class="command-form-control" value="" autocomplete="off" placeholder="{{ $secretConfigured['webmail_username'] ? 'Saved; leave blank to keep' : '' }}" aria-describedby="webmail-username-help">
                                        <small id="webmail-username-help" class="text-muted mt-1 d-block" style="font-size: 0.75rem;">{{ $secretConfigured['webmail_username'] ? 'Saved encrypted. Enter a new value to replace it.' : 'Required only when IMAP is selected.' }}</small>
                                    </div>
                                </div>
                                <div class="col-sm-12 col-md-6">
                                    <div class="command-form-group">
                                        <label class="command-form-label" for="webmail_password">WEBMAIL PASSWORD</label>
                                        <input id="webmail_password" name="webmail_password" class="command-form-control" value="" autocomplete="off" placeholder="{{ $secretConfigured['webmail_password'] ? 'Saved; leave blank to keep' : '' }}" aria-describedby="webmail-password-help">
                                        <small id="webmail-password-help" class="text-muted mt-1 d-block" style="font-size: 0.75rem;">{{ $secretConfigured['webmail_password'] ? 'Saved encrypted. Enter a new value to replace it.' : 'Required only when IMAP is selected.' }}</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="command-modal-actions mt-4 justify-content-end">
                            <button type="submit" class="btn-command-primary">
                                <i class="fas fa-save mr-1"></i> SAVE INSTALLATION SETTINGS
                            </button>
                        </div>
                    </form>
                </div>

                <div class="tab-pane" id="pmta_domains" role="tabpanel">
                    <div class="d-flex align-items-center mb-3" style="gap: 12px;">
                        <div class="command-select-wrapper" style="width: 160px;">
                            <select id="pmta_version" class="command-form-control command-select">
                                <option value="40" selected>PMTA 4.0</option>
                                <option value="45">PMTA 4.5</option>
                                <option value="50">PMTA 5.0</option>
                            </select>
                        </div>
                        <button id="load_pmta_domain_data" class="btn-command-action" type="button">
                            <i class="fas fa-download mr-1"></i> LOAD DIRECTIVES
                        </button>
                    </div>

                    <div class="command-form-group">
                        <label class="command-form-label" for="pmta_domain_data">
                            <i class="fas fa-file-code mr-1 text-purple"></i> PMTA DOMAINS CONFIGURATION DIRECTIVES
                        </label>
                        <textarea id="pmta_domain_data" class="command-form-control" rows="24" style="font-family: 'JetBrains Mono', 'Fira Code', monospace; font-size: 0.8125rem; background: #0f172a; color: #38bdf8; border-color: rgba(99, 102, 241, 0.3);"></textarea>
                    </div>

                    <div class="command-modal-actions mt-3 justify-content-end">
                        <button id="save_pmta_domain_data" class="btn-command-primary" type="button">
                            <i class="fas fa-save mr-1"></i> SAVE DIRECTIVES
                        </button>
                    </div>
                    <div id="pmta-domain-status" class="alert d-none mt-3" role="status" aria-live="polite" style="border-radius: 12px; font-size: 0.8125rem;"></div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, #6366f1, #8b5cf6) !important;
            color: #ffffff !important;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3) !important;
        }
    </style>
@endpush

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
