@extends('layouts.admin-shell')

@section('title', 'Pmta Scheduler')

@push('styles')
<link rel="stylesheet" href="{{ asset('vendor/select2/4.0.13/select2.min.css') }}">
<style>
    .pmta-scheduler-col {
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
    }
    .pmta-scheduler-label-row {
        min-height: 24px;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }
    .pmta-scheduler-label-row .command-form-label {
        margin-bottom: 0 !important;
        font-size: 0.8125rem;
        font-weight: 700;
        letter-spacing: 0.04em;
    }
    .pmta-schedule-tags + .select2-container { width: 100% !important; }
    .pmta-schedule-tags + .select2-container .select2-selection--multiple {
        min-height: 40px !important;
        height: 40px !important;
        max-height: 40px !important;
        background: var(--command-os-card-bg, #ffffff) !important;
        border: 1px solid var(--command-os-border, rgba(99, 102, 241, 0.18)) !important;
        border-radius: 10px !important;
        padding: 0 8px !important;
        display: flex !important;
        align-items: center !important;
        overflow-x: auto !important;
        overflow-y: hidden !important;
        white-space: nowrap !important;
        box-sizing: border-box !important;
    }
    .pmta-schedule-tags + .select2-container .select2-selection__rendered {
        display: flex !important;
        align-items: center !important;
        flex-wrap: nowrap !important;
        gap: 4px !important;
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
        overflow-x: auto !important;
    }
    .pmta-schedule-tags + .select2-container .select2-selection__choice {
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.15), rgba(139, 92, 246, 0.15)) !important;
        border: 1px solid rgba(99, 102, 241, 0.3) !important;
        border-radius: 6px !important;
        color: #6366f1 !important;
        font-weight: 600 !important;
        font-size: 0.75rem !important;
        margin: 0 !important;
        padding: 2px 8px !important;
        display: inline-flex !important;
        align-items: center !important;
        flex-shrink: 0 !important;
    }
    .pmta-schedule-tags + .select2-container .select2-selection__choice__remove {
        color: #ef4444 !important;
        margin-right: 4px !important;
        font-weight: bold !important;
    }
    .pmta-schedule-tags + .select2-container .select2-search--inline {
        margin: 0 !important;
        display: inline-flex !important;
        align-items: center !important;
        flex-grow: 1 !important;
    }
    .pmta-schedule-tags + .select2-container .select2-search__field {
        margin: 0 !important;
        height: 28px !important;
        font-size: 0.8125rem !important;
        padding: 0 4px !important;
        min-width: 80px !important;
        color: var(--command-os-text, #1e293b) !important;
    }
    html[data-command-theme='dark'] .pmta-schedule-tags + .select2-container .select2-selection--multiple {
        background: #0d1238 !important;
        border-color: rgba(139, 92, 246, 0.25) !important;
        color: #f8fafc !important;
    }
    html[data-command-theme='dark'] .pmta-schedule-tags + .select2-container .select2-search__field {
        color: #f8fafc !important;
    }
</style>
@endpush

@section('content')
<div class="command-os-report-page">
    <!-- 1. Hero Header -->
    <header class="command-os-report-hero mb-4">
        <div class="command-os-report-hero-left">
            <div class="command-os-report-hero-icon" aria-hidden="true">
                <i class="fas fa-clock"></i>
            </div>
            <div>
                <div class="command-os-report-hero-eyebrow">DAEMON AUTOMATION // CRON SCHEDULES</div>
                <h1 class="command-os-report-hero-title">PMTA SCHEDULER</h1>
                <p class="command-os-report-hero-desc">Automated queue operations, interval-based pausing, resuming, and resets across managed servers.</p>
            </div>
        </div>
        <div class="command-os-report-hero-right">
            <div class="command-os-report-hero-meta">
                <span class="command-os-report-badge">DAEMON CRON</span>
            </div>
        </div>
    </header>

    <!-- 2. Form Card -->
    <div class="command-os-table-card p-4 mb-4" id="pmta-scheduler"
        data-store-url="{{ route('console.pmta-scheduler.store', $workspace->id) }}"
        data-data-url="{{ route('console.pmta-scheduler.data', $workspace->id) }}"
        data-delete-url="{{ route('console.pmta-scheduler.destroy', [$workspace->id, '__ID__']) }}">
        
        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
            <h3 style="font-size: 0.9375rem; font-weight: 700; color: var(--command-os-text, #1e293b); margin: 0; text-transform: uppercase; letter-spacing: 0.04em;">
                <i class="fas fa-plus-circle mr-1 text-purple"></i> Schedule Queue Task
            </h3>
        </div>

        <div class="row align-items-start">
            <div class="col-sm-12 col-md-3 mb-3 pmta-scheduler-col">
                <div class="pmta-scheduler-label-row">
                    <label class="command-form-label" for="pmta-schedule-servers">
                        <i class="fas fa-server mr-1 text-purple"></i> SERVERS:
                    </label>
                </div>
                <select id="pmta-schedule-servers" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 5" multiple title="Select Servers">
                    @foreach ($servers as $server)
                        <option value="{{ $server->id }}">{{ $server->name }} | {{ $server->ip_address }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-12 col-md-3 mb-3 pmta-scheduler-col">
                <div class="pmta-scheduler-label-row">
                    <label class="command-form-label" for="pmta-schedule-tags">
                        <i class="fas fa-tags mr-1 text-purple"></i> TAGS (DOMAIN/VMTA):
                    </label>
                    <fieldset class="border-0 p-0 m-0 d-inline-flex align-items-center" style="gap: 10px;" role="radiogroup" aria-labelledby="pmta-schedule-queue-label">
                        <legend class="sr-only" id="pmta-schedule-queue-label">Queue</legend>
                        <label class="mb-0 d-inline-flex align-items-center" style="gap: 4px; font-size: 0.75rem; font-weight: 600; cursor: pointer;">
                            <input type="radio" name="pmta-schedule-queue" value="domain" checked>
                            <span>DOMAIN</span>
                        </label>
                        <label class="mb-0 d-inline-flex align-items-center" style="gap: 4px; font-size: 0.75rem; font-weight: 600; cursor: pointer;">
                            <input type="radio" name="pmta-schedule-queue" value="campaign">
                            <span>JOB ID</span>
                        </label>
                    </fieldset>
                </div>
                <select id="pmta-schedule-tags" class="form-control pmta-schedule-tags" multiple>
                    <option value="*/*" selected>*/*</option>
                </select>
            </div>
            <div class="col-sm-12 col-md-3 mb-3 pmta-scheduler-col">
                <div class="pmta-scheduler-label-row">
                    <label class="command-form-label" for="pmta-schedule-action">
                        <i class="fas fa-play mr-1 text-purple"></i> ACTION:
                    </label>
                </div>
                <select id="pmta-schedule-action" class="form-control selectpicker" data-live-search="true">
                    <option value="pause">PAUSE</option>
                    <option value="resume">RESUME</option>
                    <option value="delete">DELETE</option>
                    <option value="reset">RESET</option>
                    <option value="schedule">SCHEDULE</option>
                </select>
            </div>
            <div class="col-sm-12 col-md-3 mb-3 pmta-scheduler-col">
                <div class="pmta-scheduler-label-row">
                    <label class="command-form-label" for="pmta-schedule-frequency">
                        <i class="fas fa-redo mr-1 text-purple"></i> FREQUENCY:
                    </label>
                </div>
                <select id="pmta-schedule-frequency" class="form-control selectpicker" data-live-search="true" data-size="6">
                    @foreach ($frequencies as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="d-flex justify-content-end mt-3 pt-3 border-top">
            <button class="btn-command-primary" type="button" id="pmta-schedule-add">
                <i class="fas fa-calendar-plus mr-1"></i> ADD TASK
            </button>
        </div>
    </div>

    <!-- 3. Scheduled Tasks Table Card -->
    <div class="command-os-table-card">
        <div class="sr-only" id="pmta-schedule-status" role="status" aria-live="polite"></div>
        <div class="table-responsive">
            <table class="table table-sm table-hover table-striped command-os-table" id="pmta-schedule-table" aria-label="Scheduled tasks"></table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('vendor/select2/4.0.13/select2.min.js') }}"></script>
<script>
const initializePmtaScheduler = () => {
    const root = document.getElementById('pmta-scheduler');
    if (!root) return;
    const status = document.getElementById('pmta-schedule-status');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const api = async (url, method, body) => {
        const response = await fetch(url, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: body ? JSON.stringify(body) : undefined });
        const result = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(result.message || Object.values(result.errors || {}).flat()[0] || 'The request failed.');
        return result;
    };
    const announce = (message, tone = 'info') => { status.textContent = message; if (window.AlphaLinkToast) window.AlphaLinkToast.show(message, tone); };
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);
    const formatTags = (values) => (values || []).map((value) => `<span class="badge-command-pill mr-1">${escapeHtml(value).toUpperCase()}</span>`).join(' ');
    const grid = window.jQuery?.fn?.DataTable ? window.jQuery('#pmta-schedule-table').DataTable({
        ajax: { url: root.dataset.dataUrl, dataSrc: 'data' },
        dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>rt<"command-table-footer"ip>',
        buttons: [
            { extend: 'pageLength', className: 'btn-command-action' },
            {
                text: '<i class="fas fa-sync mr-1"></i> REFRESH',
                className: 'btn-command-action',
                titleAttr: 'REFRESH',
                action: (event, table) => table.ajax.reload(null, false)
            },
        ],
        displayLength: 50, order: [[0, 'desc']],
        columns: [
            {
                title: '#', data: 'id', width: '5%',
                render: (value, type) => type === 'display' ? `<span class="command-mono-badge">#${value}</span>` : value
            },
            {
                title: 'USER', data: 'user',
                render: (val, type) => type === 'display' ? `<strong>${escapeHtml(val || '')}</strong>` : val
            },
            { title: 'SERVERS', data: 'servers', render: (data, type) => type === 'display' ? formatTags(data) : (data || []).join(' ') },
            {
                title: 'QUEUE', data: 'queue',
                render: (val) => `<span class="badge-command-pill" style="font-weight: 700; color: #8b5cf6;">${escapeHtml(val || '').toUpperCase()}</span>`
            },
            { title: 'TAGS', data: 'tags', render: (data, type) => type === 'display' ? formatTags(data) : (data || []).join(' ') },
            {
                title: 'TODO', data: 'action',
                render: (val) => `<span class="badge-command-pill" style="font-weight: 700; color: #6366f1;">${escapeHtml(val || '').toUpperCase()}</span>`
            },
            { title: 'FREQUENCY', data: 'frequency' },
            { title: 'CREATED AT', data: 'created_at', render: (val, type) => type === 'display' ? `<span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">${escapeHtml(val || '')}</span>` : val },
            {
                title: 'ACTIONS', data: null, orderable: false, searchable: false,
                render: (data, type, row) => `<div class="command-action-btn-group"><button type="button" class="command-action-btn action-danger pmta-schedule-stop" data-id="${Number(row.id)}" title="STOP TASK" aria-label="Stop Task"><i class="fas fa-trash"></i></button></div>`
            },
        ],
    }) : null;
    document.getElementById('pmta-schedule-table').addEventListener('click', async (event) => {
        const button = event.target.closest('.pmta-schedule-stop');
        if (!button || !grid) return;
        if (!await window.PmtaControls.confirm('Stop this scheduled action?', 'This removes the selected scheduled action.', 'Stop', true)) return;
        button.disabled = true;
        try {
            const result = await api(root.dataset.deleteUrl.replace('__ID__', button.dataset.id), 'DELETE');
            const row = window.jQuery(button).closest('tr');
            grid.row(row).remove().draw(false);
            announce(result.message, 'success');
        } catch (error) { announce(error.message, 'danger'); button.disabled = false; }
    });
    document.getElementById('pmta-schedule-add').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const serverIds = Array.from(document.getElementById('pmta-schedule-servers').selectedOptions).map((option) => option.value);
        const tags = window.jQuery?.fn?.select2 ? window.jQuery('#pmta-schedule-tags').val() || [] : [];
        if (!serverIds.length) { announce('Please select at least one server.', 'warning'); return; }
        if (!tags.includes('*/*')) { announce('Add the */* tag before creating a scheduled PMTA action.', 'warning'); return; }
        button.disabled = true;
        try {
            const result = await api(root.dataset.storeUrl, 'POST', {
                server_ids: serverIds,
                queue: document.querySelector('input[name="pmta-schedule-queue"]:checked')?.value || 'domain',
                tags,
                action: document.getElementById('pmta-schedule-action').value,
                frequency: document.getElementById('pmta-schedule-frequency').value,
            });
            if (grid) grid.ajax.reload(null, false);
            announce(result.message, 'success');
        } catch (error) { announce(error.message, 'danger'); }
        finally { button.disabled = false; }
    });
    if (window.jQuery?.fn.selectpicker) window.jQuery('.selectpicker').selectpicker();
    if (window.jQuery?.fn.select2) window.jQuery('#pmta-schedule-tags').select2({ allowClear: true, placeholder: 'Tags', tags: true, width: '100%' });
};

if (window.jQuery) {
    window.jQuery(initializePmtaScheduler);
} else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializePmtaScheduler, { once: true });
} else {
    initializePmtaScheduler();
}
</script>
@endpush
