@extends('layouts.admin-shell')

@section('title', 'Webmail — '.($ip->dns ?: $ip->ip))

@section('content')
    @php
        $hasCompleteSavedCreds = $savedUsername !== '' && $hasSavedPassword;
    @endphp
    <div class="wm-shell" id="webmail-workbench"
         data-connect-url="{{ $connectUrl }}"
         data-message-url-template="{{ $messageUrlTemplate }}"
         data-delete-url-template="{{ $deleteUrlTemplate }}"
         data-flags-url="{{ $flagsUrl }}"
         data-bulk-delete-url="{{ $bulkDeleteUrl }}"
         data-attachment-url-template="{{ $attachmentUrlTemplate }}"
         data-send-url="{{ $sendUrl }}"
         data-default-domain="{{ $ip->dns }}"
         data-saved-username="{{ $savedUsername }}"
         data-has-saved-password="{{ $hasSavedPassword ? '1' : '0' }}"
         data-default-sender="{{ $defaultSender }}"
         data-vmta-name="{{ $vmtaName }}"
         data-pmta-port="{{ $pmtaPort ?? '' }}">

        <!-- Top Command Header -->
        <header class="wm-topbar">
            <div class="wm-topbar-left">
                <a class="wm-back-btn" href="{{ route('console.infrastructure.my-ips', $workspace->id) }}" title="Back to My IPs">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    <span>My IPs</span>
                </a>
                <div class="wm-identity">
                    <div class="wm-identity-mark" aria-hidden="true">
                        <i class="fas fa-envelope-open-text"></i>
                    </div>
                    <div class="wm-identity-copy">
                        <div class="wm-eyebrow">
                            COMMAND OS WEBMAIL &middot; {{ strtoupper((string) ($server->name ?? 'SERVER')) }}
                            <span class="wm-eyebrow-sep">&bull;</span>
                            <span>{{ $server->ip_address }}</span>
                            @if ($vmtaName !== '')
                                <span class="wm-eyebrow-sep">&bull;</span>
                                <span title="Outbound PMTA Virtual MTA">{{ $vmtaName }}</span>
                            @endif
                        </div>
                        <div class="wm-title-row">
                            <h1 class="wm-title">{{ $ip->dns ?: $ip->ip }}</h1>
                            <span class="wm-ip-chip">{{ $ip->ip }}</span>
                            <span id="webmail-connection-badge" class="wm-status-pill is-idle">READY TO CONNECT</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="wm-topbar-actions">
                <button type="button" class="btn-command-primary wm-btn-compact" id="webmail-compose-open-btn">
                    <i class="fas fa-pen" aria-hidden="true"></i> Compose
                </button>
                <button type="button" class="btn-command-action wm-btn-compact" id="webmail-refresh-btn" disabled>
                    <i class="fas fa-sync-alt" aria-hidden="true"></i> Refresh
                </button>
                <button type="button" class="btn-command-action wm-btn-compact" id="webmail-auth-toggle-btn"
                        aria-expanded="{{ $hasCompleteSavedCreds ? 'false' : 'true' }}" aria-controls="webmail-auth-drawer">
                    <i class="fas fa-key" aria-hidden="true"></i> IMAP Auth
                </button>
                <a class="btn-command-action wm-btn-compact" href="{{ $settingsUrl }}">
                    <i class="fas fa-sliders-h" aria-hidden="true"></i> Settings
                </a>
            </div>
        </header>

        <!-- Collapsible IMAP Connection Drawer -->
        <section class="wm-auth-drawer {{ $hasCompleteSavedCreds ? 'is-collapsed' : '' }}" id="webmail-auth-drawer" aria-labelledby="webmail-login-heading">
            <div class="wm-auth-card">
                <div class="wm-auth-head">
                    <div>
                        <h2 id="webmail-login-heading" class="wm-auth-title">
                            <i class="fas fa-shield-alt mr-2" aria-hidden="true"></i>IMAP Mailbox Authentication
                        </h2>
                        <p class="wm-auth-subtitle">
                            @if ($hasCompleteSavedCreds)
                                Saved Settings credentials detected for <code>{{ $savedUsername }}</code> &mdash; domain route <code>{{ $ip->dns ?: $ip->ip }}</code>.
                            @elseif ($savedUsername !== '' || $hasSavedPassword)
                                Partial Webmail credentials saved in Settings. Complete missing fields below to authenticate.
                            @else
                                Enter your Dovecot IMAP login and password below, or save defaults in Workspace Settings.
                            @endif
                        </p>
                    </div>
                    <button type="button" class="wm-drawer-close" id="webmail-auth-close-btn" title="Collapse panel" aria-label="Collapse IMAP authentication panel">
                        <i class="fas fa-chevron-up" aria-hidden="true"></i>
                    </button>
                </div>

                <form id="webmail-connect-form" novalidate>
                    <div class="wm-auth-grid">
                        <div class="wm-field">
                            <label class="command-form-label" for="webmail-username">IMAP LOGIN / MAILBOX</label>
                            <div class="input-group">
                                <input id="webmail-username" name="username" type="text" class="form-control command-form-control"
                                       value="{{ $defaultLogin }}" placeholder="alimap...@{{ $ip->dns ?: 'domain.com' }}" autocomplete="username">
                                @if ($savedUsername !== '' && $ip->dns)
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="webmail-toggle-domain-login"
                                                title="Toggle between username@domain and bare username">
                                            &#64;domain
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="wm-field">
                            <label class="command-form-label" for="webmail-password">WEBMAIL PASSWORD</label>
                            <input id="webmail-password" name="password" type="password" class="form-control command-form-control"
                                   value="" autocomplete="current-password"
                                   placeholder="{{ $hasSavedPassword ? 'Leave blank to use saved Settings password' : 'Enter Dovecot IMAP password' }}">
                        </div>

                        <div class="wm-field wm-field-sm">
                            <label class="command-form-label" for="webmail-port">IMAP PORT</label>
                            <select id="webmail-port" name="port" class="form-control command-form-control">
                                <option value="143" selected>143 (STARTTLS)</option>
                                <option value="993">993 (IMAPS SSL)</option>
                            </select>
                        </div>

                        <div class="wm-field wm-field-sm">
                            <label class="command-form-label" for="webmail-security">SECURITY</label>
                            <select id="webmail-security" name="security" class="form-control command-form-control">
                                <option value="auto" selected>Auto</option>
                                <option value="starttls">STARTTLS</option>
                                <option value="ssl">SSL / TLS</option>
                                <option value="plain">Plain (No TLS)</option>
                            </select>
                        </div>
                    </div>

                    <div class="wm-auth-footer">
                        <div class="wm-auth-toggles">
                            @if ($hasSavedPassword)
                                <label class="wm-check-label">
                                    <input type="checkbox" id="webmail-use-saved-password" checked>
                                    <span>Use saved password from Settings when blank</span>
                                </label>
                            @endif
                            @if ($canUpdateSettings)
                                <label class="wm-check-label">
                                    <input type="checkbox" id="webmail-save-to-settings">
                                    <span>Save credentials to Workspace Settings</span>
                                </label>
                            @endif
                        </div>

                        <button type="submit" class="btn-command-primary wm-btn-compact" id="webmail-connect-btn">
                            <i class="fas fa-sign-in-alt" aria-hidden="true"></i> Connect Mailbox
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <div id="webmail-status-alert" class="alert d-none wm-status-alert" role="alert"></div>

        <!-- 3-Pane Command OS Email Workspace -->
        <section class="wm-workspace" id="webmail-reader-shell" aria-label="Mailbox Workspace">
            <!-- Pane 1: Folders & Server Domains Rail -->
            <aside class="wm-rail">
                <div class="wm-rail-section">
                    <div class="wm-rail-label">MAILBOX FOLDERS</div>
                    <nav id="webmail-folders" class="wm-folder-list" aria-label="IMAP Folders">
                        <button type="button" class="wm-folder-item is-active" data-folder="INBOX">
                            <span class="wm-folder-name"><i class="fas fa-inbox" aria-hidden="true"></i> INBOX</span>
                            <span class="wm-folder-count">0</span>
                        </button>
                    </nav>
                </div>

                @if (count($siblingIps) > 1)
                    <div class="wm-rail-section">
                        <div class="wm-rail-label">SERVER DOMAINS ({{ count($siblingIps) }})</div>
                        <div class="wm-domain-switcher">
                            @foreach ($siblingIps as $sib)
                                <a href="{{ $sib['url'] }}" class="wm-domain-item {{ $sib['is_current'] ? 'is-current' : '' }}">
                                    <span class="wm-domain-dot" aria-hidden="true"></span>
                                    <span class="wm-domain-copy">
                                        <strong>{{ $sib['dns'] ?: $sib['ip'] }}</strong>
                                        <small>{{ $sib['ip'] }}</small>
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="wm-rail-section wm-rail-telemetry">
                    <div class="wm-rail-label">ENDPOINT PROFILE</div>
                    <dl class="wm-meta-dl">
                        <div><dt>IMAP Host</dt><dd>{{ $server->ip_address }}:143</dd></div>
                        <div><dt>Route Domain</dt><dd>{{ $ip->dns ?: 'Primary IP' }}</dd></div>
                        <div><dt>PMTA VMTA</dt><dd>{{ $vmtaName !== '' ? $vmtaName : 'Unmapped' }}</dd></div>
                    </dl>
                </div>
            </aside>

            <!-- Pane 2: Thread List, Search & Bulk Actions -->
            <div class="wm-list-col">
                <div class="wm-list-toolbar">
                    <div class="wm-search-wrap">
                        <i class="fas fa-search" aria-hidden="true"></i>
                        <input type="search" id="webmail-search" class="wm-search-input" placeholder="Search sender, subject, domain..." aria-label="Search messages">
                    </div>

                    <div class="wm-filter-row">
                        <label class="wm-select-all" title="Select all visible messages">
                            <input type="checkbox" id="webmail-select-all">
                            <span>All</span>
                        </label>
                        <div class="wm-filter-pills" role="tablist" aria-label="Message filters">
                            <button type="button" class="wm-filter-pill is-active" data-filter="all">All</button>
                            <button type="button" class="wm-filter-pill" data-filter="unread">Unread</button>
                            <button type="button" class="wm-filter-pill" data-filter="starred">Starred</button>
                        </div>
                    </div>

                    <div class="wm-bulk-bar d-none" id="webmail-bulk-bar">
                        <span id="webmail-bulk-count">0 selected</span>
                        <div class="wm-bulk-actions">
                            <button type="button" class="wm-mini-btn" data-bulk-flag="read" title="Mark selected as read"><i class="fas fa-envelope-open"></i></button>
                            <button type="button" class="wm-mini-btn" data-bulk-flag="unread" title="Mark selected as unread"><i class="fas fa-envelope"></i></button>
                            <button type="button" class="wm-mini-btn is-danger" id="webmail-bulk-delete-btn" title="Delete selected"><i class="fas fa-trash-alt"></i></button>
                        </div>
                    </div>
                </div>

                <div id="webmail-message-list" class="wm-message-list" aria-live="polite">
                    <div class="wm-empty-state">
                        <div class="wm-empty-icon"><i class="far fa-envelope-open" aria-hidden="true"></i></div>
                        <h3>Connecting to {{ $ip->dns ?: $server->ip_address }}</h3>
                        <p>Loading Dovecot IMAP mailbox...</p>
                    </div>
                </div>
            </div>

            <!-- Pane 3: Reader & Deliverability Inspector -->
            <main class="wm-detail-col" id="webmail-message-detail">
                <div class="wm-empty-state wm-empty-reader">
                    <div class="wm-empty-icon"><i class="fas fa-satellite-dish" aria-hidden="true"></i></div>
                    <h3>Select a message to inspect</h3>
                    <p>Review HTML rendering, attachments, hop traces, and SPF / DKIM / DMARC authentication results — or compose a live probe directly through <code>{{ $vmtaName !== '' ? $vmtaName : $server->ip_address }}</code>.</p>
                    <button type="button" class="btn-command-primary wm-btn-compact mt-2" id="webmail-probe-empty-btn">
                        <i class="fas fa-paper-plane" aria-hidden="true"></i> Send Self-Test Probe
                    </button>
                </div>
            </main>
        </section>

        <!-- Compose / Reply / Forward Modal -->
        <dialog id="webmail-compose-dialog" class="wm-compose-dialog" aria-labelledby="webmail-compose-title">
            <form id="webmail-compose-form" method="dialog" novalidate>
                <div class="wm-compose-head">
                    <div>
                        <span class="wm-compose-eyebrow">OUTBOUND PMTA COMPOSER</span>
                        <h3 id="webmail-compose-title" class="wm-compose-title">New Message</h3>
                    </div>
                    <button type="button" class="wm-drawer-close" id="webmail-compose-close" aria-label="Close composer">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </button>
                </div>

                <div class="wm-compose-route-strip">
                    <span><i class="fas fa-server mr-1"></i> {{ $server->name }} ({{ $server->ip_address }}:{{ $pmtaPort ?? 'N/A' }})</span>
                    <span><i class="fas fa-route mr-1"></i> {{ $vmtaName !== '' ? $vmtaName : 'Default Route' }}</span>
                </div>

                <div id="webmail-compose-alert" class="alert d-none mx-4 mt-3 mb-0" role="alert"></div>

                <div class="wm-compose-body">
                    <input type="hidden" id="wm-compose-in-reply-to" value="">
                    <input type="hidden" id="wm-compose-references" value="">

                    <div class="wm-compose-row">
                        <label for="wm-compose-from">From</label>
                        <input id="wm-compose-from" type="email" class="form-control command-form-control" value="{{ $defaultSender }}" required>
                    </div>
                    <div class="wm-compose-row">
                        <label for="wm-compose-to">To</label>
                        <input id="wm-compose-to" type="text" class="form-control command-form-control" placeholder="recipient@example.com (comma-separated)" required>
                    </div>
                    <div class="wm-compose-row">
                        <label for="wm-compose-cc">Cc</label>
                        <input id="wm-compose-cc" type="text" class="form-control command-form-control" placeholder="Optional Cc addresses">
                    </div>
                    <div class="wm-compose-row">
                        <label for="wm-compose-subject">Subject</label>
                        <input id="wm-compose-subject" type="text" class="form-control command-form-control" placeholder="Message subject" required>
                    </div>

                    <div class="wm-compose-editor-bar">
                        <span>Message Body</span>
                        <div class="wm-compose-mode-tabs">
                            <button type="button" class="wm-filter-pill is-active" data-compose-mode="text">Plain Text</button>
                            <button type="button" class="wm-filter-pill" data-compose-mode="html">HTML Body</button>
                        </div>
                    </div>

                    <textarea id="wm-compose-text" rows="8" class="form-control command-form-control wm-compose-textarea" placeholder="Write your message..."></textarea>
                    <textarea id="wm-compose-html" rows="8" class="form-control command-form-control wm-compose-textarea d-none" placeholder="<p>Optional HTML version...</p>"></textarea>
                </div>

                <div class="wm-compose-foot">
                    <label class="wm-check-label">
                        <input type="checkbox" id="wm-compose-save-sent" checked>
                        <span>Save copy to IMAP <code>Sent</code> folder</span>
                    </label>
                    <div class="d-flex align-items-center" style="gap: 8px;">
                        <button type="button" class="btn-command-action wm-btn-compact" id="webmail-compose-cancel">Cancel</button>
                        <button type="submit" class="btn-command-primary wm-btn-compact" id="webmail-compose-send-btn">
                            <i class="fas fa-paper-plane" aria-hidden="true"></i> Send via PMTA
                        </button>
                    </div>
                </div>
            </form>
        </dialog>
    </div>
