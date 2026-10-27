@extends('layouts.dms-reference')

@section('title', $provider ? 'Edit Server Provider' : 'Add Server Provider')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">@if ($provider) EDIT PROVIDER <span class="text-info">{{ $provider->id }} | {{ $provider->name }}</span> @else ADD PROVIDER @endif</h3>
        </div>
        <div class="block-content">
            <div class="row justify-content-center py-1">
                <div class="col-sm-10">
                    @php
                        $saveUrl = $provider
                            ? route('console.infrastructure.server-providers.update', [$workspace->id, $provider->id])
                            : route('console.infrastructure.server-providers.store', $workspace->id);
                    @endphp
                    <form id="server-provider-form" action="{{ $saveUrl }}" method="post">
                        @csrf
                        <div class="form-group">
                            <label for="name">Name:</label>
                            <input type="text" class="form-control form-control-alt" id="name" name="name"
                                   placeholder="Enter Provider Name ..." value="{{ $provider?->name }}"
                                   minlength="{{ $provider ? 4 : 2 }}" maxlength="255" required>
                        </div>
                        <div class="form-group">
                            <label for="url">Link:</label>
                            <input type="url" class="form-control form-control-alt" id="url" name="url"
                                   placeholder="Enter Provider Link ..." value="{{ $provider?->url }}" maxlength="255" required>
                        </div>
                        <div class="form-group">
                            <label for="username">Username:</label>
                            <input type="text" class="form-control form-control-alt" id="username" name="username"
                                   placeholder="Enter Provider Username ..." value="{{ $provider?->username }}"
                                   minlength="4" maxlength="255">
                        </div>
                        <div class="form-group">
                            <label for="password">Password:</label>
                            <input type="password" class="form-control form-control-alt" id="password" name="password"
                                   placeholder="{{ $provider ? 'Leave empty to keep saved password' : 'Enter Provider Password ...' }}"
                                   minlength="4" maxlength="255" @required(! $provider) autocomplete="new-password">
                            @if ($provider)
                                <small class="form-text text-muted">Leave empty to keep the saved password.</small>
                            @endif
                        </div>
                        <div class="form-group">
                            <div id="provider-result" class="alert alert-danger d-none" role="alert" aria-live="polite"></div>
                            <button id="save-provider" class="btn btn-block {{ $provider ? 'btn-warning' : 'btn-info' }}" type="submit">{{ $provider ? 'EDIT' : 'SAVE' }}</button>
                        </div>
                    </form>
                </div>
            </div>
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
            button.textContent = @json($provider ? 'UPDATING' : 'SAVING');
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
                button.textContent = @json($provider ? 'EDIT' : 'SAVE');
            }
        });
    </script>
@endpush
