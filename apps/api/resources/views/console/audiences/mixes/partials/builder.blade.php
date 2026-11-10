    <div class="command-os-entity-page campaign-mix-builder">
        @unless ($mixBuilderInModal ?? false)
            <!-- 1. Hero Header Card -->
            <header class="command-os-report-hero mb-3" aria-labelledby="campaign-mix-hero-title">
                <span class="command-os-report-hero-illustration" aria-hidden="true">
                    <i class="fas fa-blender"></i>
                </span>
                <div class="command-os-report-hero-mark" aria-hidden="true">
                    <i class="fas fa-blender"></i>
                </div>
                <div class="command-os-report-hero-copy">
                    <p class="command-os-report-eyebrow">AUDIENCE INTELLIGENCE // MIX STUDIO</p>
                    <h1 id="campaign-mix-hero-title">CREATE AUDIENCE MIX</h1>
                    <p id="campaign-mix-description">Build a reusable recipient snapshot from current list states and recorded engagement. The mix stores contact references and criteria; email addresses stay encrypted.</p>
                </div>
                <div class="command-os-hero-actions">
                    <a class="btn-command-action mr-2" href="{{ route('clean.console.audience-mixes.history') }}">
                        <i class="fas fa-history mr-1"></i> MIX HISTORY
                    </a>
                    <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                        <i class="fas fa-expand"></i>
                    </button>
                </div>
            </header>
        @else
            <div class="d-flex align-items-center justify-content-between p-3 mb-3 rounded" style="background: rgba(139, 92, 246, 0.08); border: 1px solid rgba(139, 92, 246, 0.25);">
                <div class="d-flex align-items-center">
                    <i class="fas fa-blender text-purple mr-2" style="font-size: 1.25rem;"></i>
                    <h3 class="m-0 font-w700 font-size-base text-uppercase" style="letter-spacing: 0.04em;">Create Campaign Audience Mix</h3>
                </div>
                <span class="badge-command-pill"><i class="fas fa-clock mr-1 text-purple"></i> Available 24h</span>
            </div>
        @endunless

        <form id="audience-mix-form" action="{{ route('console.audience-mixes.store', $workspace->id) }}" method="post" novalidate data-mix-modal="{{ ($mixBuilderInModal ?? false) ? 'true' : 'false' }}" data-sponsor-id="{{ $sponsorId }}">
            @csrf
            <div id="mix-errors" class="alert alert-danger py-2 px-3 mb-3 font-size-sm" role="alert" tabindex="-1" hidden style="border-radius: 10px;"></div>

            <!-- Card 1: Base Configuration & ISP Selection -->
            <div class="command-os-table-card p-4 mb-3">
                <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                    <h3 style="font-size: 0.9375rem; font-weight: 700; color: var(--command-os-text, #1e293b); margin: 0; text-transform: uppercase; letter-spacing: 0.04em;">
                        <i class="fas fa-layer-group mr-2 text-purple"></i> 1. Base Configuration &amp; Source Selection
                    </h3>
                </div>

                <div class="row">
                    <div class="col-sm-12 col-md-6 mb-3">
                        <label for="mix-name" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                            Mix Name:
                        </label>
                        <input id="mix-name" class="form-control" maxlength="100" autocomplete="off" placeholder="Leave blank to use an automatic name" style="height: 42px; border-radius: 10px; font-size: 12.5px;">
                    </div>
                    <div class="col-sm-12 col-md-3 mb-3">
                        <label for="mix-isp" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                            <span class="text-danger mr-1">*</span>Select ISP:
                        </label>
                        <select id="mix-isp" class="form-control" required style="height: 42px; border-radius: 10px; font-size: 12.5px; font-weight: 600;">
                            <option value="">Select ISP...</option>
                            @foreach ($isps as $isp)
                                @php($trackedCountries = data_get($isp, 'tracked_countries', []) ?? [])
                                <option value="{{ data_get($isp, 'id') }}" data-countries="{{ json_encode($trackedCountries) }}" @selected((int) $selectedIspId === (int) data_get($isp, 'id'))>{{ data_get($isp, 'name') }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-12 col-md-3 mb-3">
                        <label for="mix-country" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                            Country:
                        </label>
                        <select id="mix-country" class="form-control" disabled style="height: 42px; border-radius: 10px; font-size: 12.5px;">
                            <option value="">ALL</option>
                        </select>
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center pt-2 border-top" style="gap: 12px;">
                    <button id="load-mix-lists" type="button" class="btn-command-primary" disabled>
                        <i class="fas fa-sync-alt mr-1" aria-hidden="true"></i> LOAD LISTS
                    </button>
                    <span id="mix-list-status" class="text-muted font-size-sm" role="status" aria-live="polite">
                        Select an ISP to load its lists.
                    </span>
                </div>

                <!-- Lists Table (Loaded Dynamically) -->
                <div id="mix-lists-wrap" class="table-responsive mt-3 pt-3 border-top" hidden>
                    <table class="table command-os-table table-sm table-hover mb-0" id="mix-lists-table">
                        <thead>
                            <tr>
                                <th scope="col">LIST</th>
                                <th scope="col">COUNTRY</th>
                                <th scope="col" class="text-center">FRESH</th>
                                <th scope="col" class="text-center">DELIVERY</th>
                                <th scope="col" class="text-center">OPEN</th>
                                <th scope="col" class="text-center">CLICK</th>
                                <th scope="col" class="text-center">LEAD</th>
                                <th scope="col" class="text-center">UNSUBSCRIBE</th>
                            </tr>
                        </thead>
                        <tbody id="mix-lists-body"></tbody>
                    </table>
                </div>
            </div>

            <!-- Card 2: Recipient Criteria -->
            <div class="command-os-table-card p-4 mb-3">
                <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                    <h3 style="font-size: 0.9375rem; font-weight: 700; color: var(--command-os-text, #1e293b); margin: 0; text-transform: uppercase; letter-spacing: 0.04em;">
                        <i class="fas fa-filter mr-2 text-purple"></i> 2. Recipient Criteria &amp; Filters
                    </h3>
                </div>

                <div class="row">
                    <div class="col-sm-12 col-md-3 mb-3">
                        <label for="mix-sex" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                            Email Gender:
                        </label>
                        <select id="mix-sex" class="form-control" style="height: 40px; border-radius: 10px; font-size: 12px;">
                            <option value="">ALL</option>
                            <option value="men">MEN</option>
                            <option value="women">WOMEN</option>
                        </select>
                    </div>
                    <div class="col-sm-12 col-md-3 mb-3">
                        <label for="mix-device" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                            Device:
                        </label>
                        <select id="mix-device" class="form-control" style="height: 40px; border-radius: 10px; font-size: 12px;">
                            <option value="">ALL</option>
                        </select>
                    </div>
                    <div class="col-sm-12 col-md-3 mb-3">
                        <label for="mix-insert-from" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                            Last Insert From:
                        </label>
                        <input id="mix-insert-from" type="date" class="form-control" style="height: 40px; border-radius: 10px; font-size: 12px;">
                    </div>
                    <div class="col-sm-12 col-md-3 mb-3">
                        <label for="mix-insert-to" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                            Last Insert To:
                        </label>
                        <input id="mix-insert-to" type="date" class="form-control" style="height: 40px; border-radius: 10px; font-size: 12px;">
                    </div>
                </div>

                <div class="mb-3">
                    <label for="mix-extensions" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                        Email Domain Extensions:
                    </label>
                    <input id="mix-extensions" class="form-control" placeholder="gmail.com, example.org" style="height: 40px; border-radius: 10px; font-size: 12px;">
                    <small class="form-text text-muted">Optional; enter one or more domains separated by commas.</small>
                </div>

                <div class="p-3 mb-3 rounded" style="background: rgba(139, 92, 246, 0.04); border: 1px solid rgba(139, 92, 246, 0.15);">
                    <div class="row align-items-end">
                        <div class="col-sm-12 col-md-3 mb-2 mb-md-0">
                            <label for="mix-activity-from" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                                Activity From:
                            </label>
                            <input id="mix-activity-from" type="date" class="form-control" style="height: 40px; border-radius: 10px; font-size: 12px;">
                        </div>
                        <div class="col-sm-12 col-md-3 mb-2 mb-md-0">
                            <label for="mix-activity-to" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                                Activity To:
                            </label>
                            <input id="mix-activity-to" type="date" class="form-control" style="height: 40px; border-radius: 10px; font-size: 12px;">
                        </div>
                        <div class="col-sm-12 col-md-6 d-flex align-items-center" style="gap: 10px;">
                            <button id="add-mix-filter" type="button" class="btn-command-action" style="height: 40px;">
                                <i class="fas fa-plus mr-1"></i> ADD ACTIVITY FILTER
                            </button>
                            <button id="clear-mix-filters" type="button" class="btn-command-danger" style="height: 40px;">
                                <i class="fas fa-trash-alt mr-1"></i> CLEAR FILTERS
                            </button>
                        </div>
                    </div>
                </div>

                <div id="mix-filters" class="mb-3"></div>
                <small class="text-muted d-block">Activity filters include or exclude contacts using recorded campaign, Drop, and conversion events. AlphaLink supports action, country, and device fields where the event source contains them.</small>
            </div>

            <!-- Card 3: Submit Action -->
            <div class="command-os-table-card p-4 d-flex flex-wrap align-items-center justify-content-between" style="gap: 16px;">
                <div class="d-flex align-items-center">
                    <span id="mix-submit-status" class="text-muted font-size-sm" role="status" aria-live="polite"></span>
                </div>
                <button id="create-mix" type="submit" class="btn-command-primary" style="padding: 11px 24px; font-size: 13px;">
                    <i class="fas fa-layer-group mr-2" aria-hidden="true"></i> CREATE MIX FROM SELECTED LISTS
                </button>
            </div>
        </form>
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
            const historyUrl = @json(route('clean.console.audience-mixes.history'));
            const loadUrl = @json(route('clean.console.audience-mixes.options'));
            let sponsorId = form.dataset.sponsorId;
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
                if (message) {
                    errors.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    errors.focus?.();
                }
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
                    nameCell.innerHTML = `<strong class="text-ink font-w600" style="font-size: 12.5px;">${list.name}</strong>`;
                    row.append(nameCell);
                    const countryCell = document.createElement('td');
                    countryCell.innerHTML = `<span class="badge-command-pill" style="font-size: 11px;">${list.country || 'GLOBAL'}</span>`;
                    row.append(countryCell);
                    types.forEach((type) => {
                        const cell = document.createElement('td');
                        cell.className = 'text-center';
                        const count = Number(list.counts[type] || 0);
                        const locked = Boolean(list.locks[type]);
                        const label = document.createElement('label');
                        label.className = 'd-inline-flex align-items-center mb-0';
                        label.style.cursor = locked || count < 1 ? 'not-allowed' : 'pointer';
                        const checkbox = document.createElement('input');
                        checkbox.type = 'checkbox';
                        checkbox.name = 'audience_segments[]';
                        checkbox.value = `${list.id}_${type}`;
                        checkbox.disabled = locked || count < 1;
                        checkbox.setAttribute('aria-label', `${list.name}: ${type}, ${count} recipients${locked ? ', locked' : ''}`);
                        const value = document.createElement('span');
                        value.className = 'ml-1 font-mono font-size-xs';
                        if (locked) {
                            value.innerHTML = `<span class="text-danger font-w600"><i class="fas fa-lock mr-1"></i>${count}</span>`;
                        } else if (count > 0) {
                            value.innerHTML = `<strong class="text-purple">${count.toLocaleString()}</strong>`;
                        } else {
                            value.innerHTML = `<span class="text-muted">0</span>`;
                        }
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
                row.className = 'p-3 mb-2 rounded border';
                row.style.background = 'rgba(139, 92, 246, 0.05)';
                row.style.borderColor = 'rgba(139, 92, 246, 0.2)';
                row.dataset.filterIndex = String(filterIndex++);
                row.innerHTML = `
                    <div class="row align-items-end" style="gap: 8px 0;">
                        <div class="col-sm-12 col-md-2 mb-2 mb-md-0">
                            <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">To</label>
                            <select class="form-control filter-effect" style="height: 38px; border-radius: 8px; font-size: 11.5px;">
                                <option value="include">INCLUDE</option>
                                <option value="exclude">EXCLUDE</option>
                            </select>
                        </div>
                        <div class="col-sm-12 col-md-2 mb-2 mb-md-0">
                            <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">Select</label>
                            <select class="form-control filter-field" style="height: 38px; border-radius: 8px; font-size: 11.5px;">
                                <option value="country">COUNTRY</option>
                                <option value="offer_id">OFFER</option>
                                <option value="vertical">VERTICAL</option>
                            </select>
                        </div>
                        <div class="col-sm-12 col-md-3 mb-2 mb-md-0">
                            <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">From</label>
                            <select class="form-control filter-values" multiple size="3" style="border-radius: 8px; font-size: 11.5px;"></select>
                        </div>
                        <div class="col-sm-12 col-md-3 mb-2 mb-md-0">
                            <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">Where (Action)</label>
                            <select class="form-control filter-actions" multiple size="3" style="border-radius: 8px; font-size: 11.5px;"></select>
                        </div>
                        <div class="col-sm-12 col-md-2 mb-2 mb-md-0">
                            <button type="button" class="btn-command-danger remove-filter w-100" style="height: 38px; font-size: 11px;" aria-label="Remove activity filter">
                                <i class="fas fa-times mr-1"></i> REMOVE
                            </button>
                        </div>
                    </div>`;
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
                loadButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> LOADING...';
                listStatus.textContent = 'Loading lists and event choices…';
                try {
                    const query = new URLSearchParams({ isp_id: isp.value });
                    sponsorId = form.dataset.sponsorId;
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
                    loadButton.innerHTML = '<i class="fas fa-sync-alt mr-1"></i> LOAD LISTS';
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
                    sponsor_id: form.dataset.sponsorId || null,
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
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> CREATING MIX...';
                submitStatus.textContent = 'Processing audience segments and compiling recipient criteria…';
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
                    if (form.dataset.mixModal === 'true') {
                        window.dispatchEvent(new CustomEvent('alphalink:mix-created', { detail: { isp_id: isp.value, country: country.value, id: result.id } }));
                        document.getElementById('campaign-mix-dialog')?.close();
                    } else {
                        submitStatus.innerHTML = `<span class="text-success font-w600"><i class="fas fa-check-circle mr-1"></i>${result.data}.</span> <a href="${historyUrl}" class="btn-command-action ml-2" style="padding: 4px 10px; font-size: 11px;">VIEW MIX HISTORY <i class="fas fa-arrow-right ml-1"></i></a>`;
                    }
                } catch (error) {
                    submitStatus.textContent = '';
                    showError(error.message || 'Mix could not be created.');
                } finally {
                    submitButton.disabled = false;
                    submitButton.innerHTML = '<i class="fas fa-layer-group mr-2"></i> CREATE MIX FROM SELECTED LISTS';
                }
            });

            if (isp.value) {
                renderCountries();
                loadButton.disabled = false;
                loadLists();
            }
        })();
    </script>
