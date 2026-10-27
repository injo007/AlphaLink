const optionNames = ['tracking', 'proxy', 'imap', 'spf', 'dkim', 'dmarc', 'https'];

export function buildSingleInstallPayload({ serverId, rows, key, pmtaVersion, switches }) {
    const assignments = rows.map((row) => ({
        ip: String(row.ip ?? '').trim(),
        type: String(row.type ?? '').toLowerCase(),
        main: Boolean(row.main),
        sub: String(row.sub ?? '').trim(),
        domain_id: String(row.domain_id ?? ''),
    }));

    const mainAssignment = assignments.find((assignment) => assignment.main) ?? assignments[0];

    return {
        data: [{ id: String(serverId), domain: mainAssignment?.domain_id ?? '', ip_assignments: assignments }],
        key: Number(key),
        pmta_version: Number(pmtaVersion),
        ...Object.fromEntries(optionNames.map((name) => [name, switches[name] ? 1 : 0])),
    };
}

function selectedDomains(select) {
    return Array.from(select.selectedOptions, (option) => ({ id: option.value, label: option.textContent ?? '' }))
        .filter((domain) => domain.id !== '');
}

function createCell(value) {
    const cell = document.createElement('td');
    cell.textContent = value;
    return cell;
}

function readRows(tbody) {
    return Array.from(tbody.querySelectorAll('[data-install-ip-row]'), (row) => ({
        ip: row.dataset.ip ?? '',
        type: row.dataset.type ?? '',
        main: row.querySelector('[data-install-main]')?.checked ?? false,
        sub: row.querySelector('[data-install-sub]')?.value ?? '',
        domain_id: row.dataset.domainId ?? '',
    }));
}

function renderRows(tbody, rows) {
    tbody.replaceChildren();

    for (const assignment of rows) {
        const row = document.createElement('tr');
        row.dataset.installIpRow = '';
        row.dataset.ip = String(assignment.ip ?? '');
        row.dataset.type = String(assignment.type ?? '');
        row.dataset.domainId = String(assignment.domain_id ?? '');
        row.append(createCell(row.dataset.ip), createCell(row.dataset.type.toUpperCase()));

        const mainCell = document.createElement('td');
        const main = document.createElement('input');
        main.type = 'checkbox';
        main.checked = Boolean(assignment.main);
        main.setAttribute('aria-label', `Main IP ${row.dataset.ip}`);
        main.dataset.installMain = '';
        mainCell.append(main);
        row.append(mainCell);

        const subCell = document.createElement('td');
        const sub = document.createElement('input');
        sub.type = 'text';
        sub.className = 'form-control form-control-sm single-install-subdomain';
        sub.value = String(assignment.sub ?? '');
        sub.maxLength = 63;
        sub.setAttribute('aria-label', `Subdomain for ${row.dataset.ip}`);
        sub.dataset.installSub = '';
        subCell.append(sub);
        row.append(subCell);

        const domainCell = document.createElement('td');
        const domain = document.createElement('input');
        domain.type = 'text';
        domain.className = 'form-control form-control-sm single-install-ip-value single-install-domain';
        domain.readOnly = true;
        domain.value = String(assignment.domain ?? '');
        domain.setAttribute('aria-label', `Domain for ${row.dataset.ip}`);
        domainCell.append(domain);
        row.append(domainCell);

        const actionCell = document.createElement('td');
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-sm btn-alt-danger single-install-remove';
        remove.setAttribute('aria-label', `Remove ${row.dataset.ip}`);
        remove.title = 'Remove IP';
        const icon = document.createElement('i');
        icon.className = 'fa fa-times';
        icon.setAttribute('aria-hidden', 'true');
        remove.append(icon);
        actionCell.append(remove);
        row.append(actionCell);

        tbody.append(row);
    }
}

async function readJson(response) {
    return response.json().catch(() => ({}));
}

export function responseErrorMessage(body, fallback) {
    const fieldErrors = Object.values(body?.errors ?? {})
        .flat()
        .filter((message) => typeof message === 'string' && message.trim() !== '');
    const messages = [body?.message, ...fieldErrors]
        .filter((message) => typeof message === 'string' && message.trim() !== '');

    return messages.join('\n') || fallback;
}

