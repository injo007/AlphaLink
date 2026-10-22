const optionNames = ['tracking', 'proxy', 'imap', 'spf', 'dkim', 'dmarc', 'https'];

/** Build the same form payload DMS sends after AppendGrid removes empty rows. */
export function buildDmsBulkInstallPayload({ rows, key, pmtaVersion, switches }) {
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

export function dmsApplyDomainIndex(selectedIndex, selectedText) {
    if (selectedIndex < 0 || /IS OUR:\s*YES/i.test(selectedText)) return null;
    return selectedIndex;
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
        const selectedIndex = dmsApplyDomainIndex(source.selectedIndex, selectedText);
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

    window.DmsBulkInstall = {
        buildPayload: () => buildDmsBulkInstallPayload({
            rows: rows().map(readRow),
            key: Date.now(),
            pmtaVersion: document.querySelector('#pmta_version')?.value ?? 40,
            switches: Object.fromEntries(optionNames.map((name) => [
                name,
                document.querySelector(`#${name}`)?.checked ?? false,
            ])),
        }),
    };
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeBulkInstallPage, { once: true });
    } else {
        initializeBulkInstallPage();
    }
}
