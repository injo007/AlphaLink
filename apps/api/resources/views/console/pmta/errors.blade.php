@extends('layouts.dms-admin')

@section('title', 'Pmta Bounces')

@section('content')
<section class="block block-rounded" id="pmta-errors" data-search-url="{{ route('console.pmta-errors.search', $workspace->id) }}">
    <div class="block-header block-header-default"><h3 class="block-title">Pmta Bounces</h3><div class="block-options"><button type="button" class="btn-block-option" data-toggle="block-option" data-action="content_toggle" aria-label="Collapse PMTA bounces"><i class="si si-arrow-up"></i></button></div></div>
    <div class="block-content block-content-full">
        <div class="row justify-content-center">
            <div class="col-sm-12 col-md-3"><div class="form-group"><label for="pmta-errors-servers">Servers:</label><select id="pmta-errors-servers" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 5" multiple title="Select Servers">@foreach ($servers as $server)<option value="{{ $server->id }}">{{ $server->name }}{{ substr((string) $server->getKey(), 0, 8) }} | {{ $server->ip_address }}</option>@endforeach</select></div></div>
            <div class="col-sm-12 col-md-3"><div class="form-group"><label for="pmta-errors-emails">Emails:</label><textarea id="pmta-errors-emails" class="form-control" rows="1" placeholder="Enter emails"></textarea></div></div>
            <div class="col-sm-12 col-md-2" style="margin-top: 28px"><div class="form-group"><button type="button" id="pmta-errors-load" class="btn btn-primary btn-block">CHECK</button></div></div>
        </div>
        <div id="pmta-errors-status" class="sr-only" role="status" aria-live="polite">Select servers and CHECK to read today's PMTA bounce logs.</div>
        <div class="table-responsive"><table class="table table-sm" id="pmta-errors-table"></table></div>
    </div>
</section>
@endsection

@push('scripts')
<script>
(() => {
    const root=document.getElementById('pmta-errors');if(!root)return;const status=document.getElementById('pmta-errors-status');const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
    const grid=window.jQuery?.fn?.DataTable?window.jQuery('#pmta-errors-table').DataTable({data:[],dom:'<"dms-table-tools"B>frtip',buttons:[{extend:'pageLength'}],displayLength:100,order:[[2,'desc']],columns:[{title:'Server',data:'server'},{title:'Category',data:'category'},{title:'Time',data:'time'},{title:'VMTA',data:'vmta'},{title:'DNS Status',data:'dns_status'},{title:'DNS Diag',data:'dns_diag',render:(data,type)=>{if(type!=='display')return data;const field=document.createElement('textarea');field.className='form-control';field.rows=1;field.readOnly=true;field.value=data||'';return field.outerHTML;}},{title:'Recipient',data:'recipient'}] }):null;
    const announce=(message,tone='info')=>{status.textContent=message;if(window.AlphaLinkToast)window.AlphaLinkToast.show(message,tone);};
    document.getElementById('pmta-errors-load').addEventListener('click',async(event)=>{const button=event.currentTarget;const serverIds=Array.from(document.getElementById('pmta-errors-servers').selectedOptions).map((option)=>option.value);if(!serverIds.length){announce('Please select at least one server.','warning');return;}button.disabled=true;announce('Reading PMTA bounce logs…');if(grid)grid.clear().draw();try{const emails=[...new Set(document.getElementById('pmta-errors-emails').value.split(/\r?\n/).map((value)=>value.trim()).filter(Boolean))];const response=await fetch(root.dataset.searchUrl,{method:'POST',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({server_ids:serverIds,emails})});const result=await response.json().catch(()=>({}));if(!response.ok)throw new Error(result.message||Object.values(result.errors||{}).flat()[0]||'Could not read PMTA logs.');if(grid)grid.rows.add(result.data.map((row)=>({...row,category:row.category||row.error||''}))).draw();announce(`${result.data.length} bounce result(s) loaded.`,'success');}catch(error){announce(error.message,'danger');}finally{button.disabled=false;}});
    if(window.jQuery?.fn.selectpicker)window.jQuery('.selectpicker').selectpicker();
})();
</script>
@endpush
