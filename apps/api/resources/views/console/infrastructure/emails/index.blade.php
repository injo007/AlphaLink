@extends('layouts.admin-shell')

@section('title', 'Search Emails')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Header Card -->
        <header class="command-os-report-hero" aria-labelledby="email-search-hero-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true">
                <i class="fas fa-search-location"></i>
            </span>
            <div class="command-os-report-hero-mark" aria-hidden="true">
                <i class="fas fa-search-location"></i>
            </div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">IDENTITY RESOLUTION // RECIPIENT TELEMETRY</p>
                <h1 id="email-search-hero-title">SEARCH EMAILS</h1>
                <p>Cross-audience identity lookup, tracking token resolution, and member classification.</p>
            </div>
            <div class="command-os-hero-actions">
                <div class="command-os-report-hero-range">
                    <span>RESOLUTION SCOPE</span>
                    <strong><i class="fas fa-circle text-success" style="font-size: 8px;"></i> MULTI-INDEX</strong>
                </div>
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="fas fa-expand"></i>
                </button>
            </div>
        </header>

        <!-- 2. KPI Overview Cards -->
        <section class="command-os-all-stats-overview" aria-label="Search Emails Overview">
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-search"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Search Engine</span>
                    <strong>WORKSPACE</strong>
                    <small>Unified registry</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-blue" aria-hidden="true"><i class="fas fa-filter"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Query Modes</span>
                    <strong>4 MODES</strong>
                    <small>ID, Email, Hash, Token</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-emerald" aria-hidden="true"><i class="fas fa-user-shield"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Privacy Guard</span>
                    <strong>SECURE</strong>
                    <small>URL sanitized query</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-cyan" aria-hidden="true"><i class="fas fa-database"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Index Scope</span>
                    <strong>OPTIMIZED</strong>
                    <small>Multi-partition lookup</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-amber" aria-hidden="true"><i class="fas fa-layer-group"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Reclassification</span>
                    <strong>SUPPORTED</strong>
                    <small>Batch status change</small>
                </span>
            </article>
        </section>

        <!-- 3. Search Filter Card -->
        <section class="command-os-filter-card mb-3" aria-label="Search parameters">
            <form id="email-search-form" novalidate>
                <div class="row">
                    <div class="col-12 col-lg-8 offset-lg-2">
                        <!-- Search Type Pill Switcher -->
                        <div class="mb-3">
                            <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-2">
                                <i class="fas fa-filter mr-1 text-purple"></i> Search Type:
                            </label>
                            <div class="command-segmented-pills d-flex flex-wrap" role="radiogroup" aria-label="Search type">
                                @foreach (['id' => 'ID', 'email' => 'EMAIL', 'hash' => 'HASH', 'token' => 'TOKEN'] as $value => $label)
                                    <label class="command-segment-pill mb-1 mr-2" for="email-search-{{ $value }}">
                                        <input class="d-none" id="email-search-{{ $value }}" type="radio" name="type" value="{{ $value }}" @checked($value === 'id')>
                                        <span class="command-segment-btn">
                                            @if($value === 'id') <i class="fas fa-hashtag mr-1"></i>
                                            @elseif($value === 'email') <i class="fas fa-envelope mr-1"></i>
                                            @elseif($value === 'hash') <i class="fas fa-fingerprint mr-1"></i>
                                            @elseif($value === 'token') <i class="fas fa-key mr-1"></i>
                                            @endif
                                            {{ $label }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <!-- Data Textarea -->
                        <div class="form-group mb-3">
                            <label for="email-search-data" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                                <i class="fas fa-align-left mr-1 text-purple"></i> Search Values (One per line):
                            </label>
                            <textarea id="email-search-data" class="form-control command-os-textarea" rows="4" placeholder="Enter contact IDs, addresses, hashes, or tracking tokens..." aria-describedby="email-search-help" autocomplete="off" spellcheck="false" style="font-family: var(--font-mono, monospace); font-size: 13px; line-height: 1.5; border-radius: 10px;"></textarea>
                            <div class="command-os-info-banner mt-2 py-2 px-3 font-size-xs" id="email-search-help">
                                <div class="command-os-info-banner-icon"><i class="fas fa-info-circle"></i></div>
                                <div class="command-os-info-banner-content text-muted">
                                    Search uses workspace contact IDs, normalized addresses, saved keyed hashes, or exact tracking tokens with recorded events. Values are kept securely out of the URL.
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex flex-wrap align-items-center justify-content-between pt-1">
                            <div class="d-flex align-items-center">
                                <button id="email-search-submit" type="submit" class="btn-command-primary px-4 mr-2" style="height: 42px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; font-weight: 750; font-size: 11.5px; letter-spacing: .06em;">
                                    <i class="fas fa-search mr-2"></i> SEARCH
                                </button>
                                <button id="email-search-clear" type="button" class="btn-command-action px-3" style="height: 42px; display: inline-flex; align-items: center; border-radius: 10px; font-weight: 700; font-size: 11.5px;" hidden>
                                    <i class="fas fa-times mr-1"></i> CLEAR
                                </button>
                            </div>
                            <div class="font-size-xs text-muted d-none d-sm-block">
                                <kbd style="background: rgba(139, 92, 246, 0.15); color: #8b5cf6; padding: 3px 6px; border-radius: 4px;">Ctrl + Enter</kbd> to search
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </section>

        <!-- Status Message Banner -->
        <div id="email-search-status" class="alert alert-info py-2 px-3 font-size-sm" role="status" aria-live="polite" hidden style="border-radius: 10px;"></div>

        <!-- 4. Results Section Card -->
        <div class="command-os-table-card">
            <section aria-labelledby="email-results-title">
                <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 pb-2 border-bottom">
                    <div class="d-flex align-items-center">
                        <h4 id="email-results-title" class="font-w700 text-uppercase font-size-sm mb-0">
                            <i class="fas fa-database mr-2 text-purple"></i> Search Results
                        </h4>
                        <span id="email-results-count" class="badge badge-light border text-purple font-w700 ml-2"></span>
                    </div>

                    <!-- Batch Action Buttons -->
                    <div id="email-results-actions" class="email-results-actions flex-wrap align-items-center gap-2 mb-0" hidden>
                        <div class="dt-buttons d-inline-flex flex-wrap gap-2 mr-2 mb-1">
                            <button type="button" class="btn btn-command-action" id="email-select-all">
                                <i class="far fa-check-square mr-1"></i> ALL
                            </button>
                            <button type="button" class="btn btn-command-action" id="email-select-none">
                                <i class="far fa-square mr-1"></i> NONE
                            </button>
                        </div>
                        <div class="dt-buttons d-inline-flex flex-wrap gap-2 mb-1">
                            <button type="button" class="btn btn-command-action" data-email-action="proxy" disabled>
                                <i class="fas fa-user-secret mr-1 text-warning"></i> PROXY
                            </button>
                            <button type="button" class="btn btn-command-action" data-email-action="bot" disabled>
                                <i class="fas fa-robot mr-1 text-warning"></i> BOT
                            </button>
                            <button type="button" class="btn btn-command-action" data-email-action="blacklist" disabled>
                                <i class="fas fa-ban mr-1 text-danger"></i> BLACKLIST
                            </button>
                            <button type="button" class="btn btn-command-action" data-email-action="hard_bounce" disabled>
                                <i class="fas fa-times-circle mr-1 text-danger"></i> HARD BOUNCE
                            </button>
                            <button type="button" class="btn btn-command-action" data-email-action="restore" disabled>
                                <i class="fas fa-undo mr-1 text-success"></i> RESTORE
                            </button>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="listEmails" class="table command-os-table table-sm table-hover w-100" aria-label="Email search results"></table>
                </div>
            </section>
        </div>
    </div>

    <!-- Confirmation Modal Dialog -->
    <dialog id="email-action-confirmation" aria-labelledby="email-action-confirmation-title" aria-describedby="email-action-confirmation-description" class="command-os-dialog">
        <div class="command-modal-surface p-4" style="max-width: 520px; border-radius: 14px;">
            <div class="d-flex align-items-center mb-3">
                <div class="command-dialog-icon mr-3" style="font-size: 24px; color: #f59e0b;">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3 id="email-action-confirmation-title" class="font-w700 font-size-base mb-0">Confirm Audience Reclassification</h3>
            </div>
            <p id="email-action-confirmation-description" class="text-muted font-size-sm mb-4"></p>
            <div class="command-modal-actions">
                <button type="button" class="btn-command-action" id="email-action-cancel">CANCEL</button>
                <button type="button" class="btn-command-primary" id="email-action-confirm">CONTINUE</button>
            </div>
        </div>
    </dialog>
@endsection

@push('scripts')
    <script>
        window.jQuery(function () {
            const $ = window.jQuery;
            const form = document.getElementById('email-search-form');
            const searchField = document.getElementById('email-search-data');
            const submitButton = document.getElementById('email-search-submit');
            const clearButton = document.getElementById('email-search-clear');
            const status = document.getElementById('email-search-status');
            const resultActions = document.getElementById('email-results-actions');
            const resultCount = document.getElementById('email-results-count');
            const actionDialog = document.getElementById('email-action-confirmation');
            const selected = new Set();
            const baseSearchUrl = @json(route('console.infrastructure.emails.search', $workspace->id));
            const actionUrl = @json(route('console.infrastructure.emails.action', $workspace->id));
            const logsUrl = @json(route('console.infrastructure.emails.logs', [$workspace->id, '__CONTACT__']));
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            let lastSearch = null;
            let pendingAction = null;
            let table = null;

            const escapeHtml = (value) => $('<div>').text(value == null ? '' : String(value)).html();
            const announce = (message, tone = 'info') => {
                status.textContent = message;
                status.classList.remove('alert-info', 'alert-success', 'alert-warning', 'alert-danger');
                status.classList.add(`alert-${tone}`);
                status.hidden = false;
                window.AlphaLinkToast?.show(message, tone === 'danger' ? 'danger' : tone);
            };
            const currentSelection = () => [...selected];
            const updateActionButtons = () => {
                const hasSelection = selected.size > 0;
                document.querySelectorAll('[data-email-action]').forEach((button) => { button.disabled = !hasSelection; });
            };
            const selectionColumn = {
                title: '<input type="checkbox" id="email-select-visible" aria-label="Select all results">',
                data: 'id', orderable: false, searchable: false,
                render: (id, type) => type === 'display'
                    ? `<input type="checkbox" class="email-result-select" value="${escapeHtml(id)}" aria-label="Select contact ${escapeHtml(id)}" ${selected.has(String(id)) ? 'checked' : ''}>`
                    : id,
            };
            const typeColumn = {
                title: 'type', data: 'type',
                render: (value, type) => {
                    if (type !== 'display') return value || '';
                    const colors = {fresh:'secondary',delivery:'info',open:'primary',click:'primary',lead:'success',unsubscribe:'warning',opt_out:'warning',proxy:'danger',bot:'danger',blacklist:'danger',hard_bounce:'danger'};
                    const label = value || 'unassigned';
                    return `<span class="badge badge-${colors[label] || 'secondary'} px-2 py-1 font-w700 font-size-xs text-uppercase" style="letter-spacing:0.04em;">${escapeHtml(label)}</span>`;
                },
            };
            const textColumn = (title, data) => ({title, data, render: (value, type) => type === 'display' ? escapeHtml(value || '') : (value || '')});
            const columns = [
                selectionColumn,
                textColumn('#', 'id'),
                textColumn('isp', 'isp'),
                textColumn('list', 'list'),
                {title:'email',data:'email',render:(value,type,row)=>{
                    if(type!=='display') return value || '';
                    const href=logsUrl.replace('__CONTACT__',encodeURIComponent(row.id));
                    return `<a href="${escapeHtml(href)}" target="_blank" rel="noopener" class="font-w600 text-purple">${escapeHtml(value || '')}</a>`;
                }},
                typeColumn,
                textColumn('first', 'first_name'),
                textColumn('last', 'last_name'),
                textColumn('country', 'country'),
                textColumn('age', 'age'),
                textColumn('sex', 'sex'),
                textColumn('ver', 'vertical'),
                {title:'l',data:'logs_count',render:(value,type,row)=>{
                    if(type!=='display') return Number(value || 0);
                    const href=logsUrl.replace('__CONTACT__',encodeURIComponent(row.id));
                    return `<a href="${escapeHtml(href)}" target="_blank" rel="noopener" class="badge badge-light border text-purple font-w700 px-2 py-1" aria-label="View ${Number(value || 0)} logs"><i class="fas fa-file-alt mr-1"></i>${Number(value || 0)}</a>`;
                }},
            ];

            if ($.fn.DataTable) {
                table = $('#listEmails').DataTable({
                    data: [], columns, order:[[1,'asc']], paging:false, searching:false, info:false,
                    language:{emptyTable:'<div class="py-4 text-center text-muted"><i class="fas fa-inbox fa-2x mb-2 d-block text-purple opacity-50"></i>Enter one or more values above, then search.</div>'},
                    drawCallback: () => {
                        document.querySelectorAll('.email-result-select').forEach((checkbox) => { checkbox.checked = selected.has(checkbox.value); });
                        updateActionButtons();
                    },
                });
            }

            const loadResults = async (search) => {
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> SEARCHING…';
                try {
                    const response = await fetch(baseSearchUrl, {
                        method:'POST',
                        headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},
                        body:JSON.stringify(search),
                    });
                    const payload = await response.json();
                    if (!response.ok) throw new Error(payload.message || 'Search failed. Check the selected type and try again.');
                    lastSearch = search;
                    selected.clear();
                    const rows = Array.isArray(payload.data) ? payload.data : [];
                    if (table) table.clear().rows.add(rows).draw();
                    resultActions.hidden = rows.length === 0;
                    resultActions.classList.toggle('d-flex', rows.length > 0);
                    resultCount.textContent = `(${rows.length} ${rows.length === 1 ? 'record' : 'records'})`;
                    announce(rows.length ? `Found ${rows.length} records.` : 'No matching records were found.', rows.length ? 'success' : 'info');
                } catch (error) {
                    announce(error.message || 'Search failed. Try again.', 'danger');
                } finally {
                    submitButton.disabled = false;
                    submitButton.innerHTML = '<i class="fas fa-search mr-1"></i> SEARCH';
                    updateActionButtons();
                }
            };

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                const values = [...new Set(searchField.value.split(/\r?\n/).map((value) => value.trim()).filter(Boolean))];
                if (!values.length) {
                    searchField.setAttribute('aria-invalid', 'true');
                    searchField.focus();
                    announce('Enter one or more values to search.', 'warning');
                    return;
                }
                searchField.removeAttribute('aria-invalid');
                loadResults({type:form.querySelector('input[name="type"]:checked').value,data:values});
            });

            searchField.addEventListener('input', () => {
                clearButton.hidden = searchField.value.length === 0;
                searchField.removeAttribute('aria-invalid');
            });
            searchField.addEventListener('keydown', (event) => {
                if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                    event.preventDefault();
                    form.requestSubmit();
                }
            });
            clearButton.addEventListener('click', () => {
                searchField.value = '';
                clearButton.hidden = true;
                selected.clear();
                if (table) table.clear().draw();
                resultActions.hidden = true;
                resultActions.classList.remove('d-flex');
                resultCount.textContent = '';
                status.hidden = true;
                searchField.focus();
            });
            document.getElementById('email-select-all').addEventListener('click', () => {
                (table?.rows().data().toArray() || []).forEach((row) => selected.add(String(row.id)));
                table?.draw(false);
            });
            document.getElementById('email-select-none').addEventListener('click', () => {
                selected.clear();
                table?.draw(false);
            });
            document.getElementById('listEmails').addEventListener('change', (event) => {
                if (event.target.matches('.email-result-select')) {
                    if (event.target.checked) selected.add(event.target.value);
                    else selected.delete(event.target.value);
                    updateActionButtons();
                }
                if (event.target.matches('#email-select-visible')) {
                    if (event.target.checked) (table?.rows().data().toArray() || []).forEach((row) => selected.add(String(row.id)));
                    else selected.clear();
                    table?.draw(false);
                }
            });

            document.querySelectorAll('[data-email-action]').forEach((button) => button.addEventListener('click', () => {
                if (!selected.size) {
                    announce('Select at least one result first.', 'warning');
                    return;
                }
                pendingAction = button.dataset.emailAction;
                const label = button.textContent.trim();
                document.getElementById('email-action-confirmation-description').textContent = `${label} for ${selected.size} selected ${selected.size === 1 ? 'record' : 'records'}? This changes the saved audience type and records the action in the workspace audit trail.`;
                actionDialog.showModal();
            }));

            document.getElementById('email-action-cancel').addEventListener('click', () => actionDialog.close());
            document.getElementById('email-action-confirm').addEventListener('click', async () => {
                const confirmButton = document.getElementById('email-action-confirm');
                confirmButton.disabled = true;
                const action = pendingAction;
                try {
                    const response = await fetch(actionUrl, {
                        method:'PATCH',
                        headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},
                        body:JSON.stringify({ids:currentSelection(),action}),
                    });
                    const payload = await response.json();
                    if (!response.ok) throw new Error(payload.message || 'The selected audience types could not be changed.');
                    actionDialog.close();
                    announce(payload.data || 'Email types updated.', 'success');
                    if (lastSearch) await loadResults(lastSearch);
                } catch (error) {
                    actionDialog.close();
                    announce(error.message || 'The selected audience types could not be changed.', 'danger');
                } finally {
                    confirmButton.disabled = false;
                    pendingAction = null;
                }
            });
            actionDialog.addEventListener('close', () => { pendingAction = null; });
        });
    </script>
@endpush
