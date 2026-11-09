@extends('layouts.admin-shell')

@section('title', $isNetworkView ? 'My Offers' : 'Offers')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Card -->
        <header class="command-os-report-hero" aria-labelledby="offers-hero-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true">
                <i class="fas {{ $isNetworkView ? 'fa-handshake' : 'fa-tag' }}"></i>
            </span>
            <div class="command-os-report-hero-mark" aria-hidden="true">
                <i class="fas {{ $isNetworkView ? 'fa-handshake' : 'fa-tag' }}"></i>
            </div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">{{ $isNetworkView ? 'AFFILIATE NETWORK // ASSIGNED OFFERS' : 'OFFERS CATALOG // WORKSPACE INVENTORY' }}</p>
                <h1 id="offers-hero-title">{{ $isNetworkView ? 'MY OFFERS' : 'OFFERS' }}</h1>
                <p>{{ $isNetworkView ? 'Active network campaigns and offers assigned to your operator account with verified suppression files.' : 'Manage affiliate offers, payout structures, vertical assignments, and tracking assets.' }}</p>
            </div>
            <div class="command-os-hero-actions">
                <div class="command-os-report-hero-range">
                    <span>CLUSTER STATUS</span>
                    <strong><i class="fas fa-circle text-success" style="font-size: 8px;"></i> LIVE SYNC</strong>
                </div>
                @unless ($isNetworkView)
                    <label class="command-os-toggle-wrap mb-0" for="deleted">
                        <span class="command-os-toggle-label">DELETED</span>
                        <input type="checkbox" class="command-os-toggle-input" id="deleted">
                        <span class="command-os-toggle-slider"></span>
                    </label>
                @endunless
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="fas fa-expand"></i>
                </button>
            </div>
        </header>

        <!-- 2. KPI Overview Cards -->
        <section class="command-os-all-stats-overview" aria-label="Offers Overview">
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-tag"></i></span>
                <span class="all-stats-overview-copy">
                    <span>{{ $isNetworkView ? 'My Offers' : 'Active Offers' }}</span>
                    <strong id="stat-offers-count">{{ $workspace->offers()->where('is_active', true)->whereNull('deleted_at')->count() }}</strong>
                    <small>Catalog count</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-blue" aria-hidden="true"><i class="fas fa-building"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Sponsors</span>
                    <strong>{{ $sponsors->count() }}</strong>
                    <small>Connected networks</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-cyan" aria-hidden="true"><i class="fas fa-layer-group"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Verticals</span>
                    <strong>{{ $verticals->count() }}</strong>
                    <small>Active categories</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-emerald" aria-hidden="true"><i class="fas fa-shield-alt"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Suppression</span>
                    <strong>100% LIVE</strong>
                    <small>MD5 verified sync</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-amber" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Inventory Health</span>
                    <strong>OPTIMAL</strong>
                    <small>Production ready</small>
                </span>
            </article>
        </section>

        <!-- 3. Filter Bar Card -->
        <section class="command-os-filter-card" aria-label="Filter offers">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h4 class="font-size-sm font-w700 text-uppercase tracking-wider mb-0 text-muted">
                    <i class="fas fa-sliders-h mr-2 text-purple"></i> Filter &amp; Targeting
                </h4>
                <span class="badge badge-primary px-2 py-1"><i class="fas fa-filter mr-1"></i> Live Query</span>
            </div>
            <div class="form-row align-items-end">
                <div class="col-sm-6 col-lg-3">
                    <label for="offer-sponsor"><i class="fas fa-building text-purple mr-1"></i> Sponsors:</label>
                    <div class="command-input-wrap">
                        <span class="command-input-leading-icon"><i class="fas fa-building"></i></span>
                        <select class="form-control selectpicker command-input-with-icon" id="offer-sponsor" data-live-search="true">
                            <option value="0">Select Sponsor</option>
                            @foreach ($sponsors as $sponsor)
                                <option value="{{ $sponsor->id }}" @selected((string) request('sponsor') === (string) $sponsor->id)>{{ $sponsor->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label for="offer-vertical"><i class="fas fa-layer-group text-purple mr-1"></i> Verticals:</label>
                    <div class="command-input-wrap">
                        <span class="command-input-leading-icon"><i class="fas fa-layer-group"></i></span>
                        <select class="form-control selectpicker command-input-with-icon" id="offer-vertical" data-live-search="true">
                            <option value="">Select Vertical</option>
                            @foreach ($verticals as $vertical)
                                <option value="{{ $vertical }}" @selected(request('vertical') === $vertical)>{{ $vertical }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label for="offer-status"><i class="fas fa-toggle-on text-purple mr-1"></i> Status:</label>
                    <div class="command-input-wrap">
                        <span class="command-input-leading-icon"><i class="fas fa-toggle-on"></i></span>
                        <select class="form-control selectpicker command-input-with-icon" id="offer-status">
                            <option value="0">Select Status</option>
                            @foreach (\App\Models\WorkspaceOffer::STATUSES as $status)
                                <option value="{{ $status }}" @selected(request('status') === $status)>{{ strtoupper($status) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label for="offer-geo"><i class="fas fa-globe text-purple mr-1"></i> Geo:</label>
                    <div class="command-input-wrap">
                        <span class="command-input-leading-icon"><i class="fas fa-globe"></i></span>
                        <select class="form-control selectpicker command-input-with-icon" id="offer-geo" data-live-search="true">
                            <option value="ALL">Select Country</option>
                            @foreach ($countries as $code => $country)
                                <option value="{{ $code }}">{{ $country }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-sm-12 col-lg-2">
                    <button id="filter-offers" class="command-os-btn-filter" type="button">
                        <i class="fas fa-filter mr-2"></i>APPLY FILTERS
                    </button>
                </div>
            </div>
        </section>

        <!-- 4. Info Banner -->
        <div class="command-os-info-banner" role="status">
            <div class="command-os-info-banner-icon" aria-hidden="true">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="command-os-info-banner-content">
                @if ($isNetworkView)
                    Only active offers from active sponsors are shown. Operators must be assigned to both the sponsor and the offer.
                @else
                    New manual offers inherit the sponsor’s assigned operators and start inactive. Use each offer’s suppression-file action to view, refresh, download, or replace its saved file. Drops → Suppression remains the run and history screen.
                @endif
            </div>
        </div>

        <!-- 5. Table Card -->
        <div class="command-os-table-card">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="d-flex align-items-center">
                    <i class="fas fa-list-alt text-purple font-size-lg mr-2"></i>
                    <h3 class="h6 font-w700 text-uppercase tracking-wider mb-0 text-ink">{{ $isNetworkView ? 'Assigned Offers Inventory' : 'Offers Inventory & Directory' }}</h3>
                </div>
                <span class="badge badge-light border text-muted px-2 py-1 font-size-xs">
                    <i class="fas fa-database mr-1 text-purple"></i> High-Throughput Grid
                </span>
            </div>
            <table id="offers" class="table table-sm table-hover"
                   data-source-url="{{ $isNetworkView ? route('console.networks.offers.index', $workspace->id) : route('console.offers.index', $workspace->id) }}"
                   data-create-url="{{ route('console.offers.create', $workspace->id) }}"
                   data-bulk-users-url="{{ route('console.offers.bulk-users', $workspace->id) }}"
                   data-share-url="{{ route('console.offers.share', $workspace->id) }}"
                   data-suppression-url-template="{{ route('console.offers.suppression-file.show', [$workspace->id, '__OFFER_ID__']) }}"
                   data-download-url="{{ route('console.offers.index', $workspace->id) }}"
                   data-shareable="1"
                   data-manage="{{ $isNetworkView ? '0' : '1' }}"></table>
            <div id="offer-action-notice" class="alert alert-warning mt-3 d-none" role="status" aria-live="polite"></div>
        </div>
    </div>
    @unless ($isNetworkView)
        <div class="modal fade" id="offer-suppression-download-modal" tabindex="-1" role="dialog" aria-labelledby="offer-suppression-download-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="offer-suppression-download-title">SUPPRESSION FILE DOWNLOAD</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    </div>
                    <div class="modal-body">
                        <div id="offer-suppression-download-message" role="status" aria-live="polite">Connecting to the sponsor…</div>
                        <div class="progress mt-3" style="height: 22px">
                            <div id="offer-suppression-download-progress" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
                        </div>
                        <small id="offer-suppression-download-size" class="form-text text-muted mt-2"></small>
                        <div id="offer-suppression-download-error" class="alert alert-danger mt-3 d-none" role="alert"></div>
                        <div id="offer-suppression-download-complete" class="alert alert-success mt-3 d-none" role="status">
                            The verified file is saved to this offer. Open its suppression-file page to view or download it.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a id="offer-suppression-manage-link" class="btn btn-primary d-none" href="#">VIEW SAVED FILE</a>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">CLOSE</button>
                    </div>
                </div>
            </div>
        </div>
    @endunless
@endsection

@push('scripts')
    <script>
        window.jQuery(function () {
            const $ = window.jQuery;
            const tableElement = document.querySelector('#offers');
            const canManage = tableElement.dataset.manage === '1';
            const canShare = tableElement.dataset.shareable === '1';
            let dialog = null;
            const escapeHtml = (value) => $('<div>').text(value == null ? '' : String(value)).html();
            const openFrame = (url) => {
                if (!window.bootbox?.dialog) return window.location.assign(url);
                dialog = window.bootbox.dialog({
                    scrollable: true,
                    closeButton: true,
                    size: 'lg',
                    message: `<iframe src="${escapeHtml(url)}" width="100%" scrolling="no" frameborder="0"></iframe>`,
                    onEscape: true,
                    centerVertical: true,
                }).on('shown.bs.modal', function () {
                    if (window.iFrameResize) window.iFrameResize({}, 'iframe');
                }).on('hidden.bs.modal', function () { $('iframe').remove(); });
            };
            const api = async (url, method, payload = {}) => {
                const response = await fetch(url, {
                    method,
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: method === 'GET' ? undefined : JSON.stringify(payload),
                });
                const body = await response.json();
                if (!response.ok) throw new Error(Object.values(body.errors ?? {}).flat().join(' ') || body.data || body.message || 'Offer action failed.');
                return body;
            };
            const base = @json(route('console.offers.index', $workspace->id));
            const showOfferNotice = (message) => $('#offer-action-notice').text(message).removeClass('d-none');
            const selectedOfferIds = (dt) => dt.rows({ selected: true }).data().toArray().map((row) => row.id);
            const table = $('#offers').DataTable({
                ajax: {
                    url: tableElement.dataset.sourceUrl,
                    data: (data) => {
                        data.sponsor = $('#offer-sponsor').val();
                        data.vertical = $('#offer-vertical').val();
                        data.status = $('#offer-status').val();
                        data.geo = $('#offer-geo').val();
                        if (canManage) data.deleted = $('#deleted').is(':checked');
                    },
                },
                serverSide: false,
                pageLength: 50,
                order: [[1, 'desc']],
                select: canShare ? { style: 'os', selector: 'td:first-child' } : false,
                dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>' +
                     '<"table-responsive"t>' +
                     '<"command-table-footer"ip>',
                language: {
                    lengthMenu: 'Show _MENU_ rows',
                    search: '',
                    searchPlaceholder: 'Search offers...',
                    info: 'Page _PAGE_ of _PAGES_ (_TOTAL_ records)',
                    infoEmpty: 'Page 0 of 0 (0 records)',
                    paginate: {
                        previous: '<i class="fas fa-chevron-left"></i>',
                        next: '<i class="fas fa-chevron-right"></i>'
                    }
                },
                buttons: [
                    ...(canShare ? [{ text: '<i class="fas fa-share-alt mr-1"></i> SHARE', titleAttr: 'SHARE CREATIVES', action: (event, dt) => {
                        const ids = selectedOfferIds(dt);
                        if (!ids.length) {
                            showOfferNotice('Select one or more active offers before sharing creatives.');
                            return;
                        }
                        $('#offer-action-notice').addClass('d-none').text('');
                        openFrame(`${tableElement.dataset.shareUrl}?${$.param({ ids })}`);
                    } }] : []),
                    { text: '<i class="fas fa-sync-alt mr-1"></i> REFRESH', titleAttr: 'REFRESH', action: (event, dt) => dt.ajax.reload(null, false) },
                    ...(canManage ? [
                        { text: '<i class="fas fa-plus mr-1"></i> NEW OFFER', titleAttr: 'ADD NEW OFFER', action: () => openFrame(tableElement.dataset.createUrl) },
                        { text: '<i class="fas fa-users-cog mr-1"></i> BULK USERS', titleAttr: 'ALLOWED USERS', action: (event, dt) => {
                            const ids = selectedOfferIds(dt);
                            if (ids.length) openFrame(`${tableElement.dataset.bulkUsersUrl}?${$.param({ ids })}`);
                            else showOfferNotice('Select one or more offers to change allowed users.');
                        } },
                    ] : []),
                ],
                columns: [
                    ...(canShare ? [{ data: null, defaultContent: '', orderable: false, searchable: false, className: 'select-checkbox', title: '' }] : []),
                    { title: '#', data: 'id' },
                    { title: 'OID', data: 'offer_id' },
                    { title: 'SPONSOR', data: 'sponsor.name' },
                    { title: 'NAME', data: 'name' },
                    { title: 'VERTICAL', data: 'vertical' },
                    { title: 'GEO', data: 'geo_target', render: (value) => Array.isArray(value) ? escapeHtml(value.join(', ')) : '' },
                    { title: 'PAYOUT', data: 'payout' },
                    { title: 'SUP LAST UPDATE', data: 'suppression_updated_at', render: (value, type, row) => {
                        const status = !value
                            ? '<strong class="badge-suppression-stale">NEVER</strong>'
                            : (() => {
                                const days = Math.max(0, Math.floor((Date.now() - new Date(`${value}T00:00:00Z`).getTime()) / 86400000));
                                return `<strong class="${days > 7 ? 'badge-suppression-stale' : 'badge-suppression-live'}" title="${escapeHtml(value)}">${days} DAYS</strong>`;
                            })();
                        if (!canManage || row.deleted_at) return status;
                        return `<span class="text-nowrap">${status} <button type="button" class="btn btn-link btn-sm p-0 ml-1 download-suppression" style="color: #38bdf8;" data-id="${escapeHtml(row.id)}" title="DOWNLOAD SUPPRESSION FILE FROM SPONSOR" aria-label="Download suppression file for ${escapeHtml(row.name)}"><i class="fas fa-sync-alt" aria-hidden="true"></i></button></span>`;
                    } },
                    { title: 'STATUS', data: 'status', render: (value, type, row) => canManage && !row.deleted_at ? `<select class="form-control form-control-sm business-status" data-id="${escapeHtml(row.id)}">${['live','issue','paused'].map((status) => `<option value="${status}" ${value === status ? 'selected' : ''}>${status.toUpperCase()}</option>`).join('')}</select>` : escapeHtml(value) },
                    { title: 'ACTIVE', data: 'is_active', render: (value) => value ? '<span class="badge badge-command-active-yes">YES</span>' : '<span class="badge badge-command-active-no">NO</span>' },
                    { title: 'FROM', data: 'from_count', render: (value) => Number(value || 0) },
                    { title: 'SUBJECTS', data: 'subjects_count', render: (value) => Number(value || 0) },
                    ...(canManage ? [{ title: 'USERS', data: 'allowed_users', searchable: false, render: (users, type, row) => !row.deleted_at ? `<button type="button" data-id="${escapeHtml(row.id)}" class="btn btn-link users command-users-link" title="ALLOWED USERS"><i class="fas fa-users mr-1"></i>${Array.isArray(users) ? users.length : 0}</button>` : '' }] : []),
                    { title: 'ACTIONS', data: null, orderable: false, searchable: false, render: (data, type, row) => {
                        const assetsUrl = `${base}/${row.id}/assets`;
                        if (!canManage) return `<a class="command-action-btn action-primary" href="${assetsUrl}" title="SHOW OFFER ASSETS"><i class="fas fa-box-open"></i></a>`;
                        if (row.deleted_at) return `<button type="button" class="command-action-btn action-info restore" data-id="${escapeHtml(row.id)}" title="RESTORE OFFER"><i class="fas fa-trash-restore"></i></button>`;
                        const suppressionUrl = tableElement.dataset.suppressionUrlTemplate.replace('__OFFER_ID__', encodeURIComponent(row.id));
                        return `<div class="command-action-btn-group">` +
                            `<a class="command-action-btn action-primary" href="${assetsUrl}" title="SHOW OFFER ASSETS"><i class="fas fa-box-open"></i></a>` +
                            `<a class="command-action-btn action-warning" href="${escapeHtml(suppressionUrl)}" title="MANAGE SUPPRESSION FILE" aria-label="Manage suppression file for ${escapeHtml(row.name)}"><i class="fas fa-file-contract"></i></a>` +
                            `<button type="button" class="command-action-btn action-info edit-offer" data-id="${escapeHtml(row.id)}" title="UPDATE OFFER"><i class="fas fa-edit"></i></button>` +
                            `<button type="button" class="command-action-btn ${row.is_active ? 'action-status' : 'action-enable'} active-offer" data-id="${escapeHtml(row.id)}" data-status="${row.is_active ? 0 : 1}" title="${row.is_active ? 'DISABLE OFFER' : 'ENABLE OFFER'}"><i class="fas ${row.is_active ? 'fa-toggle-on' : 'fa-toggle-off'}"></i></button>` +
                            `<button type="button" class="command-action-btn action-danger delete-offer" data-id="${escapeHtml(row.id)}" title="DELETE OFFER"><i class="fas fa-trash-alt"></i></button>` +
                            `</div>`;
                    } },
                ],
            });
            table.on('xhr', function (e, settings, json) {
                if (json?.data) {
                    $('#stat-offers-count').text(json.data.length);
                }
            });
            const downloadModal = $('#offer-suppression-download-modal');
            const formatBytes = (value) => {
                const bytes = Number(value || 0);
                if (bytes < 1024) return `${bytes} B`;
                const units = ['KB', 'MB', 'GB', 'TB'];
                let size = bytes / 1024;
                let unit = 0;
                while (size >= 1024 && unit < units.length - 1) { size /= 1024; unit++; }
                return `${size.toFixed(2)} ${units[unit]}`;
            };
            const formatCount = (value) => Number(value || 0).toLocaleString();
            const showDownloadProgress = (download) => {
                const percent = download.percent;
                const progress = $('#offer-suppression-download-progress');
                progress.css('width', percent == null ? '100%' : `${percent}%`)
                    .attr('aria-valuenow', percent == null ? 0 : percent)
                    .toggleClass('progress-bar-animated', percent == null || !['completed', 'failed'].includes(download.status));
                if (percent == null) progress.addClass('progress-bar-striped');
                else progress.removeClass('progress-bar-striped');
                const labels = {
                    queued: 'Waiting for the suppression worker…',
                    resolving: 'Requesting the suppression file from the sponsor…',
                    downloading: 'Downloading suppression file…',
                    extracting: 'Unpacking the downloaded file…',
                    validating: 'Checking suppression entries…',
                    saving: 'Saving the verified suppression list…',
                    completed: 'Download complete.',
                    failed: 'Download failed.',
                };
                $('#offer-suppression-download-message').text(labels[download.status] || 'Processing suppression file…');
                const progressLabel = download.status === 'saving'
                    ? `${formatCount(download.processed_entries)} of ${formatCount(download.entry_count)} entries saved`
                    : download.total_bytes
                        ? `${formatBytes(download.downloaded_bytes)} of ${formatBytes(download.total_bytes)}${download.status === 'validating' ? ' checked' : ''}`
                        : (download.downloaded_bytes ? `${formatBytes(download.downloaded_bytes)} downloaded` : '');
                $('#offer-suppression-download-size').text(progressLabel);
                if (download.status === 'completed') {
                    $('#offer-suppression-download-complete').removeClass('d-none');
                    $('#offer-suppression-manage-link').attr('href', download.manage_url).removeClass('d-none');
                }
                if (download.status === 'failed') {
                    $('#offer-suppression-download-error').text(download.failure_message || 'The sponsor suppression file could not be downloaded.').removeClass('d-none');
                }
            };
            const pollDownload = async (offerId, download) => {
                showDownloadProgress(download);
                if (['completed', 'failed'].includes(download.status)) {
                    table.ajax.reload(null, false);
                    return;
                }
                window.setTimeout(async () => {
                    try {
                        const state = await api(`${tableElement.dataset.downloadUrl}/${offerId}/suppression-file/downloads/${download.id}`, 'GET');
                        pollDownload(offerId, state.download);
                    } catch (error) {
                        $('#offer-suppression-download-error').text(error.message).removeClass('d-none');
                    }
                }, 1200);
            };
            $('#offers').on('click', '.download-suppression', async function () {
                const control = this;
                const offerId = control.dataset.id;
                control.disabled = true;
                $('#offer-suppression-download-progress').css('width', '0%').attr('aria-valuenow', 0).addClass('progress-bar-striped progress-bar-animated');
                $('#offer-suppression-download-size').text('');
                $('#offer-suppression-download-message').text('Connecting to the sponsor…');
                $('#offer-suppression-download-error, #offer-suppression-download-complete, #offer-suppression-manage-link').addClass('d-none');
                downloadModal.modal('show');
                try {
                    const response = await api(`${tableElement.dataset.downloadUrl}/${offerId}/suppression-file/download`, 'POST');
                    pollDownload(offerId, response.download);
                } catch (error) {
                    $('#offer-suppression-download-message').text('Download could not be started.');
                    $('#offer-suppression-download-error').text(error.message).removeClass('d-none');
                } finally {
                    control.disabled = false;
                }
            });
            $('#filter-offers').on('click', () => table.ajax.reload());
            $('#deleted').on('change', () => table.ajax.reload());
            $('#offers').on('click', '.users', function () { openFrame(`${base}/${this.dataset.id}/users`); });
            $('#offers').on('click', '.edit-offer', function () { openFrame(`${base}/${this.dataset.id}/edit`); });
            $('#offers').on('change', '.business-status', async function () {
                const select = this;
                try {
                    await api(`${base}/${select.dataset.id}/business-status`, 'PATCH', { status: select.value });
                    table.ajax.reload(null, false);
                } catch (error) { window.swal?.fire({ icon: 'error', title: error.message }); }
            });
            $('#offers').on('click', '.active-offer, .delete-offer, .restore', async function () {
                const control = this;
                const action = control.classList.contains('active-offer') ? 'active' : (control.classList.contains('restore') ? 'restore' : 'delete');
                const url = `${base}/${control.dataset.id}`;
                if (action === 'delete') {
                    if (window.swal?.fire) {
                        const confirmation = await window.swal.fire({ icon: 'warning', title: 'Delete this offer?', text: 'Deleting this offer also clears its suppression metadata.', showCancelButton: true, confirmButtonText: 'Delete' });
                        if (!confirmation.isConfirmed) return;
                    } else if (!window.confirm('Delete this offer?')) return;
                }
                const method = action === 'delete' ? 'DELETE' : 'PATCH';
                try {
                    const body = await api(action === 'active' ? `${url}/active` : (action === 'restore' ? `${url}/restore` : url), method, action === 'active' ? { status: Number(control.dataset.status) } : {});
                    window.swal?.fire({ icon: 'success', title: body.data });
                    table.ajax.reload(null, false);
                } catch (error) { window.swal?.fire({ icon: 'error', title: error.message }); }
            });
            window.closeModal = function () { dialog?.modal('hide'); table.ajax.reload(null, false); };
        });
    </script>
@endpush
