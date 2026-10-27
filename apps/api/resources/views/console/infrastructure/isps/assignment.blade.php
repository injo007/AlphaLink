@extends('layouts.dms-reference')

@php
    $isUsers = $type === 'users';
    $field = $isUsers ? 'users' : 'sponsors';
    $title = $isUsers ? 'Allowed Mailers' : 'Allowed Sponsors';
    $saveUrl = $isUsers
        ? route('console.infrastructure.isps.users.update', [$workspace->id, $isp->id])
        : route('console.infrastructure.isps.sponsors.update', [$workspace->id, $isp->id]);
@endphp

@section('title', $title.' - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">{{ $isUsers ? 'ISP' : 'ISP' }} <span class="text-info">{{ $isp->name }}</span> {{ $isUsers ? 'Users' : ', allowed Sponsors' }}</h3>
        </div>
        <div class="block-content">
            <div class="row justify-content-center py-2">
                <div class="col-sm-10">
                    <div id="assignment-result" class="alert alert-danger d-none" role="alert" aria-live="assertive"></div>
                    <div class="form-group">
                        <label>
                            {{ $title }}:
                            <button id="toggle-all" class="btn btn-link btn-sm" type="button" aria-pressed="false">CHECK <span class="fas fa-check"></span></button>
                        </label>
                        <div class="row">
                            @foreach ($items->chunk(2) as $chunk)
                                <div class="col-sm-12 col-md-6">
                                    @foreach ($chunk as $item)
                                        <div class="custom-control custom-checkbox mb-1">
                                            <input type="checkbox" value="{{ $item->id }}" class="custom-control-input" id="assignment-{{ $field }}-{{ $item->id }}" name="checked_items" @checked(in_array((int) $item->id, array_map('intval', $selectedIds), true))>
                                            <label class="custom-control-label" for="assignment-{{ $field }}-{{ $item->id }}">
                                                @if ($isUsers)
                                                    {{ $item->username ? $item->username.' | ' : '' }}{{ $item->name }}
                                                @else
                                                    {{ $item->name }}@if ($item->affiliate_id) | {{ $item->affiliate_id }}@endif
                                                @endif
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="form-group">
                        <button id="update-assignment" class="btn btn-block btn-info" type="button">UPDATE {{ strtoupper($title) }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script type="module">
        import { applyDmsCheckAll } from @json(asset('alphalink/infrastructure/isp-assignment.js'));

        (() => {
            const checkboxes = [...document.querySelectorAll('input[name="checked_items"]')];
            const toggle = document.querySelector('#toggle-all');
            const button = document.querySelector('#update-assignment');
            const result = document.querySelector('#assignment-result');
            const saveUrl = @json($saveUrl);
            const field = @json($field);

            toggle.addEventListener('click', () => {
                applyDmsCheckAll(checkboxes);
            });

            button.addEventListener('click', async () => {
                button.disabled = true;
                button.textContent = 'UPDATING';
                result.classList.add('d-none');
                result.textContent = '';

                try {
                    const response = await fetch(saveUrl, {
                        method: 'PUT',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ [field]: checkboxes.filter((checkbox) => checkbox.checked).map((checkbox) => Number(checkbox.value)) }),
                    });
                    const body = await response.json();
                    if (!response.ok) {
                        const messages = Object.values(body.errors ?? {}).flat();
                        throw new Error(messages.join(' ') || body.message || 'ISP assignment could not be updated.');
                    }
                    if (window.parent !== window && typeof window.parent.closeModal === 'function') {
                        const closeModal = () => window.parent.closeModal();
                        if (window.parent.App?.swalPromise) {
                            window.parent.App.swalPromise({ icon: 'success', title: body.data, confirmButtonText: 'close' }).then(closeModal);
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
                    button.textContent = @json('UPDATE '.strtoupper($title));
                }
            });
        })();
    </script>
@endpush
