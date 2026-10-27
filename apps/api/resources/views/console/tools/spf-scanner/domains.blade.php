@extends('layouts.dms-admin')

@section('title', 'SPF Domains - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">SPF DOMAINS</h3>
            <div class="block-options"><a class="btn btn-sm btn-outline-primary mr-2" href="{{ route('console.tools.spf.index', $workspace->id) }}">SPF SCANNER</a><a class="btn btn-sm btn-primary" href="{{ route('console.tools.spf.export', array_merge(['workspace' => $workspace->id], request()->query())) }}">EXPORT CSV</a></div>
        </div>
        <div class="block-content">
            <form method="GET" class="form-row align-items-end mb-3">
                <div class="form-group col-md-6"><label for="spf-domain-search">Domain prefix</label><input class="form-control" id="spf-domain-search" name="search" value="{{ $search }}" placeholder="Search domains"></div>
                <div class="form-group col-md-2"><label for="spf-page-size">Rows</label><select class="form-control" id="spf-page-size" name="per_page">@foreach ([25, 50, 100] as $size)<option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>@endforeach</select></div>
                <div class="form-group col-md-2"><button class="btn btn-primary btn-block" type="submit">SEARCH</button></div>
                <div class="form-group col-md-2"><a class="btn btn-outline-secondary btn-block" href="{{ route('console.tools.spf.domains', $workspace->id) }}">RESET</a></div>
            </form>
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover">
                    <thead><tr><th><a href="{{ request()->fullUrlWithQuery(['sort' => 'id', 'dir' => $sort === 'id' && $direction === 'asc' ? 'desc' : 'asc']) }}">#</a></th><th><a href="{{ request()->fullUrlWithQuery(['sort' => 'domain', 'dir' => $sort === 'domain' && $direction === 'asc' ? 'desc' : 'asc']) }}">DOMAIN</a></th><th>STATUS</th><th>SPF RECORD</th><th>SCANNED</th></tr></thead>
                    <tbody>
                        @forelse ($domains as $domain)
                            <tr><td>{{ $domain->id }}</td><td>{{ $domain->domain }}</td><td><span class="badge badge-{{ $domain->status === 'ok' ? 'success' : ($domain->status === 'error' ? 'danger' : 'secondary') }}">{{ strtoupper($domain->status) }}</span></td><td><button class="btn btn-sm btn-outline-primary" type="button" data-spf-record="{{ $domain->id }}">VIEW RECORD</button></td><td>{{ $domain->scanned_at?->format('Y-m-d H:i:s') ?? '—' }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="text-muted">No scanned domains found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $domains->links() }}
        </div>
    </div>
    <dialog id="spf-record-dialog" class="p-0 border-0 rounded shadow" aria-labelledby="spf-record-title" style="width:min(760px,94vw)">
        <div class="block block-rounded mb-0"><div class="block-header"><h3 id="spf-record-title" class="block-title">SPF RECORD</h3><div class="block-options"><button type="button" class="btn-block-option" data-spf-close aria-label="Close">×</button></div></div><div class="block-content"><pre id="spf-record-content" class="text-monospace text-wrap"></pre></div></div>
    </dialog>
    <script>
        (() => { const endpoint = @json(route('console.tools.spf.records', $workspace->id)); const dialog = document.getElementById('spf-record-dialog'); document.querySelector('[data-spf-close]').addEventListener('click', () => dialog.close()); document.addEventListener('click', async (event) => { const button = event.target.closest('[data-spf-record]'); if (!button) return; try { const response = await fetch(`${endpoint}?id=${encodeURIComponent(button.dataset.spfRecord)}`, {headers:{'Accept':'application/json'}}); const data = await response.json(); if (!response.ok) throw new Error(data.error || 'Record not found.'); document.getElementById('spf-record-content').textContent = [data.formatted || 'No SPF record', ...(data.hosts || []).map((host) => `\n${host.domain}: ${host.status}\n${host.record || '(no record)'}`)].join('\n'); dialog.showModal(); } catch (error) { window.alert(error.message); } }); })();
    </script>
@endsection
