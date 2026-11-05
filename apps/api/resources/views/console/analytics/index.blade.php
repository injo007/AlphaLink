@extends('layouts.admin-shell')

@section('title', 'Dashboard · AlphaLink Command OS')

@section('content')
    <div id="command-os-dashboard-root" class="command-os-app" aria-label="AlphaLink Command OS dashboard">
        <div class="command-os-loading" role="status">Loading dashboard…</div>
    </div>
    <script type="application/json" id="command-os-dashboard-data">{!! \Illuminate\Support\Js::encode($commandOsDashboard) !!}</script>
    <noscript>
        <div class="command-os-noscript-note">JavaScript is disabled. The existing AlphaLink dashboard is shown below.</div>
        @include('console.analytics.partials.main-dashboard-content')
    </noscript>
@endsection
