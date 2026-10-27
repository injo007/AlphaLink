(() => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const request = async (url, options = {}) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                ...(options.headers ?? {}),
            },
        });
        let body = {};
        try {
            body = await response.json();
        } catch {
            body = {};
        }
        if (!response.ok) {
            const validationMessages = Object.values(body.errors ?? {}).flat();
            throw new Error(validationMessages.join(' ') || body.message || 'The saved header request failed.');
        }
        return body;
    };

    document.querySelectorAll('[data-list-url][data-target-id]').forEach((library) => {
        const select = library.querySelector('select');
        const saveButton = library.querySelector('[data-header-save]');
        const deleteButton = library.querySelector('[data-header-delete]');
        const status = library.querySelector('[data-header-status]');
        const target = document.getElementById(library.dataset.targetId);
        if (!select || !saveButton || !deleteButton || !status || !target) return;

        const setStatus = (message, isError = false) => {
            status.textContent = message;
            status.classList.toggle('text-danger', isError);
            status.classList.toggle('text-success', !isError && message !== '');
        };

        const templateUrl = (template) => template.replace('__HEADER_ID__', encodeURIComponent(select.value));

        const loadHeaders = async () => {
            const response = await request(library.dataset.listUrl, { headers: {} });
            select.replaceChildren(new Option('Select Header', ''));
            (response.headers ?? []).forEach((header) => {
                select.add(new Option(header.name, String(header.id)));
            });
            select.value = '';
            deleteButton.disabled = select.value === '';
            if (window.jQuery?.fn?.selectpicker) window.jQuery(select).selectpicker('refresh');
        };

        select.addEventListener('change', async () => {
            deleteButton.disabled = select.value === '';
            if (!select.value) return;
            setStatus('Loading header…');
            try {
                const response = await request(templateUrl(library.dataset.showUrlTemplate), { headers: {} });
                target.value = response.data ?? '';
                setStatus('Header loaded into the editor.');
            } catch (error) {
                setStatus(error.message, true);
            }
        });

        saveButton.addEventListener('click', async () => {
            if (!target.value.trim()) {
                setStatus('Enter a header before saving it.', true);
                return;
            }
            if (!window.swal?.fire) {
                setStatus('The DMS header-name dialog is unavailable.', true);
                return;
            }
            const { value, isConfirmed } = await window.swal.fire({ title: 'Enter Header Name', input: 'text' });
            const name = String(value ?? '').trim();
            if (!isConfirmed || !name) return;
            saveButton.disabled = true;
            setStatus('Saving header…');
            try {
                const response = await request(library.dataset.storeUrl, {
                    method: 'POST',
                    body: JSON.stringify({ name, text: target.value }),
                });
                await loadHeaders();
                if (response.status) {
                    await window.swal.fire({ title: 'Success', text: 'Header Saved', icon: 'success' });
                }
                setStatus('');
            } catch (error) {
                setStatus(error.message, true);
            } finally {
                saveButton.disabled = false;
            }
        });

        deleteButton.addEventListener('click', async () => {
            if (!select.value) return;
            deleteButton.disabled = true;
            setStatus('Deleting header…');
            try {
                const response = await request(templateUrl(library.dataset.deleteUrlTemplate), { method: 'DELETE' });
                await loadHeaders();
                if (response.status) {
                    await window.swal?.fire({ title: 'Success', text: 'Header Deleted', icon: 'success' });
                }
                setStatus('');
            } catch (error) {
                setStatus(error.message, true);
                deleteButton.disabled = select.value === '';
            }
        });

        loadHeaders().catch((error) => setStatus(error.message, true));
    });
})();
