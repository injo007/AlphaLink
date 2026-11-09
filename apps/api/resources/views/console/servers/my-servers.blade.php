@extends('layouts.admin-shell')

@section('title', 'My Servers')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Card -->
        <header class="command-os-hero-card">
            <div class="command-os-hero-card-left">
                <div class="command-os-hero-mark" aria-hidden="true">
                    <i class="fas fa-server"></i>
                </div>
                <div class="command-os-hero-copy">
                    <h1 class="command-os-hero-title">MY SERVERS</h1>
                </div>
            </div>
            <div class="command-os-hero-card-right">
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="si si-size-fullscreen"></i>
                </button>
            </div>
        </header>

        <!-- 2. Table Card -->
        <div class="command-os-table-card">
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
