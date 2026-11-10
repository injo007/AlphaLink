@extends('layouts.admin-shell')

@section('title', 'Pmta Settings')

@push('styles')
    <link rel="stylesheet" href="{{ asset('console-assets/vendor/jstree/style.min.css') }}">
    <style>
        #pmta-settings-files {
            min-height: 35rem;
            max-height: 35rem;
            overflow: auto;
            background: var(--command-os-surface, #ffffff);
            border: 1px solid var(--command-os-border, rgba(99, 102, 241, 0.14));
            border-radius: 12px;
        }
        #pmta-settings-contents { min-height: 35rem; }
        #pmta-settings-editor .ace_editor {
            height: 35rem;
            width: 100%;
            border-radius: 12px;
            border: 1px solid var(--command-os-border, rgba(99, 102, 241, 0.18));
            font-family: 'JetBrains Mono', 'Fira Code', 'Roboto Mono', monospace !important;
        }
        #pmta-settings-push-panel[hidden] { display: none !important; }
        #pmta-settings-holder[hidden] { display: none !important; }
    </style>
@endpush

@section('content')
<div class="command-os-report-page">
    <!-- 1. Hero Header -->
    <header class="command-os-report-hero mb-4">
        <div class="command-os-report-hero-left">
            <div class="command-os-report-hero-icon" aria-hidden="true">
                <i class="fas fa-cog"></i>
            </div>
            <div>
                <div class="command-os-report-hero-eyebrow">DAEMON CONFIGURATION // FILE MANAGEMENT</div>
                <h1 class="command-os-report-hero-title">PMTA SETTINGS</h1>
                <p class="command-os-report-hero-desc">Direct PowerMTA configuration files, directive editor, and multi-node sync orchestration.</p>
            </div>
        </div>
        <div class="command-os-report-hero-right">
            <div class="command-os-report-hero-meta">
                <span class="command-os-report-badge">CONFIG CONTROLLER</span>
            </div>
        </div>
    </header>

    <div id="pmta-settings"
        data-servers-url="{{ route('console.pmta-settings.servers', $workspace->id) }}"
        data-files-url="{{ route('console.pmta-settings.files', $workspace->id) }}"
        data-file-url="{{ route('console.pmta-settings.file', $workspace->id) }}"
        data-edit-url="{{ route('console.pmta-settings.edit', $workspace->id) }}"
        data-push-url="{{ route('console.pmta-settings.push', $workspace->id) }}"
        data-default-domains-url="{{ route('console.pmta-settings.default-domains', $workspace->id) }}">

        <!-- 2. Target Server Card -->
        <div class="command-os-table-card p-4 mb-4">
            <div class="row align-items-center">
                <div class="col-sm-12 col-md-7">
                    <label class="command-form-label" for="pmta-settings-server">
                        Target Server:
                        <button type="button" class="btn-command-action ml-1" id="pmta-settings-reload-servers" title="Reload servers" aria-label="Reload servers" style="padding: 2px 8px; font-size: 0.75rem;">
                            <i class="fas fa-sync" aria-hidden="true"></i>
                        </button>
                    </label>
                    <select id="pmta-settings-server" class="form-control selectpicker" data-live-search="true" data-size="6" title="Select Server">
                        <option value="" selected>Select Server</option>
                        @foreach ($servers as $server)
                            <option value="{{ $server->id }}">{{ $server->name }} | {{ $server->ip_address }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-12 col-md-5 d-flex justify-content-md-end mt-3 mt-md-4">
                    <button type="button" class="btn-command-action" id="pmta-settings-open-edit-push">
                        <i class="fas fa-paper-plane mr-1 text-purple"></i> EDIT &amp; PUSH TO MULTIPLE
                    </button>
                </div>
            </div>

            <!-- Push Panel -->
            <div id="pmta-settings-push-panel" class="mt-4 pt-3 border-top" hidden>
                <div class="row">
                    <div class="col-sm-12 col-md-8">
                        <div class="form-group mb-2">
                            <label class="command-form-label" for="pmta-settings-push-servers">Push Destination Servers:</label>
                            <div class="input-group">
                                <select id="pmta-settings-push-servers" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 5" title="Select servers" multiple>
                                    @foreach ($servers as $server)
                                        <option value="{{ $server->id }}">{{ $server->name }} | {{ $server->ip_address }}</option>
                                    @endforeach
                                </select>
                                <div class="input-group-append">
                                    <button type="button" class="btn-command-primary" id="pmta-settings-edit-push">
                                        <i class="fas fa-upload mr-1"></i> EXECUTE PUSH
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="table-responsive mt-3">
                    <table id="pmta-settings-push-results" class="table table-sm table-hover table-striped command-os-table" aria-label="PMTA configuration push results"></table>
                </div>
            </div>
        </div>

        <!-- 3. Editor & Files Section -->
        <div class="row" id="pmta-settings-holder" hidden>
            <div class="col-sm-12 col-md-3 mb-4">
                <div class="command-os-table-card p-3 h-100">
                    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                        <label class="command-form-label mb-0" for="pmta-settings-files">
                            <i class="fas fa-folder-open mr-1 text-purple"></i> Configuration Files
                        </label>
                        <button type="button" class="btn-command-action" id="pmta-settings-files-reload" title="Reload files" aria-label="Reload files" style="padding: 2px 8px; font-size: 0.75rem;">
                            <i class="fas fa-sync" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="p-2" id="pmta-settings-files" aria-label="PMTA configuration files"></div>
                </div>
            </div>
            <div class="col-sm-12 col-md-9 mb-4">
                <div class="command-os-table-card p-4">
                    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                        <label class="command-form-label mb-0" for="pmta-settings-contents">
                            Edit File: <span id="pmta-settings-file-name" class="badge-command-pill ml-1" style="font-weight: 700; color: #8b5cf6;"></span>
                        </label>
                        <div class="d-flex align-items-center" style="gap: 8px;">
                            <button type="button" class="btn-command-danger" id="pmta-settings-restore-domains" hidden>
                                <i class="fas fa-undo mr-1"></i> RESTORE DEFAULT CONFIG
                            </button>
                            <button type="button" class="btn-command-action" id="pmta-settings-toggle-push">
                                <i class="fas fa-share-alt mr-1"></i> PUSH PANEL
                            </button>
                            <button type="button" class="btn-command-primary" id="pmta-settings-edit">
                                <i class="fas fa-save mr-1"></i> SAVE &amp; EDIT FILE
                            </button>
                        </div>
                    </div>
                    <div class="form-group mb-0" id="pmta-settings-editor">
                        <textarea id="pmta-settings-contents" class="form-control" placeholder="Select a server and file" aria-label="PMTA configuration contents"></textarea>
                    </div>
                    <div id="pmta-settings-status" class="sr-only" role="status" aria-live="polite"></div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    <script src="{{ asset('console-assets/vendor/jstree/jstree.min.js') }}"></script>
    <script src="{{ asset('console-assets/vendor/ace/ace.js') }}" charset="utf-8"></script>
    <script src="{{ asset('console-assets/vendor/ace/ext-language_tools.js') }}"></script>
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
            const aceBase = @json(asset('console-assets/vendor/ace'));
            let selectedFile = '';
            let editor = null;

            if (window.ace) {
                window.ace.config.set('basePath', aceBase);
                window.ace.config.set('modePath', `${aceBase}/`);
                window.ace.config.set('themePath', `${aceBase}/`);
                editor = window.ace.edit('pmta-settings-contents');
                const isDark = document.documentElement.getAttribute('data-command-theme') === 'dark';
                editor.setTheme(isDark ? 'ace/theme/tomorrow_night_eighties' : 'ace/theme/github');
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
