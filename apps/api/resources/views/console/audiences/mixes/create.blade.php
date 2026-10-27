@extends('layouts.dms-admin')

@section('title', 'Create Mix - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">CREATE MIX</h3>
            <div class="block-options">
                <a class="btn btn-sm btn-alt-primary" href="{{ route('console.audience-mixes.history', $workspace->id) }}">MIX HISTORY</a>
            </div>
        </div>
        <div class="block-content block-content-full">
            <p class="text-muted">Build a reusable recipient snapshot from current list states and recorded engagement. The mix stores contact references and criteria; email addresses stay encrypted.</p>
            <form id="audience-mix-form" action="{{ route('console.audience-mixes.store', $workspace->id) }}" method="post" novalidate>
                @csrf
                <div id="mix-errors" class="alert alert-danger" role="alert" tabindex="-1" hidden></div>
                <div class="row">
                    <div class="col-sm-12 col-md-6">
                        <div class="form-group">
                            <label for="mix-name">Name</label>
                            <input id="mix-name" class="form-control" maxlength="100" autocomplete="off" placeholder="Leave blank to use an automatic name">
                        </div>
                    </div>
                    <div class="col-sm-12 col-md-3">
                        <div class="form-group">
                            <label for="mix-isp">Select ISP</label>
                            <select id="mix-isp" class="form-control" required>
                                <option value="">Select ISP...</option>
                                @foreach ($isps as $isp)
                                    <option value="{{ $isp->id }}" data-countries="{{ json_encode($isp->tracked_countries ?? []) }}" @selected((int) $selectedIspId === (int) $isp->id)>{{ $isp->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-sm-12 col-md-3">
                        <div class="form-group">
                            <label for="mix-country">Country</label>
                            <select id="mix-country" class="form-control" disabled>
                                <option value="">ALL</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <button id="load-mix-lists" type="button" class="btn btn-alt-primary" disabled>LOAD LISTS</button>
                    <span id="mix-list-status" class="ml-2 text-muted" role="status" aria-live="polite">Select an ISP to load its lists.</span>
                </div>
                <div id="mix-lists-wrap" class="table-responsive mb-4" hidden>
                    <table class="table table-sm table-bordered table-striped mb-0" id="mix-lists-table">
                        <thead>
                            <tr>
                                <th scope="col">LIST</th><th scope="col">COUNTRY</th>
                                <th scope="col">FRESH</th><th scope="col">DELIVERY</th><th scope="col">OPEN</th>
                                <th scope="col">CLICK</th><th scope="col">LEAD</th><th scope="col">UNSUBSCRIBE</th>
                            </tr>
                        </thead>
                        <tbody id="mix-lists-body"></tbody>
                    </table>
                </div>

                <div class="block block-rounded block-bordered">
                    <div class="block-header block-header-default"><h3 class="block-title">RECIPIENT CRITERIA</h3></div>
                    <div class="block-content">
                        <div class="row">
                            <div class="col-sm-12 col-md-3">
                                <div class="form-group">
                                    <label for="mix-sex">Email Gender</label>
                                    <select id="mix-sex" class="form-control">
                                        <option value="">ALL</option><option value="men">MEN</option><option value="women">WOMEN</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-sm-12 col-md-3">
                                <div class="form-group">
                                    <label for="mix-device">Device</label>
                                    <select id="mix-device" class="form-control"><option value="">ALL</option></select>
                                </div>
                            </div>
                            <div class="col-sm-12 col-md-3">
                                <div class="form-group">
                                    <label for="mix-insert-from">Last Insert From</label>
                                    <input id="mix-insert-from" type="date" class="form-control">
                                </div>
                            </div>
                            <div class="col-sm-12 col-md-3">
                                <div class="form-group">
                                    <label for="mix-insert-to">Last Insert To</label>
                                    <input id="mix-insert-to" type="date" class="form-control">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="mix-extensions">Email domain extensions</label>
                            <input id="mix-extensions" class="form-control" placeholder="gmail.com, example.org">
                            <small class="form-text text-muted">Optional; enter one or more domains separated by commas.</small>
                        </div>
                        <div class="row align-items-end">
                            <div class="col-sm-12 col-md-3">
                                <div class="form-group">
                                    <label for="mix-activity-from">Activity From</label>
                                    <input id="mix-activity-from" type="date" class="form-control">
                                </div>
                            </div>
                            <div class="col-sm-12 col-md-3">
                                <div class="form-group">
                                    <label for="mix-activity-to">Activity To</label>
                                    <input id="mix-activity-to" type="date" class="form-control">
                                </div>
                            </div>
                            <div class="col-sm-12 col-md-6">
                                <div class="form-group">
                                    <button id="add-mix-filter" type="button" class="btn btn-alt-primary">ADD ACTIVITY FILTER</button>
                                    <button id="clear-mix-filters" type="button" class="btn btn-alt-danger">CLEAR FILTERS</button>
                                </div>
                            </div>
                        </div>
                        <div id="mix-filters" class="mb-3"></div>
                        <small class="text-muted">Activity filters include or exclude contacts using recorded campaign, Drop, and conversion events. AlphaLink supports action, country, and device fields where the event source contains them.</small>
                    </div>
                </div>

                <div class="form-group d-flex flex-wrap align-items-center">
                    <button id="create-mix" type="submit" class="btn btn-info">MIX SELECTED LISTS</button>
                    <span id="mix-submit-status" class="ml-3 text-muted" role="status" aria-live="polite"></span>
                </div>
            </form>
        </div>
    </div>

    <script>
        (() => {
            const form = document.getElementById('audience-mix-form');
            const isp = document.getElementById('mix-isp');
            const country = document.getElementById('mix-country');
            const loadButton = document.getElementById('load-mix-lists');
            const listBody = document.getElementById('mix-lists-body');
            const listWrap = document.getElementById('mix-lists-wrap');
            const listStatus = document.getElementById('mix-list-status');
            const errors = document.getElementById('mix-errors');
            const submitButton = document.getElementById('create-mix');
            const submitStatus = document.getElementById('mix-submit-status');
            const filterRows = document.getElementById('mix-filters');
            const historyUrl = @json(route('console.audience-mixes.history', $workspace->id));
            const loadUrl = @json(route('console.audience-mixes.options', $workspace->id));
            const sponsorId = @json($sponsorId);
            let selectedCountry = @json($selectedCountry);
            let filterOptions = { actions: [], countries: [], devices: [], fields: [] };
            let filterIndex = 0;

            const setOptions = (select, values, selectedValue = '') => {
                const current = select.value || selectedValue;
                select.replaceChildren(new Option('Select...', ''));
                values.forEach((value) => select.add(new Option(value, value)));
                if (current && Array.from(select.options).some((option) => option.value === current)) select.value = current;
            };

            const showError = (message) => {
                errors.textContent = message;
                errors.hidden = !message;
                if (message) errors.focus?.();
            };

            const renderCountries = () => {
                const selected = isp.selectedOptions[0];
                const current = country.value || selectedCountry;
                let values = [];
                try { values = JSON.parse(selected?.dataset.countries || '[]'); } catch (_) { values = []; }
                country.replaceChildren(new Option('ALL', ''));
                values.forEach((value) => country.add(new Option(value, value)));
                if (current && values.includes(current)) country.value = current;
                selectedCountry = '';
                country.disabled = !selected?.value;
            };

            const renderLists = (lists) => {
                listBody.replaceChildren();
                if (!lists.length) {
                    listWrap.hidden = true;
                    listStatus.textContent = 'No active lists are available for this ISP and country.';
                    return;
                }
                const types = ['fresh', 'delivery', 'open', 'click', 'lead', 'unsubscribe'];
                lists.forEach((list) => {
                    const row = document.createElement('tr');
                    const nameCell = document.createElement('th');
                    nameCell.scope = 'row';
                    nameCell.textContent = list.name;
                    row.append(nameCell);
                    const countryCell = document.createElement('td');
                    countryCell.textContent = list.country || '—';
                    row.append(countryCell);
                    types.forEach((type) => {
                        const cell = document.createElement('td');
                        const count = Number(list.counts[type] || 0);
                        const locked = Boolean(list.locks[type]);
                        const label = document.createElement('label');
                        label.className = 'd-flex align-items-center mb-0';
                        const checkbox = document.createElement('input');
                        checkbox.type = 'checkbox';
                        checkbox.name = 'audience_segments[]';
                        checkbox.value = `${list.id}_${type}`;
                        checkbox.disabled = locked || count < 1;
                        checkbox.setAttribute('aria-label', `${list.name}: ${type}, ${count} recipients${locked ? ', locked' : ''}`);
                        const value = document.createElement('span');
                        value.className = 'ml-1';
                        value.textContent = locked ? `${count} (LOCKED)` : String(count);
                        label.append(checkbox, value);
                        cell.append(label);
                        row.append(cell);
                    });
                    listBody.append(row);
                });
                listWrap.hidden = false;
                listStatus.textContent = `${lists.length} list${lists.length === 1 ? '' : 's'} loaded. Select one or more unlocked email types.`;
            };

            const fieldValues = (field) => {
                if (field === 'country' || field === 'vertical') return (filterOptions[field] || []).map((value) => ({ value, label: value }));
                return (filterOptions.offers || []).map((offer) => ({
                    value: String(offer.value),
                    label: offer.group ? `${offer.group}: ${offer.label}` : offer.label,
                }));
            };

            const addFilter = () => {
                const row = document.createElement('div');
                row.className = 'row align-items-end border-bottom pb-2 mb-2';
                row.dataset.filterIndex = String(filterIndex++);
                row.innerHTML = `
                    <div class="col-sm-12 col-md-2"><label>To</label><select class="form-control filter-effect"><option value="include">INCLUDE</option><option value="exclude">EXCLUDE</option></select></div>
                    <div class="col-sm-12 col-md-2"><label>Select</label><select class="form-control filter-field"><option value="country">COUNTRY</option><option value="offer_id">OFFER</option><option value="vertical">VERTICAL</option></select></div>
                    <div class="col-sm-12 col-md-3"><label>From</label><select class="form-control filter-values" multiple size="3"></select></div>
                    <div class="col-sm-12 col-md-3"><label>Where (action)</label><select class="form-control filter-actions" multiple size="3"></select></div>
                    <div class="col-sm-12 col-md-2"><button type="button" class="btn btn-alt-danger remove-filter" aria-label="Remove activity filter">REMOVE</button></div>`;
                const field = row.querySelector('.filter-field');
                const values = row.querySelector('.filter-values');
                const actions = row.querySelector('.filter-actions');
                const updateValues = () => {
                    values.replaceChildren();
                    fieldValues(field.value).forEach((choice) => values.add(new Option(choice.label, choice.value)));
                    values.disabled = values.options.length === 0;
                };
                field.addEventListener('change', updateValues);
                filterOptions.actions.forEach((action) => actions.add(new Option(action, action)));
                row.querySelector('.remove-filter').addEventListener('click', () => row.remove());
                updateValues();
                filterRows.append(row);
            };

            const loadLists = async () => {
                showError('');
                if (!isp.value) return;
                loadButton.disabled = true;
                listStatus.textContent = 'Loading lists and event choices…';
                try {
                    const query = new URLSearchParams({ isp_id: isp.value });
                    if (sponsorId) query.set('sponsor_id', sponsorId);
                    if (country.value) query.set('country', country.value);
                    const response = await fetch(`${loadUrl}?${query}`, { headers: { Accept: 'application/json' } });
                    const payload = await response.json();
                    if (!response.ok) throw new Error(payload.message || 'Lists could not be loaded.');
                    filterOptions = payload.filters || filterOptions;
                    renderLists(payload.data || []);
                    setOptions(document.getElementById('mix-device'), filterOptions.devices || []);
                    filterRows.querySelectorAll('.filter-field').forEach((field) => field.dispatchEvent(new Event('change')));
                } catch (error) {
                    listWrap.hidden = true;
                    listStatus.textContent = 'List choices could not be refreshed.';
                    showError(error.message || 'Lists could not be loaded.');
                } finally {
                    loadButton.disabled = false;
                }
            };

            isp.addEventListener('change', () => {
                renderCountries();
                listWrap.hidden = true;
                loadButton.disabled = !isp.value;
                listStatus.textContent = isp.value ? 'Press LOAD LISTS to refresh this ISP.' : 'Select an ISP to load its lists.';
            });
            country.addEventListener('change', () => { listWrap.hidden = true; });
            loadButton.addEventListener('click', loadLists);
            document.getElementById('add-mix-filter').addEventListener('click', addFilter);
            document.getElementById('clear-mix-filters').addEventListener('click', () => filterRows.replaceChildren());

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                showError('');
                const selected = Array.from(form.querySelectorAll('input[name="audience_segments[]"]:checked')).map((input) => input.value);
                if (!isp.value || selected.length === 0) {
                    showError('Select an ISP and at least one unlocked list email type.');
                    return;
                }
                const dateRange = (fromId, toId) => {
                    const from = document.getElementById(fromId).value;
                    const to = document.getElementById(toId).value;
                    return from && to ? [from, to] : [];
                };
                const filters = Array.from(filterRows.querySelectorAll('[data-filter-index]')).map((row) => ({
                    effect: row.querySelector('.filter-effect').value,
                    field: row.querySelector('.filter-field').value,
                    values: Array.from(row.querySelector('.filter-values').selectedOptions).map((option) => option.value),
                    actions: Array.from(row.querySelector('.filter-actions').selectedOptions).map((option) => option.value),
                }));
                if (filters.some((filter) => filter.values.length === 0)) {
                    showError('Choose at least one value in every activity filter, or remove the empty filter.');
                    return;
                }
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || form.querySelector('input[name="_token"]').value;
                const payload = {
                    name: document.getElementById('mix-name').value,
                    isp_id: isp.value,
                    sponsor_id: sponsorId,
                    country: country.value,
                    audience_segments: selected,
                    email_sex: document.getElementById('mix-sex').value,
                    email_device: document.getElementById('mix-device').value,
                    last_insert_between: dateRange('mix-insert-from', 'mix-insert-to'),
                    activity_between: dateRange('mix-activity-from', 'mix-activity-to'),
                    extensions: document.getElementById('mix-extensions').value,
                    filters,
                };
                submitButton.disabled = true;
                submitStatus.textContent = 'MIXING…';
                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                        body: JSON.stringify(payload),
                    });
                    const result = await response.json();
                    if (!response.ok) {
                        const messages = Object.values(result.errors || {}).flat();
                        throw new Error(messages.join(' ') || result.data || result.message || 'Mix could not be created.');
                    }
                    submitStatus.textContent = `${result.data}.`;
                    const link = document.createElement('a');
                    link.href = historyUrl;
                    link.className = 'ml-2';
                    link.textContent = 'View mix history';
                    submitStatus.append(link);
                } catch (error) {
                    submitStatus.textContent = '';
                    showError(error.message || 'Mix could not be created.');
                } finally {
                    submitButton.disabled = false;
                }
            });

            if (isp.value) {
                renderCountries();
                loadButton.disabled = false;
                loadLists();
            }
        })();
    </script>
@endsection
