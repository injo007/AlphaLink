@extends('layouts.dms-admin')

@section('title', 'App Health - DMS')

@section('content')
    <div class="row">
        <div class="col-sm-12">
            <div class="block block-rounded">
                <div class="block-header">
                    <h3 class="block-title">APP HEALTH <small>last run: {{ \Illuminate\Support\Carbon::parse($report['finished_at'])->diffForHumans() }}</small></h3>
                </div>
                <div class="block-content">
                    <table class="table table-vcenter table-sm">
                        <thead class="thead-light">
                            <tr><th>CHECK</th><th class="text-center">Summary</th><th class="d-none d-sm-table-cell">Message</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($report['stored_check_results'] as $result)
                                <tr class="{{ $result['status'] === 'ok' ? '' : 'table-warning' }}">
                                    <td class="font-w600 font-size-sm">{{ $result['label'] }}</td>
                                    <td class="text-center">{{ $result['shortSummary'] }}</td>
                                    <td class="d-none d-sm-table-cell">{{ $result['notificationMessage'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3"><p class="text-center mb-0">No checks have been run yet.</p></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <p class="text-muted small mb-0">This page reads the latest cached report. Failed checks are shown here; external notifications are not configured.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
