@extends('layouts.dms-reference')

@php
    $editing = $list !== null;
    $saveUrl = $editing
        ? route('console.infrastructure.lists.update', [$workspace->id, $list->id])
        : route('console.infrastructure.lists.store', [$workspace->id, $isp->id]);
@endphp

@section('title', ($editing ? 'Update' : 'Add').' List - DMS')

@section('content')
    <div class="block block-rounded mb-0">
        <div class="block-header block-header-default"><h3 class="block-title">{{ $editing ? 'Update' : 'Add' }} List <span class="text-info">{{ $isp->name }}</span></h3></div>
        <div class="block-content">
            <div id="list-form-result" class="alert alert-danger d-none" role="alert" aria-live="assertive"></div>
            <div class="form-group">
                <label for="list-name">Name:</label>
                <input id="list-name" class="form-control" name="name" type="text" required minlength="2" maxlength="160" pattern="[A-Za-z0-9_-]+" value="{{ old('name', $list?->name) }}" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="list-country">Country:</label>
                <select id="list-country" class="form-control" name="country">
                    <option value="">None</option>
                    @foreach (($isp->tracked_countries ?? []) as $country)
                        <option value="{{ $country }}" @selected(old('country', $list?->country) === $country)>{{ $country }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group mb-3"><button id="save-list" class="btn btn-info btn-block" type="button">{{ $editing ? 'UPDATE LIST' : 'ADD LIST' }}</button></div>
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
                }
            });
        })();
    </script>
@endpush
