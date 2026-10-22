(() => {
    const provider = document.getElementById('servers_provider_id');
    const name = document.getElementById('name');
    const ipField = document.getElementById('ip');
    const port = document.getElementById('port');
    const username = document.getElementById('username');
    const loginType = document.getElementById('login_type');
    const password = document.getElementById('password');
    const expiry = document.getElementById('expire_at');
    const saveButton = document.getElementById('save');
    const testButton = document.getElementById('test');
    const errors = document.getElementById('validation_errors');
    const pinDialog = document.getElementById('server-pin-confirmation');
    const pinList = document.getElementById('server-pin-list');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const appWindow = window.parent !== window && window.parent.App ? window.parent : window;
    let pendingIps = [];

    // DMS uses this exact split/filter/exact-dedup behavior before either action.
    const ips = () => [...new Set(ipField.value.split('\n').filter((ip) => ip))];

    const requestData = (includeProvider, expectedPins = {}) => {
        const data = new URLSearchParams();
        ips().forEach((ip) => data.append('ips[]', ip));
        data.append('port', port.value);
        data.append('username', username.value);
        data.append('login_type', loginType.value);
        data.append('password', password.value);
        if (includeProvider) {
            data.append('servers_provider_id', provider.value);
            data.append('name', name.value);
            data.append('expire_at', expiry.value);
        }
        Object.entries(expectedPins).forEach(([ip, fingerprint]) => {
            if (ips().includes(ip)) data.append(`expected_host_fingerprints[${ip}]`, fingerprint);
        });
        return data;
    };

    const clearErrors = () => {
        errors.replaceChildren();
        errors.hidden = true;
    };

    const showErrors = (validationErrors) => {
        errors.replaceChildren();
        const list = document.createElement('ul');
        Object.values(validationErrors || {}).flat().forEach((message) => {
            const item = document.createElement('li');
            item.textContent = message;
            list.append(item);
        });
        errors.append(list);
        errors.hidden = false;
    };

    const post = async (url, data) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-CSRF-TOKEN': csrf,
            },
            body: data.toString(),
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
    };

    const setBusy = (button, busy, idleLabel, busyLabel) => {
        button.disabled = busy;
        button.innerHTML = busy ? `${busyLabel} ${window.spinner()}` : idleLabel;
    };

    const showTestResults = (payload) => {
        const result = (payload.data || []).map((message) => message
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;'))
            .join('<br>');

        return appWindow.App.swalPromise({
            icon: 'success',
            title: 'Done',
            html: result,
            confirmButtonText: 'close',
        });
    };

    const runConnectionTest = async (expectedPins = {}) => {
        const payload = await post(testButton.dataset.url, requestData(false, expectedPins));
        const fingerprints = payload.host_fingerprints || {};
        if (Object.keys(fingerprints).length > 0) {
            pendingIps = Object.keys(fingerprints);
            pinList.replaceChildren();
            pendingIps.forEach((ip) => {
                const section = document.createElement('div');
                section.className = 'server-dialog-pin';
                const label = document.createElement('label');
                label.textContent = `Expected fingerprint for ${ip}`;
                const observed = document.createElement('code');
                observed.textContent = `Observed: ${fingerprints[ip]}`;
                const input = document.createElement('input');
                input.className = 'form-control';
                input.type = 'text';
                input.autocomplete = 'off';
                input.dataset.ip = ip;
                input.setAttribute('aria-label', label.textContent);
                section.append(label, observed, input);
                pinList.append(section);
            });
            pinDialog.showModal();
            return;
        }
        await showTestResults(payload);
    };

    saveButton.addEventListener('click', async (event) => {
        event.preventDefault();
        clearErrors();
        if (!ips().length) return;

        setBusy(saveButton, true, 'SAVE', 'SAVING');
        try {
            const payload = await post(saveButton.dataset.url, requestData(true));
            await appWindow.App.swalPromise({
                icon: 'success',
                title: payload.data,
                confirmButtonText: 'close',
            });
            if (window.parent !== window && typeof window.parent.closeModal === 'function') {
                window.parent.closeModal();
            } else {
                window.location.assign(saveButton.dataset.returnUrl);
            }
        } catch (error) {
            if (error.status === 422) {
                showErrors(error.payload.errors);
            } else {
                appWindow.App.processAjaxFail(error.payload || { error: error.message });
            }
        } finally {
            setBusy(saveButton, false, 'SAVE', 'SAVING');
        }
    });

    testButton.addEventListener('click', async (event) => {
        event.preventDefault();
        clearErrors();
        if (!ips().length) return;

        setBusy(testButton, true, 'TEST CONNECTION', 'TESTING');
        try {
            await runConnectionTest();
        } catch {
            // DMS's observed Test Connection handler has no visible failure callback.
        } finally {
            setBusy(testButton, false, 'TEST CONNECTION', 'TESTING');
        }
    });

    document.getElementById('confirm-host-fingerprints').addEventListener('click', async () => {
        const expectedPins = {};
        let valid = true;
        pinList.querySelectorAll('input[data-ip]').forEach((input) => {
            const value = input.value.trim();
            if (!/^SHA256:[A-Za-z0-9+/]{43}$/.test(value)) valid = false;
            expectedPins[input.dataset.ip] = value;
        });
        if (!valid || Object.keys(expectedPins).length !== pendingIps.length) {
            pinDialog.close();
            await appWindow.App.swalPromise({
                icon: 'warning',
                title: 'Check fingerprints',
                text: 'Enter the provider-confirmed SHA-256 fingerprint for every IP.',
                confirmButtonText: 'close',
            });
            return;
        }

        pinDialog.close();
        setBusy(testButton, true, 'TEST CONNECTION', 'TESTING');
        try {
            const payload = await post(testButton.dataset.url, requestData(false, expectedPins));
            const results = payload.results || [];
            const pinsMatch = Object.entries(expectedPins).every(([ip, fingerprint]) => {
                const result = results.find((row) => row.ip === ip);
                return result
                    && result.observed_fingerprint === fingerprint
                    && ['connected', 'authentication_failed'].includes(result.status);
            });
            if (!pinsMatch) {
                await appWindow.App.swalPromise({
                    icon: 'error',
                    title: 'SSH host identity could not be verified',
                    text: 'Check the observed fingerprint with your provider before continuing.',
                    confirmButtonText: 'close',
                });
                return;
            }
            await showTestResults(payload);
        } catch {
            // DMS's observed Test Connection handler has no visible failure callback.
        } finally {
            setBusy(testButton, false, 'TEST CONNECTION', 'TESTING');
        }
    });

    document.querySelector('[data-cancel-pins]').addEventListener('click', () => pinDialog.close());
})();
