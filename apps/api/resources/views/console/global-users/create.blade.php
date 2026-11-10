@extends($workspace ? 'layouts.admin-shell' : 'layouts.global-admin')

@section('title', 'Add User')

@section('content')
    <div class="block block-rounded">
        <div class="block-header"><h3 class="block-title">ADD USER</h3></div>
        <div class="block-content"><div class="row justify-content-center"><div class="col-sm-10 col-lg-8">
            <form id="global-user-form" enctype="multipart/form-data" novalidate>
                @include('console.global-users._avatar-field', ['avatarUser' => null])
                <div class="form-group"><label for="full_name">Full Name</label><input class="form-control" id="full_name" name="full_name" minlength="4" maxlength="255" required autocomplete="name"></div>
                <div class="form-group"><label for="username">Username</label><input class="form-control" id="username" name="username" minlength="4" maxlength="255" required autocomplete="username" autocapitalize="none"></div>
                <div class="form-group"><label for="access_role">Global Access Role</label><select class="form-control" id="access_role" name="access_role" required>@foreach ($accessRoles as $role)<option value="{{ $role }}" @selected($role === 'operator')>{{ \App\Models\User::ACCESS_ROLE_LABELS[$role] }}</option>@endforeach</select><small class="form-text text-muted">Owner alone can arrange navigation; Owner and Admin have full access. Operator runs campaign and audience operations. Viewer reviews approved summaries. Privacy Officer manages contact and suppression workflows.</small></div>
                <div class="form-group"><label for="type">Account Type</label><select class="form-control" id="type" name="type" required><option value="">Select Account Type ...</option>@foreach ($types as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach</select><small class="form-text text-muted">Account Type preserves legacy login behavior; permissions come from Global Access Role.</small></div>
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
            const error = document.getElementById('user-error');
            form.addEventListener('submit', async (event) => {
                event.preventDefault(); error.classList.add('d-none'); button.disabled = true; button.textContent = 'SAVING…';
                const data = new FormData(form);
                try {
                    const response = await fetch(@json(route('console.global-users.store')), {method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content}, body: data});
                    const result = await response.json();
                    if (!response.ok) { throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'The account could not be created.'); }
                    document.getElementById('created-message').textContent = result.data;
                    document.getElementById('created-login').value = result.info;
                    document.getElementById('created-credentials').classList.remove('d-none');
                    form.querySelectorAll('#full_name, #username, #type, #access_role, #global-user-photo-input').forEach((field) => { field.disabled = true; });
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
