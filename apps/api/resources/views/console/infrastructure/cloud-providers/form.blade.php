@extends('layouts.standalone')

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
    <div class="command-modal-surface">
        <div class="command-modal-hero">
            <div class="command-modal-hero-left">
                <div class="command-modal-hero-icon">
                    <i class="fas fa-cloud-sun"></i>
                </div>
                <div>
                    <div class="command-modal-hero-eyebrow">CLOUD INFRASTRUCTURE // API VENDOR</div>
                    <h3 class="command-modal-hero-title">
                        @if ($provider)
                            EDIT CLOUD PROVIDER <span class="badge-command-pill ml-2" style="font-weight: 700; color: #8b5cf6;">#{{ $provider->id }} &bull; {{ $provider->name }}</span>
                        @else
                            CONNECT CLOUD PROVIDER
                        @endif
                    </h3>
                </div>
            </div>
        </div>

        <div class="command-modal-body">
            <div id="cloud-provider-errors" class="alert d-none mb-3" role="status" aria-live="polite" style="border-radius: 12px; font-size: 0.8125rem;"></div>

            <div class="command-form-group">
                <label class="command-form-label" for="type">
                    <i class="fas fa-layer-group mr-1 text-purple"></i> PROVIDER TYPE
                </label>
                <div class="command-select-wrapper">
                    <select name="type" id="type" class="command-form-control command-select" required>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" class="text-uppercase" @selected(old('type', $provider?->type) === $type)>{{ strtoupper($type) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="command-form-group">
                <label class="command-form-label" for="name">
                    <i class="fas fa-tag mr-1 text-purple"></i> PROVIDER NAME
                </label>
                <input type="text" class="command-form-control" id="name" name="name"
                       placeholder="Enter Provider Name ..." value="{{ old('name', $provider?->name) }}" required>
            </div>

            <div class="command-form-group">
                <label class="command-form-label" for="api_token">
                    <i class="fas fa-key mr-1 text-purple"></i> API TOKEN
                </label>
                <input type="password" class="command-form-control" id="api_token" name="api_token"
                       placeholder="{{ $provider ? 'Leave blank to keep current token' : 'Enter Provider Api ...' }}"
                       autocomplete="new-password" @if (! $provider) required @endif style="font-family: monospace;">
                <small class="text-muted mt-1 d-block" id="api-token-help" style="font-size: 0.75rem;">Test Connection checks read access to instance lists. It does not mutate cloud resources.</small>
                @if ($provider)
                    <small class="text-muted mt-1 d-block" style="font-size: 0.75rem;">Leave blank to keep the saved token.</small>
                @endif
            </div>

            <div class="command-form-group">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <label class="command-form-label mb-0">
                        <i class="fas fa-users mr-1 text-purple"></i> ALLOWED MAILERS
                    </label>
                    <button id="check" class="btn-command-action" type="button" style="padding: 4px 10px; font-size: 0.75rem;">
                        <i class="fas fa-check-double mr-1"></i> TOGGLE ALL
                    </button>
                </div>

                <div class="row" style="background: rgba(99, 102, 241, 0.03); border: 1px solid rgba(99, 102, 241, 0.12); border-radius: 14px; padding: 1rem 0.5rem;">
                    @foreach ($users->chunk(ceil(max(1, count($users)) / 2)) as $chunk)
                        <div class="col-sm-12 col-md-6">
                            @foreach ($chunk as $user)
                                <div class="custom-control custom-checkbox mb-2 px-3 py-1">
                                    <input type="checkbox" value="{{ $user->id }}"
                                           class="custom-control-input" id="allowed-mailer-{{ $user->id }}"
                                           name="allowed_users[]"
                                           @checked(in_array((string) $user->id, array_map('strval', old('allowed_users', $provider?->allowed_users ?? [])), true))>
                                    <label class="custom-control-label" for="allowed-mailer-{{ $user->id }}" style="cursor: pointer; font-size: 0.8125rem;">
                                        <strong style="color: var(--command-os-text, #1e293b);">{{ $user->name }}</strong>
                                        <span style="color: var(--command-os-text-muted, #64748b); font-size: 0.75rem;">({{ $user->username }})</span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="command-modal-actions mt-4 justify-content-between">
                <button id="test-cloud-provider" class="btn-command-action" type="button">
                    <i class="fas fa-bolt mr-1"></i> TEST CONNECTION
                </button>
                <button id="save-cloud-provider" class="btn-command-primary" type="button">
                    <i class="fas fa-save mr-1"></i> {{ $provider ? 'UPDATE PROVIDER' : 'CONNECT PROVIDER' }}
                </button>
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
                    tokenLabel.innerHTML = '<i class="fas fa-key mr-1 text-purple"></i> API TOKEN (ACCESS_KEY|ACCESS_SECRET)';
                    token.placeholder = editing ? 'Leave blank to keep current token' : 'ACCESS KEY | ACCESS SECRET';
                } else if (type.value === 'azure') {
                    tokenLabel.innerHTML = '<i class="fas fa-key mr-1 text-purple"></i> API TOKEN (TENANT_ID|CLIENT_ID|CLIENT_SECRET_VALUE)';
                    token.placeholder = editing ? 'Leave blank to keep current token' : 'TENANT ID | CLIENT ID | CLIENT SECRET VALUE';
                    tokenHelp.textContent = 'Test Connection checks read access to the instance list. It does not create or change cloud resources.';
                } else if (type.value === 'digitalocean') {
                    tokenLabel.innerHTML = '<i class="fas fa-key mr-1 text-purple"></i> DIGITALOCEAN API TOKEN';
                    token.placeholder = editing ? 'Leave blank to keep current token' : 'dop_v1_... access token';
                    tokenHelp.textContent = 'Use a DigitalOcean API access token. The read-only test requires droplet:read.';
                } else {
                    tokenLabel.innerHTML = '<i class="fas fa-key mr-1 text-purple"></i> API TOKEN';
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
                const originalText = testButton.innerHTML;
                testButton.disabled = true;
                testButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> TESTING...';
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
                    testButton.innerHTML = originalText;
                }
            });

            button.addEventListener('click', async () => {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> SAVING...';
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
                    button.innerHTML = @json($provider ? '<i class="fas fa-save mr-1"></i> UPDATE PROVIDER' : '<i class="fas fa-save mr-1"></i> CONNECT PROVIDER');
                }
            });
        })();
    </script>
@endpush
