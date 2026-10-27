@extends('layouts.dms-admin')

@section('title', 'GLOBAL TEST HISTORIES - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">GLOBAL TEST HISTORIES</h3>
            <div class="block-options"><a class="btn btn-sm btn-alt-secondary" href="{{ route('console.global-tests.index', $workspace->id) }}"><i class="fas fa-vial mr-1" aria-hidden="true"></i>New Global Test</a></div>
        </div>
        <div class="block-content block-content-full">
            <form class="form-row align-items-end mb-3" method="get" action="{{ route('console.global-tests.histories', $workspace->id) }}">
                <div class="form-group col-sm-4 col-md-3"><label for="history-from">From</label><input id="history-from" class="form-control" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></div>
                <div class="form-group col-sm-4 col-md-3"><label for="history-to">To</label><input id="history-to" class="form-control" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></div>
                <div class="form-group col-sm-4 col-md-3"><button class="btn btn-primary" type="submit">Filter</button> <a class="btn btn-alt-secondary" href="{{ route('console.global-tests.histories', $workspace->id) }}">Clear</a></div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-striped table-vcenter">
                    <caption class="sr-only">Saved Global Test results for this workspace</caption>
                    <thead><tr><th scope="col">Created</th><th scope="col">Return Path</th><th scope="col">Email Test</th><th scope="col">Option</th><th scope="col">Servers</th><th scope="col">Actor</th><th scope="col">Action</th></tr></thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr>
                                <td>{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                                <td>{{ $log->data['return_path'] ?? '' }}</td>
                                <td>{{ implode(', ', $log->data['email_tests'] ?? []) }}</td>
                                <td>{{ strtoupper($log->data['mta'] ?? '') }} / {{ strtoupper($log->data['type'] ?? '') }}</td>
                                <td>{{ count($log->data['servers'] ?? []) }}</td>
                                <td>{{ $log->actor?->name ?: $log->actor?->email }}</td>
                                <td><a class="btn btn-sm btn-alt-primary" href="{{ route('console.global-tests.history', [$workspace->id, $log->id]) }}"><i class="fas fa-eye mr-1" aria-hidden="true"></i>View</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No Global Test results have been saved for this period. DMS saves a history row only when sender output is returned.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <span class="text-muted">{{ $logs->firstItem() ?? 0 }}–{{ $logs->lastItem() ?? 0 }} of {{ $logs->total() }} results</span>
                {{ $logs->links() }}
            </div>
        </div>
    </div>
@endsection
