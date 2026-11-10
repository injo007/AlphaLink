@extends('layouts.standalone')

@section('title', 'Edit Username')

@section('content')
    <div class="command-modal-surface">
        <div class="command-modal-hero">
            <div class="command-modal-hero-left">
                <div class="command-modal-hero-icon">
                    <i class="fas fa-user-edit"></i>
                </div>
                <div>
                    <div class="command-modal-hero-eyebrow">WORKSPACE PROVISIONING PROFILE</div>
                    <h3 class="command-modal-hero-title">
                        EDIT USERNAME <span class="badge-command-pill ml-2" style="font-weight: 700; color: #8b5cf6;">{{ $user->username }}</span>
                    </h3>
                </div>
            </div>
        </div>

        <div class="command-modal-body">
            <form id="install-username-form" action="{{ route('console.infrastructure.install-username.update', $workspace->id) }}" method="post">
                @csrf
                @method('put')
                <div class="command-form-group">
                    <label class="command-form-label" for="username">
                        <i class="fas fa-user mr-1 text-purple"></i> USERNAME
                    </label>
                    <input type="text" class="command-form-control" id="username" name="username"
                        placeholder="Enter Username ..." value="{{ $user->username }}" minlength="4" maxlength="255" required>
                    <small class="text-muted mt-1 d-block" style="font-size: 0.75rem;">Use the identifier that appears in your server host names.</small>
                </div>

                <div id="username-result" class="alert alert-danger d-none mb-3" role="alert" aria-live="polite" style="border-radius: 12px; font-size: 0.8125rem;"></div>

                <div class="command-modal-actions mt-4">
                    <button id="edit-username" class="btn-command-primary" type="submit">
                        <i class="fas fa-save mr-1"></i> UPDATE USERNAME
                    </button>
                </div>
            </form>
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
            button.textContent = 'UPDATING...';
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
                button.textContent = 'UPDATE USERNAME';
            }
        });
    </script>
@endpush
