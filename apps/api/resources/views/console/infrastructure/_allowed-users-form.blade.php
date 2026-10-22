<div class="row justify-content-center py-2" data-update-url="{{ $updateUrl }}" data-server-ids="{{ json_encode($serverIds) }}">
    <div class="col-sm-10">
        <div id="validation_errors" class="alert alert-danger" role="alert" hidden></div>
        @if ($showSelectedServers ?? false)
            <div class="form-group">
                <h6>Selected Servers :</h6>
                @foreach ($servers ?? [] as $server)
                    <span class="badge badge-info p-1">{{ $server->name }} - {{ $server->id }}</span>
                @endforeach
            </div>
        @endif
        <div class="form-group">
            <label>
                Allowed Mailers :
                <button id="check" class="btn btn-link btn-sm" type="button">CHECK <span class="fas fa-check"></span></button>
            </label>
            <div class="row">
                @foreach ($users->chunk(2) as $chunk)
                    <div class="col-sm-12 col-md-6">
                        @foreach ($chunk as $user)
                            <div class="custom-control custom-checkbox mb-1">
                                <input type="checkbox" value="{{ $user->id }}" class="custom-control-input"
                                       id="allowed-user-{{ $user->id }}" name="checked_users"
                                       @checked(in_array($user->id, $allowedUserIds, false))>
                                <label class="custom-control-label" for="allowed-user-{{ $user->id }}">
                                    {{ $user->email }} | {{ $user->name }}
                                </label>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
        <div class="form-group">
            <button id="update" class="btn btn-block btn-info" type="button">UPDATE ALLOWED USERS</button>
        </div>
    </div>
</div>
