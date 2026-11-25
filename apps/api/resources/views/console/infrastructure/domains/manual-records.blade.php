@extends('layouts.standalone')

@section('title', 'Manual DNS Records')

@push('styles')
    <style>
        .manual-dns-records { min-width: 40rem; }
        .manual-dns-message { min-height: 1.5rem; }
        @media (max-width: 575.98px) { .manual-dns-records { min-width: 34rem; } }
    </style>
@endpush

@section('content')
    <div class="command-card">
        <div class="command-modal-hero">
            <div class="command-modal-hero-left">
                <div class="command-modal-hero-icon"><i class="fas fa-list-alt" aria-hidden="true"></i></div>
                <div>
                    <div class="command-modal-hero-eyebrow">MANUAL DNS // EXTERNAL DOMAIN</div>
                    <h1 class="command-modal-hero-title">REQUIRED RECORDS <span class="badge badge-purple ml-2">{{ $domain->name }}</span></h1>
                </div>
            </div>
            <div class="command-modal-hero-right">
                <a class="btn-command-action" href="{{ route('console.infrastructure.domains.index', $workspace->id) }}">
                    <i class="fas fa-arrow-left mr-1" aria-hidden="true"></i> BACK TO DOMAINS
                </a>
                @if($records !== [] || $pendingDnsPlans !== [])
                    <button class="btn-command-primary ml-2" type="button" id="copy-all-records">
                        <i class="fas fa-copy mr-1" aria-hidden="true"></i> COPY ALL
                    </button>
                @endif
            </div>
        </div>
        <div class="command-modal-body">
            <p class="text-muted font-size-xs mb-3">Add these records at your domain registrar. AlphaLink does not make DNS changes for external domains. Keep existing records that are not listed here.</p>

            @if($pendingDnsPlans !== [])
                <div class="alert alert-info" role="status">Pending install, reinstall, or domain-change DNS records are shown below. AlphaLink verifies public DNS before it changes the mail server.</div>
            @endif

            @if($recordError)
                <div class="alert alert-warning" role="status">{{ $recordError }}</div>
            @endif

            @if($records !== [])
                <h2 class="h5 mb-2">Records for installed assignments</h2>
                <div class="table-responsive">
                    <table class="command-data-table table-sm manual-dns-records w-100">
                        <thead><tr><th scope="col">NAME</th><th scope="col">TYPE</th><th scope="col">VALUE</th><th scope="col">TTL</th><th scope="col" aria-label="Actions"></th></tr></thead>
                        <tbody>
                            @foreach($records as $record)
                                <tr>
                                    <td class="font-mono">{{ $record['host'] === '@' ? '@' : $record['host'] }}</td>
                                    <td>{{ strtoupper($record['type']) }}</td>
                                    <td class="font-mono">{{ $record['address'] }}</td>
                                    <td>{{ $record['ttl'] }}</td>
                                    <td><button type="button" class="btn-command-action copy-record" data-copy="{{ implode(';', [$record['host'], strtoupper($record['type']), $record['address'], $record['ttl']]) }}" aria-label="Copy {{ strtoupper($record['type']) }} record for {{ $record['host'] }}">COPY</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @foreach($pendingDnsPlans as $plan)
                <h2 class="h5 mt-4 mb-2">Pending records for {{ $plan['server'] }}</h2>
                <div class="table-responsive">
                    <table class="command-data-table table-sm manual-dns-records w-100">
                        <thead><tr><th scope="col">NAME</th><th scope="col">TYPE</th><th scope="col">VALUE</th><th scope="col">TTL</th><th scope="col" aria-label="Actions"></th></tr></thead>
                        <tbody>
                            @foreach($plan['records'] as $record)
                                <tr>
                                    <td class="font-mono">{{ $record['host'] }}</td>
                                    <td>{{ strtoupper($record['type']) }}</td>
                                    <td class="font-mono">{{ $record['address'] }}</td>
                                    <td>{{ $record['ttl'] }}</td>
                                    <td><button type="button" class="btn-command-action copy-record" data-copy="{{ implode(';', [$record['host'], strtoupper($record['type']), $record['address'], $record['ttl']]) }}" aria-label="Copy {{ strtoupper($record['type']) }} record for {{ $record['host'] }}">COPY</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
            @if($records !== [] || $pendingDnsPlans !== [])
                <p class="manual-dns-message text-muted mt-2 mb-0 font-size-xs" role="status" aria-live="polite"></p>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const status = document.querySelector('.manual-dns-message');
            const copy = async (value) => {
                try {
                    await navigator.clipboard.writeText(value);
                    if (status) { status.textContent = 'DNS record copied.'; }
                } catch (_) {
                    if (status) { status.textContent = 'Clipboard access is unavailable. Select and copy the record from the table.'; }
                }
            };
            document.querySelectorAll('.copy-record').forEach((button) => {
                button.addEventListener('click', () => copy(button.dataset.copy || ''));
            });
            const copyAll = document.getElementById('copy-all-records');
            if (copyAll) {
                copyAll.addEventListener('click', () => copy(Array.from(document.querySelectorAll('.copy-record'), (button) => button.dataset.copy || '').join('\n')));
            }
        })();
    </script>
@endpush
