@extends('layouts.dms-reference')

@section('title', 'Add Domains - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">ADD DOMAINS</h3>
            <div class="block-options">
                <div class="custom-control custom-switch custom-control-inline">
                    <input type="checkbox" class="custom-control-input" id="auto" checked>
                    <label id="label_auto" class="custom-control-label" for="auto">AUTO</label>
                </div>
            </div>
        </div>
        <div class="block-content">
            <div class="row justify-content-center py-1">
                <div class="col-sm-10">
                    <div class="form-group">
                        <label for="provider">Select Provider:</label>
                        <select class="form-control selectpicker" id="provider" name="provider">
                            @foreach ($providers as $provider)
                                <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div id="save_div" hidden>
                        <div class="form-group">
                            <label for="domains">Domains:</label>
                            <textarea id="domains" rows="10" class="form-control form-control-alt"></textarea>
                        </div>
                        <div class="form-group">
                            <button id="save-domains" class="btn btn-block btn-info" type="button">SAVE</button>
                        </div>
                    </div>
                    <div id="import_div">
                        <div class="form-group">
                            <button id="import-domains" class="btn btn-block btn-info" type="button">IMPORT</button>
                        </div>
                        <div class="form-group">
                            <div class="table-responsive">
                                <table id="domain-import-result" class="table table-sm table-hover"></table>
                            </div>
                        </div>
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
        const formColumn = document.querySelector('.col-sm-10');
        const appWindow = window.parent !== window && window.parent.App ? window.parent : window;
        const csrf = document.querySelector('meta[name="csrf-token"]').content;

        function clearErrors() {
            formColumn.querySelector('#validation_errors')?.remove();
        }

        function showErrors(validationErrors) {
            const errors = document.createElement('div');
            errors.id = 'validation_errors';
            errors.className = 'alert alert-danger';
            const list = document.createElement('ul');
            Object.values(validationErrors || {}).flat().forEach((message) => {
                const item = document.createElement('li');
                item.textContent = message;
                list.append(item);
            });
            errors.append(list);
            formColumn.prepend(errors);
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
            button.innerHTML = `${busyLabel} ${window.spinner()}`;
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
                button.textContent = idleLabel;
            }
        }

        function updateMode() {
            const isAutomatic = autoMode.checked;
            document.querySelector('#label_auto').textContent = isAutomatic ? 'AUTO' : 'MANUEL';
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
                'IMPORT',
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
                'SAVE',
                'SAVING',
            );
        });
    </script>
@endpush
