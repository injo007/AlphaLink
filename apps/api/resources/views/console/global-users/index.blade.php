@extends($workspace ? 'layouts.admin-shell' : 'layouts.global-admin')

@section('title', 'Users')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Card -->
        <header class="command-os-hero-card">
            <div class="command-os-hero-card-left">
                <div class="command-os-hero-mark" aria-hidden="true">
                    <i class="fas fa-users-cog"></i>
                </div>
                <div class="command-os-hero-copy">
                    <h1 class="command-os-hero-title">USERS</h1>
                </div>
            </div>
            <div class="command-os-hero-card-right">
                <a class="btn-command-primary text-decoration-none px-3" href="{{ route('console.global-users.create') }}" style="height: 38px; display: inline-flex; align-items: center; border-radius: 10px; font-weight: 750; font-size: 11.5px; letter-spacing: .06em;"><i class="fas fa-plus mr-2" aria-hidden="true"></i> ADD USER</a>
                <a class="command-os-btn-option" href="{{ route('console.global-users.index') }}" title="REFRESH" aria-label="Refresh users"><i class="fas fa-sync" aria-hidden="true"></i></a>
                <button class="command-os-btn-option" type="button" data-toggle="block-option" data-action="fullscreen_toggle" title="FULLSCREEN" aria-label="Toggle fullscreen"><i class="si si-size-fullscreen"></i></button>
            </div>
        </header>

        <!-- 2. Info Banner -->
        <div class="command-os-info-banner" role="note">
            <div class="command-os-info-banner-icon" aria-hidden="true"><i class="fas fa-info-circle"></i></div>
                    <div class="command-os-info-banner-content">
                @if ($workspace)
                    Global access roles control pages across AlphaLink. Workspace membership still scopes each account's data.
                @else
                    Global access roles control pages. Attach an account to a workspace to grant workspace access.
                @endif
            </div>
        </div>

        <!-- 3. Table Card -->
        <div class="command-os-table-card">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="d-flex align-items-center">
                    <i class="fas fa-users text-purple font-size-lg mr-2"></i>
                    <h3 class="h6 font-w700 text-uppercase tracking-wider mb-0 text-ink">Global Users &amp; System Operators</h3>
                </div>
                <span class="badge badge-light border text-muted px-2 py-1 font-size-xs">
                    <i class="fas fa-shield-alt mr-1 text-purple"></i> Security Directory
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover table-striped" id="global-users-table">
                    <thead><tr><th>#</th><th>USERNAME</th><th>NAME</th><th>GLOBAL ROLE</th><th>ACCOUNT TYPE</th><th>SEEN AT</th><th>ACTIONS</th></tr></thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr data-user-row="{{ $user->id }}" @class(['is-inactive-row' => ! $user->is_active])>
                                <td><span class="font-mono text-muted">#{{ $user->id }}</span></td>
                                <td><strong class="text-ink">{{ $user->username }}</strong></td>
                                <td><span class="text-muted font-w600">{{ $user->name }}</span></td>
                                <td><span class="badge badge-light border text-purple font-w700 px-2 py-1">{{ \App\Models\User::ACCESS_ROLE_LABELS[$user->access_role] ?? $user->access_role }}</span></td>
                                <td><span class="badge badge-light border text-muted font-w700 text-uppercase px-2 py-1">{{ $user->type }}</span></td>
                                <td data-order="{{ $user->seen_at?->timestamp ?? 0 }}"><span class="badge badge-light border font-mono font-size-xs">{{ $user->seen_at?->diffForHumans() ?? 'NEVER' }}</span></td>
                                <td class="text-nowrap">
                                    <div class="command-action-btn-group">
                                        @if (! $user->isOwner() || auth()->user()->isOwner())
                                            <a class="command-action-btn action-edit" href="{{ route('console.global-users.edit', $user->id) }}" title="UPDATE USER" aria-label="Update {{ $user->username }}"><i class="fas fa-pencil-alt" aria-hidden="true"></i></a>
                                            <button class="command-action-btn action-status {{ $user->is_active ? 'is-active' : '' }}" type="button" data-user-status="{{ $user->id }}" data-status="{{ $user->is_active ? 0 : 1 }}" @disabled(auth()->user()->is($user)) title="{{ $user->is_active ? 'DISABLE USER' : 'ENABLE USER' }}" aria-label="{{ $user->is_active ? 'Disable' : 'Enable' }} {{ $user->username }}"><i class="fas fa-{{ $user->is_active ? 'toggle-on' : 'toggle-off' }}" aria-hidden="true"></i></button>
                                            <a class="command-action-btn action-warning" href="{{ route('console.global-users.password', $user->id) }}" title="EDIT PASSWORD" aria-label="Edit password for {{ $user->username }}"><i class="fas fa-key" aria-hidden="true"></i></a>
                                            @if (auth()->user()->canImpersonate() && $user->canBeImpersonated())
                                                <form method="post" action="{{ route('console.global-users.impersonation.start', $user->id) }}" class="d-inline">@csrf<button class="command-action-btn action-login" type="submit" title="IMPERSONATE OPERATOR" aria-label="Impersonate {{ $user->username }}"><i class="fas fa-user-ninja" aria-hidden="true"></i></button></form>
                                            @endif
                                        @else
                                            <span class="badge badge-light border text-muted px-2 py-1" title="Only the Owner can manage this account">Owner protected</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p id="global-users-message" role="status" aria-live="polite" class="mb-0 text-muted small mt-2"></p>
        </div>
    </div>
    <script>
        (() => {
            const table = window.jQuery?.('#global-users-table');
            if (table && typeof table.DataTable === 'function') {
                table.DataTable({
                    pageLength: 50,
                    order: [[5, 'desc']],
                    dom: '<"command-table-toolbar"<"command-table-toolbar-left"l><"command-table-toolbar-right"f>>' +
                         '<"table-responsive"t>' +
                         '<"command-table-footer"ip>',
                    language: {
                        lengthMenu: 'Show _MENU_ rows',
                        search: '',
                        searchPlaceholder: 'Search users...',
                        info: 'Page _PAGE_ of _PAGES_ (_TOTAL_ records)',
                        infoEmpty: 'Page 0 of 0 (0 records)',
                        paginate: {
                            previous: '<i class="fas fa-chevron-left"></i>',
                            next: '<i class="fas fa-chevron-right"></i>'
                        }
                    }
                });
            }
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const message = document.getElementById('global-users-message');
            document.querySelectorAll('[data-user-status]').forEach((button) => button.addEventListener('click', async () => {
                const userId = button.dataset.userStatus;
                const nextStatus = button.dataset.status;
                button.disabled = true;
                try {
                    const response = await fetch(@json(route('console.global-users.status', '__USER__')).replace('__USER__', userId), {
                        method: 'PATCH', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token}, body: JSON.stringify({status: Number(nextStatus)}),
                    });
                    const result = await response.json();
                    if (!response.ok) { throw new Error(result.message || Object.values(result.errors || {}).flat().join(' ') || 'The status could not be changed.'); }
                    const active = nextStatus === '1';
                    const row = document.querySelector(`[data-user-row="${userId}"]`);
                    row.classList.toggle('is-inactive-row', !active);
                    button.dataset.status = active ? '0' : '1';
                    button.classList.toggle('is-active', active);
                    button.title = active ? 'DISABLE USER' : 'ENABLE USER';
                    button.setAttribute('aria-label', `${active ? 'Disable' : 'Enable'} ${row.children[1].textContent}`);
                    button.innerHTML = `<i class="fas fa-${active ? 'toggle-on' : 'toggle-off'}" aria-hidden="true"></i>`;
                    message.textContent = result.data;
                } catch (error) {
                    message.textContent = error.message || 'The status could not be changed.';
                } finally {
                    button.disabled = false;
                }
            }));
        })();
    </script>
@endsection
