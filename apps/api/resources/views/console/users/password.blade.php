@extends('layouts.dms-admin')

@section('title', 'Edit User Password - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header"><h3 class="block-title">EDIT USER <span class="text-info">{{ $user->username }}</span> PASSWORD</h3></div>
        <div class="block-content"><div class="row justify-content-center"><div class="col-sm-10 col-lg-8">
            <form id="user-password-form" novalidate>
                <div class="form-group"><label for="password">New Password</label><input type="password" class="form-control" id="password" name="password" required autocomplete="new-password"></div>
                <div class="form-group"><button class="btn btn-info" id="save-password" type="submit">EDIT</button> <a class="btn btn-light" href="{{ route('console.users.index', $workspace->id) }}">CANCEL</a></div>
                <div class="alert alert-danger d-none" id="password-error" role="alert"></div><p class="text-success" id="password-success" role="status" aria-live="polite"></p>
            </form>
        </div></div></div>
    </div>
    <script>
        (() => {
            const form = document.getElementById('user-password-form');
            const button = document.getElementById('save-password');
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const error = document.getElementById('password-error');
                error.classList.add('d-none');
                button.disabled = true;
                button.textContent = 'SAVING…';
                try {
                    const response = await fetch(@json(route('console.users.password.update', [$workspace->id, $user->id])), {
                        method: 'PUT', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content}, body: JSON.stringify({password: document.getElementById('password').value}),
                    });
                    const result = await response.json();
                    if (!response.ok) { throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'The password could not be changed.'); }
                    document.getElementById('password-success').textContent = result.data;
                    button.textContent = 'SAVED';
                } catch (cause) {
                    error.textContent = cause.message || 'The password could not be changed.';
                    error.classList.remove('d-none');
                    button.disabled = false;
                    button.textContent = 'EDIT';
                }
            });
        })();
    </script>
@endsection
