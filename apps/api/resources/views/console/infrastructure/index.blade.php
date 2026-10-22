@extends('layouts.console')

@section('title', 'Server installation')

@section('content')
    <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="{{ route('console.workspace', $workspace->id) }}">Overview</a><span aria-hidden="true">/</span><span aria-current="page">Server installation</span></nav>
    <div class="page-heading compact-heading infra-heading">
        <div>
            <p class="eyebrow page-eyebrow"><span class="eyebrow-square" aria-hidden="true"></span> INFRASTRUCTURE CONTROL</p>
            <h1>Server installation<span class="heading-period">.</span></h1>
            <p class="page-lede">Register a server, prove domain ownership, then review a PowerMTA installation plan.</p>
        </div>
        <span class="read-only-stamp">PREPARATION <span aria-hidden="true">·</span> NO REMOTE CHANGES</span>
    </div>

    @if (session('status'))
        <div class="infra-feedback" role="status">{{ session('status') }}</div>
    @endif
    @if (session('warning'))
        <div class="infra-feedback infra-feedback-warning" role="status">{{ session('warning') }}</div>
    @endif
    @if ($errors->any())
        <div class="infra-feedback infra-feedback-error" role="alert"><strong>Check the fields below.</strong> {{ $errors->first() }}</div>
    @endif

    <div class="infra-flow" aria-label="Installation preparation steps">
        <span><b>1</b> Add server</span><span class="infra-flow-arrow" aria-hidden="true">→</span>
        <span><b>2</b> Verify domain</span><span class="infra-flow-arrow" aria-hidden="true">→</span>
        <span><b>3</b> Preview installation</span>
    </div>

    <div class="infra-grid">
        <section class="data-panel infra-panel" id="servers" aria-labelledby="servers-title">
            <div class="panel-header"><div><p class="eyebrow panel-eyebrow">STEP 1 · HOST</p><h2 id="servers-title">Add server</h2></div><span class="panel-meta">{{ $servers->count() }} registered</span></div>
            <div class="infra-panel-body">
                <p class="infra-intro">Enter the provider, server IPs, and SSH credentials. One server is saved for each IP. The password is encrypted and never shown here again.</p>
                <form method="post" action="{{ route('console.infrastructure.servers.store', $workspace->id) }}" class="infra-form" novalidate>
                    @csrf
                    <input type="hidden" name="request_key" value="{{ old('request_key', $formKey) }}">
                    <div class="infra-two-fields">
                        <div class="infra-field"><label for="server-name">Server name</label><input id="server-name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="off" placeholder="Primary relay" @error('name') aria-invalid="true" @enderror></div>
                        <div class="infra-field"><label for="provider">Provider</label><input id="provider" name="provider" value="{{ old('provider') }}" required maxlength="120" autocomplete="off" placeholder="Your hosting provider" @error('provider') aria-invalid="true" @enderror></div>
                    </div>
                    <div class="infra-two-fields">
                        <div class="infra-field"><label for="server-ips">Public IP addresses</label><textarea id="server-ips" name="ip_addresses_text" rows="3" required autocomplete="off" placeholder="One IPv4 or IPv6 address per line" aria-describedby="server-ips-help" @error('ip_addresses_text') aria-invalid="true" @enderror @error('ip_addresses') aria-invalid="true" @enderror>{{ old('ip_addresses_text') }}</textarea><small id="server-ips-help">Enter up to 100 addresses, one per line. All are saved together.</small></div>
                        <div class="infra-field"><label for="ssh-port">SSH port</label><input id="ssh-port" name="ssh_port" type="number" value="{{ old('ssh_port', 22) }}" min="1" max="65535" required @error('ssh_port') aria-invalid="true" @enderror></div>
                    </div>
                    <div class="infra-two-fields">
                        <div class="infra-field"><label for="ssh-username">SSH username</label><input id="ssh-username" name="ssh_username" value="{{ old('ssh_username', 'root') }}" required maxlength="32" autocomplete="username" @error('ssh_username') aria-invalid="true" @enderror></div>
                        <div class="infra-field"><label for="root-password">SSH password <span class="optional-label">or use a private key below</span></label><input id="root-password" name="root_password" type="password" minlength="8" autocomplete="new-password" @error('root_password') aria-invalid="true" @enderror><small>The connection is not attempted until the host key is verified.</small></div>
                    </div>
                    <div class="infra-field"><label for="ssh-private-key">SSH private key <span class="optional-label">optional</span></label><textarea id="ssh-private-key" name="ssh_private_key" rows="5" autocomplete="off" aria-describedby="ssh-private-key-help" @error('ssh_private_key') aria-invalid="true" @enderror></textarea><small id="ssh-private-key-help">Leave this blank when using a password. Credentials are encrypted and never shown here again.</small></div>
                    <div class="infra-field"><label for="host-fingerprint">Expected SSH fingerprint <span class="optional-label">optional</span></label><input id="host-fingerprint" name="expected_host_fingerprint" value="{{ old('expected_host_fingerprint') }}" placeholder="SHA256:…" autocomplete="off" @error('expected_host_fingerprint') aria-invalid="true" @enderror><small>Record it from your provider. AlphaLink will compare it during preflight.</small></div>
                    <button class="infra-action" type="submit">Save servers <span aria-hidden="true">↗</span></button>
                </form>
                @if ($servers->isNotEmpty())
                    <div class="infra-records" aria-label="Registered servers">
                        @foreach ($servers as $server)
                            <div class="infra-record"><div><strong>{{ $server->name }}</strong><small>{{ $server->provider }} · {{ $server->ip_address }}:{{ $server->ssh_port }}</small></div><span class="infra-state">Host check pending</span></div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        <section class="data-panel infra-panel" id="domains" aria-labelledby="domains-title">
            <div class="panel-header"><div><p class="eyebrow panel-eyebrow">STEP 2 · IDENTITY</p><h2 id="domains-title">Sending domain</h2></div><span class="panel-meta">{{ $domains->where('state', 'verified')->count() }} verified</span></div>
            <div class="infra-panel-body">
                <p class="infra-intro">A domain becomes selectable after its ownership TXT record is visible in DNS.</p>
                <form method="post" action="{{ route('console.infrastructure.domains.store', $workspace->id) }}" class="infra-form infra-domain-form" novalidate>
                    @csrf
                    <div class="infra-field"><label for="domain-name">Domain name</label><input id="domain-name" name="name" value="{{ old('name') }}" required maxlength="253" placeholder="example.com" autocomplete="off" @error('name') aria-invalid="true" @enderror></div>
                    <button class="infra-action" type="submit">Add domain <span aria-hidden="true">↗</span></button>
                </form>
                @forelse ($domains as $domain)
                    <div class="infra-domain-record">
                        <div class="infra-record"><div><strong>{{ $domain->name }}</strong><small>{{ $domain->state === 'verified' ? 'Ownership verified' : 'Waiting for DNS proof' }}</small></div><span class="infra-state {{ $domain->state === 'verified' ? 'infra-state-good' : '' }}">{{ $domain->state }}</span></div>
                        @if ($domain->state !== 'verified')
                            <div class="infra-dns-instruction"><span>Publish TXT</span><code>{{ '_alphalink-verify.'.$domain->name }}</code><code>{{ $domain->verification_token }}</code></div>
                            <form method="post" action="{{ route('console.infrastructure.domains.verify', [$workspace->id, $domain->id]) }}">@csrf<button class="infra-secondary" type="submit">Check DNS record</button></form>
                        @endif
                    </div>
                @empty
                    <p class="infra-empty">No domain has been registered yet.</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="data-panel infra-panel infra-plan-panel" id="plans" aria-labelledby="plans-title">
        <div class="panel-header"><div><p class="eyebrow panel-eyebrow">STEP 3 · REVIEW</p><h2 id="plans-title">Installation preview</h2></div><span class="panel-meta">PowerMTA is always included</span></div>
        <div class="infra-panel-body infra-plan-body">
            <div>
                <p class="infra-intro">Choose a registered server, a verified domain, the exact PowerMTA version, and the services to prepare. This creates an immutable preview; it does not connect to the server.</p>
                <form method="post" action="{{ route('console.infrastructure.plans.store', $workspace->id) }}" class="infra-form" novalidate>
                    @csrf
                    <div class="infra-two-fields">
                        <div class="infra-field"><label for="plan-server">Server</label><select id="plan-server" name="server_id" required @disabled($servers->isEmpty())><option value="">Select server</option>@foreach ($servers as $server)<option value="{{ $server->id }}" @selected(old('server_id') === $server->id)>{{ $server->name }} · {{ $server->ip_address }}</option>@endforeach</select></div>
                        <div class="infra-field"><label for="plan-domain">Verified domain</label><select id="plan-domain" name="domain_id" required @disabled($domains->where('state', 'verified')->isEmpty())><option value="">Select domain</option>@foreach ($domains->where('state', 'verified') as $domain)<option value="{{ $domain->id }}" @selected(old('domain_id') === $domain->id)>{{ $domain->name }}</option>@endforeach</select></div>
                    </div>
                    <div class="infra-field"><label for="pmta-version">PowerMTA version <span class="infra-required">REQUIRED</span></label><select id="pmta-version" name="pmta_version" required>@foreach ($versions as $version => $package)<option value="{{ $version }}" @selected(old('pmta_version', '5.0') === $version)>{{ $package['label'] }} · {{ $package['installation_status'] }}</option>@endforeach</select></div>
                    <fieldset class="infra-options"><legend>Installation options</legend><div class="infra-option-grid">
                        @foreach (['tracking' => 'Tracking + HTTPS', 'dkim' => 'DKIM', 'dmarc' => 'DMARC', 'proxy' => 'Proxy', 'spf' => 'SPF'] as $key => $label)
                            <label class="infra-option"><input type="checkbox" name="{{ $key }}" value="1" @checked(old($key))><span>{{ $label }}</span></label>
                        @endforeach
                    </div></fieldset>
                    <button class="infra-action" type="submit" @disabled($servers->isEmpty() || $domains->where('state', 'verified')->isEmpty())>Create preview <span aria-hidden="true">↗</span></button>
                    @if ($servers->isEmpty() || $domains->where('state', 'verified')->isEmpty())<p class="infra-field-note">Add a server and verify a domain to create a preview.</p>@endif
                </form>
            </div>
            <aside class="infra-package-card" aria-label="PowerMTA package status"><p class="eyebrow">PACKAGE GATE</p><h3>Version availability</h3>
                @foreach ($versions as $package)
                    <div class="infra-package"><strong>{{ $package['label'] }}</strong><span>{{ $package['artifact_status'] }}</span></div>
                @endforeach
                <p class="infra-package-note">Candidate OS: AlmaLinux 9 x86_64. It will be approved only after the exact PMTA build and license pass a clean-host test.</p>
            </aside>
        </div>
    </section>

    @if ($plan)
        <section class="data-panel infra-panel infra-preview-panel" aria-labelledby="preview-title">
            <div class="panel-header"><div><p class="eyebrow panel-eyebrow">IMMUTABLE PLAN · {{ substr($plan->preview_sha256, 0, 12) }}</p><h2 id="preview-title">Review of {{ $plan->preview['server']['name'] }}</h2></div><span class="infra-state">Installation blocked</span></div>
            <div class="infra-panel-body">
                <div class="infra-preview-summary"><div><span>SERVER</span><strong>{{ $plan->preview['server']['ip_address'] }}</strong></div><div><span>DOMAIN</span><strong>{{ $plan->preview['domain']['name'] }}</strong></div><div><span>PACKAGE</span><strong>{{ $plan->preview['pmta']['label'] }}</strong></div></div>
                <h3>Blocking checks</h3><ul class="infra-blockers">@foreach ($plan->preview['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
                @if (count($plan->preview['dns']))<h3>DNS work to prepare</h3><ul class="infra-dns-list">@foreach ($plan->preview['dns'] as $record)<li><code>{{ $record['name'] }}</code><span>{{ $record['action'] }}</span></li>@endforeach</ul>@endif
                <button class="infra-action" type="button" disabled aria-describedby="install-disabled-reason">Install server</button><p id="install-disabled-reason" class="infra-field-note">Installation unlocks only after package, host, backup, rollback, and health checks are implemented and verified.</p>
            </div>
        </section>
    @endif

    @if ($plans->isNotEmpty())
        <section class="data-panel infra-panel" aria-labelledby="plan-history-title"><div class="panel-header"><div><p class="eyebrow panel-eyebrow">REVIEW HISTORY</p><h2 id="plan-history-title">Saved previews</h2></div></div><div class="infra-panel-body infra-history">@foreach ($plans as $savedPlan)<a href="{{ route('console.infrastructure.index', ['workspace' => $workspace->id, 'plan' => $savedPlan->id]) }}"><span>{{ $savedPlan->preview['server']['name'] }} · {{ $savedPlan->preview['domain']['name'] }}</span><small>{{ $savedPlan->pmta_version }} · {{ $savedPlan->created_at?->format('M j, Y H:i') }} · blocked</small></a>@endforeach</div></section>
    @endif
@endsection
