@extends('layouts.admin-shell')

@section('title', 'Blacklist Checker')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Header Card -->
        <header class="command-os-hero-card">
            <div class="command-os-hero-card-left">
                <div class="command-os-hero-mark" aria-hidden="true">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div class="command-os-hero-copy">
                    <h1 class="command-os-hero-title">BLACKLIST CHECKER</h1>
                    <p class="command-os-hero-subtitle">Bulk recipient suppression verification, membership reclassification, and hygiene audit</p>
                </div>
            </div>
            <div class="command-os-hero-card-right d-flex align-items-center">
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="si si-size-fullscreen"></i>
                </button>
            </div>
        </header>

        <!-- 2. Upload / Configuration Filter Card -->
        <section class="command-os-filter-card" aria-label="Blacklist upload form">
            <form id="blacklist-check-form" enctype="multipart/form-data">
                <div class="form-row align-items-end">
                    <div class="col-sm-12 col-lg-5 mb-3 mb-lg-0">
                        <label for="blacklist-file" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                            <i class="fas fa-file-upload mr-1 text-purple"></i> Upload Suppression File (.txt, .csv):
                        </label>
                        <div class="command-input-wrap">
                            <input class="form-control" id="blacklist-file" name="file" type="file" accept=".txt,.csv" required style="height: 42px; padding: 7px 12px; font-size: 12px;">
                        </div>
                    </div>

                    <div class="col-sm-12 col-lg-4 mb-3 mb-lg-0">
                        <label for="blacklist-email-type" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                            <i class="fas fa-exchange-alt mr-1 text-purple"></i> Move Matching Memberships To:
                        </label>
                        <div class="command-input-wrap">
                            <select class="form-control" id="blacklist-email-type" name="email_type" required style="height: 42px;">
                                @foreach ($emailTypes as $type)
                                    <option value="{{ $type }}">{{ strtoupper(str_replace('_', ' ', $type)) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="col-sm-12 col-lg-3">
                        <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1 d-none d-lg-block" style="visibility: hidden;">&nbsp;</label>
                        <button class="command-os-btn-filter w-100" id="blacklist-submit" type="submit" style="height: 42px;">
                            <i class="fas fa-play-circle mr-1"></i> UPLOAD &amp; CHECK
                        </button>
                    </div>
                </div>
            </form>

            <div class="command-os-info-banner mt-3 py-2 px-3 font-size-xs" role="note">
                <div class="command-os-info-banner-icon"><i class="fas fa-info-circle"></i></div>
                <div class="command-os-info-banner-content text-muted">
                    This AlphaLink workflow accepts email-address files. Use one address per line, or put the address in the first CSV column. Files whose first value is a 32-character hash are rejected without changing records. File is deleted after processing.
                </div>
            </div>
        </section>

        <!-- Status Message Banner -->
        <div id="blacklist-status" class="alert alert-info py-2 px-3 font-size-sm" role="status" aria-live="polite" hidden style="border-radius: 10px;"></div>

        <!-- 3. Processes Table Card -->
        <div class="command-os-table-card">
            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                <h4 class="font-w700 text-uppercase font-size-sm mb-0">
                    <i class="fas fa-tasks mr-2 text-purple"></i> Active &amp; Historical Processes
                </h4>
                <button class="btn btn-sm btn-outline-secondary font-w700 font-size-xs px-3" id="blacklist-refresh" type="button" style="border-radius: 8px;">
                    <i class="fas fa-sync-alt mr-1"></i> REFRESH
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover table-striped w-100" aria-label="Blacklist checker processes">
                    <thead>
                        <tr>
                            <th>USER</th>
                            <th>FILE</th>
                            <th>TYPE</th>
                            <th>STATUS</th>
                            <th class="text-right">PROCESSED</th>
                            <th class="text-right">MATCHED</th>
                            <th class="text-right">UPDATED</th>
                            <th class="text-right">INVALID</th>
                            <th class="text-center">ACTION</th>
                        </tr>
                    </thead>
                    <tbody id="blacklist-process-rows">
                        <tr><td colspan="9" class="text-muted text-center py-4">Loading processes…</td></tr>
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
                status.className = `alert alert-${tone} py-2 px-3 font-size-sm`;
                status.hidden = false;
                window.AlphaLinkToast?.show(message, tone === 'danger' ? 'danger' : tone);
            };

            const statusBadge = (s) => {
                const text = String(s || '').toUpperCase();
                if (text === 'COMPLETED' || text === 'DONE' || text === 'FINISHED') {
                    return `<span class="badge badge-success px-2 py-1 font-w700 font-size-xs"><i class="fas fa-check-circle mr-1"></i>${escapeHtml(text)}</span>`;
                }
                if (text === 'RUNNING' || text === 'PROCESSING' || text === 'QUEUED') {
                    return `<span class="badge badge-warning px-2 py-1 font-w700 font-size-xs"><i class="fas fa-spinner fa-spin mr-1"></i>${escapeHtml(text)}</span>`;
                }
                if (text === 'FAILED' || text === 'ERROR') {
                    return `<span class="badge badge-danger px-2 py-1 font-w700 font-size-xs"><i class="fas fa-times-circle mr-1"></i>${escapeHtml(text)}</span>`;
                }
                return `<span class="badge badge-secondary px-2 py-1 font-w700 font-size-xs">${escapeHtml(text || 'PENDING')}</span>`;
            };

            const render = (items) => {
                if (!items.length) {
                    rows.innerHTML = '<tr><td colspan="9" class="text-muted text-center py-5"><i class="fas fa-inbox fa-2x mb-2 d-block text-purple opacity-50"></i>No active or historical blacklist processes recorded.</td></tr>';
                    return;
                }
                rows.innerHTML = items.map((item) => {
                    const action = item.can_cancel
                        ? `<div class="command-action-btn-group justify-content-center"><button type="button" class="command-action-btn action-danger" data-cancel-process="${escapeHtml(item.id)}" title="Stop process" aria-label="Stop process"><i class="fas fa-stop"></i></button></div>`
                        : '<span class="text-muted font-size-xs">—</span>';
                    const warning = item.failure_message ? `<div class="text-danger small mt-1"><i class="fas fa-exclamation-triangle mr-1"></i>${escapeHtml(item.failure_message)}</div>` : '';
                    return `<tr>
                        <td class="font-w600"><i class="far fa-user mr-1 text-muted"></i>${escapeHtml(item.user)}</td>
                        <td><span class="font-w600 text-purple">${escapeHtml(item.file)}</span>${warning}</td>
                        <td><span class="badge badge-light border text-purple px-2 py-1 font-w700 font-size-xs text-uppercase">${escapeHtml(item.type)}</span></td>
                        <td>${statusBadge(item.status)}</td>
                        <td class="text-right font-w600" style="font-family: var(--font-mono, monospace);">${Number(item.processed_count || 0).toLocaleString()}</td>
                        <td class="text-right font-w600 text-warning" style="font-family: var(--font-mono, monospace);">${Number(item.matched_count || 0).toLocaleString()}</td>
                        <td class="text-right font-w600 text-success" style="font-family: var(--font-mono, monospace);">${Number(item.updated_count || 0).toLocaleString()}</td>
                        <td class="text-right font-w600 text-danger" style="font-family: var(--font-mono, monospace);">${Number(item.invalid_count || 0).toLocaleString()}</td>
                        <td class="text-center">${action}</td>
                    </tr>`;
                }).join('');
            };

            const refresh = async () => {
                try {
                    const response = await fetch(`${listUrl}?json=1`, {headers: {Accept: 'application/json'}});
                    const payload = await response.json();
                    if (!response.ok) throw new Error(payload.message || 'Unable to load processes.');
                    render(payload.data || []);
                } catch (error) {
                    rows.innerHTML = `<tr><td colspan="9" class="text-danger text-center py-4"><i class="fas fa-exclamation-circle mr-1"></i>${escapeHtml(error.message)}</td></tr>`;
                }
            };

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (!fileInput.files.length) return;
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> UPLOADING…';
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
                    submitButton.innerHTML = '<i class="fas fa-play-circle mr-1"></i> UPLOAD &amp; CHECK';
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

            document.getElementById('blacklist-refresh').addEventListener('click', () => {
                const refreshBtn = document.getElementById('blacklist-refresh');
                refreshBtn.innerHTML = '<i class="fas fa-sync-alt fa-spin mr-1"></i> REFRESH';
                refresh().finally(() => {
                    setTimeout(() => { refreshBtn.innerHTML = '<i class="fas fa-sync-alt mr-1"></i> REFRESH'; }, 400);
                });
            });
            refresh();
            refreshTimer = window.setInterval(() => {
                if (document.visibilityState === 'visible') refresh();
            }, 5000);
            window.addEventListener('beforeunload', () => window.clearInterval(refreshTimer), {once: true});
        });
    </script>
@endpush
