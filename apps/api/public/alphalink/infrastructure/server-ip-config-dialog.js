async function readJson(response) {
    return response.json().catch(() => ({}));
}

function initializeServerIpConfigDialog(dialog) {
    const opener = document.querySelector(`[aria-controls="${dialog.id}"]`);
    const textarea = dialog.querySelector('[data-server-ip-config-values]');
    const permanent = dialog.querySelector('[data-server-ip-config-permanent]');
    const error = dialog.querySelector('[data-server-ip-config-error]');
    const cancel = dialog.querySelector('[data-server-ip-config-cancel]');
    const submit = dialog.querySelector('[data-server-ip-config-submit]');
    const serverSelect = document.querySelector(dialog.dataset.serverSelector ?? '');
    const logOutput = document.querySelector(dialog.dataset.logSelector ?? '');
    const refreshButton = document.querySelector(dialog.dataset.refreshSelector ?? '');
    if (!opener || !textarea || !permanent || !error || !cancel || !submit || !serverSelect) return;

    function closeDialog() {
        error.textContent = '';
        dialog.close();
    }

    opener.addEventListener('click', () => {
        if (!serverSelect.value) return;
        textarea.value = '';
        permanent.checked = false;
        error.textContent = '';
        dialog.showModal();
        textarea.focus();
    });
    cancel.addEventListener('click', closeDialog);
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) closeDialog();
    });
    submit.addEventListener('click', async () => {
        const ips = textarea.value.split(/\r?\n/).map((ip) => ip.trim()).filter(Boolean);
        if (ips.length === 0) {
            error.textContent = 'Enter at least one IPv4 address or CIDR.';
            return;
        }

        const template = dialog.dataset.configUrlTemplate ?? '';
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        submit.disabled = true;
        submit.textContent = 'CONFIGURING';
        error.textContent = '';
        try {
            const response = await fetch(template.replace('__SERVER__', encodeURIComponent(serverSelect.value)), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ips, permanent: permanent.checked}),
            });
            const result = await readJson(response);
            if (response.status === 401 || response.status === 419) {
                window.location.reload();
                return;
            }
            if (!response.ok) throw new Error(result.data || result.message || 'Unable to configure server IPs.');

            closeDialog();
            if (logOutput) logOutput.textContent = result.data || 'Finish Settings Ips';
            refreshButton?.click();
        } catch (failure) {
            error.textContent = failure instanceof Error ? failure.message : 'Unable to configure server IPs.';
        } finally {
            submit.disabled = false;
            submit.textContent = 'Config';
        }
    });
}

function initializeServerIpConfigDialogs() {
    document.querySelectorAll('[data-server-ip-config-dialog]').forEach(initializeServerIpConfigDialog);
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeServerIpConfigDialogs, {once: true});
    } else {
        initializeServerIpConfigDialogs();
    }
}
