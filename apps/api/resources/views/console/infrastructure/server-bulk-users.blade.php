@extends('layouts.standalone')

@section('title', 'Manage Users Servers')

@section('content')
    <div class="command-modal-surface">
        <div class="command-modal-hero">
            <div class="command-modal-hero-left">
                <div class="command-modal-hero-icon">
                    <i class="fas fa-users-cog"></i>
                </div>
                <div>
                    <div class="command-modal-hero-eyebrow">BULK ACCESS CONTROL // MULTI-SERVER DELEGATION</div>
                    <h3 class="command-modal-hero-title">ALLOWED MAILERS ASSIGNMENT</h3>
                </div>
            </div>
        </div>

        <div class="command-modal-body">
            @include('console.infrastructure._allowed-users-form', [
                'users' => $users,
                'serverIds' => $servers->pluck('id')->all(),
                'allowedUserIds' => [],
                'showSelectedServers' => true,
                'servers' => $servers,
                'updateUrl' => route('console.infrastructure.servers.bulk-users.update', $workspace->id),
            ])
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('console-assets/server-users.js') }}" defer></script>
@endpush
