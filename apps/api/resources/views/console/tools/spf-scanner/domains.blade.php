@extends('layouts.admin-shell')

@section('title', 'SPF Domains')

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-globe"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">OPERATIONS TOOLKIT // SPF SCANNER</div>
                    <h1 class="command-os-report-hero-title">SCANNED SPF DOMAINS</h1>
                    <p class="command-os-report-hero-desc">Review, filter, and inspect DNS SPF validation status for all cataloged domain assets.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta d-flex align-items-center" style="gap: 8px;">
                    <a class="btn-command-action" href="{{ route('console.tools.spf.index', $workspace->id) }}">
                        <i class="fas fa-search mr-1"></i> SPF SCANNER
                    </a>
                    <a class="btn-command-primary" href="{{ route('console.tools.spf.export', array_merge(['workspace' => $workspace->id], request()->query())) }}">
                        <i class="fas fa-download mr-1"></i> EXPORT CSV
                    </a>
                </div>
            </div>
        </header>

        <!-- 2. Filter & Table Card -->
        <div class="command-os-table-card p-4">
            <form method="GET" class="row align-items-end mb-4">
                <div class="col-md-6 mb-3 mb-md-0">
                    <label class="command-form-label" for="spf-domain-search"><i class="fas fa-filter mr-1 text-purple"></i> Domain Prefix:</label>
                    <input class="command-form-control" id="spf-domain-search" name="search" value="{{ $search }}" placeholder="Search domains..." style="height: 38px;">
                </div>
                <div class="col-md-2 mb-3 mb-md-0">
                    <label class="command-form-label" for="spf-page-size"><i class="fas fa-list-ol mr-1 text-purple"></i> Rows:</label>
                    <div class="command-select-wrapper">
                        <select class="command-form-control command-select" id="spf-page-size" name="per_page" style="height: 38px;">
                            @foreach ([25, 50, 100] as $size)
                                <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-md-2 mb-3 mb-md-0">
                    <button class="btn-command-primary w-100" type="submit" style="height: 38px;">
                        <i class="fas fa-search mr-1"></i> SEARCH
                    </button>
                </div>
                <div class="col-md-2">
                    <a class="btn-command-action w-100 text-center" href="{{ route('console.tools.spf.domains', $workspace->id) }}" style="height: 38px; line-height: 24px;">
                        <i class="fas fa-undo mr-1"></i> RESET
                    </a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover command-os-table">
                    <thead>
                        <tr>
                            <th><a href="{{ request()->fullUrlWithQuery(['sort' => 'id', 'dir' => $sort === 'id' && $direction === 'asc' ? 'desc' : 'asc']) }}">#</a></th>
                            <th><a href="{{ request()->fullUrlWithQuery(['sort' => 'domain', 'dir' => $sort === 'domain' && $direction === 'asc' ? 'desc' : 'asc']) }}">DOMAIN</a></th>
                            <th>STATUS</th>
                            <th>SPF</th>
                            <th>SCANNED</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($domains as $domain)
                            <tr>
                                <td><span class="command-mono-badge">#{{ $domain->id }}</span></td>
                                <td><strong>{{ $domain->domain }}</strong></td>
                                <td>
                                    @if ($domain->status === 'ok')
                                        <span class="badge-command-pill" style="color: #10b981;">OK</span>
                                    @elseif ($domain->status === 'error')
                                        <span class="badge-command-pill" style="color: #ef4444;">ERROR</span>
                                    @else
                                        <span class="badge-command-pill" style="color: #64748b;">{{ strtoupper($domain->status) }}</span>
                                    @endif
                                </td>
                                <td>
                                    <button class="btn-command-action" type="button" style="padding: 2px 8px; font-size: 0.75rem;" data-spf-record="{{ $domain->id }}">
                                        <i class="fas fa-eye mr-1"></i> VIEW RECORD
                                    </button>
                                </td>
                                <td><span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">{{ $domain->scanned_at?->format('Y-m-d H:i:s') ?? '—' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No scanned domains found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">
                {{ $domains->links() }}
            </div>
        </div>
    </div>

    <!-- Modal Dialog for SPF Record Details -->
    <dialog id="spf-record-dialog" class="command-modal-dialog p-0 border-0 rounded shadow" aria-labelledby="spf-record-title" style="width: min(760px, 94vw); border-radius: 16px;">
        <div class="p-4">
            <div class="d-flex align-items-center justify-content-between pb-2 mb-3 border-bottom">
                <h3 id="spf-record-title" class="mb-0" style="font-size: 1rem; font-weight: 700; color: var(--command-os-text, #1e293b); text-transform: uppercase;">
                    <i class="fas fa-shield-alt mr-1 text-purple"></i> SPF RECORD DETAILS
                </h3>
                <button type="button" class="btn-command-action" data-spf-close aria-label="Close" style="padding: 4px 10px;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <pre id="spf-record-content" class="text-monospace text-wrap p-3" style="background: rgba(139, 92, 246, 0.05); border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.15)); border-radius: 8px; font-size: 0.8125rem; max-height: 400px; overflow-y: auto;"></pre>
        </div>
    </dialog>
@endsection

@push('scripts')
<script>
    (() => {
        const endpoint = @json(route('console.tools.spf.records', $workspace->id));
        const dialog = document.getElementById('spf-record-dialog');
        document.querySelector('[data-spf-close]').addEventListener('click', () => dialog.close());
        document.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-spf-record]');
            if (!button) return;
            try {
                const response = await fetch(`${endpoint}?id=${encodeURIComponent(button.dataset.spfRecord)}`, {headers:{'Accept':'application/json'}});
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'Record not found.');
                document.getElementById('spf-record-content').textContent = [data.formatted || 'No SPF record', ...(data.hosts || []).map((host) => `\n${host.domain}: ${host.status}\n${host.record || '(no record)'}`)].join('\n');
                dialog.showModal();
            } catch (error) { window.alert(error.message); }
        });
    })();
</script>
@endpush