@endsection

@push('styles')
    <style>
        .wm-shell {
            display: flex;
            flex-direction: column;
            gap: 14px;
            color: var(--co-ink, #17203e);
        }

        .wm-topbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 16px 20px;
            border-radius: 16px;
            border: 1px solid var(--co-border, #dfe3f1);
            background: linear-gradient(135deg, rgba(121, 61, 246, 0.09), rgba(39, 123, 255, 0.05) 55%, var(--co-surface, #ffffff));
            box-shadow: var(--co-shadow, 0 4px 20px rgba(45, 54, 115, 0.05));
        }

        html[data-command-theme='dark'] .wm-topbar {
            background: linear-gradient(135deg, rgba(161, 63, 255, 0.16), rgba(24, 168, 255, 0.08) 55%, var(--co-surface, #111d3b));
        }

        .wm-topbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
            flex: 1 1 auto;
        }

        .wm-back-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 10px;
            border: 1px solid var(--co-border, #dfe3f1);
            background: var(--co-surface, #ffffff);
            color: var(--co-ink, #17203e);
            font-size: 12px;
            font-weight: 700;
            text-decoration: none !important;
            transition: border-color .15s ease, transform .15s ease;
        }

        .wm-back-btn:hover {
            border-color: var(--co-purple, #793df6);
            color: var(--co-purple, #793df6);
            transform: translateY(-1px);
        }

        .wm-identity {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .wm-identity-mark {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #793df6, #5b46f6);
            color: #fff;
            font-size: 18px;
            flex-shrink: 0;
            box-shadow: 0 8px 20px rgba(121, 61, 246, 0.28);
        }

        .wm-identity-copy {
            min-width: 0;
        }

        .wm-eyebrow {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .08em;
            color: var(--co-muted, #5f6b89);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
        }

        .wm-title-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-top: 2px;
        }

        .wm-title {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -0.025em;
            color: var(--co-ink, #17203e);
        }

        .wm-ip-chip {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            border: 1px solid var(--co-border, #dfe3f1);
            background: var(--co-surface-muted, #f7f8fd);
            color: var(--co-muted, #5f6b89);
        }

        .wm-status-pill {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .06em;
            padding: 3px 10px;
            border-radius: 999px;
            border: 1px solid rgba(121, 61, 246, 0.35);
            background: rgba(121, 61, 246, 0.12);
            color: var(--co-purple, #793df6);
        }

        .wm-status-pill.is-connected {
            border-color: rgba(16, 185, 129, 0.35);
            background: rgba(16, 185, 129, 0.14);
            color: #059669;
        }

        html[data-command-theme='dark'] .wm-status-pill.is-connected {
            color: #34d399;
        }

        .wm-status-pill.is-error {
            border-color: rgba(239, 68, 68, 0.35);
            background: rgba(239, 68, 68, 0.14);
            color: #dc2626;
        }

        html[data-command-theme='dark'] .wm-status-pill.is-error {
            color: #f87171;
        }

        .wm-topbar-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
        }

        .wm-btn-compact {
            display: inline-flex !important;
            align-items: center;
            gap: 6px;
            padding: 8px 14px !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            border-radius: 10px !important;
            white-space: nowrap;
        }

        .wm-auth-drawer.is-collapsed {
            display: none;
        }

        .wm-auth-card {
            padding: 18px 20px;
            border-radius: 14px;
            border: 1px solid var(--co-border, #dfe3f1);
            background: var(--co-surface, #ffffff);
            box-shadow: var(--co-shadow, 0 4px 20px rgba(45, 54, 115, 0.05));
        }

        .wm-auth-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .wm-auth-title {
            margin: 0;
            font-size: 14px;
            font-weight: 800;
            color: var(--co-ink, #17203e);
        }

        .wm-auth-subtitle {
            margin: 3px 0 0;
            font-size: 12px;
            color: var(--co-muted, #5f6b89);
        }

        .wm-drawer-close {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 1px solid var(--co-border, #dfe3f1);
            background: var(--co-surface-muted, #f7f8fd);
            color: var(--co-muted, #5f6b89);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .wm-auth-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) minmax(0, 1.5fr) minmax(135px, 0.7fr) minmax(135px, 0.7fr);
            gap: 12px;
        }

        .wm-auth-footer {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid var(--co-border, #dfe3f1);
        }

        .wm-auth-toggles {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
        }

        .wm-check-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            font-size: 12px;
            color: var(--co-muted, #5f6b89);
            cursor: pointer;
        }

        .wm-status-alert {
            margin: 0;
            border-radius: 12px;
            font-size: 12.5px;
        }

        /* 3-Pane Workspace */
        .wm-workspace {
            display: grid;
            grid-template-columns: 230px minmax(290px, 360px) minmax(0, 1fr);
            min-height: 640px;
            height: calc(100vh - 210px);
            border-radius: 16px;
            border: 1px solid var(--co-border, #dfe3f1);
            background: var(--co-surface, #ffffff);
            box-shadow: var(--co-shadow, 0 4px 20px rgba(45, 54, 115, 0.05));
            overflow: hidden;
        }

        .wm-rail {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 12px;
            border-right: 1px solid var(--co-border, #dfe3f1);
            background: var(--co-surface-muted, #f7f8fd);
            overflow-y: auto;
        }

        .wm-rail-label {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .09em;
            color: var(--co-subtle, #5f6b89);
            padding: 0 8px;
            margin-bottom: 8px;
        }

        .wm-folder-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .wm-folder-item {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 9px 10px;
            border-radius: 10px;
            border: 0;
            background: transparent;
            color: var(--co-ink, #17203e);
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            text-align: left;
            transition: background .14s ease, color .14s ease;
        }

        .wm-folder-item:hover {
            background: rgba(121, 61, 246, 0.09);
        }

        .wm-folder-item.is-active {
            background: linear-gradient(135deg, #793df6, #5f48f5);
            color: #ffffff;
            box-shadow: 0 6px 16px rgba(121, 61, 246, 0.25);
        }

        .wm-folder-name {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .wm-folder-count {
            font-size: 11px;
            font-weight: 700;
            padding: 1px 7px;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.2);
            color: inherit;
        }

        .wm-folder-item.is-active .wm-folder-count {
            background: rgba(255, 255, 255, 0.22);
        }

        .wm-domain-switcher {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .wm-domain-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 9px;
            border-radius: 9px;
            text-decoration: none !important;
            color: var(--co-ink, #17203e);
            border: 1px solid transparent;
        }

        .wm-domain-item:hover {
            background: rgba(121, 61, 246, 0.08);
        }

        .wm-domain-item.is-current {
            background: var(--co-surface, #ffffff);
            border-color: var(--co-border-strong, #d3d8eb);
        }

        .wm-domain-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--co-subtle, #7d8bb5);
            flex-shrink: 0;
        }

        .wm-domain-item.is-current .wm-domain-dot {
            background: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
        }

        .wm-domain-copy {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .wm-domain-copy strong {
            font-size: 12px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .wm-domain-copy small {
            font-size: 10.5px;
            color: var(--co-muted, #5f6b89);
            font-family: ui-monospace, monospace;
        }

        .wm-rail-telemetry {
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid var(--co-border, #dfe3f1);
        }

        .wm-meta-dl {
            margin: 0;
            padding: 0 8px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .wm-meta-dl div {
            display: flex;
            justify-content: space-between;
            gap: 6px;
            font-size: 11px;
        }

        .wm-meta-dl dt {
            color: var(--co-muted, #5f6b89);
            font-weight: 600;
        }

        .wm-meta-dl dd {
            margin: 0;
            font-family: ui-monospace, monospace;
            font-weight: 700;
            text-align: right;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Middle Message List Column */
        .wm-list-col {
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--co-border, #dfe3f1);
            min-height: 0;
            background: var(--co-surface, #ffffff);
        }

        .wm-list-toolbar {
            padding: 12px;
            border-bottom: 1px solid var(--co-border, #dfe3f1);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .wm-search-wrap {
            position: relative;
        }

        .wm-search-wrap i {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 12px;
            color: var(--co-muted, #5f6b89);
        }

        .wm-search-input {
            width: 100%;
            height: 36px;
            padding: 0 12px 0 32px;
            border-radius: 10px;
            border: 1px solid var(--co-border, #dfe3f1);
            background: var(--co-surface-muted, #f7f8fd);
            color: var(--co-ink, #17203e);
            font-size: 12.5px;
            outline: none;
        }

        .wm-search-input:focus {
            border-color: var(--co-purple, #793df6);
        }

        .wm-filter-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .wm-select-all {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin: 0;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--co-muted, #5f6b89);
            cursor: pointer;
        }

        .wm-filter-pills {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px;
            border-radius: 9px;
            background: var(--co-surface-muted, #f7f8fd);
            border: 1px solid var(--co-border, #dfe3f1);
        }

        .wm-filter-pill {
            border: 0;
            background: transparent;
            color: var(--co-muted, #5f6b89);
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 7px;
            cursor: pointer;
        }

        .wm-filter-pill.is-active {
            background: var(--co-purple, #793df6);
            color: #fff;
        }

        .wm-bulk-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 7px 10px;
            border-radius: 9px;
            background: rgba(121, 61, 246, 0.11);
            font-size: 11.5px;
            font-weight: 700;
        }

        .wm-bulk-actions {
            display: inline-flex;
            gap: 6px;
        }

        .wm-mini-btn {
            border: 1px solid var(--co-border, #dfe3f1);
            background: var(--co-surface, #ffffff);
            color: var(--co-ink, #17203e);
            border-radius: 7px;
            padding: 4px 8px;
            font-size: 11px;
            cursor: pointer;
        }

        .wm-mini-btn.is-danger:hover {
            border-color: #ef4444;
            color: #ef4444;
        }

        .wm-message-list {
            flex: 1 1 auto;
            overflow-y: auto;
            min-height: 0;
        }

        .wm-msg-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px;
            border-bottom: 1px solid var(--co-border, #dfe3f1);
            cursor: pointer;
            transition: background .12s ease;
        }

        .wm-msg-row:hover {
            background: rgba(121, 61, 246, 0.06);
        }

        .wm-msg-row.is-active {
            background: rgba(121, 61, 246, 0.12);
            box-shadow: inset 3px 0 0 var(--co-purple, #793df6);
        }

        .wm-msg-row-controls {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding-top: 2px;
        }

        .wm-star-btn {
            border: 0;
            background: transparent;
            padding: 0;
            font-size: 12px;
            color: var(--co-subtle, #7d8bb5);
            cursor: pointer;
        }

        .wm-star-btn.is-starred {
            color: #f59e0b;
        }

        .wm-msg-avatar {
            width: 32px;
            height: 32px;
            border-radius: 9px;
            background: linear-gradient(135deg, rgba(121, 61, 246, 0.18), rgba(39, 123, 255, 0.18));
            color: var(--co-purple, #793df6);
            font-size: 11.5px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .wm-msg-body {
            flex: 1 1 auto;
            min-width: 0;
        }

        .wm-msg-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 3px;
        }

        .wm-msg-from {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--co-ink, #17203e);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .wm-msg-row.is-unread .wm-msg-from,
        .wm-msg-row.is-unread .wm-msg-subject {
            font-weight: 800;
        }

        .wm-msg-time {
            font-size: 10.5px;
            color: var(--co-muted, #5f6b89);
            white-space: nowrap;
        }

        .wm-msg-subject-row {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .wm-unread-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--co-purple, #793df6);
            flex-shrink: 0;
        }

        .wm-msg-subject {
            font-size: 12.5px;
            color: var(--co-ink, #17203e);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .wm-msg-submeta {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 4px;
            font-size: 11px;
            color: var(--co-muted, #5f6b89);
        }

        /* Reader Detail Column */
        .wm-detail-col {
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow-y: auto;
            padding: 20px 24px;
            background: var(--co-surface, #ffffff);
        }

        .wm-empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 48px 24px;
            height: 100%;
            color: var(--co-muted, #5f6b89);
        }

        .wm-empty-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: rgba(121, 61, 246, 0.12);
            color: var(--co-purple, #793df6);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 14px;
        }

        .wm-empty-state h3 {
            margin: 0 0 6px;
            font-size: 15px;
            font-weight: 800;
            color: var(--co-ink, #17203e);
        }

        .wm-empty-state p {
            margin: 0;
            max-width: 420px;
            font-size: 12.5px;
            line-height: 1.5;
        }

        .wm-reader-head {
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--co-border, #dfe3f1);
        }

        .wm-reader-subject {
            margin: 0 0 6px;
            font-size: 18px;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: var(--co-ink, #17203e) !important;
        }

        .wm-reader-meta {
            display: flex;
            flex-direction: column;
            gap: 3px;
            font-size: 12px;
            color: var(--co-muted, #5f6b89);
        }

        .wm-reader-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            margin-top: 2px;
        }

        .wm-reader-actions .wm-btn-compact {
            padding: 6px 11px !important;
            font-size: 11.5px !important;
        }

        .wm-auth-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 12px 0;
        }

        .wm-auth-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            color: var(--co-ink, #17203e);
            border: 1px solid var(--co-border, #dfe3f1);
            background: var(--co-surface-muted, #f7f8fd);
        }

        .wm-auth-badge.is-pass {
            border-color: rgba(16, 185, 129, 0.35);
            background: rgba(16, 185, 129, 0.12);
            color: #059669;
        }

        html[data-command-theme='dark'] .wm-auth-badge.is-pass {
            color: #34d399;
        }

        .wm-auth-badge.is-fail {
            border-color: rgba(239, 68, 68, 0.35);
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
        }

        html[data-command-theme='dark'] .wm-auth-badge.is-fail {
            color: #f87171;
        }

        .wm-attachments-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
        }

        .wm-attachment-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 11px;
            border-radius: 9px;
            border: 1px solid var(--co-border, #dfe3f1);
            background: var(--co-surface-muted, #f7f8fd);
            color: var(--co-ink, #17203e);
            font-size: 12px;
            font-weight: 600;
            text-decoration: none !important;
        }

        .wm-attachment-link:hover {
            border-color: var(--co-purple, #793df6);
            color: var(--co-purple, #793df6);
        }

        .wm-reader-tabs {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 12px;
        }

        .wm-iframe {
            width: 100%;
            min-height: 400px;
            border: 1px solid var(--co-border, #dfe3f1);
            border-radius: 12px;
            background: #ffffff;
        }

        .wm-pre {
            white-space: pre-wrap;
            word-break: break-word;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 12px;
            line-height: 1.55;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid var(--co-border, #dfe3f1);
            background: var(--co-surface-muted, #f7f8fd);
            color: var(--co-ink, #17203e);
            max-height: 480px;
            overflow: auto;
            margin: 0;
        }

        .wm-hops-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 12px;
        }

        .wm-hop-card {
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--co-border, #dfe3f1);
            background: var(--co-surface-muted, #f7f8fd);
            font-family: ui-monospace, monospace;
            font-size: 11.5px;
            word-break: break-word;
        }

        /* Compose Modal */
        .wm-compose-dialog {
            max-width: 720px;
            width: calc(100% - 2rem);
            padding: 0;
            border-radius: 16px;
            border: 1px solid var(--co-border, #dfe3f1);
            background: var(--co-surface, #ffffff);
            color: var(--co-ink, #17203e);
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
        }

        .wm-compose-dialog::backdrop {
            background: rgba(9, 17, 38, 0.65);
            backdrop-filter: blur(4px);
        }

        .wm-compose-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid var(--co-border, #dfe3f1);
        }

        .wm-compose-eyebrow {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .08em;
            color: var(--co-purple, #793df6);
        }

        .wm-compose-title {
            margin: 2px 0 0;
            font-size: 16px;
            font-weight: 800;
        }

        .wm-compose-route-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            padding: 9px 20px;
            background: var(--co-surface-muted, #f7f8fd);
            border-bottom: 1px solid var(--co-border, #dfe3f1);
            font-size: 11.5px;
            font-weight: 600;
            color: var(--co-muted, #5f6b89);
        }

        .wm-compose-body {
            padding: 16px 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .wm-compose-row {
            display: grid;
            grid-template-columns: 76px minmax(0, 1fr);
            align-items: center;
            gap: 10px;
        }

        .wm-compose-row label {
            margin: 0;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--co-muted, #5f6b89);
        }

        .wm-compose-editor-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 4px;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--co-muted, #5f6b89);
        }

        .wm-compose-textarea {
            font-size: 13px;
            min-height: 180px;
        }

        .wm-compose-foot {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 20px;
            border-top: 1px solid var(--co-border, #dfe3f1);
            background: var(--co-surface-muted, #f7f8fd);
        }

        @media (max-width: 1180px) {
            .wm-workspace {
                grid-template-columns: 200px minmax(250px, 310px) minmax(0, 1fr);
            }
            .wm-auth-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 920px) {
            .wm-workspace {
                grid-template-columns: minmax(0, 1fr);
                height: auto;
            }
            .wm-rail,
            .wm-list-col {
                border-right: 0;
                border-bottom: 1px solid var(--co-border, #dfe3f1);
                max-height: 360px;
            }
            .wm-auth-grid {
                grid-template-columns: minmax(0, 1fr);
            }
            .wm-compose-row {
                grid-template-columns: minmax(0, 1fr);
                gap: 4px;
            }
        }
    </style>
@endpush

@push('scripts')
    <script>
        (function () {
            const root = document.getElementById('webmail-workbench');
            if (!root) return;

            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const authDrawer = document.getElementById('webmail-auth-drawer');
            const authToggleBtn = document.getElementById('webmail-auth-toggle-btn');
            const authCloseBtn = document.getElementById('webmail-auth-close-btn');
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
            const selectAllCheckbox = document.getElementById('webmail-select-all');
            const bulkBar = document.getElementById('webmail-bulk-bar');
            const bulkCount = document.getElementById('webmail-bulk-count');
            const bulkDeleteBtn = document.getElementById('webmail-bulk-delete-btn');

            // Compose Modal Elements
            const composeDialog = document.getElementById('webmail-compose-dialog');
            const composeOpenBtn = document.getElementById('webmail-compose-open-btn');
            const composeProbeEmptyBtn = document.getElementById('webmail-probe-empty-btn');
            const composeCloseBtn = document.getElementById('webmail-compose-close');
            const composeCancelBtn = document.getElementById('webmail-compose-cancel');
            const composeForm = document.getElementById('webmail-compose-form');
            const composeTitle = document.getElementById('webmail-compose-title');
            const composeAlert = document.getElementById('webmail-compose-alert');
            const composeFrom = document.getElementById('wm-compose-from');
            const composeTo = document.getElementById('wm-compose-to');
            const composeCc = document.getElementById('wm-compose-cc');
            const composeSubject = document.getElementById('wm-compose-subject');
            const composeText = document.getElementById('wm-compose-text');
            const composeHtml = document.getElementById('wm-compose-html');
            const composeInReplyTo = document.getElementById('wm-compose-in-reply-to');
            const composeReferences = document.getElementById('wm-compose-references');
            const composeSaveSent = document.getElementById('wm-compose-save-sent');
            const composeSendBtn = document.getElementById('webmail-compose-send-btn');

            let activeFolder = 'INBOX';
            let activeFilter = 'all';
            let cachedFolders = ['INBOX'];
            let cachedFolderCounts = {};
            let cachedMessages = [];
            let activeUid = null;
            let activeMessage = null;
            const selectedUids = new Set();

            const esc = (v) => String(v ?? '')
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

            const setDrawerOpen = (open) => {
                if (!authDrawer) return;
                authDrawer.classList.toggle('is-collapsed', !open);
                authToggleBtn?.setAttribute('aria-expanded', open ? 'true' : 'false');
            };

            const setStatus = (type, message) => {
                if (!message) {
                    statusAlert.className = 'alert d-none wm-status-alert';
                    statusAlert.textContent = '';
                    return;
                }
                statusAlert.className = `alert alert-${type} wm-status-alert`;
                statusAlert.textContent = message;
            };

            const setComposeStatus = (type, message) => {
                if (!message) {
                    composeAlert.className = 'alert d-none mx-4 mt-3 mb-0';
                    composeAlert.textContent = '';
                    return;
                }
                composeAlert.className = `alert alert-${type} mx-4 mt-3 mb-0`;
                composeAlert.textContent = message;
            };

            const setBadge = (state, label) => {
                badge.textContent = label;
                badge.classList.remove('is-idle', 'is-connected', 'is-error');
                badge.classList.add(state);
            };

            const folderIconClass = (name) => {
                const lower = String(name).toLowerCase();
                if (lower === 'inbox') return 'fas fa-inbox';
                if (lower.includes('sent')) return 'fas fa-paper-plane';
                if (lower.includes('draft')) return 'fas fa-file-alt';
                if (lower.includes('spam') || lower.includes('junk')) return 'fas fa-shield-virus';
                if (lower.includes('trash') || lower.includes('deleted')) return 'fas fa-trash-alt';
                return 'fas fa-folder';
            };

            const extractEmailOnly = (val) => {
                const str = String(val || '');
                const match = str.match(/<\s*([^<>]+)\s*>/);
                return (match ? match[1] : str).trim();
            };

            const initialsFor = (fromStr) => {
                const clean = String(fromStr || 'U').replace(/<[^>]+>/g, '').replace(/["']/g, '').trim();
                const words = clean.split(/[\s@._-]+/).filter(Boolean);
                if (words.length >= 2) return (words[0][0] + words[1][0]).toUpperCase();
                return clean.slice(0, 2).toUpperCase() || 'ML';
            };

            const formatShortDate = (raw) => {
                if (!raw) return '';
                const d = new Date(raw);
                if (Number.isNaN(d.getTime())) return String(raw).slice(0, 16);
                const now = new Date();
                if (d.toDateString() === now.toDateString()) {
                    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                }
                return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
            };

            const formatBytes = (bytes) => {
                const n = Number(bytes || 0);
                if (n < 1024) return `${n} B`;
                if (n < 1048576) return `${Math.max(1, Math.round(n / 1024))} KB`;
                return `${(n / 1048576).toFixed(1)} MB`;
            };

            const updateBulkBar = () => {
                const count = selectedUids.size;
                bulkCount.textContent = `${count} selected`;
                bulkBar.classList.toggle('d-none', count === 0);
            };

            const filteredMessages = () => {
                const q = (searchInput?.value || '').trim().toLowerCase();
                return cachedMessages.filter((m) => {
                    if (activeFilter === 'unread' && m.seen) return false;
                    if (activeFilter === 'starred' && !m.flagged) return false;
                    if (q === '') return true;
                    return String(m.subject || '').toLowerCase().includes(q)
                        || String(m.from || '').toLowerCase().includes(q)
                        || String(m.to || '').toLowerCase().includes(q);
                });
            };

            const renderFolders = () => {
                foldersHost.replaceChildren();
                for (const folder of cachedFolders) {
                    const stat = cachedFolderCounts[folder];
                    const unseen = stat ? Number(stat.unseen || 0) : null;
                    const total = stat ? Number(stat.messages || 0) : (folder === activeFolder ? cachedMessages.length : null);
                    const countLabel = unseen !== null && unseen > 0
                        ? `${unseen} / ${total}`
                        : (total !== null ? String(total) : '0');

                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'wm-folder-item' + (folder === activeFolder ? ' is-active' : '');
                    btn.dataset.folder = folder;
                    btn.innerHTML = `<span class="wm-folder-name"><i class="${folderIconClass(folder)}" aria-hidden="true"></i> ${esc(folder)}</span><span class="wm-folder-count">${esc(countLabel)}</span>`;
                    btn.addEventListener('click', () => {
                        activeFolder = folder;
                        selectedUids.clear();
                        updateBulkBar();
                        connectAndLoad();
                    });
                    foldersHost.append(btn);
                }
            };

            const renderMessageList = () => {
                const items = filteredMessages();
                if (items.length === 0) {
                    listHost.innerHTML = `
                        <div class="wm-empty-state">
                            <div class="wm-empty-icon"><i class="far fa-envelope" aria-hidden="true"></i></div>
                            <h3>No messages found in ${esc(activeFolder)}.</h3>
                            <p>${cachedMessages.length === 0 ? 'Mailbox is ready for inbound delivery or a self-test probe.' : 'Clear filters or search query to see other messages.'}</p>
                        </div>`;
                    return;
                }

                listHost.innerHTML = items.map((msg) => {
                    const checked = selectedUids.has(Number(msg.uid)) ? 'checked' : '';
                    const active = Number(msg.uid) === Number(activeUid) ? 'is-active' : '';
                    const unread = msg.seen ? '' : 'is-unread';
                    const starred = msg.flagged ? 'is-starred' : '';
                    return `
                        <div class="wm-msg-row ${unread} ${active}" data-uid="${esc(msg.uid)}">
                            <div class="wm-msg-row-controls">
                                <input type="checkbox" class="wm-msg-check" data-check-uid="${esc(msg.uid)}" ${checked} aria-label="Select message">
                                <button type="button" class="wm-star-btn ${starred}" data-star-uid="${esc(msg.uid)}" title="Toggle Star">
                                    <i class="${msg.flagged ? 'fas' : 'far'} fa-star"></i>
                                </button>
                            </div>
                            <div class="wm-msg-avatar">${esc(initialsFor(msg.from))}</div>
                            <div class="wm-msg-body">
                                <div class="wm-msg-top">
                                    <span class="wm-msg-from">${esc(msg.from || 'Unknown')}</span>
                                    <span class="wm-msg-time">${esc(formatShortDate(msg.date))}</span>
                                </div>
                                <div class="wm-msg-subject-row">
                                    ${msg.seen ? '' : '<span class="wm-unread-dot" aria-label="Unread"></span>'}
                                    <span class="wm-msg-subject">${esc(msg.subject || '(No Subject)')}</span>
                                </div>
                                <div class="wm-msg-submeta">
                                    <span>${esc(formatBytes(msg.size))}</span>
                                    ${msg.has_attachments ? '<span><i class="fas fa-paperclip"></i> Attachment</span>' : ''}
                                </div>
                            </div>
                        </div>`;
                }).join('');
            };

            const authPillHtml = (label, status, extra = '') => {
                const st = String(status || 'none').toLowerCase();
                const cls = st === 'pass' ? 'is-pass' : (st === 'fail' || st === 'softfail' ? 'is-fail' : '');
                const icon = st === 'pass' ? 'fa-check-circle' : (st === 'fail' || st === 'softfail' ? 'fa-times-circle' : 'fa-minus-circle');
                return `<span class="wm-auth-badge ${cls}"><i class="fas ${icon}"></i> ${esc(label)}: ${esc(st.toUpperCase())}${extra ? ` (${esc(extra)})` : ''}</span>`;
            };

            const openCompose = (mode = 'new', msg = null) => {
                setComposeStatus(null, '');
                composeFrom.value = root.dataset.defaultSender || usernameInput.value.trim();
                composeCc.value = '';
                composeInReplyTo.value = '';
                composeReferences.value = '';

                if (mode === 'reply' && msg) {
                    composeTitle.textContent = 'Reply to Message';
                    composeTo.value = extractEmailOnly(msg.reply_to || msg.from || '');
                    composeSubject.value = /^re:/i.test(msg.subject || '') ? msg.subject : `Re: ${msg.subject || ''}`;
                    composeInReplyTo.value = msg.message_id || '';
                    composeReferences.value = [msg.references || '', msg.message_id || ''].filter(Boolean).join(' ');
                    composeText.value = `\n\n--- On ${msg.date || ''}, ${msg.from || ''} wrote ---\n${msg.text || ''}`;
                } else if (mode === 'reply-all' && msg) {
                    composeTitle.textContent = 'Reply All';
                    composeTo.value = extractEmailOnly(msg.reply_to || msg.from || '');
                    composeCc.value = [msg.to || '', msg.cc || '']
                        .flatMap((s) => s.split(','))
                        .map(extractEmailOnly)
                        .filter((e) => e && e.toLowerCase() !== composeFrom.value.trim().toLowerCase())
                        .join(', ');
                    composeSubject.value = /^re:/i.test(msg.subject || '') ? msg.subject : `Re: ${msg.subject || ''}`;
                    composeInReplyTo.value = msg.message_id || '';
                    composeReferences.value = [msg.references || '', msg.message_id || ''].filter(Boolean).join(' ');
                    composeText.value = `\n\n--- On ${msg.date || ''}, ${msg.from || ''} wrote ---\n${msg.text || ''}`;
                } else if (mode === 'forward' && msg) {
                    composeTitle.textContent = 'Forward Message';
                    composeTo.value = '';
                    composeSubject.value = /^fwd:/i.test(msg.subject || '') ? msg.subject : `Fwd: ${msg.subject || ''}`;
                    composeText.value = `\n\n---------- Forwarded message ---------\nFrom: ${msg.from || ''}\nDate: ${msg.date || ''}\nSubject: ${msg.subject || ''}\nTo: ${msg.to || ''}\n\n${msg.text || ''}`;
                } else if (mode === 'probe') {
                    composeTitle.textContent = 'Send Self-Test Probe';
                    composeTo.value = composeFrom.value;
                    composeSubject.value = `AlphaLink Deliverability Probe — ${root.dataset.defaultDomain || 'mailbox'}`;
                    composeText.value = `Automated PMTA -> Dovecot round-trip probe sent at ${new Date().toISOString()} via ${root.dataset.vmtaName || 'default route'}.`;
                } else {
                    composeTitle.textContent = 'New Message';
                    composeTo.value = '';
                    composeSubject.value = '';
                    composeText.value = '';
                }

                if (typeof composeDialog.showModal === 'function') {
                    composeDialog.showModal();
                } else {
                    composeDialog.setAttribute('open', 'open');
                }
            };

            const renderDetail = (msg) => {
                activeMessage = msg;
                const hasHtml = Boolean(msg.html && msg.html.trim() !== '');
                const hasText = Boolean(msg.text && msg.text.trim() !== '');
                const auth = msg.auth || {};
                const attachments = msg.attachment_items || (msg.attachments || []).map((name, index) => ({
                    index,
                    filename: name,
                    content_type: 'file',
                    size: 0,
                }));

                const attachHtml = attachments.map((att) => {
                    const url = root.dataset.attachmentUrlTemplate
                        .replace('__UID__', encodeURIComponent(String(msg.uid)))
                        .replace('__INDEX__', encodeURIComponent(String(att.index))) + '?folder=' + encodeURIComponent(activeFolder);
                    return `<a class="wm-attachment-link" href="${esc(url)}" download="${esc(att.filename)}">
                        <i class="fas fa-paperclip"></i>
                        <span>${esc(att.filename)}</span>
                        ${att.size ? `<small class="text-muted">(${esc(formatBytes(att.size))})</small>` : ''}
                    </a>`;
                }).join('');

                const hopsHtml = (msg.received_hops || []).length > 0
                    ? (msg.received_hops || []).map((hop, i) => `<div class="wm-hop-card"><strong>Hop ${i + 1}:</strong> ${esc(hop)}</div>`).join('')
                    : '<div class="text-muted small">No Received headers found in this message.</div>';

                detailHost.innerHTML = `
                    <div class="wm-reader-head">
                        <div style="min-width: 0; width: 100%;">
                            <h2 class="wm-reader-subject">${esc(msg.subject || '(No Subject)')}</h2>
                            <div class="wm-reader-meta">
                                <div><strong>From:</strong> ${esc(msg.from)}</div>
                                ${msg.to ? `<div><strong>To:</strong> ${esc(msg.to)}</div>` : ''}
                                ${msg.cc ? `<div><strong>Cc:</strong> ${esc(msg.cc)}</div>` : ''}
                                ${msg.return_path ? `<div><strong>Return-Path:</strong> <code>${esc(msg.return_path)}</code></div>` : ''}
                                <div><strong>Date:</strong> ${esc(msg.date)} &middot; ${esc(formatBytes(msg.size))}</div>
                            </div>
                        </div>
                        <div class="wm-reader-actions">
                            <button type="button" class="btn-command-primary wm-btn-compact" id="wm-reply-btn"><i class="fas fa-reply"></i> Reply</button>
                            <button type="button" class="btn-command-action wm-btn-compact" id="wm-reply-all-btn"><i class="fas fa-reply-all"></i> Reply All</button>
                            <button type="button" class="btn-command-action wm-btn-compact" id="wm-forward-btn"><i class="fas fa-share"></i> Forward</button>
                            <button type="button" class="btn-command-action wm-btn-compact" id="wm-unread-btn" title="Mark unread"><i class="fas fa-envelope"></i></button>
                            <button type="button" class="btn-command-action wm-btn-compact text-danger" id="webmail-delete-msg" data-uid="${esc(msg.uid)}"><i class="fas fa-trash-alt"></i> Delete</button>
                        </div>
                    </div>

                    <div class="wm-auth-strip">
                        ${authPillHtml('SPF', auth.spf?.status || 'none')}
                        ${authPillHtml('DKIM', auth.dkim?.status || 'none', auth.dkim?.domain || '')}
                        ${authPillHtml('DMARC', auth.dmarc?.status || 'none', auth.dmarc?.policy || '')}
                        ${auth.sender_ip ? `<span class="wm-auth-badge"><i class="fas fa-network-wired"></i> Sender IP: ${esc(auth.sender_ip)}</span>` : ''}
                        ${auth.virtual_mta ? `<span class="wm-auth-badge"><i class="fas fa-route"></i> ${esc(auth.virtual_mta)}</span>` : ''}
                    </div>

                    ${attachHtml ? `<div class="wm-attachments-strip">${attachHtml}</div>` : ''}

                    <div class="wm-reader-tabs">
                        <div class="wm-filter-pills">
                            ${hasHtml ? '<button type="button" class="wm-filter-pill is-active" data-view="html">HTML Preview</button>' : ''}
                            <button type="button" class="wm-filter-pill ${hasHtml ? '' : 'is-active'}" data-view="text">Plain Text</button>
                            <button type="button" class="wm-filter-pill" data-view="auth">Auth &amp; Hops</button>
                            <button type="button" class="wm-filter-pill" data-view="headers">Raw Headers</button>
                        </div>
                        <button type="button" class="wm-mini-btn" id="wm-copy-headers-btn"><i class="far fa-copy mr-1"></i>Copy Headers</button>
                    </div>

                    <div id="webmail-pane-html" ${hasHtml ? '' : 'hidden'}></div>
                    <pre id="webmail-pane-text" class="wm-pre" ${hasHtml ? 'hidden' : ''}>${esc(hasText ? msg.text : '(No plain text part)')}</pre>
                    <div id="webmail-pane-auth" hidden>
                        <div class="wm-hop-card"><strong>SPF:</strong> ${esc(auth.spf?.detail || 'None')}</div>
                        <div class="wm-hop-card mt-2"><strong>DKIM:</strong> ${esc(auth.dkim?.detail || 'None')}${auth.dkim?.selector ? ` &middot; s=${esc(auth.dkim.selector)}` : ''}</div>
                        <div class="wm-hop-card mt-2"><strong>DMARC:</strong> ${esc(auth.dmarc?.detail || 'None')}</div>
                        <div class="wm-hops-list">${hopsHtml}</div>
                    </div>
                    <pre id="webmail-pane-headers" class="wm-pre" hidden>${esc(msg.raw_headers || '')}</pre>
                `;

                if (hasHtml) {
                    const frame = document.createElement('iframe');
                    frame.className = 'wm-iframe';
                    frame.setAttribute('sandbox', '');
                    frame.setAttribute('title', 'Email HTML body');
                    frame.srcdoc = msg.html;
                    detailHost.querySelector('#webmail-pane-html')?.append(frame);
                }

                detailHost.querySelectorAll('[data-view]').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const view = btn.dataset.view;
                        detailHost.querySelectorAll('[data-view]').forEach((b) => b.classList.remove('is-active'));
                        btn.classList.add('is-active');
                        detailHost.querySelector('#webmail-pane-html').hidden = view !== 'html';
                        detailHost.querySelector('#webmail-pane-text').hidden = view !== 'text';
                        detailHost.querySelector('#webmail-pane-auth').hidden = view !== 'auth';
                        detailHost.querySelector('#webmail-pane-headers').hidden = view !== 'headers';
                    });
                });

                detailHost.querySelector('#wm-reply-btn')?.addEventListener('click', () => openCompose('reply', msg));
                detailHost.querySelector('#wm-reply-all-btn')?.addEventListener('click', () => openCompose('reply-all', msg));
                detailHost.querySelector('#wm-forward-btn')?.addEventListener('click', () => openCompose('forward', msg));
                detailHost.querySelector('#wm-copy-headers-btn')?.addEventListener('click', async () => {
                    await navigator.clipboard?.writeText(msg.raw_headers || '');
                    setStatus('success', 'Copied full RFC 5322 headers to clipboard.');
                });

                detailHost.querySelector('#wm-unread-btn')?.addEventListener('click', () => updateMessageFlags([msg.uid], 'unread'));

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
                        selectedUids.delete(Number(uid));
                        activeUid = null;
                        activeMessage = null;
                        updateBulkBar();
                        renderMessageList();
                        detailHost.innerHTML = '<div class="wm-empty-state"><div class="wm-empty-icon"><i class="fas fa-trash-alt"></i></div><h3>Message deleted</h3><p>Removed from ' + esc(activeFolder) + '.</p></div>';
                    } else {
                        const err = await resp.json().catch(() => ({}));
                        setStatus('danger', err.message || 'Could not delete the selected message.');
                    }
                });
            };

            const loadMessage = async (uid) => {
                activeUid = Number(uid);
                const listEntry = cachedMessages.find((m) => Number(m.uid) === activeUid);
                if (listEntry) listEntry.seen = true;
                renderMessageList();
                detailHost.innerHTML = '<div class="wm-empty-state"><i class="fas fa-spinner fa-spin fa-2x mb-2"></i><p>Loading RFC 5322 message...</p></div>';
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

            const updateMessageFlags = async (uids, action) => {
                const resp = await fetch(root.dataset.flagsUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ folder: activeFolder, uids, action }),
                });
                if (!resp.ok) {
                    const err = await resp.json().catch(() => ({}));
                    setStatus('danger', err.message || 'Could not update message flags.');
                    return;
                }
                const uidSet = new Set(uids.map(Number));
                cachedMessages.forEach((m) => {
                    if (!uidSet.has(Number(m.uid))) return;
                    if (action === 'read') m.seen = true;
                    if (action === 'unread') m.seen = false;
                    if (action === 'star') m.flagged = true;
                    if (action === 'unstar') m.flagged = false;
                });
                renderMessageList();
            };

            const connectAndLoad = async () => {
                connectBtn.disabled = true;
                refreshBtn.disabled = true;
                setBadge('is-idle', 'CONNECTING...');
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
                            include_counts: true,
                        }),
                    });

                    const payload = await resp.json().catch(() => ({}));
                    if (!resp.ok) {
                        const firstError = payload.errors ? Object.values(payload.errors).flat()[0] : null;
                        throw new Error(firstError || payload.message || 'Could not sign in to IMAP webmail.');
                    }

                    cachedMessages = payload.messages || [];
                    cachedFolders = payload.folders || ['INBOX'];
                    cachedFolderCounts = payload.folder_counts || {};
                    setBadge('is-connected', `CONNECTED (${cachedMessages.length})`);
                    refreshBtn.disabled = false;
                    renderFolders();
                    renderMessageList();

                    if (saveSettingsCheckbox?.checked) {
                        saveSettingsCheckbox.checked = false;
                        setStatus('success', 'Connected and saved Webmail credentials to Workspace Settings.');
                    }
                    setDrawerOpen(false);
                } catch (err) {
                    setBadge('is-error', 'DISCONNECTED');
                    setDrawerOpen(true);
                    setStatus('danger', err.message);
                } finally {
                    connectBtn.disabled = false;
                }
            };

            authToggleBtn?.addEventListener('click', () => setDrawerOpen(authDrawer.classList.contains('is-collapsed')));
            authCloseBtn?.addEventListener('click', () => setDrawerOpen(false));

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

            form?.addEventListener('submit', (ev) => {
                ev.preventDefault();
                connectAndLoad();
            });
            refreshBtn?.addEventListener('click', connectAndLoad);
            searchInput?.addEventListener('input', renderMessageList);

            document.querySelectorAll('.wm-filter-pill[data-filter]').forEach((pill) => {
                pill.addEventListener('click', () => {
                    activeFilter = pill.dataset.filter || 'all';
                    document.querySelectorAll('.wm-filter-pill[data-filter]').forEach((p) => p.classList.remove('is-active'));
                    pill.classList.add('is-active');
                    renderMessageList();
                });
            });

            selectAllCheckbox?.addEventListener('change', () => {
                const visible = filteredMessages();
                selectedUids.clear();
                if (selectAllCheckbox.checked) {
                    visible.forEach((m) => selectedUids.add(Number(m.uid)));
                }
                updateBulkBar();
                renderMessageList();
            });

            document.querySelectorAll('[data-bulk-flag]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    if (selectedUids.size === 0) return;
                    updateMessageFlags(Array.from(selectedUids), btn.dataset.bulkFlag);
                });
            });

            bulkDeleteBtn?.addEventListener('click', async () => {
                if (selectedUids.size === 0) return;
                const uids = Array.from(selectedUids);
                const resp = await fetch(root.dataset.bulkDeleteUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ folder: activeFolder, uids }),
                });
                if (resp.ok) {
                    const uidSet = new Set(uids);
                    cachedMessages = cachedMessages.filter((m) => !uidSet.has(Number(m.uid)));
                    if (activeUid && uidSet.has(Number(activeUid))) {
                        activeUid = null;
                        detailHost.innerHTML = '<div class="wm-empty-state"><div class="wm-empty-icon"><i class="fas fa-trash-alt"></i></div><h3>Selected messages deleted</h3></div>';
                    }
                    selectedUids.clear();
                    updateBulkBar();
                    renderMessageList();
                } else {
                    const err = await resp.json().catch(() => ({}));
                    setStatus('danger', err.message || 'Bulk delete failed.');
                }
            });

            listHost?.addEventListener('click', (ev) => {
                const check = ev.target.closest('.wm-msg-check');
                if (check) {
                    ev.stopPropagation();
                    const uid = Number(check.dataset.checkUid);
                    if (check.checked) selectedUids.add(uid);
                    else selectedUids.delete(uid);
                    updateBulkBar();
                    return;
                }

                const starBtn = ev.target.closest('[data-star-uid]');
                if (starBtn) {
                    ev.stopPropagation();
                    const uid = Number(starBtn.dataset.starUid);
                    const msg = cachedMessages.find((m) => Number(m.uid) === uid);
                    updateMessageFlags([uid], msg?.flagged ? 'unstar' : 'star');
                    return;
                }

                const row = ev.target.closest('[data-uid]');
                if (row) {
                    loadMessage(row.dataset.uid);
                }
            });

            // Compose Modal Events
            composeOpenBtn?.addEventListener('click', () => openCompose('new'));
            composeProbeEmptyBtn?.addEventListener('click', () => openCompose('probe'));
            const closeCompose = () => {
                if (typeof composeDialog.close === 'function') composeDialog.close();
                else composeDialog.removeAttribute('open');
            };
            composeCloseBtn?.addEventListener('click', closeCompose);
            composeCancelBtn?.addEventListener('click', closeCompose);

            document.querySelectorAll('[data-compose-mode]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const mode = btn.dataset.composeMode;
                    document.querySelectorAll('[data-compose-mode]').forEach((b) => b.classList.remove('is-active'));
                    btn.classList.add('is-active');
                    composeText.classList.toggle('d-none', mode !== 'text');
                    composeHtml.classList.toggle('d-none', mode !== 'html');
                });
            });

            composeForm?.addEventListener('submit', async (ev) => {
                ev.preventDefault();
                composeSendBtn.disabled = true;
                setComposeStatus(null, '');

                try {
                    const resp = await fetch(root.dataset.sendUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                        body: JSON.stringify({
                            from: composeFrom.value.trim(),
                            to: composeTo.value.trim(),
                            cc: composeCc.value.trim(),
                            subject: composeSubject.value.trim(),
                            text: composeText.value,
                            html: composeHtml.value,
                            in_reply_to: composeInReplyTo.value.trim(),
                            references: composeReferences.value.trim(),
                            save_to_sent: Boolean(composeSaveSent.checked),
                        }),
                    });

                    const payload = await resp.json().catch(() => ({}));
                    if (!resp.ok) {
                        const firstError = payload.errors ? Object.values(payload.errors).flat()[0] : null;
                        throw new Error(firstError || payload.message || 'Could not send message via PMTA.');
                    }

                    closeCompose();
                    setStatus('success', `Sent via PMTA (${payload.vmta}) on port ${payload.port}.${payload.saved_to_sent ? ' Saved copy to Sent.' : ''}`);
                    if (activeFolder.toLowerCase() === 'sent' || composeTo.value.includes(composeFrom.value)) {
                        setTimeout(connectAndLoad, 1200);
                    }
                } catch (err) {
                    setComposeStatus('danger', err.message);
                } finally {
                    composeSendBtn.disabled = false;
                }
            });

            if (root.dataset.savedUsername !== '' && root.dataset.hasSavedPassword === '1') {
                connectAndLoad();
            }
        })();
    </script>
@endpush
