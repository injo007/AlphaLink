@extends('layouts.standalone')

@section('title', 'Manage Users Server')

@section('content')
    <div class="command-modal-surface">
        <div class="command-modal-hero">
            <div class="command-modal-hero-left">
                <div class="command-modal-hero-icon">
                    <i class="fas fa-users-cog"></i>
                </div>
                <div>
                    <div class="command-modal-hero-eyebrow">ACCESS CONTROL // MAILER DELEGATION</div>
                    <h3 class="command-modal-hero-title">
                        SERVER USERS <span class="badge-command-pill ml-2" style="font-weight: 700; color: #8b5cf6;">#{{ $server->id }} &bull; {{ $server->name }}</span>
                    </h3>
                </div>
            </div>
        </div>

        <div class="command-modal-body">
            @include('console.infrastructure._allowed-users-form', [
                'users' => $users,
                'serverIds' => [$server->id],
                'allowedUserIds' => $allowedUserIds,
                'showSelectedServers' => false,
                'updateUrl' => route('console.infrastructure.servers.users.update', [$workspace->id, $server->id]),
            ])
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('console-assets/server-users.js') }}" defer></script>
@endpush
