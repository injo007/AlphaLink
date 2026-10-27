@extends('layouts.dms-admin')

@section('title', 'Mix History - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">MIX HISTORY</h3>
            <div class="block-options">
                <a class="btn btn-sm btn-alt-primary" href="{{ route('console.audience-mixes.index', $workspace->id) }}">CREATE MIX</a>
            </div>
        </div>
        <div class="block-content block-content-full">
            <form id="mix-history-dates" method="get" class="form-inline mb-3">
                <div class="form-group mr-2 mb-2">
                    <label for="mix-history-from" class="mr-2">From</label>
                    <input id="mix-history-from" name="from" type="date" class="form-control" value="{{ $from }}" required>
                </div>
                <div class="form-group mr-2 mb-2">
                    <label for="mix-history-to" class="mr-2">To</label>
                    <input id="mix-history-to" name="to" type="date" class="form-control" value="{{ $to }}" required>
                </div>
                <button type="submit" class="btn btn-alt-primary mb-2">FILTER</button>
            </form>
            <div id="mix-history-status" class="sr-only" role="status" aria-live="polite"></div>
            <div class="table-responsive">
                <table class="table table-sm table-striped table-bordered table-hover mb-0">
                    <thead>
                        <tr>
                            <th scope="col">CREATED</th>
                            <th scope="col">MIX</th>
                            <th scope="col">ISP</th>
                            <th scope="col">RECIPIENTS</th>
                            <th scope="col">CRITERIA</th>
                            <th scope="col">STATUS</th>
                            <th scope="col">ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($mixes as $mix)
                            <tr>
                                <td>{{ $mix->created_at?->format('Y-m-d H:i:s') }}</td>
                                <th scope="row">{{ $mix->name }}</th>
                                <td>{{ $mix->isp?->name ?? '—' }}</td>
                                <td class="text-right">{{ number_format($mix->recipient_count) }}</td>
                                <td>
                                    <details>
                                        <summary>View saved criteria</summary>
                                        <pre class="mb-0 mt-2 text-monospace" style="white-space: pre-wrap">{{ json_encode($mix->criteria, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                </td>
                                <td>
                                    @if ($mix->status === 'active')
                                        <span class="badge badge-success">ACTIVE</span>
                                    @else
                                        <span class="badge badge-secondary">EXPIRED</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($mix->status === 'expired')
                                        <button type="button" class="btn btn-sm btn-alt-info remix-mix" data-url="{{ route('console.audience-mixes.remix', [$workspace->id, $mix->id]) }}">
                                            <i class="fas fa-sync mr-1" aria-hidden="true"></i>REMIX
                                        </button>
                                    @else
                                        <span class="text-muted" title="This snapshot can be used until DMS Mix cleanup expires it.">READY</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No mixes were created in this date range.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $mixes->links() }}</div>
        </div>
    </div>
    <script>
        (() => {
            const dateForm = document.getElementById('mix-history-dates');
            const from = document.getElementById('mix-history-from');
            const to = document.getElementById('mix-history-to');
            const status = document.getElementById('mix-history-status');
            const prefix = `audience_mix_history_${@json((string) $workspace->id)}`;
            const params = new URLSearchParams(window.location.search);
            if (!params.has('from') && !params.has('to') && sessionStorage.getItem(`${prefix}_from`) && sessionStorage.getItem(`${prefix}_to`)) {
                from.value = sessionStorage.getItem(`${prefix}_from`);
                to.value = sessionStorage.getItem(`${prefix}_to`);
                dateForm.requestSubmit();
            }
            dateForm.addEventListener('submit', () => {
                sessionStorage.setItem(`${prefix}_from`, from.value);
                sessionStorage.setItem(`${prefix}_to`, to.value);
            });
            document.querySelectorAll('.remix-mix').forEach((button) => button.addEventListener('click', async () => {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                button.disabled = true;
                button.textContent = 'REMIXING…';
                status.textContent = 'Rebuilding the saved audience mix.';
                try {
                    const response = await fetch(button.dataset.url, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf || '' },
                        body: JSON.stringify({}),
                    });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.data || result.message || 'Mix could not be rebuilt.');
                    status.textContent = `${result.data}.`;
                    window.location.reload();
                } catch (error) {
                    status.textContent = error.message || 'Mix could not be rebuilt.';
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-sync mr-1" aria-hidden="true"></i>REMIX';
                }
            }));
        })();
    </script>
@endsection
