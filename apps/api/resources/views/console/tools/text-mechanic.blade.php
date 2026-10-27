@extends('layouts.dms-admin')

@section('title', 'TEXT MECHANIC - DMS')

@push('styles')
    <style>
        #text {
            display: block;
            margin: 0;
            padding: 8px 10px;
            overflow: scroll;
            font-family: Arial, sans-serif;
            font-size: 16px;
            line-height: 1.4;
            color: #000;
            background-color: #fff;
            border: 1px solid #e5e5e5;
            outline: none;
            resize: none;
            box-sizing: border-box;
            border-radius: 3px;
            box-shadow: inset 0 0 6px #fff;
        }
    </style>
@endpush

@section('content')
    <section class="block block-rounded" id="text-mechanic">
        <div class="block-header">
            <h3 class="block-title">TEXT MECHANIC</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <div class="form-group" id="sorting" aria-label="Text actions">
                <button id="asc" type="button" class="btn btn-primary">SORT ASC</button>&nbsp;
                <button id="desc" type="button" class="btn btn-primary">SORT DESC</button>&nbsp;
                <button id="length" type="button" class="btn btn-primary">SORT LENGTH</button>&nbsp;
                <button id="random" type="button" class="btn btn-primary">SORT RANDOM</button>&nbsp;
                <button id="rduplicate" type="button" class="btn btn-primary">REMOVE DUPLICATE</button>&nbsp;
                <button id="ips" type="button" class="btn btn-primary">EXTRACT IPS</button>&nbsp;
                <button id="sall" type="button" class="btn btn-primary">SELECT ALL</button>&nbsp;
                <button id="copy" type="button" class="btn btn-primary" data-clipboard-target="#text">COPY</button>&nbsp;
                <button id="clear" type="button" class="btn btn-primary">CLEAR</button>
            </div>
            <label class="sr-only" for="text">Text to process</label>
            <textarea id="text" class="form-control" wrap="off" rows="40" placeholder="PUT YOUR TEXT HERE" spellcheck="false"></textarea>
            <div id="text-mechanic-status" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
        </div>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('alphalink/tools/text-mechanic.js') }}?v={{ substr(sha1_file(public_path('alphalink/tools/text-mechanic.js')), 0, 12) }}" defer></script>
@endpush
