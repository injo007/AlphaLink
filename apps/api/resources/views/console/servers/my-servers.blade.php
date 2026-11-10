@extends('layouts.admin-shell')

@section('title', 'My Servers')

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-server"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">MEMBER SERVERS // ASSIGNED HARDWARE</div>
                    <h1 class="command-os-report-hero-title">MY SERVERS</h1>
                    <p class="command-os-report-hero-desc">All dedicated MTA servers, IP allocations, and PowerMTA daemon controllers assigned to your account.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    <span class="command-os-report-badge">ASSIGNED FLEET</span>
                </div>
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
