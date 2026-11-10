@extends('layouts.admin-shell')

@section('title', 'Subscribers')

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-users"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">OPERATIONS TOOLKIT // AUDIENCE SUBSCRIBERS</div>
                    <h1 class="command-os-report-hero-title">WORKSPACE SUBSCRIBERS</h1>
                    <p class="command-os-report-hero-desc">Directory of captured subscriber records, geo-locations, device platforms, and registration timestamps.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    <span class="command-os-report-badge">AUDIENCE</span>
                </div>
            </div>
        </header>

        <!-- 2. Data Table Card -->
        <div class="command-os-table-card p-4">
            <div class="table-responsive">
                <table id="subscribers" class="table table-sm table-hover table-striped command-os-table w-100">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">EMAIL</th>
                            <th scope="col">COUNTRY</th>
                            <th scope="col">STATE</th>
                            <th scope="col">CITY</th>
                            <th scope="col">DEVICE</th>
                            <th scope="col">PLATFORM</th>
                            <th scope="col">BROWSER</th>
                            <th scope="col">IS MOBILE</th>
                            <th scope="col">CREATED AT</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.jQuery(function () {
            const $ = window.jQuery;
            $('#subscribers').DataTable({
                ajax: @json(route('console.miscellaneous.subscribers', $workspace->id)),
                serverSide: true,
                dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>rt<"command-table-footer"ip>',
                buttons: [
                    { extend: 'pageLength', className: 'btn-command-action' },
                    { text: '<i class="fas fa-sync mr-1"></i> REFRESH', className: 'btn-command-action', titleAttr: 'REFRESH', action: (event, table) => table.ajax.reload(null, false) },
                ],
                columns: [
                    { title: '#', data: 'id', render: (val, type) => type === 'display' ? `<span class="command-mono-badge">#${val}</span>` : val },
                    { title: 'EMAIL', data: 'email', render: (val, type) => type === 'display' ? `<strong>${$.fn.dataTable.render.text().display(val)}</strong>` : val },
                    { title: 'COUNTRY', data: 'country', render: $.fn.dataTable.render.text() },
                    { title: 'STATE', data: 'state', render: $.fn.dataTable.render.text() },
                    { title: 'CITY', data: 'city', render: $.fn.dataTable.render.text() },
                    { title: 'DEVICE', data: 'device', render: $.fn.dataTable.render.text() },
                    { title: 'PLATFORM', data: 'platform', render: $.fn.dataTable.render.text() },
                    { title: 'BROWSER', data: 'browser', render: $.fn.dataTable.render.text() },
                    { title: 'IS MOBILE', data: 'is_mobile', render: data => data ? '<span class="badge-command-pill" style="color: #8b5cf6;">YES</span>' : '<span class="badge-command-pill" style="color: #64748b;">NO</span>' },
                    { title: 'CREATED AT', data: 'created_at', render: (val, type) => type === 'display' ? `<span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">${$.fn.dataTable.render.text().display(val)}</span>` : val },
                ],
                language: {
                    searchPlaceholder: 'Search subscribers…',
                    emptyTable: 'No subscriber records are available for this workspace.'
                },
            });
        });
    </script>
@endpush
