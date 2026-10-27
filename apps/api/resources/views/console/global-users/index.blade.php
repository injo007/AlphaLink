@extends($workspace ? 'layouts.dms-admin' : 'layouts.global-admin')

@section('title', 'Users - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">USERS</h3>
            <div class="block-options">
                <button class="btn-block-option" type="button" data-toggle="block-option" data-action="fullscreen_toggle" title="FULLSCREEN" aria-label="Toggle fullscreen"></button>
                <a class="btn-block-option" href="{{ route('console.global-users.index') }}" title="REFRESH" aria-label="Refresh users"><i class="fas fa-sync" aria-hidden="true"></i></a>
                <a class="btn btn-sm btn-primary" href="{{ route('console.global-users.create') }}"><i class="fa fa-plus mr-1" aria-hidden="true"></i> ADD USER</a>
            </div>
        </div>
        <div class="block-content block-content-full">
            @if ($workspace)
                <p class="text-muted">Global account directory. Workspace access is assigned separately from account roles.</p>
            @else
                <p class="text-muted">Global account directory. Attach an account to a workspace to grant workspace access.</p>
            @endif
            <div class="table-responsive">
                <table class="table table-sm table-hover table-striped" id="global-users-table">
                    <thead><tr><th>#</th><th>USERNAME</th><th>NAME</th><th>TYPE</th><th>SEEN AT</th><th>ACTIONS</th></tr></thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr data-user-row="{{ $user->id }}" @class(['table-danger' => ! $user->is_active])>
                                <td>{{ $user->id }}</td>
                                <td>{{ $user->username }}</td>
                                <td>{{ $user->name }}</td>
                                <td><span class="badge badge-{{ $user->type === 'admin' ? 'primary' : 'info' }} p-1">{{ $user->type }}</span></td>
                                <td data-order="{{ $user->seen_at?->timestamp ?? 0 }}">{{ $user->seen_at?->diffForHumans() ?? 'NA' }}</td>
                                <td class="text-nowrap">
                                    <a class="btn btn-info btn-sm" href="{{ route('console.global-users.edit', $user->id) }}" title="UPDATE" aria-label="Update {{ $user->username }}"><i class="fas fa-pencil-alt" aria-hidden="true"></i></a>
                                    <button class="btn btn-{{ $user->is_active ? 'warning' : 'success' }} btn-sm" type="button" data-user-status="{{ $user->id }}" data-status="{{ $user->is_active ? 0 : 1 }}" @disabled(auth()->user()->is($user)) title="{{ $user->is_active ? 'DISABLE USER' : 'ENABLE USER' }}" aria-label="{{ $user->is_active ? 'Disable' : 'Enable' }} {{ $user->username }}"><i class="fas fa-{{ $user->is_active ? 'times' : 'check' }}" aria-hidden="true"></i></button>
                                    <a class="btn btn-secondary btn-sm" href="{{ route('console.global-users.password', $user->id) }}" title="EDIT PASSWORD" aria-label="Edit password for {{ $user->username }}"><i class="fas fa-unlock-alt" aria-hidden="true"></i></a>
                                    @if (auth()->user()->canImpersonate() && $user->canBeImpersonated())
                                        <form method="post" action="{{ route('console.global-users.impersonation.start', $user->id) }}" class="d-inline">@csrf<button class="btn btn-dark btn-sm" type="submit" title="IMPERSONATE" aria-label="Impersonate {{ $user->username }}"><i class="fas fa-user-ninja" aria-hidden="true"></i></button></form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p id="global-users-message" role="status" aria-live="polite" class="mb-0"></p>
        </div>
    </div>
    <script>
        (() => {
            const table = window.jQuery?.('#global-users-table');
            if (table && typeof table.DataTable === 'function') {
                table.DataTable({pageLength: 50, order: [[4, 'desc']]});
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
                    row.classList.toggle('table-danger', !active);
                    button.dataset.status = active ? '0' : '1';
                    button.classList.toggle('btn-warning', active);
                    button.classList.toggle('btn-success', !active);
                    button.title = active ? 'DISABLE USER' : 'ENABLE USER';
                    button.setAttribute('aria-label', `${active ? 'Disable' : 'Enable'} ${row.children[1].textContent}`);
                    button.innerHTML = `<i class="fas fa-${active ? 'times' : 'check'}" aria-hidden="true"></i>`;
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
