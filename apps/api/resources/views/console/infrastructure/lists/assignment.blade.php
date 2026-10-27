@extends('layouts.dms-reference')

@php
    $isUsers = $type === 'users';
    $field = $isUsers ? 'users' : 'sponsors';
    $title = $isUsers ? 'Allowed Mailers' : 'Allowed Sponsors';
    $bulk = isset($lists);
    $saveUrl = $bulk
        ? route('console.infrastructure.lists.bulk-assignment.update', [$workspace->id, $type])
        : route('console.infrastructure.lists.assignment.update', [$workspace->id, $list->id, $type]);
    $selectedListIds = $bulk ? $lists->modelKeys() : [];
@endphp

@section('title', $title.' - DMS')

@section('content')
    <div class="block block-rounded mb-0">
        <div class="block-header block-header-default">
            <h3 class="block-title">{{ $bulk ? 'Selected Lists' : 'List' }} <span class="text-info">{{ $bulk ? $lists->count().' lists' : $list->name }}</span> {{ $isUsers ? 'Users' : 'Allowed Sponsors' }}</h3>
        </div>
        <div class="block-content">
            <div id="assignment-result" class="alert alert-danger d-none" role="alert" aria-live="assertive"></div>
            @if ($bulk && $mixedSelection)
                <div class="alert alert-warning">The selected lists currently have different {{ strtolower($title) }} assignments. Choose the complete replacement set below before saving.</div>
            @endif
            <div class="form-group">
                <label>{{ $title }}: <button id="toggle-all" class="btn btn-link btn-sm" type="button" aria-pressed="false">CHECK <span class="fas fa-check"></span></button></label>
                @if ($items->isEmpty())
                    <p class="text-muted">{{ $isUsers ? 'There are no operator members in this workspace to assign.' : 'There are no active sponsors to assign.' }}</p>
                @else
                    <div class="row">
                        @foreach ($items->chunk(2) as $chunk)
                            <div class="col-sm-12 col-md-6">
                                @foreach ($chunk as $item)
                                    <div class="custom-control custom-checkbox mb-1">
                                        <input type="checkbox" value="{{ $item->id }}" class="custom-control-input" id="assignment-{{ $field }}-{{ $item->id }}" name="checked_items" @checked(in_array((int) $item->id, array_map('intval', $selectedIds), true))>
                                        <label class="custom-control-label" for="assignment-{{ $field }}-{{ $item->id }}">
                                            @if ($isUsers)
                                                {{ $item->name }} | {{ $item->email }}
                                            @else
                                                {{ $item->name }}@if ($item->affiliate_id) | {{ $item->affiliate_id }}@endif
                                            @endif
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="form-group mb-3"><button id="update-assignment" class="btn btn-block btn-info" type="button">UPDATE {{ strtoupper($title) }}</button></div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const checkboxes = [...document.querySelectorAll('input[name="checked_items"]')];
            const toggle = document.querySelector('#toggle-all');
            const button = document.querySelector('#update-assignment');
            const result = document.querySelector('#assignment-result');
            toggle.addEventListener('click', () => {
                const shouldCheck = checkboxes.some((checkbox) => !checkbox.checked);
                checkboxes.forEach((checkbox) => { checkbox.checked = shouldCheck; });
                toggle.setAttribute('aria-pressed', String(shouldCheck));
                toggle.innerHTML = shouldCheck ? 'UNCHECK <span class="fas fa-times"></span>' : 'CHECK <span class="fas fa-check"></span>';
            });
            button.addEventListener('click', async () => {
                button.disabled = true;
                result.classList.add('d-none');
                try {
                    const payload = { [@json($field)]: checkboxes.filter((checkbox) => checkbox.checked).map((checkbox) => Number(checkbox.value)) };
                    @if ($bulk)
                        payload.lists = @json(array_values($selectedListIds));
                    @endif
                    const response = await fetch(@json($saveUrl), {
                        method: 'PUT',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify(payload),
                    });
                    const body = await response.json();
                    if (!response.ok) {
                        const messages = Object.values(body.errors || {}).flat();
                        throw new Error(messages.join(' ') || body.message || body.data || 'Assignment could not be saved.');
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
