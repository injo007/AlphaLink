@extends('layouts.standalone')

@section('title', $isp ? 'Edit ISP' : 'Add ISP')

@php
    $saveUrl = $isp
        ? route('clean.console.infrastructure.isps.index').'/'.$isp->id
        : route('clean.console.infrastructure.isps.store');
    $saveMethod = $isp ? 'PUT' : 'POST';
@endphp

@section('content')
    <div class="command-modal-surface p-4" style="min-height: 100vh;">
        <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
            <div class="d-flex align-items-center">
                <i class="fas fa-network-wired text-purple mr-2" style="font-size: 1.25rem;"></i>
                <h3 class="m-0 font-w700 font-size-base text-uppercase" style="letter-spacing: 0.04em;">
                    {{ $isp ? 'EDIT ISP' : 'ADD NEW ISP' }}
                    @if ($isp) <span class="badge-command-pill ml-2">#{{ $isp->id }} | {{ $isp->name }}</span>@endif
                </h3>
            </div>
            <span class="badge-command-pill">ISP DIRECTORY</span>
        </div>

        <div class="row justify-content-center">
            <div class="col-12 col-md-10">
                <div id="isp-result" class="alert alert-danger d-none py-2 px-3 mb-3 font-size-sm" role="alert" aria-live="polite" style="border-radius: 10px;"></div>

                <div class="form-group mb-3">
                    <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1" for="name">
                        <span class="text-danger mr-1">*</span>ISP Name:
                    </label>
                    <input type="text" class="form-control" id="name" name="name"
                        placeholder="e.g. Gmail, Yahoo, Outlook..." value="{{ old('name', $isp?->name) }}" minlength="3" maxlength="255" style="height: 42px; border-radius: 10px; font-size: 12.5px;">
                </div>

                <div class="form-group mb-3">
                    <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1" for="tracked_countries">
                        Tracked Countries:
                    </label>
                    <select id="tracked_countries" class="form-control" multiple style="border-radius: 10px;">
                        @foreach ($countries as $code => $country)
                            <option value="{{ $code }}" @selected(in_array($code, old('tracked_countries', $isp?->tracked_countries ?? []), true))>{{ $country }} ({{ $code }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group mb-4">
                    <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1" for="quality_emails">
                        Quality Emails (One per line):
                    </label>
                    <textarea id="quality_emails" class="form-control" rows="6" placeholder="admin@domain.com&#10;test@domain.com" style="border-radius: 10px; font-family: monospace; font-size: 12px;">{{ old('quality_emails_text', implode("\n", $isp?->quality_emails ?? [])) }}</textarea>
                </div>

                <div class="d-flex justify-content-end" style="gap: 12px;">
                    <button id="save-isp" class="btn-command-primary" type="button" style="padding: 10px 24px;">
                        <i class="fas fa-save mr-1"></i> {{ $isp ? 'UPDATE ISP' : 'SAVE ISP' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('vendor/select2/4.0.13/select2.min.js') }}"></script>
    <script>
        (() => {
            window.jQuery('#tracked_countries').select2({
                allowClear: true,
                placeholder: 'TRACKED COUNTRIES',
                multi: true,
                closeOnSelect: false,
            });
            const result = document.querySelector('#isp-result');
            const button = document.querySelector('#save-isp');

            button.addEventListener('click', async () => {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> ' + @json($isp ? 'UPDATING...' : 'SAVING...');
                result.classList.add('d-none');
                result.textContent = '';
                const emails = [...new Set(document.querySelector('#quality_emails').value.split('\n').filter((email) => email))];
                const payload = {
                    name: document.querySelector('#name').value,
                    tracked_countries: window.jQuery('#tracked_countries').val() || null,
                    quality_emails: emails.length ? emails : null,
                };

                try {
                    const response = await fetch(@json($saveUrl), {
                        method: @json($saveMethod),
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify(payload),
                    });
                    const body = await response.json();
                    if (!response.ok) {
                        throw new Error(Object.values(body.errors ?? {}).flat().join(' ') || 'ISP could not be saved.');
                    }
                    if (window.parent !== window && typeof window.parent.closeModal === 'function') {
                        const closeModal = () => window.parent.closeModal();
                        if (window.parent.App?.swalPromise) {
                            window.parent.App.swalPromise({
                                icon: 'success',
                                title: body.data,
                                confirmButtonText: 'close',
                            }).then(closeModal);
                        } else {
                            closeModal();
                        }
                    } else {
                        window.location.assign(@json(route('clean.console.infrastructure.isps.index')));
                    }
                } catch (error) {
                    result.textContent = error.message;
                    result.classList.remove('d-none');
                } finally {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-save mr-1"></i> ' + @json($isp ? 'UPDATE ISP' : 'SAVE ISP');
                }
            });
        })();
    </script>
@endpush
