@extends('layouts.admin-shell')

@section('title', 'Edit User')

@section('content')
    <main class="command-os-user-form" aria-labelledby="edit-user-title">
        <header class="command-os-user-form-heading">
            <span class="command-os-user-form-mark" aria-hidden="true"><i class="fas fa-user-edit"></i></span>
            <div class="command-os-user-form-heading-copy">
                <span class="command-os-user-form-eyebrow">ACCESS MANAGEMENT</span>
                <h1 id="edit-user-title">Edit User</h1>
                <p>Update this account’s details and global access role.</p>
            </div>
        </header>

        <section class="command-os-user-form-card" aria-label="Edit workspace member">
            <div class="command-os-user-form-card-heading">
                <span class="command-os-user-form-section-icon" aria-hidden="true"><i class="fas fa-id-card"></i></span>
                <div>
                    <h2>Member details</h2>
                    <p>The selected Global Access Role applies across the account’s workspace memberships.</p>
                </div>
            </div>

            <form id="edit-user-form" class="command-os-user-form-body" novalidate>
                <div class="command-os-user-form-fields">
                    <div class="form-group command-os-user-field">
                        <label for="name">Full Name</label>
                        <input class="form-control" id="name" name="name" minlength="4" maxlength="255" required autocomplete="name" value="{{ $user->name }}">
                    </div>
                    <div class="form-group command-os-user-field">
                        <label for="username">Username</label>
                        <input class="form-control" id="username" name="username" minlength="4" maxlength="255" required autocomplete="username" autocapitalize="none" value="{{ $user->username }}">
                    </div>
                    <div class="form-group command-os-user-field">
                        <label for="email">Login Email</label>
                        <input class="form-control" id="email" type="email" autocomplete="email" aria-describedby="user-email-help" value="{{ $user->email }}" disabled>
                        <small id="user-email-help" class="command-os-user-field-help">Login email is read-only on this screen.</small>
                    </div>
                    <div class="form-group command-os-user-field">
                        <label for="type">Account Type</label>
                        <select id="type" name="type" class="form-control">
                            @foreach ($types as $type)
                                <option value="{{ $type }}" @selected($type === $user->type)>{{ $type }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="command-os-user-access-grid is-role-only">
                    <div class="command-os-user-workspace-role">
                        <label for="workspace_role">Global Access Role</label>
                        <p>Changing this role updates access across every workspace. Owner alone can arrange the shared navigation.</p>
                        <select class="form-control" id="workspace_role" name="workspace_role">
                            @foreach ($workspaceRoles as $role)
                                <option value="{{ $role }}" @selected($role === $user->access_role)>{{ \App\Models\User::ACCESS_ROLE_LABELS[$role] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="alert alert-danger d-none command-os-user-form-error" id="user-error" role="alert"></div>
                <p class="command-os-user-form-success" id="user-success" role="status" aria-live="polite"></p>

                <div class="command-os-user-form-actions">
                    <button class="command-os-user-form-save" id="save-user" type="submit"><i class="fas fa-user-check" aria-hidden="true"></i><span id="edit-user-submit-label">Save Changes</span></button>
                    <a class="command-os-user-form-cancel" href="{{ route('console.users.index', $workspace->id) }}">Cancel</a>
                </div>
            </form>
        </section>
    </main>
    <script>
        (() => {
            const form = document.getElementById('edit-user-form');
            const button = document.getElementById('save-user');
            const buttonLabel = document.getElementById('edit-user-submit-label');
            const error = document.getElementById('user-error');
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                error.classList.add('d-none');
                error.textContent = '';
                button.disabled = true;
                buttonLabel.textContent = 'Saving…';
                const data = new FormData(form);
                const payload = Object.fromEntries(data.entries());
                try {
                    const response = await fetch(@json(route('console.users.update', [$workspace->id, $user->id])), {
                        method: 'PUT',
                        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content},
                        body: JSON.stringify(payload),
                    });
                    const result = await response.json();
                    if (!response.ok) { throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'The account could not be updated.'); }
                    document.getElementById('user-success').textContent = result.data;
                    buttonLabel.textContent = 'Saved';
                } catch (cause) {
                    error.textContent = cause.message || 'The account could not be updated.';
                    error.classList.remove('d-none');
                    button.disabled = false;
                    buttonLabel.textContent = 'Save Changes';
                }
            });
        })();
    </script>
@endsection
