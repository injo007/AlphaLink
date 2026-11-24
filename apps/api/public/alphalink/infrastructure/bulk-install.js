const optionNames = ['tracking', 'proxy', 'imap', 'spf', 'dkim', 'dmarc', 'https', 'clean_dns', 'multi_domain_per_ip'];

/** Build the source-compatible request after blank domain rows are removed. */
export function buildBulkInstallPayload({ rows, key, pmtaVersion, switches, domainsPerIp }) {
    const multiDomainPerIp = Boolean(switches.multi_domain_per_ip);
    const data = rows
        .filter((row) => {
            const domain = String(row.domain ?? '').trim();
            return domain !== '' && domain !== '0';
        })
        .map((row) => ({
            name: row.name,
            id: row.id,
            ip: row.ip,
            domain: row.ip_assignments?.find((assignment) => assignment.main)?.domain_id ?? row.domain,
            check: Boolean(row.check),
            ...(multiDomainPerIp && Array.isArray(row.ip_assignments) ? {
                ip_assignments: row.ip_assignments.map((assignment) => ({
                    ip: String(assignment.ip ?? ''),
                    type: String(assignment.type ?? '').toLowerCase(),
                    main: Boolean(assignment.main),
                    sub: String(assignment.sub ?? ''),
                    domain_id: String(assignment.domain_id ?? ''),
                    domains: (Array.isArray(assignment.domains) ? assignment.domains : []).map((domain) => ({
                        domain_id: String(domain.domain_id ?? ''),
                        sub: String(domain.sub ?? ''),
                    })),
                })),
            } : {}),
        }));

    return {
        data,
        key: Number(key),
        pmta_version: Number(pmtaVersion),
        ...Object.fromEntries(optionNames.map((name) => [name, switches[name] ? 1 : 0])),
        multi_domain_per_ip: multiDomainPerIp ? 1 : 0,
        ...(multiDomainPerIp ? {
            domains_per_ip: Number(domainsPerIp)
                || ((data[0]?.ip_assignments?.[0]?.domains?.length ?? 0) + 1),
        } : {}),
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
    const ipAssignments = readIpAssignments(row);

    return {
        name: row.dataset?.serverName ?? values[0]?.value ?? '',
        id: row.dataset?.serverId ?? values[1]?.value ?? '',
        ip: row.dataset?.serverIp ?? values[2]?.value ?? '',
        domain: ipAssignments.find((assignment) => assignment.main)?.domain_id ?? row.querySelector('[data-domain-select]')?.value ?? '',
        check: row.querySelector('[data-apply-domain]')?.checked ?? false,
        ip_assignments: ipAssignments,
    };
}

function readIpAssignments(serverRow) {
    const panel = serverRow.nextElementSibling;
    if (!panel?.matches('[data-ip-assignments-panel]')) return [];

    return Array.from(panel.querySelectorAll('[data-ip-assignment]'), (row) => ({
        ip: row.dataset.ip ?? '',
        type: row.dataset.type ?? '',
        main: row.querySelector('[data-ip-main]')?.checked ?? false,
        sub: row.querySelector('[data-ip-sub]')?.value ?? '',
        domain_id: row.querySelector('[data-ip-domain]')?.value ?? '',
        domains: Array.from(row.querySelectorAll('[data-extra-domain]'), (select, index) => ({
            domain_id: select.value,
            sub: row.querySelectorAll('[data-extra-sub]')[index]?.value ?? '',
        })),
    }));
}

function renderIpAssignments(serverRow, assignments, domainOptions, domainCount, enabled) {
    let panel = serverRow.nextElementSibling;
    if (!panel?.matches('[data-ip-assignments-panel]')) {
        panel = document.createElement('tr');
        panel.dataset.ipAssignmentsPanel = '';
        panel.innerHTML = '<td colspan="6"><div class="bulk-install-ip-assignment-panel"></div></td>';
        serverRow.after(panel);
    }
    panel.hidden = !enabled;
    if (!enabled) return;
    const host = panel.querySelector('.bulk-install-ip-assignment-panel');
    host.replaceChildren();

    const heading = document.createElement('p');
    heading.className = 'small mb-2';
    heading.textContent = 'Assign a sending hostname to each physical IP. Additional domains use separate DKIM identities and HTTPS tracking hosts.';
    host.append(heading);
    const table = document.createElement('table');
    table.className = 'table table-sm table-bordered mb-0';
    table.innerHTML = '<thead><tr><th>IP</th><th>Type</th><th>Main</th><th>Subdomain</th><th>Primary domain</th><th>Additional domains and subdomains</th></tr></thead>';
    const body = document.createElement('tbody');

    for (const assignment of assignments) {
        const row = document.createElement('tr');
        row.dataset.ipAssignment = '';
        row.dataset.ip = String(assignment.ip ?? '');
        row.dataset.type = String(assignment.type ?? '');
        const ipCell = document.createElement('td'); ipCell.textContent = row.dataset.ip;
        const typeCell = document.createElement('td'); typeCell.textContent = row.dataset.type.toUpperCase();
        const mainCell = document.createElement('td');
        const main = document.createElement('input'); main.type = 'checkbox'; main.checked = Boolean(assignment.main); main.dataset.ipMain = '';
        main.setAttribute('aria-label', `Main IP ${row.dataset.ip}`); mainCell.append(main);
        const subCell = document.createElement('td');
        const sub = document.createElement('input'); sub.type = 'text'; sub.className = 'form-control form-control-sm'; sub.maxLength = 63;
        sub.value = String(assignment.sub ?? ''); sub.dataset.ipSub = ''; sub.setAttribute('aria-label', `Subdomain for ${row.dataset.ip}`); subCell.append(sub);
        const domainCell = document.createElement('td');
        const primary = document.createElement('select'); primary.className = 'form-control form-control-sm'; primary.required = true; primary.dataset.ipDomain = '';
        for (const optionData of domainOptions) {
            const option = document.createElement('option'); option.value = optionData.id; option.textContent = optionData.label; primary.append(option);
        }
        primary.value = String(assignment.domain_id ?? ''); domainCell.append(primary);
        const extraCell = document.createElement('td');
        const extras = Array.isArray(assignment.domains) ? assignment.domains : [];
        for (let index = 0; index < Math.max(1, domainCount - 1); index += 1) {
            const current = extras[index] ?? { domain_id: '', sub: '' };
            const group = document.createElement('div'); group.className = 'd-flex align-items-center mb-1'; group.style.gap = '6px';
            const extraSub = document.createElement('input'); extraSub.type = 'text'; extraSub.className = 'form-control form-control-sm';
            extraSub.maxLength = 63; extraSub.placeholder = 'Subdomain (optional)'; extraSub.value = String(current.sub ?? ''); extraSub.dataset.extraSub = '';
            const extraDomain = document.createElement('select'); extraDomain.className = 'form-control form-control-sm'; extraDomain.required = true; extraDomain.dataset.extraDomain = '';
            const placeholder = document.createElement('option'); placeholder.value = ''; placeholder.textContent = 'Select domain…'; extraDomain.append(placeholder);
            for (const optionData of domainOptions) {
                const option = document.createElement('option'); option.value = optionData.id; option.textContent = optionData.label; extraDomain.append(option);
            }
            extraDomain.value = String(current.domain_id ?? '');
            group.append(extraSub, extraDomain); extraCell.append(group);
        }
        row.append(ipCell, typeCell, mainCell, subCell, domainCell, extraCell);
        body.append(row);
    }

    table.append(body); host.append(table);
}

function initializeBulkInstallPage() {
    const rows = () => Array.from(document.querySelectorAll('[data-server-row]'));
    const multiDomainToggle = document.querySelector('#multi_domain_per_ip');
    const domainCountInput = document.querySelector('#bulk-domain-count');
    const multiDomainHelp = document.querySelector('#bulk-multi-domain-help');
    const installButton = document.querySelector('#install');
    const logContainer = document.querySelector('#container_log_result');
    const logResult = document.querySelector('#log_result');
    const domainsForRow = (row) => Array.from(row.querySelector('[data-domain-select]')?.options ?? [])
        .filter((option) => option.value !== '')
        .map((option) => ({ id: option.value, label: option.textContent ?? '' }));
    const redrawAssignments = () => {
        rows().forEach((row) => {
            const currentAssignments = readIpAssignments(row);
            if (currentAssignments.length > 0) {
                renderIpAssignments(row, currentAssignments, domainsForRow(row), Number(domainCountInput.value) || 2, multiDomainToggle.checked);
            }
        });
    };
    const syncMultiDomainControls = () => {
        const enabled = multiDomainToggle.checked;
        domainCountInput.closest?.('.command-form-group')?.classList.toggle('d-none', !enabled);
        multiDomainHelp?.classList?.toggle('d-none', !enabled);
        for (const option of ['tracking', 'https', 'spf', 'dkim', 'dmarc']) {
            const input = document.querySelector(`#${option}`);
            if (enabled) input.checked = true;
            input.disabled = enabled;
        }
        const cleanDns = document.querySelector('#clean_dns');
        if (enabled) cleanDns.checked = false;
        cleanDns.disabled = enabled;
        rows().forEach((row) => {
            row.querySelector('[data-configure-ip-assignments]')?.classList?.toggle('d-none', !enabled);
            const panel = row.nextElementSibling;
            if (panel?.matches('[data-ip-assignments-panel]')) {
                const currentAssignments = readIpAssignments(row);
                renderIpAssignments(row, currentAssignments, domainsForRow(row), Number(domainCountInput.value) || 2, enabled);
            }
        });
    };

    multiDomainToggle?.addEventListener?.('change', syncMultiDomainControls);
    domainCountInput?.addEventListener?.('change', () => {
        domainCountInput.value = String(Math.max(2, Math.min(33, Number(domainCountInput.value) || 2)));
        redrawAssignments();
    });
    syncMultiDomainControls();

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
            domainsPerIp: Number(domainCountInput.value) || 2,
            switches: Object.fromEntries(optionNames.map((name) => [
                name,
                document.querySelector(`#${name}`)?.checked ?? false,
            ])),
        }),
    };

    document.addEventListener('click', async (event) => {
        const configure = event.target.closest('[data-configure-ip-assignments]');
        if (!configure || !multiDomainToggle.checked) return;
        const serverRow = configure.closest('[data-server-row]');
        const domainId = serverRow?.querySelector('[data-domain-select]')?.value ?? '';
        if (!serverRow || domainId === '') {
            if (logResult) logResult.textContent = 'Select a primary domain before loading this server’s IP assignments.';
            logContainer?.classList.remove('d-none');
            return;
        }
        configure.disabled = true;
        configure.textContent = 'LOADING IPS…';
        try {
            const response = await fetch(document.querySelector('#servers').dataset.loadIpsUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ server_id: serverRow.dataset.serverId, domain_ids: [domainId], with_ipv6: 1 }),
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(formatBulkInstallError(body));
            const domainOptions = domainsForRow(serverRow);
            const assignments = (Array.isArray(body.data) ? body.data : []).map((assignment) => ({ ...assignment, domains: [] }));
            renderIpAssignments(serverRow, assignments, domainOptions, Number(domainCountInput.value) || 2, true);
            configure.textContent = 'REFRESH IP ASSIGNMENTS';
        } catch (error) {
            if (logResult) logResult.textContent = error instanceof Error ? error.message : 'Could not load IP assignments.';
            logContainer?.classList.remove('d-none');
        } finally {
            configure.disabled = false;
        }
    });

    installButton?.addEventListener('click', async () => {
        if (installButton.disabled) return;

        if (multiDomainToggle.checked) {
            const requiredAdditionalCount = Math.max(1, (Number(domainCountInput.value) || 2) - 1);
            for (const row of rows()) {
                const assignments = readIpAssignments(row);
                if (assignments.length === 0
                    || assignments.filter((assignment) => assignment.main).length !== 1
                    || assignments.some((assignment) => assignment.domain_id === ''
                        || assignment.domains.length !== requiredAdditionalCount
                        || assignment.domains.some((domain) => domain.domain_id === ''))) {
                    if (logResult) logResult.textContent = `Load IP assignments for every server, choose exactly one main IP, and assign ${requiredAdditionalCount} additional domain${requiredAdditionalCount === 1 ? '' : 's'} to every IP.`;
                    logContainer?.classList.remove('d-none');
                    return;
                }
                if (assignments.length * (requiredAdditionalCount + 1) > 100) {
                    if (logResult) logResult.textContent = 'Each server can have at most 100 HTTPS tracking hostnames on its current certificate. Reduce the IP or domain count.';
                    logContainer?.classList.remove('d-none');
                    return;
                }
            }
        }

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
