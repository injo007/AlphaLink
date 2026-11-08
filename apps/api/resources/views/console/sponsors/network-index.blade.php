@extends('layouts.admin-shell')

@section('title', 'My Sponsors')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Card -->
        <header class="command-os-hero-card">
            <div class="command-os-hero-card-left">
                <div class="command-os-hero-mark" aria-hidden="true">
                    <i class="fas fa-users"></i>
                </div>
                <div class="command-os-hero-copy">
                    <span class="command-os-hero-eyebrow">MY SPONSORS</span>
                    <h1 class="command-os-hero-title">MY SPONSORS</h1>
                </div>
            </div>
            <div class="command-os-hero-card-right">
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="si si-size-fullscreen"></i>
                </button>
            </div>
        </header>

        <!-- 2. Info Banner -->
        <div class="command-os-info-banner" role="note">
            <div class="command-os-info-banner-icon" aria-hidden="true">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="command-os-info-banner-content">
                <div>Sponsor login opens an assigned provider session in the browser helper. Download and load the helper before using Login.</div>
                @if ($extensionUrl)
                    <a href="{{ $extensionUrl }}" class="btn btn-sm btn-alt-primary mt-1">Download sponsor helper</a>
                @else
                    <div class="small mt-1 text-warning">The sponsor helper bundle is not installed on this app server, so download and browser handoff are unavailable.</div>
                @endif
                <div class="small mt-1">The browser helper requests cookie access across HTTP and HTTPS websites. Review that browser permission scope before installing it.</div>
            </div>
        </div>

        <!-- 3. Table Card -->
        <div class="command-os-table-card">
            <table id="network-sponsors" class="table table-sm table-hover"
                   data-source-url="{{ route('console.networks.sponsors.index', $workspace->id) }}"
                   data-login-url="{{ route('console.sponsors.login', [$workspace->id, 0]) }}"
                   data-extension-id="{{ $extensionId }}"></table>
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
                pageLength: 20,
                order: [[0, 'desc']],
                dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>' +
                     '<"table-responsive"t>' +
                     '<"command-table-footer"ip>',
                language: {
                    lengthMenu: 'Show _MENU_ rows',
                    search: '',
                    searchPlaceholder: 'Search...',
                    info: 'Page _PAGE_ of _PAGES_ (_TOTAL_ records)',
                    infoEmpty: 'Page 0 of 0 (0 records)',
                    paginate: {
                        previous: '<i class="fas fa-chevron-left"></i>',
                        next: '<i class="fas fa-chevron-right"></i>'
                    }
                },
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
                    { title: 'TOTAL OFFERS', data: 'offers_count', render: (value, type, row) => type === 'display' ? `<a href="${@json(route('console.networks.offers.index', $workspace->id))}?sponsor=${escapeHtml(row.id)}" class="command-table-link">${Number(value || 0)}</a>` : value },
                    { title: 'CREATED AT', data: 'created_at' },
                    {
                        title: 'URL',
                        data: 'url',
                        render: (url, type) => type === 'display'
                            ? `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" class="command-table-link" aria-label="Open sponsor website"><i class="fas fa-link"></i></a>`
                            : url,
                    },
                    {
                        title: 'ACTIONS',
                        data: null,
                        orderable: false,
                        searchable: false,
                        render: (value, type, row) => `<div class="command-action-btn-group"><button type="button" data-id="${escapeHtml(row.id)}" class="command-action-btn action-login login" title="LOGIN SPONSOR" aria-label="Login to ${escapeHtml(row.name)}"><i class="fas fa-poll"></i></button></div>`,
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
