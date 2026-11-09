<div class="tab-pane" id="pmta_domains" role="tabpanel" aria-labelledby="pmta_domains_tab">
    <div class="command-os-settings-card">
        <div class="command-os-settings-card-header">
            <div class="command-os-settings-card-title-group">
                <div class="command-os-settings-card-icon">
                    <i class="fas fa-terminal"></i>
                </div>
                <div class="command-os-settings-card-titles">
                    <h3>PMTA DOMAINS SETTINGS</h3>
                    <p>Virtual MTAs, envelope routing policies &amp; domain configuration profiles</p>
                </div>
            </div>
            <div class="command-os-settings-card-tag">ROUTING ENGINE</div>
        </div>
        <div class="command-os-settings-card-body">
            <div class="row align-items-center mb-4">
                <div class="col-sm-12 col-md-6 col-lg-4">
                    <div class="form-group command-os-field-group mb-0">
                        <label for="pmta_version">
                            <span><i class="fas fa-code-branch command-field-icon"></i> PowerMTA Target Version:</span>
                        </label>
                        <div class="command-input-wrap">
                            <span class="command-input-leading-icon"><i class="fas fa-layer-group"></i></span>
                            <select id="pmta_version" class="form-control command-os-custom-select" aria-label="PowerMTA version">
                                <option value="40" selected>PowerMTA 4.0 &mdash; Legacy Stable</option>
                                <option value="45">PowerMTA 4.5 &mdash; Fast Engine</option>
                                <option value="50">PowerMTA 5.0 &mdash; High Volume</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="col-sm-12 col-md-6 col-lg-3 mt-3 mt-md-4">
                    <button id="load_pmta_domain_data" class="btn btn-primary command-os-btn-save-settings w-100" type="button" style="padding: 10px 20px !important;">
                        <i class="fas fa-sync-alt mr-2"></i> LOAD SETTINGS
                    </button>
                </div>
            </div>

            <div class="command-os-terminal-card">
                <div class="command-os-terminal-bar">
                    <div class="command-os-terminal-dots">
                        <span class="command-os-terminal-dot is-red"></span>
                        <span class="command-os-terminal-dot is-yellow"></span>
                        <span class="command-os-terminal-dot is-green"></span>
                    </div>
                    <div class="command-os-terminal-title"><i class="fas fa-file-code mr-1"></i> pmta.conf // DOMAIN &amp; IP CONFIGURATION</div>
                    <div class="command-os-terminal-badge"><i class="fas fa-terminal mr-1"></i> PMTA-CONFIG</div>
                </div>
                <textarea id="pmta_domain_data" class="form-control" rows="35" aria-describedby="pmta-domain-status" placeholder="# Load PMTA settings above or paste domain directives here..."></textarea>
            </div>

            <div class="command-os-settings-footer-bar mt-4">
                <div class="command-os-footer-status">
                    <span class="command-os-pulse-dot"></span>
                    <span>Directives Editor Ready</span>
                </div>
                <button id="save_pmta_domain_data" class="btn btn-primary command-os-btn-save-settings" type="button">
                    <i class="fas fa-save mr-2"></i> SAVE SETTINGS
                </button>
            </div>
            <div id="pmta-domain-status" class="alert d-none mt-3" role="status" aria-live="polite"></div>
        </div>
    </div>
</div>

@push('scripts')
    <script>
        $(function () {
            const status = $('#pmta-domain-status');
            const loadEndpoint = @json(route('console.infrastructure.install-settings.domains.load', $workspace->id));
            const saveEndpoint = @json(route('console.infrastructure.install-settings.domains.update', $workspace->id));
            const csrf = @json(csrf_token());

            const showStatus = (message, success) => {
                status.removeClass('d-none alert-success alert-danger')
                    .addClass(success ? 'alert-success' : 'alert-danger')
                    .text(message);
            };

            $('#load_pmta_domain_data').on('click', async function () {
                const button = $(this);
                button.prop('disabled', true);
                status.addClass('d-none');
                try {
                    const url = new URL(loadEndpoint, window.location.origin);
                    url.searchParams.set('pmta_version', $('#pmta_version').val());
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    const result = await response.json();
                    if (!response.ok) {
                        throw new Error(result.message || 'Could not load PMTA settings.');
                    }
                    $('#pmta_domain_data').val(result.data || '');
                } catch (error) {
                    showStatus(error instanceof Error ? error.message : 'Could not load PMTA settings.', false);
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
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({
                            pmta_version: $('#pmta_version').val(),
                            data: $('#pmta_domain_data').val(),
                        }),
                    });
                    const result = await response.json();
                    if (!response.ok) {
                        throw new Error(result.message || 'Could not save PMTA settings.');
                    }
                    showStatus(result.data || 'Edited Successfully', true);
                } catch (error) {
                    showStatus(error instanceof Error ? error.message : 'Could not save PMTA settings.', false);
                } finally {
                    button.prop('disabled', false);
                }
            });
        });
    </script>
@endpush
