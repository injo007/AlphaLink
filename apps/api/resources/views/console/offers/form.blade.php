@extends('layouts.dms-reference')

@section('title', $offer ? 'Edit Offer - DMS' : 'Add Offer - DMS')

@php
    $saveUrl = $offer ? route('console.offers.update', [$workspace->id, $offer->id]) : route('console.offers.store', $workspace->id);
    $saveMethod = $offer ? 'PUT' : 'POST';
    $fromValues = $offer?->assets->where('type', 'from')->pluck('value')->implode("\n") ?? '';
    $subjectValues = $offer?->assets->where('type', 'subject')->pluck('value')->implode("\n") ?? '';
@endphp

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">{{ $offer ? 'EDIT OFFER' : 'ADD NEW OFFER' }}@if ($offer) <span class="text-info">{{ $offer->id }} | {{ $offer->offer_id }} | {{ $offer->name }}</span>@endif</h3>
            @if ($canImport)
                <div class="block-options">
                    <div class="custom-control custom-switch custom-control-inline">
                        <input type="checkbox" class="custom-control-input" id="offer-import-mode" checked>
                        <label class="custom-control-label" for="offer-import-mode" id="offer-mode-label">IMPORT</label>
                    </div>
                </div>
            @endif
        </div>
        <div class="block-content">
            <div class="row justify-content-center py-1">
                <div class="col-sm-10">
                    @unless ($canImport)
                        <div class="alert alert-info" role="status">DMS manual offer fields and validation. New offers inherit the selected sponsor’s operator access and begin inactive.</div>
                    @endunless
                    <div id="offer-result" class="alert alert-danger d-none" role="alert" aria-live="assertive"></div>
                    <div class="form-group"><label for="sponsor_id">Select Sponsor:</label><select id="sponsor_id" class="form-control selectpicker" data-live-search="true" required><option value="">Select Sponsor</option>@foreach ($sponsors as $sponsor)<option value="{{ $sponsor->id }}" data-type="{{ $sponsor->type }}" @selected((string) old('sponsor_id', $offer?->sponsor_id ?? request('sponsor')) === (string) $sponsor->id)>{{ $sponsor->name }}</option>@endforeach</select></div>
                    @if ($canImport)
                        <div id="import_div">
                            <div class="form-group">
                                <label for="offer_ids">Offer Ids:</label>
                                <textarea id="offer_ids" rows="6" class="form-control form-control-alt" placeholder="Offer Ids" style="resize: none"></textarea>
                            </div>
                            <p id="import-provider-status" class="text-muted" role="status">Choose a HitPath, NewHitPath, Cake or EverFlow sponsor. Each import reads only the selected offer IDs.</p>
                            <div id="import-result" class="alert alert-danger d-none" role="alert" aria-live="assertive"></div>
                            <div class="form-group">
                                <button id="import-offers" class="btn btn-block btn-info" type="button" disabled>IMPORT</button>
                            </div>
                        </div>
                        <div id="save_div" class="d-none">
                    @endif
                    <div class="form-group"><label for="offer_id">Offer ID:</label><input id="offer_id" class="form-control form-control-alt" maxlength="255" value="{{ old('offer_id', $offer?->offer_id) }}" required></div>
                    <div class="form-group"><label for="name">Offer Name:</label><input id="name" class="form-control form-control-alt" maxlength="255" value="{{ old('name', $offer?->name) }}" required></div>
                    <div class="form-group"><label for="type">Offer Type:</label><select id="type" class="form-control selectpicker" required>@foreach (\App\Models\WorkspaceOffer::TYPES as $type)<option value="{{ $type }}" @selected(old('type', strtoupper($offer?->type ?? 'CPA')) === $type)>{{ $type }}</option>@endforeach</select></div>
                    <div class="form-group"><label for="vertical">Offer Vertical:</label><input id="vertical" class="form-control form-control-alt" maxlength="255" value="{{ old('vertical', $offer?->vertical) }}" required></div>
                    <div class="form-group"><label for="geo_target">Offer Geo Target:</label><select id="geo_target" class="form-control selectpicker" data-live-search="true" data-actions-box="true" multiple required>@foreach ($countries as $code => $country)<option value="{{ $code }}" @selected(in_array($code, old('geo_target', $offer?->geo_target ?? []), true))>{{ $country }}</option>@endforeach<option value="ALL" @selected(in_array('ALL', old('geo_target', $offer?->geo_target ?? []), true))>ALL COUNTRIES ALLOWED</option></select></div>
                    <div class="form-group"><label for="payout">Offer Payout:</label><input id="payout" type="number" min="0" max="9999" step="0.0001" class="form-control form-control-alt" value="{{ old('payout', $offer?->payout ?? '0') }}" required></div>
                    <div class="form-group"><label for="allowed_days">Offer Allowed Days:</label><select id="allowed_days" class="form-control selectpicker" data-actions-box="true" multiple required>@foreach (\App\Models\WorkspaceOffer::ALLOWED_DAYS as $day)<option value="{{ $day }}" @selected(in_array($day, old('allowed_days', $offer?->allowed_days ?? \App\Models\WorkspaceOffer::ALLOWED_DAYS), true))>{{ $day }}</option>@endforeach</select></div>
                    <div class="form-group"><label for="note">Offer Note:</label><textarea id="note" class="form-control form-control-alt" maxlength="9999">{{ old('note', $offer?->note) }}</textarea></div>
                    <div class="form-group"><label for="redirect">Redirect Link:</label><input id="redirect" type="url" class="form-control form-control-alt" maxlength="255" value="{{ old('redirect', $offer?->redirect) }}" required></div>
                    <div class="form-group"><label for="unsubscribe">Unsubscribe Link:</label><input id="unsubscribe" type="url" class="form-control form-control-alt" maxlength="255" value="{{ old('unsubscribe', $offer?->unsubscribe) }}"></div>
                    @if ($offer)
                        <div class="alert alert-info mt-3" role="status">
                            Suppression files are managed from Drops → Suppression. Uploading there attaches the verified file to this offer.
                            <a class="btn btn-sm btn-outline-primary ml-2" href="{{ route('clean.console.drops.suppression', ['sponsor_id' => $offer->sponsor_id, 'offer_id' => $offer->id]) }}" target="_blank" rel="noopener">MANAGE SUPPRESSION FILE</a>
                        </div>
                    @endif
                    @unless ($offer)
                        <div class="alert alert-info" role="status">After saving this offer, upload its suppression file from <a href="{{ route('clean.console.drops.suppression') }}" target="_blank" rel="noopener">Drops → Suppression</a>. The file is stored with the offer record; new offers remain inactive until they meet the activation requirements.</div>
                        <div class="form-group"><label for="from">From:</label><textarea id="from" rows="5" class="form-control form-control-alt" placeholder="One sender name or address per line" required></textarea></div>
                        <div class="form-group"><label for="subjects">Subjects:</label><textarea id="subjects" rows="5" class="form-control form-control-alt" placeholder="One subject line per line" required></textarea></div>
                    @endunless
                    @if ($canImport)
                        </div>
                    @endif
                    <button id="save-offer" class="btn btn-block {{ $offer ? 'btn-warning' : 'btn-info' }} {{ $canImport ? 'd-none' : '' }}" type="button">{{ $offer ? 'EDIT' : 'SAVE' }}</button>
                </div>
            </div>
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
            const importMode = document.querySelector('#offer-import-mode');
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
                const ids = importIds.value.split(/\r?\n/).map((value) => value.trim()).filter(Boolean);
                const providerSupported = importableProviderTypes.includes(providerType);
                importButton.disabled = !providerSupported || ids.length === 0;
                providerStatus.textContent = !selectedOption?.value
                    ? 'Choose a HitPath, NewHitPath, Cake, HasOffers or EverFlow sponsor. Enter the provider offer IDs you want to import.'
                    : (!providerSupported
                        ? 'Automatic import is not yet implemented for this DMS network. Use MANUEL mode to add an offer.'
                        : ({
                            hitpath: 'HitPath imports selected campaign IDs and reads DMS-mapped offer fields, From/Subject lines, and the first redirect and last unsubscribe links from email assets.',
                            newhitpath: 'NewHitPath imports selected campaign metadata and email assets, normalizes HTML, rewrites links, and safely fetches public HTTPS images.',
                            cake: 'Cake imports selected campaign metadata and sender lines, uses the first creative link, and safely imports an available creative bundle.',
                            hasoffers: 'HasOffers imports selected offer metadata, category and geo targeting, sender lines, tracking links, and the DMS opt-out link.',
                            everflow: 'EverFlow imports selected offer IDs, DMS-mapped fields, From/Subject assets, and available HTML/image creatives.',
                        })[providerType]);
            };
            importMode?.addEventListener('change', () => {
                const importing = importMode.checked;
                document.querySelector('#offer-mode-label').textContent = importing ? 'IMPORT' : 'MANUEL';
                document.querySelector('#import_div').classList.toggle('d-none', !importing);
                document.querySelector('#save_div').classList.toggle('d-none', importing);
                document.querySelector('#save-offer').classList.toggle('d-none', importing);
            });
            sponsorSelect.addEventListener('change', syncImportAvailability);
            importIds?.addEventListener('input', syncImportAvailability);
            importButton?.addEventListener('click', async () => {
                const ids = [...new Set(importIds.value.split(/\r?\n/).filter(Boolean))].map((value) => value.trim());
                const sponsor = sponsorSelect.value;
                importResult.classList.add('d-none');
                importButton.disabled = true;
                importButton.textContent = 'IMPORTING';
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
                    importButton.textContent = 'IMPORT';
                    syncImportAvailability();
                }
            });
            syncImportAvailability();
            const csvLines = (value) => [...new Set(value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean))];
            const selected = (element) => [...element.selectedOptions].map((option) => option.value);
            button.addEventListener('click', async () => {
                button.disabled = true;
                button.textContent = isEditing ? 'EDITING' : 'SAVING';
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
                    button.textContent = isEditing ? 'EDIT' : 'SAVE';
                }
            });
        })();
    </script>
@endpush
