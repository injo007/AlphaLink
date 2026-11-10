<dialog
    class="server-ip-config-dialog"
    id="{{ $idPrefix }}-ip-config-dialog"
    aria-labelledby="{{ $idPrefix }}-ip-config-title"
    data-server-ip-config-dialog
    data-config-url-template="{{ $configUrlTemplate }}"
    data-server-selector="{{ $serverSelector }}"
    data-log-selector="{{ $logSelector }}"
    data-refresh-selector="{{ $refreshSelector }}"
>
    <div class="d-flex align-items-center mb-3">
        <div class="command-modal-hero-icon mr-3" style="width: 42px; height: 42px; font-size: 1.125rem;">
            <i class="fas fa-network-wired"></i>
        </div>
        <div>
            <div class="command-modal-hero-eyebrow">NETWORK INTERFACE ALLOCATION</div>
            <h4 class="mb-0 font-w700" id="{{ $idPrefix }}-ip-config-title" style="font-size: 1.125rem;">CONFIGURE SERVER IPS</h4>
        </div>
    </div>

    <div class="command-form-group">
        <label class="command-form-label" for="{{ $idPrefix }}-ip-config-values">
            <i class="fas fa-list-ul mr-1 text-purple"></i> SERVER IPS OR CIDR
        </label>
        <textarea
            id="{{ $idPrefix }}-ip-config-values"
            class="command-form-control server-ip-config-values"
            placeholder="Enter IP addresses or CIDR ranges (one per line)..."
            rows="5"
            data-server-ip-config-values
            style="font-family: monospace;"
        ></textarea>
    </div>

    <div class="custom-control custom-checkbox mt-2">
        <input type="checkbox" class="custom-control-input" id="{{ $idPrefix }}-ip-config-permanent" data-server-ip-config-permanent>
        <label class="custom-control-label" for="{{ $idPrefix }}-ip-config-permanent" style="font-size: 0.8125rem; font-weight: 600;">PERMANENT CONFIGURATION</label>
    </div>
    <p class="server-ip-config-note" style="font-size: 0.75rem; color: var(--command-os-text-muted, #64748b); margin-top: 6px;">Permanent configuration writes interface alias files and restarts networking. Temporary configuration applies aliases dynamically until the next restart.</p>
    <p class="server-ip-config-error text-danger" role="alert" aria-live="polite" data-server-ip-config-error style="font-size: 0.8125rem;"></p>
    
    <div class="command-modal-actions mt-4">
        <button type="button" class="btn-command-action" data-server-ip-config-cancel>Cancel</button>
        <button type="button" class="btn-command-primary" data-server-ip-config-submit>Apply Configuration</button>
    </div>
</dialog>

@push('styles')
    <style>
        .server-ip-config-dialog {
            background: var(--command-os-card-bg, #ffffff);
            border: 1px solid var(--command-os-border, rgba(99, 102, 241, 0.15));
            border-radius: 18px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.25);
            color: var(--command-os-text, #1e293b);
            margin: auto;
            max-height: calc(100dvh - 2rem);
            max-width: calc(100vw - 2rem);
            overflow: auto;
            padding: 1.75rem;
            width: 34rem;
        }
        .server-ip-config-dialog::backdrop {
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
        }
        .server-ip-config-values { min-height: 8rem; resize: vertical; }
        @media (max-width: 575.98px) {
            .server-ip-config-dialog { padding: 1.25rem; }
        }
    </style>
@endpush
