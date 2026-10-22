@extends('layouts.dms-reference')

@section('title', 'Add Servers - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">ADD SERVERS</h3>
        </div>
        <div class="block-content">
            <div class="row justify-content-center py-1">
                <div class="col-sm-10">
                    <div id="validation_errors" class="alert alert-danger" role="alert" hidden></div>
                    <div class="form-group">
                        <label for="servers_provider_id">Provider :</label>
                        <select id="servers_provider_id" class="form-control selectpicker" title="Select Server Provider" data-live-search="true" data-size="6">
                            @foreach ($providers as $provider)
                                <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="name">Name:</label>
                        <input type="text" class="form-control form-control-alt" id="name" name="name" placeholder="Enter Name ..." value="server">
                    </div>
                    <div class="form-group">
                        <label for="ip">Ip :</label>
                        <textarea id="ip" class="form-control" placeholder="Ip Or Ips"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="port">Port:</label>
                        <input type="text" class="form-control form-control-alt" id="port" name="port" placeholder="Enter Port ..." value="22">
                    </div>
                    <div class="form-group">
                        <label for="username">Username:</label>
                        <input type="text" class="form-control form-control-alt" id="username" name="username" placeholder="Enter Username ..." value="root">
                    </div>
                    <div class="form-group">
                        <label for="login_type">Password Type:</label>
                        <select id="login_type" class="form-control selectpicker" title="select password type" data-live-search="true">
                            <option value="password" selected>Password</option>
                            <option value="ssh_key">Ssh Key</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="password">Password:</label>
                        <textarea type="text" id="password" name="password" class="form-control form-control-alt" placeholder="Enter Password ..."></textarea>
                    </div>
                    <div class="form-group">
                        <label for="expire_at">Expire At:</label>
                        <input type="date" class="form-control" id="expire_at" placeholder="Expire At" min="{{ now()->format('Y-m-d') }}" value="{{ $defaultExpireAt }}">
                    </div>
                    <div class="form-group server-form-actions">
                        <button id="test" class="btn btn-block btn-warning" type="button" data-url="{{ route('console.infrastructure.servers.test-connection', $workspace->id) }}">TEST CONNECTION</button>
                        <button id="save" class="btn btn-block btn-info" type="button" data-url="{{ route('console.infrastructure.servers.store', $workspace->id) }}" data-return-url="{{ route('console.infrastructure.index', $workspace->id) }}">SAVE</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <dialog id="server-pin-confirmation" class="server-dialog" aria-labelledby="server-pin-title">
        <h2 id="server-pin-title">Verify SSH host identity</h2>
        <p>Compare each observed fingerprint with the value supplied by your server provider. AlphaLink will not send the SSH credential until they match.</p>
        <div id="server-pin-list"></div>
        <div class="server-dialog-actions">
            <button class="btn btn-secondary" type="button" data-cancel-pins>Cancel</button>
            <button id="confirm-host-fingerprints" class="btn btn-warning" type="button">Verify &amp; Test</button>
        </div>
    </dialog>
@endsection

@push('scripts')
    <script src="{{ asset('dms-reference/server-registration.js') }}" defer></script>
@endpush
