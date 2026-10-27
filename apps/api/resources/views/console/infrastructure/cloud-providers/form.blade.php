@extends('layouts.dms-reference')

@section('title', $provider ? 'Edit Cloud Provider' : 'Add Cloud Provider')

@php
    $saveUrl = $provider
        ? route('clean.console.infrastructure.cloud-providers.update', $provider->id)
        : route('clean.console.infrastructure.cloud-providers.store');
    $testUrl = $provider
        ? route('clean.console.infrastructure.cloud-providers.test-existing-connection', $provider->id)
        : route('clean.console.infrastructure.cloud-providers.test-new-connection');
    $saveMethod = $provider ? 'PUT' : 'POST';
@endphp

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">{{ $provider ? 'EDIT PROVIDER' : 'ADD PROVIDER' }}</h3>
        </div>
        <div class="block-content">
            <div class="row justify-content-center py-1">
                <div class="col-sm-10">
                    <div id="cloud-provider-errors" class="alert d-none" role="status" aria-live="polite"></div>
                    <div class="form-group">
                        <label for="type">Type:</label>
                        <select name="type" id="type" class="form-control selectpicker" data-live-search="true" required>
                            @foreach ($types as $type)
                                <option value="{{ $type }}" class="text-uppercase" @selected(old('type', $provider?->type) === $type)>{{ $type }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="name">Name:</label>
                        <input type="text" class="form-control form-control-alt" id="name" name="name"
                               placeholder="Enter Provider Name ..." value="{{ old('name', $provider?->name) }}" required>
                    </div>
                    <div class="form-group">
                        <label for="api_token">Api Token:</label>
                        <input type="password" class="form-control form-control-alt" id="api_token" name="api_token"
                               placeholder="{{ $provider ? 'Leave blank to keep current token' : 'Enter Provider Api ...' }}"
                               autocomplete="new-password" @if (! $provider) required @endif>
                        <small class="form-text text-muted" id="api-token-help">Test Connection checks read access to the instance list. It does not create or change cloud resources.</small>
                        @if ($provider)
                            <small class="form-text text-muted">Leave blank to keep the saved token. Enter a replacement when changing the provider type.</small>
                        @endif
                    </div>
                    <div class="form-group">
                        <label>
                            Allowed Mailers :
                            <button id="check" class="btn btn-link btn-sm" type="button">
                                CHECK <span class="fas fa-check" aria-hidden="true"></span>
                            </button>
                        </label>
                        <div class="row">
                            @foreach ($users->chunk(2) as $chunk)
                                <div class="col-sm-12 col-md-6">
                                    @foreach ($chunk as $user)
                                        <div class="custom-control custom-checkbox mb-1">
                                            <input type="checkbox" value="{{ $user->id }}"
                                                   class="custom-control-input" id="allowed-mailer-{{ $user->id }}"
                                                   name="allowed_users[]"
                                                   @checked(in_array((string) $user->id, array_map('strval', old('allowed_users', $provider?->allowed_users ?? [])), true))>
                                            <label class="custom-control-label" for="allowed-mailer-{{ $user->id }}">
                                                {{ $user->username }} | {{ $user->name }}
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="form-group d-flex flex-wrap justify-content-between">
                        <button id="test-cloud-provider" class="btn btn-alt-primary mb-2" type="button">TEST CONNECTION</button>
                        <button id="save-cloud-provider" class="btn {{ $provider ? 'btn-warning' : 'btn-info' }} mb-2" type="button">
                            {{ $provider ? 'EDIT' : 'SAVE' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const type = document.querySelector('#type');
            const tokenLabel = document.querySelector('label[for="api_token"]');
            const token = document.querySelector('#api_token');
            const tokenHelp = document.querySelector('#api-token-help');
            const editing = @json((bool) $provider);
            const errorBox = document.querySelector('#cloud-provider-errors');
            const button = document.querySelector('#save-cloud-provider');
            const testButton = document.querySelector('#test-cloud-provider');
            const testUrl = @json($testUrl);
            const checkboxSelector = 'input[name="allowed_users[]"]';

            function updateTokenLabel() {
                if (type.value === 'amazon') {
                    tokenLabel.textContent = 'Api Token: (ACCESS_KEY|ACCESS_SECRET)';
                    token.placeholder = editing ? 'Leave blank to keep current token' : 'ACCESS KEY | ACCESS SECRET';
                } else if (type.value === 'azure') {
                    tokenLabel.textContent = 'Api Token: (TENANT_ID|CLIENT_ID|CLIENT_SECRET_VALUE)';
                    token.placeholder = editing ? 'Leave blank to keep current token' : 'TENANT ID | CLIENT ID | CLIENT SECRET VALUE';
                    tokenHelp.textContent = 'Test Connection checks read access to the instance list. It does not create or change cloud resources.';
                } else if (type.value === 'digitalocean') {
                    tokenLabel.textContent = 'Api Token:';
                    token.placeholder = editing ? 'Leave blank to keep current token' : 'DigitalOcean API access token';
                    tokenHelp.textContent = 'Use a DigitalOcean API access token (dop_v1_ personal access or doo_v1_ OAuth access), not a dor_v1_ refresh token or Spaces key. The read-only test requires droplet:read and any dependent read scopes DigitalOcean requires for custom tokens; testing does not save token changes.';
                } else {
                    tokenLabel.textContent = 'Api Token:';
                    token.placeholder = editing ? 'Leave blank to keep current token' : 'Enter Provider Api ...';
                    tokenHelp.textContent = 'Test Connection checks read access to the instance list. It does not create or change cloud resources.';
                }
            }

            function showError(message) {
                errorBox.textContent = message;
                errorBox.className = message === '' ? 'alert d-none' : 'alert alert-danger';
            }

            type.addEventListener('change', updateTokenLabel);
            document.querySelector('#check').addEventListener('click', () => {
                const boxes = [...document.querySelectorAll(checkboxSelector)];
                const checked = boxes.length > 0 && !boxes[0].checked;
                boxes.forEach((box) => { box.checked = checked; });
            });
            updateTokenLabel();

            testButton.addEventListener('click', async () => {
                const originalText = testButton.textContent;
                testButton.disabled = true;
                testButton.textContent = 'TESTING…';
                showError('');

                try {
                    const response = await fetch(testUrl, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ type: type.value, api_token: token.value }),
                    });
                    const body = await response.json();
                    if (!response.ok) {
                        const validation = Object.values(body.errors ?? {}).flat().join(' ');
                        throw new Error(validation || body.message || 'Connection check could not be completed.');
                    }
                    errorBox.className = `alert alert-${body.success ? 'success' : 'danger'}`;
                    errorBox.textContent = body.message;
                } catch (error) {
                    errorBox.className = 'alert alert-danger';
                    errorBox.textContent = error instanceof Error ? error.message : 'Connection check could not be completed.';
                } finally {
                    testButton.disabled = false;
                    testButton.textContent = originalText;
                }
            });

            button.addEventListener('click', async () => {
                button.disabled = true;
                button.textContent = @json($provider ? 'EDITING…' : 'SAVING…');
                showError('');

                const payload = {
                    type: type.value,
                    name: document.querySelector('#name').value,
                    api_token: token.value,
                    allowed_users: [...document.querySelectorAll(`${checkboxSelector}:checked`)].map((box) => Number(box.value)),
                };

                try {
                    const response = await fetch(@json($saveUrl), {
                        method: @json($saveMethod),
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify(payload),
                    });
                    const body = await response.json();

                    if (!response.ok) {
                        const validation = Object.values(body.errors ?? {}).flat().join('\n');
                        throw new Error(validation || body.message || 'The cloud provider could not be saved.');
                    }

                    if (window.parent?.closeCloudProviderDialog) {
                        await window.parent.closeCloudProviderDialog(body.data);
                    } else {
                        window.location.assign(@json(route('clean.console.infrastructure.cloud-providers.index')));
                    }
                } catch (error) {
                    showError(error instanceof Error ? error.message : 'The cloud provider could not be saved.');
                } finally {
                    button.disabled = false;
                    button.textContent = @json($provider ? 'EDIT' : 'SAVE');
                }
            });
        })();
    </script>
@endpush
