@extends('layouts.dms-reference')

@section('title', 'Edit User - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">EDIT USER <span class="text-info">{{ $user->username }}</span></h3>
        </div>
        <div class="block-content">
            <div class="row justify-content-center py-1">
                <div class="col-sm-10">
                    <form id="install-username-form" action="{{ route('console.infrastructure.install-username.update', $workspace->id) }}" method="post">
                        @csrf
                        @method('put')
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" class="form-control form-control-alt" id="username" name="username"
                                placeholder="Enter Username ..." value="{{ $user->username }}" minlength="4" maxlength="255" required>
                            <small class="form-text text-muted">Use the DMS username that appears in this server's name.</small>
                        </div>
                        <div class="form-group">
                            <div id="username-result" class="alert alert-danger d-none" role="alert" aria-live="polite"></div>
                            <button id="edit-username" class="btn btn-block btn-warning" type="submit">EDIT</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelector('#install-username-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const button = form.querySelector('#edit-username');
            const result = form.querySelector('#username-result');
            button.disabled = true;
            button.textContent = 'UPDATING';
            result.classList.add('d-none');
            result.textContent = '';

            try {
                const response = await fetch(form.action, {
                    method: 'PUT',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value,
                    },
                    body: JSON.stringify({ username: form.querySelector('#username').value }),
                });
                const body = await response.json();
                if (!response.ok) {
                    const errors = Object.values(body.errors ?? {}).flat();
                    throw new Error(errors.join(' ') || 'Username could not be updated.');
                }
                window.location.assign(@json(route('console.infrastructure.bulk-install', $workspace->id)));
            } catch (error) {
                result.textContent = error.message;
                result.classList.remove('d-none');
            } finally {
                button.disabled = false;
                button.textContent = 'EDIT';
            }
        });
    </script>
@endpush
