@extends('layouts.admin-shell')

@section('title', 'Webmail — '.($ip->dns ?: $ip->ip))

@section('content')
    <div class="command-os-report-page" id="webmail-workbench"
         data-connect-url="{{ $connectUrl }}"
         data-message-url-template="{{ $messageUrlTemplate }}"
         data-delete-url-template="{{ $deleteUrlTemplate }}"
         data-default-domain="{{ $ip->dns }}"
         data-saved-username="{{ $savedUsername }}"
         data-has-saved-password="{{ $hasSavedPassword ? '1' : '0' }}">

        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-envelope-open-text"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">IMAP WEBMAIL // {{ strtoupper((string) ($server->name ?? 'SERVER')) }}</div>
                    <h1 class="command-os-report-hero-title">{{ $ip->dns ?: $ip->ip }}</h1>
                    <p class="command-os-report-hero-desc">
                        Connect to Dovecot IMAP on <strong>{{ $server->ip_address }}</strong> for IP <strong>{{ $ip->ip }}</strong>
                        using your saved Settings credentials or custom mailbox login.
                    </p>
                </div>
            </div>
            <div class="command-os-report-hero-right d-flex flex-wrap align-items-center" style="gap: 8px;">
                <a class="btn-command-action" href="{{ route('console.infrastructure.my-ips', $workspace->id) }}">
                    <i class="fas fa-arrow-left mr-1"></i> MY IPS
                </a>
                <a class="btn-command-action" href="{{ $settingsUrl }}">
                    <i class="fas fa-sliders-h mr-1"></i> WEBMAIL SETTINGS
                </a>
            </div>
        </header>

        <!-- Webmail Login & Connection Card -->
        <section class="command-os-table-card p-4 mb-4" aria-labelledby="webmail-login-heading">
            <div class="d-flex flex-wrap align-items-center justify-content-between mb-3" style="gap: 10px;">
                <div>
                    <h2 id="webmail-login-heading" class="h6 font-w700 mb-1">
                        <i class="fas fa-key mr-2 text-purple" aria-hidden="true"></i>Mailbox Authentication
                    </h2>
                    <small class="text-muted">
                        @if ($savedUsername !== '' && $hasSavedPassword)
                            Saved Settings credentials detected for <code>{{ $savedUsername }}</code>. Pre-filled for domain <code>{{ $ip->dns }}</code>.
                        @elseif ($savedUsername !== '' || $hasSavedPassword)
                            Partial Webmail credentials saved in Settings. Enter missing fields below or update them in Settings.
                        @else
                            No Webmail login and password saved in Settings yet. Enter your IMAP login and password below to sign in.
                        @endif
                    </small>
                </div>
                <span id="webmail-connection-badge" class="badge-command-pill">READY TO CONNECT</span>
            </div>

            <form id="webmail-connect-form" novalidate>
                <div class="form-row">
                    <div class="form-group col-md-4 col-sm-12">
                        <label class="command-form-label" for="webmail-username">IMAP LOGIN / MAILBOX</label>
                        <div class="input-group">
                            <input id="webmail-username" name="username" type="text" class="form-control command-form-control"
                                   value="{{ $defaultLogin }}" placeholder="alimap...@{{ $ip->dns ?: 'domain.com' }}" autocomplete="username">
                            @if ($savedUsername !== '' && $ip->dns)
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="webmail-toggle-domain-login"
                                            title="Switch between username@domain and bare username">
                                        @domain
                                    </button>
                                </div>
                            @endif
                        </div>
                        <small class="form-text text-muted">Dovecot routes INBOX by domain suffix (<code>username@{{ $ip->dns ?: 'domain' }}</code>).</small>
                    </div>

                    <div class="form-group col-md-4 col-sm-12">
                        <label class="command-form-label" for="webmail-password">WEBMAIL PASSWORD</label>
                        <input id="webmail-password" name="password" type="password" class="form-control command-form-control"
                               value="" autocomplete="current-password"
                               placeholder="{{ $hasSavedPassword ? 'Leave blank to use saved Settings password' : 'Enter Dovecot IMAP password' }}">
                        @if ($hasSavedPassword)
                            <div class="custom-control custom-switch mt-2">
                                <input type="checkbox" class="custom-control-input" id="webmail-use-saved-password" checked>
                                <label class="custom-control-label small" for="webmail-use-saved-password">Use saved password from Settings when blank</label>
                            </div>
                        @endif
                    </div>

                    <div class="form-group col-md-2 col-sm-6">
                        <label class="command-form-label" for="webmail-port">IMAP PORT</label>
                        <select id="webmail-port" name="port" class="form-control command-form-control">
                            <option value="143" selected>143 (IMAP / STARTTLS)</option>
                            <option value="993">993 (IMAPS SSL)</option>
                        </select>
                    </div>

                    <div class="form-group col-md-2 col-sm-6">
                        <label class="command-form-label" for="webmail-security">SECURITY</label>
                        <select id="webmail-security" name="security" class="form-control command-form-control">
                            <option value="auto" selected>Auto</option>
                            <option value="starttls">STARTTLS</option>
                            <option value="ssl">SSL / TLS</option>
                            <option value="plain">Plain (No TLS)</option>
                        </select>
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center justify-content-between mt-2" style="gap: 12px;">
                    <div>
                        @if ($canUpdateSettings)
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="webmail-save-to-settings">
                                <label class="custom-control-label small text-muted" for="webmail-save-to-settings">
                                    Save this login and password to Workspace Settings for future installs &amp; Webmail logins
                                </label>
                            </div>
                        @endif
                    </div>
                    <div class="d-flex align-items-center" style="gap: 8px;">
                        <button type="button" class="btn-command-action" id="webmail-refresh-btn" disabled>
                            <i class="fas fa-sync-alt mr-1"></i> REFRESH INBOX
                        </button>
                        <button type="submit" class="btn-command-primary" id="webmail-connect-btn">
                            <i class="fas fa-sign-in-alt mr-1"></i> SIGN IN TO WEBMAIL
                        </button>
                    </div>
                </div>
            </form>

            <div id="webmail-status-alert" class="alert d-none mt-3 mb-0" role="alert" style="border-radius: 10px; font-size: 0.8125rem;"></div>
        </section>

        <!-- Webmail Inbox & Reader Workspace -->
        <section class="command-os-table-card p-3" id="webmail-reader-shell">
            <div class="d-flex flex-wrap align-items-center justify-content-between pb-3 mb-3 border-bottom webmail-toolbar" style="gap: 10px;">
                <div id="webmail-folders" class="d-flex flex-wrap align-items-center" style="gap: 6px;">
                    <span class="badge-command-pill">INBOX</span>
                </div>
                <div class="d-flex align-items-center" style="gap: 8px; min-width: 240px;">
                    <input type="search" id="webmail-search" class="form-control form-control-sm command-form-control" placeholder="Filter subject or sender..." aria-label="Filter messages">
                </div>
            </div>

            <div class="row">
                <div class="col-lg-5 col-md-12 mb-3 mb-lg-0">
                    <div id="webmail-message-list" class="webmail-list-pane" aria-live="polite">
                        <div class="text-center text-muted py-5 px-3">
                            <i class="far fa-envelope fa-2x mb-2 d-block opacity-50" aria-hidden="true"></i>
                            Sign in above to load messages from <strong>{{ $server->ip_address }}</strong>.
                        </div>
                    </div>
                </div>

                <div class="col-lg-7 col-md-12">
                    <div id="webmail-message-detail" class="webmail-detail-pane">
                        <div class="text-center text-muted py-5 px-3">
                            <i class="far fa-file-alt fa-2x mb-2 d-block opacity-50" aria-hidden="true"></i>
                            Select an email from the folder list to inspect its headers and content.
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
@endsection

