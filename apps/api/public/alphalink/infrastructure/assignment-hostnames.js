/** Return the first repeated effective hostname within one physical-IP row. */
export function findDuplicateSendingHostname(assignments, domainNameForId) {
    const seen = new Set();
    for (const assignment of assignments) {
        const domain = String(domainNameForId(String(assignment.domain_id ?? '')) ?? '')
            .trim()
            .toLowerCase()
            .replace(/\.$/, '');
        if (domain === '') continue;
        const sub = String(assignment.sub ?? '').trim().toLowerCase();
        const hostname = `${sub === '' ? '' : `${sub}.`}${domain}`;
        if (seen.has(hostname)) return hostname;
        seen.add(hostname);
    }

    return null;
}
