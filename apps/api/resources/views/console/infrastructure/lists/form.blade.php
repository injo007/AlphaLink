@extends('layouts.standalone')

@php
    $editing = $list !== null;
    $saveUrl = $editing
        ? route('clean.console.infrastructure.lists.index').'/'.$list->id
        : route('clean.console.infrastructure.isps.index').'/'.$isp->id.'/lists';
@endphp

@section('title', ($editing ? 'Update' : 'Add').' List')

@section('content')
    <div class="command-modal-surface p-4" style="min-height: 100vh;">
        <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
            <div class="d-flex align-items-center">
                <i class="fas fa-list-ul text-purple mr-2" style="font-size: 1.25rem;"></i>
                <h3 class="m-0 font-w700 font-size-base text-uppercase" style="letter-spacing: 0.04em;">
                    {{ $editing ? 'UPDATE LIST' : 'ADD NEW LIST' }}
                    <span class="badge-command-pill ml-2">{{ $isp->name }}</span>
                </h3>
            </div>
            <span class="badge-command-pill">RECIPIENT INVENTORY</span>
        </div>

        <div class="row justify-content-center">
            <div class="col-12 col-md-10">
                <div id="list-form-result" class="alert alert-danger d-none py-2 px-3 mb-3 font-size-sm" role="alert" aria-live="assertive" style="border-radius: 10px;"></div>

                <div class="form-group mb-3">
                    <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1" for="list-name">
                        <span class="text-danger mr-1">*</span>List Name:
                    </label>
                    <input id="list-name" class="form-control" name="name" type="text" required minlength="2" maxlength="160" pattern="[A-Za-z0-9_-]+" value="{{ old('name', $list?->name) }}" autocomplete="off" placeholder="e.g. gmail_us_active_01" style="height: 42px; border-radius: 10px; font-size: 12.5px;">
                    <small class="form-text text-muted">Use alphanumeric characters, underscores, and dashes only.</small>
                </div>

                <div class="form-group mb-4">
                    <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1" for="list-country">
                        Target Country:
                    </label>
                    <select id="list-country" class="form-control" name="country" style="height: 42px; border-radius: 10px; font-size: 12.5px;">
                        <option value="">GLOBAL (No country filter)</option>
                        @foreach (($isp->tracked_countries ?? []) as $country)
                            <option value="{{ $country }}" @selected(old('country', $list?->country) === $country)>{{ $country }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="d-flex justify-content-end pt-3 border-top" style="gap: 12px;">
                    <button id="save-list" class="btn-command-primary" type="button" style="padding: 10px 24px;">
                        <i class="fas fa-save mr-1"></i> {{ $editing ? 'UPDATE LIST' : 'ADD LIST' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const button = document.querySelector('#save-list');
            const result = document.querySelector('#list-form-result');
            button.addEventListener('click', async () => {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> ' + @json($editing ? 'UPDATING...' : 'SAVING...');
                result.classList.add('d-none');
                try {
                    const response = await fetch(@json($saveUrl), {
                        method: @json($editing ? 'PUT' : 'POST'),
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({
                            name: document.querySelector('#list-name').value,
                            country: document.querySelector('#list-country').value || null,
                        }),
                    });
                    const body = await response.json();
                    if (!response.ok) {
                        const messages = Object.values(body.errors || {}).flat();
                        throw new Error(messages.join(' ') || body.message || body.data || 'List could not be saved.');
                    }
                    if (window.parent?.closeModal) window.parent.closeModal();
                } catch (error) {
                    result.textContent = error.message;
                    result.classList.remove('d-none');
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-save mr-1"></i> ' + @json($editing ? 'UPDATE LIST' : 'ADD LIST');
                }
            });
        })();
    </script>
@endpush
