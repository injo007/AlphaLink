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
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const appWindow = window.parent !== window && window.parent.App ? window.parent : window;

    // DMS uses this exact split/filter/exact-dedup behavior before either action.
    const ips = () => [...new Set(ipField.value.split('\n').filter((ip) => ip))];

    const requestData = (includeProvider) => {
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

    const runConnectionTest = async () => {
        const payload = await post(testButton.dataset.url, requestData(false));
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

})();
