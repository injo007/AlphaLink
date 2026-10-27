@extends('layouts.dms-admin')

@section('title', 'Seed List Emails')

@section('content')
<div id="warmup-seed-emails"
    data-rows-url="{{ route('console.warmups.seeds.show', [$workspace->id, $seedList->id]) }}"
    data-delete-url="{{ route('console.warmups.seeds.emails.destroy', [$workspace->id, $seedList->id]) }}"
    data-edit-form-template="{{ route('console.warmups.seed-emails.edit', [$workspace->id, '__EMAIL_ID__']) }}"
    data-update-url-template="{{ route('console.warmups.seed-emails.update', [$workspace->id, '__EMAIL_ID__']) }}">
    <section class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Seed List Emails <span class="text-info">{{ $seedList->id }} - {{ $seedList->name }}</span></h3>
            <div class="block-options"><button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle fullscreen"><i class="fas fa-expand-arrows-alt" aria-hidden="true"></i></button></div>
        </div>
        <div class="block-content block-content-full">
            <div id="warmup-seed-emails-message" class="alert alert-warning py-2" role="status" aria-live="polite" hidden></div>
            <div class="table-responsive"><table id="warmup-seed-emails-table" class="table table-sm table-hover table-striped w-100"></table></div>
        </div>
    </section>
    <dialog id="warmup-seed-emails-dialog" class="warmup-seeds-dialog" aria-labelledby="warmup-seed-emails-dialog-title">
        <div class="warmup-seeds-dialog-header"><h2 id="warmup-seed-emails-dialog-title" class="h5 mb-0">Edit Email</h2><button type="button" class="btn btn-sm btn-alt-secondary" id="warmup-seed-emails-dialog-close" aria-label="Close">×</button></div>
        <div id="warmup-seed-emails-dialog-content" class="warmup-seeds-dialog-content"></div>
    </dialog>
</div>
@endsection

@push('styles')
<style>
    .warmup-seeds-dialog { border: 0; border-radius: .25rem; box-shadow: 0 .5rem 2rem rgba(0,0,0,.28); max-height: 90vh; max-width: min(680px, calc(100vw - 2rem)); padding: 0; width: 100%; }
    .warmup-seeds-dialog::backdrop { background: rgba(0,0,0,.55); }
    .warmup-seeds-dialog-header { align-items: center; border-bottom: 1px solid #e4e7ed; display: flex; justify-content: space-between; padding: 1rem; }
    .warmup-seeds-dialog-content { padding: 1rem; }
</style>
@endpush

@push('scripts')
<script src="{{ asset('dms-reference/pmta-controls.js') }}"></script>
<script src="{{ asset('dms-reference/warmup-seeds.js') }}?v={{ substr(sha1_file(public_path('dms-reference/warmup-seeds.js')), 0, 12) }}"></script>
@endpush
