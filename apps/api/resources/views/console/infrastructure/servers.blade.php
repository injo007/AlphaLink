@extends('layouts.dms-admin')

@section('title', 'Servers - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Servers</h3>
            <div class="block-options">
                <div class="custom-control custom-switch custom-control-danger custom-control-inline">
                    <input type="checkbox" class="custom-control-input" id="returned" aria-label="Show returned servers">
                    <label id="label_auto" class="custom-control-label" for="returned">RETURNED</label>
                </div>
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <div class="table-responsive">
                <table id="servers" class="table table-sm table-hover table-striped" data-source-url="{{ $serversDataUrl }}" data-create-url="{{ $createUrl }}" data-returned="false"></table>
            </div>
        </div>
    </div>
@endsection
