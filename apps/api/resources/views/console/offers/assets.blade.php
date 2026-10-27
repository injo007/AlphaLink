@extends('layouts.dms-admin')

@section('title', 'Offer Assets - DMS')

@section('content')
    <div class="content-heading">Offer <span class="text-info">{{ $offer->id }} | {{ $offer->offer_id }} | {{ $offer->name }}</span> Assets</div>
    <div class="block block-rounded">
        <div class="block-content">
            <table class="table table-sm table-bordered" style="table-layout: fixed;word-wrap: break-word;">
                <tr>
                    <th>Redirect Link:</th>
                    <td><a href="{{ $offer->redirect }}" target="_blank" rel="noopener noreferrer">{{ $offer->redirect }}</a></td>
                    <th>Unsubscribe Link:</th>
                    <td>@if ($offer->unsubscribe)<a href="{{ $offer->unsubscribe }}" target="_blank" rel="noopener noreferrer">{{ $offer->unsubscribe }}</a>@endif</td>
                </tr>
                <tr>
                    <th>Geo Target:</th>
                    <td>{{ implode('-', $offer->geo_target ?? []) }}</td>
                    <th>Allowed Days:</th>
                    <td>{{ implode('-', $offer->allowed_days ?? []) }}</td>
                </tr>
                <tr><th>Note:</th><td style="white-space: pre-wrap;" colspan="3">{{ $offer->note }}</td></tr>
            </table>
        </div>
    </div>
    <div class="block block-rounded">
        <ul class="nav nav-tabs nav-tabs-block" id="offer-asset-tabs" role="tablist">
            @foreach (['from' => 'FROM', 'subjects' => 'SUBJECTS', 'creatives' => 'CREATIVES', 'unsubscribe' => 'UNSUBSCRIBE', 'html' => 'HTML'] as $assetType => $label)
                <li class="nav-item">
                    <a class="nav-link {{ $loop->first ? 'active' : '' }}" href="#{{ $assetType }}" data-asset-type="{{ $assetType }}" role="tab">
                        {{ $label }} <span id="{{ $assetType }}-count" class="badge badge-pill badge-info">{{ $assetCounts[$assetType] ?? 0 }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
        <div class="block-content tab-content">
            @if (! $isNetworkView)
                <div class="alert alert-info" role="status">Creative images and the single replaceable unsubscribe image are stored privately in this workspace. HTML assets are supplied by affiliate offer import; that provider import flow remains unavailable until its DMS adapters are implemented.</div>
                <div id="asset-result" class="alert alert-danger d-none" role="alert" aria-live="assertive"></div>
            @endif
            <div class="table-responsive">
                <table id="offer-assets" class="table table-sm table-hover table-striped"
                       data-source-url="{{ route('console.offers.assets', [$workspace->id, $offer->id]) }}"
                       data-manage="{{ $isNetworkView ? '0' : '1' }}"
                       data-upload-creative-url="{{ route('console.offers.assets.upload-form', [$workspace->id, $offer->id, 'creative']) }}"
                       data-upload-unsubscribe-url="{{ route('console.offers.assets.upload-form', [$workspace->id, $offer->id, 'unsubscribe']) }}"
                       data-bulk-status-url="{{ route('console.offers.assets.bulk-status', [$workspace->id, $offer->id]) }}"></table>
            </div>
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
                        document.querySelector(`#${activeType}-count`).textContent = response.count ?? response.data?.length ?? 0;
                        return response.data ?? [];
                    },
                },
                serverSide: false,
                pageLength: 100,
                ...(canManage ? {
                    select: { style: 'os', selector: 'td:first-child' },
                    buttons: [
                        { extend: 'pageLength' },
                        {
                            text: '<i class="fas fa-sync"></i>',
                            titleAttr: 'REFRESH',
                            action: (event, dataTable) => dataTable.ajax.reload(null, false),
                        },
                        {
                            text: '<i class="fa fa-plus"></i>',
                            titleAttr: 'ADD NEW FROM',
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
                            text: '<i class="fa fa-times"></i>',
                            titleAttr: 'DISABLE SELECTED ASSETS',
                            action: () => changeSelectedStatus(false),
                        },
                        {
                            text: '<i class="fas fa-check"></i>',
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
                    { title: '#', data: 'id' },
                    {
                        title: 'VALUE',
                        data: 'value',
                        render: (value, type, row) => {
                            if (type !== 'display') return value;
                            if (row.file_url) return `<a href="${escapeHtml(row.file_url)}" target="_blank" rel="noopener noreferrer"><img loading="lazy" src="${escapeHtml(row.file_url)}" alt="Offer ${escapeHtml(row.type)} asset" style="width: 100px;height: 100px;object-fit: contain;"></a>`;
                            if (row.type === 'html') return `<textarea class="form-control html-preview" rows="10" readonly aria-label="HTML creative; click to preview">${escapeHtml(value)}</textarea>`;
                            return escapeHtml(value);
                        },
                    },
                    { title: 'STATUS', data: 'is_active', render: (active) => active ? '<span class="badge badge-success">YES</span>' : '<span class="badge badge-danger">NO</span>' },
                    { title: 'CREATED AT', data: 'created_at', defaultContent: '' },
                    ...(canManage ? [{
                        title: 'ACTIONS', data: null, orderable: false, searchable: false,
                        render: (data, type, row) => {
                            const actions = [];
                            if (['from', 'subject'].includes(row.type)) {
                                actions.push(`<button class="btn btn-sm btn-info asset-edit" type="button" data-id="${row.id}" data-value="${escapeHtml(row.value)}" title="EDIT"><i class="fas fa-pencil-alt"></i></button>&nbsp;`);
                                actions.push(`<button class="btn btn-sm btn-danger asset-delete" type="button" data-id="${row.id}" title="DELETE"><i class="fas fa-trash"></i></button>&nbsp;`);
                            }
                            if (['from', 'subject', 'creative', 'html'].includes(row.type)) {
                                actions.push(`<button class="btn btn-sm ${row.is_active ? 'btn-warning' : 'btn-success'} asset-toggle" type="button" data-id="${row.id}" data-status="${row.is_active ? 0 : 1}" title="${row.is_active ? 'DISABLE' : 'ENABLE'}"><i class="fas ${row.is_active ? 'fa-times' : 'fa-check'}"></i></button>`);
                            }
                            return actions.join('');
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
                        if (response.ok) document.querySelector(`#${type}-count`).textContent = (await response.json()).count ?? 0;
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
