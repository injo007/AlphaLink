@extends('layouts.dms-reference')

@section('title', $provider ? 'Edit Provider' : 'Add domains Provider')

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
                            ? route('clean.console.infrastructure.domain-providers.update', $provider->id)
                            : route('clean.console.infrastructure.domain-providers.store');
                        $testUrl = $provider
                            ? route('clean.console.infrastructure.domain-providers.test-existing-connection', $provider->id)
                            : route('clean.console.infrastructure.domain-providers.test-new-connection');
                    @endphp
                    <form id="domain-provider-form" action="{{ $saveUrl }}" data-test-url="{{ $testUrl }}" method="post">
                        @csrf
                        <div class="form-group">
                            <label for="name">Name:</label>
                            <input type="text" class="form-control form-control-alt" id="name" name="name" placeholder="Enter Provide Name ..." value="{{ $provider?->name }}" minlength="4" maxlength="255" required>
                        </div>
                        <div class="form-group">
                            <label for="type">Provider Type:</label>
                            <select class="form-control selectpicker" id="type" name="type" required>
                                @foreach ($types as $type)
                                    <option value="{{ $type }}" @selected($provider?->type === $type)>{{ $type }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="username">Username:</label>
                            <input type="text" class="form-control form-control-alt" id="username" name="username" placeholder="Enter Username ..." value="{{ $provider?->username }}" minlength="4" maxlength="255">
                        </div>
                        <div class="form-group">
                            <label for="password">Password:</label>
                            <input type="password" class="form-control form-control-alt" id="password" name="password" placeholder="Enter Password ..." minlength="4" maxlength="255" autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label for="api_key" id="api-key-label">Api Key:</label>
                            <input type="password" class="form-control form-control-alt" id="api_key" name="api_key" placeholder="{{ $provider ? 'Leave empty to keep saved credential' : 'Enter Api Key ...' }}" minlength="4" maxlength="255" autocomplete="new-password" @required(! $provider)>
                        </div>
                        <div class="form-group">
                            <label for="api_secret">Api Secret: <small>(Leave Empty If None)</small></label>
                            <input type="password" class="form-control form-control-alt" id="api_secret" name="api_secret" placeholder="Enter Api Secret ..." minlength="4" maxlength="255" autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <div id="provider-result" class="alert d-none" role="status" aria-live="polite"></div>
                            <div class="d-flex flex-wrap justify-content-between">
                                <button id="test-provider" class="btn btn-alt-primary mb-2" type="button">TEST CONNECTION</button>
                                <button id="save-provider" class="btn {{ $provider ? 'btn-warning' : 'btn-info' }} mb-2" type="submit">{{ $provider ? 'EDIT' : 'SAVE' }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const providerType = document.querySelector('#type');
        const username = document.querySelector('#username');
        const password = document.querySelector('#password');
        const apiSecret = document.querySelector('#api_secret');
        const apiKey = document.querySelector('#api_key');
        const apiKeyLabel = document.querySelector('#api-key-label');
        const originalProviderType = @json($provider?->type);
        const isNewProvider = @json(! $provider);
        const testButton = document.querySelector('#test-provider');
        const resultBox = document.querySelector('#provider-result');
        const syncProviderFields = () => {
            const cloudflare = providerType.value === 'cloudflare';
            username.closest('.form-group').hidden = cloudflare;
            password.closest('.form-group').hidden = cloudflare;
            apiSecret.closest('.form-group').hidden = cloudflare;
            username.required = !cloudflare;
            apiKeyLabel.textContent = cloudflare ? 'Cloudflare API Token:' : 'Api Key:';
            apiKey.placeholder = cloudflare
                ? 'Token with Zone Read, DNS Read, and DNS Write permissions'
                : @json($provider ? 'Leave empty to keep saved credential' : 'Enter Api Key ...');
            apiKey.required = isNewProvider || originalProviderType !== providerType.value;
        };
        providerType.addEventListener('change', syncProviderFields);
        syncProviderFields();

        testButton.addEventListener('click', async () => {
            const form = document.querySelector('#domain-provider-form');
            const originalText = testButton.textContent;
            testButton.disabled = true;
            testButton.textContent = 'TESTING…';
            resultBox.className = 'alert d-none';
            resultBox.textContent = '';

            try {
                const response = await fetch(form.dataset.testUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value,
                    },
                    body: JSON.stringify(Object.fromEntries(new FormData(form))),
                });
                const body = await response.json();
                if (!response.ok) {
                    const errors = Object.values(body.errors ?? {}).flat();
                    throw new Error(errors.join(' ') || 'Connection check could not be completed.');
                }
                resultBox.className = `alert alert-${body.success ? 'success' : 'danger'}`;
                resultBox.textContent = body.message;
            } catch (error) {
                resultBox.className = 'alert alert-danger';
                resultBox.textContent = error.message || 'Connection check could not be completed.';
            } finally {
                testButton.disabled = false;
                testButton.textContent = originalText;
            }
        });

        document.querySelector('#domain-provider-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const button = form.querySelector('#save-provider');
            const result = form.querySelector('#provider-result');
            const payload = Object.fromEntries(new FormData(form));
            button.disabled = true;
            button.textContent = 'SAVING';
            result.className = 'alert alert-danger d-none';
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
                    const errors = Object.values(body.errors ?? {}).flat();
                    throw new Error(errors.join(' ' ) || 'Provider could not be saved.');
                }
                const appWindow = window.parent !== window && window.parent.App ? window.parent : window;
                await appWindow.App.swalPromise({ icon: 'success', title: body.data, confirmButtonText: 'close' });
                if (window.parent !== window && typeof window.parent.closeModal === 'function') {
                    window.parent.closeModal();
                } else {
                    window.location.assign(@json(route('clean.console.infrastructure.domain-providers.index')));
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
