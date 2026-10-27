@extends('layouts.dms-admin')

@section('title', 'GLOBAL TEST HISTORY - DMS')

@section('content')
    @php
        $header = base64_decode((string) ($log->data['header'] ?? ''), true);
        $body = base64_decode((string) ($log->data['body'] ?? ''), true);
    @endphp
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">GLOBAL TEST HISTORY</h3>
            <div class="block-options"><a class="btn btn-sm btn-alt-secondary" href="{{ route('console.global-tests.histories', $workspace->id) }}"><i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>Histories</a></div>
        </div>
        <div class="block-content block-content-full">
            <dl class="row">
                <dt class="col-sm-3">Created</dt><dd class="col-sm-9">{{ $log->created_at?->format('Y-m-d H:i:s') }}</dd>
                <dt class="col-sm-3">Created by</dt><dd class="col-sm-9">{{ $log->actor?->name ?: $log->actor?->email }}</dd>
                <dt class="col-sm-3">Return Path</dt><dd class="col-sm-9">{{ $log->data['return_path'] ?? '' }}</dd>
                <dt class="col-sm-3">Test recipients</dt><dd class="col-sm-9">{{ implode(', ', $log->data['email_tests'] ?? []) }}</dd>
                <dt class="col-sm-3">Sender option</dt><dd class="col-sm-9">{{ strtoupper($log->data['mta'] ?? '') }} / {{ strtoupper($log->data['type'] ?? '') }}</dd>
                <dt class="col-sm-3">Server IDs</dt><dd class="col-sm-9"><code>{{ implode(', ', $log->data['servers'] ?? []) }}</code></dd>
                <dt class="col-sm-3">IP / MTA IDs</dt><dd class="col-sm-9"><code>{{ implode(', ', $log->data['ips'] ?? []) }}</code></dd>
                <dt class="col-sm-3">Repeat</dt><dd class="col-sm-9">{{ $log->data['repeat'] ?? '—' }}</dd>
            </dl>
            <div class="form-group"><label for="global-history-header">Header</label><textarea id="global-history-header" class="form-control" rows="8" readonly>{{ $header === false ? 'Stored header could not be decoded.' : $header }}</textarea></div>
            <div class="form-group"><label for="global-history-body">Body</label><textarea id="global-history-body" class="form-control" rows="10" readonly>{{ $body === false ? 'Stored body could not be decoded.' : $body }}</textarea></div>
            <div class="form-group"><label for="global-history-output">Sender output</label><pre id="global-history-output" class="global-test-output">{{ $log->output }}</pre></div>
        </div>
    </div>
@endsection

@push('styles')
    <style>.global-test-output { max-height: 30rem; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; background: #f6f7f8; border: 1px solid #d8dee4; padding: .75rem; }</style>
@endpush
