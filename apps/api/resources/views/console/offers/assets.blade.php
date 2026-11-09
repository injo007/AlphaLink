@extends('layouts.admin-shell')

@section('title', "Offer {$offer->id} Assets")

@section('content')
<div id="offer-assets-page" class="command-os-entity-page">
    <!-- 1. Hero Card -->
    <header class="command-os-report-hero" aria-labelledby="offer-assets-hero-title">
        <span class="command-os-report-hero-illustration" aria-hidden="true">
            <i class="fas fa-boxes"></i>
        </span>
        <div class="command-os-report-hero-mark" aria-hidden="true">
            <i class="fas fa-boxes"></i>
        </div>
        <div class="command-os-report-hero-copy">
            <p class="command-os-report-eyebrow">OFFER MANAGEMENT // ASSET REPOSITORY</p>
            <h1 id="offer-assets-hero-title">OFFER #{{ $offer->id }} ASSETS</h1>
            <p>{{ $offer->name }} &middot; {{ $offer->sponsor?->name ?? 'Unknown sponsor' }} &middot; Offer ID {{ $offer->offer_id }} &middot; {{ $offer->vertical ?: 'No vertical' }}</p>
        </div>
        <div class="command-os-hero-actions">
            <a class="btn btn-sm btn-light" href="{{ route('clean.console.offers.index', ['sponsor' => $offer->sponsor_id]) }}">
                <i class="fas fa-arrow-left mr-1"></i> BACK TO OFFERS
            </a>
            <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                <i class="fas fa-expand"></i>
            </button>
        </div>
    </header>

    <!-- 2. KPI Overview Cards -->
    <section class="command-os-all-stats-overview" aria-label="Offer Assets Overview">
        <article class="all-stats-overview-card">
            <span class="all-stats-overview-icon icon-amber" aria-hidden="true"><i class="fas fa-envelope"></i></span>
            <span class="all-stats-overview-copy">
                <span>From Names</span>
                <strong id="stat-from-count">{{ $assetCounts['from'] ?? 0 }}</strong>
                <small>Sender identities</small>
            </span>
        </article>
        <article class="all-stats-overview-card">
            <span class="all-stats-overview-icon icon-rose" aria-hidden="true"><i class="fas fa-heading"></i></span>
            <span class="all-stats-overview-copy">
                <span>Subjects</span>
                <strong id="stat-subjects-count">{{ $assetCounts['subjects'] ?? 0 }}</strong>
                <small>Headline variants</small>
            </span>
        </article>
        <article class="all-stats-overview-card">
            <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-images"></i></span>
            <span class="all-stats-overview-copy">
                <span>Creatives</span>
                <strong id="stat-creatives-count">{{ $assetCounts['creatives'] ?? 0 }}</strong>
                <small>Image assets</small>
            </span>
        </article>
        <article class="all-stats-overview-card">
            <span class="all-stats-overview-icon icon-emerald" aria-hidden="true"><i class="fas fa-user-minus"></i></span>
            <span class="all-stats-overview-copy">
                <span>Unsubscribe</span>
                <strong id="stat-unsubscribe-count">{{ $assetCounts['unsubscribe'] ?? 0 }}</strong>
                <small>Opt-out creatives</small>
            </span>
        </article>
        <article class="all-stats-overview-card">
            <span class="all-stats-overview-icon icon-cyan" aria-hidden="true"><i class="fas fa-code"></i></span>
            <span class="all-stats-overview-copy">
                <span>HTML Bodies</span>
                <strong id="stat-html-count">{{ $assetCounts['html'] ?? 0 }}</strong>
                <small>Direct templates</small>
            </span>
        </article>
    </section>

    <!-- 3. Offer Summary Card -->
    <section class="command-os-settings-card mb-4" aria-label="Offer Configuration Summary">
        <header class="command-os-card-header mb-3">
            <div class="command-os-card-title">
                <span class="command-os-card-icon icon-purple" aria-hidden="true"><i class="fas fa-info-circle"></i></span>
                <div>
                    <h2>OFFER PARAMETERS</h2>
                    <p>Core routing, geo distribution, and campaign notes</p>
                </div>
            </div>
        </header>
        <div class="row font-size-sm">
            <div class="col-12 col-md-6 mb-2">
                <span class="text-muted font-w700 text-uppercase mr-2 font-size-xs">Redirect Link:</span>
                @if ($offer->redirect)
                    <a href="{{ $offer->redirect }}" target="_blank" rel="noopener noreferrer" class="text-purple font-mono font-size-xs">{{ $offer->redirect }} <i class="fas fa-external-link-alt ml-1"></i></a>
                @else
                    <span class="text-muted">&mdash;</span>
                @endif
            </div>
            <div class="col-12 col-md-6 mb-2">
                <span class="text-muted font-w700 text-uppercase mr-2 font-size-xs">Unsubscribe Link:</span>
                @if ($offer->unsubscribe)
                    <a href="{{ $offer->unsubscribe }}" target="_blank" rel="noopener noreferrer" class="text-purple font-mono font-size-xs">{{ $offer->unsubscribe }} <i class="fas fa-external-link-alt ml-1"></i></a>
                @else
                    <span class="text-muted">&mdash;</span>
                @endif
            </div>
            <div class="col-12 col-md-6 mb-2">
                <span class="text-muted font-w700 text-uppercase mr-2 font-size-xs">Geo Target:</span>
                @if (!empty($offer->geo_target))
                    @foreach ((array) $offer->geo_target as $geo)
                        <span class="badge badge-light border text-purple mr-1">{{ $geo }}</span>
                    @endforeach
                @else
                    <span class="badge badge-success">ALL</span>
                @endif
            </div>
            <div class="col-12 col-md-6 mb-2">
                <span class="text-muted font-w700 text-uppercase mr-2 font-size-xs">Allowed Days:</span>
                @if (!empty($offer->allowed_days))
                    @foreach ((array) $offer->allowed_days as $day)
                        <span class="badge badge-light border text-muted mr-1">{{ $day }}</span>
                    @endforeach
                @else
                    <span class="badge badge-light border text-muted">EVERYDAY</span>
                @endif
            </div>
            @if ($offer->note)
                <div class="col-12 mt-2 pt-2 border-top">
                    <span class="text-muted font-w700 text-uppercase mr-2 font-size-xs">Note:</span>
                    <span class="text-ink font-size-xs" style="white-space: pre-wrap;">{{ $offer->note }}</span>
                </div>
            @endif
        </div>
    </section>

    <!-- 4. Table Card with Custom Tabs -->
    <div class="command-os-table-card">
        <ul class="nav nav-pills command-os-settings-tabs mb-3" id="offer-asset-tabs" role="tablist">
            @foreach (['from' => ['FROM', 'fa-envelope'], 'subjects' => ['SUBJECTS', 'fa-heading'], 'creatives' => ['CREATIVES', 'fa-images'], 'unsubscribe' => ['UNSUBSCRIBE', 'fa-user-minus'], 'html' => ['HTML', 'fa-code']] as $assetType => [$label, $icon])
                <li class="nav-item">
                    <a class="nav-link {{ $loop->first ? 'active' : '' }}" href="#{{ $assetType }}" data-asset-type="{{ $assetType }}" role="tab">
                        <i class="fas {{ $icon }} mr-1"></i> {{ $label }} <span id="{{ $assetType }}-count" class="badge badge-pill badge-light ml-1">{{ $assetCounts[$assetType] ?? 0 }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
        @if (! $isNetworkView)
            <div class="alert alert-info py-2 font-size-sm" role="status"><i class="fas fa-info-circle mr-1"></i> Creative images and the single replaceable unsubscribe image are stored privately in this workspace. HTML assets are supplied by affiliate offer import.</div>
            <div id="asset-result" class="alert alert-danger d-none py-2 font-size-sm" role="alert" aria-live="assertive"></div>
        @endif
        <table id="offer-assets" class="table table-sm table-hover table-striped w-100"
               data-source-url="{{ route('console.offers.assets', [$workspace->id, $offer->id]) }}"
               data-manage="{{ $isNetworkView ? '0' : '1' }}"
               data-upload-creative-url="{{ route('console.offers.assets.upload-form', [$workspace->id, $offer->id, 'creative']) }}"
               data-upload-unsubscribe-url="{{ route('console.offers.assets.upload-form', [$workspace->id, $offer->id, 'unsubscribe']) }}"
               data-bulk-status-url="{{ route('console.offers.assets.bulk-status', [$workspace->id, $offer->id]) }}"></table>
    </div>
</div>
@endsection

@push('scripts')
    <script>
        window.jQuery(function () {
            const $ = window.jQuery;
            const tableElement = document.querySelector('#offer-assets');
            const canManage = tableElement.dataset.manage === '1';
            const typeTabs = ['from', 'subjects', 'creatives', 'unsubscribe', 'html'];
            const bulkTypes = ['from', 'subjects', 'creatives'];
            let activeType = 'from';
            let dialog = null;
            let table;
            const escapeHtml = (value) => $('<div>').text(value == null ? '' : String(value)).html();
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const endpoint = @json(route('console.offers.index', $workspace->id));
            const uploadDialog = (url) => {
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
                }).on('hidden.bs.modal', function () {
                    $('iframe').remove();
                    table.ajax.reload(null, false);
                    refreshCounts();
                });
            };
            const request = async (url, method, payload) => {
                const response = await fetch(url, {
                    method,
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: payload ? JSON.stringify(payload) : undefined,
                });
                const body = await response.json();
                if (!response.ok) throw new Error(Object.values(body.errors ?? {}).flat().join(' ') || body.message || body.data || 'Offer asset action failed.');
                return body;
            };
            const typeUrl = (type) => `${tableElement.dataset.sourceUrl}?type=${encodeURIComponent(type)}`;
            const showError = (message) => {
                const result = document.querySelector('#asset-result');
                if (result) {
                    result.textContent = message;
                    result.classList.remove('d-none');
                    return;
                }
                window.swal?.fire({ icon: 'error', title: message });
            };
            const selectedAssetIds = () => table.rows({ selected: true }).data().toArray().map((row) => Number(row.id));
            const changeSelectedStatus = async (status) => {
                const ids = selectedAssetIds();
                if (!ids.length) {
                    showError('Select at least one asset.');
                    return;
                }
                const action = status ? 'Enable' : 'Disable';
                if (!window.swal?.fire) {
                    showError('The confirmation dialog is unavailable. Reload the page and try again.');
                    return;
                }
                const confirmation = await window.swal.fire({
                        icon: status ? 'question' : 'warning',
                        title: `${action} ${ids.length} selected ${activeType} asset${ids.length === 1 ? '' : 's'}?`,
                        text: 'Only the selected assets in this offer will change.',
                        showCancelButton: true,
                        confirmButtonText: action,
                    });
                if (!confirmation.isConfirmed) return;

                const toolbarButtons = table.buttons().container().find('button');
                toolbarButtons.prop('disabled', true);
                try {
                    const body = await request(tableElement.dataset.bulkStatusUrl, 'PATCH', { ids, status });
                    table.rows({ selected: true }).deselect();
                    table.ajax.reload(null, false);
                    document.querySelector('#asset-result')?.classList.add('d-none');
                    window.swal?.fire({ icon: 'success', title: `${action}d ${body.updated} asset${body.updated === 1 ? '' : 's'}.` });
                } catch (error) {
                    showError(error.message);
                } finally {
                    toolbarButtons.prop('disabled', false);
                }
            };
            table = $('#offer-assets').DataTable({
                ajax: {
                    url: typeUrl(activeType),
                    dataSrc: (response) => {
                        const count = response.count ?? response.data?.length ?? 0;
                        const tabBadge = document.querySelector(`#${activeType}-count`);
                        const statBadge = document.querySelector(`#stat-${activeType}-count`);
                        if (tabBadge) tabBadge.textContent = count;
                        if (statBadge) statBadge.textContent = count;
                        return response.data ?? [];
                    },
                },
                serverSide: false,
                pageLength: 50,
                dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>' +
                     '<"table-responsive"t>' +
                     '<"command-table-footer"ip>',
                language: {
                    lengthMenu: 'Show _MENU_ rows',
                    search: '',
                    searchPlaceholder: 'Search assets...',
                    info: 'Page _PAGE_ of _PAGES_ (_TOTAL_ records)',
                    infoEmpty: 'Page 0 of 0 (0 records)',
                    paginate: {
                        previous: '<i class="fas fa-chevron-left"></i>',
                        next: '<i class="fas fa-chevron-right"></i>'
                    }
                },
                ...(canManage ? {
                    select: { style: 'os', selector: 'td:first-child' },
                    buttons: {
                        dom: {
                            container: {
                                tag: 'div',
                                className: 'dt-buttons'
                            },
                            button: {
                                tag: 'button',
                                className: 'btn'
                            }
                        },
                        buttons: [
                            {
                                text: '<i class="fas fa-sync-alt mr-1"></i> REFRESH',
                                className: 'btn-command-action',
                                titleAttr: 'REFRESH',
                                action: (event, dataTable) => dataTable.ajax.reload(null, false),
                            },
                            {
                                text: '<i class="fas fa-plus mr-1"></i> ADD ASSET',
                                className: 'btn-command-primary',
                                titleAttr: 'ADD NEW ASSET',
                                action: async () => {
                                    if (activeType === 'creatives') return uploadDialog(tableElement.dataset.uploadCreativeUrl);
                                    if (activeType === 'unsubscribe') return uploadDialog(tableElement.dataset.uploadUnsubscribeUrl);
                                    if (!['from', 'subjects'].includes(activeType) || !window.swal?.fire) return;
                                    const label = activeType === 'from' ? 'FROM' : 'SUBJECTS';
                                    const result = await window.swal.fire({
                                        title: `INSERT NEW ${label}`,
                                        input: 'textarea',
                                    inputLabel: 'Enter one value per line',
                                    showCancelButton: true,
                                    confirmButtonText: 'ADD',
                                });
                                if (!result.isConfirmed) return;
                                try {
                                    const type = activeType === 'from' ? 'from' : 'subject';
                                    const assets = [...new Set(String(result.value || '').split(/\r?\n/).map((value) => value.trim()).filter(Boolean))];
                                    const body = await request(tableElement.dataset.sourceUrl, 'POST', { type, assets });
                                    await window.swal.fire({ icon: 'success', title: body.data });
                                    table.ajax.reload(null, false);
                                    refreshCounts();
                                } catch (error) { showError(error.message); }
                            },
                        },
                        {
                            text: '<i class="fas fa-ban mr-1"></i> DISABLE',
                            titleAttr: 'DISABLE SELECTED ASSETS',
                            action: () => changeSelectedStatus(false),
                        },
                        {
                            text: '<i class="fas fa-check mr-1"></i> ENABLE',
                            titleAttr: 'ENABLE SELECTED ASSETS',
                            action: () => changeSelectedStatus(true),
                        },
                    ],
                } : {}),
                columns: [
                    ...(canManage ? [{
                        data: null,
                        defaultContent: '',
                        orderable: false,
                        searchable: false,
                        className: 'select-checkbox',
                        title: '',
                    }] : []),
                    { title: '#', data: 'id', render: (val) => `<span class="font-mono text-muted">#${escapeHtml(val)}</span>` },
                    {
                        title: 'VALUE',
                        data: 'value',
                        render: (value, type, row) => {
                            if (type !== 'display') return value;
                            if (row.file_url) return `<a href="${escapeHtml(row.file_url)}" target="_blank" rel="noopener noreferrer"><img loading="lazy" src="${escapeHtml(row.file_url)}" alt="Offer ${escapeHtml(row.type)} asset" style="width: 80px;height: 80px;object-fit: contain;border-radius:8px;border:1px solid var(--cv-border);background:var(--cv-surface-2);padding:2px;"></a>`;
                            if (row.type === 'html') return `<textarea class="form-control html-preview font-mono font-size-xs" rows="3" readonly aria-label="HTML creative; click to preview">${escapeHtml(value)}</textarea>`;
                            return `<span class="text-ink font-w600">${escapeHtml(value)}</span>`;
                        },
                    },
                    { title: 'STATUS', data: 'is_active', render: (active) => active ? '<span class="badge badge-success px-2 py-1 font-w700">ACTIVE</span>' : '<span class="badge badge-danger px-2 py-1 font-w700">DISABLED</span>' },
                    { title: 'CREATED AT', data: 'created_at', render: (val) => val ? `<span class="text-muted text-nowrap font-size-xs">${escapeHtml(val.split(' ')[0] || val)}</span>` : '' },
                    ...(canManage ? [{
                        title: 'ACTIONS', data: null, orderable: false, searchable: false,
                        render: (data, type, row) => {
                            const actions = [];
                            if (['from', 'subject'].includes(row.type)) {
                                actions.push(`<button class="command-action-btn action-info asset-edit" type="button" data-id="${row.id}" data-value="${escapeHtml(row.value)}" title="EDIT"><i class="fas fa-pencil-alt"></i></button>`);
                                actions.push(`<button class="command-action-btn action-danger asset-delete" type="button" data-id="${row.id}" title="DELETE"><i class="fas fa-trash-alt"></i></button>`);
                            }
                            if (['from', 'subject', 'creative', 'html'].includes(row.type)) {
                                actions.push(`<button class="command-action-btn ${row.is_active ? 'action-status' : 'action-enable'} asset-toggle" type="button" data-id="${row.id}" data-status="${row.is_active ? 0 : 1}" title="${row.is_active ? 'DISABLE' : 'ENABLE'}"><i class="fas ${row.is_active ? 'fa-toggle-on' : 'fa-toggle-off'}"></i></button>`);
                            }
                            return `<div class="command-action-btn-group">${actions.join('')}</div>`;
                        },
                    }] : []),
                ],
            });
            const updateAssetToolbar = () => {
                if (!canManage) return;
                const addTitle = {
                    from: 'ADD NEW FROM',
                    subjects: 'ADD NEW SUBJECT',
                    creatives: 'ADD NEW CREATIVE',
                    unsubscribe: 'REPLACE UNSUBSCRIBE',
                }[activeType];
                const addButton = $(table.button(2).node());
                addButton.toggle(activeType !== 'html');
                addButton.attr('title', addTitle || 'ADD ASSET').attr('aria-label', addTitle || 'ADD ASSET');
                $(table.button(3).node()).toggle(bulkTypes.includes(activeType));
                $(table.button(4).node()).toggle(bulkTypes.includes(activeType));
                table.column(0).visible(bulkTypes.includes(activeType));
            };
            updateAssetToolbar();
            const refreshCounts = async () => {
                await Promise.all(typeTabs.map(async (type) => {
                    try {
                        const response = await fetch(typeUrl(type), { headers: { 'Accept': 'application/json' } });
                        if (response.ok) {
                            const count = (await response.json()).count ?? 0;
                            const tabCount = document.querySelector(`#${type}-count`);
                            const statCount = document.querySelector(`#stat-${type}-count`);
                            if (tabCount) tabCount.textContent = count;
                            if (statCount) statCount.textContent = count;
                        }
                    } catch (_) { /* keep the last verified count */ }
                }));
            };
            $('#offer-asset-tabs').on('click', '[data-asset-type]', function (event) {
                event.preventDefault();
                activeType = this.dataset.assetType;
                table.rows({ selected: true }).deselect();
                $('#offer-asset-tabs .nav-link').removeClass('active');
                $(this).addClass('active');
                document.querySelector('#asset-result')?.classList.add('d-none');
                updateAssetToolbar();
                $('#offer-assets').DataTable().ajax.url(typeUrl(activeType)).load();
            });
            $('#offer-assets').on('click', '.html-preview', function () {
                if (!window.bootbox?.dialog) {
                    window.swal?.fire({ icon: 'error', title: 'HTML preview is unavailable.' });
                    return;
                }
                const asset = table.row($(this).closest('tr')).data();
                const html = String(asset.value || '').replace(
                    /(<img[^>]+src=['"]|background-image:\s*url\(['"]?)http:\/\/\[domain\]/gi,
                    (match, prefix) => prefix + `${window.location.origin}/o`,
                );
                const frame = document.createElement('iframe');
                frame.width = '100%';
                frame.height = '700';
                frame.setAttribute('sandbox', 'allow-forms allow-popups');
                frame.setAttribute('referrerpolicy', 'no-referrer');
                frame.srcdoc = html;
                window.bootbox.dialog({
                    scrollable: true,
                    closeButton: true,
                    size: 'xl',
                    message: frame,
                    onEscape: true,
                    centerVertical: true,
                });
            });
            if (canManage) {
                $('#offer-assets').on('click', '.asset-edit', async function () {
                    const row = table.row($(this).closest('tr')).data();
                    if (!window.swal?.fire) {
                        showError('The edit dialog is unavailable. Reload the page and try again.');
                        return;
                    }
                    const value = await window.swal.fire({ title: 'Edit asset', input: 'text', inputValue: row.value, showCancelButton: true });
                    if (!value.isConfirmed || value.value == null) return;
                    try {
                        await request(`${endpoint}/assets/${row.id}`, 'PUT', { value: value.value });
                        table.ajax.reload(null, false);
                    } catch (error) { window.swal?.fire({ icon: 'error', title: error.message }); }
                });
                $('#offer-assets').on('click', '.asset-toggle', async function () {
                    const button = this;
                    try {
                        const body = await request(`${endpoint}/assets/${button.dataset.id}/status`, 'PATCH', { status: Number(button.dataset.status) });
                        window.swal?.fire({ icon: 'success', title: body.data });
                        table.ajax.reload(null, false);
                    } catch (error) { window.swal?.fire({ icon: 'error', title: error.message }); }
                });
                $('#offer-assets').on('click', '.asset-delete', async function () {
                    if (!window.swal?.fire) {
                        showError('The confirmation dialog is unavailable. Reload the page and try again.');
                        return;
                    }
                    const confirmation = await window.swal.fire({ icon: 'warning', title: 'Delete this asset?', showCancelButton: true, confirmButtonText: 'Delete' });
                    if (!confirmation.isConfirmed) return;
                    try {
                        const body = await request(`${endpoint}/assets/${this.dataset.id}`, 'DELETE');
                        window.swal?.fire({ icon: 'success', title: body.data });
                        table.ajax.reload(null, false);
                        refreshCounts();
                    } catch (error) { window.swal?.fire({ icon: 'error', title: error.message }); }
                });
            }
            window.closeAssetUpload = () => dialog?.modal('hide');
        });
    </script>
@endpush
