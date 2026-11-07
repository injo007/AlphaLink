@extends('layouts.admin-shell')

@section('title', $campaign->name)

@section('content')
    @php
        $content = $campaign->latestRevision?->payload ?? [];
    @endphp
    <div class="command-os-campaign-detail">
        <header class="command-os-campaign-detail-heading">
            <div>
                <p class="command-os-campaign-detail-eyebrow">CAMPAIGN WORKSPACE <span aria-hidden="true">/</span> MESSAGE REVIEW</p>
                <h1>Campaign draft: {{ $campaign->name }}</h1>
                <p>Review the latest saved message and its delivery setup.</p>
            </div>
        </header>

        @if (session('status'))
            <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="block block-rounded command-os-campaign-message-card">
            <div class="block-header command-os-campaign-message-card-header">
                <div class="command-os-campaign-section-title">
                    <span class="command-os-campaign-section-icon" aria-hidden="true"><i class="fa fa-envelope-open-text"></i></span>
                    <div>
                        <h2 class="block-title">Latest saved message</h2>
                        <p>Header and body from the same saved revision.</p>
                    </div>
                </div>
                <div class="block-options">
                    @if ($campaign->latestRevision)
                        <span class="command-os-campaign-revision"><i class="fa fa-history" aria-hidden="true"></i> REVISION {{ $campaign->latestRevision->revision_number }}</span>
                    @else
                        <span class="command-os-campaign-revision is-empty">NO CONTENT</span>
                    @endif
                </div>
            </div>
            <div class="block-content block-content-full command-os-campaign-message-content">
                @if ($campaign->latestRevision)
                    <div class="command-os-campaign-message-grid">
                        <section class="command-os-campaign-message-pane" aria-labelledby="campaign-saved-header-title">
                            <div class="command-os-campaign-message-pane-heading">
                                <span class="command-os-campaign-pane-icon" aria-hidden="true"><i class="fa fa-list-alt"></i></span>
                                <div>
                                    <h3 id="campaign-saved-header-title">Campaign header</h3>
                                    <p>Message structure and delivery fields</p>
                                </div>
                            </div>
                            <pre class="campaign-message-preview">{{ $content['header'] ?? '' }}</pre>
                        </section>

                        <section class="command-os-campaign-message-pane" aria-labelledby="campaign-saved-body-title">
                            <div class="command-os-campaign-message-pane-heading">
                                <span class="command-os-campaign-pane-icon is-body" aria-hidden="true"><i class="fa fa-code"></i></span>
                                <div>
                                    <h3 id="campaign-saved-body-title">Campaign body template</h3>
                                    <p>Saved content with its original placeholders</p>
                                </div>
                            </div>
                            @if (filled($content['body'] ?? null))
                                <pre class="campaign-message-preview">{{ $content['body'] }}</pre>
                            @else
                                <div class="command-os-campaign-message-empty">No body content was saved in this revision.</div>
                            @endif
                        </section>
                    </div>

                    <dl class="command-os-campaign-message-meta">
                        @if ($campaign->kind === 'transactional')
                            <div class="command-os-campaign-meta-item">
                                <dt>From</dt>
                                <dd>{{ $content['from_name'] ?? '' }} &lt;{{ $content['from_email'] ?? '' }}&gt;</dd>
                            </div>
                            <div class="command-os-campaign-meta-item">
                                <dt>Reply-to</dt>
                                <dd>{{ $content['reply_to'] ?? 'Not set' }}</dd>
                            </div>
                            <div class="command-os-campaign-meta-item">
                                <dt>Subject</dt>
                                <dd>{{ $content['subject'] ?? '' }}</dd>
                            </div>
                        @endif
                        <div class="command-os-campaign-meta-item">
                            <dt>Return path</dt>
                            <dd><code>{{ $content['return_path'] ?? '[rnd]@[domain]' }}</code></dd>
                        </div>
                        <div class="command-os-campaign-meta-item">
                            <dt>Email test</dt>
                            <dd>
                                @if (! empty($content['email_tests']))
                                    {{ implode(', ', $content['email_tests']) }}
                                @else
                                    <span class="text-muted">No test addresses saved.</span>
                                @endif
                            </dd>
                        </div>
                        <div class="command-os-campaign-meta-item is-fingerprint">
                            <dt>Content fingerprint</dt>
                            <dd><code>{{ $campaign->latestRevision->content_sha256 }}</code></dd>
                        </div>
                    </dl>

                    @if ($campaign->kind === 'transactional' && filled($content['text_body'] ?? null))
                        <section class="command-os-campaign-message-pane command-os-campaign-message-variant" aria-labelledby="campaign-saved-text-title">
                            <div class="command-os-campaign-message-pane-heading">
                                <span class="command-os-campaign-pane-icon" aria-hidden="true"><i class="fa fa-align-left"></i></span>
                                <div><h3 id="campaign-saved-text-title">Plain-text body</h3><p>Text version saved for this transactional message</p></div>
                            </div>
                            <pre class="campaign-message-preview">{{ $content['text_body'] }}</pre>
                        </section>
                    @endif
                    @if ($campaign->kind === 'transactional' && filled($content['html_body'] ?? null))
                        <details class="command-os-campaign-html-source">
                            <summary><span><i class="fa fa-file-code" aria-hidden="true"></i> View saved HTML source</span><i class="fa fa-chevron-down" aria-hidden="true"></i></summary>
                            <pre class="campaign-message-preview mt-2">{{ $content['html_body'] }}</pre>
                        </details>
                    @endif
                @else
                    <div class="command-os-campaign-message-empty" role="status">No saved content revision is attached yet.</div>
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
                <h3 class="block-title"><i class="fas fa-list-ul" aria-hidden="true"></i> Saved campaign selections</h3>
                <div class="block-options"><span class="badge badge-warning">DRAFT · NOT SEND-READY</span></div>
            </div>
            <div class="block-content block-content-full">
                @if ($campaign->status === 'preparing_snapshot')
                    @php
                        $snapshotStage = $campaign->snapshot_build_stage ?? 'queued';
                        $snapshotStep = match ($snapshotStage) {
                            'suppression_file' => 1,
                            'counting_audience', 'audience' => 2,
                            'writing_snapshot' => 3,
                            default => 0,
                        };
                        $snapshotIsIndeterminate = in_array($snapshotStage, ['queued', 'counting_audience', 'writing_snapshot'], true);
                        $snapshotStageLabel = match ($snapshotStage) {
                            'suppression_file' => 'Reading and verifying the offer suppression file',
                            'counting_audience' => 'Counting selected list addresses',
                            'audience' => 'Checking selected list addresses against suppression rules',
                            'writing_snapshot' => 'Saving the immutable recipient snapshot',
                            default => 'Waiting for the background preparation worker',
                        };
                    @endphp
                    <section class="alert alert-info mb-3" id="campaign-snapshot-progress"
                        data-progress-url="{{ route('console.campaigns.snapshot-progress', [$workspace->id, $campaign->id]) }}"
                        aria-labelledby="campaign-snapshot-progress-title">
                        <h4 class="h6 mb-2" id="campaign-snapshot-progress-title"><i class="fas fa-users" aria-hidden="true"></i> Preparing the campaign audience</h4>
                        <p class="mb-3" id="campaign-snapshot-stage" aria-live="polite">{{ $snapshotStageLabel }}</p>
                        <div class="progress mb-2" style="height: 0.8rem">
                            <div id="campaign-snapshot-bar" class="progress-bar {{ $snapshotIsIndeterminate ? 'progress-bar-striped progress-bar-animated w-100' : '' }}"
                                role="progressbar" aria-label="Campaign audience preparation progress"
                                @unless ($snapshotIsIndeterminate) aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) $campaign->snapshot_build_percent }}" style="width: {{ (int) $campaign->snapshot_build_percent }}%" @endunless></div>
                        </div>
                        <div class="d-flex justify-content-between flex-wrap small mb-3">
                            <span id="campaign-snapshot-count">
                                @if ($snapshotStage === 'suppression_file' && $campaign->snapshot_build_total)
                                    {{ number_format($campaign->snapshot_build_processed / 1048576, 1) }} MB of {{ number_format($campaign->snapshot_build_total / 1048576, 1) }} MB read
                                @elseif ($snapshotStage === 'audience' && $campaign->snapshot_build_total !== null)
                                    {{ number_format($campaign->snapshot_build_processed) }} of {{ number_format($campaign->snapshot_build_total) }} addresses checked
                                @elseif ($snapshotStage === 'queued')
                                    Your campaign is saved. Preparation will begin when a worker is available.
                                @elseif ($snapshotStage === 'counting_audience')
                                    Counting the selected addresses before checking their suppression status.
                                @else
                                    Saving the final campaign snapshot…
                                @endif
                            </span>
                            <span id="campaign-snapshot-percent" @if ($snapshotIsIndeterminate) hidden @endif>{{ (int) $campaign->snapshot_build_percent }}%</span>
                        </div>
                        <ol class="d-flex flex-wrap list-unstyled mb-0" aria-label="Campaign preparation steps">
                            @foreach (['Read suppression file', 'Check selected addresses', 'Save recipient snapshot'] as $index => $stepLabel)
                                @php $stepNumber = $index + 1; @endphp
                                <li class="mr-3 mb-1" id="campaign-snapshot-step-{{ $stepNumber }}" @if ($snapshotStep === $stepNumber) aria-current="step" @endif>
                                    <span class="badge {{ $snapshotStep === $stepNumber ? 'badge-primary' : ($snapshotStep > $stepNumber ? 'badge-success' : 'badge-secondary') }}">{{ $stepNumber }}</span>
                                    {{ $stepLabel }}
                                </li>
                            @endforeach
                        </ol>
                        <div class="alert alert-danger mt-3 mb-0" id="campaign-snapshot-poll-error" role="alert" hidden>
                            Progress is temporarily unavailable. The preparation job may still be running; retrying status checks.
                        </div>
                    </section>
                @elseif ($campaign->status === 'snapshot_failed')
                    <div class="alert alert-danger" role="alert">
                        <p class="mb-2"><strong>Campaign audience preparation failed.</strong> {{ $campaign->snapshot_build_error ?? 'Review the suppression file and selected lists, then retry.' }}</p>
                        <form method="POST" action="{{ route('console.campaigns.snapshot-retry', [$workspace->id, $campaign->id]) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-danger">Retry audience preparation</button>
                        </form>
                    </div>
                @else
                    <div class="alert alert-info" role="status">The selected suppression file was applied when this recipient snapshot was prepared. Before delivery, AlphaLink verifies the reviewed offer suppression file and checks current consent, workspace suppression, and offer suppression again. After preparation completes, open My Campaigns and choose SEND to start the background PMTA delivery.</div>
                @endif
                <dl class="row mb-3">
                    <dt class="col-sm-3">Sponsor</dt><dd class="col-sm-9">{{ $sponsorChoice['name'] ?? 'No longer available' }} (ID {{ $sendSetup['sponsor_id'] ?? '—' }})</dd>
                    <dt class="col-sm-3">Offer</dt><dd class="col-sm-9">{{ $offerChoice['name'] ?? 'No longer eligible' }} (ID {{ $sendSetup['offer_id'] ?? '—' }})</dd>
                    <dt class="col-sm-3">ISP</dt><dd class="col-sm-9">{{ $ispChoice['name'] ?? 'No longer available' }} (ID {{ $sendSetup['isp_id'] ?? '—' }})</dd>
                    <dt class="col-sm-3">Country</dt><dd class="col-sm-9">Offer {{ $sendSetup['offer_country'] ?? '—' }} · List {{ $sendSetup['country'] ?? '—' }}</dd>
                    <dt class="col-sm-3">Campaign domain</dt><dd class="col-sm-9">{{ $sendSetup['domain'] ?? 'Not selected' }}</dd>
                    <dt class="col-sm-3">Negative file</dt><dd class="col-sm-9">{{ ($sendSetup['negative_id'] ?? null) ? ($negativeChoice['name'] ?? 'No longer available').' (ID '.$sendSetup['negative_id'].')' : 'Not selected' }}</dd>
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
                        <small class="text-muted">Selected email types: {{ collect($sendSetup['audience_segments'] ?? [])->map(fn ($segment) => strtoupper(substr(strrchr($segment, '_') ?: '', 1)))->unique()->implode(', ') }}.</small>
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
                    <dt class="col-sm-3">Selected {{ match ($sendSetup['route_option'] ?? 'ip') { 'smtp' => 'SMTP routes', 'vmta' => 'VMTAs', default => 'IPs' } }}</dt>
                    <dd class="col-sm-9">
                        <ul class="mb-0">
                            @foreach ($sendSetup['ip_ids'] ?? [] as $ipId)
                                @php
                                    $ipChoice = $availableIps->get($ipId);
                                    $routeOption = $sendSetup['route_option'] ?? 'ip';
                                    $routeDescription = match ($routeOption) {
                                        'smtp' => implode(' · ', array_filter([$ipChoice['ip'] ?? null, $ipChoice['username'] ?? null, $ipChoice['name'] ?? null, $ipChoice['dns'] ?? null, isset($ipChoice['pmta_name']) ? 'PMTA route '.$ipChoice['pmta_name'] : null], static fn ($value) => filled($value))),
                                        'vmta' => implode(' · ', array_filter([$ipChoice['ip'] ?? null, $ipChoice['name'] ?? null, $ipChoice['dns'] ?? null, isset($ipChoice['pmta_name']) ? 'PMTA route '.$ipChoice['pmta_name'] : null], static fn ($value) => filled($value))),
                                        default => implode(' · ', array_filter([$ipChoice['ip'] ?? null, isset($ipChoice['type']) ? strtoupper($ipChoice['type']) : null, $ipChoice['dns'] ?? null], static fn ($value) => filled($value))),
                                    };
                                @endphp
                                <li>{{ $routeDescription !== '' ? $routeDescription : 'Unavailable record' }} (ID {{ $ipId }})</li>
                            @endforeach
                        </ul>
                    </dd>
                </dl>
                <p class="mb-0 text-muted">Send parameters: {{ json_encode($sendSetup['parameters'] ?? [], JSON_UNESCAPED_SLASHES) }}</p>
                @if ($recipientSnapshot)
                    <hr>
                    <h4 class="h6"><i class="fas fa-shield-alt" aria-hidden="true"></i> Immutable recipient snapshot</h4>
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Eligible addresses</dt><dd class="col-sm-9">{{ number_format($recipientSnapshot->recipient_count) }}</dd>
                        <dt class="col-sm-3">Offer suppressed</dt><dd class="col-sm-9">{{ number_format($recipientSnapshot->offer_suppressed_count) }}</dd>
                        <dt class="col-sm-3">Workspace suppressed</dt><dd class="col-sm-9">{{ number_format($recipientSnapshot->workspace_suppressed_count) }}</dd>
                        <dt class="col-sm-3">Duplicate addresses</dt><dd class="col-sm-9">{{ number_format($recipientSnapshot->duplicate_count) }}</dd>
                        <dt class="col-sm-3">Manifest SHA-256</dt><dd class="col-sm-9"><code>{{ $recipientSnapshot->recipients_sha256 }}</code></dd>
                    </dl>
                @else
                    @if (! in_array($campaign->status, ['preparing_snapshot', 'snapshot_failed'], true))
                        <p class="mb-0 text-danger">No recipient snapshot is attached to this revision. SEND becomes available in My Campaigns after recipient preparation finishes.</p>
                    @endif
                @endif
            </div>
        </div>
    @endif

    @if ($campaign->kind === 'marketing' && is_array($sendSetup) && $campaign->status === 'draft' && $campaign->is_active)
        <div id="pmta-test" class="block block-rounded">
            <div class="block-header"><h3 class="block-title"><i class="fas fa-paper-plane" aria-hidden="true"></i> Send a PMTA campaign test</h3></div>
            <div class="block-content block-content-full">
                <p>Select a saved campaign server and Email Test recipient. AlphaLink connects to that server’s PMTA listener at the server IP and workspace port; that is the PMTA ingress address. It then pins the campaign’s saved IP, VMTA, or SMTP route with <code>X-Virtual-MTA</code>, which controls the outbound source/account. With Option IP, using <code>[smtp]</code> or <code>[smtp_name]</code> selects the one active SMTP account linked to that IP for outbound delivery; without those tags it uses the selected IP route. Multiple linked SMTP accounts require choosing the exact account through Option SMTP. Review the From, subject and body before explicitly sending.</p>
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
                            <div class="form-group col-md-6"><label for="pmta-test-from">From address</label><input id="pmta-test-from" name="from_email" type="text" inputmode="email" autocomplete="off" placeholder="sender@[domain]" maxlength="320" class="form-control" required><small class="form-text text-muted">Campaign tags such as <code>[domain]</code> and <code>[smtp]</code> are expanded before address validation.</small></div>
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
            <h3 class="block-title"><i class="fas fa-envelope" aria-hidden="true"></i> Send one test email</h3>
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
            <summary class="block-header campaign-details-summary"><i class="fas fa-edit" aria-hidden="true"></i> {{ $campaign->kind === 'marketing' ? 'Edit campaign' : 'Save a new content revision' }}</summary>
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
                                <label for="revision-domain">Campaign domain</label>
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
                                <small class="form-text text-muted">The selected file ID is saved. This field does not alter the saved recipient snapshot.</small>
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
                                <small class="form-text text-muted">Selecting an HTML asset replaces the Body with its campaign template. The <code>[domain]</code> tag resolves when the message is sent; other URL hosts stay unchanged.</small>
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
                        @include('console.campaigns.partials.header-library', ['workspace' => $workspace, 'textareaId' => 'revision-header', 'idPrefix' => 'revision'])
                        <div class="form-group col-12">
                            <label for="revision-header">Header</label>
                            <textarea id="revision-header" name="header" rows="8" class="form-control" spellcheck="false" required>{{ $content['header'] ?? "MIME-version: 1.0\nContent-type: text/html\nTo: [to]\nfrom: [from] <info@[domain]>\nSubject: [subject]\nDate: [date]" }}</textarea>
                        </div>
                        <div class="form-group col-12">
                            <label for="revision-body">Campaign body template</label>
                            <textarea id="revision-body" name="body" rows="8" class="form-control" spellcheck="false">{{ $content['body'] ?? '' }}</textarea>
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
            <h3 class="block-title"><i class="fas fa-history" aria-hidden="true"></i> Test attempt history</h3>
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
            <h3 class="block-title"><i class="fas fa-clipboard-list" aria-hidden="true"></i> Campaign test logs</h3>
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
                                                <summary><i class="fas fa-sliders-h" aria-hidden="true"></i> View test configuration</summary>
                                                @php
                                                    $testSnapshot = $attempt->test_snapshot;
                                                    $testServers = collect($testSnapshot['servers'] ?? [])
                                                        ->map(fn (array $server): string => trim(($server['name'] ?? 'Server unavailable').' · '.($server['id'] ?? '')))
                                                        ->implode("\n");
                                                    $outboundMtaNames = collect($testSnapshot['virtual_mtas'] ?? [])
                                                        ->pluck('pmta_name')
                                                        ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
                                                        ->unique()
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
                                                            <label>Outbound PMTA route</label>
                                                            <textarea class="form-control" rows="2" readonly>{{ strtoupper($testSnapshot['outbound_route'] ?? $testSnapshot['mta'] ?? 'Unknown') }}{{ $outboundMtaNames !== '' ? "\n".$outboundMtaNames : '' }}</textarea>
                                                            <small class="form-text text-muted">AlphaLink connects to the server’s PMTA listener using its IP. This route alias tells PMTA which source IP or SMTP relay virtual MTA to use. PMTA acceptance does not confirm mailbox delivery.</small>
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
            <h3 class="block-title"><i class="fas fa-code-branch" aria-hidden="true"></i> Content revisions</h3>
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
        The campaign test path uses the selected PMTA server and recipient. Transactional drafts use Symfony Mailer, with separate header, placeholder, route and delivery behavior.
    </div>
    <a class="btn btn-alt-secondary" href="{{ route('console.campaigns.index', $workspace->id) }}"><i class="fa fa-arrow-left mr-1"></i> Back to campaigns</a>
    </div>
