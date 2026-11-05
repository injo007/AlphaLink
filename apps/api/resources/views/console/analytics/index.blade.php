@extends('layouts.admin-shell')

@section('title', 'Dashboard · AlphaLink Command OS')

@section('content')
    @php
        $commandOsPayload = array_merge($commandOsDashboard, [
            'actions' => [
                ['label' => 'All Stats', 'href' => route('console.analytics.all-stats', $workspace->id), 'icon' => 'chart'],
                ['label' => 'ISP Stats', 'href' => route('console.analytics.isp-stats', $workspace->id), 'icon' => 'globe'],
                ['label' => 'Mailer Revenue', 'href' => route('console.analytics.mailer-revenue', $workspace->id), 'icon' => 'dollar'],
                ['label' => 'Performance', 'href' => '#performance', 'icon' => 'activity'],
                ['label' => 'GEO data status', 'href' => '#geo-data-status', 'icon' => 'map'],
                ['label' => 'Graph data status', 'href' => '#relationship-data-status', 'icon' => 'network'],
            ],
        ]);
    @endphp
    <div id="command-os-dashboard-root" class="command-os-app" aria-label="AlphaLink Command OS dashboard">
        <div class="command-os-loading" role="status">Loading dashboard…</div>
    </div>
    <script type="application/json" id="command-os-dashboard-data">{!! \Illuminate\Support\Js::encode($commandOsPayload) !!}</script>
    <noscript>
        <div class="command-os-noscript-note">JavaScript is disabled. The existing AlphaLink dashboard is shown below.</div>
        @include('console.analytics.partials.main-dashboard-content')
    </noscript>
@endsection
