@extends('layouts.standalone')

@php
    $isUsers = $type === 'users';
    $field = $isUsers ? 'users' : 'sponsors';
    $title = $isUsers ? 'Allowed Mailers' : 'Allowed Sponsors';
    $saveUrl = $isUsers
        ? route('clean.console.infrastructure.isps.index').'/'.$isp->id.'/users'
        : route('clean.console.infrastructure.isps.index').'/'.$isp->id.'/sponsors';
@endphp

@section('title', $title)

@section('content')
    <div class="command-modal-surface p-4" style="min-height: 100vh;">
        <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
            <div class="d-flex align-items-center">
                <i class="fas {{ $isUsers ? 'fa-users' : 'fa-hand-holding-usd' }} text-purple mr-2" style="font-size: 1.25rem;"></i>
                <h3 class="m-0 font-w700 font-size-base text-uppercase" style="letter-spacing: 0.04em;">
                    {{ $isp->name }} <span class="badge-command-pill ml-2">{{ $title }}</span>
                </h3>
            </div>
            <button id="toggle-all" class="btn-command-action" type="button" style="padding: 4px 12px; font-size: 11px;" aria-pressed="false">
                <i class="fas fa-check mr-1"></i> CHECK ALL
            </button>
        </div>

        <div class="row justify-content-center">
            <div class="col-12 col-md-11">
                <div id="assignment-result" class="alert alert-danger d-none py-2 px-3 mb-3 font-size-sm" role="alert" aria-live="assertive" style="border-radius: 10px;"></div>

                <div class="form-group mb-4">
                    <div class="row" style="gap: 8px 0;">
                        @foreach ($items->chunk(2) as $chunk)
                            <div class="col-sm-12 col-md-6">
                                @foreach ($chunk as $item)
                                    <div class="custom-control custom-checkbox p-2 mb-2 rounded" style="background: rgba(139, 92, 246, 0.04); border: 1px solid rgba(139, 92, 246, 0.15);">
                                        <input type="checkbox" value="{{ $item->id }}" class="custom-control-input" id="assignment-{{ $field }}-{{ $item->id }}" name="checked_items" @checked(in_array((int) $item->id, array_map('intval', $selectedIds), true))>
                                        <label class="custom-control-label font-w600" for="assignment-{{ $field }}-{{ $item->id }}" style="cursor: pointer; font-size: 12.5px;">
                                            @if ($isUsers)
                                                <span class="text-purple">{{ $item->username ? $item->username.' | ' : '' }}</span>{{ $item->name }}
                                            @else
                                                <span class="text-purple">{{ $item->name }}</span>@if ($item->affiliate_id) <small class="text-muted font-mono">({{ $item->affiliate_id }})</small>@endif
                                            @endif
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="d-flex justify-content-end pt-3 border-top" style="gap: 12px;">
                    <button id="update-assignment" class="btn-command-primary" type="button" style="padding: 10px 24px;">
                        <i class="fas fa-save mr-1"></i> UPDATE {{ strtoupper($title) }}
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script type="module">
        import { applySelectAll } from @json(asset('alphalink/infrastructure/isp-assignment.js'));

        (() => {
            const checkboxes = [...document.querySelectorAll('input[name="checked_items"]')];
            const toggle = document.querySelector('#toggle-all');
            const button = document.querySelector('#update-assignment');
            const result = document.querySelector('#assignment-result');
            const saveUrl = @json($saveUrl);
            const field = @json($field);

            toggle.addEventListener('click', () => {
                applySelectAll(checkboxes);
                const allChecked = checkboxes.every((c) => c.checked);
                toggle.innerHTML = allChecked ? '<i class="fas fa-times mr-1"></i> UNCHECK ALL' : '<i class="fas fa-check mr-1"></i> CHECK ALL';
            });

            button.addEventListener('click', async () => {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> UPDATING...';
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
                        window.location.assign(@json(route('clean.console.infrastructure.isps.index')));
                    }
                } catch (error) {
                    result.textContent = error.message;
                    result.classList.remove('d-none');
                } finally {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-save mr-1"></i> ' + @json('UPDATE '.strtoupper($title));
                }
            });
        })();
    </script>
@endpush
