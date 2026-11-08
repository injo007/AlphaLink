@extends('layouts.admin-shell')

@section('title', 'Add User')

@section('content')
    <main class="command-os-user-create" aria-labelledby="user-create-title">
        <header class="command-os-user-create-heading">
            <span class="command-os-user-create-mark" aria-hidden="true"><i class="fas fa-user-plus"></i></span>
            <div class="command-os-user-create-heading-copy">
                <span class="command-os-user-create-eyebrow">ACCESS MANAGEMENT</span>
                <h1 id="user-create-title">Add User</h1>
                <p>Add a member to {{ $workspace->name }} and set their account and workspace access.</p>
            </div>
        </header>

        <section class="command-os-user-create-card" aria-label="New workspace member">
            <div class="command-os-user-create-card-heading">
                <span class="command-os-user-create-section-icon" aria-hidden="true"><i class="fas fa-id-card"></i></span>
                <div>
                    <h2>Member details</h2>
                    <p>Enter the login identity and choose the access this member needs.</p>
                </div>
            </div>

            <form id="create-user-form" class="command-os-user-create-form" novalidate>
                <div class="command-os-user-create-fields">
                    <div class="form-group command-os-user-field">
                        <label for="name">Full Name</label>
                        <input class="form-control" id="name" name="name" minlength="4" maxlength="255" required autocomplete="name">
                    </div>
                    <div class="form-group command-os-user-field">
                        <label for="email">Login Email</label>
                        <input class="form-control" id="email" name="email" type="email" maxlength="255" required autocomplete="email">
                    </div>
                    <div class="form-group command-os-user-field">
                        <label for="username">Username</label>
                        <input class="form-control" id="username" name="username" minlength="4" maxlength="255" pattern="[A-Za-z]+" required autocomplete="username" autocapitalize="none">
                    </div>
                    <div class="form-group command-os-user-field">
                        <label for="type">User Type</label>
                        <select id="type" name="type" class="form-control" required>
                            @foreach ($types as $type)
                                <option value="{{ $type }}">{{ $type }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="command-os-user-access-grid">
                    <fieldset class="command-os-user-roles" id="role-container">
                        <legend>User Roles</legend>
                        <div class="command-os-user-role-grid">
                            @foreach ($roles as $key => $label)
                                <div class="command-os-user-role-option">
                                    <div class="custom-control custom-checkbox">
                                        <input class="custom-control-input" id="role-{{ $key }}" name="roles[]" type="checkbox" value="{{ $key }}">
                                        <label class="custom-control-label" for="role-{{ $key }}">{{ $label }}</label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <small>Account permissions apply across the platform. Workspace access is controlled separately.</small>
                    </fieldset>

                    <div class="command-os-user-workspace-role">
                        <label for="workspace_role">Workspace Role</label>
                        <p>Choose what the member can do in this workspace.</p>
                        <select class="form-control" id="workspace_role" name="workspace_role" required>
                            @foreach ($workspaceRoles as $role)
                                <option value="{{ $role }}" @selected($role === 'operator')>{{ str_replace('_', ' ', $role) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="alert alert-danger d-none command-os-user-create-error" id="user-error" role="alert"></div>

                <div class="command-os-user-create-actions">
                    <button class="command-os-user-create-save" id="save-user" type="submit"><i class="fas fa-user-check" aria-hidden="true"></i><span>Save User</span></button>
                    <a class="command-os-user-create-cancel" href="{{ route('console.users.index', $workspace->id) }}">Cancel</a>
                </div>
            </form>
        </section>

        <section class="alert alert-success d-none command-os-user-created" id="created-credentials" aria-live="polite">
            <div class="command-os-user-created-heading">
                <span class="command-os-user-created-icon" aria-hidden="true"><i class="fas fa-check"></i></span>
                <div>
                    <h2>User created</h2>
                    <p>Copy and share these login details securely. The temporary password will not be shown again.</p>
                </div>
            </div>
            <div class="command-os-user-created-details">
                <p><span>Email</span><code id="created-email"></code></p>
                <p><span>Username and temporary password</span><code id="created-info"></code></p>
            </div>
            <button class="command-os-user-created-copy" id="copy-created-info" type="button"><i class="far fa-copy" aria-hidden="true"></i><span>Copy login details</span></button>
        </section>
    </main>
    <script>
        (() => {
            const form = document.getElementById('create-user-form');
            const button = document.getElementById('save-user');
            const error = document.getElementById('user-error');
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const roles = document.getElementById('role-container');
            const typeField = document.getElementById('type');
            const accessGrid = roles.closest('.command-os-user-access-grid');
            const syncRoleVisibility = () => {
                roles.hidden = typeField.value === 'admin';
                accessGrid.classList.toggle('is-admin', roles.hidden);
                if (roles.hidden) { roles.querySelectorAll('input').forEach((input) => { input.checked = false; }); }
            };
            typeField.addEventListener('change', syncRoleVisibility);
            syncRoleVisibility();
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
