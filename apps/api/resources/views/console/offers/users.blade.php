@extends('layouts.standalone')

@php
    $saveUrl = $bulk ? route('console.offers.bulk-users.update', $workspace->id) : route('console.offers.users.update', [$workspace->id, $offer->id]);
@endphp

@section('title', 'Manage Offer Users')

@section('content')
<div class="command-os-settings-card m-2 p-3 p-md-4">
    <header class="command-os-card-header mb-3">
        <div class="command-os-card-title">
            <span class="command-os-card-icon icon-purple" aria-hidden="true"><i class="fas fa-users-cog"></i></span>
            <div>
                <h2>@if ($bulk) BULK ASSIGN MAIL USERS ({{ $offers->count() }} OFFERS) @else ASSIGN USERS: {{ $offer->name }} @endif</h2>
                <p>Configure which mailers have operational access to dispatch campaigns for this offer</p>
            </div>
        </div>
    </header>

    <div class="alert alert-info py-2 font-size-sm mb-3" role="status">
        <i class="fas fa-info-circle mr-1"></i> Offer access controls which operators can use this offer. Sponsor-level assignments are propagated to offers automatically.
    </div>

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
                        <input type="checkbox" value="{{ $user->id }}" class="mr-2" id="allowed-user-{{ $user->id }}" name="checked_users" @checked($offers->every(fn ($item) => in_array($user->id, $item->allowed_users ?? [])))>
                        <span class="font-size-sm text-ink font-w600">{{ $user->name }}</span>
                        <span class="font-size-xs text-muted ml-auto font-mono">{{ $user->email }}</span>
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
    <script>
        (() => {
            const checks = [...document.querySelectorAll('input[name="checked_users"]')];
            const button = document.querySelector('#update-users');
            const result = document.querySelector('#users-result');
            const offerIds = @json($offers->pluck('id')->map(fn ($id) => (int) $id)->values());
            const bulk = @json($bulk);
            document.querySelector('#toggle-all').addEventListener('click', (event) => {
                const checked = checks.some((input) => !input.checked);
                checks.forEach((input) => { input.checked = checked; });
                event.currentTarget.setAttribute('aria-pressed', String(checked));
                event.currentTarget.firstChild.textContent = checked ? 'UNCHECK ' : 'CHECK ';
            });
            button.addEventListener('click', async () => {
                button.disabled = true;
                const payload = { users: checks.filter((input) => input.checked).map((input) => Number(input.value)) };
                if (bulk) payload.offers = offerIds;
                try {
                    const response = await fetch(@json($saveUrl), { method: 'PUT', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify(payload) });
                    const body = await response.json();
                    if (!response.ok) throw new Error(Object.values(body.errors ?? {}).flat().join(' ') || body.message || 'Offer access could not be updated.');
                    if (window.parent !== window && typeof window.parent.closeModal === 'function') {
                        const close = () => window.parent.closeModal();
                        window.parent.App?.swalPromise ? window.parent.App.swalPromise({ icon: 'success', title: body.data, confirmButtonText: 'close' }).then(close) : close();
                    } else window.location.assign(@json(route('console.offers.index', $workspace->id)));
                } catch (error) {
                    result.textContent = error.message;
                    result.classList.remove('d-none');
                } finally { button.disabled = false; }
            });
        })();
    </script>
@endpush