@push('styles')
    <style>
        .webmail-list-pane { border: 1px solid var(--command-os-border, rgba(148, 163, 184, 0.25)); border-radius: 12px; max-height: 540px; overflow-y: auto; }
        .webmail-detail-pane { border: 1px solid var(--command-os-border, rgba(148, 163, 184, 0.25)); border-radius: 12px; min-height: 420px; padding: 16px; }
        .webmail-msg-item { width: 100%; text-align: left; background: transparent; border: 0; border-bottom: 1px solid rgba(148, 163, 184, 0.16); padding: 11px 14px; cursor: pointer; display: block; color: inherit; transition: background .15s ease; }
        .webmail-msg-item:hover { background: rgba(99, 102, 241, 0.08); }
        .webmail-msg-item.is-active { background: rgba(124, 58, 237, 0.14); border-left: 3px solid #8b5cf6; }
        .webmail-msg-item.is-unread .webmail-msg-subject { font-weight: 800; }
        .webmail-msg-subject { font-size: 0.875rem; font-weight: 600; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .webmail-msg-meta { font-size: 0.75rem; opacity: 0.75; display: flex; justify-content: space-between; gap: 8px; }
        .webmail-folder-btn { border: 1px solid rgba(139, 92, 246, 0.35); background: transparent; color: inherit; border-radius: 999px; padding: 4px 12px; font-size: 0.75rem; font-weight: 700; cursor: pointer; }
        .webmail-folder-btn.is-active { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; border-color: transparent; }
        .webmail-iframe { width: 100%; min-height: 320px; border: 1px solid rgba(148, 163, 184, 0.25); border-radius: 8px; background: #fff; }
        .webmail-pre { white-space: pre-wrap; word-break: break-word; font-family: ui-monospace, monospace; font-size: 0.78rem; max-height: 360px; overflow: auto; background: rgba(15, 23, 42, 0.05); padding: 12px; border-radius: 8px; }
        html[data-command-theme='dark'] .webmail-pre { background: #090d16; color: #e2e8f0; }
    </style>
@endpush

@push('scripts')
    <script>
        (function () {
            const root = document.getElementById('webmail-workbench');
            if (!root) return;

            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const form = document.getElementById('webmail-connect-form');
            const usernameInput = document.getElementById('webmail-username');
            const passwordInput = document.getElementById('webmail-password');
            const useSavedCheckbox = document.getElementById('webmail-use-saved-password');
            const saveSettingsCheckbox = document.getElementById('webmail-save-to-settings');
            const portSelect = document.getElementById('webmail-port');
            const securitySelect = document.getElementById('webmail-security');
            const connectBtn = document.getElementById('webmail-connect-btn');
            const refreshBtn = document.getElementById('webmail-refresh-btn');
            const statusAlert = document.getElementById('webmail-status-alert');
            const badge = document.getElementById('webmail-connection-badge');
            const foldersHost = document.getElementById('webmail-folders');
            const listHost = document.getElementById('webmail-message-list');
            const detailHost = document.getElementById('webmail-message-detail');
            const searchInput = document.getElementById('webmail-search');
            const domainToggleBtn = document.getElementById('webmail-toggle-domain-login');

            let activeFolder = 'INBOX';
            let cachedMessages = [];
            let activeUid = null;

            const esc = (v) => String(v ?? '')
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

            const setStatus = (type, message) => {
                if (!message) {
                    statusAlert.className = 'alert d-none mt-3 mb-0';
                    statusAlert.textContent = '';
                    return;
                }
                statusAlert.className = `alert alert-${type} mt-3 mb-0`;
                statusAlert.textContent = message;
            };

            domainToggleBtn?.addEventListener('click', () => {
                const bare = root.dataset.savedUsername || '';
                const dom = root.dataset.defaultDomain || '';
                if (!bare || !dom) return;
                const withDom = `${bare}@${dom}`;
                usernameInput.value = usernameInput.value.trim() === withDom ? bare : withDom;
            });

            portSelect?.addEventListener('change', () => {
                if (portSelect.value === '993' && securitySelect.value === 'auto') {
                    securitySelect.value = 'ssl';
                } else if (portSelect.value === '143' && securitySelect.value === 'ssl') {
                    securitySelect.value = 'auto';
                }
            });

            const renderFolders = (folders) => {
                foldersHost.replaceChildren();
                for (const folder of folders) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'webmail-folder-btn' + (folder === activeFolder ? ' is-active' : '');
                    btn.textContent = folder;
                    btn.addEventListener('click', () => {
                        activeFolder = folder;
                        connectAndLoad();
                    });
                    foldersHost.append(btn);
                }
            };

            const renderMessageList = () => {
                const q = (searchInput?.value || '').trim().toLowerCase();
                const filtered = q === ''
                    ? cachedMessages
                    : cachedMessages.filter((m) =>
                        String(m.subject || '').toLowerCase().includes(q)
                        || String(m.from || '').toLowerCase().includes(q));

                if (filtered.length === 0) {
                    listHost.innerHTML = '<div class="text-center text-muted py-5 px-3">No messages found in ' + esc(activeFolder) + '.</div>';
                    return;
                }

                listHost.innerHTML = filtered.map((msg) => `
                    <button type="button" class="webmail-msg-item ${msg.seen ? '' : 'is-unread'} ${msg.uid === activeUid ? 'is-active' : ''}" data-uid="${esc(msg.uid)}">
                        <div class="webmail-msg-subject">${esc(msg.subject || '(No Subject)')}</div>
                        <div class="webmail-msg-meta">
                            <span>${esc(msg.from || 'Unknown')}</span>
                            <span>${esc(msg.date || '')}</span>
                        </div>
                    </button>
                `).join('');
            };

            const renderDetail = (msg) => {
                const hasHtml = Boolean(msg.html && msg.html.trim() !== '');
                const hasText = Boolean(msg.text && msg.text.trim() !== '');
                const attachmentBadges = (msg.attachments || []).map((name) => `<span class="badge-command-pill mr-1">${esc(name)}</span>`).join('');

                detailHost.innerHTML = `
                    <div class="d-flex flex-wrap justify-content-between align-items-start pb-3 mb-3 border-bottom" style="gap: 10px;">
                        <div>
                            <h3 class="h6 font-w700 mb-1">${esc(msg.subject || '(No Subject)')}</h3>
                            <div class="small text-muted"><strong>From:</strong> ${esc(msg.from)}</div>
                            ${msg.to ? `<div class="small text-muted"><strong>To:</strong> ${esc(msg.to)}</div>` : ''}
                            <div class="small text-muted"><strong>Date:</strong> ${esc(msg.date)} &middot; ${Math.max(1, Math.round((msg.size || 0) / 1024))} KB</div>
                            ${attachmentBadges ? `<div class="mt-2">${attachmentBadges}</div>` : ''}
                        </div>
                        <div class="d-flex align-items-center" style="gap: 6px;">
                            <button type="button" class="btn btn-sm btn-outline-danger" id="webmail-delete-msg" data-uid="${esc(msg.uid)}">
                                <i class="fas fa-trash-alt mr-1"></i>Delete
                            </button>
                        </div>
                    </div>
                    <ul class="nav nav-pills mb-2" style="gap: 6px;">
                        ${hasHtml ? '<li class="nav-item"><button type="button" class="btn btn-sm btn-outline-primary active" data-view="html">HTML</button></li>' : ''}
                        <li class="nav-item"><button type="button" class="btn btn-sm btn-outline-secondary ${hasHtml ? '' : 'active'}" data-view="text">Plain Text</button></li>
                        <li class="nav-item"><button type="button" class="btn btn-sm btn-outline-secondary" data-view="headers">Raw Headers</button></li>
                    </ul>
                    <div id="webmail-pane-html" ${hasHtml ? '' : 'hidden'}></div>
                    <pre id="webmail-pane-text" class="webmail-pre" ${hasHtml ? 'hidden' : ''}>${esc(hasText ? msg.text : '(No plain text part)')}</pre>
                    <pre id="webmail-pane-headers" class="webmail-pre" hidden>${esc(msg.raw_headers || '')}</pre>
                `;

                if (hasHtml) {
                    const frame = document.createElement('iframe');
                    frame.className = 'webmail-iframe';
                    frame.setAttribute('sandbox', '');
                    frame.setAttribute('title', 'Email HTML body');
                    frame.srcdoc = msg.html;
                    detailHost.querySelector('#webmail-pane-html')?.append(frame);
                }

                detailHost.querySelectorAll('[data-view]').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const view = btn.dataset.view;
                        detailHost.querySelectorAll('[data-view]').forEach((b) => b.classList.remove('active'));
                        btn.classList.add('active');
                        detailHost.querySelector('#webmail-pane-html').hidden = view !== 'html';
                        detailHost.querySelector('#webmail-pane-text').hidden = view !== 'text';
                        detailHost.querySelector('#webmail-pane-headers').hidden = view !== 'headers';
                    });
                });

                detailHost.querySelector('#webmail-delete-msg')?.addEventListener('click', async (ev) => {
                    const uid = ev.currentTarget.dataset.uid;
                    if (!uid) return;
                    const delUrl = root.dataset.deleteUrlTemplate.replace('__UID__', encodeURIComponent(uid)) + '?folder=' + encodeURIComponent(activeFolder);
                    const resp = await fetch(delUrl, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    });
                    if (resp.ok) {
                        cachedMessages = cachedMessages.filter((m) => String(m.uid) !== String(uid));
                        activeUid = null;
                        renderMessageList();
                        detailHost.innerHTML = '<div class="text-center text-muted py-5 px-3">Message deleted from ' + esc(activeFolder) + '.</div>';
                    } else {
                        const err = await resp.json().catch(() => ({}));
                        setStatus('danger', err.message || 'Could not delete the selected message.');
                    }
                });
            };

            const loadMessage = async (uid) => {
                activeUid = Number(uid);
                renderMessageList();
                detailHost.innerHTML = '<div class="text-center text-muted py-5 px-3"><i class="fas fa-spinner fa-spin mr-2"></i>Loading message...</div>';
                const url = root.dataset.messageUrlTemplate.replace('__UID__', encodeURIComponent(String(uid))) + '?folder=' + encodeURIComponent(activeFolder);
                try {
                    const resp = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    const payload = await resp.json();
                    if (!resp.ok) {
                        throw new Error(payload.message || 'Could not open message.');
                    }
                    renderDetail(payload.message);
                } catch (err) {
                    detailHost.innerHTML = `<div class="alert alert-danger mb-0">${esc(err.message)}</div>`;
                }
            };

            const connectAndLoad = async () => {
                connectBtn.disabled = true;
                refreshBtn.disabled = true;
                badge.textContent = 'CONNECTING...';
                setStatus(null, '');

                try {
                    const resp = await fetch(root.dataset.connectUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({
                            username: usernameInput.value.trim(),
                            password: passwordInput.value,
                            use_saved_password: useSavedCheckbox ? useSavedCheckbox.checked : true,
                            save_to_settings: saveSettingsCheckbox ? saveSettingsCheckbox.checked : false,
                            port: Number(portSelect.value),
                            security: securitySelect.value,
                            folder: activeFolder,
                        }),
                    });

                    const payload = await resp.json().catch(() => ({}));
                    if (!resp.ok) {
                        const firstError = payload.errors ? Object.values(payload.errors).flat()[0] : null;
                        throw new Error(firstError || payload.message || 'Could not sign in to IMAP webmail.');
                    }

                    badge.textContent = `CONNECTED (${payload.messages.length})`;
                    refreshBtn.disabled = false;
                    cachedMessages = payload.messages || [];
                    renderFolders(payload.folders || ['INBOX']);
                    renderMessageList();
                    if (saveSettingsCheckbox?.checked) {
                        saveSettingsCheckbox.checked = false;
                        setStatus('success', 'Connected and updated Workspace Settings Webmail credentials.');
                    }
                } catch (err) {
                    badge.textContent = 'DISCONNECTED';
                    setStatus('danger', err.message);
                } finally {
                    connectBtn.disabled = false;
                }
            };

            form?.addEventListener('submit', (ev) => {
                ev.preventDefault();
                connectAndLoad();
            });
            refreshBtn?.addEventListener('click', connectAndLoad);
            searchInput?.addEventListener('input', renderMessageList);
            listHost?.addEventListener('click', (ev) => {
                const item = ev.target.closest('[data-uid]');
                if (item) {
                    loadMessage(item.dataset.uid);
                }
            });

            if (root.dataset.savedUsername !== '' && root.dataset.hasSavedPassword === '1') {
                connectAndLoad();
            }
        })();
    </script>
@endpush
