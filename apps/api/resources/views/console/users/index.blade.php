@extends('layouts.admin-shell')

@section('title', 'Workspace Users')

@section('content')
    @php
        $userCount = $users->count();
        $activeCount = $users->where('is_active', true)->count();
        $disabledCount = $userCount - $activeCount;
        $adminCount = $users->whereIn('access_role', ['owner', 'admin'])->count();
    @endphp

    <main class="command-os-users" aria-labelledby="workspace-users-title">
        <header class="command-os-users-heading">
            <span class="command-os-users-heading-mark" aria-hidden="true"><i class="fas fa-users-cog"></i></span>
            <div class="command-os-users-heading-copy">
                <span class="command-os-users-eyebrow">ACCESS MANAGEMENT</span>
                <h1 id="workspace-users-title">Workspace Users</h1>
                <p>Manage accounts and access for <strong>{{ $workspace->name }}</strong>.</p>
            </div>
            <div class="command-os-users-heading-actions">
                <span class="command-os-users-scope"><i class="fas fa-shield-alt" aria-hidden="true"></i> Workspace access</span>
                <a class="command-os-users-primary" href="{{ route('console.users.create', $workspace->id) }}"><i class="fas fa-user-plus" aria-hidden="true"></i><span>Add User</span></a>
            </div>
        </header>

        <section class="command-os-users-overview" aria-label="Workspace member overview">
            <article class="command-os-users-stat command-os-users-stat-purple">
                <span class="command-os-users-stat-icon" aria-hidden="true"><i class="fas fa-users"></i></span>
                <span class="command-os-users-stat-copy"><span>Total members</span><strong>{{ number_format($userCount) }}</strong><small>In this workspace</small></span>
            </article>
            <article class="command-os-users-stat command-os-users-stat-green">
                <span class="command-os-users-stat-icon" aria-hidden="true"><i class="fas fa-user-check"></i></span>
                <span class="command-os-users-stat-copy"><span>Active</span><strong data-user-stat="active">{{ number_format($activeCount) }}</strong><small>Accounts enabled</small></span>
            </article>
            <article class="command-os-users-stat command-os-users-stat-amber">
                <span class="command-os-users-stat-icon" aria-hidden="true"><i class="fas fa-user-slash"></i></span>
                <span class="command-os-users-stat-copy"><span>Disabled</span><strong data-user-stat="disabled">{{ number_format($disabledCount) }}</strong><small>Accounts paused</small></span>
            </article>
            <article class="command-os-users-stat command-os-users-stat-cyan">
                <span class="command-os-users-stat-icon" aria-hidden="true"><i class="fas fa-user-shield"></i></span>
                    <span class="command-os-users-stat-copy"><span>Full access</span><strong>{{ number_format($adminCount) }}</strong><small>Owner and Admin roles</small></span>
            </article>
        </section>

        @if ($availableUsers->isNotEmpty())
            <section class="command-os-users-attach-panel" aria-labelledby="attach-existing-user-title">
                <div class="command-os-users-attach-heading">
                    <span class="command-os-users-panel-icon" aria-hidden="true"><i class="fas fa-user-plus"></i></span>
                    <div>
                        <h2 id="attach-existing-user-title">Add an existing account</h2>
                        <p>Connect an existing AlphaLink account and set its global role across every workspace.</p>
                    </div>
                </div>
                <form id="attach-existing-user-form" class="command-os-users-attach-form" novalidate>
                    <div class="command-os-users-field command-os-users-account-field">
                        <label for="existing-user-id">Account</label>
                        <select id="existing-user-id" class="form-control" name="user_id" required>
                            <option value="">Select an account</option>
                            @foreach ($availableUsers as $availableUser)
                                <option value="{{ $availableUser->id }}">{{ $availableUser->username ?: $availableUser->name }} · {{ $availableUser->name }} · {{ \App\Models\User::ACCESS_ROLE_LABELS[$availableUser->access_role] ?? $availableUser->access_role }}{{ $availableUser->is_active ? '' : ' · Disabled' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="command-os-users-field command-os-users-role-field">
                        <label for="existing-user-role">Global access role</label>
                        <select id="existing-user-role" class="form-control" name="workspace_role" required>
                            @foreach ($workspaceRoles as $role)
                                <option value="{{ $role }}">{{ \App\Models\User::ACCESS_ROLE_LABELS[$role] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="command-os-users-attach" id="attach-existing-user" type="submit"><i class="fas fa-link" aria-hidden="true"></i><span>Add to workspace</span></button>
                    <p class="command-os-users-attach-error" id="attach-existing-user-error" role="alert" hidden></p>
                </form>
            </section>
        @endif

        <section class="command-os-users-directory" aria-labelledby="workspace-members-title">
            <div class="command-os-users-directory-heading">
                <div class="command-os-users-directory-title">
                    <span class="command-os-users-panel-icon" aria-hidden="true"><i class="fas fa-id-card-alt"></i></span>
                    <div>
                        <h2 id="workspace-members-title">Members</h2>
                        <p>Account status applies across AlphaLink workspaces.</p>
                    </div>
                </div>
                @if ($users->isNotEmpty())
                    <div class="command-os-users-search-wrap">
                        <label class="sr-only" for="workspace-user-search">Search workspace users</label>
                        <i class="fas fa-search" aria-hidden="true"></i>
                        <input id="workspace-user-search" type="search" autocomplete="off" placeholder="Search name, email, role…">
                        <button type="button" id="workspace-user-search-clear" aria-label="Clear user search" hidden><i class="fas fa-times" aria-hidden="true"></i></button>
                    </div>
                @endif
            </div>

            @if ($users->isNotEmpty())
                <div class="command-os-users-table-wrap">
                    <table class="command-os-users-table" id="workspace-users">
                        <caption class="sr-only">Workspace user accounts, access roles, status, and actions</caption>
                        <thead>
                            <tr><th scope="col">#</th><th scope="col">Username</th><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Account type</th><th scope="col">Global access role</th><th scope="col">Last seen</th><th scope="col">Status</th><th scope="col">Actions</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($users as $user)
                                @php
                                    $userLabel = $user->username ?: $user->email ?: $user->name;
                                    $userSearchBase = mb_strtolower(implode(' ', array_filter([
                                        (string) $user->id,
                                        $user->username,
                                        $user->name,
                                        $user->email,
                                        $user->type,
                                        $user->access_role,
                                        $user->pivot->role,
                                    ])));
                                    $userSearchText = trim($userSearchBase.' '.($user->is_active ? 'active' : 'disabled'));
                                @endphp
                                <tr data-user-row="{{ $user->id }}" data-user-label="{{ $userLabel }}" data-search="{{ $userSearchText }}" data-search-base="{{ $userSearchBase }}" @class(['is-disabled' => ! $user->is_active])>
                                    <td data-label="#"><span class="command-os-users-id">{{ $user->id }}</span></td>
                                    <td data-label="Username"><strong class="command-os-users-username">{{ $user->username ?: '—' }}</strong></td>
                                    <td data-label="Name">{{ $user->name }}</td>
                                    <td data-label="Email" class="command-os-users-email">{{ $user->email ?: '—' }}</td>
                                    <td data-label="Type"><span class="command-os-users-type command-os-users-type-{{ $user->type }}">{{ $user->type }}</span></td>
                                    <td data-label="Global access role"><span class="command-os-users-role">{{ \App\Models\User::ACCESS_ROLE_LABELS[$user->access_role] ?? $user->access_role }}</span></td>
                                    <td data-label="Last seen">{{ $user->seen_at?->diffForHumans() ?? 'Never' }}</td>
                                    <td data-label="Status"><span class="command-os-users-status {{ $user->is_active ? 'is-active' : 'is-disabled' }}" data-status>{{ $user->is_active ? 'Active' : 'Disabled' }}</span></td>
                                    <td data-label="Actions">
                                        <div class="command-os-users-row-actions">
                                            @if (! $user->isOwner() || auth()->user()->isOwner())
                                                <a class="command-os-users-action command-os-users-action-edit" href="{{ route('console.users.edit', [$workspace->id, $user->id]) }}" aria-label="Edit {{ $userLabel }}" title="Edit user"><i class="fas fa-pen" aria-hidden="true"></i><span>Edit</span></a>
                                                <a class="command-os-users-action command-os-users-action-password" href="{{ route('console.users.password', [$workspace->id, $user->id]) }}" aria-label="Change password for {{ $userLabel }}" title="Change password"><i class="fas fa-key" aria-hidden="true"></i><span>Password</span></a>
                                                <button class="command-os-users-action command-os-users-status-action {{ $user->is_active ? 'is-disable' : 'is-enable' }}" type="button" data-user-status="{{ $user->id }}" data-status="{{ $user->is_active ? 0 : 1 }}" @disabled(auth()->user()->is($user)) aria-label="{{ $user->is_active ? 'Disable' : 'Enable' }} {{ $userLabel }}" title="{{ $user->is_active ? 'Disable account' : 'Enable account' }}"><i class="fas fa-{{ $user->is_active ? 'user-slash' : 'user-check' }}" aria-hidden="true"></i><span>{{ $user->is_active ? 'Disable' : 'Enable' }}</span></button>
                                            @else
                                                <span class="command-os-users-role" title="Only the Owner can manage this account">Owner protected</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="command-os-users-directory-footer">
                    <p id="workspace-user-results" role="status" aria-live="polite">Showing {{ number_format($userCount) }} {{ \Illuminate\Support\Str::plural('member', $userCount) }}</p>
                    <p>Use the row actions to update account access.</p>
                </div>
                <p class="command-os-users-no-results" id="workspace-user-no-results" role="status" hidden>No members match that search.</p>
            @else
                <div class="command-os-users-empty" role="status">
                    <span aria-hidden="true"><i class="fas fa-user-friends"></i></span>
                    <h3>No workspace members yet</h3>
                    <p>Add a new user or connect an existing AlphaLink account to get started.</p>
                    <a class="command-os-users-primary" href="{{ route('console.users.create', $workspace->id) }}"><i class="fas fa-user-plus" aria-hidden="true"></i><span>Add User</span></a>
                </div>
            @endif
            <p class="command-os-users-message" id="users-message" role="status" aria-live="polite"></p>
        </section>
    </main>

    <script>
        (() => {
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const message = document.getElementById('users-message');
            const usersTable = document.getElementById('workspace-users');
            const search = document.getElementById('workspace-user-search');
            const clearSearch = document.getElementById('workspace-user-search-clear');
            const results = document.getElementById('workspace-user-results');
            const noResults = document.getElementById('workspace-user-no-results');
            const rows = [...(usersTable?.querySelectorAll('[data-user-row]') || [])];

            const filterUsers = () => {
                if (!search) return;
                const query = search.value.trim().toLocaleLowerCase();
                let visible = 0;
                rows.forEach((row) => {
                    const matches = !query || row.dataset.search.includes(query);
                    row.hidden = !matches;
                    if (matches) visible += 1;
                });
                clearSearch.hidden = !query;
                results.textContent = query
                    ? `Showing ${visible} of ${rows.length} members`
                    : `Showing ${rows.length} ${rows.length === 1 ? 'member' : 'members'}`;
                noResults.hidden = visible > 0;
            };

            search?.addEventListener('input', filterUsers);
            clearSearch?.addEventListener('click', () => {
                search.value = '';
                search.focus();
                filterUsers();
            });

            document.querySelectorAll('[data-user-status]').forEach((button) => button.addEventListener('click', async () => {
                const userId = button.dataset.userStatus;
                const nextStatus = button.dataset.status;
                const url = @json(route('console.users.status', [$workspace->id, '__USER__']));
                button.disabled = true;
                message.classList.remove('is-error');
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
                    row.classList.toggle('is-disabled', !active);
                    const statusBadge = row.querySelector('[data-status]');
                    statusBadge.textContent = active ? 'Active' : 'Disabled';
                    statusBadge.classList.toggle('is-active', active);
                    statusBadge.classList.toggle('is-disabled', !active);
                    document.querySelector('[data-user-stat="active"]').textContent = new Intl.NumberFormat().format(document.querySelectorAll('.command-os-users-status.is-active').length);
                    document.querySelector('[data-user-stat="disabled"]').textContent = new Intl.NumberFormat().format(document.querySelectorAll('.command-os-users-status.is-disabled').length);
                    button.dataset.status = active ? '0' : '1';
                    button.classList.toggle('is-disable', active);
                    button.classList.toggle('is-enable', !active);
                    button.setAttribute('aria-label', `${active ? 'Disable' : 'Enable'} ${row.dataset.userLabel}`);
                    row.dataset.search = `${row.dataset.searchBase} ${active ? 'active' : 'disabled'}`;
                    button.title = active ? 'Disable account' : 'Enable account';
                    button.innerHTML = `<i class="fas fa-${active ? 'user-slash' : 'user-check'}" aria-hidden="true"></i><span>${active ? 'Disable' : 'Enable'}</span>`;
                    message.textContent = result.data;
                    filterUsers();
                } catch (error) {
                    message.classList.add('is-error');
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
                error.hidden = true;
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
                    error.hidden = false;
                    button.disabled = false;
                }
            });
        })();
    </script>
@endsection
