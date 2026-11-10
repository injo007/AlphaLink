@extends('layouts.standalone')

@section('title', 'Add Domains')

@section('content')
    <div class="command-modal-surface">
        <div class="command-modal-hero">
            <div class="command-modal-hero-left">
                <div class="command-modal-hero-icon">
                    <i class="fas fa-globe"></i>
                </div>
                <div>
                    <div class="command-modal-hero-eyebrow">DNS INVENTORY // DOMAIN INGESTION</div>
                    <h3 class="command-modal-hero-title">ADD DOMAINS</h3>
                </div>
            </div>
            <div class="command-modal-hero-right">
                <div class="command-mode-toggle-pill">
                    <span class="command-mode-label" id="label_auto">AUTO</span>
                    <label class="command-switch">
                        <input type="checkbox" id="auto" checked>
                        <span class="command-slider"></span>
                    </label>
                </div>
            </div>
        </div>

        <div class="command-modal-body">
            <div id="validation_errors_container"></div>

            <div class="command-form-group">
                <label class="command-form-label" for="provider">
                    <i class="fas fa-cloud mr-1 text-purple"></i> SELECT PROVIDER
                </label>
                <div class="command-select-wrapper">
                    <select class="command-form-control command-select" id="provider" name="provider">
                        @foreach ($providers as $provider)
                            <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div id="save_div" hidden>
                <div class="command-form-group">
                    <label class="command-form-label" for="domains">
                        <i class="fas fa-list mr-1 text-purple"></i> DOMAIN NAMES <span class="command-form-hint">(ONE PER LINE)</span>
                    </label>
                    <textarea id="domains" rows="8" class="command-form-control font-mono" placeholder="example.com&#10;mail.domain.org&#10;newsletter.brand.net"></textarea>
                </div>
                <div class="command-form-group mt-3">
                    <button id="save-domains" class="btn-command-primary btn-block py-2 text-center" type="button">
                        <i class="fas fa-save mr-2"></i> SAVE DOMAINS
                    </button>
                </div>
            </div>

            <div id="import_div">
                <div class="command-form-group mt-2">
                    <button id="import-domains" class="btn-command-primary btn-block py-2 text-center" type="button">
                        <i class="fas fa-cloud-download-alt mr-2"></i> IMPORT DOMAINS FROM PROVIDER
                    </button>
                </div>
                <div class="command-form-group mt-3">
                    <div class="table-responsive">
                        <table id="domain-import-result" class="command-data-table table-sm w-100"></table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const autoMode = document.querySelector('#auto');
        const importPanel = document.querySelector('#import_div');
        const savePanel = document.querySelector('#save_div');
        const errorContainer = document.querySelector('#validation_errors_container');
        const appWindow = window.parent !== window && window.parent.App ? window.parent : window;
        const csrf = document.querySelector('meta[name="csrf-token"]').content;

        function clearErrors() {
            errorContainer.innerHTML = '';
        }

        function showErrors(validationErrors) {
            clearErrors();
            const errors = document.createElement('div');
            errors.id = 'validation_errors';
            errors.className = 'alert alert-danger';
            const list = document.createElement('ul');
            list.className = 'mb-0 pl-3';
            Object.values(validationErrors || {}).flat().forEach((message) => {
                const item = document.createElement('li');
                item.textContent = message;
                list.append(item);
            });
            errors.append(list);
            errorContainer.appendChild(errors);
        }

        async function post(url, data) {
            const formData = new URLSearchParams();
            Object.entries(data).forEach(([key, value]) => {
                if (Array.isArray(value)) {
                    value.forEach((item) => formData.append(`${key}[]`, item));
                } else {
                    formData.append(key, value);
                }
            });
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-CSRF-TOKEN': csrf,
                },
                body: formData.toString(),
                credentials: 'same-origin',
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                const error = new Error(payload.message || 'The request could not be completed.');
                error.status = response.status;
                error.payload = payload;
                throw error;
            }
            return payload;
        }

        async function submit(button, url, data, idleLabel, busyLabel) {
            clearErrors();
            button.disabled = true;
            button.innerHTML = `${busyLabel} <i class="fas fa-spinner fa-spin ml-2"></i>`;
            try {
                const payload = await post(url, data);
                await appWindow.App.swalPromise({
                    icon: 'success',
                    title: payload.data,
                    confirmButtonText: 'close',
                });
                if (window.parent !== window && typeof window.parent.closeModal === 'function') {
                    window.parent.closeModal();
                } else {
                    window.location.assign(@json(route('console.infrastructure.bulk-install', $workspace->id)));
                }
            } catch (error) {
                if (error.status === 422) {
                    showErrors(error.payload.errors);
                } else {
                    appWindow.App.processAjaxFail(error.payload || { error: error.message });
                }
            } finally {
                button.disabled = false;
                button.innerHTML = idleLabel;
            }
        }

        function updateMode() {
            const isAutomatic = autoMode.checked;
            document.querySelector('#label_auto').textContent = isAutomatic ? 'AUTO' : 'MANUAL';
            importPanel.hidden = !isAutomatic;
            savePanel.hidden = isAutomatic;
        }

        autoMode.addEventListener('change', updateMode);
        updateMode();

        document.querySelector('#import-domains').addEventListener('click', async (event) => {
            const button = event.currentTarget;
            const provider = document.querySelector('#provider').value;
            await submit(
                button,
                @json(route('console.infrastructure.domains.import', $workspace->id)),
                { provider },
                '<i class="fas fa-cloud-download-alt mr-2"></i> IMPORT DOMAINS FROM PROVIDER',
                'IMPORTING',
            );
        });

        document.querySelector('#save-domains').addEventListener('click', async (event) => {
            const button = event.currentTarget;
            const domains = [...new Set(document.querySelector('#domains').value.split('\n').map((value) => value.trim()).filter((value) => value))];
            if (domains.length === 0) {
                return;
            }
            await submit(
                button,
                @json(route('console.infrastructure.domains.manual', $workspace->id)),
                { provider: document.querySelector('#provider').value, domains },
                '<i class="fas fa-save mr-2"></i> SAVE DOMAINS',
                'SAVING',
            );
        });
    </script>
@endpush
