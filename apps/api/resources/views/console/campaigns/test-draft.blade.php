@extends('layouts.dms-admin')

@section('title', 'New test message')

@section('content')
    <h2 class="content-heading">NEW TEST MESSAGE</h2>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert" tabindex="-1" id="test-draft-errors">
            <p class="mb-1">Review the message fields below:</p>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="alert alert-info" role="status">
        Save a single transactional message, then send it to one address you authorize from its draft page. This uses AlphaLink’s Symfony Mailer path. It does not select an audience, create a campaign send, or use DMS’s native server/IP test sender.
    </div>

    <div class="block block-rounded" id="transactional-sender-domains">
        <div class="block-header"><h3 class="block-title">Transactional sender-domain readiness</h3></div>
        <div class="block-content block-content-full">
            <p class="text-muted">This AlphaLink-only verification is for the separate Symfony transactional test flow. DMS Campaign Test uses the selected PMTA server and does not require sender-domain DNS verification.</p>
            <form method="POST" action="{{ route('console.infrastructure.domains.store', $workspace->id) }}" class="form-inline mb-3" novalidate>
                @csrf
                <div class="form-group mr-2 mb-2">
                    <label for="sender-domain-name" class="sr-only">Sender domain</label>
                    <input id="sender-domain-name" name="name" type="text" class="form-control @error('name') is-invalid @enderror" maxlength="253" autocomplete="off" placeholder="example.com" aria-invalid="@error('name') true @else false @enderror" aria-describedby="@error('name') sender-domain-error @enderror" required>
                </div>
                <button type="submit" class="btn btn-primary mb-2">Add sender domain</button>
                @error('name')<div id="sender-domain-error" class="invalid-feedback d-block" role="alert">{{ $message }}</div>@enderror
            </form>
            @if($senderDomains->isEmpty())
                <p class="mb-0">No sender domains are registered for transactional Symfony tests.</p>
            @else
                <p class="text-muted">Use a From address on a verified domain below. Publish the exact TXT proof at your DNS provider, then check it here. This does not edit DNS.</p>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-vcenter mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Domain</th>
                                <th scope="col">Status</th>
                                <th scope="col">DNS proof</th>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($senderDomains as $senderDomain)
                                <tr>
                                    <th scope="row">{{ $senderDomain->name }}</th>
                                    <td>
                                        @if($senderDomain->state === 'verified' && $senderDomain->verified_at)
                                            <span class="badge badge-success">Verified</span>
                                        @else
                                            <span class="badge badge-warning">Pending</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($senderDomain->state === 'verified' && $senderDomain->verified_at)
                                            Use an address such as <code>{{ 'sender@'.$senderDomain->name }}</code>.
                                        @else
                                            <div><strong>TXT host:</strong> <code>_alphalink-verify.{{ $senderDomain->name }}</code> <button type="button" class="btn btn-secondary btn-sm" data-copy-value="_alphalink-verify.{{ $senderDomain->name }}" aria-label="Copy TXT host for {{ $senderDomain->name }}">Copy</button></div>
                                            <div class="mt-1"><strong>TXT value:</strong> <code class="text-break">{{ $senderDomain->verification_token }}</code> <button type="button" class="btn btn-secondary btn-sm" data-copy-value="{{ $senderDomain->verification_token }}" aria-label="Copy TXT value for {{ $senderDomain->name }}">Copy</button></div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($senderDomain->state === 'verified' && $senderDomain->verified_at)
                                            <span class="text-success">Ready for transactional tests</span>
                                        @else
                                            <form method="POST" action="{{ route('console.infrastructure.domains.verify', [$workspace->id, $senderDomain->id]) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-info btn-sm">Check DNS record</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <p id="sender-domain-copy-status" class="sr-only" role="status" aria-live="polite"></p>
        </div>
    </div>

    <div class="block block-rounded">
        <div class="block-header"><h3 class="block-title">Message details</h3></div>
        <div class="block-content block-content-full">
            <form method="POST" action="{{ route('console.campaigns.store', $workspace->id) }}" novalidate>
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
                <input type="hidden" name="kind" value="transactional">
                <input type="hidden" name="header" value="{{ old('header', $dmsHeader) }}">

                <div class="form-group">
                    <label for="test-draft-name">Draft name</label>
                    <input id="test-draft-name" name="name" type="text" maxlength="160" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', 'Test message '.now()->format('Y-m-d H:i')) }}" required aria-invalid="@error('name') true @else false @enderror" @error('name') aria-describedby="test-draft-name-error" @enderror>
                    @error('name')<div class="invalid-feedback" id="test-draft-name-error">{{ $message }}</div>@enderror
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="test-draft-from-name">From name</label>
                        <input id="test-draft-from-name" name="from_name" type="text" maxlength="160" class="form-control @error('from_name') is-invalid @enderror" value="{{ old('from_name') }}" autocomplete="name" required aria-invalid="@error('from_name') true @else false @enderror" @error('from_name') aria-describedby="test-draft-from-name-error" @enderror>
                        @error('from_name')<div class="invalid-feedback" id="test-draft-from-name-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group col-md-6">
                        <label for="test-draft-from-email">From address</label>
                        <input id="test-draft-from-email" name="from_email" type="email" maxlength="320" class="form-control @error('from_email') is-invalid @enderror" value="{{ old('from_email') }}" autocomplete="email" required aria-invalid="@error('from_email') true @else false @enderror" @error('from_email') aria-describedby="test-draft-from-email-error" @enderror>
                        @error('from_email')<div class="invalid-feedback" id="test-draft-from-email-error">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">Use an address on a domain you control and have verified in the sender-domain section above. Example/test domains are blocked.</small>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="test-draft-reply-to">Reply-to address <span class="text-muted">(optional)</span></label>
                        <input id="test-draft-reply-to" name="reply_to" type="email" maxlength="320" class="form-control @error('reply_to') is-invalid @enderror" value="{{ old('reply_to') }}" autocomplete="email" aria-invalid="@error('reply_to') true @else false @enderror" @error('reply_to') aria-describedby="test-draft-reply-to-error" @enderror>
                        @error('reply_to')<div class="invalid-feedback" id="test-draft-reply-to-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group col-md-6">
                        <label for="test-draft-subject">Subject</label>
                        <input id="test-draft-subject" name="subject" type="text" maxlength="998" class="form-control @error('subject') is-invalid @enderror" value="{{ old('subject') }}" required aria-invalid="@error('subject') true @else false @enderror" @error('subject') aria-describedby="test-draft-subject-error" @enderror>
                        @error('subject')<div class="invalid-feedback" id="test-draft-subject-error">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="form-group">
                    <label for="test-draft-text">Plain-text message <span class="text-muted">(optional when HTML is supplied)</span></label>
                    <textarea id="test-draft-text" name="text_body" rows="7" maxlength="1000000" class="form-control @error('text_body') is-invalid @enderror" aria-invalid="@error('text_body') true @else false @enderror" @error('text_body') aria-describedby="test-draft-text-error" @enderror>{{ old('text_body') }}</textarea>
                    @error('text_body')<div class="invalid-feedback" id="test-draft-text-error">{{ $message }}</div>@enderror
                </div>

                <div class="form-group">
                    <label for="test-draft-html">HTML message <span class="text-muted">(optional when plain text is supplied)</span></label>
                    <textarea id="test-draft-html" name="html_body" rows="10" maxlength="2000000" class="form-control @error('html_body') is-invalid @enderror" spellcheck="false" aria-invalid="@error('html_body') true @else false @enderror" @error('html_body') aria-describedby="test-draft-html-error" @enderror>{{ old('html_body') }}</textarea>
                    @error('html_body')<div class="invalid-feedback" id="test-draft-html-error">{{ $message }}</div>@enderror
                    <small class="form-text text-muted">Symfony Mailer sends these structured fields. The stored DMS header template is not interpreted by this one-recipient test path.</small>
                </div>

                <div class="d-flex flex-wrap align-items-center">
                    <button class="btn btn-primary mr-2" type="submit">Save test message draft</button>
                    <a class="btn btn-alt-secondary" href="{{ route('console.campaigns.index', $workspace->id) }}">Cancel</a>
                </div>
                <p class="text-muted small mt-3 mb-0">Saving creates a draft only. Sending happens on the next page, after AlphaLink confirms that the From domain is verified in this workspace.</p>
            </form>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        #test-draft-text, #test-draft-html { resize: none; }
    </style>
@endpush

@push('scripts')
    <script>
        (() => {
            const firstInvalidField = document.querySelector('.is-invalid');
            if (firstInvalidField) {
                firstInvalidField.focus();
            }

            document.querySelectorAll('[data-copy-value]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const value = button.dataset.copyValue;
                    const status = document.getElementById('sender-domain-copy-status');
                    try {
                        await navigator.clipboard.writeText(value);
                        status.textContent = `${button.getAttribute('aria-label')} copied.`;
                    } catch {
                        const field = document.createElement('textarea');
                        field.value = value;
                        field.setAttribute('readonly', '');
                        field.style.position = 'fixed';
                        field.style.opacity = '0';
                        document.body.appendChild(field);
                        field.select();
                        const copied = document.execCommand('copy');
                        field.remove();
                        status.textContent = copied ? `${button.getAttribute('aria-label')} copied.` : 'Copy failed. Select and copy the value from its field.';
                    }
                });
            });
        })();
    </script>
@endpush
