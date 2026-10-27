@extends('layouts.dms-reference')

@section('title', $sponsor ? 'Edit Sponsor - DMS' : 'Add Sponsor - DMS')

@php
    $saveUrl = $sponsor
        ? route('clean.console.sponsors.update', $sponsor->id)
        : route('clean.console.sponsors.store');
    $testUrl = $sponsor
        ? route('clean.console.sponsors.test-existing-connection', $sponsor->id)
        : route('clean.console.sponsors.test-new-connection');
    $saveMethod = $sponsor ? 'PUT' : 'POST';
@endphp

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">{{ $sponsor ? 'EDIT SPONSOR' : 'ADD SPONSOR' }}@if ($sponsor) <span class="text-info">{{ $sponsor->id }} | {{ $sponsor->name }}</span>@endif</h3>
        </div>
        <div class="block-content">
            <div class="row justify-content-center py-1">
                <div class="col-sm-10">
                    <div id="sponsor-result" class="alert d-none" role="status" aria-live="polite"></div>
                    <form id="sponsor-form" data-test-url="{{ $testUrl }}" novalidate>
                        <div class="form-group">
                            <label for="name">Name:</label>
                            <input type="text" class="form-control form-control-alt" id="name" name="name" value="{{ old('name', $sponsor?->name) }}" placeholder="Enter Sponsor Name ..." minlength="2" maxlength="255" required>
                        </div>
                        <div class="form-group">
                            <label for="affiliate_id">Affiliate ID:</label>
                            <input type="text" class="form-control form-control-alt" id="affiliate_id" name="affiliate_id" value="{{ old('affiliate_id', $sponsor?->affiliate_id) }}" placeholder="Enter Affiliate ID ..." maxlength="255">
                        </div>
                        <div class="form-group">
                            <label for="url">Sponsor URL:</label>
                            <input type="url" class="form-control form-control-alt" id="url" name="url" value="{{ old('url', $sponsor?->url) }}" placeholder="https://network.example" minlength="4" maxlength="255" required>
                        </div>
                        <div class="form-group">
                            <label for="username">Username:</label>
                            <input type="text" class="form-control form-control-alt" id="username" name="username" value="{{ old('username', $sponsor?->username) }}" autocomplete="username" minlength="4" maxlength="255" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Password{{ $sponsor ? ' (leave blank to keep the saved password)' : '' }}:</label>
                            <input type="password" class="form-control form-control-alt" id="password" name="password" autocomplete="{{ $sponsor ? 'new-password' : 'new-password' }}" minlength="4" maxlength="255" @required(! $sponsor)>
                        </div>
                        <div class="form-group">
                            <label for="api_key">API Key{{ $sponsor ? ' (leave blank to keep the saved key)' : '' }}:</label>
                            <textarea class="form-control form-control-alt" id="api_key" name="api_key" rows="3" autocomplete="off" minlength="4" maxlength="9999" @required(! $sponsor)></textarea>
                            <small class="form-text text-muted">Test Connection sends a read-only API request. It does not save offers, conversions, or test results.</small>
                        </div>
                        <div class="form-group">
                            <label for="type">Sponsor Type:</label>
                            <select class="form-control selectpicker" id="type" name="type" title="Select Sponsor Type ..." data-live-search="true" required>
                                @foreach ($types as $type)
                                    <option value="{{ $type }}" @selected(old('type', $sponsor?->type) === $type)>{{ $type }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <div class="d-flex flex-wrap justify-content-between">
                                <button id="test-sponsor" class="btn btn-alt-primary mb-2" type="button">TEST CONNECTION</button>
                                <button id="save-sponsor" class="btn {{ $sponsor ? 'btn-warning' : 'btn-info' }} mb-2" type="submit">{{ $sponsor ? 'EDIT' : 'SAVE' }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const form = document.querySelector('#sponsor-form');
            const button = document.querySelector('#save-sponsor');
            const result = document.querySelector('#sponsor-result');
            const testButton = document.querySelector('#test-sponsor');
            const isEditing = @json((bool) $sponsor);
            const saveUrl = @json($saveUrl);

            testButton.addEventListener('click', async () => {
                const originalText = testButton.textContent;
                testButton.disabled = true;
                testButton.textContent = 'TESTING…';
                result.className = 'alert d-none';
                result.textContent = '';
                const payload = Object.fromEntries(new FormData(form).entries());

                try {
                    const response = await fetch(form.dataset.testUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({
                            affiliate_id: payload.affiliate_id || null,
                            url: payload.url,
                            api_key: payload.api_key || '',
                            type: payload.type,
                        }),
                    });
                    const body = await response.json();
                    if (!response.ok) {
                        const messages = Object.values(body.errors ?? {}).flat();
                        throw new Error(messages.join(' ') || body.message || 'Connection check could not be completed.');
                    }
                    result.className = `alert alert-${body.success ? 'success' : 'danger'}`;
                    result.textContent = body.message;
                } catch (error) {
                    result.className = 'alert alert-danger';
                    result.textContent = error.message || 'Connection check could not be completed.';
                } finally {
                    testButton.disabled = false;
                    testButton.textContent = originalText;
                }
            });

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                button.disabled = true;
                button.textContent = isEditing ? 'UPDATING' : 'SAVING';
                result.className = 'alert alert-danger d-none';
                result.textContent = '';
                const payload = Object.fromEntries(new FormData(form).entries());
                payload.affiliate_id = payload.affiliate_id || null;
                if (isEditing && !payload.password) delete payload.password;
                if (isEditing && !payload.api_key) delete payload.api_key;

                try {
                    const response = await fetch(saveUrl, {
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
                        const messages = Object.values(body.errors ?? {}).flat();
                        throw new Error(messages.join(' ') || body.message || 'Sponsor could not be saved.');
                    }
                    if (window.parent !== window && typeof window.parent.closeModal === 'function') {
                        const closeModal = () => window.parent.closeModal();
                        if (window.parent.App?.swalPromise) {
                            window.parent.App.swalPromise({ icon: 'success', title: body.data, confirmButtonText: 'close' }).then(closeModal);
                        } else {
                            closeModal();
                        }
                    } else {
                        window.location.assign(@json(route('clean.console.sponsors.index')));
                    }
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
