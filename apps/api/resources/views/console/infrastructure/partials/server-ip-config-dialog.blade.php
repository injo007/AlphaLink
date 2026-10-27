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
    <h3 class="sr-only" id="{{ $idPrefix }}-ip-config-title">Configure server IPs</h3>
    <span class="server-ip-config-question" aria-hidden="true">?</span>
    <label for="{{ $idPrefix }}-ip-config-values">SERVER IPS OR CIDR</label>
    <textarea
        id="{{ $idPrefix }}-ip-config-values"
        class="form-control server-ip-config-values"
        placeholder="Put Your Ips Here"
        rows="5"
        data-server-ip-config-values
    ></textarea>
    <div class="custom-control custom-checkbox mt-2">
        <input type="checkbox" class="custom-control-input" id="{{ $idPrefix }}-ip-config-permanent" data-server-ip-config-permanent>
        <label class="custom-control-label" for="{{ $idPrefix }}-ip-config-permanent">PERMANENT</label>
    </div>
    <p class="server-ip-config-note">Permanent configuration replaces existing interface alias files and restarts networking. Temporary configuration adds aliases until the next network restart.</p>
    <p class="server-ip-config-error text-danger" role="alert" aria-live="polite" data-server-ip-config-error></p>
    <div class="server-ip-config-actions">
        <button type="button" class="btn btn-alt-secondary" data-server-ip-config-cancel>Cancel</button>
        <button type="button" class="btn btn-primary" data-server-ip-config-submit>Config</button>
    </div>
</dialog>

@push('styles')
    <style>
        .server-ip-config-dialog {
            background: #fff;
            border: 0;
            border-radius: .3125rem;
            box-shadow: 0 0 1.5rem rgb(0 0 0 / 18%);
            color: #545454;
            margin: auto;
            max-height: calc(100dvh - 2rem);
            max-width: calc(100vw - 2rem);
            overflow: auto;
            padding: 1.5rem 1.75rem;
            width: 32rem;
        }
        .server-ip-config-dialog::backdrop { background: rgb(0 0 0 / 40%); }
        .server-ip-config-question {
            align-items: center;
            border: .25rem solid #87adbd;
            border-radius: 50%;
            color: #87adbd;
            display: flex;
            font-size: 3.25rem;
            font-weight: 300;
            height: 5rem;
            justify-content: center;
            line-height: 1;
            margin: .25rem auto 1.25rem;
            width: 5rem;
        }
        .server-ip-config-dialog > label { display: block; font-weight: 600; margin-bottom: .5rem; }
        .server-ip-config-values { min-height: 8rem; resize: none; }
        .server-ip-config-note { color: #8a6d3b; font-size: .875rem; margin: .75rem 0 0; }
        .server-ip-config-error { margin: .5rem 0 0; }
        .server-ip-config-actions { display: flex; gap: .5rem; justify-content: flex-end; margin-top: 1.25rem; }
        @media (max-width: 575.98px) {
            .server-ip-config-dialog { padding: 1.25rem; }
        }
    </style>
@endpush
