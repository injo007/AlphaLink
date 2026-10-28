@extends('layouts.dms-admin')

@section('title', 'Blacklist Checker - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Blacklist Checker</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <form id="blacklist-check-form" enctype="multipart/form-data">
                <div class="row push">
                    <div class="col-sm-12 col-lg-5">
                        <div class="form-group">
                            <label for="blacklist-file">File (.txt or .csv)</label>
                            <input class="form-control" id="blacklist-file" name="file" type="file" accept=".txt,.csv" required>
                            <small class="form-text text-muted">Use one address per line, or put the address in the first CSV column. The file is private and is deleted after processing.</small>
                        </div>
                    </div>
                    <div class="col-sm-12 col-lg-3">
                        <div class="form-group">
                            <label for="blacklist-email-type">Move matching memberships to</label>
                            <select class="form-control" id="blacklist-email-type" name="email_type" required>
                                @foreach ($emailTypes as $type)
                                    <option value="{{ $type }}">{{ strtoupper(str_replace('_', ' ', $type)) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-sm-12 col-lg-2 d-flex align-items-end pb-3">
                        <button class="btn btn-alt-primary" id="blacklist-submit" type="submit">UPLOAD AND CHECK</button>
                    </div>
                </div>
            </form>
            <div class="alert alert-info" role="note">This AlphaLink workflow currently accepts email-address files. DMS files whose first value is a 32-character hash are rejected without changing records.</div>
            <div id="blacklist-status" class="alert alert-info" role="status" aria-live="polite" hidden></div>
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h4 class="h5 mb-0">Processes</h4>
                <button class="btn btn-sm btn-alt-secondary" id="blacklist-refresh" type="button">REFRESH</button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover table-striped" aria-label="Blacklist checker processes">
                    <thead>
                        <tr>
                            <th>USER</th><th>FILE</th><th>TYPE</th><th>STATUS</th>
                            <th>PROCESSED</th><th>MATCHED</th><th>UPDATED</th><th>INVALID</th><th>ACTION</th>
                        </tr>
                    </thead>
                    <tbody id="blacklist-process-rows">
                        <tr><td colspan="9" class="text-muted">Loading processes…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.jQuery(function () {
            const form = document.getElementById('blacklist-check-form');
            const fileInput = document.getElementById('blacklist-file');
            const submitButton = document.getElementById('blacklist-submit');
            const status = document.getElementById('blacklist-status');
            const rows = document.getElementById('blacklist-process-rows');
            const listUrl = @json(route('clean.console.infrastructure.blacklist-checker'));
            const uploadUrl = @json(route('clean.console.infrastructure.blacklist-checker.upload'));
            const cancelUrl = @json(route('clean.console.infrastructure.blacklist-checker.cancel', '__PROCESS__'));
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            let refreshTimer = null;

            const escapeHtml = (value) => window.jQuery('<div>').text(value == null ? '' : String(value)).html();
            const announce = (message, tone = 'info') => {
                status.textContent = message;
                status.className = `alert alert-${tone}`;
                status.hidden = false;
            };

            const render = (items) => {
                if (!items.length) {
                    rows.innerHTML = '<tr><td colspan="9" class="text-muted">No blacklist processes.</td></tr>';
                    return;
                }
                rows.innerHTML = items.map((item) => {
                    const action = item.can_cancel
                        ? `<button type="button" class="btn btn-sm btn-alt-danger" data-cancel-process="${escapeHtml(item.id)}">STOP</button>`
                        : '—';
                    const warning = item.failure_message ? `<div class="text-danger small">${escapeHtml(item.failure_message)}</div>` : '';
                    return `<tr><td>${escapeHtml(item.user)}</td><td>${escapeHtml(item.file)}${warning}</td><td>${escapeHtml(item.type)}</td><td>${escapeHtml(item.status)}</td><td>${Number(item.processed_count || 0).toLocaleString()}</td><td>${Number(item.matched_count || 0).toLocaleString()}</td><td>${Number(item.updated_count || 0).toLocaleString()}</td><td>${Number(item.invalid_count || 0).toLocaleString()}</td><td>${action}</td></tr>`;
                }).join('');
            };

            const refresh = async () => {
                try {
                    const response = await fetch(`${listUrl}?json=1`, {headers: {Accept: 'application/json'}});
                    const payload = await response.json();
                    if (!response.ok) throw new Error(payload.message || 'Unable to load processes.');
                    render(payload.data || []);
                } catch (error) {
                    rows.innerHTML = `<tr><td colspan="9" class="text-danger">${escapeHtml(error.message)}</td></tr>`;
                }
            };

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (!fileInput.files.length) return;
                submitButton.disabled = true;
                try {
                    const response = await fetch(uploadUrl, {
                        method: 'POST',
                        headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'},
                        body: new FormData(form),
                    });
                    const payload = await response.json();
                    if (!response.ok) {
                        const firstError = Object.values(payload.errors || {}).flat()[0];
                        throw new Error(firstError || payload.message || 'The blacklist file could not be queued.');
                    }
                    form.reset();
                    announce(payload.message || 'Blacklist check queued.', 'success');
                    await refresh();
                } catch (error) {
                    announce(error.message, 'danger');
                } finally {
                    submitButton.disabled = false;
                }
            });

            rows.addEventListener('click', async (event) => {
                const button = event.target.closest('[data-cancel-process]');
                if (!button) return;
                button.disabled = true;
                try {
                    const url = cancelUrl.replace('__PROCESS__', encodeURIComponent(button.dataset.cancelProcess));
                    const response = await fetch(url, {method: 'PATCH', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
                    const payload = await response.json();
                    if (!response.ok) throw new Error(payload.message || 'Unable to stop this process.');
                    announce(payload.message, 'warning');
                    await refresh();
                } catch (error) {
                    announce(error.message, 'danger');
                    button.disabled = false;
                }
            });

            document.getElementById('blacklist-refresh').addEventListener('click', refresh);
            refresh();
            refreshTimer = window.setInterval(() => {
                if (document.visibilityState === 'visible') refresh();
            }, 5000);
            window.addEventListener('beforeunload', () => window.clearInterval(refreshTimer), {once: true});
        });
    </script>
@endpush
