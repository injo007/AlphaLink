@extends('layouts.dms-reference')

@section('title', $isp ? 'Edit Isp - DMS' : 'Add Isp - DMS')

@php
    $saveUrl = $isp
        ? route('console.infrastructure.isps.update', [$workspace->id, $isp->id])
        : route('console.infrastructure.isps.store', $workspace->id);
    $saveMethod = $isp ? 'PUT' : 'POST';
@endphp

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">{{ $isp ? 'EDIT ISP' : 'ADD ISP' }}@if ($isp) <span class="text-info">{{ $isp->id }} | {{ $isp->name }}</span>@endif</h3>
        </div>
        <div class="block-content">
            <div class="row justify-content-center py-1">
                <div class="col-sm-10">
                    <div id="isp-result" class="alert alert-danger d-none" role="alert" aria-live="polite"></div>
                    <div class="form-group">
                        <label for="name">Name{{ $isp ? ':' : '' }}</label>
                        <input type="text" class="form-control form-control-alt" id="name" name="name"
                            placeholder="Enter Isp Name.." value="{{ old('name', $isp?->name) }}" minlength="3" maxlength="255">
                    </div>
                    <div class="form-group">
                        <label for="tracked_countries">Tracked Countries:</label>
                        <select id="tracked_countries" class="form-control" multiple>
                            @foreach ($countries as $code => $country)
                                <option value="{{ $code }}" @selected(in_array($code, old('tracked_countries', $isp?->tracked_countries ?? []), true))>{{ $country }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="quality_emails">Quality Emails:</label>
                        <textarea id="quality_emails" class="form-control form-control-alt" rows="8" placeholder="Enter Quality Emails ...">{{ old('quality_emails_text', implode("\n", $isp?->quality_emails ?? [])) }}</textarea>
                    </div>
                    <div class="form-group">
                        <button id="save-isp" class="btn btn-block {{ $isp ? 'btn-warning' : 'btn-info' }}" type="button">{{ $isp ? 'EDIT' : 'SAVE' }}</button>
                    </div>
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
                button.textContent = @json($isp ? 'UPDATING' : 'SAVING');
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
                        window.location.assign(@json(route('console.infrastructure.isps.index', $workspace->id)));
                    }
                } catch (error) {
                    result.textContent = error.message;
                    result.classList.remove('d-none');
                } finally {
                    button.disabled = false;
                    button.textContent = @json($isp ? 'EDIT' : 'SAVE');
                }
            });
        })();
    </script>
@endpush
