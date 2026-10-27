@extends('layouts.dms-admin')

@section('title', 'My Servers - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">My Servers</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <p id="my-servers-status" class="sr-only" role="status" aria-live="polite"></p>
            <div class="table-responsive">
                <table id="my-servers"
                       class="table table-sm table-hover table-striped"
                       data-source-url="{{ $serversUrl }}"
                       data-my-ips-url="{{ $myIpsUrl }}"
                       data-pmta-url="{{ $pmtaAdminUrl }}"
                       aria-label="My servers"></table>
            </div>
        </div>
    </div>
@endsection