@endsection

@push('styles')
    <style>
        .command-os-campaign-detail { max-width: 1680px; margin: 0 auto; padding-bottom: 18px; }
        .command-os-campaign-detail-heading { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin: 2px 0 18px; }
        .command-os-campaign-detail-heading h1 { margin: 4px 0 5px; color: var(--co-ink); font-size: clamp(22px, 2.2vw, 30px); font-weight: 780; letter-spacing: -.045em; line-height: 1.15; }
        .command-os-campaign-detail-heading > div > p:last-child { margin: 0; color: var(--co-muted); font-size: 12px; }
        .command-os-campaign-detail-eyebrow { margin: 0; color: var(--co-purple); font-size: 9px; font-weight: 800; letter-spacing: .15em; }
        .command-os-campaign-detail-eyebrow span { padding: 0 5px; color: var(--co-subtle); }
        .command-os-campaign-detail .alert { border-radius: 12px; }
        .command-os-campaign-detail .block { overflow: visible; border: 1px solid var(--co-border); border-radius: 16px; background: var(--co-surface); box-shadow: var(--co-shadow); }
        .command-os-campaign-detail .block-header { min-height: 68px; align-items: center; gap: 14px; padding: 13px 18px; border-bottom: 1px solid var(--co-border); border-radius: 16px 16px 0 0; background: color-mix(in srgb, var(--co-purple) 3%, var(--co-surface)); }
        .command-os-campaign-detail .block-title { margin: 0; color: var(--co-ink); font-size: 16px; font-weight: 760; letter-spacing: -.025em; }
        .command-os-campaign-detail .block-title > i,
        .command-os-campaign-detail .campaign-details-summary > i { width: 20px; margin-right: 6px; color: var(--co-purple); text-align: center; }
        .command-os-campaign-detail h4 > i,
        .command-os-campaign-detail details summary > i { margin-right: 6px; color: var(--co-purple); }
        .command-os-campaign-detail .block-content { color: var(--co-ink); }
        .command-os-campaign-message-card { margin-bottom: 16px; }
        .command-os-campaign-message-card-header { justify-content: space-between; }
        .command-os-campaign-section-title { display: flex; min-width: 0; align-items: center; gap: 11px; }
        .command-os-campaign-section-title > div > p { margin: 4px 0 0; color: var(--co-muted); font-size: 10px; }
        .command-os-campaign-section-icon,
        .command-os-campaign-pane-icon { display: grid; width: 38px; height: 38px; flex: 0 0 38px; place-items: center; border: 1px solid color-mix(in srgb, var(--co-purple) 18%, var(--co-border)); border-radius: 12px; color: var(--co-purple); background: color-mix(in srgb, var(--co-purple) 9%, var(--co-surface)); font-size: 15px; }
        .command-os-campaign-revision { display: inline-flex; min-height: 28px; align-items: center; gap: 6px; padding: 5px 10px; border: 1px solid color-mix(in srgb, var(--co-purple) 24%, var(--co-border)); border-radius: 999px; color: var(--co-purple); background: color-mix(in srgb, var(--co-purple) 8%, var(--co-surface)); font-size: 9px; font-weight: 800; letter-spacing: .04em; white-space: nowrap; }
        .command-os-campaign-revision.is-empty { color: var(--co-muted); border-color: var(--co-border); background: var(--co-surface-muted); }
        .command-os-campaign-message-content { padding: 16px !important; }
        .command-os-campaign-message-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: stretch; gap: 14px; }
        .command-os-campaign-message-pane { min-width: 0; padding: 13px; border: 1px solid var(--co-border); border-radius: 13px; background: color-mix(in srgb, var(--co-surface-muted) 76%, var(--co-surface)); }
        .command-os-campaign-message-pane-heading { display: flex; align-items: center; gap: 9px; margin-bottom: 11px; }
        .command-os-campaign-pane-icon { width: 32px; height: 32px; flex-basis: 32px; border-radius: 10px; font-size: 12px; }
        .command-os-campaign-pane-icon.is-body { color: var(--co-blue); border-color: color-mix(in srgb, var(--co-blue) 21%, var(--co-border)); background: color-mix(in srgb, var(--co-blue) 9%, var(--co-surface)); }
        .command-os-campaign-message-pane-heading h3 { margin: 0; color: var(--co-ink); font-size: 12px; font-weight: 760; }
        .command-os-campaign-message-pane-heading p { margin: 3px 0 0; color: var(--co-muted); font-size: 9px; }
        .campaign-message-preview { max-height: 24rem; overflow: auto; margin: 0; padding: 13px; border: 1px solid var(--co-border); border-radius: 9px; color: var(--co-ink); background: var(--co-surface); font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', monospace; font-size: 11px; line-height: 1.55; tab-size: 4; white-space: pre-wrap; overflow-wrap: anywhere; }
        .command-os-campaign-message-empty { display: grid; min-height: 110px; place-items: center; padding: 18px; border: 1px dashed var(--co-border-strong); border-radius: 9px; color: var(--co-muted); background: var(--co-surface); font-size: 11px; text-align: center; }
        .command-os-campaign-message-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 190px), 1fr)); gap: 9px; margin: 14px 0 0; }
        .command-os-campaign-meta-item { min-width: 0; margin: 0; padding: 11px 12px; border: 1px solid var(--co-border); border-radius: 11px; background: var(--co-surface); }
        .command-os-campaign-meta-item dt { margin: 0 0 5px; color: var(--co-subtle); font-size: 8px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
        .command-os-campaign-meta-item dd { margin: 0; color: var(--co-ink); font-size: 10px; line-height: 1.5; overflow-wrap: anywhere; }
        .command-os-campaign-meta-item code { color: var(--co-purple); font-size: 10px; overflow-wrap: anywhere; white-space: normal; }
        .command-os-campaign-meta-item.is-fingerprint { grid-column: span 2; }
        .command-os-campaign-message-variant { margin-top: 14px; }
        .command-os-campaign-html-source { margin-top: 12px; padding: 0 12px 12px; border: 1px solid var(--co-border); border-radius: 11px; background: var(--co-surface); }
        .command-os-campaign-html-source > summary { display: flex; min-height: 44px; align-items: center; justify-content: space-between; gap: 12px; color: var(--co-ink); cursor: pointer; font-size: 10px; font-weight: 700; list-style: none; }
        .command-os-campaign-html-source > summary::-webkit-details-marker { display: none; }
        .command-os-campaign-html-source > summary span { display: inline-flex; align-items: center; gap: 8px; }
        .command-os-campaign-html-source > summary span i { color: var(--co-purple); }
        .command-os-campaign-html-source[open] > summary > i { transform: rotate(180deg); }
        .command-os-campaign-html-source > summary > i { color: var(--co-muted); transition: transform .18s ease; }
        .command-os-campaign-detail > .btn { min-height: 38px; border-radius: 10px; }
        .campaign-details-summary { cursor: pointer; display: block; font-weight: 600; list-style-position: inside; padding: 1rem 1.25rem; }
        .campaign-test-form { max-width: 42rem; }
        .campaign-test-form input[type="email"] { min-height: 2.8125rem; }
        .campaign-test-log-details > summary { color: var(--color-primary, #17685f); cursor: pointer; font-weight: 600; padding: .5rem 0; }
        .campaign-test-log-details .campaign-message-preview { max-height: 14rem; }
        .campaign-test-form :focus-visible, .campaign-details-summary:focus-visible, .command-os-campaign-html-source > summary:focus-visible { outline: 3px solid color-mix(in srgb, var(--co-purple) 55%, transparent); outline-offset: 2px; }
        @media (max-width: 760px) {
            .command-os-campaign-detail-heading { margin-bottom: 14px; }
            .command-os-campaign-message-grid { grid-template-columns: minmax(0, 1fr); }
            .command-os-campaign-meta-item.is-fingerprint { grid-column: auto; }
        }
        @media (max-width: 480px) {
            .command-os-campaign-detail-heading h1 { font-size: 22px; }
            .command-os-campaign-detail .block-header { align-items: flex-start; padding: 12px; }
            .command-os-campaign-message-card-header { flex-direction: column; }
            .command-os-campaign-message-content { padding: 10px !important; }
            .command-os-campaign-message-pane { padding: 10px; }
            .campaign-message-preview { max-height: 18rem; padding: 10px; font-size: 10px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .command-os-campaign-html-source > summary > i { transition: none; }
        }
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('alphalink/campaign-header-library.js') }}" defer></script>
    <script>
        (() => {
            const snapshotProgress = document.getElementById('campaign-snapshot-progress');
            if (snapshotProgress) {
                const progressUrl = snapshotProgress.dataset.progressUrl;
                const bar = document.getElementById('campaign-snapshot-bar');
                const stageText = document.getElementById('campaign-snapshot-stage');
                const countText = document.getElementById('campaign-snapshot-count');
                const percentText = document.getElementById('campaign-snapshot-percent');
                const pollError = document.getElementById('campaign-snapshot-poll-error');
                const retryDelay = [2000, 4000, 8000, 15000];
                let failures = 0;
                let stopped = false;
                const stageLabels = {
                    queued: 'Waiting for the background preparation worker',
                    suppression_file: 'Reading and verifying the offer suppression file',
                    counting_audience: 'Counting selected list addresses',
                    audience: 'Checking selected list addresses against suppression rules',
                    writing_snapshot: 'Saving the immutable recipient snapshot',
                };
                const stepForStage = {suppression_file: 1, counting_audience: 2, audience: 2, writing_snapshot: 3};
                const formatMegabytes = bytes => `${(Number(bytes || 0) / 1048576).toLocaleString(undefined, {maximumFractionDigits: 1})} MB`;
                const schedule = delay => { if (!stopped) window.setTimeout(poll, delay); };
                const poll = async () => {
                    try {
                        const response = await fetch(progressUrl, {
                            credentials: 'same-origin',
                            cache: 'no-store',
                            headers: {'Accept': 'application/json'},
                        });
                        if (!response.ok) throw new Error('Campaign progress request failed.');
                        const progress = await response.json();
                        failures = 0;
                        pollError.hidden = true;
                        const stage = progress.stage || 'queued';
                        const indeterminate = stage === 'queued' || stage === 'counting_audience' || stage === 'writing_snapshot';
                        stageText.textContent = stageLabels[stage] || 'Preparing the campaign audience';
                        if (indeterminate) {
                            bar.classList.add('progress-bar-striped', 'progress-bar-animated', 'w-100');
                            bar.removeAttribute('aria-valuemin');
                            bar.removeAttribute('aria-valuemax');
                            bar.removeAttribute('aria-valuenow');
                            bar.style.removeProperty('width');
                            percentText.hidden = true;
                            countText.textContent = stage === 'queued'
                                ? 'Your campaign is saved. Preparation will begin when a worker is available.'
                                : (stage === 'counting_audience'
                                    ? 'Counting the selected addresses before checking their suppression status.'
                                    : 'Saving the final campaign snapshot…');
                        } else {
                            const percent = Math.max(0, Math.min(100, Number(progress.percent || 0)));
                            bar.classList.remove('progress-bar-striped', 'progress-bar-animated', 'w-100');
                            bar.setAttribute('aria-valuemin', '0');
                            bar.setAttribute('aria-valuemax', '100');
                            bar.setAttribute('aria-valuenow', String(percent));
                            bar.style.width = `${percent}%`;
                            percentText.hidden = false;
                            percentText.textContent = `${percent}%`;
                            countText.textContent = stage === 'suppression_file'
                                ? `${formatMegabytes(progress.processed)} of ${formatMegabytes(progress.total)} read`
                                : `${Number(progress.processed || 0).toLocaleString()} of ${Number(progress.total || 0).toLocaleString()} addresses checked`;
                        }
                        const currentStep = stepForStage[stage] || 0;
                        [1, 2, 3].forEach(step => {
                            const item = document.getElementById(`campaign-snapshot-step-${step}`);
                            const badge = item?.querySelector('.badge');
                            if (!item || !badge) return;
                            item.removeAttribute('aria-current');
                            badge.classList.remove('badge-primary', 'badge-success', 'badge-secondary');
                            if (step === currentStep) {
                                item.setAttribute('aria-current', 'step');
                                badge.classList.add('badge-primary');
                            } else {
                                badge.classList.add(step < currentStep ? 'badge-success' : 'badge-secondary');
                            }
                        });
                        if (progress.status === 'snapshot_failed') {
                            stopped = true;
                            window.location.reload();
                            return;
                        }
                        if (progress.ready || progress.status === 'draft') {
                            stopped = true;
                            stageText.textContent = 'Campaign audience preparation completed.';
                            bar.classList.remove('progress-bar-striped', 'progress-bar-animated', 'w-100');
                            bar.setAttribute('aria-valuemin', '0');
                            bar.setAttribute('aria-valuemax', '100');
                            bar.setAttribute('aria-valuenow', '100');
                            bar.style.width = '100%';
                            percentText.hidden = false;
                            percentText.textContent = '100%';
                            window.setTimeout(() => window.location.reload(), 800);
                            return;
                        }
                        schedule(2000);
                    } catch (error) {
                        failures = Math.min(failures + 1, retryDelay.length - 1);
                        pollError.hidden = false;
                        schedule(retryDelay[failures]);
                    }
                };
                schedule(1000);
            }

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

            const body = document.getElementById('revision-body');
            const creative = document.getElementById('revision-creative-asset');
            const html = document.getElementById('revision-html-asset');
            html?.addEventListener('change', () => {
                const option = html.selectedOptions[0];
                if (option?.value) body.value = option.dataset.assetValue || '';
            });
            creative?.addEventListener('change', () => {
                const option = creative.selectedOptions[0];
                if (option?.value) body.value = body.value.replace(/src="[^"]*"/i, 'src="https://[domain]/' + (option.dataset.assetValue || 'NA') + '"');
            });
        })();
    </script>
@endpush
