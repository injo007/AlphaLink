@extends('layouts.dms-admin')

@section('title', 'Subscribers - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">subscribers</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <div class="table-responsive">
                <table id="subscribers" class="table table-sm table-hover table-striped">
                    <thead><tr><th scope="col">#</th><th scope="col">email</th><th scope="col">country</th><th scope="col">state</th><th scope="col">city</th><th scope="col">device</th><th scope="col">platform</th><th scope="col">browser</th><th scope="col">is mobile</th><th scope="col">CREATED AT</th></tr></thead>
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
                buttons: [
                    { extend: 'pageLength' },
                    { text: '<i class="fas fa-sync"></i>', titleAttr: 'REFRESH', action: (event, table) => table.ajax.reload(null, false) },
                ],
                columns: [
                    { title: '#', data: 'id', render: $.fn.dataTable.render.text() },
                    { title: 'email', data: 'email', render: $.fn.dataTable.render.text() },
                    { title: 'country', data: 'country', render: $.fn.dataTable.render.text() },
                    { title: 'state', data: 'state', render: $.fn.dataTable.render.text() },
                    { title: 'city', data: 'city', render: $.fn.dataTable.render.text() },
                    { title: 'device', data: 'device', render: $.fn.dataTable.render.text() },
                    { title: 'platform', data: 'platform', render: $.fn.dataTable.render.text() },
                    { title: 'browser', data: 'browser', render: $.fn.dataTable.render.text() },
                    { title: 'is mobile', data: 'is_mobile', render: data => data ? 'YES' : 'NO' },
                    { title: 'CREATED AT', data: 'created_at', render: $.fn.dataTable.render.text() },
                ],
                language: { emptyTable: 'No subscriber records are available for this workspace.' },
            });
        });
    </script>
@endpush
