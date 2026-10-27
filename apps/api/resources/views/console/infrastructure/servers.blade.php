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
                       data-edit-url-template="{{ $editUrlTemplate }}"
                       data-return-url="{{ route('console.infrastructure.servers.return', $workspace->id) }}"
                       data-bulk-users-url="{{ route('console.infrastructure.servers.bulk-users', $workspace->id) }}"
                       data-install-url="{{ route('console.infrastructure.install.index', $workspace->id) }}"
                       data-bulk-install-url="{{ route('console.infrastructure.bulk-install', $workspace->id) }}"
                       data-status-url-template="{{ route('console.infrastructure.servers.status', [$workspace->id, '__SERVER__']) }}"
                       data-lock-url-template="{{ route('console.infrastructure.servers.lock', [$workspace->id, '__SERVER__']) }}"
                       data-restore-url-template="{{ route('console.infrastructure.servers.restore', [$workspace->id, '__SERVER__']) }}"
                       data-history-url-template="{{ route('console.infrastructure.servers.history', [$workspace->id, '__SERVER__']) }}"
                       data-console-url-template="{{ route('console.infrastructure.servers.console', [$workspace->id, '__SERVER__']) }}"
                       data-ips-url-template="{{ route('console.infrastructure.servers.ips', [$workspace->id, '__SERVER__']) }}"
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
        .server-frame-dialog {
            border: 0;
            background: transparent;
            height: min(78vh, 760px);
            max-height: calc(100dvh - 2rem);
            max-width: calc(100vw - 2rem);
            overflow: visible;
            padding: 0;
            width: min(1140px, calc(100vw - 2rem));
        }
        .server-frame-dialog::backdrop { background: rgb(0 0 0 / 45%); }
        .server-frame-dialog .modal-dialog { height: 100%; margin: 0; max-width: none; width: 100%; }
        .server-frame-dialog .modal-content { display: flex; height: 100%; overflow: hidden; }
        .server-frame-dialog .modal-body { flex: 1 1 auto; min-height: 0; overflow: auto; padding: 0; }
        .server-frame-dialog iframe { border: 0; display: block; height: 100%; min-height: 450px; width: 100%; }
        .server-frame-dialog .close { font-size: 1.5rem; }
        .bootbox .server-action-frame { border: 0; display: block; height: min(72vh, 760px); min-height: 450px; width: 100%; }
        @media (max-width: 575.98px) {
            .server-frame-dialog { height: calc(100dvh - 1rem); max-height: calc(100dvh - 1rem); max-width: calc(100vw - 1rem); width: calc(100vw - 1rem); }
            .server-frame-dialog iframe { min-height: 360px; }
            .bootbox .server-action-frame { height: 68vh; min-height: 360px; }
        }
        #servers { table-layout: fixed; width: 100% !important; }
        #servers th, #servers td { box-sizing: border-box; }
        #servers th { font-size: .72rem; padding-left: .2rem; padding-right: .2rem; white-space: nowrap; }
        #servers td { overflow-wrap: anywhere; }
        #servers td:nth-child(5) { font-size: .72rem; white-space: nowrap; }
    </style>
@endpush
