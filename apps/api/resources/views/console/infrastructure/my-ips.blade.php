@extends('layouts.dms-admin')

@section('title', 'My IPs - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">My IPs</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <p class="text-muted mb-3">Active IPs on active, installed servers in this workspace. PTR, sender score and blacklist badges use the latest saved local history snapshot.</p>
            <div class="alert alert-info py-2" role="note">
                Webmail access is not enabled here. The DMS shortcut creates an external SSO session and changes remote mail-service and PMTA queue state.
            </div>
            <div class="table-responsive">
                <table id="my-ips" class="table table-sm table-hover table-striped"
                       data-source-url="{{ $ipsUrl }}"
                       data-export-url="{{ $exportUrl }}"
                       aria-label="Workspace IP addresses"></table>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        #my-ips { min-width: 920px; }
        #my-ips .ip-copy { border: 0; background: transparent; color: #4c78a8; cursor: pointer; padding: 0 .2rem; }
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
                const link = url ? '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(text) + '</a>' : escapeHtml(text || 'NA');
                return link + copyButton(text || 'NA', label);
            };
            const statusBadge = (value, label) => {
                const isListed = value === true;
                return '<span class="badge ip-badge ' + (isListed ? 'badge-danger' : 'badge-success') + '" title="' + escapeHtml(label) + '">' + (isListed ? 'YES' : 'NO') + '</span>';
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
                dom: 'Bfrtip',
                buttons: [
                    { extend: 'pageLength' },
                    {
                        text: 'EXPORT',
                        action: function () {
                            window.location.assign(table.dataset.exportUrl);
                        }
                    },
                    {
                        text: '<i class="fas fa-sync" aria-hidden="true"></i>',
                        titleAttr: 'Refresh',
                        action: function (event, dt) { dt.ajax.reload(null, false); }
                    }
                ],
                columns: [
                    { title: '#', data: 'id', width: '5%' },
                    {
                        title: 'server',
                        data: 'server',
                        type: 'natural',
                        render: function (server, type) {
                            const text = (server && server.name ? server.name : '') + (server && server.id ? server.id : '');
                            return type === 'display' ? escapeHtml(text) : text;
                        }
                    },
                    {
                        title: 'ip',
                        data: 'ip',
                        type: 'ip-address',
                        render: function (value, type) { return type === 'display' ? hostCell(value, 'IP address') : value; }
                    },
                    { title: 'type', data: 'type' },
                    {
                        title: 'domain',
                        data: 'dns',
                        render: function (value, type) { return type === 'display' ? hostCell(value, 'domain') : value; }
                    },
                    {
                        title: 'ptr',
                        data: 'history',
                        render: function (history, type) {
                            const value = historyValue(history, 'ptr') || 'NA';
                            return type === 'display' ? escapeHtml(value) + copyButton(value, 'PTR') : value;
                        }
                    },
                    {
                        title: 'score',
                        data: 'history',
                        render: function (history) { return escapeHtml(historyValue(history, 'sender_score') || 'NA'); }
                    },
                    {
                        title: 'webmail',
                        data: 'id',
                        orderable: false,
                        searchable: false,
                        render: function () {
                            return '<button type="button" class="btn btn-sm btn-link" title="Unavailable: remote SSO and PMTA actions are not implemented" aria-label="Webmail unavailable" disabled><i class="far fa-envelope" aria-hidden="true"></i></button>';
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
