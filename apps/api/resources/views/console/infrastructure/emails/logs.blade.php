@extends('layouts.admin-shell')

@section('title', 'Email Logs')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Header Card -->
        <header class="command-os-report-hero mb-3" aria-labelledby="email-logs-hero-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true">
                <i class="fas fa-file-alt"></i>
            </span>
            <div class="command-os-report-hero-mark" aria-hidden="true">
                <i class="fas fa-file-alt"></i>
            </div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">AUDIENCE INTELLIGENCE // ACTIVITY AUDIT</p>
                <h1 id="email-logs-hero-title">CONTACT ACTIVITY LOGS</h1>
                <p>Campaign and Drop activity, click telemetry, and affiliate conversions for <span class="badge-command-pill">{{ $contact->email }}</span>.</p>
            </div>
            <div class="command-os-hero-actions">
                <a class="btn-command-action mr-2" href="{{ route('clean.console.infrastructure.emails.index') }}">
                    <i class="fa fa-arrow-left mr-1" aria-hidden="true"></i> SEARCH EMAILS
                </a>
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="fas fa-expand"></i>
                </button>
            </div>
        </header>

        <!-- 2. Table Card -->
        <div class="command-os-table-card">
            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                <h4 class="font-w700 text-uppercase font-size-sm mb-0">
                    <i class="fas fa-history mr-2 text-purple"></i> Event Telemetry Records
                </h4>
                <button type="button" id="email-logs-refresh" class="btn-command-action">
                    <i class="fas fa-sync-alt mr-1" aria-hidden="true"></i> REFRESH
                </button>
            </div>
            <div id="email-logs-status" class="alert alert-danger py-2 px-3 mb-3 font-size-sm" role="alert" hidden style="border-radius: 10px;"></div>
            <div class="table-responsive">
                <table id="email-logs" class="table command-os-table table-sm table-hover w-100" aria-label="Campaign and Drop contact logs"></table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.jQuery(function () {
            const $ = window.jQuery;
            const status = document.getElementById('email-logs-status');
            const escapeHtml = (value) => $('<div>').text(value == null ? '' : String(value)).html();
            const unavailable = (value, type) => type === 'display' ? escapeHtml(value ?? '—') : (value ?? '');
            const table = $.fn.DataTable ? $('#email-logs').DataTable({
                processing: true,
                serverSide: true,
                pageLength: 50,
                order: [[12, 'desc']],
                ajax: {
                    url: `${@json(route('console.infrastructure.emails.logs', [$workspace->id, $contact->id]))}?json=1`,
                    dataSrc: 'data',
                    error: (xhr) => {
                        status.textContent = xhr.responseJSON?.message || 'Email logs could not be loaded. Refresh to try again.';
                        status.hidden = false;
                    },
                },
                columns: [
                    {title:'#',data:'id',render:(value,type)=>type==='display'?`<span class="text-muted font-mono" style="font-size: 11px;">#${escapeHtml(value)}</span>`:value},
                    {title:'ACTION',data:'action',render:(value,type)=>type==='display'?`<span class="badge-command-pill" style="font-size: 10.5px;">${escapeHtml(value)}</span>`:value},
                    {title:'OFFER',data:'offer_id',render:(value,type)=>unavailable(value,type)},
                    {title:'IP',data:'ip',render:(value,type)=>type==='display'?`<span class="font-mono text-purple" style="font-size: 11px;">${escapeHtml(value ?? '—')}</span>`:value},
                    {title:'COUNTRY',data:'country',render:(value,type)=>type==='display'?`<span class="badge-command-pill" style="font-size: 10px;">${escapeHtml(value ?? '—')}</span>`:value},
                    {title:'STATE',data:'state',render:(value,type)=>unavailable(value,type)},
                    {title:'CITY',data:'city',render:(value,type)=>unavailable(value,type)},
                    {title:'VERTICAL',data:'vertical',render:(value,type)=>unavailable(value,type)},
                    {title:'DEVICE',data:'device',render:(value,type)=>unavailable(value,type)},
                    {title:'PLATFORM',data:'platform',render:(value,type)=>unavailable(value,type)},
                    {title:'BROWSER',data:'browser',render:(value,type)=>unavailable(value,type)},
                    {title:'MOBILE',data:'is_mobile',render:(value,type)=>type==='display'?(value===null?'—':(value?'<span class="text-success font-w600">Yes</span>':'<span class="text-muted">No</span>')):value},
                    {title:'TIMESTAMP',data:'created_at',render:(value,type)=>type==='display'?`<span class="text-muted font-mono" style="font-size: 11px;">${escapeHtml(value ?? '—')}</span>`:value},
                ],
                language: {
                    emptyTable: 'No campaign, Drop, or lead logs are recorded for this contact.',
                    lengthMenu: 'Show _MENU_ rows',
                    search: '',
                    searchPlaceholder: 'Filter logs...',
                    paginate: {
                        previous: '<i class="fas fa-chevron-left"></i>',
                        next: '<i class="fas fa-chevron-right"></i>'
                    }
                },
            }) : null;
            document.getElementById('email-logs-refresh').addEventListener('click', () => {
                status.hidden = true;
                table?.ajax.reload(null, false);
            });
        });
    </script>
@endpush
