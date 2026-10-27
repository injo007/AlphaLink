@extends('layouts.dms-admin')

@section('title', $campaign->name)

@section('content')
    @php
        $content = $campaign->latestRevision?->payload ?? [];
    @endphp
    <div class="content-heading">Campaign draft: {{ $campaign->name }}</div>

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Latest saved message</h3>
            <div class="block-options">
                @if ($campaign->latestRevision)
                    <span class="badge badge-primary">REVISION {{ $campaign->latestRevision->revision_number }}</span>
                @else
                    <span class="badge badge-secondary">NO CONTENT</span>
                @endif
            </div>
        </div>
        <div class="block-content block-content-full">
            @if ($campaign->latestRevision)
                <dl class="row mb-3">
                    @if ($campaign->kind === 'transactional')
                        <dt class="col-sm-2">From</dt><dd class="col-sm-10">{{ $content['from_name'] ?? '' }} &lt;{{ $content['from_email'] ?? '' }}&gt;</dd>
                        <dt class="col-sm-2">Reply-to</dt><dd class="col-sm-10">{{ $content['reply_to'] ?? 'Not set' }}</dd>
                        <dt class="col-sm-2">Subject</dt><dd class="col-sm-10">{{ $content['subject'] ?? '' }}</dd>
                    @endif
                    <dt class="col-sm-2">Return path</dt><dd class="col-sm-10"><code>{{ $content['return_path'] ?? '[rnd]@[domain]' }}</code></dd>
                    <dt class="col-sm-2">Email Test</dt>
                    <dd class="col-sm-10">
                        @if (! empty($content['email_tests']))
                            {{ implode(', ', $content['email_tests']) }}
                        @else
                            <span class="text-muted">No DMS test addresses saved.</span>
                        @endif
                    </dd>
                    <dt class="col-sm-2">DMS Header</dt><dd class="col-sm-10"><pre class="campaign-message-preview">{{ $content['header'] ?? '' }}</pre></dd>
                    <dt class="col-sm-2">Content fingerprint</dt><dd class="col-sm-10"><code>{{ $campaign->latestRevision->content_sha256 }}</code></dd>
                </dl>
                @if (filled($content['body'] ?? null))
                    <h4 class="h6">DMS Body template</h4>
                    <pre class="campaign-message-preview">{{ $content['body'] }}</pre>
                @endif
                @if ($campaign->kind === 'transactional' && filled($content['text_body'] ?? null))
                    <h4 class="h6">Plain-text body</h4>
                    <pre class="campaign-message-preview">{{ $content['text_body'] }}</pre>
                @endif
                @if ($campaign->kind === 'transactional' && filled($content['html_body'] ?? null))
                    <details class="mt-3">
                        <summary>View saved HTML source</summary>
                        <pre class="campaign-message-preview mt-2">{{ $content['html_body'] }}</pre>
                    </details>
                @endif
            @else
                <p class="mb-0">No saved content revision is attached yet.</p>
            @endif
        </div>
    </div>

    @if (is_array($sendSetup))
        @php
            $availableSponsors = collect($sendOptions['sponsors'] ?? []);
            $availableOffers = collect($sendOptions['offers'] ?? []);
            $availableNegatives = collect($sendOptions['negatives'] ?? []);
            $availableIsps = collect($sendOptions['isps'] ?? []);
            $availableLists = collect($sendOptions['lists'] ?? [])->keyBy('id');
            $availableServers = collect($sendOptions['servers'] ?? [])->keyBy('id');
            $availableIps = collect($sendOptions['ips'] ?? [])->keyBy('id');
            $sponsorChoice = $availableSponsors->firstWhere('id', $sendSetup['sponsor_id'] ?? null);
            $offerChoice = $availableOffers->firstWhere('id', $sendSetup['offer_id'] ?? null);
            $offerAssets = collect($offerChoice['assets'] ?? []);
            $creativeChoice = $offerAssets->firstWhere('id', $sendSetup['creative_asset_id'] ?? null);
            $htmlChoice = $offerAssets->firstWhere('id', $sendSetup['html_asset_id'] ?? null);
            $negativeChoice = $availableNegatives->firstWhere('id', $sendSetup['negative_id'] ?? null);
            $ispChoice = $availableIsps->firstWhere('id', $sendSetup['isp_id'] ?? null);
        @endphp
        <div class="block block-rounded">
            <div class="block-header">
                <h3 class="block-title">Saved DMS campaign selections</h3>
                <div class="block-options"><span class="badge badge-warning">DRAFT · NOT SEND-READY</span></div>
            </div>
            <div class="block-content block-content-full">
                <div class="alert alert-warning" role="status">DMS-mapped list locks, workspace suppression, selected-offer unsubscribe exclusions, offer-file suppression, and the point-in-time recipient manifest are applied when this draft is created. The selected negative file ID is saved with the draft, but its native sender behavior is not verified. AlphaLink has not implemented or verified DMS’s native sender, so this campaign cannot be launched.</div>
                <dl class="row mb-3">
                    <dt class="col-sm-3">Sponsor</dt><dd class="col-sm-9">{{ $sponsorChoice['name'] ?? 'No longer available' }} (ID {{ $sendSetup['sponsor_id'] ?? '—' }})</dd>
                    <dt class="col-sm-3">Offer</dt><dd class="col-sm-9">{{ $offerChoice['name'] ?? 'No longer eligible' }} (ID {{ $sendSetup['offer_id'] ?? '—' }})</dd>
                    <dt class="col-sm-3">ISP</dt><dd class="col-sm-9">{{ $ispChoice['name'] ?? 'No longer available' }} (ID {{ $sendSetup['isp_id'] ?? '—' }})</dd>
                    <dt class="col-sm-3">Country</dt><dd class="col-sm-9">Offer {{ $sendSetup['offer_country'] ?? '—' }} · List {{ $sendSetup['country'] ?? '—' }}</dd>
                    <dt class="col-sm-3">DMS domain</dt><dd class="col-sm-9">{{ $sendSetup['domain'] ?? 'Not selected' }}</dd>
                    <dt class="col-sm-3">DMS negative file</dt><dd class="col-sm-9">{{ ($sendSetup['negative_id'] ?? null) ? ($negativeChoice['name'] ?? 'No longer available').' (ID '.$sendSetup['negative_id'].')' : 'Not selected' }}</dd>
                    <dt class="col-sm-3">Creative image</dt><dd class="col-sm-9">{{ $creativeChoice['value'] ?? 'Not selected or no longer available' }}</dd>
                    <dt class="col-sm-3">HTML template</dt><dd class="col-sm-9">{{ $htmlChoice ? 'Selected (ID '.$htmlChoice['id'].')' : 'Not selected or no longer available' }}</dd>
                    <dt class="col-sm-3">Order / start</dt><dd class="col-sm-9">{{ strtoupper($sendSetup['order_by'] ?? 'default') }} / {{ $sendSetup['start_from'] ?? 0 }}</dd>
                    <dt class="col-sm-3">Routing</dt><dd class="col-sm-9">{{ strtoupper($sendSetup['route_option'] ?? 'IP') }} · {{ strtoupper($sendSetup['ip_type'] ?? 'ALL') }}</dd>
                    <dt class="col-sm-3">Send mode</dt><dd class="col-sm-9">{{ strtoupper($sendSetup['send_type'] ?? '—') }}</dd>
                    <dt class="col-sm-3">Selected list records</dt>
                    <dd class="col-sm-9">
                        <ul class="mb-0">
                            @foreach ($sendSetup['audience_list_ids'] ?? [] as $listId)
                                @php
                                    $listChoice = $availableLists->get($listId);
                                @endphp
                                <li>{{ $listChoice['name'] ?? 'Unavailable list' }} — {{ $listChoice['country'] ?? 'saved record' }} (ID {{ $listId }})</li>
                            @endforeach
                        </ul>
                        <small class="text-muted">Selected DMS email types: {{ collect($sendSetup['audience_segments'] ?? [])->map(fn ($segment) => strtoupper(substr(strrchr($segment, '_') ?: '', 1)))->unique()->implode(', ') }}.</small>
                    </dd>
                    <dt class="col-sm-3">Selected servers</dt>
                    <dd class="col-sm-9">
                        <ul class="mb-0">
                            @foreach ($sendSetup['server_ids'] ?? [] as $serverId)
                                @php
                                    $serverChoice = $availableServers->get($serverId);
                                @endphp
                                <li>{{ $serverChoice['name'] ?? 'Unavailable server' }} {{ isset($serverChoice['ip']) ? '· '.$serverChoice['ip'] : '' }} (ID {{ $serverId }})</li>
                            @endforeach
                        </ul>
                    </dd>
                    <dt class="col-sm-3">Selected IP / MTA records</dt>
                    <dd class="col-sm-9">
                        <ul class="mb-0">
                            @foreach ($sendSetup['ip_ids'] ?? [] as $ipId)
                                @php
                                    $ipChoice = $availableIps->get($ipId);
                                @endphp
                                <li>{{ $ipChoice['ip'] ?? 'Unavailable record' }} {{ isset($ipChoice['type']) ? '· '.strtoupper($ipChoice['type']) : '' }} (ID {{ $ipId }})</li>
                            @endforeach
                        </ul>
                    </dd>
                </dl>
                <p class="mb-0 text-muted">Send parameters: {{ json_encode($sendSetup['parameters'] ?? [], JSON_UNESCAPED_SLASHES) }}</p>
                @if ($recipientSnapshot)
                    <hr>
                    <h4 class="h6">Immutable recipient snapshot</h4>
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Eligible addresses</dt><dd class="col-sm-9">{{ number_format($recipientSnapshot->recipient_count) }}</dd>
                        <dt class="col-sm-3">Offer suppressed</dt><dd class="col-sm-9">{{ number_format($recipientSnapshot->offer_suppressed_count) }}</dd>
                        <dt class="col-sm-3">Workspace suppressed</dt><dd class="col-sm-9">{{ number_format($recipientSnapshot->workspace_suppressed_count) }}</dd>
                        <dt class="col-sm-3">Duplicate addresses</dt><dd class="col-sm-9">{{ number_format($recipientSnapshot->duplicate_count) }}</dd>
                        <dt class="col-sm-3">Manifest SHA-256</dt><dd class="col-sm-9"><code>{{ $recipientSnapshot->recipients_sha256 }}</code></dd>
                    </dl>
                @else
                    <p class="mb-0 text-danger">No recipient snapshot is attached to this revision. It cannot be launched.</p>
                @endif
            </div>
        </div>
    @endif

    @if ($campaign->kind === 'marketing' && is_array($sendSetup) && $campaign->status === 'draft' && $campaign->is_active)
        <div id="pmta-test" class="block block-rounded">
            <div class="block-header"><h3 class="block-title">Send a PMTA campaign test</h3></div>
            <div class="block-content block-content-full">
                <p>Select a saved campaign server and Email Test recipient. AlphaLink connects to that server’s PMTA SMTP listener using the workspace port, falling back to the configured installation default when no workspace override is saved. Review the From, subject and body below before explicitly sending. Symfony Mailer builds this test MIME message; DMS’s native header, placeholder and VMTA behavior is not reproduced.</p>
                @if (! $pmtaPort)
                    <div class="alert alert-warning">Configure a valid PMTA SMTP listener port in installation settings before testing.</div>
                @else
                    <form method="POST" action="{{ route('console.campaigns.pmta-test', [$workspace->id, $campaign->id]) }}">
                        @csrf
                        <input type="hidden" name="revision_id" value="{{ $campaign->latestRevision->id }}">
                        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <div class="form-row">
                            <div class="form-group col-md-6"><label for="pmta-test-server">Selected PMTA server</label><select id="pmta-test-server" name="server_id" class="form-control" required><option value="">Select server</option>@foreach ($sendSetup['server_ids'] ?? [] as $serverId)@if ($availableServers->has($serverId))<option value="{{ $serverId }}">{{ $availableServers->get($serverId)['name'] }} · {{ $availableServers->get($serverId)['ip'] }}:{{ $pmtaPort }}</option>@endif @endforeach</select></div>
                            <div class="form-group col-md-6"><label for="pmta-test-recipient">Email Test recipient</label><select id="pmta-test-recipient" name="recipient" class="form-control" required><option value="">Select recipient</option>@foreach ($content['email_tests'] ?? [] as $address)<option value="{{ $address }}">{{ $address }}</option>@endforeach</select></div>
                            <div class="form-group col-md-6"><label for="pmta-test-from">From address</label><input id="pmta-test-from" name="from_email" type="email" maxlength="320" class="form-control" required></div>
                            <div class="form-group col-md-6"><label for="pmta-test-subject">Subject</label><input id="pmta-test-subject" name="subject" type="text" maxlength="998" class="form-control" required></div>
                            <div class="form-group col-12"><label for="pmta-test-body">HTML body sent in this test</label><textarea id="pmta-test-body" name="body" rows="8" class="form-control" required>{{ $content['body'] ?? '' }}</textarea></div>
                        </div>
                        <div class="form-check mb-3"><input class="form-check-input" id="pmta-test-authorized" name="confirm_authorized_recipient" type="checkbox" value="1" required><label class="form-check-label" for="pmta-test-authorized">I authorize one test message to this recipient through the selected PMTA server.</label></div>
                        <div class="form-check mb-3"><input class="form-check-input" id="pmta-test-retry" name="confirm_retry_unknown" type="checkbox" value="1"><label class="form-check-label" for="pmta-test-retry">I checked an uncertain earlier result and authorize a retry.</label></div>
                        <button class="btn btn-warning" type="submit">Send PMTA test email</button>
                    </form>
                @endif
            </div>
        </div>
    @endif

    @if ($campaign->kind === 'transactional')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Send one test email</h3>
            <div class="block-options">
                @if ($smtpReady)
                    @if ($latestSmtpCheck?->action === 'campaign.smtp_connection_succeeded')
                        <span class="badge badge-success">LAST SMTP CHECK SUCCEEDED</span>
                    @elseif ($latestSmtpCheck?->action === 'campaign.smtp_connection_failed')
                        <span class="badge badge-danger">LAST SMTP CHECK FAILED</span>
                    @else
                        <span class="badge badge-warning">SMTP SETTINGS PRESENT · NOT CHECKED</span>
                    @endif
                @else
                    <span class="badge badge-warning">SMTP NOT CONFIGURED</span>
                @endif
            </div>
        </div>
        <div class="block-content block-content-full">
            <p class="text-muted">This uses the saved revision below and AlphaLink’s Symfony Mailer SMTP transport. AlphaLink sends to only the address entered here; it does not load audience lists or start a campaign send.</p>
            @if ($smtpDestination)
                <div class="alert alert-info" role="status">
                    <strong>Effective Symfony SMTP destination:</strong>
                    <code>{{ $smtpDestination['host'] }}:{{ $smtpDestination['port'] }}</code>
                    <span class="ml-2">{{ $smtpDestination['security'] }}</span>
                </div>
            @else
                <div class="alert alert-secondary" role="status">The active Symfony SMTP destination is not available from the configured transport.</div>
            @endif
            <div class="alert alert-warning" role="status">An SMTP acceptance means the configured mail server accepted the message. It does not prove inbox delivery. If the result is uncertain, check the recipient mailbox and SMTP logs before retrying.</div>
            @if ($smtpReady && $latestSmtpCheck)
                <p class="text-muted small" role="status">
                    Latest no-email SMTP check: {{ $latestSmtpCheck->action === 'campaign.smtp_connection_succeeded' ? 'handshake succeeded' : 'handshake failed' }}
                    at {{ $latestSmtpCheck->occurred_at->timezone(config('app.timezone'))->format('Y-m-d H:i T') }}.
                    Sending opens a fresh SMTP connection; a handshake does not confirm relay permission or delivery.
                </p>
            @endif

            <form method="POST" action="{{ route('console.campaigns.smtp-check', $workspace->id) }}" class="mb-4">
                @csrf
                <button class="btn btn-alt-secondary" type="submit">Check SMTP connection (no email)</button>
                <span class="text-muted small ml-2">Uses AlphaLink’s configured SMTP settings and records whether the server handshake succeeds.</span>
            </form>

            @if (! $smtpReady)
                <div class="alert alert-danger" role="alert">SMTP sending is not configured. Set <code>MAIL_MAILER=smtp</code>, <code>MAIL_HOST</code>, and <code>MAIL_PORT</code> in the environment loaded by this AlphaLink runtime, then reload its configuration. A log mailer or the default localhost placeholder cannot send this test.</div>
            @elseif ($campaign->status !== 'draft' || ! $campaign->is_active)
                <div class="alert alert-info" role="status">Only active draft campaigns can send a test message.</div>
            @elseif (! $campaign->latestRevision)
                <div class="alert alert-info" role="status">Save message content before sending a test.</div>
            @elseif ($senderDomainIsReserved)
                <div class="alert alert-danger" role="alert">
                    This saved revision uses a reserved example/test sender domain, so it cannot deliver a real email. Add a domain you control under
                    <a href="{{ route('console.campaigns.test-draft.create', $workspace->id) }}#transactional-sender-domains">New Test Message sender-domain settings</a>, publish its AlphaLink verification TXT record, and verify ownership. Then save a new revision with a From address at that domain.
                </div>
            @elseif (! $senderDomainIsVerified)
                <div class="alert alert-danger" role="alert">
                    The saved sender domain is not verified in this workspace. Add a domain you control under
                    <a href="{{ route('console.campaigns.test-draft.create', $workspace->id) }}#transactional-sender-domains">New Test Message sender-domain settings</a>, publish its AlphaLink verification TXT record, verify ownership, then save a new revision with a From address at that exact domain.
                </div>
            @else
                <form method="POST" action="{{ route('console.campaigns.test-email', [$workspace->id, $campaign->id]) }}" class="campaign-test-form">
                    @csrf
                    <input type="hidden" name="revision_id" value="{{ $campaign->latestRevision->id }}">
                    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <div class="form-group">
                        <label for="campaign-test-recipient">One test recipient</label>
                        <input id="campaign-test-recipient" name="recipient" type="email" maxlength="320" class="form-control" value="{{ old('recipient') }}" autocomplete="email" required>
                    </div>
                    <div class="custom-control custom-checkbox mb-3">
                        <input id="campaign-test-authorized" name="confirm_authorized_recipient" value="1" type="checkbox" class="custom-control-input" required>
                        <label class="custom-control-label" for="campaign-test-authorized">I am authorized to send this test message to this address.</label>
                    </div>
                    <div class="custom-control custom-checkbox mb-3">
                        <input id="campaign-test-retry-unknown" name="confirm_retry_unknown" value="1" type="checkbox" class="custom-control-input">
                        <label class="custom-control-label" for="campaign-test-retry-unknown">If an earlier result for this address is unknown, I checked the mailbox and SMTP logs and authorize one retry.</label>
                    </div>
                    <button class="btn btn-primary" type="submit">Send one test email</button>
                </form>
            @endif
        </div>
    </div>
    @endif

    @if ($campaign->kind === 'transactional' && $campaign->status === 'draft' && $campaign->is_active)
        <details id="edit-campaign" class="block block-rounded">
            <summary class="block-header campaign-details-summary">{{ $campaign->kind === 'marketing' ? 'Edit campaign' : 'Save a new content revision' }}</summary>
            <div class="block-content block-content-full">
                <p class="text-muted">Saved revisions do not change after creation. Saving here creates a new revision; old test attempts remain attached to the revision they used.</p>
                <form method="POST" action="{{ route('console.campaigns.revisions.store', [$workspace->id, $campaign->id]) }}">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="expected_revision_id" value="{{ $campaign->latestRevision->id }}">
                    <div class="form-row">
                        @if ($campaign->kind === 'transactional')
                        <div class="form-group col-md-6">
                            <label for="revision-from-name">From name</label>
                            <input id="revision-from-name" name="from_name" type="text" maxlength="160" class="form-control" value="{{ $content['from_name'] ?? '' }}" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="revision-from-email">From address</label>
                            <input id="revision-from-email" name="from_email" type="email" maxlength="320" class="form-control" value="{{ $content['from_email'] ?? '' }}" required>
                            <small class="form-text text-muted">Use an address at a domain you control and have verified in <a href="{{ route('console.infrastructure.domains.index', $workspace->id) }}">Infrastructure &gt; Domains</a>. Reserved example/test domains are blocked.</small>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="revision-reply-to">Reply-to address <span class="text-muted">(optional)</span></label>
                            <input id="revision-reply-to" name="reply_to" type="email" maxlength="320" class="form-control" value="{{ $content['reply_to'] ?? '' }}">
                        </div>
                        @endif
                        <div class="form-group col-md-6">
                            <label for="revision-return-path">Return path</label>
                            <input id="revision-return-path" name="return_path" type="text" maxlength="320" class="form-control" value="{{ $content['return_path'] ?? '[rnd]@[domain]' }}">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="revision-email-tests">Email Test addresses</label>
                            <textarea id="revision-email-tests" name="email_test_addresses" rows="3" class="form-control" autocomplete="off" required>{{ implode("\n", $content['email_tests'] ?? []) }}</textarea>
                            <small class="form-text text-muted">At least one address, one per line. Repeated entries are preserved. Saved with the new immutable revision; not sent by AlphaLink's one-recipient Symfony test.</small>
                        </div>
                        @if (is_array($sendSetup))
                            <div class="form-group col-md-6">
                                <label for="revision-domain">DMS campaign domain</label>
                                <select id="revision-domain" name="domain" class="form-control selectpicker" data-live-search="true" data-allow-clear="true" title="Select Domain">
                                    <option value="">Select Domain</option>
                                    @foreach (($sendOptions['campaign_domains'] ?? []) as $domainOption)
                                        <option value="{{ $domainOption['value'] }}" @selected(($sendSetup['domain'] ?? '') === $domainOption['value'])>{{ $domainOption['name'] }} — {{ $domainOption['dns'] }}</option>
                                    @endforeach
                                </select>
                                <small class="form-text text-muted">Only current active redirect domains and domains on installed, accessible servers are accepted.</small>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="revision-negative">Negative</label>
                                <select id="revision-negative" name="send_setup[negative_id]" class="form-control selectpicker" data-live-search="true" data-allow-clear="true" title="Select Negative">
                                    <option value="">Select Negative</option>
                                    @foreach (($sendOptions['negatives'] ?? []) as $negativeOption)
                                        <option value="{{ $negativeOption['id'] }}" @selected((string) old('send_setup.negative_id', $sendSetup['negative_id'] ?? '') === (string) $negativeOption['id'])>{{ $negativeOption['name'] }}</option>
                                    @endforeach
                                </select>
                                <small class="form-text text-muted">DMS saves the selected file ID. This field does not alter the saved recipient snapshot.</small>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="revision-creative-asset">Creative image</label>
                                <select id="revision-creative-asset" name="send_setup[creative_asset_id]" class="form-control selectpicker" data-live-search="true" title="Select Creative">
                                    <option value="">Select Creative</option>
                                    @foreach (($offerChoice['assets'] ?? []) as $asset)
                                        @if ($asset['type'] === 'creative')
                                            <option value="{{ $asset['id'] }}" data-asset-value="{{ $asset['value'] }}" @selected((string) old('send_setup.creative_asset_id', $sendSetup['creative_asset_id'] ?? '') === (string) $asset['id'])>{{ $asset['value'] }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="revision-html-asset">HTML</label>
                                <select id="revision-html-asset" name="send_setup[html_asset_id]" class="form-control selectpicker" data-live-search="true" title="Select HTML">
                                    <option value="">Select HTML</option>
                                    @foreach (($offerChoice['assets'] ?? []) as $asset)
                                        @if ($asset['type'] === 'html')
                                            <option value="{{ $asset['id'] }}" data-asset-value="{{ $asset['value'] }}" @selected((string) old('send_setup.html_asset_id', $sendSetup['html_asset_id'] ?? '') === (string) $asset['id'])>{{ \Illuminate\Support\Str::limit($asset['value'], 50) }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                <small class="form-text text-muted">Selecting an HTML asset replaces the Body with its DMS template and substitutes the selected domain.</small>
                            </div>
                        @endif
                        @if (is_array($sendSetup))
                            <div class="form-group col-md-6"><label for="revision-from-assets">From assets</label><select id="revision-from-assets" name="send_setup[from_asset_ids][]" class="form-control selectpicker" multiple data-live-search="true">@foreach (($offerChoice['assets'] ?? []) as $asset)@if ($asset['type'] === 'from')<option value="{{ $asset['id'] }}" @selected(in_array($asset['id'], $sendSetup['from_asset_ids'] ?? []))>{{ $asset['value'] }}</option>@endif @endforeach</select></div>
                            <div class="form-group col-md-6"><label for="revision-subject-assets">Subject assets</label><select id="revision-subject-assets" name="send_setup[subject_asset_ids][]" class="form-control selectpicker" multiple data-live-search="true">@foreach (($offerChoice['assets'] ?? []) as $asset)@if ($asset['type'] === 'subject')<option value="{{ $asset['id'] }}" @selected(in_array($asset['id'], $sendSetup['subject_asset_ids'] ?? []))>{{ $asset['value'] }}</option>@endif @endforeach</select></div>
                            <div class="form-group col-md-4"><label for="revision-route">Routing option</label><select id="revision-route" name="send_setup[route_option]" class="form-control">@foreach (['ip' => 'IP', 'vmta' => 'VMTA', 'smtp' => 'SMTP'] as $value => $label)<option value="{{ $value }}" @selected(($sendSetup['route_option'] ?? 'ip') === $value)>{{ $label }}</option>@endforeach</select></div>
                            <div class="form-group col-md-4"><label for="revision-ip-type">IP type</label><select id="revision-ip-type" name="send_setup[ip_type]" class="form-control">@foreach (['all' => 'ALL', 'ipv4' => 'IPv4', 'ipv6' => 'IPv6'] as $value => $label)<option value="{{ $value }}" @selected(($sendSetup['ip_type'] ?? 'all') === $value)>{{ $label }}</option>@endforeach</select></div>
                            <div class="form-group col-md-4"><label for="revision-send-type">Send type</label><select id="revision-send-type" name="send_setup[send_type]" class="form-control">@foreach (['delay', 'delayp', 'delayps', 'hs', 'hsp', 'hsps', 'pickup', 'pickups'] as $value)<option value="{{ $value }}" @selected(($sendSetup['send_type'] ?? '') === $value)>{{ strtoupper($value) }}</option>@endforeach</select></div>
                            <div class="form-group col-md-6"><label for="revision-servers">Servers</label><select id="revision-servers" name="send_setup[server_ids][]" class="form-control selectpicker" multiple required data-live-search="true">@foreach ($sendOptions['servers'] ?? [] as $server)<option value="{{ $server['id'] }}" @selected(in_array($server['id'], $sendSetup['server_ids'] ?? []))>{{ $server['name'] }} · {{ $server['ip'] }}</option>@endforeach</select></div>
                            <div class="form-group col-md-6"><label for="revision-ips">IPs / MTAs</label><select id="revision-ips" name="send_setup[ip_ids][]" class="form-control selectpicker" multiple required data-live-search="true">@foreach ($sendOptions['ips'] ?? [] as $ip)<option value="{{ $ip['id'] }}" @selected(in_array($ip['id'], $sendSetup['ip_ids'] ?? []))>{{ $ip['ip'] }} · {{ strtoupper($ip['type']) }}</option>@endforeach</select></div>
                            @foreach (['total_send' => ['Total send', 1], 'fraction_ip' => ['Fraction IP', 1], 'sleep_email' => ['Sleep between emails', 0], 'sleep_ip' => ['Sleep between IPs', 0], 'seed_every' => ['Test after', 0], 'bounce_limit' => ['Bounce limit', 0], 'duplicate_data' => ['Duplicate data', 1]] as $field => [$label, $minimum])
                                <div class="form-group col-md-3"><label for="revision-{{ $field }}">{{ $label }}</label><input id="revision-{{ $field }}" name="send_setup[{{ $field }}]" type="number" min="{{ $minimum }}" class="form-control" value="{{ $sendSetup['parameters'][$field] ?? '' }}" required></div>
                            @endforeach
                        @endif
                        @include('console.campaigns.partials.header-library', ['workspace' => $workspace, 'textareaId' => 'revision-dms-header', 'idPrefix' => 'revision'])
                        <div class="form-group col-12">
                            <label for="revision-dms-header">Header</label>
                            <textarea id="revision-dms-header" name="header" rows="8" class="form-control" spellcheck="false" required>{{ $content['header'] ?? "MIME-version: 1.0\nContent-type: text/html\nTo: [to]\nfrom: [from] <info@[domain]>\nSubject: [subject]\nDate: [date]" }}</textarea>
                        </div>
                        <div class="form-group col-12">
                            <label for="revision-dms-body">DMS Body template</label>
                            <textarea id="revision-dms-body" name="body" rows="8" class="form-control" spellcheck="false">{{ $content['body'] ?? '' }}</textarea>
                        </div>
                        @if (is_array($sendSetup))
                            <div class="form-row">
                                @include('console.campaigns.partials.placeholder-editor', [
                                    'idPrefix' => 'revision',
                                    'rows' => old('send_setup.placeholder_inputs', collect($sendSetup['placeholders'] ?? [])->map(fn (array $placeholder): array => [
                                        'data_text' => implode("\n", $placeholder['data'] ?? []),
                                        'rotate_every' => $placeholder['rotate_every'] ?? 1,
                                    ])->all()),
                                    'combination' => old('send_setup.placeholder_combination', $sendSetup['placeholder_combination'] ?? false),
                                    'allCombination' => old('send_setup.placeholder_all_combination', $sendSetup['placeholder_all_combination'] ?? false),
                                ])
                            </div>
                        @endif
                        @if ($campaign->kind === 'transactional')
                        <div class="form-group col-12">
                            <label for="revision-subject">Subject</label>
                            <input id="revision-subject" name="subject" type="text" maxlength="998" class="form-control" value="{{ $content['subject'] ?? '' }}" required>
                        </div>
                        <div class="form-group col-12">
                            <label for="revision-text-body">Plain-text message</label>
                            <textarea id="revision-text-body" name="text_body" rows="7" class="form-control">{{ $content['text_body'] ?? '' }}</textarea>
                        </div>
                        <div class="form-group col-12">
                            <label for="revision-html-body">HTML message <span class="text-muted">(optional when plain text is present)</span></label>
                            <textarea id="revision-html-body" name="html_body" rows="7" class="form-control" spellcheck="false">{{ $content['html_body'] ?? '' }}</textarea>
                        </div>
                        @endif
                    </div>
                    <button class="btn btn-primary" type="submit">Save new revision</button>
                </form>
            </div>
        </details>
    @endif

    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Test attempt history</h3>
            <div class="block-options">Most recent {{ $testMailAttempts->count() }}</div>
        </div>
        <div class="block-content block-content-full">
            @if ($testMailAttempts->isEmpty())
                <p class="text-center mb-0">No test email has been attempted for this campaign.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead><tr><th scope="col">TIME</th><th scope="col">REVISION</th><th scope="col">TRANSPORT</th><th scope="col">RESULT</th></tr></thead>
                        <tbody>
                            @foreach ($testMailAttempts as $attempt)
                                <tr>
                                    <td>{{ $attempt->created_at?->format('Y-m-d H:i:s') }}</td>
                                    <td>{{ $attempt->revision?->revision_number ?? '—' }}</td>
                                    <td>{{ strtoupper($attempt->mailer) }}</td>
                                    <td>
                                        @if ($attempt->status === 'accepted_by_transport')
                                            SMTP accepted; delivery unverified
                                        @elseif ($attempt->status === 'transport_unavailable')
                                            No email sent; SMTP connection unavailable
                                        @else
                                            Result unknown; check mailbox and SMTP logs
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div id="campaign-test-logs" class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Campaign test logs</h3>
            <div class="block-options">Showing {{ $manualTestAttempts->count() }} of {{ $manualTestAttempts->total() }}</div>
        </div>
        <div class="block-content block-content-full">
            @if ($manualTestAttempts->count() === 0)
                <p class="text-center mb-0">No campaign test has been attempted.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead><tr><th scope="col">TIME</th><th scope="col">SERVER</th><th scope="col">SOURCE</th><th scope="col">RESULT</th></tr></thead>
                        <tbody>
                            @foreach ($manualTestAttempts as $attempt)
                                @php
                                    $campaignTestResult = match ($attempt->status) {
                                        'accepted_by_transport' => 'PMTA accepted; mailbox delivery unconfirmed',
                                        'rejected_by_transport' => 'Rejected by PMTA; no message accepted',
                                        'transport_unavailable' => 'PMTA connection unavailable; no message accepted',
                                        'partially_accepted' => 'Partially accepted; inspect PMTA logs',
                                        'outcome_unknown', 'preparing' => 'Outcome unknown; check PMTA logs and mailbox',
                                        default => 'Test failed; inspect application logs',
                                    };
                                @endphp
                                <tr>
                                    <td>{{ \Illuminate\Support\Carbon::parse($attempt->created_at)->format('Y-m-d H:i:s') }}</td>
                                    <td>{{ $attempt->server_name ?? 'Server unavailable' }}</td>
                                    <td>Campaign Test</td>
                                    <td>{{ $campaignTestResult }}</td>
                                </tr>
                                <tr>
                                    <td colspan="4" class="pt-0">
                                        @if (is_array($attempt->test_snapshot))
                                            <details class="campaign-test-log-details">
                                                <summary>View test configuration</summary>
                                                @php
                                                    $testSnapshot = $attempt->test_snapshot;
                                                    $testServers = collect($testSnapshot['servers'] ?? [])
                                                        ->map(fn (array $server): string => trim(($server['name'] ?? 'Server unavailable').' · '.($server['id'] ?? '')))
                                                        ->implode("\n");
                                                @endphp
                                                <div class="row mt-3">
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>Email Test addresses</label>
                                                            <textarea class="form-control" rows="2" readonly>{{ implode("\n", $testSnapshot['email_tests'] ?? []) }}</textarea>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Return-Path submitted</label>
                                                            <input class="form-control" type="text" value="{{ $testSnapshot['return_path'] ?? '' }}" readonly>
                                                        </div>
                                                        @if (array_key_exists('resolved_return_path', $testSnapshot))
                                                            <div class="form-group">
                                                                <label>Return-Path used for the first test message</label>
                                                                <input class="form-control" type="text" value="{{ $testSnapshot['resolved_return_path'] }}" readonly>
                                                            </div>
                                                        @endif
                                                        <div class="form-group">
                                                            <label>Selected servers</label>
                                                            <textarea class="form-control" rows="2" readonly>{{ $testServers }}</textarea>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Selected IP IDs</label>
                                                            <input class="form-control" type="text" value="{{ implode(', ', array_map('strval', $testSnapshot['ips'] ?? [])) }}" readonly>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Route · IP type · Send type</label>
                                                            <input class="form-control" type="text" value="{{ strtoupper(($testSnapshot['mta'] ?? '—').' · '.($testSnapshot['type'] ?? '—').' · '.($testSnapshot['send_type'] ?? '—')) }}" readonly>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Domain · Negative · Creative</label>
                                                            <input class="form-control" type="text" value="{{ ($testSnapshot['domain'] ?? '—').' · '.($testSnapshot['negative_id'] ?? '—').' · '.($testSnapshot['creative_id'] ?? '—') }}" readonly>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>From asset IDs · Subject asset IDs</label>
                                                            <input class="form-control" type="text" value="{{ implode(', ', array_map('strval', $testSnapshot['from'] ?? [])).' · '.implode(', ', array_map('strval', $testSnapshot['subjects'] ?? [])) }}" readonly>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Placeholder settings</label>
                                                            <textarea class="form-control" rows="3" readonly>{{ json_encode([
                                                                'combination' => $testSnapshot['placeholder_combination'] ?? false,
                                                                'all_combination' => $testSnapshot['placeholder_all_combination'] ?? false,
                                                                'placeholders' => $testSnapshot['placeholders'] ?? [],
                                                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Header submitted</label>
                                                            <pre class="campaign-message-preview">{{ $testSnapshot['header'] ?? '' }}</pre>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Header used for the first test message</label>
                                                            <pre class="campaign-message-preview">{{ $testSnapshot['resolved_header'] ?? '' }}</pre>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Body submitted</label>
                                                            <pre class="campaign-message-preview">{{ $testSnapshot['body'] ?? '' }}</pre>
                                                        </div>
                                                        @if (array_key_exists('resolved_body', $testSnapshot))
                                                            <div class="form-group">
                                                                <label>Body used for the first test message</label>
                                                                <pre class="campaign-message-preview">{{ $testSnapshot['resolved_body'] }}</pre>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </details>
                                        @else
                                            <span class="small text-muted">Test configuration details are unavailable for this older log.</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $manualTestAttempts->links() }}
            @endif
        </div>
    </div>

    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Content revisions</h3>
            <div class="block-options">{{ $revisions->total() }} {{ $revisions->total() === 1 ? 'revision' : 'revisions' }}</div>
        </div>
        <div class="block-content block-content-full">
            @if ($revisions->isEmpty())
                <p class="text-center mb-0">No content revision is attached.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead><tr><th scope="col">VERSION</th><th scope="col">CONTENT FINGERPRINT</th><th scope="col">SAVED</th></tr></thead>
                        <tbody>
                            @foreach ($revisions as $revision)
                                <tr>
                                    <td>R{{ str_pad((string) $revision->revision_number, 2, '0', STR_PAD_LEFT) }}@if ($loop->first && $revisions->currentPage() === 1) <span class="badge badge-primary">LATEST</span>@endif</td>
                                    <td><code>{{ $revision->content_sha256 }}</code></td>
                                    <td>{{ $revision->created_at?->format('Y-m-d H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-end">{{ $revisions->links() }}</div>
            @endif
        </div>
    </div>

    <div class="alert alert-info" role="status">
        DMS manual campaign tests generate a test-send configuration and run its native sender. AlphaLink’s PMTA test uses Symfony Mailer with a selected server and one explicit recipient. It does not reproduce the native sender’s header, placeholder, VMTA or delivery behavior.
    </div>
    <a class="btn btn-alt-secondary" href="{{ route('console.campaigns.index', $workspace->id) }}"><i class="fa fa-arrow-left mr-1"></i> Back to campaigns</a>
@endsection

@push('styles')
    <style>
        .campaign-message-preview { background: #f6f7f3; border: 1px solid #dfe3d9; border-radius: .35rem; color: #183636; font-family: SFMono-Regular, Consolas, Liberation Mono, monospace; font-size: .875rem; max-height: 24rem; overflow: auto; padding: .85rem; white-space: pre-wrap; overflow-wrap: anywhere; }
        .campaign-details-summary { cursor: pointer; display: block; font-weight: 600; list-style-position: inside; padding: 1rem 1.25rem; }
        .campaign-test-form { max-width: 42rem; }
        .campaign-test-form input[type="email"] { min-height: 2.8125rem; }
        .campaign-test-log-details > summary { color: var(--color-primary, #17685f); cursor: pointer; font-weight: 600; padding: .5rem 0; }
        .campaign-test-log-details .campaign-message-preview { max-height: 14rem; }
        .campaign-test-form :focus-visible, .campaign-details-summary:focus-visible { outline: 3px solid #17685f; outline-offset: 2px; }
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('alphalink/campaign-header-library.js') }}" defer></script>
    <script>
        (() => {
            const editor = document.getElementById('edit-campaign');
            const revealEditor = () => {
                if (window.location.hash === '#edit-campaign' && editor) editor.open = true;
            };
            revealEditor();
            window.addEventListener('hashchange', revealEditor);

            const route = document.getElementById('revision-route');
            const ipType = document.getElementById('revision-ip-type');
            const servers = document.getElementById('revision-servers');
            const ips = document.getElementById('revision-ips');
            const optionUrl = @json(route('console.campaigns.send-options', $workspace->id));
            const refreshIps = async () => {
                if (!route || !ipType || !servers || !ips) return;
                const selected = new Set(Array.from(ips.selectedOptions).map(option => option.value));
                const params = new URLSearchParams({
                    sponsor_id: @json($sendSetup['sponsor_id'] ?? ''),
                    offer_country: @json($sendSetup['offer_country'] ?? 'ALL'),
                    isp_id: @json($sendSetup['isp_id'] ?? ''),
                    country: @json($sendSetup['country'] ?? 'ALL'),
                    route: route.value,
                    ip_type: ipType.value,
                });
                Array.from(servers.selectedOptions).forEach(option => params.append('server_ids[]', option.value));
                const response = await fetch(optionUrl + '?' + params.toString(), {headers: {'Accept': 'application/json'}});
                if (!response.ok) return;
                const choices = (await response.json()).data.ips || [];
                ips.replaceChildren(...choices.map(choice => {
                    const option = new Option(`${choice.ip} · ${String(choice.type).toUpperCase()}`, String(choice.id));
                    option.selected = selected.has(String(choice.id));
                    return option;
                }));
                if (window.jQuery && window.jQuery.fn.selectpicker) window.jQuery(ips).selectpicker('refresh');
            };
            [route, ipType, servers].forEach(element => element?.addEventListener('change', () => { refreshIps().catch(() => {}); }));

            const body = document.getElementById('revision-dms-body');
            const domain = document.getElementById('revision-domain');
            const creative = document.getElementById('revision-creative-asset');
            const html = document.getElementById('revision-html-asset');
            const selectedDomain = () => domain?.value || '[domain]';
            html?.addEventListener('change', () => {
                const option = html.selectedOptions[0];
                if (option?.value) body.value = (option.dataset.assetValue || '').replaceAll('[domain]', selectedDomain());
            });
            creative?.addEventListener('change', () => {
                const option = creative.selectedOptions[0];
                if (option?.value) body.value = body.value.replace(/src="[^"]*"/i, 'src="http://' + selectedDomain() + '/' + (option.dataset.assetValue || 'NA') + '"');
            });
            domain?.addEventListener('change', () => {
                body.value = body.value.replace(/(https?:\/\/)(.*?)\//g, 'http://' + selectedDomain() + '/');
            });
        })();
    </script>
@endpush
