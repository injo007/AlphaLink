(() => {
    const form = document.querySelector('[data-analytics-date-range-form]');
    if (!form) {
        return;
    }

    const dateInput = form.querySelector('#analytics-dates');
    const fromInput = form.querySelector('[name="from"]');
    const toInput = form.querySelector('[name="to"]');
    const storageKey = form.dataset.storageKey;
    if (!dateInput || !fromInput || !toInput || !storageKey) {
        return;
    }

    const fromStorageKey = `${storageKey}_date_from`;
    const toStorageKey = `${storageKey}_date_to`;
    const getStored = (key) => {
        try {
            return window.sessionStorage.getItem(key);
        } catch {
            return null;
        }
    };
    const setStored = () => {
        try {
            window.sessionStorage.setItem(fromStorageKey, fromInput.value);
            window.sessionStorage.setItem(toStorageKey, toInput.value);
        } catch {
            // The report remains usable when browser storage is unavailable.
        }
    };
    const setVisibleRange = () => {
        dateInput.value = `${fromInput.value} - ${toInput.value}`;
    };
    const submitRange = () => {
        setStored();
        setVisibleRange();
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    };

    document.querySelectorAll('[data-clear-analytics-date-range]').forEach((link) => {
        link.addEventListener('click', () => {
            if (link.dataset.clearAnalyticsDateRange !== storageKey) {
                return;
            }

            try {
                window.sessionStorage.removeItem(fromStorageKey);
                window.sessionStorage.removeItem(toStorageKey);
            } catch {
                // The reset link still navigates to the default date range.
            }
        });
    });

    const hasRangeInUrl = new URLSearchParams(window.location.search).has('from')
        || new URLSearchParams(window.location.search).has('to');
    const storedFrom = getStored(fromStorageKey);
    const storedTo = getStored(toStorageKey);
    if (!hasRangeInUrl && storedFrom && storedTo
        && (storedFrom !== fromInput.value || storedTo !== toInput.value)) {
        fromInput.value = storedFrom;
        toInput.value = storedTo;
        if (storageKey !== 'all_stats') {
            submitRange();
        }
    } else if (!storedFrom || !storedTo) {
        setStored();
    }

    setVisibleRange();
    form.addEventListener('submit', setStored);

    if (typeof window.jQuery !== 'function'
        || typeof window.jQuery.fn?.daterangepicker !== 'function'
        || typeof window.moment !== 'function') {
        dateInput.readOnly = false;
        dateInput.setAttribute('aria-describedby', 'analytics-date-range-fallback');
        const help = document.createElement('small');
        help.id = 'analytics-date-range-fallback';
        help.className = 'form-text text-warning';
        help.textContent = 'The calendar did not load. Enter the range as YYYY-MM-DD - YYYY-MM-DD.';
        dateInput.insertAdjacentElement('afterend', help);
        dateInput.addEventListener('change', () => {
            const match = dateInput.value.match(/^(\d{4}-\d{2}-\d{2})\s+-\s+(\d{4}-\d{2}-\d{2})$/);
            if (!match) {
                return;
            }

            fromInput.value = match[1];
            toInput.value = match[2];
        });
        return;
    }

    const moment = window.moment;
    const startDate = moment(fromInput.value, 'YYYY-MM-DD', true);
    const endDate = moment(toInput.value, 'YYYY-MM-DD', true);
    const today = moment().startOf('day');
    const from = startDate.isValid() ? startDate : today;
    const to = endDate.isValid() ? endDate : today;
    const ranges = {
        Today: [moment(), moment()],
        Yesterday: [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
        'Today-Yesterday': [moment().subtract(1, 'days'), moment()],
        'Last 7 Days': [moment().subtract(6, 'days'), moment()],
        'This Month': [moment().startOf('month'), moment().endOf('month')],
        'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
    };

    window.jQuery(dateInput).daterangepicker({
        startDate: from,
        endDate: to,
        ...(form.dataset.minDate === 'none' ? {} : { minDate: moment().subtract(1, 'year') }),
        maxDate: moment(),
        opens: 'left',
        alwaysShowCalendars: true,
        ranges,
        locale: {
            format: 'YYYY-MM-DD',
            firstDay: 1,
        },
    }).on('apply.daterangepicker', (event, picker) => {
        fromInput.value = picker.startDate.format('YYYY-MM-DD');
        toInput.value = picker.endDate.format('YYYY-MM-DD');
        submitRange();
    });
})();
