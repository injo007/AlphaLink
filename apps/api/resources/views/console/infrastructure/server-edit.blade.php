@extends('layouts.standalone')

@section('title', 'Edit Server')

@section('content')
    <div class="command-modal-surface">
        <div class="command-modal-hero">
            <div class="command-modal-hero-left">
                <div class="command-modal-hero-icon">
                    <i class="fas fa-server"></i>
                </div>
                <div>
                    <div class="command-modal-hero-eyebrow">FLEET TELEMETRY // HARDWARE PROFILE</div>
                    <h3 class="command-modal-hero-title">
                        EDIT SERVER <span class="badge-command-pill ml-2" style="font-weight: 700; color: #8b5cf6;">#{{ $server->getKey() }} &bull; {{ $server->name }}</span>
                    </h3>
                </div>
            </div>
        </div>

        <div class="command-modal-body">
            @if ($server->is_installed)
                <div class="alert alert-warning d-flex align-items-center justify-content-between mb-3" style="border-radius: 12px; font-size: 0.8125rem;">
                    <div><i class="fa fa-fw fa-exclamation-circle mr-1"></i> <strong>NOTICE:</strong> THIS SERVER IS CURRENTLY INSTALLED AND DEPLOYED</div>
                </div>
            @endif

            <div id="server-edit-result" class="alert alert-danger d-none mb-3" role="alert" aria-live="polite" tabindex="-1" style="border-radius: 12px; font-size: 0.8125rem;"></div>

            @if ($username !== '' && ! $isBulkInstallEligible)
                <div class="alert alert-warning d-flex align-items-center justify-content-between mb-3" role="note" style="border-radius: 12px; font-size: 0.8125rem;">
                    <div>
                        Bulk Install includes active servers matching account username. Suggested: <code style="font-weight: 700; color: #8b5cf6;">{{ $suggestedName }}</code>
                    </div>
                    <button id="use-suggested-name" class="btn-command-action" type="button" style="padding: 4px 10px; font-size: 0.75rem;">
                        APPLY NAME
                    </button>
                </div>
            @endif

            <form id="server-edit-form" action="{{ route('console.infrastructure.servers.update', [$workspace->id, $server->getKey()]) }}" method="post" novalidate>
                @csrf
                @method('put')

                <div class="command-form-group">
                    <label class="command-form-label" for="servers_provider_id">
                        <i class="fas fa-building mr-1 text-purple"></i> PROVIDER
                    </label>
                    <div class="command-select-wrapper">
                        <select id="servers_provider_id" name="servers_provider_id" class="command-form-control command-select" required aria-describedby="servers_provider_id-error">
                            @foreach ($providers as $provider)
                                <option value="{{ $provider->id }}" @selected((int) $provider->id === (int) $server->servers_provider_id)>{{ $provider->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <small id="servers_provider_id-error" class="text-danger mt-1 d-block" data-error-for="servers_provider_id"></small>
                </div>

                <div class="command-form-group">
                    <label class="command-form-label" for="name">
                        <i class="fas fa-tag mr-1 text-purple"></i> NAME
                    </label>
                    <input type="text" class="command-form-control" id="name" name="name" placeholder="Enter Name ..." value="{{ old('name', $server->name) }}" minlength="2" maxlength="120" required aria-describedby="name-error">
                    <small id="name-error" class="text-danger mt-1 d-block" data-error-for="name"></small>
                </div>

                <div class="command-form-group">
                    <label class="command-form-label" for="ip">
                        <i class="fas fa-network-wired mr-1 text-purple"></i> MAIN IP ADDRESS
                    </label>
                    <input type="text" class="command-form-control" id="ip" name="ip" placeholder="Ip" value="{{ old('ip', $server->ip_address) }}" inputmode="url" required aria-describedby="ip-error" style="font-family: monospace;">
                    <small id="ip-error" class="text-danger mt-1 d-block" data-error-for="ip"></small>
                </div>

                <div class="row">
                    <div class="col-sm-6">
                        <div class="command-form-group">
                            <label class="command-form-label" for="port">
                                <i class="fas fa-plug mr-1 text-purple"></i> PORT
                            </label>
                            <input type="text" class="command-form-control" id="port" name="port" placeholder="Port ..." value="{{ old('port', $server->ssh_port) }}" inputmode="numeric" required aria-describedby="port-error">
                            <small id="port-error" class="text-danger mt-1 d-block" data-error-for="port"></small>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="command-form-group">
                            <label class="command-form-label" for="username">
                                <i class="fas fa-user mr-1 text-purple"></i> USERNAME
                            </label>
                            <input type="text" class="command-form-control" id="username" name="username" placeholder="Username ..." value="{{ old('username', $server->ssh_username) }}" maxlength="32" autocomplete="username" required aria-describedby="username-error">
                            <small id="username-error" class="text-danger mt-1 d-block" data-error-for="username"></small>
                        </div>
                    </div>
                </div>

                <div class="command-form-group">
                    <label class="command-form-label" for="login_type">
                        <i class="fas fa-key mr-1 text-purple"></i> PASSWORD TYPE
                    </label>
                    <div class="command-select-wrapper">
                        <select id="login_type" name="login_type" class="command-form-control command-select" required aria-describedby="login_type-error">
                            <option value="password" @selected(old('login_type', $server->login_type) === 'password')>Password</option>
                            <option value="ssh_key" @selected(old('login_type', $server->login_type) === 'ssh_key')>SSH Key</option>
                        </select>
                    </div>
                    <small id="login_type-error" class="text-danger mt-1 d-block" data-error-for="login_type"></small>
                </div>

                <div class="command-form-group">
                    <label class="command-form-label" for="password">
                        <i class="fas fa-lock mr-1 text-purple"></i> PASSWORD / PRIVATE KEY
                    </label>
                    <textarea id="password" name="password" class="command-form-control" rows="3" placeholder="Leave empty to keep existing password..." autocomplete="new-password" spellcheck="false" aria-describedby="password-error"></textarea>
                    <small id="password-error" class="text-danger mt-1 d-block" data-error-for="password"></small>
                </div>

                <div class="command-form-group">
                    <label class="command-form-label" for="expire_at">
                        <i class="far fa-calendar-alt mr-1 text-purple"></i> EXPIRE AT
                    </label>
                    <input type="date" class="command-form-control" id="expire_at" name="expire_at" min="{{ now()->format('Y-m-d') }}" value="{{ old('expire_at', $server->expire_at?->format('Y-m-d')) }}" required aria-describedby="expire_at-error">
                    <small id="expire_at-error" class="text-danger mt-1 d-block" data-error-for="expire_at"></small>
                </div>

                <div class="command-modal-actions mt-4">
                    <button id="test-server-connection" class="btn-command-action" type="button" data-url="{{ route('console.infrastructure.servers.test-existing-connection', [$workspace->id, $server->getKey()]) }}">
                        <i class="fas fa-bolt mr-1"></i> TEST CONNECTION
                    </button>
                    <button id="save-server" class="btn-command-primary" type="submit">
                        <i class="fas fa-save mr-1"></i> UPDATE SERVER
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const form = document.querySelector('#server-edit-form');
            const result = document.querySelector('#server-edit-result');
            const saveButton = document.querySelector('#save-server');
            const testButton = document.querySelector('#test-server-connection');
            const credential = document.querySelector('#password');
            const initialValues = new Map([...form.elements]
                .filter((element) => element.name && element.name !== '_token' && element.name !== '_method')
                .map((element) => [element.name, element.value]));
            let saved = false;
            const fields = ['servers_provider_id', 'name', 'ip', 'port', 'username', 'login_type', 'password', 'expire_at'];

            function clearErrors() {
                result.classList.add('d-none');
                result.textContent = '';
                form.querySelectorAll('[data-error-for]').forEach((error) => { error.textContent = ''; });
                form.querySelectorAll('[aria-invalid="true"]').forEach((field) => field.removeAttribute('aria-invalid'));
            }

            function showErrors(errors, message = '') {
                clearErrors();
                const firstField = fields.find((field) => errors[field]?.length);
                for (const field of fields) {
                    const messages = errors[field];
                    if (!messages?.length) continue;
                    const control = form.elements.namedItem(field);
                    control?.setAttribute('aria-invalid', 'true');
                    form.querySelector(`[data-error-for="${field}"]`).textContent = messages.join(' ');
                }
                result.textContent = message || (firstField ? 'Review the highlighted fields.' : 'The server could not be updated. Try again.');
                result.classList.remove('d-none');
                if (firstField) form.elements.namedItem(firstField)?.focus();
                else result.focus();
            }

            function payload() {
                return Object.fromEntries(fields.map((field) => [field, form.elements.namedItem(field).value]));
            }

            async function request(url, method) {
                const response = await fetch(url, {
                    method,
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value,
                    },
                    body: JSON.stringify(payload()),
                });
                const body = await response.json();
                if (!response.ok) {
                    showErrors(body.errors ?? {}, body.message ?? body.data ?? 'The request failed. Try again.');
                    return null;
                }
                return body;
            }

            document.querySelector('#use-suggested-name')?.addEventListener('click', () => {
                document.querySelector('#name').value = @json($suggestedName);
                document.querySelector('#name').focus();
            });

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                clearErrors();
                saveButton.disabled = true;
                saveButton.setAttribute('aria-busy', 'true');
                saveButton.textContent = 'UPDATING';
                try {
                    const body = await request(form.action, 'PUT');
                    if (!body) return;
                    saved = true;
                    const appWindow = window.parent !== window && window.parent.App ? window.parent : window;
                    await appWindow.App?.swalPromise({ icon: 'success', title: body.data, confirmButtonText: 'close' });
                    if (window.parent !== window && typeof window.parent.closeModal === 'function') {
                        window.parent.AlphaLinkServers?.reload();
                        await window.parent.closeModal();
                    } else {
                        window.location.assign(@json($serversUrl));
                    }
                } catch {
                    showErrors({}, 'The server could not be updated. Check your connection and try again.');
                } finally {
                    saveButton.disabled = false;
                    saveButton.removeAttribute('aria-busy');
                    saveButton.textContent = 'EDIT';
                }
            });

            testButton.addEventListener('click', async () => {
                clearErrors();
                testButton.disabled = true;
                testButton.setAttribute('aria-busy', 'true');
                testButton.textContent = 'TESTING';
                try {
                    const body = await request(testButton.dataset.url, 'POST');
                    if (!body) return;
                    result.textContent = body.data;
                    result.className = `alert ${body.results?.[0]?.status === 'connected' ? 'alert-success' : 'alert-warning'}`;
                    result.setAttribute('role', 'status');
                    result.focus();
                } catch {
                    showErrors({}, 'Connection test failed. Check the server details and try again.');
                } finally {
                    testButton.disabled = false;
                    testButton.removeAttribute('aria-busy');
                    testButton.textContent = 'TEST CONNECTION';
                }
            });

            window.AlphaLinkServerEdit = {
                isDirty: () => !saved && ([...initialValues].some(([field, value]) => form.elements.namedItem(field)?.value !== value) || credential.value !== ''),
                allowClose: () => { saved = true; },
            };
        })();
    </script>
@endpush
