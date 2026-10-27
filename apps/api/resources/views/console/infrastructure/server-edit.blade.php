@extends('layouts.dms-reference')

@section('title', 'Edit Server - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">EDIT SERVER <span class="text-info">{{ $server->name }} | {{ $server->getKey() }}</span></h3>
        </div>
        <div class="block-content">
            <div class="row justify-content-center py-1">
                <div class="col-sm-10">
                    @if ($server->is_installed)
                        <div class="alert alert-warning d-flex align-items-center justify-content-between">
                            <div class="flex-fill mr-3"><strong class="mb-0">WARNING THIS SERVER IS ALREADY INSTALLED</strong></div>
                            <div class="flex-00-auto"><i class="fa fa-fw fa-exclamation-circle"></i></div>
                        </div>
                    @endif
                    <div id="server-edit-result" class="alert alert-danger d-none" role="alert" aria-live="polite" tabindex="-1"></div>
                    @if ($username !== '' && ! $isBulkInstallEligible)
                        <div class="alert alert-warning" role="note">
                            DMS Bulk Install includes active, uninstalled servers whose names match this account's username.
                            Suggested name: <code>{{ $suggestedName }}</code>
                            <button id="use-suggested-name" class="btn btn-sm btn-outline-warning ml-2" type="button">USE SUGGESTED NAME</button>
                        </div>
                    @endif
                    <form id="server-edit-form" action="{{ route('console.infrastructure.servers.update', [$workspace->id, $server->getKey()]) }}" method="post" novalidate>
                        @csrf
                        @method('put')
                        <div class="form-group">
                            <label for="servers_provider_id">Provider :</label>
                            <select id="servers_provider_id" name="servers_provider_id" class="form-control selectpicker" title="Select Server Provider" data-allow-clear="true" data-live-search="true" data-size="6" required aria-describedby="servers_provider_id-error">
                                @foreach ($providers as $provider)
                                    <option value="{{ $provider->id }}" @selected((int) $provider->id === (int) $server->servers_provider_id)>{{ $provider->name }}</option>
                                @endforeach
                            </select>
                            <small id="servers_provider_id-error" class="form-text text-danger" data-error-for="servers_provider_id"></small>
                        </div>
                        <div class="form-group">
                            <label for="name">Name:</label>
                            <input type="text" class="form-control form-control-alt" id="name" name="name" placeholder="Enter Name ..." value="{{ old('name', $server->name) }}" minlength="2" maxlength="120" required aria-describedby="name-error">
                            <small id="name-error" class="form-text text-danger" data-error-for="name"></small>
                        </div>
                        <div class="form-group">
                            <label for="ip">Ip :</label>
                            <input type="text" class="form-control form-control-alt" id="ip" name="ip" placeholder="Ip" value="{{ old('ip', $server->ip_address) }}" inputmode="url" required aria-describedby="ip-error">
                            <small id="ip-error" class="form-text text-danger" data-error-for="ip"></small>
                        </div>
                        <div class="form-group">
                            <label for="port">Port:</label>
                            <input type="text" class="form-control form-control-alt" id="port" name="port" placeholder="Enter Port ..." value="{{ old('port', $server->ssh_port) }}" inputmode="numeric" required aria-describedby="port-error">
                            <small id="port-error" class="form-text text-danger" data-error-for="port"></small>
                        </div>
                        <div class="form-group">
                            <label for="username">Username:</label>
                            <input type="text" class="form-control form-control-alt" id="username" name="username" placeholder="Enter Username ..." value="{{ old('username', $server->ssh_username) }}" maxlength="32" autocomplete="username" required aria-describedby="username-error">
                            <small id="username-error" class="form-text text-danger" data-error-for="username"></small>
                        </div>
                        <div class="form-group">
                            <label for="login_type">Password Type:</label>
                            <select id="login_type" name="login_type" class="form-control selectpicker" title="select password type" data-live-search="true" required aria-describedby="login_type-error">
                                <option value="password" @selected(old('login_type', $server->login_type) === 'password')>Password</option>
                                <option value="ssh_key" @selected(old('login_type', $server->login_type) === 'ssh_key')>Ssh Key</option>
                            </select>
                            <small id="login_type-error" class="form-text text-danger" data-error-for="login_type"></small>
                        </div>
                        <div class="form-group">
                            <label for="password">Password:</label>
                            <textarea id="password" name="password" class="form-control form-control-alt" placeholder="Enter Password ..." title="Leave empty to keep the saved credential; enter a new password or SSH key to rotate it." autocomplete="new-password" spellcheck="false" aria-describedby="password-error"></textarea>
                            <small id="password-error" class="form-text text-danger" data-error-for="password"></small>
                        </div>
                        <div class="form-group">
                            <label for="expire_at">Expire At:</label>
                            <input type="date" class="form-control" id="expire_at" name="expire_at" min="{{ now()->format('Y-m-d') }}" value="{{ old('expire_at', $server->expire_at?->format('Y-m-d')) }}" required aria-describedby="expire_at-error">
                            <small id="expire_at-error" class="form-text text-danger" data-error-for="expire_at"></small>
                        </div>
                        <div class="form-group server-form-actions">
                            <button id="test-server-connection" class="btn btn-block btn-warning" type="button" data-url="{{ route('console.infrastructure.servers.test-existing-connection', [$workspace->id, $server->getKey()]) }}">TEST CONNECTION</button>
                            <button id="save-server" class="btn btn-block btn-info" type="submit">EDIT</button>
                        </div>
                    </form>
                </div>
            </div>
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
