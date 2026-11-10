<div class="py-2" data-update-url="{{ $updateUrl }}" data-server-ids="{{ json_encode($serverIds) }}">
    <div id="validation_errors" class="alert alert-danger" role="alert" hidden style="border-radius: 12px; font-size: 0.8125rem;"></div>

    @if ($showSelectedServers ?? false)
        <div class="command-form-group mb-3">
            <label class="command-form-label">
                <i class="fas fa-server mr-1 text-purple"></i> SELECTED SERVERS ({{ count($servers ?? []) }})
            </label>
            <div class="d-flex flex-wrap" style="gap: 6px;">
                @foreach ($servers ?? [] as $server)
                    <span class="badge-command-pill" style="font-weight: 600; color: #8b5cf6;">{{ $server->name }} &bull; #{{ $server->id }}</span>
                @endforeach
            </div>
        </div>
    @endif

    <div class="command-form-group">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <label class="command-form-label mb-0">
                <i class="fas fa-user-check mr-1 text-purple"></i> ALLOWED MAILERS
            </label>
            <button id="check" class="btn-command-action" type="button" style="padding: 4px 10px; font-size: 0.75rem;">
                <i class="fas fa-check-double mr-1"></i> TOGGLE ALL
            </button>
        </div>

        <div class="row" style="background: rgba(99, 102, 241, 0.03); border: 1px solid rgba(99, 102, 241, 0.12); border-radius: 14px; padding: 1rem 0.5rem;">
            @foreach ($users->chunk(ceil(max(1, count($users)) / 2)) as $chunk)
                <div class="col-sm-12 col-md-6">
                    @foreach ($chunk as $user)
                        <div class="custom-control custom-checkbox mb-2 px-3 py-1" style="border-radius: 8px;">
                            <input type="checkbox" value="{{ $user->id }}" class="custom-control-input"
                                   id="allowed-user-{{ $user->id }}" name="checked_users"
                                   @checked(in_array($user->id, $allowedUserIds, false))>
                            <label class="custom-control-label" for="allowed-user-{{ $user->id }}" style="cursor: pointer; font-size: 0.8125rem;">
                                <strong style="color: var(--command-os-text, #1e293b);">{{ $user->name }}</strong>
                                <span style="color: var(--command-os-text-muted, #64748b); font-size: 0.75rem;">({{ $user->email }})</span>
                            </label>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    <div class="command-modal-actions mt-4">
        <button id="update" class="btn-command-primary" type="button">
            <i class="fas fa-save mr-1"></i> UPDATE ALLOWED USERS
        </button>
    </div>
</div>
