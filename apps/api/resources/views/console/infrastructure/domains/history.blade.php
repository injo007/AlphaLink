@extends('layouts.standalone')

@section('title', 'Domain History')

@push('styles')
    <link rel="stylesheet" href="{{ asset('console-assets/plugins/datatables/datatables.min.css') }}">
    <style>
        .command-data-table textarea {
            background: transparent !important;
            border: 1px solid rgba(139, 92, 246, 0.2) !important;
            border-radius: 6px;
            font-size: 11px;
            color: inherit;
            resize: none;
        }
        html[data-command-theme='dark'] .command-data-table textarea {
            background: rgba(13, 18, 56, 0.6) !important;
            color: #e2e8f0 !important;
        }
    </style>
@endpush

@section('content')
    <div class="command-modal-surface">
        <div class="command-modal-hero">
            <div class="command-modal-hero-left">
                <div class="command-modal-hero-icon">
                    <i class="fas fa-history"></i>
                </div>
                <div>
                    <div class="command-modal-hero-eyebrow">DNS TELEMETRY // REPUTATION AUDIT</div>
                    <h3 class="command-modal-hero-title">
                        DOMAIN HISTORIES <span class="badge badge-purple ml-2">{{ $domain->name }}</span>
                    </h3>
                </div>
            </div>
        </div>

        <div class="command-modal-body">
            <div class="table-responsive">
                <table id="histories" class="command-data-table table-sm w-100"></table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('console-assets/plugins/datatables/datatables.min.js') }}"></script>
    <script src="{{ asset('console-assets/plugins/datatables/jquery.mark.min.js') }}"></script>
    <script src="{{ asset('console-assets/plugins/datatables/datatables.mark.js') }}"></script>
    <script src="{{ asset('console-assets/assets/js/tables_datatables.js') }}"></script>
    <script>
        $(function () {
            const formatBadge = (value) => {
                const cls = value ? 'badge-command-active-yes' : 'badge-command-active-no';
                const text = value ? 'LISTED' : 'CLEAN';
                return `<span class="badge ${cls}">${text}</span>`;
            };
            const textarea = (value) => {
                const element = document.createElement('textarea');
                element.rows = 2;
                element.className = 'form-control font-mono';
                element.readOnly = true;
                element.value = value ?? '';
                return element.outerHTML;
            };

            $('#histories').DataTable({
                ajax: @json(route('console.infrastructure.domains.history', [$workspace->id, $domain->id])),
                serverSide: true,
                dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>rt<"command-table-footer"ip>',
                buttons: [
                    {
                        text: '<i class="fas fa-sync mr-1"></i> REFRESH',
                        className: 'btn-command-action',
                        titleAttr: 'REFRESH',
                        action: function (event, table) { table.ajax.reload(null, false); }
                    }
                ],
                columns: [
                    {title: '#', data: 'id', render: (data) => `<span class="badge badge-light border font-mono">#${data}</span>`},
                    {title: 'A RECORD', data: 'a_record', render: (data) => `<span class="font-mono font-size-xs">${data || '-'}</span>`},
                    {title: 'SPF', data: 'spf', render: (data, type) => type === 'display' ? textarea(data) : data},
                    {title: 'DKIM', data: 'dkim', render: (data, type) => type === 'display' ? textarea(data) : data},
                    {title: 'DMARC', data: 'dmarc', render: (data, type) => type === 'display' ? textarea(data) : data},
                    {title: 'SPAMHAUS', data: 'is_listed_spamhaus', render: formatBadge, searchable: false},
                    {title: 'HOSTKARMA', data: 'is_listed_hostkarma', render: formatBadge, searchable: false},
                    {title: 'AUDITED AT', data: 'created_at', render: (data) => `<span class="text-muted font-size-xs">${data || '-'}</span>`}
                ]
            });
        });
    </script>
@endpush
