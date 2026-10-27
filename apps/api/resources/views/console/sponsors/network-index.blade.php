@extends('layouts.dms-admin')

@section('title', 'My Sponsors - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">My Sponsors</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content">
            <div class="alert alert-info" role="note">
                DMS-style sponsor login opens an assigned provider session in the browser helper. Download and load the helper before using Login.
                <a href="{{ $extensionUrl }}" class="btn btn-sm btn-alt-primary ml-2">Download sponsor helper</a>
                <div class="small mt-2">The reviewed DMS helper requests cookie access across HTTP and HTTPS websites. Review that browser permission scope before installing it.</div>
            </div>
            <div class="table-responsive">
                <table id="network-sponsors" class="table table-sm table-hover table-striped"
                       data-source-url="{{ route('console.networks.sponsors.index', $workspace->id) }}"
                       data-login-url="{{ route('console.sponsors.login', [$workspace->id, 0]) }}"
                       data-extension-id="{{ $extensionId }}"></table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.jQuery(function () {
            const $ = window.jQuery;
            const tableElement = document.querySelector('#network-sponsors');
            const escapeHtml = (value) => $('<div>').text(value == null ? '' : String(value)).html();

            $('#network-sponsors').dataTable({
                ajax: tableElement.dataset.sourceUrl,
                serverSide: false,
                order: [[0, 'desc']],
                buttons: [
                    { extend: 'pageLength' },
                    {
                        text: '<i class="fas fa-sync"></i>',
                        titleAttr: 'REFRESH',
                        action: (event, table) => table.ajax.reload(null, false),
                    },
                ],
                columns: [
                    { title: '#', data: 'id' },
                    { title: 'AFFILIATE ID', data: 'affiliate_id', defaultContent: '' },
                    { title: 'NAME', data: 'name' },
                    { title: 'TOTAL OFFERS', data: 'offers_count', render: (value, type, row) => type === 'display' ? `<a href="${@json(route('console.networks.offers.index', $workspace->id))}?sponsor=${escapeHtml(row.id)}">${Number(value || 0)}</a>` : value },
                    { title: 'CREATED AT', data: 'created_at' },
                    {
                        title: 'URL',
                        data: 'url',
                        render: (url, type) => type === 'display'
                            ? `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" aria-label="Open sponsor website"><i class="fas fa-link"></i></a>`
                            : url,
                    },
                    {
                        title: 'ACTIONS',
                        data: null,
                        orderable: false,
                        searchable: false,
                        render: (value, type, row) => `<button type="button" data-id="${escapeHtml(row.id)}" class="btn btn-info btn-sm login" title="LOGIN SPONSOR" aria-label="Login to ${escapeHtml(row.name)}"><i class="fas fa-door-open"></i></button>&nbsp;`,
                    },
                ],
            }).on('click', '.login', async function (event) {
                event.preventDefault();
                const button = this;
                const priorHtml = button.innerHTML;
                const showError = (title) => window.swal?.fire
                    ? window.swal.fire({ icon: 'error', title })
                    : window.alert(title);
                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
                try {
                    const extensionId = tableElement.dataset.extensionId;
                    if (!window.App?.pingExtension || !await window.App.pingExtension(extensionId)) {
                        await showError('Sponsor helper is not installed or enabled. Download it above, extract it, and load it from your browser extensions page.');
                        return;
                    }
                    const loginUrl = tableElement.dataset.loginUrl.replace(/\/0\/login$/, `/${encodeURIComponent(button.dataset.id)}/login`);
                    const response = await fetch(loginUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                    });
                    const result = await response.json();
                    if (!response.ok || result.status !== true) {
                        await showError(result.message || 'Sponsor login could not be completed.');
                        return;
                    }
                    const extensionResult = await window.App.commandExtension(extensionId, { type: 'connect', data: result });
                    if (extensionResult?.status !== 'ok') {
                        await showError('The browser helper did not confirm the sponsor session handoff.');
                    }
                } catch (error) {
                    await showError(error.message || 'Sponsor login could not be completed.');
                } finally {
                    button.disabled = false;
                    button.innerHTML = priorHtml;
                }
            });
        });
    </script>
@endpush
