@extends($workspace ? 'layouts.admin-shell' : 'layouts.global-admin')

@section('title', 'Edit User')

@section('content')
    <div class="block block-rounded">
        <div class="block-header"><h3 class="block-title">EDIT USER <span class="text-info">{{ $user->username }}</span></h3></div>
        <div class="block-content"><div class="row justify-content-center"><div class="col-sm-10 col-lg-8">
            <form id="edit-global-user-form" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="_method" value="PUT">
                @include('console.global-users._avatar-field', ['avatarUser' => $user])
                <div class="form-group"><label for="full_name">Full Name</label><input class="form-control" id="full_name" name="full_name" minlength="4" maxlength="255" required value="{{ $user->name }}"></div>
                <div class="form-group"><label for="username">Username</label><input class="form-control" id="username" name="username" minlength="4" maxlength="255" required value="{{ $user->username }}" autocapitalize="none"></div>
                <div class="form-group"><label for="access_role">Global Access Role</label><select class="form-control" id="access_role" name="access_role" required>@foreach ($accessRoles as $role)<option value="{{ $role }}" @selected($role === $user->access_role)>{{ \App\Models\User::ACCESS_ROLE_LABELS[$role] }}</option>@endforeach</select><small class="form-text text-muted">Owner and Admin have full access; only Owner arranges shared navigation. Operator, Viewer, and Privacy Officer use role defaults that you can customize below for this account.</small></div>
                <div class="form-group"><label for="type">Account Type</label><select class="form-control" id="type" name="type" required>@foreach ($types as $type)<option value="{{ $type }}" @selected($type === $user->type)>{{ $type }}</option>@endforeach</select><small class="form-text text-muted">Mailer accounts show a sending-access preset. Access Role and the page permissions below determine what this account can use.</small></div>
                @include('console.global-users._permissions-field')
                <div class="form-group"><button class="btn btn-warning" id="save-user" type="submit">EDIT</button> <a class="btn btn-light" href="{{ route('console.global-users.index') }}">CANCEL</a></div>
                <div class="alert alert-danger d-none" id="user-error" role="alert"></div><p class="text-success" id="user-success" role="status" aria-live="polite"></p>
            </form>
        </div></div></div>
    </div>
    @if ($workspace && auth()->user()->hasFullAccess())
        <section class="global-user-comms-section" aria-labelledby="global-user-comms-title">
            <div class="global-user-comms-heading">
                <div><p>GLOBAL USER ACCOUNT</p><h2 id="global-user-comms-title">Tasks &amp; messages</h2><span>Recent workspace updates addressed to this account.</span></div>
                @if ($operatorInWorkspace)<span class="global-user-comms-recipient">Sending to {{ $user->name ?: $user->username }}</span>@endif
            </div>
            @if (session('communication_status'))
                <div class="workspace-comms-alert" role="status">{{ session('communication_status') }}</div>
            @endif
            @if ($errors->any())
                <div class="workspace-comms-alert workspace-comms-alert-error" role="alert">{{ $errors->first() }}</div>
            @endif
            @if ($operatorInWorkspace)
                <form method="post" action="{{ route('console.workspace-communications.store', ['workspace' => $workspace->getKey()]) }}" class="global-user-comms-compose" data-account-communication-form>
                    @csrf
                    <input type="hidden" name="audience" value="operator">
                    <input type="hidden" name="operator_id" value="{{ $user->id }}">
                    <input type="hidden" name="return_user_id" value="{{ $user->id }}">
                    <div class="global-user-comms-compose-heading"><div><span>NEW UPDATE</span><h3>Send directly to this operator</h3></div><small>Appears in their team inbox or notification bell.</small></div>
                    <div class="global-user-comms-compose-fields">
                        <label class="workspace-comms-field"><span>Update type</span><select name="kind" data-account-kind required><option value="task" @selected(old('kind', 'task') === 'task')>Task</option><option value="message" @selected(old('kind') === 'message')>Message</option><option value="notification" @selected(old('kind') === 'notification')>Notification</option></select></label>
                        <label class="workspace-comms-field"><span>Title</span><input name="title" type="text" minlength="3" maxlength="140" required value="{{ old('title') }}" placeholder="A clear, short title"></label>
                        @include('console.workspace-communications._tone-field', ['selectedTone' => old('tone', 'standard')])
                        <label class="workspace-comms-field global-user-comms-compose-body"><span>Details</span><textarea name="body" rows="3" minlength="3" maxlength="5000" required placeholder="Add the context and next steps…">{{ old('body') }}</textarea></label>
                        <label class="workspace-comms-field global-user-comms-compose-due" data-account-due @if (old('kind', 'task') !== 'task') hidden @endif><span>Due date <em>Optional</em></span><input type="datetime-local" name="due_at" value="{{ old('due_at') }}"></label>
                        <button class="workspace-comms-submit" type="submit"><span aria-hidden="true">↗</span> Send to operator</button>
                    </div>
                </form>
            @else
                <p class="global-user-comms-empty">Direct updates can be sent from here when this account is an active operator in {{ $workspace->name }}. Team wide updates are managed in Workspace Settings.</p>
            @endif
            @if ($communicationsForUser->isEmpty())
                <p class="global-user-comms-empty">No task, message, or notification has been sent to this account in {{ $workspace->name }}.</p>
            @else
                <div class="global-user-comms-list">
                    @foreach ($communicationsForUser as $recipientUpdate)
                        @php($update = $recipientUpdate->communication)
                        <article><div class="global-user-comms-item-heading"><span class="workspace-comms-kind workspace-comms-kind-{{ $update->kind }}">{{ ucfirst($update->kind) }}</span><span class="workspace-comms-priority tone-{{ $update->tone }}">{{ \App\Models\WorkspaceCommunication::TONE_LABELS[$update->tone] ?? 'Standard' }}</span><time datetime="{{ $update->created_at->toIso8601String() }}">{{ $update->created_at->format('M j, Y · H:i') }}</time></div><h3>{{ $update->title }}</h3><p>{{ $update->body }}</p><footer><span>From {{ $update->author?->name ?? 'Workspace admin' }}</span><span>{{ $recipientUpdate->completed_at ? 'Completed' : ($recipientUpdate->read_at ? 'Read' : 'Unread') }}</span></footer></article>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
    <script>
        (() => {
            const communicationForm = document.querySelector('[data-account-communication-form]');
            if (communicationForm) {
                const kind = communicationForm.querySelector('[data-account-kind]');
                const due = communicationForm.querySelector('[data-account-due]');
                const syncDue = () => { due.hidden = kind.value !== 'task'; };
                kind.addEventListener('change', syncDue);
                syncDue();
            }

            const form = document.getElementById('edit-global-user-form'); const button = document.getElementById('save-user'); const error = document.getElementById('user-error');
            form.addEventListener('submit', async (event) => {
                event.preventDefault(); error.classList.add('d-none'); button.disabled = true; button.textContent = 'UPDATING…'; const data = new FormData(form);
                try {
                    data.set('_method', 'PUT');
                    const response = await fetch(@json(route('console.global-users.update', $user->id)), {method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content}, body: data});
                    const result = await response.json(); if (!response.ok) { throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'The account could not be updated.'); }
                    document.getElementById('user-success').textContent = result.data; button.textContent = 'UPDATED';
                    window.setTimeout(() => window.location.reload(), 650);
                } catch (cause) { error.textContent = cause.message || 'The account could not be updated.'; error.classList.remove('d-none'); button.disabled = false; button.textContent = 'EDIT'; }
            });
        })();
    </script>
@endsection
