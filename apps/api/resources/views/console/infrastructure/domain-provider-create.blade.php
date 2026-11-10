@extends('layouts.standalone')

@section('title', $provider ? 'Edit Provider' : 'Add Domain Provider')

@section('content')
    <div class="command-modal-surface">
        <div class="command-modal-hero">
            <div class="command-modal-hero-left">
                <div class="command-modal-hero-icon">
                    <i class="fas fa-cloud"></i>
                </div>
                <div>
                    <div class="command-modal-hero-eyebrow">DNS GATEWAYS // API CONFIGURATION</div>
                    <h3 class="command-modal-hero-title">
                        @if ($provider)
                            EDIT PROVIDER <span class="badge badge-purple ml-2">#{{ $provider->id }} &bull; {{ $provider->name }}</span>
                        @else
                            CONNECT DOMAIN PROVIDER
                        @endif
                    </h3>
                </div>
            </div>
        </div>

        <div class="command-modal-body">
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
                <div class="command-form-group">
                    <label class="command-form-label" for="name">
                        <i class="fas fa-tag mr-1 text-purple"></i> PROVIDER NAME
                    </label>
                    <input type="text" class="command-form-control" id="name" name="name" placeholder="Enter provider name..." value="{{ $provider?->name }}" minlength="4" maxlength="255" required>
                </div>

                <div class="command-form-group">
                    <label class="command-form-label" for="type">
                        <i class="fas fa-layer-group mr-1 text-purple"></i> PROVIDER TYPE
                    </label>
                    <div class="command-select-wrapper">
                        <select class="command-form-control command-select" id="type" name="type" required>
                            @foreach ($types as $type)
                                <option value="{{ $type }}" @selected($provider?->type === $type)>{{ strtoupper($type) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="command-form-group">
                    <label class="command-form-label" for="username">
                        <i class="fas fa-user mr-1 text-purple"></i> USERNAME
                    </label>
                    <input type="text" class="command-form-control font-mono" id="username" name="username" placeholder="Enter account username..." value="{{ $provider?->username }}" minlength="4" maxlength="255">
                </div>

                <div class="command-form-group">
                    <label class="command-form-label" for="password">
                        <i class="fas fa-key mr-1 text-purple"></i> PASSWORD
                    </label>
                    <input type="password" class="command-form-control font-mono" id="password" name="password" placeholder="Enter account password..." minlength="4" maxlength="255" autocomplete="new-password">
                </div>

                <div class="command-form-group">
                    <label class="command-form-label" for="api_key" id="api-key-label">
                        <i class="fas fa-shield-alt mr-1 text-purple"></i> API KEY
                    </label>
                    <input type="password" class="command-form-control font-mono" id="api_key" name="api_key" placeholder="{{ $provider ? 'Leave empty to keep saved credential' : 'Enter API key / token...' }}" minlength="4" maxlength="255" autocomplete="new-password" @required(! $provider)>
                </div>

                <div class="command-form-group">
                    <label class="command-form-label" for="api_secret">
                        <i class="fas fa-lock mr-1 text-purple"></i> API SECRET <span class="command-form-hint">(LEAVE EMPTY IF NONE)</span>
                    </label>
                    <input type="password" class="command-form-control font-mono" id="api_secret" name="api_secret" placeholder="Enter API secret if applicable..." minlength="4" maxlength="255" autocomplete="new-password">
                </div>

                <div class="command-form-group mt-3">
                    <div id="provider-result" class="alert d-none py-2 px-3 font-size-sm" role="status" aria-live="polite"></div>
                    <div class="d-flex flex-wrap align-items-center justify-content-between pt-2">
                        <button id="test-provider" class="btn-command-action mb-2" type="button">
                            <i class="fas fa-bolt mr-1"></i> TEST CONNECTION
                        </button>
                        <button id="save-provider" class="btn-command-primary mb-2" type="submit">
                            <i class="fas fa-check mr-1"></i> {{ $provider ? 'UPDATE PROVIDER' : 'CONNECT PROVIDER' }}
                        </button>
                    </div>
                </div>
            </form>
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
            username.closest('.command-form-group').hidden = cloudflare;
            password.closest('.command-form-group').hidden = cloudflare;
            apiSecret.closest('.command-form-group').hidden = cloudflare;
            username.required = !cloudflare;
            apiKeyLabel.innerHTML = cloudflare ? '<i class="fas fa-shield-alt mr-1 text-purple"></i> CLOUDFLARE API TOKEN:' : '<i class="fas fa-shield-alt mr-1 text-purple"></i> API KEY:';
            apiKey.placeholder = cloudflare
                ? 'Token with Zone Read, DNS Read, and DNS Write permissions'
                : @json($provider ? 'Leave empty to keep saved credential' : 'Enter Api Key ...');
            apiKey.required = isNewProvider || originalProviderType !== providerType.value;
        };
        providerType.addEventListener('change', syncProviderFields);
        syncProviderFields();

        testButton.addEventListener('click', async () => {
            const form = document.querySelector('#domain-provider-form');
            const originalHtml = testButton.innerHTML;
            testButton.disabled = true;
            testButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> TESTING…';
            resultBox.className = 'alert d-none py-2 px-3 font-size-sm';
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
                resultBox.className = `alert alert-${body.success ? 'success' : 'danger'} py-2 px-3 font-size-sm`;
                resultBox.textContent = body.message;
            } catch (error) {
                resultBox.className = 'alert alert-danger py-2 px-3 font-size-sm';
                resultBox.textContent = error.message || 'Connection check could not be completed.';
            } finally {
                testButton.disabled = false;
                testButton.innerHTML = originalHtml;
            }
        });

        document.querySelector('#domain-provider-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const button = form.querySelector('#save-provider');
            const result = form.querySelector('#provider-result');
            const payload = Object.fromEntries(new FormData(form));
            const originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> SAVING…';
            result.className = 'alert alert-danger d-none py-2 px-3 font-size-sm';
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
                button.innerHTML = originalHtml;
            }
        });
    </script>
@endpush
