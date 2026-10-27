@extends('layouts.dms-admin')

@section('title', 'Users Management - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">USERS</h3>
            <div class="block-options">
                <a class="btn btn-sm btn-primary" href="{{ route('console.users.create', $workspace->id) }}"><i class="fa fa-plus mr-1" aria-hidden="true"></i> ADD USER</a>
            </div>
        </div>
        <div class="block-content block-content-full">
            <p class="text-muted">Manage accounts that belong to this workspace. Disabling an account applies across AlphaLink workspaces.</p>
            @if ($availableUsers->isNotEmpty())
                <form id="attach-existing-user-form" class="form-row align-items-end mb-4" novalidate>
                    <div class="form-group col-md-6 mb-2"><label for="existing-user-id">Add an existing account</label><select id="existing-user-id" class="form-control" name="user_id" required><option value="">Select account ...</option>@foreach ($availableUsers as $availableUser)<option value="{{ $availableUser->id }}">{{ $availableUser->username }} · {{ $availableUser->name }}{{ $availableUser->is_active ? '' : ' · DISABLED' }}</option>@endforeach</select></div>
                    <div class="form-group col-md-3 mb-2"><label for="existing-user-role">Workspace role</label><select id="existing-user-role" class="form-control" name="workspace_role" required>@foreach ($workspaceRoles as $role)<option value="{{ $role }}">{{ str_replace('_', ' ', $role) }}</option>@endforeach</select></div>
                    <div class="form-group col-md-3 mb-2"><button class="btn btn-primary" id="attach-existing-user" type="submit">ADD EXISTING ACCOUNT</button></div>
                    <div class="col-12"><p class="text-danger d-none" id="attach-existing-user-error" role="alert"></p></div>
                </form>
            @endif
            <div class="table-responsive">
                <table class="table table-sm table-hover table-striped" id="workspace-users">
                    <thead><tr><th>#</th><th>USERNAME</th><th>NAME</th><th>EMAIL</th><th>TYPE</th><th>WORKSPACE ROLE</th><th>SEEN AT</th><th>STATUS</th><th>ACTIONS</th></tr></thead>
                    <tbody>
                        @forelse ($users as $user)
                            <tr data-user-row="{{ $user->id }}" @class(['table-danger' => ! $user->is_active])>
                                <td>{{ $user->id }}</td>
                                <td>{{ $user->username ?: '—' }}</td>
                                <td>{{ $user->name }}</td>
                                <td>{{ $user->email ?: '—' }}</td>
                                <td><span class="badge badge-{{ $user->type === 'admin' ? 'primary' : 'info' }} p-1">{{ $user->type }}</span></td>
                                <td>{{ $user->pivot->role }}</td>
                                <td>{{ $user->seen_at?->diffForHumans() ?? 'NA' }}</td>
                                <td data-status>{{ $user->is_active ? 'Active' : 'Disabled' }}</td>
                                <td class="text-nowrap">
                                    <a class="btn btn-info btn-sm" href="{{ route('console.users.edit', [$workspace->id, $user->id]) }}" aria-label="Edit {{ $user->username ?: $user->email }}"><i class="fas fa-pencil-alt" aria-hidden="true"></i></a>
                                    <a class="btn btn-secondary btn-sm" href="{{ route('console.users.password', [$workspace->id, $user->id]) }}" aria-label="Change password for {{ $user->username ?: $user->email }}"><i class="fas fa-unlock-alt" aria-hidden="true"></i></a>
                                    <button class="btn btn-{{ $user->is_active ? 'warning' : 'success' }} btn-sm" type="button" data-user-status="{{ $user->id }}" data-status="{{ $user->is_active ? 0 : 1 }}" @disabled(auth()->user()->is($user)) aria-label="{{ $user->is_active ? 'Disable' : 'Enable' }} {{ $user->username ?: $user->email }}"><i class="fas fa-{{ $user->is_active ? 'times' : 'check' }}" aria-hidden="true"></i></button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted py-4">No workspace users are available yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p id="users-message" role="status" aria-live="polite" class="mb-0"></p>
        </div>
    </div>
    <script>
        (() => {
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const message = document.getElementById('users-message');
            document.querySelectorAll('[data-user-status]').forEach((button) => button.addEventListener('click', async () => {
                const userId = button.dataset.userStatus;
                const nextStatus = button.dataset.status;
                const url = @json(route('console.users.status', [$workspace->id, '__USER__']));
                button.disabled = true;
                message.textContent = 'Updating account status…';
                try {
                    const response = await fetch(url.replace('__USER__', userId), {
                        method: 'PATCH',
                        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token},
                        body: JSON.stringify({status: Number(nextStatus)}),
                    });
                    const result = await response.json();
                    if (!response.ok) { throw new Error(result.message || Object.values(result.errors || {}).flat().join(' ') || 'The status could not be changed.'); }
                    const active = nextStatus === '1';
                    const row = document.querySelector(`[data-user-row="${userId}"]`);
                    row.classList.toggle('table-danger', !active);
                    row.querySelector('[data-status]').textContent = active ? 'Active' : 'Disabled';
                    button.dataset.status = active ? '0' : '1';
                    button.classList.toggle('btn-warning', active);
                    button.classList.toggle('btn-success', !active);
                    button.setAttribute('aria-label', `${active ? 'Disable' : 'Enable'} ${row.children[1].textContent}`);
                    button.innerHTML = `<i class="fas fa-${active ? 'times' : 'check'}" aria-hidden="true"></i>`;
                    message.textContent = result.data;
                } catch (error) {
                    message.textContent = error.message || 'The status could not be changed.';
                } finally {
                    button.disabled = false;
                }
            }));
            const attachForm = document.getElementById('attach-existing-user-form');
            attachForm?.addEventListener('submit', async (event) => {
                event.preventDefault();
                const button = document.getElementById('attach-existing-user');
                const error = document.getElementById('attach-existing-user-error');
                error.classList.add('d-none');
                button.disabled = true;
                try {
                    const response = await fetch(@json(route('console.users.attach', $workspace->id)), {
                        method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token},
                        body: JSON.stringify({user_id: document.getElementById('existing-user-id').value, workspace_role: document.getElementById('existing-user-role').value}),
                    });
                    const result = await response.json();
                    if (!response.ok) { throw new Error(result.message || Object.values(result.errors || {}).flat().join(' ') || 'The account could not be added.'); }
                    window.location.reload();
                } catch (cause) {
                    error.textContent = cause.message || 'The account could not be added.';
                    error.classList.remove('d-none');
                    button.disabled = false;
                }
            });
        })();
    </script>
@endsection
