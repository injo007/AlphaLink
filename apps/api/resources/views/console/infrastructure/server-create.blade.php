@extends('layouts.standalone')

@section('title', 'Add Servers')

@section('content')
    <div class="command-modal-surface">
        <div class="command-modal-hero">
            <div class="command-modal-hero-left">
                <div class="command-modal-hero-icon">
                    <i class="fas fa-server"></i>
                </div>
                <div>
                    <div class="command-modal-hero-eyebrow">FLEET PROVISIONING // REGISTRATION</div>
                    <h3 class="command-modal-hero-title">ADD SERVERS</h3>
                </div>
            </div>
        </div>

        <div class="command-modal-body">
            <div id="validation_errors" class="alert alert-danger" role="alert" hidden></div>

            <div class="command-form-group">
                <label class="command-form-label" for="servers_provider_id">
                    <i class="fas fa-building mr-1 text-purple"></i> PROVIDER
                </label>
                <div class="command-select-wrapper">
                    <select id="servers_provider_id" class="command-form-control command-select" title="Select Server Provider">
                        <option value="" disabled selected>Select Server Provider...</option>
                        @foreach ($providers as $provider)
                            <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="command-form-group">
                <label class="command-form-label" for="name">
                    <i class="fas fa-tag mr-1 text-purple"></i> NAME
                </label>
                <input type="text" class="command-form-control" id="name" name="name" placeholder="Enter Name ..." value="server">
            </div>

            <div class="command-form-group">
                <label class="command-form-label" for="ip">
                    <i class="fas fa-network-wired mr-1 text-purple"></i> IP OR IPS
                </label>
                <textarea id="ip" class="command-form-control" rows="3" placeholder="Ip Or Ips (one per line)"></textarea>
            </div>

            <div class="row">
                <div class="col-sm-6">
                    <div class="command-form-group">
                        <label class="command-form-label" for="port">
                            <i class="fas fa-plug mr-1 text-purple"></i> PORT
                        </label>
                        <input type="text" class="command-form-control" id="port" name="port" placeholder="22" value="22">
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="command-form-group">
                        <label class="command-form-label" for="username">
                            <i class="fas fa-user mr-1 text-purple"></i> USERNAME
                        </label>
                        <input type="text" class="command-form-control" id="username" name="username" placeholder="root" value="root">
                    </div>
                </div>
            </div>

            <div class="command-form-group">
                <label class="command-form-label" for="login_type">
                    <i class="fas fa-key mr-1 text-purple"></i> AUTHENTICATION TYPE
                </label>
                <div class="command-select-wrapper">
                    <select id="login_type" class="command-form-control command-select">
                        <option value="password" selected>Password</option>
                        <option value="ssh_key">SSH Key</option>
                    </select>
                </div>
            </div>

            <div class="command-form-group">
                <label class="command-form-label" for="password">
                    <i class="fas fa-lock mr-1 text-purple"></i> PASSWORD / SSH KEY
                </label>
                <textarea id="password" name="password" class="command-form-control" rows="3" placeholder="Enter Password or Private Key ..."></textarea>
            </div>

            <div class="command-form-group">
                <label class="command-form-label" for="expire_at">
                    <i class="far fa-calendar-alt mr-1 text-purple"></i> EXPIRE AT
                </label>
                <input type="date" class="command-form-control" id="expire_at" min="{{ now()->format('Y-m-d') }}" value="{{ $defaultExpireAt }}">
            </div>

            <div class="command-modal-actions mt-4">
                <button id="test" class="btn-command-action" type="button" data-url="{{ route('console.infrastructure.servers.test-connection', $workspace->id) }}">
                    <i class="fas fa-bolt mr-1"></i> TEST CONNECTION
                </button>
                <button id="save" class="btn-command-primary" type="button" data-url="{{ route('console.infrastructure.servers.store', $workspace->id) }}" data-return-url="{{ route('console.infrastructure.servers.index', $workspace->id) }}">
                    <i class="fas fa-check mr-1"></i> SAVE SERVER
                </button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('console-assets/server-registration.js') }}" defer></script>
@endpush
