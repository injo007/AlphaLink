@extends('layouts.dms-admin')

@section('title', 'Edit User - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header"><h3 class="block-title">EDIT USER <span class="text-info">{{ $user->username }}</span></h3></div>
        <div class="block-content"><div class="row justify-content-center"><div class="col-sm-10 col-lg-8">
            <form id="edit-user-form" novalidate>
                <div class="form-group"><label for="name">Full Name</label><input class="form-control" id="name" name="name" minlength="4" maxlength="255" required value="{{ $user->name }}"></div>
                <div class="form-group"><label for="username">Username</label><input class="form-control" id="username" name="username" minlength="4" maxlength="255" required value="{{ $user->username }}"></div>
                <div class="form-group"><label for="email">Login Email</label><input class="form-control" id="email" value="{{ $user->email }}" disabled></div>
                <div class="form-group"><label for="type">User Type</label><select id="type" name="type" class="form-control">@foreach ($types as $type)<option value="{{ $type }}" @selected($type === $user->type)>{{ $type }}</option>@endforeach</select></div>
                <fieldset class="form-group" id="role-container" @if ($user->type === 'admin') hidden @endif><legend class="col-form-label">User Roles</legend><div class="row">@foreach ($roles as $key => $label)<div class="col-sm-6"><div class="custom-control custom-checkbox"><input class="custom-control-input" id="role-{{ $key }}" name="roles[]" type="checkbox" value="{{ $key }}" @checked(in_array($key, $user->roles ?? [], true))><label class="custom-control-label" for="role-{{ $key }}">{{ $label }}</label></div></div>@endforeach</div><small class="form-text text-muted">DMS reference roles are stored with the account; AlphaLink access remains governed by the workspace role.</small></fieldset>
                <div class="form-group"><label for="workspace_role">Workspace Role</label><select class="form-control" id="workspace_role" name="workspace_role">@foreach ($workspaceRoles as $role)<option value="{{ $role }}" @selected($role === $user->pivot->role)>{{ str_replace('_', ' ', $role) }}</option>@endforeach</select></div>
                <div class="form-group"><button class="btn btn-warning" id="save-user" type="submit">EDIT</button> <a class="btn btn-light" href="{{ route('console.users.index', $workspace->id) }}">CANCEL</a></div>
                <div class="alert alert-danger d-none" id="user-error" role="alert"></div><p class="text-success" id="user-success" role="status" aria-live="polite"></p>
            </form>
        </div></div></div>
    </div>
    <script>
        (() => {
            const form = document.getElementById('edit-user-form');
            const button = document.getElementById('save-user');
            const error = document.getElementById('user-error');
            const roles = document.getElementById('role-container');
            document.getElementById('type').addEventListener('change', (event) => {
                roles.hidden = event.currentTarget.value === 'admin';
                if (roles.hidden) { roles.querySelectorAll('input').forEach((input) => { input.checked = false; }); }
            });
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                error.classList.add('d-none');
                button.disabled = true;
                button.textContent = 'UPDATING…';
                const data = new FormData(form);
                const payload = Object.fromEntries(data.entries());
                payload.roles = data.getAll('roles[]');
                try {
                    const response = await fetch(@json(route('console.users.update', [$workspace->id, $user->id])), {
                        method: 'PUT', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content}, body: JSON.stringify(payload),
                    });
                    const result = await response.json();
                    if (!response.ok) { throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'The account could not be updated.'); }
                    document.getElementById('user-success').textContent = result.data;
                    button.textContent = 'UPDATED';
                } catch (cause) {
                    error.textContent = cause.message || 'The account could not be updated.';
                    error.classList.remove('d-none');
                    button.disabled = false;
                    button.textContent = 'EDIT';
                }
            });
        })();
    </script>
@endsection
