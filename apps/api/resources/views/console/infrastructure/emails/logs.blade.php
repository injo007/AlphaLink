@extends('layouts.dms-admin')

@section('title', 'Email Logs - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title"><span class="text-info">{{ $contact->email }}</span> LOGS</h3>
            <div class="block-options">
                <a class="btn btn-sm btn-alt-secondary" href="{{ route('console.infrastructure.emails.index', $workspace->id) }}"><i class="fa fa-arrow-left mr-1" aria-hidden="true"></i> SEARCH EMAILS</a>
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <p class="text-muted">Campaign and Drop activity, including recorded affiliate leads, for this workspace contact.</p>
            <div class="d-flex justify-content-end mb-2">
                <button type="button" id="email-logs-refresh" class="btn btn-sm btn-alt-primary"><i class="fa fa-sync mr-1" aria-hidden="true"></i> REFRESH</button>
            </div>
            <div id="email-logs-status" class="alert alert-danger" role="alert" hidden></div>
            <div class="table-responsive">
                <table id="email-logs" class="table table-sm table-hover table-striped w-100" aria-label="Campaign and Drop contact logs"></table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.jQuery(function () {
            const $ = window.jQuery;
            const tableElement = document.getElementById('email-logs');
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
                    {title:'#',data:'id',render:(value,type)=>unavailable(value,type)},
                    {title:'action',data:'action',render:(value,type)=>unavailable(value,type)},
                    {title:'offer',data:'offer_id',render:(value,type)=>unavailable(value,type)},
                    {title:'ip',data:'ip',render:(value,type)=>unavailable(value,type)},
                    {title:'country',data:'country',render:(value,type)=>unavailable(value,type)},
                    {title:'state',data:'state',render:(value,type)=>unavailable(value,type)},
                    {title:'city',data:'city',render:(value,type)=>unavailable(value,type)},
                    {title:'vertical',data:'vertical',render:(value,type)=>unavailable(value,type)},
                    {title:'device',data:'device',render:(value,type)=>unavailable(value,type)},
                    {title:'platform',data:'platform',render:(value,type)=>unavailable(value,type)},
                    {title:'browser',data:'browser',render:(value,type)=>unavailable(value,type)},
                    {title:'is mobile',data:'is_mobile',render:(value,type)=>type==='display'?(value===null?'—':(value?'Yes':'No')):value},
                    {title:'at',data:'created_at',render:(value,type)=>unavailable(value,type)},
                ],
                language:{emptyTable:'No campaign, Drop, or lead logs are recorded for this contact.'},
            }) : null;
            document.getElementById('email-logs-refresh').addEventListener('click', () => {
                status.hidden = true;
                table?.ajax.reload(null, false);
            });
        });
    </script>
@endpush
