@extends('layouts.admin-shell')

@section('title', 'My IPs')

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-network-wired"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">NETWORK ASSETS // REPUTATION TELEMETRY</div>
                    <h1 class="command-os-report-hero-title">MY IPS</h1>
                    <p class="command-os-report-hero-desc">Active IP allocations, reverse DNS PTR mappings, sender reputation scores, and blacklist telemetry across assigned servers.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    <span class="command-os-report-badge">IP POOL AUDIT</span>
                </div>
            </div>
        </header>

        <!-- 2. Table Card -->
        <div class="command-os-table-card">
            <div class="alert alert-info mx-3 mt-3 mb-2 py-2" role="note" style="border-radius: 10px; font-size: 0.8125rem;">
                <i class="fas fa-info-circle mr-1"></i> PTR, sender score, and blacklist matrices reflect the latest automated telemetry audit snapshot.
            </div>

            <div class="table-responsive">
                <table id="my-ips" class="table table-sm table-hover table-striped command-os-table"
                       data-source-url="{{ $ipsUrl }}"
                       data-webmail-url-template="{{ $webmailUrlTemplate }}"
                       data-export-url="{{ $exportUrl }}"
                       aria-label="Workspace IP addresses"></table>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        #my-ips { min-width: 920px; }
        #my-ips .ip-copy { border: 0; background: transparent; color: #8b5cf6; cursor: pointer; padding: 0 .2rem; }
        #my-ips .ip-badge { display: inline-block; margin: .08rem; }
    </style>
@endpush

