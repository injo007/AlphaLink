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
                <div class="form-group"><label for="access_role">Global Access Role</label><select class="form-control" id="access_role" name="access_role" required>@foreach ($accessRoles as $role)<option value="{{ $role }}" @selected($role === $user->access_role)>{{ \App\Models\User::ACCESS_ROLE_LABELS[$role] }}</option>@endforeach</select><small class="form-text text-muted">Owner alone can arrange navigation; Owner and Admin have full access. Operator runs campaign and audience operations. Viewer reviews approved summaries. Privacy Officer manages contact and suppression workflows.</small></div>
                <div class="form-group"><label for="type">Account Type</label><select class="form-control" id="type" name="type" required>@foreach ($types as $type)<option value="{{ $type }}" @selected($type === $user->type)>{{ $type }}</option>@endforeach</select><small class="form-text text-muted">Account Type preserves legacy login behavior; permissions come from Global Access Role.</small></div>
                @include('console.global-users._permissions-field')
                <div class="form-group"><button class="btn btn-warning" id="save-user" type="submit">EDIT</button> <a class="btn btn-light" href="{{ route('console.global-users.index') }}">CANCEL</a></div>
                <div class="alert alert-danger d-none" id="user-error" role="alert"></div><p class="text-success" id="user-success" role="status" aria-live="polite"></p>
            </form>
        </div></div></div>
    </div>
    <script>
        (() => {
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
