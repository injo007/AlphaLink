@extends('layouts.standalone')

@section('title', $sponsor ? 'Edit Sponsor' : 'Add Sponsor')

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
    <div class="command-os-settings-card m-0" style="border-radius: 0; min-height: 100vh;">
        <div class="command-os-settings-card-header">
            <div class="command-os-settings-card-title-group">
                <div class="command-os-settings-card-icon">
                    <i class="fas fa-building"></i>
                </div>
                <div class="command-os-settings-card-titles">
                    <h3>{{ $sponsor ? 'EDIT SPONSOR' : 'ADD NEW SPONSOR' }}</h3>
                    <p>Affiliate network endpoint, authentication parameters, and API access credentials</p>
                </div>
            </div>
            @if ($sponsor)
                <span class="badge badge-purple px-2 py-1 font-mono font-w700">#{{ $sponsor->id }} &bull; {{ $sponsor->name }}</span>
            @else
                <span class="command-os-settings-card-tag">NEW GATEWAY</span>
            @endif
        </div>
        <div class="command-os-settings-card-body p-4">
            <div id="sponsor-result" class="alert d-none mb-3" role="status" aria-live="polite"></div>
            <form id="sponsor-form" data-test-url="{{ $testUrl }}" novalidate>
                <div class="row">
                    <div class="col-sm-12 col-md-6">
                        <div class="form-group command-os-field-group">
                            <label for="name">
                                <span><i class="fas fa-building command-field-icon"></i> Sponsor Name:</span>
                            </label>
                            <div class="command-input-wrap">
                                <span class="command-input-leading-icon"><i class="fas fa-building"></i></span>
                                <input type="text" class="form-control command-input-with-icon" id="name" name="name" value="{{ old('name', $sponsor?->name) }}" placeholder="Enter Sponsor Name ..." minlength="2" maxlength="255" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-12 col-md-6">
                        <div class="form-group command-os-field-group">
                            <label for="affiliate_id">
                                <span><i class="fas fa-id-badge command-field-icon"></i> Affiliate ID:</span>
                            </label>
                            <div class="command-input-wrap">
                                <span class="command-input-leading-icon"><i class="fas fa-id-badge"></i></span>
                                <input type="text" class="form-control command-input-with-icon" id="affiliate_id" name="affiliate_id" value="{{ old('affiliate_id', $sponsor?->affiliate_id) }}" placeholder="Enter Affiliate ID ..." maxlength="255">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-sm-12 col-md-6">
                        <div class="form-group command-os-field-group">
                            <label for="url">
                                <span><i class="fas fa-link command-field-icon"></i> Sponsor URL:</span>
                            </label>
                            <div class="command-input-wrap">
                                <span class="command-input-leading-icon"><i class="fas fa-link"></i></span>
                                <input type="url" class="form-control command-input-with-icon" id="url" name="url" value="{{ old('url', $sponsor?->url) }}" placeholder="https://network.example" minlength="4" maxlength="255" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-12 col-md-6">
                        <div class="form-group command-os-field-group">
                            <label for="type">
                                <span><i class="fas fa-layer-group command-field-icon"></i> Sponsor Type:</span>
                            </label>
                            <div class="command-input-wrap">
                                <span class="command-input-leading-icon"><i class="fas fa-layer-group"></i></span>
                                <select class="form-control command-os-custom-select" id="type" name="type" required>
                                    @foreach ($types as $type)
                                        <option value="{{ $type }}" @selected(old('type', $sponsor?->type) === $type)>{{ $type }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-sm-12 col-md-6">
                        <div class="form-group command-os-field-group">
                            <label for="username">
                                <span><i class="fas fa-user command-field-icon"></i> Username:</span>
                            </label>
                            <div class="command-input-wrap">
                                <span class="command-input-leading-icon"><i class="fas fa-user"></i></span>
                                <input type="text" class="form-control command-input-with-icon" id="username" name="username" value="{{ old('username', $sponsor?->username) }}" autocomplete="username" minlength="4" maxlength="255" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-12 col-md-6">
                        <div class="form-group command-os-field-group">
                            <label for="password">
                                <span><i class="fas fa-lock command-field-icon"></i> Password:</span>
                            </label>
                            <div class="command-input-wrap">
                                <span class="command-input-leading-icon"><i class="fas fa-lock"></i></span>
                                <input type="password" class="form-control command-input-with-icon" id="password" name="password" autocomplete="new-password" placeholder="{{ $sponsor ? 'Leave blank to keep saved' : 'Enter password...' }}" minlength="4" maxlength="255" @required(! $sponsor)>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <div class="form-group command-os-field-group">
                            <label for="api_key">
                                <span><i class="fas fa-key command-field-icon"></i> API Key:</span>
                            </label>
                            <textarea class="form-control font-mono" id="api_key" name="api_key" rows="3" autocomplete="off" placeholder="{{ $sponsor ? 'Leave blank to keep saved API key' : 'Paste API Key / Session Token...' }}" minlength="4" maxlength="9999" style="border-radius: 10px; font-size: 12px;" @required(! $sponsor)></textarea>
                            <small class="form-text text-muted mt-1"><i class="fas fa-info-circle mr-1"></i> Test Connection sends a read-only API request. It does not mutate offers, conversions, or test results.</small>
                        </div>
                    </div>
                </div>

                <hr class="command-os-divider my-3">

                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mt-4">
                    <button id="test-sponsor" class="btn btn-outline-primary px-4 py-2 font-w700" type="button" style="border-radius: 10px; font-size: 12px; letter-spacing: 0.05em;">
                        <i class="fas fa-plug mr-2"></i> TEST CONNECTION
                    </button>
                    <button id="save-sponsor" class="btn btn-primary command-os-btn-save-settings" type="submit" style="width: auto; padding: 10px 28px !important;">
                        <i class="fas fa-check mr-2"></i> {{ $sponsor ? 'UPDATE SPONSOR' : 'SAVE SPONSOR' }}
                    </button>
                </div>
            </form>
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
                const originalHtml = testButton.innerHTML;
                testButton.disabled = true;
                testButton.innerHTML = '<span class="spinner-border spinner-border-sm mr-2" role="status"></span> TESTING…';
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
                            username: payload.username || null,
                            password: payload.password || '',
                            type: payload.type,
                        }),
                    });

                    const body = await response.json();
                    if (!response.ok) {
                        throw new Error(Object.values(body.errors ?? {}).flat().join(' ') || body.message || 'Connection test failed.');
                    }

                    result.className = 'alert alert-success';
                    result.textContent = body.message || 'Connection test successful.';
                } catch (error) {
                    result.className = 'alert alert-danger';
                    result.textContent = error.message;
                } finally {
                    testButton.disabled = false;
                    testButton.innerHTML = originalHtml;
                }
            });

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                button.disabled = true;
                const originalHtml = button.innerHTML;
                button.innerHTML = '<span class="spinner-border spinner-border-sm mr-2" role="status"></span> SAVING…';
                result.className = 'alert d-none';
                result.textContent = '';

                const formData = new FormData(form);
                const payload = {
                    name: formData.get('name')?.trim(),
                    affiliate_id: formData.get('affiliate_id')?.trim() || null,
                    url: formData.get('url')?.trim(),
                    username: formData.get('username')?.trim(),
                    type: formData.get('type'),
                };

                const password = formData.get('password');
                if (password || !isEditing) {
                    payload.password = password;
                }

                const apiKey = formData.get('api_key');
                if (apiKey || !isEditing) {
                    payload.api_key = apiKey;
                }

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
                        throw new Error(Object.values(body.errors ?? {}).flat().join(' ') || body.message || 'Could not save sponsor.');
                    }

                    if (window.parent !== window && typeof window.parent.closeModal === 'function') {
                        const close = () => window.parent.closeModal();
                        if (window.parent.App?.swalPromise) {
                            window.parent.App.swalPromise({ icon: 'success', title: body.data || 'Sponsor saved successfully.', confirmButtonText: 'close' }).then(close);
                        } else {
                            close();
                        }
                    } else {
                        window.location.assign(@json(route('clean.console.sponsors.index')));
                    }
                } catch (error) {
                    result.className = 'alert alert-danger';
                    result.textContent = error.message;
                } finally {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                }
            });
        })();
    </script>
@endpush