@push('scripts')
    <script>
        (function () {
            const table = document.getElementById('my-ips');
            if (!table || !window.jQuery || !jQuery.fn.dataTable) return;

            const escapeHtml = (value) => String(value == null ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            const safeHostUrl = (value) => {
                const host = String(value == null ? '' : value).trim();
                if (!host || host.indexOf('/') >= 0 || host.indexOf('?') >= 0 || host.indexOf('#') >= 0
                    || host.indexOf('@') >= 0 || /\s/.test(host)) return '';
                const candidate = host.indexOf(':') >= 0 && host.charAt(0) !== '[' ? '[' + host + ']' : host;
                try {
                    const parsed = new URL(window.location.protocol + '//' + candidate);
                    return (parsed.protocol === 'https:' || parsed.protocol === 'http:') ? parsed.href : '';
                } catch (error) {
                    return '';
                }
            };
            const copyButton = (value, label) => '<button type="button" class="ip-copy" data-copy="' + escapeHtml(value) + '" title="Copy ' + escapeHtml(label) + '" aria-label="Copy ' + escapeHtml(label) + '"><i class="fa fa-clone" aria-hidden="true"></i></button>';
            const hostCell = (value, label) => {
                const text = String(value == null ? '' : value);
                const url = safeHostUrl(text);
                const link = url
                    ? '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer" style="font-family: monospace; font-weight: 600; color: #6366f1;">' + escapeHtml(text) + '</a>'
                    : '<span style="font-family: monospace; font-weight: 600; color: #6366f1;">' + escapeHtml(text || 'NA') + '</span>';
                return link + copyButton(text || 'NA', label);
            };
            const statusBadge = (value, label) => {
                const isListed = value === true;
                const style = isListed
                    ? 'background: rgba(239, 68, 68, 0.2); border: 1px solid rgba(239, 68, 68, 0.5); color: #f87171; font-weight: 800; font-size: 9px; padding: 2px 7px;'
                    : 'background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; font-weight: 700; font-size: 9px; padding: 2px 7px;';
                return `<span class="badge-command-pill ip-badge" style="${style}" title="${escapeHtml(label)}: ${isListed ? 'LISTED (ALERT)' : 'CLEAN'}">${isListed ? 'YES' : 'NO'}</span>`;
            };
            const historyValue = (history, key) => history && history[key] != null ? history[key] : '';
            const ajaxUrl = new URL(table.dataset.sourceUrl, window.location.href);
            @if (request()->query('server_id'))
                ajaxUrl.searchParams.set('server_id', @json((string) request()->query('server_id')));
            @endif

            const dataTable = jQuery(table).DataTable({
                ajax: { url: ajaxUrl.toString(), dataSrc: 'data' },
                order: [[0, 'desc']],
                pageLength: 50,
                responsive: false,
                dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>rt<"command-table-footer"ip>',
                buttons: [
                    { extend: 'pageLength', className: 'btn-command-action' },
                    {
                        text: '<i class="fas fa-file-export mr-1"></i> EXPORT',
                        className: 'btn-command-action',
                        action: function () {
                            window.location.assign(table.dataset.exportUrl);
                        }
                    },
                    {
                        text: '<i class="fas fa-sync mr-1" aria-hidden="true"></i> REFRESH',
                        className: 'btn-command-action',
                        titleAttr: 'Refresh',
                        action: function (event, dt) { dt.ajax.reload(null, false); }
                    }
                ],
                columns: [
                    {
                        title: '#', data: 'id', width: '5%',
                        render: function (id, type) {
                            return type === 'display' ? '<span class="command-mono-badge">#' + escapeHtml(id) + '</span>' : id;
                        }
                    },
                    {
                        title: 'SERVER',
                        data: 'server',
                        type: 'natural',
                        render: function (server, type) {
                            const text = (server && server.name ? server.name : '') + (server && server.id ? server.id : '');
                            return type === 'display' ? '<strong style="color: var(--command-os-text, #1e293b);">' + escapeHtml(text) + '</strong>' : text;
                        }
                    },
                    {
                        title: 'IP',
                        data: 'ip',
                        type: 'ip-address',
                        render: function (value, type) { return type === 'display' ? hostCell(value, 'IP address') : value; }
                    },
                    {
                        title: 'TYPE',
                        data: 'type',
                        render: function (value) { return '<span class="badge-command-pill">' + escapeHtml(value || 'N/A') + '</span>'; }
                    },
                    {
                        title: 'DOMAIN',
                        data: 'dns',
                        render: function (value, type) {
                            const text = String(value == null ? '' : value);
                            const url = safeHostUrl(text);
                            const link = url
                                ? '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer" style="font-weight: 600; color: #8b5cf6;">' + escapeHtml(text) + '</a>'
                                : '<span style="font-weight: 600; color: #8b5cf6;">' + escapeHtml(text || 'NA') + '</span>';
                            return link + copyButton(text || 'NA', 'domain');
                        }
                    },
                    {
                        title: 'PTR',
                        data: 'history',
                        render: function (history, type) {
                            const value = historyValue(history, 'ptr') || 'NA';
                            return type === 'display' ? '<span style="font-family: monospace; font-size: 0.8125rem;">' + escapeHtml(value) + '</span>' + copyButton(value, 'PTR') : value;
                        }
                    },
                    {
                        title: 'SCORE',
                        data: 'history',
                        render: function (history) {
                            const score = historyValue(history, 'sender_score');
                            return score ? '<span class="badge-command-pill" style="font-weight: 700; color: #10b981;">' + escapeHtml(score) + '</span>' : '<span class="text-muted">NA</span>';
                        }
                    },
                    {
                        title: 'WEBMAIL',
                        data: 'id',
                        orderable: false,
                        searchable: false,
                        render: function (id, type, row) {
                            if (type !== 'display') {
                                return id;
                            }
                            const template = table.dataset.webmailUrlTemplate || '';
                            const url = template.replace('__IP__', encodeURIComponent(String(id)));
                            const domain = row && row.dns ? String(row.dns) : 'IP';
                            return '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer" class="command-action-btn" title="Open Webmail for ' + escapeHtml(domain) + '" aria-label="Open Webmail for ' + escapeHtml(domain) + '"><i class="far fa-envelope" aria-hidden="true"></i></a>';
                        }
                    },
                    {
                        title: 'BLACKLISTED',
                        data: 'history',
                        orderable: false,
                        searchable: false,
                        render: function (history) {
                            const value = history || {};
                            return statusBadge(value.ip_listed_spamhaus, 'IP Spamhaus Zen')
                                + statusBadge(value.ip_listed_spamcop, 'IP SpamCop')
                                + statusBadge(value.ip_listed_barracuda, 'IP Barracuda')
                                + '<br>'
                                + statusBadge(value.domain_listed_hostkarma, 'Domain Hostkarma')
                                + statusBadge(value.domain_listed_spamhaus_dbl, 'Domain Spamhaus DBL')
                                + statusBadge(value.domain_listed_surbl, 'Domain SURBL');
                        }
                    }
                ]
            });

            jQuery(table).on('click', '[data-copy]', async function () {
                const value = this.dataset.copy || '';
                try {
                    await navigator.clipboard.writeText(value);
                    this.title = 'Copied';
                } catch (error) {
                    const temporary = document.createElement('textarea');
                    temporary.value = value;
                    temporary.setAttribute('readonly', '');
                    temporary.style.position = 'fixed';
                    temporary.style.opacity = '0';
                    document.body.appendChild(temporary);
                    temporary.select();
                    document.execCommand('copy');
                    temporary.remove();
                    this.title = 'Copied';
                }
            });
        })();
    </script>
@endpush