function initializeSingleInstallPage() {
    const form = document.querySelector('#single-install-form');
    if (!form) return;

    const serverSelect = form.querySelector('#single-server');
    const domainSelect = form.querySelector('#single-domains');
    const withIpv6 = form.querySelector('#single-with-ipv6');
    const loadButton = form.querySelector('#single-load-ips');
    const configIpsButton = form.querySelector('#single-config-ips');
    const holder = form.querySelector('#single-install-holder');
    const tbody = form.querySelector('#single-install-ip-rows');
    const installButton = form.querySelector('#single-install-submit');
    const configIpsNote = form.querySelector('#single-install-config-ips-note');
    const error = form.querySelector('#single-install-error');
    const logPanel = form.querySelector('#single-install-logs');
    const logOutput = form.querySelector('#single-install-log');

    const setError = (message) => {
        error.textContent = message;
        error.classList.toggle('d-none', message === '');
    };

    loadButton.addEventListener('click', async () => {
        const domains = selectedDomains(domainSelect);
        if (!serverSelect.value || domains.length === 0) {
            setError('Please select a server and at least one domain.');
            return;
        }

        setError('');
        loadButton.disabled = true;
        loadButton.textContent = 'LOADING IPS…';
        installButton.disabled = true;

        try {
            const response = await fetch(form.dataset.loadUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({
                    server_id: serverSelect.value,
                    domain_ids: domains.map((domain) => domain.id),
                    with_ipv6: withIpv6.checked ? 1 : 0,
                }),
            });
            const body = await readJson(response);
            if (response.status === 401 || response.status === 419) {
                window.location.reload();
                return;
            }
            if (!response.ok) {
                throw new Error(responseErrorMessage(body, 'Could not load server IPs. The server returned an empty error response.'));
            }

            const rows = Array.isArray(body.data) ? body.data : [];
            const domainNames = new Map(domains.map((domain) => [domain.id, domain.label.split(' | ')[0]]));
            rows.forEach((row) => { row.domain = domainNames.get(String(row.domain_id)) ?? row.domain ?? ''; });
            renderRows(tbody, rows);
            holder.classList.remove('d-none');
            configIpsNote.classList.remove('d-none');
            configIpsButton.disabled = rows.length === 0;
            installButton.disabled = rows.length === 0;
            serverSelect.disabled = true;
            domainSelect.disabled = true;
            withIpv6.disabled = true;
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Could not load server IPs.');
        } finally {
            loadButton.disabled = false;
            loadButton.textContent = 'LOAD IPS';
        }
    });

    tbody.addEventListener('click', (event) => {
        const remove = event.target.closest('.single-install-remove');
        if (!remove) return;
        if (window.confirm('Are you sure you want to remove this row?')) {
            remove.closest('[data-install-ip-row]')?.remove();
            installButton.disabled = tbody.querySelectorAll('[data-install-ip-row]').length === 0;
        }
    });

    tbody.addEventListener('change', (event) => {
        if (!event.target.matches('[data-install-main]') || !event.target.checked) return;
        tbody.querySelectorAll('[data-install-main]').forEach((checkbox) => {
            if (checkbox !== event.target) checkbox.checked = false;
        });
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const rows = readRows(tbody);
        if (installButton.disabled || rows.length === 0) return;

        const payload = buildSingleInstallPayload({
            serverId: serverSelect.value,
            rows,
            key: Date.now(),
            pmtaVersion: form.querySelector('#pmta_version').value,
            switches: Object.fromEntries(optionNames.map((name) => [name, form.querySelector(`#${name}`).checked])),
        });
        const logUrl = form.dataset.logUrl.replace('__token__', encodeURIComponent(String(payload.key)));
        const readLogs = async (url = logUrl) => {
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                if (response.status === 401 || response.status === 419) {
                    window.location.reload();
                    return { status: 'interrupted' };
                }
                if (!response.ok) return null;
                const body = await response.json();
                logOutput.textContent = typeof body === 'string' ? body : String(body.data ?? '');
                return typeof body === 'object' && body !== null ? body : { status: 'running' };
            } catch {
                // Keep the most recent log visible through transient request failures.
                return null;
            }
        };
        const waitForRun = async (url) => {
            while (true) {
                const state = await readLogs(url);
                if (state?.status === 'succeeded' || state?.status === 'failed') return state;
                if (state?.status === 'interrupted') return state;
                await new Promise((resolve) => window.setTimeout(resolve, 1000));
            }
        };

        setError('');
        logPanel.classList.remove('d-none');
        logOutput.textContent = 'Preparing installation…';
        installButton.disabled = true;
        installButton.textContent = 'INSTALLING…';
        loadButton.disabled = true;

        try {
            const response = await fetch(form.dataset.installUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify(payload),
            });
            const body = await readJson(response);
            if (response.status === 401 || response.status === 419) {
                window.location.reload();
                return;
            }
            if (!response.ok) {
                const messages = [body.message, ...(body.blockers ?? []), ...(body.servers ?? []).flatMap((entry) => entry.blockers ?? []), ...Object.values(body.errors ?? {}).flat()]
                    .filter((message) => typeof message === 'string' && message !== '');
                throw new Error(messages.join('\n') || 'Installation could not start.');
            }

            const runLogUrl = body.run_id
                ? form.dataset.logUrl.replace('__token__', encodeURIComponent(String(body.run_id)))
                : logUrl;
            logOutput.textContent = body.data || 'Installation queued. Waiting for live logs…';
            const state = await waitForRun(runLogUrl);
            if (state.status === 'interrupted') return;
            if (state.status === 'failed') {
                installButton.disabled = false;
                installButton.textContent = 'INSTALL';
                loadButton.disabled = false;
                return;
            }
            installButton.textContent = 'DONE';
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Installation could not start.');
            installButton.disabled = false;
            installButton.textContent = 'INSTALL';
            loadButton.disabled = false;
        }
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeSingleInstallPage, { once: true });
    } else {
        initializeSingleInstallPage();
    }
}
