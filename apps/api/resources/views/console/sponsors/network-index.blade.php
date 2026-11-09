@extends('layouts.admin-shell')

@section('title', 'My Sponsors')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Card -->
        <header class="command-os-report-hero" aria-labelledby="my-sponsors-hero-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true">
                <i class="fas fa-handshake"></i>
            </span>
            <div class="command-os-report-hero-mark" aria-hidden="true">
                <i class="fas fa-handshake"></i>
            </div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">NETWORK SPONSORS // OPERATOR PORTAL</p>
                <h1 id="my-sponsors-hero-title">MY SPONSORS</h1>
                <p>Assigned affiliate networks, direct advertiser portals, and browser session helpers available to your operator account.</p>
            </div>
            <div class="command-os-hero-actions">
                <div class="command-os-report-hero-range">
                    <span>CLUSTER STATUS</span>
                    <strong><i class="fas fa-circle text-success" style="font-size: 8px;"></i> OPERATOR READY</strong>
                </div>
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="fas fa-expand"></i>
                </button>
            </div>
        </header>

        <!-- 2. KPI Overview Cards -->
        <section class="command-os-all-stats-overview" aria-label="My Sponsors Overview">
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-blue" aria-hidden="true"><i class="fas fa-handshake"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Assigned Sponsors</span>
                    <strong id="stat-network-sponsors-count">{{ isset($sponsors) ? $sponsors->count() : 0 }}</strong>
                    <small>Operator network access</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-puzzle-piece"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Session Helper</span>
                    <strong>{{ $extensionUrl ? 'READY' : 'LOCAL' }}</strong>
                    <small>Browser proxy bridge</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-emerald" aria-hidden="true"><i class="fas fa-id-badge"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Operator Access</span>
                    <strong>GRANTED</strong>
                    <small>Verified assignment</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-cyan" aria-hidden="true"><i class="fas fa-network-wired"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Active Offers</span>
                    <strong>{{ $sponsors->sum('offers_count') }}</strong>
                    <small>Available campaigns</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-amber" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Platform Status</span>
                    <strong>OPTIMAL</strong>
                    <small>Production ready</small>
                </span>
            </article>
        </section>

        <!-- 3. Info Banner -->
        <div class="command-os-info-banner" role="note">
            <div class="command-os-info-banner-icon" aria-hidden="true">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="command-os-info-banner-content">
                <div>Sponsor login opens an assigned provider session in the browser helper. Download and load the helper before using Login.</div>
                @if ($extensionUrl)
                    <a href="{{ $extensionUrl }}" class="btn btn-sm btn-alt-primary mt-1"><i class="fas fa-download mr-1"></i> Download sponsor helper</a>
                @else
                    <div class="small mt-1 text-warning"><i class="fas fa-exclamation-triangle mr-1"></i> The sponsor helper bundle is not installed on this app server, so download and browser handoff are unavailable.</div>
                @endif
                <div class="small mt-1 text-muted">The browser helper requests cookie access across HTTP and HTTPS websites. Review that browser permission scope before installing it.</div>
            </div>
        </div>

        <!-- 4. Table Card -->
        <div class="command-os-table-card">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="d-flex align-items-center">
                    <i class="fas fa-handshake text-purple font-size-lg mr-2"></i>
                    <h3 class="h6 font-w700 text-uppercase tracking-wider mb-0 text-ink">Assigned Network Sponsors Directory</h3>
                </div>
                <span class="badge badge-light border text-muted px-2 py-1 font-size-xs">
                    <i class="fas fa-user-check mr-1 text-purple"></i> Operator Scope
                </span>
            </div>
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

            const netTable = $('#network-sponsors').dataTable({
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
                    searchPlaceholder: 'Search sponsors...',
                    info: 'Page _PAGE_ of _PAGES_ (_TOTAL_ records)',
                    infoEmpty: 'Page 0 of 0 (0 records)',
                    paginate: {
                        previous: '<i class="fas fa-chevron-left"></i>',
                        next: '<i class="fas fa-chevron-right"></i>'
                    }
                },
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
                            action: (event, table) => table.ajax.reload(null, false),
                        },
                    ]
                },
                columns: [
                    { title: '#', data: 'id', render: (val) => `<span class="font-mono text-muted">#${escapeHtml(val)}</span>` },
                    { title: 'AFFILIATE ID', data: 'affiliate_id', render: (val) => val ? `<span class="badge badge-light border font-mono">${escapeHtml(val)}</span>` : '<span class="text-muted">—</span>' },
                    { title: 'NAME', data: 'name', render: (val) => `<strong class="text-ink">${escapeHtml(val)}</strong>` },
                    { title: 'TOTAL OFFERS', data: 'offers_count', render: (value, type, row) => type === 'display' ? `<a href="${@json(route('console.networks.offers.index', $workspace->id))}?sponsor=${escapeHtml(row.id)}" class="command-table-link"><span class="badge badge-light border text-purple px-2 py-1 font-w700">${Number(value || 0)} Offers</span></a>` : value },
                    { title: 'CREATED AT', data: 'created_at', render: (val) => val ? `<span class="text-muted text-nowrap font-size-xs">${escapeHtml(val.split(' ')[0] || val)}</span>` : '' },
                    {
                        title: 'URL',
                        data: 'url',
                        render: (url, type) => type === 'display' && url
                            ? `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" class="command-action-btn action-url" title="Open sponsor website" aria-label="Open sponsor website"><i class="fas fa-external-link-alt"></i></a>`
                            : (url || ''),
                    },
                    {
                        title: 'ACTIONS',
                        data: null,
                        orderable: false,
                        searchable: false,
                        render: (value, type, row) => `<div class="command-action-btn-group"><button type="button" data-id="${escapeHtml(row.id)}" class="command-action-btn action-login login" title="LOGIN SPONSOR" aria-label="Login to ${escapeHtml(row.name)}"><i class="fas fa-sign-in-alt"></i></button></div>`,
                    },
                ],
            });

            netTable.on('xhr.dt', function (e, settings, json) {
                if (json?.data) {
                    $('#stat-network-sponsors-count').text(json.data.length);
                }
            });

            $('#network-sponsors').on('click', '.login', async function (event) {
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
