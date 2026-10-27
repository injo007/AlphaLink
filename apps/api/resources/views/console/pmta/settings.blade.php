@extends('layouts.dms-admin')

@section('title', 'Pmta Settings')

@push('styles')
    <link rel="stylesheet" href="{{ asset('dms-reference/vendor/jstree/style.min.css') }}">
    <style>
        #pmta-settings-files { min-height: 35rem; overflow: auto; }
        #pmta-settings-contents { min-height: 35rem; }
        #pmta-settings-editor .ace_editor { height: 35rem; width: 100%; }
        #pmta-settings-push-panel[hidden] { display: none !important; }
        #pmta-settings-holder[hidden] { display: none !important; }
    </style>
@endpush

@section('content')
    <section class="block block-rounded" id="pmta-settings"
        data-servers-url="{{ route('console.pmta-settings.servers', $workspace->id) }}"
        data-files-url="{{ route('console.pmta-settings.files', $workspace->id) }}"
        data-file-url="{{ route('console.pmta-settings.file', $workspace->id) }}"
        data-edit-url="{{ route('console.pmta-settings.edit', $workspace->id) }}"
        data-push-url="{{ route('console.pmta-settings.push', $workspace->id) }}"
        data-default-domains-url="{{ route('console.pmta-settings.default-domains', $workspace->id) }}">
        <div class="block-header">
            <h3 class="block-title">Pmta Settings</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle fullscreen">
                    <i class="fas fa-expand-arrows-alt" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <div class="row justify-content-center">
                <div class="col-sm-12 col-md-6">
                    <div class="form-group">
                        <label for="pmta-settings-server">
                            Servers:
                            <button type="button" class="btn btn-link btn-sm p-0 ml-1" id="pmta-settings-reload-servers" title="Reload servers" aria-label="Reload servers">
                                <i class="fas fa-sync" aria-hidden="true"></i>
                            </button>
                        </label>
                        <select id="pmta-settings-server" class="form-control selectpicker" data-live-search="true" data-size="6" title="Select Server">
                            <option value="" selected>Select Server</option>
                            @foreach ($servers as $server)
                                <option value="{{ $server->id }}">{{ $server->name }} | {{ $server->ip_address }}</option>
                            @endforeach
                        </select>
                        <button type="button" class="btn btn-warning mt-1" id="pmta-settings-open-edit-push">EDIT AND PUSH</button>
                    </div>
                </div>
            </div>
            <div class="row justify-content-center">
                <div class="col-sm-12 col-md-6">
                    <div id="pmta-settings-push-panel" hidden>
                        <div class="form-group">
                            <label for="pmta-settings-push-servers">Select Server:</label>
                            <div class="input-group">
                                <select id="pmta-settings-push-servers" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 5" title="Select servers" multiple>
                                    @foreach ($servers as $server)
                                        <option value="{{ $server->id }}">{{ $server->name }} | {{ $server->ip_address }}</option>
                                    @endforeach
                                </select>
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-warning" id="pmta-settings-edit-push">EDIT AND PUSH</button>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="table-responsive">
                                <table id="pmta-settings-push-results" class="table table-sm table-hover table-bordered" aria-label="PMTA configuration push results"></table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row" id="pmta-settings-holder" hidden>
                <div class="col-sm-12 col-md-3">
                    <div class="form-group">
                        <label for="pmta-settings-files">
                            Files:
                            <button type="button" class="btn btn-link btn-sm p-0 ml-1" id="pmta-settings-files-reload" title="Reload files" aria-label="Reload files">
                                <i class="fas fa-sync" aria-hidden="true"></i>
                            </button>
                        </label>
                        <div class="card p-2" id="pmta-settings-files" aria-label="PMTA configuration files"></div>
                    </div>
                </div>
                <div class="col-sm-12 col-md-9">
                    <div class="form-group" id="pmta-settings-editor">
                        <label for="pmta-settings-contents">Edit File: <span id="pmta-settings-file-name" class="badge badge-primary"></span></label>
                        <textarea id="pmta-settings-contents" class="form-control" placeholder="Select a server and file" aria-label="PMTA configuration contents"></textarea>
                    </div>
                    <div class="form-group">
                        <button type="button" class="btn btn-primary" id="pmta-settings-edit">EDIT FILE</button>
                        <button type="button" class="btn btn-info" id="pmta-settings-toggle-push">PUSH</button>
                        <button type="button" class="btn btn-warning" id="pmta-settings-restore-domains" hidden>RESTORE DEFAULT CONFIG</button>
                    </div>
                    <div id="pmta-settings-status" class="sr-only" role="status" aria-live="polite"></div>
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('dms-reference/vendor/jstree/jstree.min.js') }}"></script>
    <script src="{{ asset('dms-reference/vendor/ace/ace.js') }}" charset="utf-8"></script>
    <script src="{{ asset('dms-reference/vendor/ace/ext-language_tools.js') }}"></script>
    <script>
        (() => {
            const root = document.getElementById('pmta-settings');
            if (!root) return;

            const serverSelect = document.getElementById('pmta-settings-server');
            const settingsHolder = document.getElementById('pmta-settings-holder');
            const pushServers = document.getElementById('pmta-settings-push-servers');
            const files = window.jQuery?.fn?.jstree ? window.jQuery('#pmta-settings-files') : null;
            const status = document.getElementById('pmta-settings-status');
            const restoreButton = document.getElementById('pmta-settings-restore-domains');
            const pushPanel = document.getElementById('pmta-settings-push-panel');
            const fileName = document.getElementById('pmta-settings-file-name');
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const aceBase = @json(asset('dms-reference/vendor/ace'));
            let selectedFile = '';
            let editor = null;

            if (window.ace) {
                window.ace.config.set('basePath', aceBase);
                window.ace.config.set('modePath', `${aceBase}/`);
                window.ace.config.set('themePath', `${aceBase}/`);
                editor = window.ace.edit('pmta-settings-contents');
                editor.setTheme('ace/theme/github');
                editor.session.setMode('ace/mode/text');
                editor.session.setUseWorker(false);
                editor.setAutoScrollEditorIntoView(false);
                editor.setShowPrintMargin(false);
                editor.$blockScrolling = Infinity;
                editor.setFontSize(13);
                editor.setOptions({ minLines: 35, maxLines: 35, enableLiveAutocompletion: true, wrap: true });
            }

            const api = async (url, method = 'GET', body = null) => {
                const response = await fetch(url, {
                    method,
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: body ? JSON.stringify(body) : undefined,
                });
                const result = await response.json().catch(() => ({}));
                if (!response.ok || result.success === false) {
                    throw new Error(result.message || Object.values(result.errors || {}).flat()[0] || 'PMTA settings request failed.');
                }

                return result;
            };
            const announce = (message, tone = 'info') => {
                status.textContent = message;
                if (window.AlphaLinkToast) window.AlphaLinkToast.show(message, tone);
            };
            const getContents = () => editor ? editor.getValue() : document.getElementById('pmta-settings-contents').value;
            const setContents = (value) => {
                if (editor) editor.setValue(value || '', -1);
                else document.getElementById('pmta-settings-contents').value = value || '';
            };
            const setBusy = (button, busy, label) => {
                if (!button.dataset.originalLabel) button.dataset.originalLabel = button.textContent.trim();
                button.disabled = busy;
                button.textContent = busy ? `${label}…` : button.dataset.originalLabel;
            };
            const queryUrl = (base, values) => {
                const url = new URL(base, window.location.href);
                Object.entries(values).forEach(([key, value]) => url.searchParams.set(key, value));
                return url.toString();
            };
            const clearSelection = () => {
                selectedFile = '';
                fileName.textContent = '';
                setContents('');
                restoreButton.hidden = true;
                if (files) files.jstree('deselect_all', true);
            };
            const updateSettingsVisibility = () => {
                settingsHolder.hidden = !serverSelect.value;
            };
            const renderPushResults = (rows) => {
                const table = document.getElementById('pmta-settings-push-results');
                table.replaceChildren();
                const head = document.createElement('thead');
                const header = document.createElement('tr');
                ['Server', 'Result'].forEach((label) => {
                    const cell = document.createElement('th');
                    cell.scope = 'col';
                    cell.textContent = label;
                    header.append(cell);
                });
                head.append(header);
                const body = document.createElement('tbody');
                (rows || []).forEach((result) => {
                    const row = document.createElement('tr');
                    [result.server, result.result].forEach((value) => {
                        const cell = document.createElement('td');
                        cell.textContent = value || '';
                        row.append(cell);
                    });
                    body.append(row);
                });
                table.append(head, body);
            };
            const loadFiles = async () => {
                const serverId = serverSelect.value;
                if (!serverId) {
                    if (files) files.jstree(true).settings.core.data = [];
                    if (files) files.jstree(true).refresh();
                    clearSelection();
                    announce('Select a server to load PMTA configuration files.');
                    return;
                }
                const button = document.getElementById('pmta-settings-files-reload');
                button.querySelector('i').classList.add('fa-spin');
                try {
                    const result = await api(queryUrl(root.dataset.filesUrl, { server_id: serverId }));
                    clearSelection();
                    files.jstree(true).settings.core.data = (result.files || []).map((name) => ({ text: name }));
                    files.jstree(true).refresh();
                    announce(`${result.files.length} PMTA configuration file(s) loaded.`, 'success');
                } catch (error) {
                    announce(error.message, 'danger');
                } finally {
                    button.querySelector('i').classList.remove('fa-spin');
                }
            };

            if (files) {
                files.jstree({
                    core: { multiple: false, themes: { responsive: true }, data: [] },
                    types: { default: { icon: 'fas fa-file' } },
                    plugins: ['types'],
                });
                files.on('changed.jstree', async (event, data) => {
                    const file = data.node?.text;
                    if (!file) return;
                    selectedFile = file;
                    fileName.textContent = 'Loading…';
                    setContents('');
                    restoreButton.hidden = true;
                    try {
                        const result = await api(queryUrl(root.dataset.fileUrl, { server_id: serverSelect.value, file }));
                        selectedFile = result.file;
                        fileName.textContent = result.file;
                        setContents(result.contents);
                        restoreButton.hidden = result.file !== 'domain';
                        announce(`Loaded ${result.file}.`);
                    } catch (error) {
                        selectedFile = '';
                        fileName.textContent = '';
                        announce(error.message, 'danger');
                    }
                });
            }

            document.getElementById('pmta-settings-reload-servers').addEventListener('click', async (event) => {
                const button = event.currentTarget;
                button.querySelector('i').classList.add('fa-spin');
                const previous = serverSelect.value;
                try {
                    const result = await api(root.dataset.serversUrl);
                    const updateSelect = (select) => {
                        const existing = select.value;
                        const options = (result.data || []).map((server) => {
                            const option = document.createElement('option');
                            option.value = server.id;
                            option.textContent = `${server.name} | ${server.ip_address}`;
                            return option;
                        });
                        if (select === serverSelect) {
                            const placeholder = document.createElement('option');
                            placeholder.value = '';
                            placeholder.textContent = 'Select Server';
                            placeholder.selected = true;
                            options.unshift(placeholder);
                        }
                        select.replaceChildren(...options);
                        if (options.some((option) => option.value === existing)) select.value = existing;
                        else if (select === serverSelect && options.some((option) => option.value === previous)) select.value = previous;
                    };
                    updateSelect(serverSelect);
                    updateSelect(pushServers);
                    if (window.jQuery?.fn.selectpicker) window.jQuery('.selectpicker').selectpicker('refresh');
                    updateSettingsVisibility();
                    await loadFiles();
                    announce(`${result.data.length} installed server(s) loaded.`, 'success');
                } catch (error) {
                    announce(error.message, 'danger');
                } finally {
                    button.querySelector('i').classList.remove('fa-spin');
                }
            });
            document.getElementById('pmta-settings-files-reload').addEventListener('click', loadFiles);
            updateSettingsVisibility();
            serverSelect.addEventListener('change', () => {
                clearSelection();
                updateSettingsVisibility();
                loadFiles();
            });

            document.getElementById('pmta-settings-edit').addEventListener('click', async (event) => {
                const button = event.currentTarget;
                if (!serverSelect.value || !selectedFile) {
                    announce('Select a server and configuration file first.', 'warning');
                    return;
                }
                setBusy(button, true, 'EDITING');
                try {
                    const result = await api(root.dataset.editUrl, 'POST', {
                        server_id: serverSelect.value,
                        file: selectedFile,
                        contents: getContents(),
                    });
                    clearSelection();
                    announce(result.message, 'success');
                } catch (error) {
                    announce(error.message, 'danger');
                } finally {
                    setBusy(button, false, 'EDITING');
                }
            });
            document.getElementById('pmta-settings-toggle-push').addEventListener('click', () => {
                pushPanel.hidden = !pushPanel.hidden;
            });
            document.getElementById('pmta-settings-open-edit-push').addEventListener('click', () => {
                pushPanel.hidden = !pushPanel.hidden;
            });
            document.getElementById('pmta-settings-edit-push').addEventListener('click', async (event) => {
                const button = event.currentTarget;
                const serverIds = Array.from(pushServers.selectedOptions).map((option) => option.value);
                if (!serverIds.length) {
                    announce('Select at least one server.', 'warning');
                    return;
                }
                if (!selectedFile) {
                    announce('Select a configuration file first.', 'warning');
                    return;
                }
                renderPushResults(serverIds.map((id) => ({
                    server: Array.from(pushServers.options).find((option) => option.value === id)?.textContent || id,
                    result: 'Pushing…',
                })));
                setBusy(button, true, 'PUSHING');
                try {
                    const result = await api(root.dataset.pushUrl, 'POST', {
                        server_ids: serverIds,
                        file: selectedFile,
                        contents: getContents(),
                    });
                    renderPushResults(result.results);
                    announce(result.results.map((row) => `${row.server}: ${row.result}`).join(' · '), result.success ? 'success' : 'danger');
                } catch (error) {
                    renderPushResults([{ server: 'AlphaLink', result: error.message }]);
                    announce(error.message, 'danger');
                } finally {
                    setBusy(button, false, 'PUSHING');
                }
            });
            restoreButton.addEventListener('click', async (event) => {
                const button = event.currentTarget;
                if (!serverSelect.value || selectedFile !== 'domain') return;
                button.disabled = true;
                try {
                    const result = await api(queryUrl(root.dataset.defaultDomainsUrl, { server_id: serverSelect.value }));
                    setContents(result.contents);
                    announce('Default PMTA domain configuration loaded into the editor. Select EDIT FILE to save it.', 'success');
                } catch (error) {
                    announce(error.message, 'danger');
                } finally {
                    button.disabled = false;
                }
            });

            if (window.jQuery?.fn.selectpicker) window.jQuery('.selectpicker').selectpicker();
        })();
    </script>
@endpush
