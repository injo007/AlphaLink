@extends('layouts.standalone')

@php
    $saveUrl = $bulk
        ? route('console.sponsors.bulk-users.update', $workspace->id)
        : route('console.sponsors.users.update', [$workspace->id, $sponsor->id]);
@endphp

@section('title', $bulk ? 'Manage Sponsor Users' : 'Manage Users Sponsor')

@section('content')
<div class="command-os-settings-card m-2 p-3 p-md-4">
    <header class="command-os-card-header mb-3">
        <div class="command-os-card-title">
            <span class="command-os-card-icon icon-purple" aria-hidden="true"><i class="fas fa-users-cog"></i></span>
            <div>
                <h2>@if ($bulk) BULK ASSIGN MAIL USERS ({{ $sponsors->count() }} SPONSORS) @else ASSIGN USERS: {{ $sponsor->name }} @endif</h2>
                <p>Authorize mailers to operate campaigns and access offers belonging to this sponsor</p>
            </div>
        </div>
    </header>

    <div id="users-result" class="alert alert-danger d-none py-2 font-size-sm mb-3" role="alert" aria-live="assertive"></div>

    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
        <span class="font-size-xs font-w700 text-uppercase text-muted"><i class="fas fa-user-check text-purple mr-1"></i> Authorized Mailers:</span>
        <button id="toggle-all" class="command-action-btn action-info px-3" type="button" aria-pressed="false" style="font-size: 11px; width: auto; height: 28px;">
            <i class="fas fa-check-double mr-1"></i> CHECK ALL
        </button>
    </div>

    @if ($users->isEmpty())
        <p class="text-muted font-size-sm">There are no operator members in this workspace to assign.</p>
    @else
        <div class="row mb-4">
            @foreach ($users as $user)
                <div class="col-12 col-md-6 mb-2">
                    <label class="d-flex align-items-center p-2 rounded border" style="background: var(--cv-surface-2); cursor: pointer;" for="allowed-user-{{ $user->id }}">
                        <input type="checkbox" value="{{ $user->id }}" class="mr-2" id="allowed-user-{{ $user->id }}" name="checked_users" @checked(!$bulk && in_array((int) $user->id, array_map('intval', $sponsor->allowed_users ?? []), true))>
                        <span class="font-size-sm text-ink font-w600">{{ $user->name }}</span>
                        @if ($user->username)
                            <span class="font-size-xs text-muted ml-auto font-mono">{{ $user->username }}</span>
                        @endif
                    </label>
                </div>
            @endforeach
        </div>
    @endif

    <button id="update-users" class="command-os-btn-filter w-100" type="button">
        <i class="fas fa-save mr-2"></i>SAVE ALLOWED USERS
    </button>
</div>
@endsection

@push('scripts')
    <script type="module">
        import { applySelectAll } from @json(asset('alphalink/infrastructure/isp-assignment.js'));

        (() => {
            const checkboxes = [...document.querySelectorAll('input[name="checked_users"]')];
            const toggle = document.querySelector('#toggle-all');
            const button = document.querySelector('#update-users');
            const result = document.querySelector('#users-result');
            const sponsorIds = @json($sponsors->pluck('id')->map(fn ($id) => (int) $id)->values());
            const bulk = @json($bulk);
            const saveUrl = @json($saveUrl);

            toggle.addEventListener('click', () => {
                applySelectAll(checkboxes);
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
