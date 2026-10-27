@extends($workspace ? 'layouts.dms-admin' : 'layouts.global-admin')

@section('title', 'Add User - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header"><h3 class="block-title">ADD USER</h3></div>
        <div class="block-content"><div class="row justify-content-center"><div class="col-sm-10 col-lg-8">
            <form id="global-user-form" novalidate>
                <div class="form-group"><label for="full_name">Full Name</label><input class="form-control" id="full_name" name="full_name" minlength="4" maxlength="255" required autocomplete="name"></div>
                <div class="form-group"><label for="username">Username</label><input class="form-control" id="username" name="username" minlength="4" maxlength="255" required autocomplete="username" autocapitalize="none"></div>
                <div class="form-group"><label for="type">User Type</label><select class="form-control" id="type" name="type" required><option value="">Select User Type ...</option>@foreach ($types as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach</select></div>
                <fieldset class="form-group" id="role-container" hidden><legend class="col-form-label">User Roles</legend><div class="row">@foreach ($roles as $key => $label)<div class="col-sm-6"><div class="custom-control custom-checkbox"><input class="custom-control-input" id="role-{{ $key }}" name="roles[]" type="checkbox" value="{{ $key }}"><label class="custom-control-label" for="role-{{ $key }}">{{ $label }}</label></div></div>@endforeach</div></fieldset>
                <div class="form-group"><button class="btn btn-info" id="save-user" type="submit">SAVE</button> <a class="btn btn-light" href="{{ route('console.global-users.index') }}">CANCEL</a></div>
                <div class="alert alert-danger d-none" id="user-error" role="alert"></div>
                <section class="alert alert-success d-none" id="created-credentials" aria-live="polite"><strong id="created-message"></strong><p class="mt-2 mb-2">Copy these login details now. The temporary password will not be shown again.</p><label for="created-login">Username:password</label><input class="form-control" id="created-login" readonly><button class="btn btn-success mt-2" id="copy-login" type="button">COPY LOGIN AND PASSWORD</button></section>
            </form>
        </div></div></div>
    </div>
    <script>
        (() => {
            const form = document.getElementById('global-user-form');
            const button = document.getElementById('save-user');
            const roleContainer = document.getElementById('role-container');
            const error = document.getElementById('user-error');
            const type = document.getElementById('type');
            type.addEventListener('change', () => {
                roleContainer.hidden = type.value !== 'mailer';
            });
            form.addEventListener('submit', async (event) => {
                event.preventDefault(); error.classList.add('d-none'); button.disabled = true; button.textContent = 'SAVING…';
                const data = new FormData(form);
                const payload = {username: data.get('username'), full_name: data.get('full_name'), type: data.get('type'), roles: data.getAll('roles[]')};
                try {
                    const response = await fetch(@json(route('console.global-users.store')), {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content}, body: JSON.stringify(payload)});
                    const result = await response.json();
                    if (!response.ok) { throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'The account could not be created.'); }
                    document.getElementById('created-message').textContent = result.data;
                    document.getElementById('created-login').value = result.info;
                    document.getElementById('created-credentials').classList.remove('d-none');
                    form.querySelectorAll('#full_name, #username, #type, #role-container').forEach((field) => { field.disabled = true; });
                    roleContainer.querySelectorAll('input').forEach((field) => { field.disabled = true; });
                    button.classList.add('d-none');
                } catch (cause) {
                    error.textContent = cause.message || 'The account could not be created.'; error.classList.remove('d-none'); button.disabled = false; button.textContent = 'SAVE';
                }
            });
            document.getElementById('copy-login').addEventListener('click', async () => {
                const value = document.getElementById('created-login').value;
                try { await navigator.clipboard.writeText(value); document.getElementById('copy-login').textContent = 'COPIED'; }
                catch { document.getElementById('created-login').select(); document.execCommand('copy'); document.getElementById('copy-login').textContent = 'COPIED'; }
            });
        })();
    </script>
@endsection
