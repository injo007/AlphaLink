@extends('layouts.dms-reference')

@section('title', 'Manage Users Servers')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">Servers Allowed Users</h3>
        </div>
        <div class="block-content">
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
    <script src="{{ asset('dms-reference/server-users.js') }}" defer></script>
@endpush
