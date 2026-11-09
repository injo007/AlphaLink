@extends('layouts.standalone')

@section('title', $offer ? 'Edit Offer' : 'Add Offer')

@php
    $saveUrl = $offer ? route('console.offers.update', [$workspace->id, $offer->id]) : route('console.offers.store', $workspace->id);
    $saveMethod = $offer ? 'PUT' : 'POST';
    $fromValues = $offer?->assets->where('type', 'from')->pluck('value')->implode("\n") ?? '';
    $subjectValues = $offer?->assets->where('type', 'subject')->pluck('value')->implode("\n") ?? '';
@endphp

@section('content')
    <div class="command-os-settings-card m-0" style="border-radius: 0; min-height: 100vh;">
        <div class="command-os-settings-card-header">
            <div class="command-os-settings-card-title-group">
                <div class="command-os-settings-card-icon">
                    <i class="fas fa-tag"></i>
                </div>
                <div class="command-os-settings-card-titles">
                    <h3>{{ $offer ? 'EDIT OFFER' : 'ADD NEW OFFER' }}</h3>
                    <p>Campaign metadata, payout terms, geo-targeting policies, and tracking coordinates</p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                @if ($offer)
                    <span class="badge badge-purple px-2 py-1 font-mono font-w700">OID: {{ $offer->offer_id }} &bull; {{ $offer->name }}</span>
                @elseif ($canImport)
                    <div class="command-os-seg-toggle" style="width: 220px; height: 36px;">
                        <input type="checkbox" id="offer-import-mode" class="d-none" checked>
                        <div class="command-os-seg-track" style="height: 36px; padding: 2px;">
                            <button type="button" class="command-os-seg-btn is-active js-mode-btn" data-mode="import">
                                <i class="fas fa-cloud-download-alt mr-1"></i> IMPORT
                            </button>
                            <button type="button" class="command-os-seg-btn js-mode-btn" data-mode="manual">
                                <i class="fas fa-edit mr-1"></i> MANUAL
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="command-os-settings-card-body p-4">
            <div id="offer-result" class="alert alert-danger d-none mb-3" role="alert" aria-live="assertive"></div>

            {{-- 1. Sponsor Selection (Always active) --}}
            <div class="row">
                <div class="col-sm-12 col-md-6">
                    <div class="form-group command-os-field-group">
                        <label for="sponsor_id">
                            <span><i class="fas fa-building command-field-icon"></i> Connected Sponsor:</span>
                        </label>
                        <select id="sponsor_id" class="form-control selectpicker" data-live-search="true" required>
                            <option value="">Select Sponsor</option>
                            @foreach ($sponsors as $sponsor)
                                <option value="{{ $sponsor->id }}" data-type="{{ $sponsor->type }}" @selected((string) old('sponsor_id', $offer?->sponsor_id ?? request('sponsor')) === (string) $sponsor->id)>{{ $sponsor->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-sm-12 col-md-6">
                    <div class="form-group command-os-field-group">
                        <label for="type">
                            <span><i class="fas fa-layer-group command-field-icon"></i> Offer Type:</span>
                        </label>
                        <div class="command-input-wrap">
                            <span class="command-input-leading-icon"><i class="fas fa-layer-group"></i></span>
                            <select id="type" class="form-control command-os-custom-select" required>
                                @foreach (\App\Models\WorkspaceOffer::TYPES as $type)
                                    <option value="{{ $type }}" @selected(old('type', strtoupper($offer?->type ?? 'CPA')) === $type)>{{ $type }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            @if ($canImport)
                {{-- Import Panel --}}
                <div id="import_div">
                    <div class="row">
                        <div class="col-12">
                            <div class="form-group command-os-field-group">
                                <label for="offer_ids">
                                    <span><i class="fas fa-list-ol command-field-icon"></i> Provider Offer IDs:</span>
                                </label>
                                <textarea id="offer_ids" rows="5" class="form-control font-mono" placeholder="Enter provider offer IDs, one per line (e.g. 10101)..." style="border-radius: 10px; font-size: 13px; resize: none;"></textarea>
                                <small id="import-provider-status" class="form-text text-muted mt-2" role="status">Choose a HitPath, NewHitPath, Cake, HasOffers or EverFlow sponsor. Each import fetches and normalizes campaign metadata automatically.</small>
                            </div>
                        </div>
                    </div>
                    <div id="import-result" class="alert d-none mb-3" role="alert" aria-live="assertive"></div>
                    <div class="d-flex justify-content-end mt-3">
                        <button id="import-offers" class="btn btn-primary command-os-btn-save-settings" type="button" disabled style="width: auto; padding: 10px 28px !important;">
                            <i class="fas fa-cloud-download-alt mr-2"></i> IMPORT SELECTED OFFERS
                        </button>
                    </div>
                </div>
                <div id="save_div" class="d-none">
            @endif

            {{-- Manual Entry Panel --}}
            <div class="row">
                <div class="col-sm-12 col-md-6">
                    <div class="form-group command-os-field-group">
                        <label for="offer_id">
                            <span><i class="fas fa-hashtag command-field-icon"></i> Offer ID:</span>
                        </label>
                        <div class="command-input-wrap">
                            <span class="command-input-leading-icon"><i class="fas fa-hashtag"></i></span>
                            <input id="offer_id" class="form-control command-input-with-icon font-mono" maxlength="255" value="{{ old('offer_id', $offer?->offer_id) }}" placeholder="e.g. 54321" required>
                        </div>
                    </div>
                </div>
                <div class="col-sm-12 col-md-6">
                    <div class="form-group command-os-field-group">
                        <label for="name">
                            <span><i class="fas fa-tag command-field-icon"></i> Offer Name:</span>
                        </label>
                        <div class="command-input-wrap">
                            <span class="command-input-leading-icon"><i class="fas fa-tag"></i></span>
                            <input id="name" class="form-control command-input-with-icon" maxlength="255" value="{{ old('name', $offer?->name) }}" placeholder="e.g. Health Insurance Direct CPA" required>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-12 col-md-6">
                    <div class="form-group command-os-field-group">
                        <label for="vertical">
                            <span><i class="fas fa-cubes command-field-icon"></i> Offer Vertical:</span>
                        </label>
                        <div class="command-input-wrap">
                            <span class="command-input-leading-icon"><i class="fas fa-cubes"></i></span>
                            <input id="vertical" class="form-control command-input-with-icon" maxlength="255" value="{{ old('vertical', $offer?->vertical) }}" placeholder="e.g. Insurance, Finance, Sweepstakes..." required>
                        </div>
                    </div>
                </div>
                <div class="col-sm-12 col-md-6">
                    <div class="form-group command-os-field-group">
                        <label for="payout">
                            <span><i class="fas fa-dollar-sign command-field-icon"></i> Payout ($ USD):</span>
                        </label>
                        <div class="command-input-wrap">
                            <span class="command-input-leading-icon"><i class="fas fa-dollar-sign"></i></span>
                            <input id="payout" type="number" min="0" max="9999" step="0.0001" class="form-control command-input-with-icon" value="{{ old('payout', $offer?->payout ?? '0') }}" placeholder="0.00" required>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-12 col-md-6">
                    <div class="form-group command-os-field-group">
                        <label for="geo_target">
                            <span><i class="fas fa-globe command-field-icon"></i> Geo Targeting:</span>
                        </label>
                        <select id="geo_target" class="form-control selectpicker" data-live-search="true" data-actions-box="true" multiple required>
                            <option value="ALL" @selected(in_array('ALL', old('geo_target', $offer?->geo_target ?? []), true))>ALL COUNTRIES ALLOWED</option>
                            @foreach ($countries as $code => $country)
                                <option value="{{ $code }}" @selected(in_array($code, old('geo_target', $offer?->geo_target ?? []), true))>{{ $country }} ({{ $code }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-sm-12 col-md-6">
                    <div class="form-group command-os-field-group">
                        <label for="allowed_days">
                            <span><i class="fas fa-calendar-week command-field-icon"></i> Allowed Days:</span>
                        </label>
                        <select id="allowed_days" class="form-control selectpicker" data-actions-box="true" multiple required>
                            @foreach (\App\Models\WorkspaceOffer::ALLOWED_DAYS as $day)
                                <option value="{{ $day }}" @selected(in_array($day, old('allowed_days', $offer?->allowed_days ?? \App\Models\WorkspaceOffer::ALLOWED_DAYS), true))>{{ $day }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-12 col-md-6">
                    <div class="form-group command-os-field-group">
                        <label for="redirect">
                            <span><i class="fas fa-external-link-alt command-field-icon"></i> Redirect Link:</span>
                        </label>
                        <div class="command-input-wrap">
                            <span class="command-input-leading-icon"><i class="fas fa-external-link-alt"></i></span>
                            <input id="redirect" type="url" class="form-control command-input-with-icon" maxlength="255" value="{{ old('redirect', $offer?->redirect) }}" placeholder="https://tracking.link/click..." required>
                        </div>
                    </div>
                </div>
                <div class="col-sm-12 col-md-6">
                    <div class="form-group command-os-field-group">
                        <label for="unsubscribe">
                            <span><i class="fas fa-user-times command-field-icon"></i> Unsubscribe Link:</span>
                        </label>
                        <div class="command-input-wrap">
                            <span class="command-input-leading-icon"><i class="fas fa-user-times"></i></span>
                            <input id="unsubscribe" type="url" class="form-control command-input-with-icon" maxlength="255" value="{{ old('unsubscribe', $offer?->unsubscribe) }}" placeholder="https://optout.link/unsub...">
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="form-group command-os-field-group">
                        <label for="note">
                            <span><i class="fas fa-sticky-note command-field-icon"></i> Internal Notes &amp; Suppression Instructions:</span>
                        </label>
                        <textarea id="note" class="form-control" rows="2" maxlength="9999" placeholder="Optional notes for operations and mailers..." style="border-radius: 10px; font-size: 13px;">{{ old('note', $offer?->note) }}</textarea>
                    </div>
                </div>
            </div>

            @unless ($offer)
                <hr class="command-os-divider my-3">
                <div class="row">
                    <div class="col-sm-12 col-md-6">
                        <div class="form-group command-os-field-group">
                            <label for="from">
                                <span><i class="fas fa-user-tag command-field-icon"></i> From Sender Names:</span>
                            </label>
                            <textarea id="from" rows="4" class="form-control font-mono" placeholder="One sender name per line..." style="border-radius: 10px; font-size: 12px;" required></textarea>
                        </div>
                    </div>
                    <div class="col-sm-12 col-md-6">
                        <div class="form-group command-os-field-group">
                            <label for="subjects">
                                <span><i class="fas fa-envelope-open-text command-field-icon"></i> Email Subjects:</span>
                            </label>
                            <textarea id="subjects" rows="4" class="form-control font-mono" placeholder="One subject line per line..." style="border-radius: 10px; font-size: 12px;" required></textarea>
                        </div>
                    </div>
                </div>
            @endunless

            <div class="d-flex justify-content-end mt-4">
                <button id="save-offer" class="btn btn-primary command-os-btn-save-settings {{ $canImport ? 'd-none' : '' }}" type="button" style="width: auto; padding: 10px 28px !important;">
                    <i class="fas fa-check mr-2"></i> {{ $offer ? 'UPDATE OFFER' : 'SAVE OFFER' }}
                </button>
            </div>

            @if ($canImport)
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const button = document.querySelector('#save-offer');
            const result = document.querySelector('#offer-result');
            const isEditing = @json((bool) $offer);
            const saveUrl = @json($saveUrl);
            const canImport = @json($canImport);
            const importUrl = @json(route('console.offers.import', $workspace->id));
            const importModeCheckbox = document.querySelector('#offer-import-mode');
            const importButton = document.querySelector('#import-offers');
            const importResult = document.querySelector('#import-result');
            const providerStatus = document.querySelector('#import-provider-status');
            const sponsorSelect = document.querySelector('#sponsor_id');
            const importIds = document.querySelector('#offer_ids');
            const importableProviderTypes = @json(\App\Models\WorkspaceSponsor::IMPORT_TYPES);

            const syncImportAvailability = () => {
                if (!canImport) return;
                const selectedOption = sponsorSelect.selectedOptions[0];
                const providerType = selectedOption?.dataset.type ?? '';
                const ids = importIds?.value.split(/\r?\n/).map((value) => value.trim()).filter(Boolean) || [];
                const providerSupported = importableProviderTypes.includes(providerType);
                if (importButton) {
                    importButton.disabled = !providerSupported || ids.length === 0;
                }
                if (providerStatus) {
                    providerStatus.textContent = !selectedOption?.value
                        ? 'Choose a HitPath, NewHitPath, Cake, HasOffers or EverFlow sponsor. Enter the provider offer IDs you want to import.'
                        : (!providerSupported
                            ? 'Automatic import is not yet implemented for this network. Use MANUAL mode to add an offer.'
                            : ({
                                hitpath: 'HitPath imports selected campaign IDs and reads offer fields, From/Subject lines, and redirect/unsubscribe links.',
                                newhitpath: 'NewHitPath imports campaign metadata, sender assets, normalizes HTML, rewrites links, and safely fetches public HTTPS images.',
                                cake: 'Cake imports campaign metadata and sender lines, uses creative links, and imports available creative bundles.',
                                hasoffers: 'HasOffers imports offer metadata, category and geo targeting, sender lines, tracking links, and provider opt-out link.',
                                everflow: 'EverFlow imports selected offer IDs, offer fields, From/Subject assets, and available HTML/image creatives.',
                            })[providerType] || 'Provider supported for direct API import.');
                }
            };

            document.querySelectorAll('.js-mode-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const mode = btn.dataset.mode;
                    document.querySelectorAll('.js-mode-btn').forEach(b => b.classList.remove('is-active'));
                    btn.classList.add('is-active');

                    const importing = mode === 'import';
                    if (importModeCheckbox) importModeCheckbox.checked = importing;
                    document.querySelector('#import_div')?.classList.toggle('d-none', !importing);
                    document.querySelector('#save_div')?.classList.toggle('d-none', importing);
                    button?.classList.toggle('d-none', importing);
                });
            });

            sponsorSelect.addEventListener('change', syncImportAvailability);
            importIds?.addEventListener('input', syncImportAvailability);
            importButton?.addEventListener('click', async () => {
                const ids = [...new Set(importIds.value.split(/\r?\n/).filter(Boolean))].map((value) => value.trim());
                const sponsor = sponsorSelect.value;
                importResult.classList.add('d-none');
                importButton.disabled = true;
                const originalHtml = importButton.innerHTML;
                importButton.innerHTML = '<span class="spinner-border spinner-border-sm mr-2" role="status"></span> IMPORTING…';

                try {
                    const response = await fetch(importUrl, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        body: JSON.stringify({ sponsor, ids }),
                    });
                    const body = await response.json();
                    if (!response.ok) throw new Error(Object.values(body.errors ?? {}).flat().join(' ') || body.message || 'Offer import failed.');
                    const close = () => window.parent.closeModal?.();
                    if (window.parent !== window && typeof window.parent.closeModal === 'function') {
                        window.parent.App?.swalPromise ? window.parent.App.swalPromise({ icon: 'success', title: body.data, confirmButtonText: 'close' }).then(close) : close();
                    } else {
                        importResult.classList.remove('alert-danger');
                        importResult.classList.add('alert-success');
                        importResult.textContent = body.data;
                        importResult.classList.remove('d-none');
                    }
                } catch (error) {
                    importResult.classList.remove('alert-success');
                    importResult.classList.add('alert-danger');
                    importResult.textContent = error.message;
                    importResult.classList.remove('d-none');
                } finally {
                    importButton.disabled = false;
                    importButton.innerHTML = originalHtml;
                    syncImportAvailability();
                }
            });

            syncImportAvailability();
            const csvLines = (value) => [...new Set(value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean))];
            const selected = (element) => [...element.selectedOptions].map((option) => option.value);

            button?.addEventListener('click', async () => {
                button.disabled = true;
                const originalHtml = button.innerHTML;
                button.innerHTML = '<span class="spinner-border spinner-border-sm mr-2" role="status"></span> SAVING…';
                result.classList.add('d-none');

                const payload = {
                    sponsor_id: Number(document.querySelector('#sponsor_id').value),
                    offer_id: document.querySelector('#offer_id').value.trim(),
                    name: document.querySelector('#name').value.trim(),
                    type: document.querySelector('#type').value,
                    vertical: document.querySelector('#vertical').value.trim(),
                    geo_target: selected(document.querySelector('#geo_target')),
                    payout: document.querySelector('#payout').value,
                    allowed_days: selected(document.querySelector('#allowed_days')),
                    note: document.querySelector('#note').value,
                    redirect: document.querySelector('#redirect').value.trim(),
                    unsubscribe: document.querySelector('#unsubscribe').value.trim() || null,
                };

                if (!isEditing) {
                    payload.from = csvLines(document.querySelector('#from').value);
                    payload.subjects = csvLines(document.querySelector('#subjects').value);
                }

                try {
                    const response = await fetch(saveUrl, {
                        method: @json($saveMethod),
                        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        body: JSON.stringify(payload),
                    });
                    const body = await response.json();
                    if (!response.ok) throw new Error(Object.values(body.errors ?? {}).flat().join(' ') || body.message || body.data || 'Offer could not be saved.');
                    if (window.parent !== window && typeof window.parent.closeModal === 'function') {
                        const close = () => window.parent.closeModal();
                        window.parent.App?.swalPromise ? window.parent.App.swalPromise({ icon: 'success', title: body.data, confirmButtonText: 'close' }).then(close) : close();
                    } else window.location.assign(@json(route('console.offers.index', $workspace->id)));
                } catch (error) {
                    result.textContent = error.message;
                    result.classList.remove('d-none');
                } finally {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                }
            });
        })();
    </script>
@endpush
