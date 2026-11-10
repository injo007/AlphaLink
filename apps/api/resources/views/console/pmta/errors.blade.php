@extends('layouts.admin-shell')

@section('title', 'Pmta Bounces')

@section('content')
<div class="command-os-report-page">
    <!-- 1. Hero Header -->
    <header class="command-os-report-hero mb-4">
        <div class="command-os-report-hero-left">
            <div class="command-os-report-hero-icon" aria-hidden="true">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div>
                <div class="command-os-report-hero-eyebrow">DIAGNOSTIC LOGS // BOUNCE TELEMETRY</div>
                <h1 class="command-os-report-hero-title">PMTA BOUNCES &amp; ERRORS</h1>
                <p class="command-os-report-hero-desc">Diagnostic bounce log inspection, recipient failure tracing, and DNS diagnostics across managed servers.</p>
            </div>
        </div>
        <div class="command-os-report-hero-right">
            <div class="command-os-report-hero-meta">
                <span class="command-os-report-badge">ERROR TELEMETRY</span>
            </div>
        </div>
    </header>

    <div id="pmta-errors" data-search-url="{{ route('console.pmta-errors.search', $workspace->id) }}">
        <!-- 2. Filter Card -->
        <div class="command-os-table-card p-4 mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                <h3 style="font-size: 0.9375rem; font-weight: 700; color: var(--command-os-text, #1e293b); margin: 0; text-transform: uppercase; letter-spacing: 0.04em;">
                    <i class="fas fa-filter mr-1 text-purple"></i> Query Bounce &amp; Error Logs
                </h3>
            </div>
            <div class="row align-items-end">
                <div class="col-sm-12 col-md-5 mb-3 mb-md-0">
                    <label class="command-form-label" for="pmta-errors-servers">Target Servers:</label>
                    <select id="pmta-errors-servers" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 5" multiple title="Select Servers">
                        @foreach ($servers as $server)
                            <option value="{{ $server->id }}">{{ $server->name }}{{ substr((string) $server->getKey(), 0, 8) }} | {{ $server->ip_address }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-12 col-md-5 mb-3 mb-md-0">
                    <label class="command-form-label" for="pmta-errors-emails">Filter Recipients (one per line):</label>
                    <textarea id="pmta-errors-emails" class="form-control" rows="1" placeholder="user@example.com" style="min-height: 38px;"></textarea>
                </div>
                <div class="col-sm-12 col-md-2">
                    <button type="button" id="pmta-errors-load" class="btn-command-primary btn-block">
                        <i class="fas fa-search mr-1"></i> CHECK LOGS
                    </button>
                </div>
            </div>
            <div id="pmta-errors-status" class="sr-only" role="status" aria-live="polite">Select servers and CHECK to read today's PMTA bounce logs.</div>
        </div>

        <!-- 3. Results Table Card -->
        <div class="command-os-table-card">
            <div class="table-responsive">
                <table class="table table-sm table-hover table-striped command-os-table" id="pmta-errors-table" aria-label="Bounce and error logs"></table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const root = document.getElementById('pmta-errors');
    if (!root) return;
    const status = document.getElementById('pmta-errors-status');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const grid = window.jQuery?.fn?.DataTable ? window.jQuery('#pmta-errors-table').DataTable({
        data: [],
        dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>rt<"command-table-footer"ip>',
        buttons: [
            { extend: 'pageLength', className: 'btn-command-action' },
        ],
        displayLength: 100,
        order: [[2, 'desc']],
        columns: [
            {
                title: 'SERVER', data: 'server',
                render: (val, type) => type === 'display' ? `<strong>${val || ''}</strong>` : val
            },
            {
                title: 'CATEGORY', data: 'category',
                render: (val, type) => type === 'display' ? `<span class="badge-command-pill" style="font-weight: 700; color: #ef4444;">${val || 'UNKNOWN'}</span>` : val
            },
            {
                title: 'TIME', data: 'time',
                render: (val, type) => type === 'display' ? `<span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">${val || ''}</span>` : val
            },
            {
                title: 'VMTA', data: 'vmta',
                render: (val, type) => type === 'display' ? `<span style="font-family: monospace; color: #8b5cf6;">${val || 'N/A'}</span>` : val
            },
            { title: 'DNS STATUS', data: 'dns_status' },
            {
                title: 'DNS DIAG', data: 'dns_diag',
                render: (data, type) => {
                    if (type !== 'display') return data;
                    const field = document.createElement('textarea');
                    field.className = 'form-control';
                    field.rows = 1;
                    field.readOnly = true;
                    field.style.minHeight = '32px';
                    field.style.fontSize = '0.75rem';
                    field.style.fontFamily = 'monospace';
                    field.value = data || '';
                    return field.outerHTML;
                }
            },
            {
                title: 'RECIPIENT', data: 'recipient',
                render: (val, type) => type === 'display' ? `<span style="font-family: monospace; font-weight: 600;">${val || ''}</span>` : val
            }
        ]
    }) : null;
    const announce = (message, tone = 'info') => {
        status.textContent = message;
        if (window.AlphaLinkToast) window.AlphaLinkToast.show(message, tone);
    };
    document.getElementById('pmta-errors-load').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const serverIds = Array.from(document.getElementById('pmta-errors-servers').selectedOptions).map((option) => option.value);
        if (!serverIds.length) { announce('Please select at least one server.', 'warning'); return; }
        button.disabled = true;
        announce('Reading PMTA bounce logs…');
        if (grid) grid.clear().draw();
        try {
            const emails = [...new Set(document.getElementById('pmta-errors-emails').value.split(/\r?\n/).map((value) => value.trim()).filter(Boolean))];
            const response = await fetch(root.dataset.searchUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ server_ids: serverIds, emails })
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(result.message || Object.values(result.errors || {}).flat()[0] || 'Could not read PMTA logs.');
            if (grid) grid.rows.add(result.data.map((row) => ({ ...row, category: row.category || row.error || '' }))).draw();
            announce(`${result.data.length} bounce result(s) loaded.`, 'success');
        } catch (error) { announce(error.message, 'danger'); }
        finally { button.disabled = false; }
    });
    if (window.jQuery?.fn.selectpicker) window.jQuery('.selectpicker').selectpicker();
})();
</script>
@endpush
