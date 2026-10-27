@extends('layouts.dms-admin')

@section('title', 'Pmta Scheduler')

@push('styles')
<link rel="stylesheet" href="{{ asset('vendor/select2/4.0.13/select2.min.css') }}">
<style>
    .pmta-schedule-tags + .select2-container { width: 100% !important; }
    .pmta-schedule-tags + .select2-container .select2-selection--multiple { min-height: 38px; border-color: #d1d9e0; border-radius: .25rem; }
    .pmta-schedule-tags + .select2-container .select2-selection__choice { margin-top: 5px; }
</style>
@endpush

@section('content')
<section class="block" id="pmta-scheduler"
    data-store-url="{{ route('console.pmta-scheduler.store', $workspace->id) }}"
    data-data-url="{{ route('console.pmta-scheduler.data', $workspace->id) }}"
    data-delete-url="{{ route('console.pmta-scheduler.destroy', [$workspace->id, '__ID__']) }}">
    <div class="block-header"><h3 class="block-title">pmta scheduler</h3><div class="block-options"><button type="button" class="btn-block-option" data-toggle="block-option" data-action="content_toggle" aria-label="Collapse PMTA scheduler"><i class="si si-arrow-up"></i></button></div></div>
    <div class="block-content">
        <div class="row">
            <div class="col-sm-12 col-md-3">
                <div class="form-group"><label for="pmta-schedule-servers">Servers:</label><select id="pmta-schedule-servers" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 5" multiple title="Select Servers">@foreach ($servers as $server)<option value="{{ $server->id }}">{{ $server->name }} | {{ $server->ip_address }}</option>@endforeach</select></div>
                <fieldset class="form-group mb-0" role="radiogroup" aria-labelledby="pmta-schedule-queue-label">
                    <legend class="sr-only" id="pmta-schedule-queue-label">Queue</legend>
                    <label class="custom-control custom-radio d-block mb-1"><input class="custom-control-input" type="radio" name="pmta-schedule-queue" value="domain" checked><span class="custom-control-label">Domain</span></label>
                    <label class="custom-control custom-radio d-block mb-0"><input class="custom-control-input" type="radio" name="pmta-schedule-queue" value="campaign"><span class="custom-control-label">Job Id</span></label>
                </fieldset>
            </div>
            <div class="col-sm-12 col-md-3 form-group"><label for="pmta-schedule-tags">Tags (domain/vmta):</label><select id="pmta-schedule-tags" class="form-control pmta-schedule-tags" multiple><option value="*/*" selected>*/*</option></select></div>
            <div class="col-sm-12 col-md-2 form-group"><label for="pmta-schedule-action">Action:</label><select id="pmta-schedule-action" class="form-control selectpicker" data-live-search="true"><option value="pause">PAUSE</option><option value="resume">RESUME</option><option value="delete">DELETE</option><option value="reset">RESET</option><option value="schedule">SCHEDULE</option></select></div>
            <div class="col-sm-12 col-md-2 form-group"><label for="pmta-schedule-frequency">Frequency:</label><select id="pmta-schedule-frequency" class="form-control selectpicker" data-live-search="true" data-size="6">@foreach ($frequencies as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
        </div>
        <div class="row"><div class="col-sm-12"><div class="form-group text-md-right"><button class="btn btn-primary" type="button" id="pmta-schedule-add">ADD TASK</button></div></div></div>
        <hr>
        <div class="sr-only" id="pmta-schedule-status" role="status" aria-live="polite"></div>
        <div class="table-responsive"><table class="table table-sm table-hover table-striped" id="pmta-schedule-table"></table></div>
    </div>
</section>
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
    const formatTags = (values) => (values || []).map((value) => `<span class="badge badge-primary mr-1">${escapeHtml(value).toUpperCase()}</span>`).join(' ');
    const grid = window.jQuery?.fn?.DataTable ? window.jQuery('#pmta-schedule-table').DataTable({
        ajax: { url: root.dataset.dataUrl, dataSrc: 'data' },
        buttons: [
            { extend: 'pageLength' },
            { text: '<i class="fas fa-sync"></i>', titleAttr: 'REFRESH', action: (event, table) => table.ajax.reload(null, false) },
        ],
        displayLength: 50, order: [[0, 'desc']],
        columns: [
            { title: '#', data: 'id' }, { title: 'USER', data: 'user' },
            { title: 'SERVERS', data: 'servers', render: (data, type) => type === 'display' ? formatTags(data) : (data || []).join(' ') },
            { title: 'QUEUE', data: 'queue' },
            { title: 'TAGS', data: 'tags', render: (data, type) => type === 'display' ? formatTags(data) : (data || []).join(' ') },
            { title: 'TODO', data: 'action' }, { title: 'FREQUENCY', data: 'frequency' }, { title: 'CREATED AT', data: 'created_at' },
            { title: 'ACTIONS', data: null, orderable: false, searchable: false, render: (data, type, row) => `<button type="button" class="btn btn-sm btn-danger pmta-schedule-stop" data-id="${Number(row.id)}">STOP</button>` },
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
