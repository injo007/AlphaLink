@extends($workspace ? 'layouts.dms-admin' : 'layouts.global-admin')

@section('title', 'Edit User - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header"><h3 class="block-title">EDIT USER <span class="text-info">{{ $user->username }}</span></h3></div>
        <div class="block-content"><div class="row justify-content-center"><div class="col-sm-10 col-lg-8">
            <form id="edit-global-user-form" novalidate>
                <div class="form-group"><label for="full_name">Full Name</label><input class="form-control" id="full_name" name="full_name" minlength="4" maxlength="255" required value="{{ $user->name }}"></div>
                <div class="form-group"><label for="username">Username</label><input class="form-control" id="username" name="username" minlength="4" maxlength="255" required value="{{ $user->username }}" autocapitalize="none"></div>
                <div class="form-group"><label for="type">User Type</label><select class="form-control" id="type" name="type" required>@foreach ($types as $type)<option value="{{ $type }}" @selected($type === $user->type)>{{ $type }}</option>@endforeach</select></div>
                <fieldset class="form-group" id="role-container" @if ($user->type === 'admin') hidden @endif><legend class="col-form-label">User Roles</legend><div class="row">@foreach ($roles as $key => $label)<div class="col-sm-6"><div class="custom-control custom-checkbox"><input class="custom-control-input" id="role-{{ $key }}" name="roles[]" type="checkbox" value="{{ $key }}" @checked(in_array($key, $user->roles ?? [], true))><label class="custom-control-label" for="role-{{ $key }}">{{ $label }}</label></div></div>@endforeach</div></fieldset>
                <div class="form-group"><button class="btn btn-warning" id="save-user" type="submit">EDIT</button> <a class="btn btn-light" href="{{ route('console.global-users.index') }}">CANCEL</a></div>
                <div class="alert alert-danger d-none" id="user-error" role="alert"></div><p class="text-success" id="user-success" role="status" aria-live="polite"></p>
            </form>
        </div></div></div>
    </div>
    <script>
        (() => {
            const form = document.getElementById('edit-global-user-form'); const button = document.getElementById('save-user'); const error = document.getElementById('user-error'); const roleContainer = document.getElementById('role-container'); const type = document.getElementById('type');
            type.addEventListener('change', () => { roleContainer.hidden = type.value === 'admin'; });
            form.addEventListener('submit', async (event) => {
                event.preventDefault(); error.classList.add('d-none'); button.disabled = true; button.textContent = 'UPDATING…'; const data = new FormData(form);
                const payload = {username: data.get('username'), full_name: data.get('full_name'), type: data.get('type'), roles: data.getAll('roles[]')};
                try {
                    const response = await fetch(@json(route('console.global-users.update', $user->id)), {method: 'PUT', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content}, body: JSON.stringify(payload)});
                    const result = await response.json(); if (!response.ok) { throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'The account could not be updated.'); }
                    document.getElementById('user-success').textContent = result.data; button.textContent = 'UPDATED';
                } catch (cause) { error.textContent = cause.message || 'The account could not be updated.'; error.classList.remove('d-none'); button.disabled = false; button.textContent = 'EDIT'; }
            });
        })();
    </script>
@endsection
