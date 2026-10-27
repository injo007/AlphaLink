@extends('layouts.dms-reference')

@php
    $saveUrl = $bulk
        ? route('console.sponsors.bulk-users.update', $workspace->id)
        : route('console.sponsors.users.update', [$workspace->id, $sponsor->id]);
@endphp

@section('title', $bulk ? 'Manage Sponsor Users - DMS' : 'Manage Users Sponsor - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">
                @if ($bulk)
                    Allowed Mailers for {{ $sponsors->count() }} Sponsors
                @else
                    Sponsor: <span class="text-info">{{ $sponsor->name }}</span>
                @endif
            </h3>
        </div>
        <div class="block-content">
            <div class="row justify-content-center py-2">
                <div class="col-sm-10">
                    <div id="users-result" class="alert alert-danger d-none" role="alert" aria-live="assertive"></div>
                    <div class="form-group">
                        <label>
                            Allowed Mailers:
                            <button id="toggle-all" class="btn btn-link btn-sm" type="button">CHECK <span class="fas fa-check"></span></button>
                        </label>
                        <div class="row">
                            @foreach ($users->chunk(2) as $chunk)
                                <div class="col-sm-12 col-md-6">
                                    @foreach ($chunk as $user)
                                        <div class="custom-control custom-checkbox mb-1">
                                            <input type="checkbox" value="{{ $user->id }}" class="custom-control-input" id="allowed-user-{{ $user->id }}" name="checked_users" @checked(!$bulk && in_array((int) $user->id, array_map('intval', $sponsor->allowed_users ?? []), true))>
                                            <label class="custom-control-label" for="allowed-user-{{ $user->id }}">{{ $user->username ? $user->username.' | ' : '' }}{{ $user->name }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="form-group">
                        <button id="update-users" class="btn btn-block btn-info" type="button">UPDATE ALLOWED USERS</button>
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
            const checkboxes = [...document.querySelectorAll('input[name="checked_users"]')];
            const toggle = document.querySelector('#toggle-all');
            const button = document.querySelector('#update-users');
            const result = document.querySelector('#users-result');
            const sponsorIds = @json($sponsors->pluck('id')->map(fn ($id) => (int) $id)->values());
            const bulk = @json($bulk);
            const saveUrl = @json($saveUrl);

            toggle.addEventListener('click', () => {
                applyDmsCheckAll(checkboxes);
            });

            button.addEventListener('click', async () => {
                button.disabled = true;
                button.textContent = 'UPDATING';
                result.classList.add('d-none');
                result.textContent = '';
                const payload = {
                    users: checkboxes.filter((checkbox) => checkbox.checked).map((checkbox) => Number(checkbox.value)),
                };
                if (bulk) payload.sponsors = sponsorIds;

                try {
                    const response = await fetch(saveUrl, {
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
                        const messages = Object.values(body.errors ?? {}).flat();
                        throw new Error(messages.join(' ') || body.message || 'Sponsor access could not be updated.');
                    }
                    if (window.parent !== window && typeof window.parent.closeModal === 'function') {
                        const closeModal = () => window.parent.closeModal();
                        if (window.parent.App?.swalPromise) {
                            window.parent.App.swalPromise({ icon: 'success', title: body.data, confirmButtonText: 'close' }).then(closeModal);
                        } else {
                            closeModal();
                        }
                    } else {
                        window.location.assign(@json(route('console.sponsors.index', $workspace->id)));
                    }
                } catch (error) {
                    result.textContent = error.message;
                    result.classList.remove('d-none');
                } finally {
                    button.disabled = false;
                    button.textContent = 'UPDATE ALLOWED USERS';
                }
            });
        })();
    </script>
@endpush
