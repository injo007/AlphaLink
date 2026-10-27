<div class="tab-pane" id="pmta_domains" role="tabpanel" aria-labelledby="pmta_domains_tab">
    <div class="form-group">
        <div class="input-group">
            <select id="pmta_version" class="form-control" aria-label="PowerMTA version">
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
        <textarea id="pmta_domain_data" class="form-control" rows="50" aria-describedby="pmta-domain-status"></textarea>
    </div>
    <div class="form-group text-md-right">
        <button id="save_pmta_domain_data" class="btn btn-primary" type="button">SAVE SETTINGS</button>
    </div>
    <div id="pmta-domain-status" class="alert d-none" role="status" aria-live="polite"></div>
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
