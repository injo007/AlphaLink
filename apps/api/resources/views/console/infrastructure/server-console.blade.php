@extends('layouts.dms-reference')

@section('title', 'Console')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">
                SERVER <span class="text-info">{{ $server->name }} | {{ $server->id }}</span> CONSOLE
            </h3>
        </div>
        <div class="block-content">
            <div class="row justify-content-center">
                <div class="col-sm-10">
                    <form id="server-console-form" action="{{ $consoleUrl }}" method="post">
                        @csrf
                        <div class="form-group">
                            <label for="command">Command : </label>
                            <textarea id="command" name="cmd" class="form-control" rows="5"></textarea>
                        </div>
                        <div class="form-group">
                            <button id="run" type="submit" class="btn btn-block btn-warning">RUN</button>
                        </div>
                    </form>
                    <div class="form-group">
                        <code id="result" style="white-space: pre-wrap;" aria-live="polite"></code>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script defer src="{{ asset('dms-reference/server-console.js') }}"></script>
@endpush
