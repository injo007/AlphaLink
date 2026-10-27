@extends('layouts.dms-reference')

@php
    $saveUrl = $bulk ? route('console.offers.bulk-users.update', $workspace->id) : route('console.offers.users.update', [$workspace->id, $offer->id]);
@endphp

@section('title', 'Manage Offer Users - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default"><h3 class="block-title">@if ($bulk) Allowed Mailers for {{ $offers->count() }} Offers @else Offer: <span class="text-info">{{ $offer->name }}</span>@endif</h3></div>
        <div class="block-content">
            <div class="row justify-content-center py-2"><div class="col-sm-10">
                <div class="alert alert-info" role="status">Offer access controls which operators can use this offer. DMS sponsor-level assignments are propagated to offers by the single-sponsor assignment action.</div>
                <div id="users-result" class="alert alert-danger d-none" role="alert" aria-live="assertive"></div>
                <div class="form-group"><label>Allowed Mailers: <button id="toggle-all" class="btn btn-link btn-sm" type="button" aria-pressed="false">CHECK <span class="fas fa-check"></span></button></label>
                    @if ($users->isEmpty())<p class="text-muted">There are no operator members in this workspace to assign.</p>@else
                        <div class="row">@foreach ($users->chunk(2) as $chunk)<div class="col-sm-12 col-md-6">@foreach ($chunk as $user)<div class="custom-control custom-checkbox mb-1"><input type="checkbox" value="{{ $user->id }}" class="custom-control-input" id="allowed-user-{{ $user->id }}" name="checked_users" @checked($offers->every(fn ($item) => in_array($user->id, $item->allowed_users ?? [])))><label class="custom-control-label" for="allowed-user-{{ $user->id }}">{{ $user->name }} | {{ $user->email }}</label></div>@endforeach</div>@endforeach</div>
                    @endif
                </div>
                <button id="update-users" class="btn btn-block btn-info" type="button">UPDATE ALLOWED USERS</button>
            </div></div>
        </div>
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
