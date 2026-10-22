@extends('layouts.dms-admin')

@section('title', 'Servers - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Servers</h3>
            <div class="block-options">
                <div class="custom-control custom-switch custom-control-danger custom-control-inline">
                    <input type="checkbox" class="custom-control-input" id="returned" aria-label="Show returned servers">
                    <label id="label_auto" class="custom-control-label" for="returned">RETURNED</label>
                </div>
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <div class="table-responsive">
                <table id="servers" class="table table-sm table-hover table-striped"
                       data-source-url="{{ $serversDataUrl }}"
                       data-create-url="{{ $createUrl }}"
                       data-return-url="{{ route('console.infrastructure.servers.return', $workspace->id) }}"
                       data-bulk-users-url="{{ route('console.infrastructure.servers.bulk-users', $workspace->id) }}"
                       data-status-url-template="{{ route('console.infrastructure.servers.status', [$workspace->id, '__SERVER__']) }}"
                       data-lock-url-template="{{ route('console.infrastructure.servers.lock', [$workspace->id, '__SERVER__']) }}"
                       data-restore-url-template="{{ route('console.infrastructure.servers.restore', [$workspace->id, '__SERVER__']) }}"
                       data-history-url-template="{{ route('console.infrastructure.servers.history', [$workspace->id, '__SERVER__']) }}"
                       data-users-url-template="{{ route('console.infrastructure.servers.users', [$workspace->id, '__SERVER__']) }}"
                       data-can-manage="{{ $canManageServers ? 'true' : 'false' }}"
                       data-can-return="{{ $canReturnServers ? 'true' : 'false' }}"
                       data-returned="false"></table>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        #servers { table-layout: fixed; width: 100% !important; }
        #servers th, #servers td { box-sizing: border-box; }
        #servers th { font-size: .72rem; padding-left: .2rem; padding-right: .2rem; white-space: nowrap; }
        #servers td { overflow-wrap: anywhere; }
        #servers td:nth-child(5) { font-size: .72rem; white-space: nowrap; }
    </style>
@endpush
