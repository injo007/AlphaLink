(() => {
    const dialog = document.getElementById('campaign-clone-dialog');
    const content = document.getElementById('campaign-clone-dialog-content');
    const openers = Array.from(document.querySelectorAll('[data-campaign-clone-open]'));
    let cloneCreated = false;
    let progressTimer = null;

    const setStatus = (element, message, level = 'muted') => {
        element.className = `small text-${level}`;
        element.textContent = message;
    };

    const initializeCloneForm = (form, modalForm) => {
        if (!form || form.dataset.initialized === 'true') return;
        form.dataset.initialized = 'true';
        let terminalProgress = false;

        const isp = form.querySelector('#clone-isp');
        const country = form.querySelector('#clone-country');
        const segments = form.querySelector('#clone-segments');
        const estimate = form.querySelector('#clone-recipient-estimate');
        const status = form.querySelector('#clone-status');
        const submit = form.querySelector('#clone-submit');
        const progress = form.querySelector('#clone-progress');
        const progressBar = form.querySelector('#clone-progress-bar');
        const progressTrack = progress?.querySelector('[role="progressbar"]');
        const progressLabel = form.querySelector('#clone-progress-label');
        const cancel = form.querySelector('[data-campaign-clone-cancel]');
        if (!isp || !country || !segments || !estimate || !status || !submit) return;
        let listRequestId = 0;

        if (window.jQuery?.fn?.selectpicker) {
            window.jQuery(form).find('.selectpicker').selectpicker();
        }

        const refreshPicker = element => {
            if (window.jQuery?.fn?.selectpicker) window.jQuery(element).selectpicker('refresh');
        };

        const updateEstimate = () => {
            const selected = Array.from(segments.selectedOptions);
            const total = selected.reduce((sum, option) => sum + Number(option.dataset.recipientCount || 0), 0);
            estimate.textContent = new Intl.NumberFormat().format(total);
            submit.disabled = selected.length === 0;
        };

        const refreshCountries = () => {
            const selected = isp.selectedOptions[0];
            let countries = [];
            try {
                countries = JSON.parse(selected?.dataset.countries || '[]');
            } catch (_) {
                countries = [];
            }

            const previous = country.value;
            country.replaceChildren(new Option('ALL', 'ALL'));
            for (const code of countries) {
                country.add(new Option(String(code).toUpperCase(), String(code).toUpperCase()));
            }
            country.value = countries.map(code => String(code).toUpperCase()).includes(previous) ? previous : 'ALL';
            refreshPicker(country);
        };

        const loadSegments = async () => {
            const requestId = ++listRequestId;
            if (!isp.value) {
                segments.replaceChildren();
                refreshPicker(segments);
                updateEstimate();
                setStatus(status, 'Choose an ISP to load its eligible lists.');
                return;
            }

            const params = new URLSearchParams({
                sponsor_id: form.dataset.sponsorId,
                offer_country: form.dataset.offerCountry,
                isp_id: isp.value,
                country: country.value,
                route: form.dataset.routeOption,
                ip_type: form.dataset.ipType,
            });
            for (const serverId of JSON.parse(form.dataset.serverIds || '[]')) params.append('server_ids[]', serverId);

            submit.disabled = true;
            setStatus(status, 'Loading eligible lists…');
            segments.replaceChildren();
            refreshPicker(segments);

            try {
                const response = await fetch(`${form.dataset.optionsUrl}?${params.toString()}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!response.ok) throw new Error(`Lists could not be loaded (HTTP ${response.status}).`);
                const payload = await response.json();
                if (requestId !== listRequestId) return;
                for (const segment of payload.data?.segments || []) {
                    const label = `${segment.name} · ${String(segment.email_type).toUpperCase()} · ${new Intl.NumberFormat().format(segment.recipient_count)}`;
                    const option = new Option(label, String(segment.id));
                    option.dataset.recipientCount = String(segment.recipient_count);
                    segments.add(option);
                }
                refreshPicker(segments);
                setStatus(status, segments.options.length
                    ? 'Select one or more eligible lists for the cloned campaign.'
                    : 'No eligible lists are available for this ISP and country.');
            } catch (error) {
                if (requestId !== listRequestId) return;
                setStatus(status, error instanceof Error ? error.message : 'Lists could not be loaded. Reload the page and try again.', 'danger');
            }

            updateEstimate();
        };

        isp.addEventListener('change', () => {
            refreshCountries();
            loadSegments();
        });
        country.addEventListener('change', loadSegments);
        segments.addEventListener('change', updateEstimate);
        cancel?.addEventListener('click', () => {
            if (dialog && modalForm) {
                dialog.close();
            } else {
                window.location.assign(form.dataset.cancelUrl);
            }
        });

        updateEstimate();

        if (!modalForm) return;

        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (submit.disabled) return;

            const body = new FormData(form);
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            submit.textContent = 'CREATING CLONE…';
            form.querySelectorAll('select, input:not([type="hidden"])').forEach(field => { field.disabled = true; });
            setStatus(status, 'Saving the clone and starting its fresh suppression check…', 'info');

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const result = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const validation = Object.values(result.errors || {}).flat()[0];
                    throw new Error(result.message || validation || 'The campaign clone could not be created. Review the selected ISP and lists.');
                }

                cloneCreated = true;
                terminalProgress = false;
                progress.hidden = false;
                progressTrack?.setAttribute('aria-valuenow', '0');
                progressBar.style.width = '0%';
                setStatus(status, result.message || 'Clone saved. Suppression and recipient preparation are running.', 'info');

                const pollProgress = async () => {
                    if (terminalProgress) return;
                    try {
                        const progressResponse = await fetch(result.progress_url, {
                            cache: 'no-store',
                            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });
                        if (!progressResponse.ok) return;
                        const state = await progressResponse.json();
                        const percent = Math.max(0, Math.min(100, Number(state.percent || 0)));
                        progressTrack?.setAttribute('aria-valuenow', String(percent));
                        progressBar.style.width = `${percent}%`;

                        if (state.stage === 'suppression_file' && state.total) {
                            progressLabel.textContent = `Checking suppression file · ${Math.floor(state.processed / 1048576)} MB of ${Math.ceil(state.total / 1048576)} MB · ${percent}%`;
                        } else if (state.stage === 'audience' && state.total !== null) {
                            progressLabel.textContent = `Checking selected addresses · ${Number(state.processed).toLocaleString()} of ${Number(state.total).toLocaleString()} · ${percent}%`;
                        } else {
                            const stages = {
                                queued: 'Waiting for recipient preparation to start…',
                                counting_audience: 'Counting the selected lists…',
                                writing_snapshot: 'Saving the suppressed recipient list…',
                            };
                            progressLabel.textContent = stages[state.stage] || `Preparing the cloned recipient list… ${percent}%`;
                        }

                        if (state.status === 'snapshot_failed') {
                            terminalProgress = true;
                            window.clearInterval(progressTimer);
                            progressTimer = null;
                            progressBar.classList.remove('progress-bar-animated');
                            progressBar.classList.add('bg-danger');
                            setStatus(status, state.error || 'Suppression or recipient preparation failed. Open the cloned campaign to review and retry.', 'danger');
                            submit.textContent = 'CLOSE';
                            submit.disabled = false;
                            submit.removeAttribute('aria-busy');
                            submit.type = 'button';
                            submit.addEventListener('click', () => window.location.reload(), { once: true });
                        } else if (state.ready) {
                            terminalProgress = true;
                            window.clearInterval(progressTimer);
                            progressTimer = null;
                            progressTrack?.setAttribute('aria-valuenow', '100');
                            progressBar.style.width = '100%';
                            progressLabel.textContent = `Suppression complete · ${Number(state.total || state.processed || 0).toLocaleString()} recipients ready`;
                            setStatus(status, 'Clone ready. Returning to My Campaigns…', 'success');
                            window.setTimeout(() => window.location.reload(), 900);
                        }
                    } catch (_) {
                        // Keep the visible progress until a later poll succeeds.
                    }
                };

                await pollProgress();
                if (!terminalProgress && cloneCreated && progressTimer === null) {
                    progressTimer = window.setInterval(pollProgress, 2500);
                }
            } catch (error) {
                form.querySelectorAll('select, input:not([type="hidden"])').forEach(field => { field.disabled = false; });
                form.querySelectorAll('.selectpicker').forEach(refreshPicker);
                submit.disabled = false;
                submit.removeAttribute('aria-busy');
                submit.textContent = 'CLONE CAMPAIGN';
                setStatus(status, error instanceof Error ? error.message : 'The campaign clone could not be created.', 'danger');
            }
        });
    };

    const loadModal = async opener => {
        if (!dialog || !content || typeof dialog.showModal !== 'function') {
            window.location.assign(opener.href);
            return;
        }

        if (!dialog.open) dialog.showModal();
        content.innerHTML = '<p class="text-muted mb-0">Loading campaign settings…</p>';
        try {
            const url = new URL(opener.href, window.location.href);
            url.searchParams.set('modal', '1');
            const response = await fetch(url.toString(), {
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error(response.status === 409
                    ? 'This campaign is not eligible to clone. Check that its offer is still live and active.'
                    : `Campaign settings could not be loaded (HTTP ${response.status}).`);
            }
            const markup = await response.text();
            content.innerHTML = markup;
            const form = content.querySelector('#campaign-clone-form');
            initializeCloneForm(form, true);
            form?.querySelector('#clone-name')?.focus();
        } catch (error) {
            content.replaceChildren();
            const message = document.createElement('p');
            message.className = 'text-danger mb-0';
            message.setAttribute('role', 'alert');
            message.textContent = error instanceof Error ? error.message : 'Campaign settings could not be loaded.';
            content.append(message);
        }
    };

    for (const opener of openers) {
        opener.addEventListener('click', event => {
            event.preventDefault();
            loadModal(opener);
        });
    }

    if (dialog && content) {
        dialog.querySelector('[data-campaign-clone-dialog-close]')?.addEventListener('click', () => dialog.close());
        dialog.addEventListener('close', () => {
            if (progressTimer !== null) window.clearInterval(progressTimer);
            progressTimer = null;
            if (cloneCreated) window.location.reload();
        });
    }

    const standaloneForm = document.getElementById('campaign-clone-form');
    if (standaloneForm && !dialog) initializeCloneForm(standaloneForm, false);
})();
