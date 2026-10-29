(() => {
    'use strict';

    const root = document.getElementById('drops-suppression');
    if (!root || typeof window.jQuery !== 'function') return;

    const $ = window.jQuery;
    const sponsor = document.getElementById('drops-suppression-sponsor');
    const offer = document.getElementById('drops-suppression-offer');
    const isps = document.getElementById('drops-suppression-isps');
    const lists = document.getElementById('drops-suppression-lists');
    const form = document.getElementById('drops-suppression-form');
    const message = document.getElementById('drops-suppression-message');
    const dateFrom = document.getElementById('drops-suppression-from');
    const dateTo = document.getElementById('drops-suppression-to');
    const startButton = document.getElementById('drops-suppression-start');
    const fileStatus = document.getElementById('offer-suppression-file-status');
    const fileManagerLink = document.getElementById('offer-suppression-file-manage');
    const canManageFiles = root.dataset.canManageFiles === '1';
    const initialSponsorId = root.dataset.initialSponsorId;
    const initialOfferId = root.dataset.initialOfferId;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let table;
    let initialOfferPending = Boolean(initialSponsorId && initialOfferId);
    let sponsorRequest = 0;
    let listsRequest = 0;
    const monitored = new Set();

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    })[character]);

    const toast = (text, tone = 'info') => {
        if (window.AlphaLinkToast?.show) window.AlphaLinkToast.show(text, tone);
        else {
            message.textContent = text;
            message.className = `alert alert-${tone === 'danger' ? 'danger' : tone} py-2 mb-3`;
            message.hidden = false;
        }
    };

    const showError = (error) => {
        const firstValidation = error?.errors ? Object.values(error.errors).flat()[0] : null;
        toast(firstValidation || error?.data || error?.message || 'Unable to complete the suppression action.', 'danger');
    };

    const requestJson = async (url, options = {}) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                ...(options.headers || {}),
            },
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw payload;
        return payload;
    };

    const refreshPicker = (select) => {
        if ($.fn.selectpicker) $(select).selectpicker('refresh');
    };

    const setOptions = (select, options, placeholder, multiple = false) => {
        select.replaceChildren();
        if (!multiple) {
            const first = document.createElement('option');
            first.value = '';
            first.textContent = placeholder;
            select.append(first);
        }
        for (const item of options) {
            const option = document.createElement('option');
            option.value = String(item.id);
            option.textContent = item.label;
            for (const [key, value] of Object.entries(item.dataAttributes || {})) {
                option.dataset[key] = String(value ?? '');
            }
            select.append(option);
        }
        select.disabled = options.length === 0;
        refreshPicker(select);
    };

    const selectedValues = (select) => Array.from(select.selectedOptions).map((item) => item.value);

    const catalogUrl = (params = {}) => {
        const url = new URL(root.dataset.catalogUrl, window.location.origin);
        for (const [key, value] of Object.entries(params)) {
            if (Array.isArray(value)) value.forEach((entry) => url.searchParams.append(`${key}[]`, entry));
            else if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, value);
        }
        return url.toString();
    };

    const selectedOfferOption = () => offer.selectedOptions[0] || null;

    const updateStartButton = () => {
        const selectedIsps = selectedValues(isps);
        const selectedLists = selectedValues(lists);
        const eligible = selectedOfferOption()?.dataset.canRun === 'true';
        startButton.disabled = !sponsor.value || !offer.value || !eligible || selectedIsps.length === 0 || selectedLists.length === 0;
    };

    const updateFileState = () => {
        const selected = selectedOfferOption();
        if (!selected?.value) {
            fileStatus.textContent = 'Select an offer to check its saved suppression file.';
            if (fileManagerLink) {
                fileManagerLink.hidden = true;
                fileManagerLink.removeAttribute('href');
            }
            updateStartButton();
            return;
        }

        const hasFile = selected.dataset.hasFile === 'true';
        fileStatus.textContent = hasFile
            ? `Offer suppression file: ${String(selected.dataset.format || 'verified').toUpperCase()} · updated ${selected.dataset.updatedAt || 'date unavailable'}.`
            : 'This offer has no saved suppression file.';
        const manageTemplate = root.dataset.offerFileManageUrlTemplate;
        const canManage = canManageFiles && Boolean(manageTemplate);
        if (fileManagerLink) {
            fileManagerLink.hidden = !canManage;
            if (canManage) {
                fileManagerLink.href = manageTemplate.replace('__OFFER_ID__', encodeURIComponent(selected.value));
            } else {
                fileManagerLink.removeAttribute('href');
            }
        }
        updateStartButton();
    };

    const offerLabel = (item) => {
        const state = item.can_run_suppression ? 'READY' : (item.is_active ? 'NOT READY' : 'INACTIVE');
        const file = item.has_suppression_file ? 'FILE' : 'NO FILE';
        return `(${item.offer_id || ''}) ${item.name} - ${item.vertical || ''} - $${item.payout || '0'} · ${file} · ${state}`;
    };

    const loadLists = async () => {
        const requestId = ++listsRequest;
        const sponsorId = sponsor.value;
        const offerId = offer.value;
        const selectedIsps = selectedValues(isps);
        setOptions(lists, [], 'Select Lists', true);
        if (!sponsorId || !offerId || selectedOfferOption()?.dataset.canRun !== 'true' || selectedIsps.length === 0) {
            updateStartButton();
            return;
        }
        try {
            const data = await requestJson(catalogUrl({ sponsor_id: sponsorId, offer_id: offerId, isp_ids: selectedIsps }));
            if (requestId !== listsRequest || sponsor.value !== sponsorId || offer.value !== offerId
                || JSON.stringify(selectedValues(isps)) !== JSON.stringify(selectedIsps)) return;
            const options = (data.lists || []).map((item) => ({ id: item.id, label: `(${item.id}) - ${item.name} - (count: ${item.total})` }));
            setOptions(lists, options, 'Select Lists', true);
        } catch (error) {
            if (requestId === listsRequest) showError(error);
        } finally {
            if (requestId === listsRequest) updateStartButton();
        }
    };

    const loadSponsorOptions = async () => {
        const requestId = ++sponsorRequest;
        const sponsorId = sponsor.value;
        listsRequest += 1;
        setOptions(offer, [], 'Select Offer');
        setOptions(isps, [], 'Select ISPs', true);
        setOptions(lists, [], 'Select Lists', true);
        updateFileState();
        if (!sponsorId) {
            return;
        }
        try {
            const data = await requestJson(catalogUrl({ sponsor_id: sponsorId }));
            if (requestId !== sponsorRequest || sponsor.value !== sponsorId) return;
            const offerOptions = (data.offers || []).map((item) => ({
                id: item.id,
                label: offerLabel(item),
                dataAttributes: {
                    canRun: item.can_run_suppression,
                    hasFile: item.has_suppression_file,
                    format: item.suppression_format,
                    updatedAt: item.suppression_updated_at,
                    active: item.is_active,
                    offerId: item.offer_id,
                    name: item.name,
                    vertical: item.vertical,
                    payout: item.payout,
                },
            }));
            setOptions(offer, offerOptions, 'Select Offer');
            setOptions(isps, (data.isps || []).map((item) => ({ id: item.id, label: item.name })), 'Select ISPs', true);
            if (initialOfferPending && sponsor.value === initialSponsorId) {
                offer.value = initialOfferId;
                initialOfferPending = false;
                refreshPicker(offer);
            }
            updateFileState();
        } catch (error) {
            if (requestId === sponsorRequest) showError(error);
        } finally {
            if (requestId === sponsorRequest) updateStartButton();
        }
    };

    const terminal = (status) => /complete|error|killed/i.test(String(status || ''));

    const monitor = async (id) => {
        if (monitored.has(id)) return;
        monitored.add(id);
        const url = root.dataset.monitorUrlTemplate.replace('__SUPPRESSION_ID__', encodeURIComponent(id));
        window.setTimeout(async () => {
            try {
                const state = await requestJson(url);
                if (!terminal(state.status)) {
                    monitored.delete(id);
                    table?.ajax.reload(null, false);
                    monitor(id);
                } else {
                    monitored.delete(id);
                    table?.ajax.reload(null, false);
                }
            } catch (error) {
                monitored.delete(id);
                showError(error);
            }
        }, 10000);
    };

    const addBadge = (label, values) => {
        const section = document.createElement('p');
        const title = document.createElement('strong');
        title.textContent = `${label}: `;
        section.append(title, document.createTextNode(values.length ? values.join(', ') : 'NA'));
        return section;
    };

    const showScope = (row) => {
        const content = document.getElementById('drops-suppression-details-content');
        content.replaceChildren(
            addBadge('ISPs', row.isps || []),
            addBadge('Lists', row.lists || []),
        );
        const dialog = document.getElementById('drops-suppression-details');
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else toast(`ISPs: ${(row.isps || []).join(', ')}; Lists: ${(row.lists || []).join(', ')}`);
    };

    const kill = async (id, button) => {
        if (!window.confirm('Are you sure you want to stop this suppression run?')) return;
        button.disabled = true;
        try {
            const result = await requestJson(root.dataset.killUrl, {
                method: 'POST',
                body: JSON.stringify({ suppression_id: id }),
            });
            toast(result.data || 'Killed Successfully', 'success');
            table?.ajax.reload(null, false);
        } catch (error) {
            showError(error);
        } finally {
            button.disabled = false;
        }
    };

    sponsor.addEventListener('change', () => {
        void loadSponsorOptions();
    });
    isps.addEventListener('change', () => void loadLists());
    lists.addEventListener('change', updateStartButton);
    offer.addEventListener('change', () => {
        listsRequest += 1;
        setOptions(lists, [], 'Select Lists', true);
        updateFileState();
        void loadLists();
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!sponsor.value || !offer.value || selectedOfferOption()?.dataset.canRun !== 'true'
            || selectedValues(isps).length === 0 || selectedValues(lists).length === 0) {
            toast('Choose an eligible offer, at least one ISP, and at least one list before starting suppression.', 'warning');
            return;
        }
        const button = startButton;
        button.disabled = true;
        try {
            const result = await requestJson(root.dataset.startUrl, {
                method: 'POST',
                body: JSON.stringify({
                    sponsor: sponsor.value,
                    offer: offer.value,
                    isp: selectedValues(isps),
                    lists: selectedValues(lists),
                }),
            });
            if (result.started === false) {
                toast('Suppression is up to date for all selected lists.', 'info');
            } else {
                toast(result.data || 'Process Started', 'success');
                table?.ajax.reload(null, false);
                if (result.id) monitor(result.id);
            }
        } catch (error) {
            showError(error);
        } finally {
            button.disabled = false;
            updateStartButton();
        }
    });

    if (sponsor.value) void loadSponsorOptions();
    else updateFileState();
    window.addEventListener('pageshow', updateStartButton);

    document.getElementById('drops-suppression-refresh').addEventListener('click', () => table?.ajax.reload(null, false));
    dateFrom.addEventListener('change', () => table?.ajax.reload());
    dateTo.addEventListener('change', () => table?.ajax.reload());

    if ($.fn.DataTable) {
        table = $('#drops-suppression-table').DataTable({
            ajax: {
                url: root.dataset.rowsUrl,
                data: (params) => {
                    params.from = dateFrom.value || root.dataset.defaultFrom;
                    params.to = dateTo.value || root.dataset.today;
                },
                dataSrc: 'data',
            },
            displayLength: 50,
            order: [[0, 'desc']],
            columns: [
                { title: '#', data: 'id' },
                { title: 'User', data: 'user.username', render: (value) => escapeHtml(value || 'NA') },
                { title: 'Sponsor', data: 'sponsor.name', render: (value) => escapeHtml(value || 'NA') },
                { title: 'Offer', data: 'offer', render: (value) => escapeHtml(`(${value?.offer_id || ''}) - ${value?.name || ''}`) },
                { title: 'ISPs / Lists', data: null, orderable: false, render: () => '<button type="button" class="btn btn-link btn-sm js-suppression-scope">VIEW</button>' },
                { title: 'Status', data: 'status', render: (value) => escapeHtml(value) },
                { title: 'New found', data: 'found' },
                { title: 'Elapsed', data: 'elapsed', render: (value) => escapeHtml(value || 'NA') },
                { title: 'At', data: 'created_at', render: (value) => escapeHtml(value || 'NA') },
                { title: 'Actions', data: null, orderable: false, searchable: false, render: (_value, _type, row) => terminal(row.status) ? '' : `<button type="button" class="btn btn-sm btn-danger js-suppression-kill" data-id="${Number(row.id)}">KILL</button>` },
            ],
            rowCallback: (_row, row) => {
                if (!terminal(row.status)) monitor(row.id);
            },
        });

        $('#drops-suppression-table').on('click', '.js-suppression-scope', function () {
            showScope(table.row($(this).closest('tr')).data());
        });
        $('#drops-suppression-table').on('click', '.js-suppression-kill', function () {
            void kill(Number(this.dataset.id), this);
        });
    }
})();
