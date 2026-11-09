@extends('layouts.admin-shell')

@section('title', 'Lists')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Card -->
        <header class="command-os-hero-card">
            <div class="command-os-hero-card-left">
                <div class="command-os-hero-mark" aria-hidden="true">
                    <i class="fas fa-list-ul"></i>
                </div>
                <div class="command-os-hero-copy">
                    <h1 class="command-os-hero-title">LISTS</h1>
                    <p class="command-os-hero-subtitle">Audience &amp; Infrastructure list partitions, locks, tracking, and recipient inventory</p>
                </div>
            </div>
            <div class="command-os-hero-card-right d-flex align-items-center">
                <span class="badge badge-light border text-purple px-2 py-1 font-w700 mr-2" id="stat-lists-pill" style="display:none;">
                    <i class="fas fa-layer-group mr-1"></i><span id="stat-lists-count">0</span> Lists
                </span>
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="si si-size-fullscreen"></i>
                </button>
            </div>
        </header>

        <!-- 2. Filter Bar Card -->
        <section class="command-os-filter-card mb-3" aria-label="Filter lists">
            <div class="form-row align-items-end">
                <div class="col-sm-12 col-md-6 col-lg-5 mb-2 mb-md-0">
                    <label for="isps" class="command-os-field-label font-w600 text-uppercase font-size-xs mb-1">
                        <i class="fas fa-server mr-1 text-purple"></i> Select ISP:
                    </label>
                    <div class="command-input-wrap">
                        <select id="isps" name="isps" class="form-control selectpicker" data-size="10" data-live-search="true" title="Choose an ISP to load lists..." data-allow-clear="true">
                            @foreach ($isps as $isp)
                                <option value="{{ $isp->id }}">{{ $isp->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-sm-12 col-md-6 col-lg-3">
                    <button id="load_lists" type="button" class="command-os-btn-filter w-100">
                        <i class="fas fa-sync-alt mr-1"></i> LOAD LISTS
                    </button>
                </div>
            </div>
        </section>

        <!-- 3. Table Card -->
        <div class="command-os-table-card">
            <div id="lists-empty-prompt" class="command-table-empty-state py-5 text-center">
                <div class="command-empty-state-icon mb-3" style="font-size: 2.2rem; color: #8b5cf6; opacity: 0.85;">
                    <i class="fas fa-layer-group"></i>
                </div>
                <h4 class="font-w600 text-ink mb-1">Select an ISP to Load Lists</h4>
                <p class="text-muted font-size-sm mb-0">Choose an active ISP from the dropdown above and click Load Lists to inspect audiences.</p>
            </div>
            <div class="table-responsive" id="lists-table-wrapper" style="display: none;">
                <table id="lists" class="table table-sm table-hover table-striped w-100"
                    data-workspace-id="{{ $workspace->id }}"
                    data-base-url="{{ url('/app/workspaces/'.$workspace->id.'/infrastructure') }}"></table>
            </div>
            @if ($isps->isEmpty())
                <div class="command-os-info-banner mt-3" role="status">
                    <div class="command-os-info-banner-icon"><i class="fas fa-info-circle"></i></div>
                    <div class="command-os-info-banner-content">Add an ISP before creating its lists.</div>
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.jQuery(function () {
            const $ = window.jQuery;
            if ($.fn.selectpicker) $('#isps').selectpicker();
            const tableElement = document.querySelector('#lists');
            const baseUrl = tableElement.dataset.baseUrl;
            let dialog = null;
            let activeTableUrl = null;
            const escapeHtml = (value) => $('<div>').text(value == null ? '' : String(value)).html();
            const activeId = () => $('#isps').val();

            const openFrame = (url) => {
                if (!window.bootbox?.dialog) {
                    window.location.assign(url);
                    return;
                }
                dialog = window.bootbox.dialog({
                    scrollable: true,
                    closeButton: true,
                    size: 'lg',
                    message: `<iframe src="${escapeHtml(url)}" width="100%" scrolling="no" frameborder="0"></iframe>`,
                    onEscape: true,
                    centerVertical: true,
                }).on('shown.bs.modal', function () {
                    if (window.iFrameResize) window.iFrameResize({}, 'iframe');
                }).on('hidden.bs.modal', function () {
                    $('iframe').remove();
                });
            };

            const send = async (url, method, payload) => {
                const response = await fetch(url, {
                    method,
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(payload),
                });
                const body = await response.json();
                if (!response.ok) throw new Error(body.message || body.data || 'The list change could not be saved.');
                return body;
            };

            const reload = () => {
                if (activeTableUrl && $.fn.DataTable.isDataTable('#lists')) {
                    $('#lists').DataTable().ajax.reload(null, false);
                }
            };

            const makeTable = (ispId) => {
                activeTableUrl = `${baseUrl}/isps/${encodeURIComponent(ispId)}/lists`;
                $('#lists-empty-prompt').hide();
                $('#lists-table-wrapper').show();

                if ($.fn.DataTable.isDataTable('#lists')) {
                    $('#lists').DataTable().clear().destroy();
                    $('#lists').off().empty();
                }
                $('#lists').html('<tfoot><tr><td colspan="3">TOTAL:</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr></tfoot>');
                const dt = $('#lists').DataTable({
                    ajax: activeTableUrl,
                    serverSide: false,
                    paging: false,
                    pageLength: -1,
                    lengthChange: false,
                    select: { style: 'os' },
                    buttons: [
                        { text: '<i class="far fa-square mr-1"></i> Deselect', className: 'btn btn-sm btn-alt-secondary mr-1 mb-1', titleAttr: 'SELECT NONE', action: (event, table) => table.rows({ page: 'current' }).deselect() },
                        { text: '<i class="far fa-check-square mr-1"></i> Select All', className: 'btn btn-sm btn-alt-secondary mr-1 mb-1', titleAttr: 'SELECT ALL', action: (event, table) => table.rows({ page: 'current' }).select() },
                        {
                            text: '<i class="fas fa-users mr-1"></i> Allowed Users', className: 'btn btn-sm btn-alt-secondary mr-1 mb-1', titleAttr: 'ALLOWED USERS',
                            action: (event, table) => openBulkAssignment(table, 'users'),
                        },
                        {
                            text: '<i class="fas fa-hand-holding-usd mr-1"></i> Allowed Sponsors', className: 'btn btn-sm btn-alt-secondary mr-1 mb-1', titleAttr: 'ALLOWED SPONSORS',
                            action: (event, table) => openBulkAssignment(table, 'sponsors'),
                        },
                        {
                            text: '<i class="fa fa-plus mr-1"></i> Add New List', className: 'btn btn-sm btn-primary mr-1 mb-1', titleAttr: 'ADD NEW LIST',
                            action: () => openFrame(`${baseUrl}/isps/${encodeURIComponent(ispId)}/lists/create`),
                        },
                        { text: '<i class="fas fa-sync-alt mr-1"></i> Refresh', className: 'btn btn-sm btn-alt-secondary mr-1 mb-1', titleAttr: 'REFRESH', action: (event, table) => table.ajax.reload(null, false) },
                    ],
                    rowCallback: (row, data) => {
                        if (!data.is_active) $('td', row).addClass('row-disabled');
                        ['fresh', 'delivery', 'open', 'click', 'lead', 'unsubscribe'].forEach((type, index) => {
                            if (data[`is_${type}_locked`]) $('td', row).eq(index + 3).addClass('cell-locked');
                        });
                    },
                    columns: [
                        { title: '#', data: 'id', render: (val) => `<span class="badge badge-light border font-mono font-size-xs text-muted">#${escapeHtml(val)}</span>` },
                        { title: 'NAME', data: 'name', render: (val) => `<strong class="text-ink font-w600">${escapeHtml(val)}</strong>` },
                        { title: 'COUNTRY', data: 'country', defaultContent: '', render: (val) => `<span class="badge badge-light border text-purple px-2 py-1 font-w700">${escapeHtml(val || 'GLOBAL')}</span>` },
                        ...['fresh', 'delivery', 'open', 'click', 'lead', 'unsubscribe'].map((type) => ({
                            title: type === 'delivery' ? 'dlv' : type === 'unsubscribe' ? 'unsub' : type,
                            data: `history.${type}`,
                            searchable: false,
                            render: (value, display, row) => {
                                const count = value != null && !isNaN(value) ? Number(value).toLocaleString() : (value ?? '0');
                                const isLocked = Boolean(row[`is_${type}_locked`]);
                                const lockIcon = isLocked ? 'fa-lock' : 'fa-lock-open';
                                const lockColor = isLocked ? '#ef4444' : '#10b981';
                                return `<span class="font-mono text-nowrap font-size-xs font-w600">${escapeHtml(count)}</span>&nbsp;<button type="button" class="btn btn-link btn-sm p-0 lock" style="color: ${lockColor};" data-id="${escapeHtml(row.id)}" data-type="${type}" data-status="${isLocked ? 0 : 1}" title="${isLocked ? 'UNLOCK' : 'LOCK'} LIST TYPE" aria-label="${isLocked ? 'Unlock' : 'Lock'} ${type} for ${escapeHtml(row.name)}"><i class="fas ${lockIcon}"></i></button>`;
                            },
                        })),
                        ...['opt_out', 'proxy', 'bot', 'blacklist', 'hard_bounce'].map((type) => ({
                            title: ({ opt_out: 'optout', proxy: 'proxy', bot: 'bt', blacklist: 'bl', hard_bounce: 'hb' })[type],
                            data: `history.${type}`,
                            searchable: false,
                            render: (value) => `<span class="font-mono font-size-xs text-muted">${escapeHtml(value != null && !isNaN(value) ? Number(value).toLocaleString() : (value ?? '0'))}</span>`,
                        })),
                        {
                            title: 'ACTIONS', data: null, orderable: false, searchable: false,
                            render: (data, display, row) => {
                                const users = `<button type="button" data-id="${escapeHtml(row.id)}" class="command-action-btn action-users assignment" data-type="users" title="ALLOWED USERS" aria-label="Allowed users for ${escapeHtml(row.name)}"><i class="fas fa-users"></i></button>`;
                                const sponsors = `<button type="button" data-id="${escapeHtml(row.id)}" class="command-action-btn action-sponsors assignment" data-type="sponsors" title="ALLOWED SPONSORS" aria-label="Allowed sponsors for ${escapeHtml(row.name)}"><i class="fas fa-hand-holding-usd"></i></button>`;
                                const upload = `<button type="button" data-id="${escapeHtml(row.id)}" class="command-action-btn action-upload upload" title="UPLOAD EMAILS" aria-label="Upload emails to ${escapeHtml(row.name)}"><i class="fas fa-upload"></i></button>`;
                                const edit = `<button type="button" data-id="${escapeHtml(row.id)}" class="command-action-btn action-edit edit" title="UPDATE LIST" aria-label="Update ${escapeHtml(row.name)}"><i class="fas fa-pencil-alt"></i></button>`;
                                const track = `<button type="button" data-id="${escapeHtml(row.id)}" data-status="${row.is_tracked ? 0 : 1}" class="command-action-btn action-track ${row.is_tracked ? 'is-active' : ''} track" title="${row.is_tracked ? 'DISABLE TRACKING' : 'ENABLE TRACKING'}" aria-label="Toggle tracking for ${escapeHtml(row.name)}"><i class="fas ${row.is_tracked ? 'fa-satellite-dish' : 'fa-satellite'}"></i></button>`;
                                const status = `<button type="button" data-id="${escapeHtml(row.id)}" data-status="${row.is_active ? 0 : 1}" class="command-action-btn ${row.is_active ? 'action-status is-active' : 'action-enable'} status" title="${row.is_active ? 'DISABLE LIST' : 'ENABLE LIST'}" aria-label="${row.is_active ? 'Disable' : 'Enable'} ${escapeHtml(row.name)}"><i class="fas ${row.is_active ? 'fa-toggle-on' : 'fa-toggle-off'}"></i></button>`;
                                const remove = `<button type="button" data-id="${escapeHtml(row.id)}" class="command-action-btn action-danger delete" title="DELETE LIST" aria-label="Delete ${escapeHtml(row.name)}"><i class="fas fa-trash-alt"></i></button>`;
                                return `<div class="command-action-btn-group">${users}${sponsors}${upload}${edit}${track}${status}${remove}</div>`;
                            },
                        },
                    ],
                });

                dt.on('xhr', function (e, settings, json) {
                    if (json?.data) {
                        $('#stat-lists-count').text(json.data.length);
                        $('#stat-lists-pill').show();
                    }
                });

                $('#lists').on('click', '.edit', function (event) {
                    event.preventDefault();
                    openFrame(`${baseUrl}/lists/${encodeURIComponent(this.dataset.id)}/edit`);
                }).on('click', '.assignment', function (event) {
                    event.preventDefault();
                    openFrame(`${baseUrl}/lists/${encodeURIComponent(this.dataset.id)}/assignment/${this.dataset.type}`);
                }).on('click', '.upload', function (event) {
                    event.preventDefault();
                    openFrame(`${baseUrl}/lists/${encodeURIComponent(this.dataset.id)}/upload`);
                }).on('click', '.lock', async function (event) {
                    event.preventDefault();
                    const button = this;
                    button.disabled = true;
                    try {
                        await send(`${baseUrl}/lists/${encodeURIComponent(button.dataset.id)}/lock`, 'PATCH', { type: button.dataset.type, status: Number(button.dataset.status) });
                        reload();
                    } catch (error) {
                        button.disabled = false;
                        if (window.swal?.fire) await window.swal.fire({ icon: 'error', title: error.message });
                    }
                }).on('click', '.track', async function (event) {
                    event.preventDefault();
                    const button = this;
                    button.disabled = true;
                    try {
                        await send(`${baseUrl}/lists/${encodeURIComponent(button.dataset.id)}/track`, 'PATCH', { track: Number(button.dataset.status) });
                        reload();
                    } catch (error) {
                        button.disabled = false;
                        if (window.swal?.fire) await window.swal.fire({ icon: 'error', title: error.message });
                    }
                }).on('click', '.status', async function (event) {
                    event.preventDefault();
                    const button = this;
                    button.disabled = true;
                    try {
                        await send(`${baseUrl}/lists/${encodeURIComponent(button.dataset.id)}/status`, 'PATCH', { status: Number(button.dataset.status) });
                        reload();
                    } catch (error) {
                        button.disabled = false;
                        if (window.swal?.fire) await window.swal.fire({ icon: 'error', title: error.message });
                    }
                }).on('click', '.delete', async function (event) {
                    event.preventDefault();
                    const button = this;
                    const decision = window.Swal?.fire
                        ? await window.Swal.fire({ icon: 'warning', title: 'Delete this list?', text: 'The list will leave active workflows. Campaign snapshots and history will be retained.', showCancelButton: true, confirmButtonText: 'DELETE LIST' })
                        : { isConfirmed: window.confirm('Delete this list? Campaign snapshots and history will be retained.') };
                    if (!decision.isConfirmed && !decision.value) return;
                    button.disabled = true;
                    try {
                        await send(`${baseUrl}/lists/${encodeURIComponent(button.dataset.id)}`, 'DELETE', {});
                        reload();
                    } catch (error) {
                        button.disabled = false;
                        if (window.swal?.fire) await window.swal.fire({ icon: 'error', title: error.message });
                    }
                });
            };

            const openBulkAssignment = (table, type) => {
                const ids = table.rows({ selected: true }).data().toArray().map((row) => row.id);
                if (!ids.length) return;
                const query = new URLSearchParams();
                ids.forEach((id) => query.append('ids[]', id));
                openFrame(`${baseUrl}/lists/bulk-assignment/${type}?${query.toString()}`);
            };

            $('#load_lists').on('click', function (event) {
                event.preventDefault();
                if (!activeId()) {
                    window.AlphaLinkToast?.show('Please select an ISP first.', 'warning');
                    return;
                }
                const button = this;
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-1"></i> LOADING...';
                makeTable(activeId());
                $('#lists').one('xhr.dt', () => {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-sync-alt mr-1"></i> LOAD LISTS';
                });
            });

            window.closeModal = function () {
                if (dialog) dialog.modal('hide');
                reload();
            };
        });
    </script>
@endpush
