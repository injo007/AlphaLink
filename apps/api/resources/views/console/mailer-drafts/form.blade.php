@extends('layouts.admin-shell')

@php($isDrop = $kind === 'drop')
@php($title = $isDrop ? 'Drop Draft' : 'Warmup Draft')
@section('title', $draft ? 'Edit '.$title : 'New '.$title)

@section('content')
<section class="block block-rounded">
    <div class="block-header">
        <h3 class="block-title">{{ $draft ? 'Edit '.$title : 'New '.$title }}</h3>
        <div class="block-options"><a class="btn btn-sm btn-alt-secondary" href="{{ $indexUrl }}">BACK TO {{ $isDrop ? 'DROPS' : 'WARMUPS' }}</a></div>
    </div>
    <div class="block-content block-content-full">
        <div class="alert alert-warning" role="status">
            <strong>Draft only.</strong> The Drop and Warmup sender is not connected to a verified AlphaLink execution path. Save stores this content as an encrypted revision. No test, send, or schedule action is available on this page.
        </div>
        @if ($errors->any())
            <div class="alert alert-danger" role="alert"><strong>Review these fields:</strong><ul class="mb-0 mt-2">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <form method="post" action="{{ $draft ? $updateUrl : $storeUrl }}">
            @csrf
            @if ($draft)
                @method('put')
                <input type="hidden" name="source_revision_id" value="{{ $draft->latestRevision?->id }}">
            @endif
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            <div class="form-group">
                <label for="mailer-draft-name">Draft name</label>
                <input class="form-control" id="mailer-draft-name" name="name" type="text" maxlength="160" required value="{{ old('name', $draft?->name ?? ($isDrop ? 'New Drop' : 'New Warmup')) }}">
            </div>

            @if ($isDrop)
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="drop-isp">ISP</label>
                        <select class="form-control" id="drop-isp" name="isp_id">
                            <option value="">Choose later</option>
                            @foreach ($isps as $isp)
                                <option value="{{ $isp->id }}" @selected((string) old('isp_id', $content['isp_id'] ?? '') === (string) $isp->id)>{{ $isp->name }}</option>
                            @endforeach
                        </select>
                        @if ($isps->isEmpty())<small class="form-text text-muted">Add an active ISP before preparing a Drop audience.</small>@endif
                    </div>
                    <div class="form-group col-md-8">
                        <label>Countries</label>
                        <div class="border rounded p-2" style="max-height:12rem;overflow:auto">
                            @php($selectedIspId = old('isp_id', $content['isp_id'] ?? ''))
                            @php($countryOptions = $lists->filter(fn ($list) => $selectedIspId === '' || (string) $list->isp_id === (string) $selectedIspId)->pluck('country')->filter()->unique()->sort()->values())
                            @if ($countryOptions->isEmpty())
                                <span class="text-muted">Country options appear when active audience lists have a country.</span>
                            @else
                                @foreach ($countryOptions as $country)
                                    <label class="d-inline-flex align-items-center mr-3 mb-1"><input class="mr-1" type="checkbox" name="countries[]" value="{{ strtoupper($country) }}" @checked(in_array(strtoupper($country), old('countries', $content['countries'] ?? []), true))>{{ strtoupper($country) }}</label>
                                @endforeach
                            @endif
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Audience lists</label>
                    <div class="border rounded p-2" style="max-height:15rem;overflow:auto">
                        @forelse ($lists as $list)
                            <label class="d-flex align-items-center mb-1"><input class="mr-2" type="checkbox" name="list_ids[]" value="{{ $list->id }}" @checked(in_array((string) $list->id, array_map('strval', old('list_ids', $content['list_ids'] ?? [])), true))><span>{{ $list->name }} <small class="text-muted">{{ $list->isp?->name ?? 'No ISP' }} · {{ $list->country ?: 'No country' }}</small></span></label>
                        @empty
                            <span class="text-muted">No active lists are available in this workspace.</span>
                        @endforelse
                    </div>
                    <small class="form-text text-muted">Lists, ISP, and countries are saved as draft selections. Recipient counts and send readiness are not calculated here.</small>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4"><label for="drop-from">From</label><input class="form-control" id="drop-from" name="from" maxlength="320" value="{{ old('from', $content['from'] ?? '') }}" placeholder="Sender name or address"></div>
                    <div class="form-group col-md-8"><label for="drop-subject">Subject</label><input class="form-control" id="drop-subject" name="subject" maxlength="998" required value="{{ old('subject', $content['subject'] ?? '') }}"></div>
                </div>
                <div class="form-group"><label for="drop-header">Header</label><textarea class="form-control resize-none" id="drop-header" name="header" rows="5">{{ old('header', $content['header'] ?? '') }}</textarea></div>
            @else
                <div class="form-row">
                    <div class="form-group col-md-6"><label for="warmup-return-path">Return path</label><input class="form-control" id="warmup-return-path" name="return_path" maxlength="320" value="{{ old('return_path', $content['return_path'] ?? '') }}" placeholder="Optional"></div>
                    <div class="form-group col-md-6"><label for="warmup-test-addresses">Email test addresses</label><textarea class="form-control resize-none" id="warmup-test-addresses" name="email_test_addresses_text" rows="2" placeholder="One address per line">{{ old('email_test_addresses_text', implode("\n", $content['email_test_addresses'] ?? [])) }}</textarea><small class="form-text text-muted">Saved for a future, verified test workflow.</small></div>
                </div>
                <div class="form-group"><label>Seed lists</label><div class="border rounded p-2" style="max-height:15rem;overflow:auto">
                    @forelse ($seedLists as $seedList)
                        <label class="d-flex align-items-center mb-1"><input class="mr-2" type="checkbox" name="seed_list_ids[]" value="{{ $seedList->id }}" @checked(in_array((int) $seedList->id, array_map('intval', old('seed_list_ids', $content['seed_list_ids'] ?? [])), true))><span>{{ $seedList->name }} <small class="text-muted">{{ number_format($seedList->emails_count) }} addresses</small></span></label>
                    @empty
                        <span class="text-muted">No active seed lists are available. <a href="{{ $seedListsUrl }}">Manage seed lists</a>.</span>
                    @endforelse
                </div><small class="form-text text-muted">Seed lists can be selected now; this draft page does not build a recipient snapshot.</small></div>
                <div class="form-row">
                    <div class="form-group col-md-4"><label for="warmup-send-type">Send mode</label><select class="form-control" id="warmup-send-type" name="send_type" required>@foreach (['delay', 'delayp', 'delayps', 'hs', 'hsp', 'hsps', 'pickup', 'pickups'] as $sendType)<option value="{{ $sendType }}" @selected(old('send_type', $content['send_type'] ?? 'delay') === $sendType)>{{ strtoupper($sendType) }}</option>@endforeach</select></div>
                    @foreach ([['total_send', 'Total send'], ['fraction_ip', 'Fraction per IP'], ['sleep_email', 'Sleep per email'], ['sleep_ip', 'Sleep per IP'], ['seed_every', 'Seed interval'], ['bounce_limit', 'Bounce limit']] as [$field, $label])
                        <div class="form-group col-md-4"><label for="warmup-{{ $field }}">{{ $label }}</label><input class="form-control" id="warmup-{{ $field }}" type="number" min="0" max="2147483647" name="{{ $field }}" value="{{ old($field, $content[$field] ?? '') }}"></div>
                    @endforeach
                </div>
                <div class="form-group"><label for="warmup-header">Header</label><textarea class="form-control resize-none" id="warmup-header" name="header" rows="6" required>{{ old('header', $content['header'] ?? '') }}</textarea></div>
            @endif

            <div class="form-group"><label for="mailer-draft-body">HTML body</label><textarea class="form-control resize-none" id="mailer-draft-body" name="html_body" rows="18" required>{{ old('html_body', $content['html_body'] ?? '') }}</textarea><small class="form-text text-muted">Saved exactly as entered. AlphaLink does not rewrite domain tags or other URLs while editing this draft.</small></div>
            <div class="d-flex flex-wrap align-items-center" style="gap:.75rem">
                <button class="btn btn-primary" type="submit">{{ $draft ? 'SAVE NEW REVISION' : 'SAVE DRAFT' }}</button>
                <a class="btn btn-alt-secondary" href="{{ $indexUrl }}">CANCEL</a>
                <span class="text-muted small">Saving never starts a send process.</span>
            </div>
        </form>
    </div>
</section>
@endsection
