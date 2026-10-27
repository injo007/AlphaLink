@extends('layouts.dms-admin')

@section('title', 'Horizon - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Horizon</h3>
            @if ($available)
                <div class="block-options">
                    <button type="button" class="btn btn-sm btn-alt-warning" disabled aria-describedby="horizon-restart-note">RESTART HORIZON</button>
                </div>
            @endif
        </div>
        <div class="block-content block-content-full">
            @if ($available)
                <iframe src="{{ route('horizon.index') }}" title="Laravel Horizon dashboard" width="100%" height="720" frameborder="0"></iframe>
                <p class="text-muted small">Horizon monitors the Redis-backed default queue. Suppression imports run on the separate PostgreSQL-backed suppression worker.</p>
                <p id="horizon-restart-note" class="text-muted small">Restart is not available from this page.</p>
            @else
                <div class="alert alert-warning" role="status">
                    <h4 class="alert-heading">Horizon dashboard unavailable</h4>
                    <p class="mb-1">Laravel Horizon is not installed and routed in this AlphaLink runtime.</p>
                    <p class="mb-0">Queue connection: <strong>{{ $connection }}</strong>. Worker activity is not verified here. No restart action is available.</p>
                </div>
            @endif
        </div>
    </div>
@endsection
