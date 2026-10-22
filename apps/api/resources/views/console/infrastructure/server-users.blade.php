@extends('layouts.dms-reference')

@section('title', 'Manage Users Server')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">Server <span class="text-info">{{ $server->name }} | {{ $server->id }}</span> Users</h3>
        </div>
        <div class="block-content">
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
    <script src="{{ asset('dms-reference/server-users.js') }}" defer></script>
@endpush
