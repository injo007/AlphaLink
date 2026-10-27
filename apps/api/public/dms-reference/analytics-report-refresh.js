(() => {
    const form = document.querySelector('[data-analytics-report-refresh]');
    const resultsSelector = '#analytics-report-results';

    if (!form || typeof window.fetch !== 'function') {
        return;
    }

    let results = document.querySelector(resultsSelector);
    const status = document.querySelector('[data-analytics-report-status]');
    let activeRequest = null;
    let requestVersion = 0;

    if (!results || !status) {
        return;
    }

    const cleanUrl = (value) => {
        const url = new URL(value, window.location.href);
        url.searchParams.delete('fragment');
        return url;
    };

    const urlFromForm = () => {
        const url = new URL(form.action, window.location.href);
        const params = new URLSearchParams();
        for (const [name, value] of new FormData(form).entries()) {
            if (String(value) !== '') {
                params.set(name, String(value));
            }
        }
        url.search = params.toString();
        return url;
    };

    const updateDatePicker = () => {
        const dateInput = form.querySelector('#analytics-dates');
        const from = form.elements.namedItem('from')?.value;
        const to = form.elements.namedItem('to')?.value;
        if (!dateInput || !from || !to) {
            return;
        }

        dateInput.value = `${from} - ${to}`;
        const picker = window.jQuery?.(dateInput).data('daterangepicker');
        if (picker && typeof window.moment === 'function') {
            picker.setStartDate(window.moment(from, 'YYYY-MM-DD', true));
            picker.setEndDate(window.moment(to, 'YYYY-MM-DD', true));
        }
    };

    const syncFormFromUrl = (url) => {
        const today = typeof window.moment === 'function'
            ? window.moment().format('YYYY-MM-DD')
            : new Date().toISOString().slice(0, 10);

        for (const element of form.elements) {
            if (!element.name) {
                continue;
            }

            if (element.name === 'from' || element.name === 'to') {
                element.value = url.searchParams.get(element.name) || today;
            } else if (element instanceof HTMLSelectElement) {
                element.value = url.searchParams.get(element.name) || element.options[0]?.value || '';
            }
        }
        updateDatePicker();
    };

    const setStatus = (message, tone = 'muted', retryUrl = null) => {
        status.replaceChildren(document.createTextNode(message));
        status.className = tone === 'danger' ? 'alert alert-danger mb-2' : 'small text-muted mb-2';

        if (retryUrl) {
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'btn btn-sm btn-alt-danger ml-2';
            retry.textContent = 'Retry';
            retry.addEventListener('click', () => refresh(retryUrl, { push: false }));
            status.append(' ', retry);
        }
    };

    const refresh = async (target, { push = false, focusHref = null } = {}) => {
        const displayUrl = cleanUrl(target);
        const requestUrl = new URL(displayUrl);
        requestUrl.searchParams.set('fragment', '1');
        const version = ++requestVersion;

        activeRequest?.abort();
        activeRequest = new AbortController();
        results.setAttribute('aria-busy', 'true');
        setStatus('Updating report…');

        try {
            const response = await window.fetch(requestUrl, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: activeRequest.signal,
            });
            if (response.status === 401 || response.redirected) {
                results.replaceChildren();
                results.setAttribute('aria-busy', 'false');
                setStatus('Your session may have expired. Sign in again before viewing this report.', 'danger');
                return;
            }
            if (response.status === 403 || response.status === 404) {
                results.replaceChildren();
                results.setAttribute('aria-busy', 'false');
                setStatus('You no longer have access to this report. Reload the page or ask a workspace owner.', 'danger');
                return;
            }
            if (!response.ok) {
                throw new Error('The report could not be refreshed. Your current results are still shown.');
            }

            const markup = await response.text();
            const parsed = new DOMParser().parseFromString(markup, 'text/html');
            const replacement = parsed.querySelector(resultsSelector);
            if (!replacement) {
                throw new Error('The report response was incomplete. Refresh the page and try again.');
            }
            if (version !== requestVersion) {
                return;
            }

            results.replaceWith(replacement);
            results = replacement;
            results.setAttribute('aria-busy', 'false');

            if (push && displayUrl.href !== cleanUrl(window.location.href).href) {
                window.history.pushState({}, '', displayUrl);
            }
            setStatus('Report updated.');
            document.dispatchEvent(new CustomEvent('analytics-report-fragment-updated', {
                detail: { url: displayUrl.href },
            }));

            if (focusHref) {
                const wanted = cleanUrl(focusHref).href;
                const nextFocus = Array.from(results.querySelectorAll('a[href]'))
                    .find((link) => cleanUrl(link.href).href === wanted);
                if (nextFocus) {
                    nextFocus.focus();
                } else {
                    results.tabIndex = -1;
                    results.focus();
                }
            }
        } catch (error) {
            if (error?.name === 'AbortError' || version !== requestVersion) {
                return;
            }

            results.setAttribute('aria-busy', 'false');
            setStatus(error instanceof Error ? error.message : 'The report could not be refreshed.', 'danger', displayUrl.href);
        }
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const url = urlFromForm();
        url.searchParams.delete('page');
        refresh(url.href, { push: true });
    });

    results.parentElement.addEventListener('click', (event) => {
        const targetElement = event.target instanceof Element ? event.target : null;
        const link = targetElement?.closest('a[href]');
        if (!link || !results.contains(link) || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const target = cleanUrl(link.href);
        const action = cleanUrl(form.action);
        if (target.origin !== window.location.origin || target.pathname !== action.pathname) {
            return;
        }

        event.preventDefault();
        refresh(target.href, { push: true, focusHref: target.href });
    });

    document.addEventListener('click', (event) => {
        const targetElement = event.target instanceof Element ? event.target : null;
        const reload = targetElement?.closest('[data-analytics-report-reload]');
        if (reload) {
            event.preventDefault();
            refresh(urlFromForm().href);
            return;
        }

        const reset = targetElement?.closest('[data-clear-analytics-date-range]');
        if (!reset || reset.dataset.clearAnalyticsDateRange !== form.dataset.storageKey) {
            return;
        }

        event.preventDefault();
        const storageKey = form.dataset.storageKey;
        try {
            window.sessionStorage.removeItem(`${storageKey}_date_from`);
            window.sessionStorage.removeItem(`${storageKey}_date_to`);
        } catch {
            // The reset remains available when browser storage is disabled.
        }

        for (const element of form.elements) {
            if (element instanceof HTMLSelectElement) {
                element.selectedIndex = 0;
            }
        }
        const today = typeof window.moment === 'function'
            ? window.moment().format('YYYY-MM-DD')
            : new Date().toISOString().slice(0, 10);
        form.elements.namedItem('from').value = today;
        form.elements.namedItem('to').value = today;
        updateDatePicker();
        refresh(urlFromForm().href, { push: true });
    });

    window.addEventListener('popstate', () => {
        const url = cleanUrl(window.location.href);
        syncFormFromUrl(url);
        refresh(url.href);
    });

    window.setInterval(() => {
        refresh(urlFromForm().href);
    }, 15 * 60 * 1000);
})();
