const optionNames = ['tracking', 'proxy', 'imap', 'spf', 'dkim', 'dmarc', 'https'];

/** Build the source-compatible request after blank domain rows are removed. */
export function buildBulkInstallPayload({ rows, key, pmtaVersion, switches }) {
    const data = rows
        .filter((row) => {
            const domain = String(row.domain ?? '').trim();
            return domain !== '' && domain !== '0';
        })
        .map((row) => ({
            name: row.name,
            id: row.id,
            ip: row.ip,
            domain: row.domain,
            check: Boolean(row.check),
        }));

    return {
        data,
        key: Number(key),
        pmta_version: Number(pmtaVersion),
        ...Object.fromEntries(optionNames.map((name) => [name, switches[name] ? 1 : 0])),
    };
}

export function applyDomainIndex(selectedIndex, selectedText) {
    if (selectedIndex < 0 || /IS OUR:\s*YES/i.test(selectedText)) return null;
    return selectedIndex;
}

export function formatBulkInstallError(result) {
    const message = typeof result.message === 'string' ? result.message : 'Bulk installation request failed.';
    const blockers = Array.isArray(result.blockers) ? result.blockers : [];
    const serverBlockers = Array.isArray(result.servers)
        ? result.servers.flatMap((server, index) => (Array.isArray(server.blockers)
            ? server.blockers.map((blocker) => `Server ${index + 1}: ${blocker}`)
            : []))
        : [];
    return [message, ...blockers, ...serverBlockers].join('\n');
}

function readRow(row) {
    const values = row.querySelectorAll('.bulk-install-row-value');

    return {
        name: values[0]?.value ?? '',
        id: values[1]?.value ?? '',
        ip: values[2]?.value ?? '',
        domain: row.querySelector('[data-domain-select]')?.value ?? '',
        check: row.querySelector('[data-apply-domain]')?.checked ?? false,
    };
}

function initializeBulkInstallPage() {
    const rows = () => Array.from(document.querySelectorAll('[data-server-row]'));

    document.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-row]');
        if (!remove) return;

        if (window.confirm('Are you sure you want to remove this row?')) {
            remove.closest('[data-server-row]')?.remove();
        }
    });

    document.addEventListener('click', (event) => {
        const apply = event.target.closest('[data-apply-domain]');
        if (!apply) return;

        event.preventDefault();
        const source = apply.closest('[data-server-row]')?.querySelector('[data-domain-select]');
        if (!source) return;

        const selectedText = source.options[source.selectedIndex]?.text ?? '';
        const selectedIndex = applyDomainIndex(source.selectedIndex, selectedText);
        if (selectedIndex === null && /IS OUR:\s*YES/i.test(selectedText)) {
            window.alert("You Can't Use Our Domain In Bulk Install");
            return;
        }
        if (selectedIndex === null) return;

        rows().forEach((candidate) => {
            const target = candidate.querySelector('[data-domain-select]');
            if (target) target.selectedIndex = selectedIndex;
        });
    });

    window.AlphaLinkBulkInstall = {
        buildPayload: () => buildBulkInstallPayload({
            rows: rows().map(readRow),
            key: Date.now(),
            pmtaVersion: document.querySelector('#pmta_version')?.value ?? 45,
            switches: Object.fromEntries(optionNames.map((name) => [
                name,
                document.querySelector(`#${name}`)?.checked ?? false,
            ])),
        }),
    };

    const installButton = document.querySelector('#install');
    const logContainer = document.querySelector('#container_log_result');
    const logResult = document.querySelector('#log_result');

    installButton?.addEventListener('click', async () => {
        if (installButton.disabled) return;

        rows().forEach((row) => {
            const domain = row.querySelector('[data-domain-select]')?.value ?? '';
            if (domain === '' || domain === '0') row.remove();
        });

        const payload = window.AlphaLinkBulkInstall.buildPayload();
        const token = encodeURIComponent(String(payload.key));
        const logUrl = installButton.dataset.logUrl.replace('__token__', token);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const refreshLogs = async (url = logUrl) => {
            try {
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });
                if (response.status === 401 || response.status === 419) {
                    window.location.reload();
                    return { status: 'interrupted' };
                }
                if (!response.ok) return null;
                const payload = await response.json();
                if (logResult) {
                    logResult.textContent = typeof payload === 'string'
                        ? payload
                        : String(payload.data ?? '');
                }
                return typeof payload === 'object' && payload !== null ? payload : { status: 'running' };
            } catch {
                // Keep the latest visible transcript during transient log-read failures.
                return null;
            }
        };
        const waitForRun = async (runLogUrl) => {
            while (true) {
                const state = await refreshLogs(runLogUrl);
                if (state?.status === 'succeeded' || state?.status === 'failed') return state;
                if (state?.status === 'interrupted') return state;
                await new Promise((resolve) => window.setTimeout(resolve, 1000));
            }
        };

        logContainer?.classList.remove('d-none');
        if (logResult) logResult.textContent = '';
        installButton.disabled = true;
        installButton.textContent = 'INSTALLING ...';

        try {
            const response = await fetch(installButton.dataset.installUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(payload),
            });
            if (response.status === 401 || response.status === 419) {
                window.location.reload();
                return;
            }
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(formatBulkInstallError(result));
            }
            const runLogUrl = result.run_id
                ? installButton.dataset.logUrl.replace('__token__', encodeURIComponent(String(result.run_id)))
                : logUrl;
            if (logResult) logResult.textContent = result.data || 'Installation queued. Waiting for live logs…';
            const state = await waitForRun(runLogUrl);
            if (state.status === 'interrupted') return;
            if (state.status === 'failed') {
                installButton.disabled = false;
                installButton.textContent = 'INSTALL';
                return;
            }
            if (window.App?.swalPromise) {
                await window.App.swalPromise({ icon: 'success', title: 'Installation complete', allowOutsideClick: false });
            }
            installButton.textContent = 'DONE';
        } catch (error) {
            if (logResult) {
                logResult.textContent = error instanceof Error ? error.message : 'Bulk installation request failed.';
            }
            installButton.disabled = false;
            installButton.textContent = 'INSTALL';
        }
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeBulkInstallPage, { once: true });
    } else {
        initializeBulkInstallPage();
    }
}
