@extends('layouts.standalone')

@section('title', $provider ? 'Edit Server Provider' : 'Add Server Provider')

@section('content')
    <div class="command-modal-surface">
        <div class="command-modal-hero">
            <div class="command-modal-hero-left">
                <div class="command-modal-hero-icon">
                    <i class="fas fa-server"></i>
                </div>
                <div>
                    <div class="command-modal-hero-eyebrow">HARDWARE VENDORS // INFRASTRUCTURE API</div>
                    <h3 class="command-modal-hero-title">
                        @if ($provider)
                            EDIT PROVIDER <span class="badge-command-pill ml-2" style="font-weight: 700; color: #8b5cf6;">#{{ $provider->id }} &bull; {{ $provider->name }}</span>
                        @else
                            CONNECT SERVER PROVIDER
                        @endif
                    </h3>
                </div>
            </div>
        </div>

        <div class="command-modal-body">
            @php
                $saveUrl = $provider
                    ? route('console.infrastructure.server-providers.update', [$workspace->id, $provider->id])
                    : route('console.infrastructure.server-providers.store', $workspace->id);
            @endphp
            <form id="server-provider-form" action="{{ $saveUrl }}" method="post">
                @csrf
                <div class="command-form-group">
                    <label class="command-form-label" for="name">
                        <i class="fas fa-tag mr-1 text-purple"></i> PROVIDER NAME
                    </label>
                    <input type="text" class="command-form-control" id="name" name="name"
                           placeholder="Enter Provider Name ..." value="{{ $provider?->name }}"
                           minlength="{{ $provider ? 4 : 2 }}" maxlength="255" required>
                </div>

                <div class="command-form-group">
                    <label class="command-form-label" for="url">
                        <i class="fas fa-link mr-1 text-purple"></i> PORTAL URL / LINK
                    </label>
                    <input type="url" class="command-form-control" id="url" name="url"
                           placeholder="https://provider.com/portal ..." value="{{ $provider?->url }}" maxlength="255" required>
                </div>

                <div class="command-form-group">
                    <label class="command-form-label" for="username">
                        <i class="fas fa-user mr-1 text-purple"></i> USERNAME
                    </label>
                    <input type="text" class="command-form-control" id="username" name="username"
                           placeholder="Enter Provider Username ..." value="{{ $provider?->username }}"
                           minlength="4" maxlength="255">
                </div>

                <div class="command-form-group">
                    <label class="command-form-label" for="password">
                        <i class="fas fa-lock mr-1 text-purple"></i> PASSWORD
                    </label>
                    <input type="password" class="command-form-control" id="password" name="password"
                           placeholder="{{ $provider ? 'Leave empty to keep saved password' : 'Enter Provider Password ...' }}"
                           minlength="4" maxlength="255" @required(! $provider) autocomplete="new-password">
                    @if ($provider)
                        <small class="text-muted mt-1 d-block" style="font-size: 0.75rem;">Leave empty to keep the saved password.</small>
                    @endif
                </div>

                <div id="provider-result" class="alert alert-danger d-none mb-3" role="alert" aria-live="polite" style="border-radius: 12px; font-size: 0.8125rem;"></div>

                <div class="command-modal-actions mt-4">
                    <button type="button" class="btn-command-action" onclick="if(window.parent!==window&&typeof window.parent.closeModal==='function'){window.parent.closeModal();}else{window.history.back();}">
                        <i class="fas fa-times mr-1"></i> CANCEL
                    </button>
                    <button id="save-provider" class="btn-command-primary" type="submit">
                        <i class="fas fa-save mr-1"></i> {{ $provider ? 'UPDATE PROVIDER' : 'CONNECT PROVIDER' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelector('#server-provider-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const button = form.querySelector('#save-provider');
            const result = form.querySelector('#provider-result');
            const payload = Object.fromEntries(new FormData(form));
            button.disabled = true;
            button.textContent = @json($provider ? 'UPDATING...' : 'SAVING...');
            result.classList.add('d-none');
            result.textContent = '';

            try {
                const response = await fetch(form.action, {
                    method: @json($provider ? 'PUT' : 'POST'),
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value,
                    },
                    body: JSON.stringify(payload),
                });
                const body = await response.json();
                if (!response.ok) {
                    throw new Error(Object.values(body.errors ?? {}).flat().join(' ') || body.message || 'Provider could not be saved.');
                }
                const parentWindow = window.parent !== window && typeof window.parent.closeModal === 'function' ? window.parent : window;
                if (parentWindow.App?.swalPromise) {
                    await parentWindow.App.swalPromise({ icon: 'success', title: body.data, confirmButtonText: 'close' });
                    parentWindow.closeModal();
                } else {
                    window.alert(body.data);
                    window.location.assign(@json(route('console.infrastructure.server-providers.index', $workspace->id)));
                }
            } catch (error) {
                result.textContent = error.message;
                result.classList.remove('d-none');
            } finally {
                button.disabled = false;
                button.textContent = @json($provider ? 'UPDATE PROVIDER' : 'CONNECT PROVIDER');
            }
        });
    </script>
@endpush
