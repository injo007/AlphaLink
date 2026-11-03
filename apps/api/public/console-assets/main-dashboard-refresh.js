(() => {
    let region = document.querySelector('[data-main-dashboard-refresh]');
    if (!region) {
        return;
    }

    const interval = Number(region.dataset.refreshInterval);
    if (!Number.isFinite(interval) || interval <= 0) {
        return;
    }

    let requestPending = false;

    const refreshDashboard = async () => {
        if (requestPending) {
            return;
        }

        requestPending = true;
        try {
            const response = await fetch(region.dataset.refreshUrl, {
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                cache: 'no-store',
                credentials: 'same-origin',
            });

            if (response.redirected || response.status === 401 || response.status === 403) {
                const destination = new URL(response.url || window.location.href, window.location.href);
                window.location.assign(destination.origin === window.location.origin ? destination.href : window.location.href);
                return;
            }

            if (!response.ok) {
                return;
            }

            const refreshedDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
            const refreshedRegion = refreshedDocument.querySelector('[data-main-dashboard-refresh]');
            if (!refreshedRegion) {
                return;
            }

            const scrollX = window.scrollX;
            const scrollY = window.scrollY;
            const focusedElement = document.activeElement;
            const focusedHref = region.contains(focusedElement) && focusedElement.matches('a[href]')
                ? focusedElement.getAttribute('href')
                : null;
            const nextRegion = document.importNode(refreshedRegion, true);
            region.replaceWith(nextRegion);
            region = nextRegion;
            window.scrollTo(scrollX, scrollY);

            if (focusedHref !== null) {
                const restoredFocus = Array.from(region.querySelectorAll('a[href]'))
                    .find((link) => link.getAttribute('href') === focusedHref);
                restoredFocus?.focus({ preventScroll: true });
            }
        } catch {
            // Keep the last rendered snapshot and retry on the next dashboard interval.
        } finally {
            requestPending = false;
        }
    };

    window.setInterval(refreshDashboard, interval);
})();
