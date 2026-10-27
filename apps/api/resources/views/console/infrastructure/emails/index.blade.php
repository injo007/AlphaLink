@extends('layouts.dms-admin')

@section('title', 'Search Emails - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Search Emails</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <form id="email-search-form" novalidate>
                <div class="row push">
                    <div class="col-sm-12 col-md-6 offset-md-3">
                        <fieldset class="form-group mb-3">
                            <legend class="font-size-sm font-w600">Search Type</legend>
                            <div class="d-flex flex-wrap" role="radiogroup" aria-label="Search type">
                                @foreach (['id' => 'ID', 'email' => 'EMAIL', 'hash' => 'HASH', 'token' => 'TOKEN'] as $value => $label)
                                    <div class="custom-control custom-radio custom-control-inline">
                                        <input class="custom-control-input" id="email-search-{{ $value }}" type="radio" name="type" value="{{ $value }}" @checked($value === 'id')>
                                        <label class="custom-control-label" for="email-search-{{ $value }}">{{ $label }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>
                        <div class="form-group">
                            <label for="email-search-data">Data, one value per line</label>
                            <textarea id="email-search-data" class="form-control" rows="5" aria-describedby="email-search-help" autocomplete="off" spellcheck="false"></textarea>
                            <small id="email-search-help" class="form-text text-muted">Search uses workspace contact IDs, normalized addresses, saved keyed hashes, or exact tracking tokens with recorded events. Values are kept out of the URL.</small>
                        </div>
                        <div class="d-flex flex-wrap align-items-center">
                            <button id="email-search-submit" type="submit" class="btn btn-alt-primary mr-2">SEARCH</button>
                            <button id="email-search-clear" type="button" class="btn btn-alt-secondary" hidden>CLEAR</button>
                        </div>
                    </div>
                </div>
            </form>

            <div id="email-search-status" class="alert alert-info" role="status" aria-live="polite" hidden></div>

            <section aria-labelledby="email-results-title">
                <div class="d-flex flex-wrap align-items-center justify-content-between mb-2">
                    <h4 id="email-results-title" class="h5 mb-2">Results <span id="email-results-count" class="text-muted font-size-sm"></span></h4>
                    <div id="email-results-actions" class="email-results-actions align-items-center mb-2" hidden>
                        <button type="button" class="btn btn-sm btn-alt-secondary mr-1 mb-1" id="email-select-all">SELECT ALL</button>
                        <button type="button" class="btn btn-sm btn-alt-secondary mr-2 mb-1" id="email-select-none">SELECT NONE</button>
                        <button type="button" class="btn btn-sm btn-alt-warning mr-1 mb-1" data-email-action="proxy" disabled>MOVE TO PROXY</button>
                        <button type="button" class="btn btn-sm btn-alt-warning mr-1 mb-1" data-email-action="bot" disabled>MOVE TO BOT</button>
                        <button type="button" class="btn btn-sm btn-alt-danger mr-1 mb-1" data-email-action="blacklist" disabled>MOVE TO BLACKLIST</button>
                        <button type="button" class="btn btn-sm btn-alt-danger mr-1 mb-1" data-email-action="hard_bounce" disabled>MOVE TO HARD BOUNCE</button>
                        <button type="button" class="btn btn-sm btn-alt-success mb-1" data-email-action="restore" disabled>RESTORE</button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table id="listEmails" class="table table-sm table-hover table-striped w-100" aria-label="Email search results"></table>
                </div>
            </section>
        </div>
    </div>

    <dialog id="email-action-confirmation" aria-labelledby="email-action-confirmation-title" aria-describedby="email-action-confirmation-description" style="border:1px solid #d5d9df;border-radius:.35rem;max-width:32rem;padding:1.25rem;width:calc(100% - 2rem);box-shadow:0 1rem 3rem rgba(0,0,0,.25)">
        <h3 id="email-action-confirmation-title" class="h5">Confirm email type change</h3>
        <p id="email-action-confirmation-description" class="text-muted"></p>
        <div class="text-right">
            <button type="button" class="btn btn-alt-secondary mr-2" id="email-action-cancel">Cancel</button>
            <button type="button" class="btn btn-primary" id="email-action-confirm">Continue</button>
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
                    return `<span class="badge badge-${colors[label] || 'secondary'} p-1">${escapeHtml(label)}</span>`;
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
                    return `<a href="${escapeHtml(href)}" target="_blank" rel="noopener">${escapeHtml(value || '')}</a>`;
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
                    return `<a href="${escapeHtml(href)}" target="_blank" rel="noopener" aria-label="View ${Number(value || 0)} logs">${Number(value || 0)}</a>`;
                }},
            ];

            if ($.fn.DataTable) {
                table = $('#listEmails').DataTable({
                    data: [], columns, order:[[1,'asc']], paging:false, searching:false, info:false,
                    language:{emptyTable:'Enter one or more values above, then search.'},
                    drawCallback: () => {
                        document.querySelectorAll('.email-result-select').forEach((checkbox) => { checkbox.checked = selected.has(checkbox.value); });
                        updateActionButtons();
                    },
                });
            }

            const loadResults = async (search) => {
                submitButton.disabled = true;
                submitButton.textContent = 'SEARCHING…';
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
                    submitButton.textContent = 'SEARCH';
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
