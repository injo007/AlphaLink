@extends('layouts.dms-admin')

@section('title', 'Add User - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header"><h3 class="block-title">ADD USER</h3></div>
        <div class="block-content">
            <div class="row justify-content-center"><div class="col-sm-10 col-lg-8">
                <form id="create-user-form" novalidate>
                    <div class="form-group"><label for="name">Full Name</label><input class="form-control" id="name" name="name" minlength="4" maxlength="255" required autocomplete="name"></div>
                    <div class="form-group"><label for="email">Login Email</label><input class="form-control" id="email" name="email" type="email" maxlength="255" required autocomplete="email"></div>
                    <div class="form-group"><label for="username">Username</label><input class="form-control" id="username" name="username" minlength="4" maxlength="255" pattern="[A-Za-z]+" required autocomplete="username"></div>
                    <div class="form-group"><label for="type">User Type</label><select id="type" name="type" class="form-control" required>@foreach ($types as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach</select></div>
                    <fieldset class="form-group" id="role-container"><legend class="col-form-label">User Roles</legend><div class="row">@foreach ($roles as $key => $label)<div class="col-sm-6"><div class="custom-control custom-checkbox"><input class="custom-control-input" id="role-{{ $key }}" name="roles[]" type="checkbox" value="{{ $key }}"><label class="custom-control-label" for="role-{{ $key }}">{{ $label }}</label></div></div>@endforeach</div><small class="form-text text-muted">DMS reference roles are stored with the account; AlphaLink access remains governed by the workspace role.</small></fieldset>
                    <div class="form-group"><label for="workspace_role">Workspace Role</label><select class="form-control" id="workspace_role" name="workspace_role" required>@foreach ($workspaceRoles as $role)<option value="{{ $role }}" @selected($role === 'operator')>{{ str_replace('_', ' ', $role) }}</option>@endforeach</select></div>
                    <div class="form-group"><button class="btn btn-primary" id="save-user" type="submit">SAVE</button> <a class="btn btn-light" href="{{ route('console.users.index', $workspace->id) }}">CANCEL</a></div>
                    <div class="alert alert-danger d-none" id="user-error" role="alert"></div>
                </form>
                <section class="alert alert-success d-none" id="created-credentials" aria-live="polite">
                    <strong>User created. Save these login details now:</strong>
                    <p class="mb-1">Email: <code id="created-email"></code></p>
                    <p class="mb-2">Username and temporary password: <code id="created-info"></code></p>
                    <button class="btn btn-sm btn-success" id="copy-created-info" type="button">Copy login details</button>
                </section>
            </div></div>
        </div>
    </div>
    <script>
        (() => {
            const form = document.getElementById('create-user-form');
            const button = document.getElementById('save-user');
            const error = document.getElementById('user-error');
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const roles = document.getElementById('role-container');
            document.getElementById('type').addEventListener('change', (event) => {
                roles.hidden = event.currentTarget.value === 'admin';
                if (roles.hidden) { roles.querySelectorAll('input').forEach((input) => { input.checked = false; }); }
            });
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                error.classList.add('d-none');
                const data = new FormData(form);
                const payload = Object.fromEntries(data.entries());
                payload.roles = data.getAll('roles[]');
                button.disabled = true;
                button.textContent = 'SAVING…';
                try {
                    const response = await fetch(@json(route('console.users.store', $workspace->id)), {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token},
                        body: JSON.stringify(payload),
                    });
                    const result = await response.json();
                    if (!response.ok) { throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'The account could not be created.'); }
                    document.getElementById('created-email').textContent = result.login_email;
                    document.getElementById('created-info').textContent = result.info;
                    document.getElementById('created-credentials').classList.remove('d-none');
                    button.textContent = 'CREATED';
                } catch (cause) {
                    error.textContent = cause.message || 'The account could not be created.';
                    error.classList.remove('d-none');
                    button.disabled = false;
                    button.textContent = 'SAVE';
                }
            });
            document.getElementById('copy-created-info').addEventListener('click', async () => {
                const value = `${document.getElementById('created-email').textContent}:${document.getElementById('created-info').textContent.split(':').slice(1).join(':')}`;
                try { await navigator.clipboard.writeText(value); }
                catch {
                    const field = document.createElement('textarea'); field.value = value; document.body.append(field); field.select(); document.execCommand('copy'); field.remove();
                }
                document.getElementById('copy-created-info').textContent = 'Copied';
            });
        })();
    </script>
@endsection
