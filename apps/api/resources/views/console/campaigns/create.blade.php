@extends('layouts.admin-shell')

@section('title', isset($editingCampaign) ? 'Edit Campaign' : 'Create Campaign')

@section('content')
    @php
        $editingCampaign = $editingCampaign ?? null;
        $preparingCampaign = $preparingCampaign ?? null;
        $savedContent = $editingCampaign?->latestRevision?->payload ?? [];
        $savedSetup = $savedContent['send_setup'] ?? null;
        $selectedOfferAssets = $editingCampaign
            ? ($savedOfferAssets ?? [])
            : ($sendOptions['offers']->firstWhere('id', (int) old('send_setup.offer_id', 0))['assets'] ?? []);
        $defaultCampaignHeader = "MIME-version: 1.0\nContent-type: text/html\nTo: [to]\nfrom: [from] <info@[domain]>\nSubject: [subject]\nDate: [date]";
        $campaignParameterIcons = [
            'duplicate_data' => 'fa-copy',
            'total_send' => 'fa-paper-plane',
            'fraction_ip' => 'fa-percent',
            'sleep_email' => 'fa-clock',
            'sleep_ip' => 'fa-clock',
            'seed_every' => 'fa-flask',
            'bounce_limit' => 'fa-exclamation-triangle',
        ];
        $setup = [
            'sponsor_id' => (string) old('send_setup.sponsor_id', $savedSetup['sponsor_id'] ?? ''),
            'offer_id' => (string) old('send_setup.offer_id', $savedSetup['offer_id'] ?? ''),
            'negative_id' => (string) old('send_setup.negative_id', $savedSetup['negative_id'] ?? ''),
            'offer_country' => strtoupper((string) old('send_setup.offer_country', $savedSetup['offer_country'] ?? 'ALL')),
            'isp_id' => (string) old('send_setup.isp_id', $savedSetup['isp_id'] ?? ''),
            'country' => strtoupper((string) old('send_setup.country', $savedSetup['country'] ?? 'ALL')),
            'audience_segments' => array_map('strval', (array) old('send_setup.audience_segments', $savedSetup['audience_segments'] ?? [])),
            'from_asset_ids' => array_map('strval', (array) old('send_setup.from_asset_ids', $savedSetup['from_asset_ids'] ?? [])),
            'subject_asset_ids' => array_map('strval', (array) old('send_setup.subject_asset_ids', $savedSetup['subject_asset_ids'] ?? [])),
            'creative_asset_id' => (string) old('send_setup.creative_asset_id', $savedSetup['creative_asset_id'] ?? ''),
            'html_asset_id' => (string) old('send_setup.html_asset_id', $savedSetup['html_asset_id'] ?? ''),
            'order_by' => (string) old('send_setup.order_by', $savedSetup['order_by'] ?? 'default'),
            'start_from' => (string) old('send_setup.start_from', $savedSetup['start_from'] ?? '0'),
            'route_option' => (string) old('send_setup.route_option', $savedSetup['route_option'] ?? 'ip'),
            'ip_type' => (string) old('send_setup.ip_type', $savedSetup['ip_type'] ?? 'ipv4'),
            'server_ids' => array_map('strval', (array) old('send_setup.server_ids', $savedSetup['server_ids'] ?? [])),
            'ip_ids' => array_map('strval', (array) old('send_setup.ip_ids', $savedSetup['ip_ids'] ?? [])),
            'send_type' => (string) old('send_setup.send_type', $savedSetup['send_type'] ?? 'delay'),
            'total_send' => (string) old('send_setup.total_send', $savedSetup['parameters']['total_send'] ?? '1'),
            'fraction_ip' => (string) old('send_setup.fraction_ip', $savedSetup['parameters']['fraction_ip'] ?? '1'),
            'sleep_email' => (string) old('send_setup.sleep_email', $savedSetup['parameters']['sleep_email'] ?? '0'),
            'sleep_ip' => (string) old('send_setup.sleep_ip', $savedSetup['parameters']['sleep_ip'] ?? '0'),
            'seed_every' => (string) old('send_setup.seed_every', $savedSetup['parameters']['seed_every'] ?? '0'),
            'bounce_limit' => (string) old('send_setup.bounce_limit', $savedSetup['parameters']['bounce_limit'] ?? '1000'),
            'duplicate_data' => (string) old('send_setup.duplicate_data', $savedSetup['parameters']['duplicate_data'] ?? '1'),
        ];
    @endphp

    <div class="campaign-create-page" style="--campaign-hero-art: url('{{ asset('console-assets/campaign-composer-atmosphere.png') }}')">
    <header class="campaign-create-hero">
        <div class="campaign-create-hero-icon" aria-hidden="true"><i class="fas fa-paper-plane"></i></div>
        <div class="campaign-create-hero-copy">
            <h1 id="campaign-page-title">{{ $editingCampaign ? 'Edit Campaign' : 'Create Campaign' }}</h1>
            <p>Build, target and prepare your email campaign with full control.</p>
        </div>
    </header>

    @if (session('status'))
        <div class="alert alert-success" role="status" aria-live="polite">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger" role="alert" tabindex="-1">
            <p class="mb-1">Review the campaign fields below:</p>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div id="campaign-snapshot-progress" class="block block-rounded border mb-4" role="status" aria-live="polite" @if (!$preparingCampaign) hidden @endif
         data-campaigns-url="{{ route('console.campaigns.index', $workspace->id) }}"
         @if ($preparingCampaign) data-progress-url="{{ route('console.campaigns.snapshot-progress', [$workspace->id, $preparingCampaign->id]) }}" data-campaign-url="{{ route('console.campaigns.show', [$workspace->id, $preparingCampaign->id]) }}" data-edit-url="{{ route('console.campaigns.edit', [$workspace->id, $preparingCampaign->id]) }}" @endif>
        <div class="block-header block-header-default">
            <h3 class="block-title">SUPPRESSION AND RECIPIENT PREPARATION</h3>
            <span id="campaign-snapshot-percent" class="badge badge-primary">0%</span>
        </div>
        <div class="block-content">
            <p id="campaign-snapshot-stage" class="mb-2">Waiting for preparation status…</p>
            <div class="progress" style="height: 1.25rem" role="progressbar" aria-label="Campaign suppression and recipient preparation" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <div id="campaign-snapshot-bar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%">0%</div>
            </div>
            <div class="d-flex justify-content-between mt-2">
                <small id="campaign-snapshot-detail" class="text-muted">The campaign composer stays open while the selected lists are processed.</small>
                <a id="campaign-snapshot-open" class="small" href="#" hidden>Open saved campaign</a>
            </div>
            <div id="campaign-snapshot-error" class="alert alert-danger mt-3 mb-0" role="alert" hidden></div>
            <a id="campaign-snapshot-retry" class="btn btn-sm btn-outline-primary mt-3" href="#" hidden>Review saved campaign and retry preparation</a>
        </div>
    </div>

    @php
        $campaignMenuItems = $editingCampaign
            ? [
                ['action' => 'edit', 'label' => 'EDIT CAMPAIGN', 'icon' => 'fas fa-edit'],
                ['action' => 'test', 'label' => 'TEST CAMPAIGN', 'icon' => 'fas fa-vial'],
                ['action' => 'pmta-admin', 'label' => 'PMTA ADMIN', 'icon' => 'fas fa-grip-horizontal'],
                ['action' => 'pmta-panel', 'label' => 'PMTA PANEL', 'icon' => 'fas fa-server'],
            ]
            : [
                ['action' => 'create', 'label' => 'CREATE CAMPAIGN', 'icon' => 'fas fa-plus'],
                ['action' => 'create-send', 'label' => 'CREATE & SEND CAMPAIGN', 'icon' => 'fas fa-paper-plane', 'unavailable' => true],
                ['action' => 'test', 'label' => 'TEST CAMPAIGN', 'icon' => 'fas fa-vial'],
                ['action' => 'pmta-admin', 'label' => 'PMTA ADMIN', 'icon' => 'fas fa-grip-horizontal'],
                ['action' => 'pmta-panel', 'label' => 'PMTA PANEL', 'icon' => 'fas fa-server'],
            ];
    @endphp

        <style>
            #campaign-action-backdrop { position: fixed; inset: 0; z-index: 1090; background: rgba(0, 0, 0, .82); }
            #campaign-snapshot-progress[hidden], #campaign-snapshot-open[hidden], #campaign-snapshot-retry[hidden], #campaign-new-campaign[hidden] { display: none !important; }
            #campaign-floating-actions { position: fixed; top: 45%; right: 0; z-index: 1100; }
            #campaign-actions-trigger { width: 36px; height: 36px; border: 0; color: #fff; background: #527bd8; font-size: 18px; box-shadow: 0 2px 6px rgba(0, 0, 0, .25); }
            #campaign-actions-panel { position: fixed; top: calc(45% + 42px); right: 0; width: min(218px, calc(100vw - 4px)); padding: 0; background: transparent; box-shadow: none; }
            #campaign-actions-panel[hidden], #campaign-action-backdrop[hidden], #campaign-context-menu[hidden] { display: none !important; }
            #campaign-actions-panel button { position: relative; display: flex; width: 100%; min-height: 36px; align-items: center; justify-content: flex-end; border: 0; background: transparent; color: #fff; padding: 6px 46px 6px 8px; text-align: right; text-shadow: 0 1px 2px #111; white-space: nowrap; }
            #campaign-context-menu button { display: flex; width: 100%; align-items: center; justify-content: space-between; gap: 16px; border: 0; background: transparent; color: #253044; padding: 8px 12px; text-align: left; white-space: nowrap; }
            #campaign-actions-panel button:hover, #campaign-actions-panel button:focus { background: rgba(82, 123, 216, .28); color: #fff; outline: 0; }
            #campaign-context-menu button:hover, #campaign-context-menu button:focus { background: #edf2ff; outline: 0; }
            #campaign-actions-panel i { position: absolute; top: 0; right: 0; display: grid; width: 36px; height: 36px; place-items: center; color: #fff; background: #527bd8; text-shadow: none; }
            #campaign-context-menu i { color: #3182ce; }
            #campaign-actions-panel [aria-disabled="true"], #campaign-context-menu [aria-disabled="true"] { color: #687386; cursor: help; }
            #campaign-actions-panel [aria-disabled="true"] { color: #e0e4ec; }
            #campaign-actions-panel [aria-disabled="true"] i { background: #72809a; }
            #campaign-actions-panel [aria-disabled="true"]:hover { background: rgba(82, 123, 216, .18); }
            #campaign-context-menu [aria-disabled="true"]:hover { background: #f5f6f8; }
            #campaign-context-menu { position: fixed; z-index: 1110; min-width: 190px; padding: 5px 0; background: #fff; box-shadow: 0 2px 8px rgba(0, 0, 0, .32); }
            #campaign-action-status { position: sticky; top: .25rem; z-index: 1120; }
            #campaign-action-status[hidden] { display: none !important; }
            #campaign-body-preview { border: 1px solid #d5dce1; border-radius: .35rem; box-shadow: 0 8px 28px rgb(52 58 64 / 22%); max-height: 90vh; max-width: 96vw; padding: 1rem; width: min(1100px, 96vw); }
            #campaign-body-preview::backdrop { background: rgba(27, 33, 40, .58); }
            #campaign-body-preview .campaign-body-preview-header { align-items: center; display: flex; justify-content: space-between; margin-bottom: .75rem; }
            #campaign-body-preview-frame { border: 1px solid #d7dce3; display: block; height: min(72vh, 820px); min-height: 360px; width: 100%; }
            .campaign-create-page {
                --campaign-ink: #101746;
                --campaign-muted: #667092;
                --campaign-border: #dce0f4;
                --campaign-surface: #fff;
                --campaign-wash: #f8f7ff;
                --campaign-purple: #792bfa;
                --campaign-blue: #238dff;
                color: var(--campaign-ink);
                font-family: Inter, 'Segoe UI', Arial, sans-serif;
                isolation: isolate;
                position: relative;
            }
            .campaign-create-page::after {
                background-image: var(--campaign-hero-art);
                background-position: left top;
                background-repeat: no-repeat;
                background-size: 200% auto;
                bottom: 0;
                content: '';
                height: 104px;
                left: 0;
                opacity: .78;
                pointer-events: none;
                position: fixed;
                right: 0;
                z-index: -1;
            }
            .campaign-create-hero {
                align-items: center;
                display: flex;
                gap: 16px;
                min-height: 78px;
                margin: 0 0 8px;
                padding: 8px 4px;
                overflow: hidden;
                background-color: transparent;
                background-image: linear-gradient(90deg, rgb(255 255 255 / 92%) 0%, rgb(255 255 255 / 74%) 34%, rgb(255 255 255 / 20%) 100%), var(--campaign-hero-art);
                background-position: center 46%;
                background-size: cover;
            }
            .campaign-create-hero-icon {
                align-items: center;
                background: linear-gradient(145deg, #c45bff, #7027f4 72%);
                border: 1px solid rgb(255 255 255 / 72%);
                border-radius: 17px;
                box-shadow: 0 8px 20px rgb(112 39 244 / 28%);
                color: #fff;
                display: flex;
                flex: 0 0 58px;
                font-size: 25px;
                height: 58px;
                justify-content: center;
            }
            .campaign-create-hero-copy { min-width: 0; }
            .campaign-create-hero h1 {
                color: #101443;
                font-size: clamp(23px, 1.8vw, 29px);
                font-weight: 800;
                letter-spacing: -.045em;
                line-height: 1.08;
                margin: 0;
            }
            .campaign-create-hero-copy > p:last-child {
                color: #303c70;
                font-size: 13px;
                margin: 4px 0 0;
            }
            .campaign-stage-track {
                margin: 0 0 16px;
                overflow: hidden;
                border: 1px solid #d9dcf3;
                border-radius: 17px;
                background: linear-gradient(105deg, #fff 0%, #fbfaff 53%, #f3f1ff 100%);
                box-shadow: 0 5px 16px rgb(63 67 140 / 6%);
            }
            .campaign-stage-track ol {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                list-style: none;
                margin: 0;
                padding: 0;
            }
            .campaign-stage-track li {
                align-items: center;
                color: #26315e;
                display: flex;
                gap: 11px;
                min-height: 56px;
                padding: 8px 17px;
                position: relative;
                z-index: 1;
            }
            .campaign-stage-track li + li { padding-left: 25px; }
            .campaign-stage-track li:not(:last-child)::after {
                border-bottom: 28px solid transparent;
                border-left: 14px solid #f0effb;
                border-top: 28px solid transparent;
                content: '';
                height: 0;
                position: absolute;
                right: -13px;
                top: 0;
                width: 0;
                z-index: 2;
            }
            .campaign-stage-number {
                align-items: center;
                background: #fff;
                border: 1px solid #c9ccef;
                border-radius: 50%;
                color: #15204d;
                display: inline-flex;
                flex: 0 0 36px;
                font-size: 16px;
                font-weight: 750;
                height: 36px;
                justify-content: center;
            }
            .campaign-stage-track li strong,
            .campaign-stage-track li small { display: block; line-height: 1.2; }
            .campaign-stage-track li strong { font-size: 13px; font-weight: 780; }
            .campaign-stage-track li small { color: #687293; font-size: 11px; margin-top: 2px; }
            .campaign-stage-track li.is-current {
                background: linear-gradient(110deg, #c65aff, #762df4 75%);
                color: #fff;
                z-index: 4;
            }
            .campaign-stage-track li.is-current::after { border-left-color: #762df4; }
            .campaign-stage-track li.is-current .campaign-stage-number {
                background: rgb(255 255 255 / 20%);
                border-color: rgb(255 255 255 / 75%);
                color: #fff;
            }
            .campaign-stage-track li.is-current small { color: rgb(255 255 255 / 88%); }
            .campaign-create-layout {
                align-items: start;
                display: grid;
                gap: 14px;
                grid-template-columns: minmax(0, 1fr) minmax(270px, 300px);
            }
            .campaign-create-main { min-width: 0; }
            .campaign-create-page .block {
                background: var(--campaign-surface);
                border: 1px solid var(--campaign-border);
                border-radius: 15px;
                box-shadow: 0 5px 18px rgb(63 67 140 / 6%);
                margin-bottom: 10px;
                overflow: visible;
            }
            .campaign-create-page .block-header {
                align-items: center;
                background: transparent;
                border-bottom: 1px solid #f0eff8;
                border-radius: 15px 15px 0 0;
                display: flex;
                flex-wrap: wrap;
                gap: 4px 12px;
                min-height: 42px;
                padding: 8px 14px;
            }
            .campaign-create-page .block-title {
                color: var(--campaign-ink);
                font-size: 15px;
                font-weight: 790;
                letter-spacing: -.025em;
                margin: 0;
                text-transform: none;
            }
            .campaign-create-page .block-title > i,
            .campaign-create-page #campaign-targeting .block-title > i {
                color: var(--campaign-purple);
                margin-right: 7px;
            }
            .campaign-section-caption { color: var(--campaign-muted); font-size: 11px; }
            .campaign-create-page .block-content { padding: 12px 14px; }
            .campaign-create-page #campaign-targeting > .row { margin-left: -5px; margin-right: -5px; }
            .campaign-create-page #campaign-targeting > .row > [class*='col-'] { padding-left: 5px; padding-right: 5px; }
            .campaign-create-page #campaign-targeting .block { height: 100%; }
            .campaign-create-page #campaign-targeting .block,
            .campaign-create-page .campaign-email-card,
            .campaign-create-page .campaign-delivery-card {
                background: linear-gradient(145deg, #fff 0%, #fcfbff 62%, #f8f7ff 100%);
                border: 1px solid var(--campaign-border);
                position: relative;
                box-shadow: 0 6px 18px rgb(78 64 164 / 7%);
                overflow: hidden;
            }
            .campaign-create-page #campaign-targeting > .row > [class*='col-']:first-child .block {
                background: linear-gradient(145deg, #fff 0%, #fdfaff 63%, #f7f2ff 100%);
            }
            .campaign-create-page #campaign-targeting > .row > [class*='col-']:last-child .block {
                background: linear-gradient(145deg, #fff 0%, #fbfdff 63%, #f2f8ff 100%);
            }
            .campaign-create-page #campaign-targeting .block-header { min-height: 40px; }
            .campaign-create-page #campaign-targeting .block-content { padding: 6px 12px; }
            .campaign-create-page #campaign-targeting .form-group { margin-bottom: 0; }
            .campaign-create-page #campaign-targeting .form-control:not(textarea):not([multiple]):not(.bootstrap-select) { height: 24px; min-height: 24px; padding: 1px 8px; }
            .campaign-create-page #campaign-targeting .bootstrap-select.form-control > .dropdown-toggle { height: 24px; min-height: 24px; padding: 1px 8px; }
            .campaign-create-page .campaign-target-label-row { align-items: center; display: flex; justify-content: space-between; margin-bottom: 4px; }
            .campaign-create-page .campaign-target-label-row label { margin-bottom: 0; }
            .campaign-create-page #campaign-targeting > .row > [class*='col-']:first-child .block-header,
            .campaign-create-page .campaign-email-card .block-header,
            .campaign-create-page .campaign-delivery-card .block-header {
                background-image: linear-gradient(135deg, transparent 72%, rgb(143 75 247 / 5%) 72%, rgb(143 75 247 / 5%) 80%, transparent 80%, transparent 84%, rgb(143 75 247 / 8%) 84%, rgb(143 75 247 / 8%) 92%, transparent 92%);
            }
            .campaign-create-page #campaign-targeting > .row > [class*='col-']:last-child .block-header {
                background-image: linear-gradient(135deg, transparent 72%, rgb(45 164 246 / 6%) 72%, rgb(45 164 246 / 6%) 80%, transparent 80%, transparent 84%, rgb(45 164 246 / 9%) 84%, rgb(45 164 246 / 9%) 92%, transparent 92%);
            }
            .campaign-create-page .form-group { margin-bottom: 8px; }
            .campaign-create-page label { color: var(--campaign-ink); font-size: 11px; font-weight: 700; margin-bottom: 4px; }
            .campaign-create-page .form-control,
            .campaign-create-page .bootstrap-select > .dropdown-toggle {
                background-color: #fff;
                border: 1px solid #cfd4ed;
                border-radius: 7px;
                color: #151d43;
                font-size: 12px;
                min-height: 30px;
                padding: 4px 9px;
            }
            .campaign-create-page .form-control:not(textarea):not([multiple]):not(.bootstrap-select) { height: 30px; }
            .campaign-create-page .form-control:focus,
            .campaign-create-page .bootstrap-select > .dropdown-toggle:focus {
                border-color: #9254ff;
                box-shadow: 0 0 0 3px rgb(139 74 255 / 12%);
            }
            .campaign-create-page .form-text,
            .campaign-create-page .text-muted { color: var(--campaign-muted) !important; font-size: 10px; line-height: 1.45; }
            .campaign-create-page .bootstrap-select { width: 100% !important; }
            .campaign-create-page .bootstrap-select > .dropdown-toggle { box-shadow: none; }
            .campaign-create-page .bootstrap-select.form-control {
                background: transparent;
                border: 0;
                box-shadow: none;
                height: auto;
                min-height: 0;
                padding: 0;
            }
            .campaign-create-page .bootstrap-select.form-control > .dropdown-toggle { height: 30px; min-height: 30px; }
            .campaign-create-page .campaign-field-icon,
            .campaign-create-page .campaign-label-icon {
                color: var(--campaign-purple);
                display: inline-block;
                font-size: 11px;
                margin-right: 5px;
                text-align: center;
                width: 13px;
            }
            .campaign-create-page #campaign-usable-tags {
                align-items: center;
                background: linear-gradient(100deg, #fff, #f6f3ff);
                border: 1px solid #ddd7ff;
                border-radius: 12px;
                color: #5f32d5;
                display: flex;
                flex-wrap: wrap;
                font-size: 10px;
                gap: 4px 8px;
                justify-content: flex-start;
                margin: 0 0 10px;
                padding: 8px 12px !important;
                text-align: left !important;
            }
            .campaign-create-page #campaign-usable-tags > .campaign-usable-tag-list { min-width: 0; }
            .campaign-create-page #campaign-usable-tags small { color: var(--campaign-muted); font-size: 9px; }
            .campaign-create-page #campaign-usable-tags .close { margin-left: auto; }
            .campaign-create-page .campaign-create-help { margin: 0; }
            .campaign-create-page .campaign-create-help summary {
                color: var(--campaign-muted);
                cursor: pointer;
                font-size: 10px;
                line-height: 1.3;
                list-style: none;
                width: fit-content;
            }
            .campaign-create-page .campaign-create-help summary::-webkit-details-marker { display: none; }
            .campaign-create-page .campaign-target-label-row .campaign-create-help summary {
                align-items: center;
                border: 1px solid #e3dcfb;
                border-radius: 50%;
                color: #7d43e8;
                display: inline-flex;
                height: 17px;
                justify-content: center;
                width: 17px;
            }
            .campaign-create-page .campaign-target-label-row .campaign-create-help summary .campaign-field-icon { margin: 0; width: auto; }
            .campaign-create-page .campaign-create-help:not([open]) > small { display: none; }
            .campaign-create-page .campaign-create-help[open] > small { display: block; margin-top: 5px; }
            .campaign-create-page .campaign-email-card .block-content { padding: 6px 12px; }
            .campaign-create-page .campaign-email-card .form-group { margin-bottom: 0; }
            .campaign-create-page .campaign-email-card label { margin-bottom: 2px; }
            .campaign-create-page .campaign-email-card .form-control:not(textarea):not([multiple]):not(.bootstrap-select) { height: 24px; min-height: 24px; padding: 1px 8px; }
            .campaign-create-page .campaign-email-card .bootstrap-select.form-control > .dropdown-toggle { height: 24px; min-height: 24px; padding: 1px 8px; }
            .campaign-create-page .campaign-email-card #campaign-email-tests { height: 24px; min-height: 24px; resize: vertical; }
            .campaign-create-page .campaign-header-library .input-group { display: flex; flex-wrap: nowrap; }
            .campaign-create-page .campaign-header-library .input-group > .bootstrap-select.form-control { flex: 1 1 0; min-width: 0; width: 1% !important; }
            .campaign-create-page .campaign-header-library .input-group > .input-group-append { display: flex; flex: 0 0 auto; }
            .campaign-create-page .campaign-header-library [data-header-status]:empty { display: none; }
            .campaign-create-page #campaign-saved-emails .input-group { display: flex; flex-wrap: nowrap; }
            .campaign-create-page #campaign-saved-emails .input-group > .bootstrap-select.form-control { flex: 1 1 0; min-width: 0; width: 1% !important; }
            .campaign-create-page #campaign-saved-emails .input-group > .input-group-append { display: flex; flex: 0 0 auto; }
            .campaign-create-page #saved-email-manage { height: 26px; min-height: 26px; padding: 1px 9px; }
            .campaign-create-page .campaign-delivery-card [data-campaign-placeholder-editor] {
                background: transparent;
                border: 0;
                border-radius: 0;
                box-shadow: none;
                margin: 0;
                position: static;
            }
            .campaign-create-page .campaign-delivery-card [data-campaign-placeholder-editor] > .block-header {
                background: transparent;
                border: 0;
                justify-content: flex-end;
                min-height: 0;
                padding: 0;
                position: absolute;
                right: 14px;
                top: 7px;
                z-index: 2;
            }
            .campaign-create-page .campaign-delivery-card > .block-header > .campaign-section-caption { display: none; }
            .campaign-create-page .campaign-delivery-card > .block-content { padding: 6px 12px 10px; position: static; }
            .campaign-create-page .campaign-delivery-card [data-campaign-placeholder-editor] > .block-content { padding: 0 3px; }
            .campaign-create-page .campaign-delivery-card [data-campaign-placeholder-editor] .table-responsive:not(:has(tbody tr)) { display: none; }
            .campaign-create-page .campaign-delivery-card [data-campaign-placeholder-editor]:has(tbody tr) > .block-content { padding-top: 28px; }
            .campaign-create-page .campaign-placeholder-toolbar { gap: 7px; margin: 0; }
            .campaign-create-page .campaign-placeholder-toolbar .custom-control-inline { margin-right: 0; }
            .campaign-create-page .campaign-placeholder-toolbar [data-placeholder-add] {
                background: rgb(122 48 244 / 7%);
                border: 1px solid rgb(122 48 244 / 18%);
                border-radius: 7px;
                color: #6224d5;
                line-height: 1.25;
                min-height: 26px;
                padding: 2px 8px;
            }
            .campaign-create-page .campaign-placeholder-toolbar [data-placeholder-add]:hover,
            .campaign-create-page .campaign-placeholder-toolbar [data-placeholder-add]:focus { background: rgb(122 48 244 / 13%); }
            .campaign-create-page .campaign-header-editor,
            .campaign-create-page #campaign-body {
                background: #fcfbff;
                font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', monospace;
                font-size: 11px;
                line-height: 1.4;
                min-height: 80px;
                resize: vertical;
            }
            .campaign-create-page .campaign-header-editor { height: 82px; }
            .campaign-create-page #campaign-body { height: 82px; }
            .campaign-create-page #campaign-routing {
                border-top: 0;
                margin-top: 0;
                padding-top: 4px;
            }
            .campaign-create-page #campaign-routing > .row > [class*='col-'] { min-width: 0; }
            .campaign-create-page .campaign-delivery-card .form-group { margin-bottom: 2px; }
            .campaign-create-page .campaign-delivery-card label { margin-bottom: 2px; }
            .campaign-create-page .campaign-delivery-card .form-control:not(textarea):not([multiple]):not(.bootstrap-select) { height: 24px; min-height: 24px; padding: 1px 8px; }
            .campaign-create-page .campaign-delivery-card .bootstrap-select.form-control > .dropdown-toggle { height: 24px; min-height: 24px; padding: 1px 8px; }
            .campaign-create-page #send-ips-available-filter,
            .campaign-create-page #send-ips-selected-filter { height: 22px; min-height: 22px; padding: 1px 7px; }
            .campaign-create-page #send-ips-available,
            .campaign-create-page #send-ips-selected { height: 56px; min-height: 56px; }
            .campaign-create-page #campaign-routing .col-md-2.d-flex.flex-column {
                align-content: center;
                display: grid !important;
                gap: 4px;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                padding-top: 18px;
            }
            .campaign-create-page #campaign-routing .col-md-2 [data-ip-move] { margin: 0 !important; min-height: 23px; padding: 0 4px; width: 100%; }
            .campaign-create-page #send-route-behavior { background: #f7f5ff; border-left: 3px solid #9a5aff; border-radius: 0 7px 7px 0; margin-top: 8px; padding: 7px 9px; }
            .campaign-create-page #campaign-options-status.alert-info {
                background: transparent !important;
                border: 0 !important;
                box-shadow: none;
                color: var(--campaign-muted);
                font-size: 10px;
                margin: 0 0 6px;
                padding: 3px 12px !important;
            }
            .campaign-create-summary { position: sticky; top: 56px; }
            .campaign-summary-card {
                background: var(--campaign-surface);
                border: 1px solid var(--campaign-border);
                border-radius: 15px;
                box-shadow: 0 5px 18px rgb(63 67 140 / 7%);
                margin-bottom: 10px;
                padding: 13px;
            }
            .campaign-summary-heading { align-items: center; border-bottom: 1px solid #efedfa; display: flex; gap: 10px; padding-bottom: 10px; }
            .campaign-summary-icon {
                align-items: center;
                background: linear-gradient(145deg, #efe7ff, #e1e9ff);
                border-radius: 11px;
                color: #7b34f4;
                display: inline-flex;
                flex: 0 0 38px;
                font-size: 17px;
                height: 38px;
                justify-content: center;
            }
            .campaign-summary-card h2 { color: var(--campaign-ink); font-size: 15px; font-weight: 790; letter-spacing: -.025em; margin: 0; }
            .campaign-summary-heading p { color: var(--campaign-muted); font-size: 10px; line-height: 1.35; margin: 3px 0 0; }
            .campaign-summary-checklist { list-style: none; margin: 6px 0 0; padding: 0; }
            .campaign-summary-checklist li { align-items: center; border-bottom: 1px solid #f0eff8; display: flex; justify-content: space-between; gap: 8px; min-height: 33px; }
            .campaign-summary-checklist li:last-child { border-bottom: 0; }
            .campaign-summary-checklist li > span { color: var(--campaign-ink); font-size: 11px; font-weight: 680; white-space: nowrap; }
            .campaign-summary-checklist li > span i { color: #7941ee; margin-right: 6px; width: 15px; }
            .campaign-summary-checklist strong { color: #8a90a8; font-size: 10px; font-weight: 690; text-align: right; }
            .campaign-summary-checklist strong.is-ready { color: #15975a; }
            .campaign-summary-checklist strong.is-pending { color: #dc8b20; }
            .campaign-summary-checklist strong.is-optional { color: #7b66bb; }
            .campaign-summary-stats { display: grid; gap: 7px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: 10px; }
            .campaign-summary-stats > div { align-items: center; background: linear-gradient(150deg, #fff, #f8f7ff); border: 1px solid #e2e1f3; border-radius: 11px; display: flex; flex-direction: column; min-height: 78px; justify-content: center; padding: 7px 3px; text-align: center; }
            .campaign-summary-stats i { color: #8035f4; font-size: 16px; margin-bottom: 3px; }
            .campaign-summary-stats strong { color: var(--campaign-ink); font-size: 18px; font-variant-numeric: tabular-nums; font-weight: 800; line-height: 1.05; }
            .campaign-summary-stats span { color: var(--campaign-muted); font-size: 9px; margin-top: 3px; }
            .campaign-readiness-heading { align-items: center; display: flex; justify-content: space-between; gap: 8px; }
            .campaign-readiness-heading strong { color: #7535ee; font-size: 12px; }
            .campaign-readiness-track { background: #eceafd; border-radius: 99px; height: 10px; margin: 10px 0 6px; overflow: hidden; }
            .campaign-readiness-track span { background: linear-gradient(90deg, #c04eff, #6d37f8); border-radius: inherit; display: block; height: 100%; transition: width .2s ease; width: 0; }
            .campaign-readiness-card small { color: var(--campaign-muted); font-size: 10px; }
            .campaign-summary-actions {
                background: linear-gradient(145deg, #fff, #f6f3ff);
                border: 1px solid #ded9f8;
                border-radius: 15px;
                box-shadow: 0 6px 18px rgb(77 56 169 / 8%);
                padding: 12px;
            }
            .campaign-summary-actions p { color: var(--campaign-muted); font-size: 10px; line-height: 1.45; margin: 0 0 10px; }
            .campaign-create-submit {
                background: linear-gradient(100deg, #b34eff, #6e2df1);
                border: 0;
                border-radius: 11px;
                box-shadow: 0 7px 16px rgb(112 42 242 / 22%);
                color: #fff;
                font-size: 14px;
                font-weight: 780;
                min-height: 46px;
                width: 100%;
            }
            .campaign-create-submit:hover,
            .campaign-create-submit:focus { background: linear-gradient(100deg, #a53df5, #5e22dd); box-shadow: 0 0 0 3px rgb(139 74 255 / 20%), 0 7px 16px rgb(112 42 242 / 22%); color: #fff; }
            .campaign-create-submit i { margin-right: 7px; }
            .campaign-create-page .campaign-cancel-link { border-color: #d9d8ed; border-radius: 9px; color: var(--campaign-ink); display: block; font-size: 12px; font-weight: 700; margin-top: 8px; padding: 8px; text-align: center; }
            .campaign-create-page #campaign-floating-actions { bottom: 16px; right: 16px; top: auto; }
            .campaign-create-page #campaign-actions-trigger {
                background: linear-gradient(145deg, #b14eff, #7028f4);
                border: 1px solid rgb(255 255 255 / 76%);
                border-radius: 50%;
                box-shadow: 0 5px 16px rgb(97 49 206 / 25%);
                height: 40px;
                width: 40px;
            }
            .campaign-create-page #campaign-actions-panel {
                background: #fff;
                border: 1px solid var(--campaign-border);
                border-radius: 12px;
                bottom: 64px;
                box-shadow: 0 12px 30px rgb(29 31 68 / 18%);
                padding: 6px;
                right: 16px;
                top: auto;
                width: min(230px, calc(100vw - 32px));
            }
            .campaign-create-page #campaign-actions-panel button {
                border-radius: 8px;
                color: var(--campaign-ink);
                justify-content: flex-start;
                padding: 8px 8px 8px 42px;
                text-align: left;
                text-shadow: none;
            }
            .campaign-create-page #campaign-actions-panel button:hover,
            .campaign-create-page #campaign-actions-panel button:focus { background: #f4efff; color: #5220b7; }
            .campaign-create-page #campaign-actions-panel i {
                background: transparent;
                color: #7837ef;
                left: 8px;
                right: auto;
                width: 26px;
            }
            .campaign-create-page #campaign-actions-panel [aria-disabled='true'] { color: #73798b; }
            .campaign-create-page #campaign-actions-panel [aria-disabled='true'] i { background: transparent; color: #9095a4; }
            .campaign-create-page :focus-visible { outline: 3px solid rgb(127 61 245 / 60%); outline-offset: 2px; }
            html[data-command-theme='dark'] .campaign-create-page {
                --campaign-ink: #f1f3ff;
                --campaign-muted: #abb6d8;
                --campaign-border: #26365f;
                --campaign-surface: #111d3b;
                --campaign-wash: #152342;
                --campaign-purple: #bd72ff;
                --campaign-blue: #3ca9ff;
            }
            html[data-command-theme='dark'] .campaign-create-hero {
                background-color: #101b38;
                background-image: linear-gradient(90deg, rgb(9 17 38 / 87%) 0%, rgb(9 17 38 / 65%) 35%, rgb(9 17 38 / 27%) 100%), var(--campaign-hero-art);
            }
            html[data-command-theme='dark'] .campaign-create-page::after { opacity: .12; }
            html[data-command-theme='dark'] .campaign-create-page #campaign-targeting .block,
            html[data-command-theme='dark'] .campaign-create-page .campaign-email-card,
            html[data-command-theme='dark'] .campaign-create-page .campaign-delivery-card {
                background: linear-gradient(145deg, #111d3b 0%, #142140 100%);
                border-color: var(--campaign-border);
                box-shadow: 0 7px 20px rgb(0 0 0 / 18%);
            }
            html[data-command-theme='dark'] .campaign-create-page #campaign-targeting > .row > [class*='col-']:first-child .block {
                background: linear-gradient(145deg, #111d3b 0%, #1a2146 100%);
            }
            html[data-command-theme='dark'] .campaign-create-page #campaign-targeting > .row > [class*='col-']:last-child .block {
                background: linear-gradient(145deg, #111d3b 0%, #132845 100%);
            }
            html[data-command-theme='dark'] .campaign-create-page #campaign-options-status.alert-info { background: transparent; color: var(--campaign-muted); }
            html[data-command-theme='dark'] .campaign-create-page .campaign-placeholder-toolbar [data-placeholder-add] {
                background: rgb(189 114 255 / 10%);
                border-color: rgb(189 114 255 / 24%);
                color: #e0c7ff;
            }
            html[data-command-theme='dark'] .campaign-create-hero h1,
            html[data-command-theme='dark'] .campaign-create-hero-copy > p:last-child { color: #f3f4ff; }
            html[data-command-theme='dark'] .campaign-stage-track,
            html[data-command-theme='dark'] .campaign-summary-stats > div,
            html[data-command-theme='dark'] .campaign-summary-actions { background: linear-gradient(120deg, #111d3b, #172447); border-color: var(--campaign-border); }
            html[data-command-theme='dark'] .campaign-create-page .block-header { background: transparent; border-color: var(--campaign-border); }
            html[data-command-theme='dark'] .campaign-create-page #campaign-targeting > .row > [class*='col-']:first-child .block-header,
            html[data-command-theme='dark'] .campaign-create-page .campaign-email-card .block-header,
            html[data-command-theme='dark'] .campaign-create-page .campaign-delivery-card .block-header {
                background-image: linear-gradient(135deg, transparent 72%, rgb(181 127 255 / 8%) 72%, rgb(181 127 255 / 8%) 80%, transparent 80%, transparent 84%, rgb(181 127 255 / 12%) 84%, rgb(181 127 255 / 12%) 92%, transparent 92%);
            }
            html[data-command-theme='dark'] .campaign-create-page #campaign-targeting > .row > [class*='col-']:last-child .block-header {
                background-image: linear-gradient(135deg, transparent 72%, rgb(56 176 255 / 9%) 72%, rgb(56 176 255 / 9%) 80%, transparent 80%, transparent 84%, rgb(56 176 255 / 13%) 84%, rgb(56 176 255 / 13%) 92%, transparent 92%);
            }
            html[data-command-theme='dark'] .campaign-stage-track li { color: #eef1ff; }
            html[data-command-theme='dark'] .campaign-stage-track li + li,
            html[data-command-theme='dark'] .campaign-summary-heading,
            html[data-command-theme='dark'] .campaign-summary-checklist li { border-color: #26365f; }
            html[data-command-theme='dark'] .campaign-stage-track li:not(:last-child)::after { border-left-color: #172447; }
            html[data-command-theme='dark'] .campaign-stage-track li.is-current::after { border-left-color: #762df4; }
            html[data-command-theme='dark'] .campaign-stage-track li:nth-child(3),
            html[data-command-theme='dark'] .campaign-stage-track li:nth-child(4) { border-top-color: #26365f; }
            html[data-command-theme='dark'] .campaign-stage-number,
            html[data-command-theme='dark'] .campaign-create-page .form-control,
            html[data-command-theme='dark'] .campaign-create-page .bootstrap-select > .dropdown-toggle,
            html[data-command-theme='dark'] .campaign-create-page #campaign-actions-panel { background-color: #0c1731; border-color: #334574; color: #eff2ff; }
            html[data-command-theme='dark'] .campaign-create-page .campaign-header-editor,
            html[data-command-theme='dark'] .campaign-create-page #campaign-body,
            html[data-command-theme='dark'] .campaign-create-page #send-route-behavior { background: #0c1731; color: #eff2ff; }
            html[data-command-theme='dark'] .campaign-create-page #campaign-usable-tags { background: #172347; border-color: #394778; }
            html[data-command-theme='dark'] .campaign-readiness-track { background: #26365f; }
            html[data-command-theme='dark'] .campaign-create-page #campaign-actions-panel button { color: #eff2ff; }
            @media (max-width: 1120px) {
                .campaign-create-layout { grid-template-columns: minmax(0, 1fr); }
                .campaign-create-summary { display: grid; gap: 10px; grid-template-columns: repeat(2, minmax(0, 1fr)); position: static; }
                .campaign-create-summary > * { margin-bottom: 0; }
                .campaign-summary-actions { align-self: start; }
            }
            @media (max-width: 767.98px) {
                .campaign-create-page::after { height: 64px; opacity: .56; }
                .campaign-create-hero { align-items: center; background-position: 64% center; min-height: 72px; padding: 6px 0; }
                .campaign-create-hero-icon { border-radius: 13px; flex-basis: 44px; font-size: 20px; height: 44px; }
                .campaign-create-hero-copy > p:last-child { max-width: 32ch; }
                .campaign-stage-track { border-radius: 13px; }
                .campaign-stage-track ol { grid-template-columns: repeat(2, minmax(0, 1fr)); }
                .campaign-stage-track li { min-height: 52px; padding: 7px 9px; }
                .campaign-stage-track li:nth-child(3) { border-left: 0; border-top: 1px solid #e6e6f4; }
                .campaign-stage-track li:nth-child(4) { border-top: 1px solid #e6e6f4; }
                .campaign-create-page #campaign-targeting > .row > [class*='col-'] { flex: 0 0 100%; max-width: 100%; }
                .campaign-create-summary { grid-template-columns: minmax(0, 1fr); }
                .campaign-create-page .block-header { align-items: flex-start; flex-direction: column; }
                .campaign-create-page .campaign-delivery-card [data-campaign-placeholder-editor] > .block-header {
                    align-items: center;
                    flex-direction: row;
                    justify-content: flex-start;
                    padding: 0;
                    position: static;
                }
                .campaign-create-page .campaign-delivery-card [data-campaign-placeholder-editor]:has(tbody tr) > .block-content { padding-top: 0; }
                .campaign-create-page .campaign-delivery-card [data-campaign-placeholder-editor] .table-responsive:has(tbody tr) { margin-top: 6px; }
                .campaign-create-page .campaign-placeholder-toolbar { flex-wrap: wrap; justify-content: flex-start; }
                .campaign-create-page .form-row > [class*='col-'] { flex: 0 0 100%; max-width: 100%; }
            }
            @media (prefers-reduced-motion: reduce) {
                .campaign-create-page *,
                .campaign-create-page *::before,
                .campaign-create-page *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; }
            }
        </style>
        <form method="POST" action="{{ $editingCampaign ? route('console.campaigns.revisions.store', [$workspace->id, $editingCampaign->id]) : route('console.campaigns.store', $workspace->id) }}" id="campaign-create-form" data-options-url="{{ $sendOptionsUrl }}" novalidate>
        <div id="campaign-action-backdrop" hidden></div>
        <div id="campaign-floating-actions" data-pmta-admin-url="{{ route('console.pmta-admin.index', $workspace->id) }}" data-editing="{{ $editingCampaign ? 'true' : 'false' }}">
            <button type="button" id="campaign-actions-trigger" aria-label="Open campaign actions" aria-haspopup="menu" aria-expanded="false"><i class="fas fa-bars" aria-hidden="true"></i></button>
            <div id="campaign-actions-panel" role="menu" aria-label="Campaign actions" hidden>
                @foreach ($campaignMenuItems as $item)
                    <button type="button" role="menuitem" data-campaign-menu-action="{{ $item['action'] }}" @if ($item['action'] === 'test') data-test-url="{{ route('console.campaigns.manual-test', $workspace->id) }}" @endif @if ($item['unavailable'] ?? false) aria-disabled="true" title="Save the draft, wait for recipient preparation, then use SEND from My Campaigns." @endif>
                        {{ $item['label'] }} <i class="{{ $item['icon'] }}" aria-hidden="true"></i>
                    </button>
                @endforeach
            </div>
        </div>
        <div id="campaign-context-menu" role="menu" aria-label="Campaign actions" hidden>
            @foreach ($campaignMenuItems as $item)
                <button type="button" role="menuitem" data-campaign-menu-action="{{ $item['action'] }}" @if ($item['action'] === 'test') data-test-url="{{ route('console.campaigns.manual-test', $workspace->id) }}" @endif @if ($item['unavailable'] ?? false) aria-disabled="true" title="Save the draft, wait for recipient preparation, then use SEND from My Campaigns." @endif>
                    {{ $item['label'] }} <i class="{{ $item['icon'] }}" aria-hidden="true"></i>
                </button>
            @endforeach
        </div>
        <div id="campaign-action-status" class="alert alert-info mb-0" role="status" aria-live="polite" hidden></div>
        <div id="campaign-submit-error" class="alert alert-danger mb-3" role="alert" hidden></div>
        @if ($editingCampaign)
            <a id="campaign-test-log-link" class="d-inline-block mt-2" href="{{ route('console.campaigns.show', [$workspace->id, $editingCampaign->id]) }}#campaign-test-logs" hidden>Open campaign test logs</a>
        @endif

        @csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
        @if ($editingCampaign)
            <input type="hidden" name="expected_revision_id" value="{{ $editingCampaign->latestRevision->id }}">
            <input type="hidden" name="test_campaign_id" value="{{ $editingCampaign->id }}">
        @endif
        @unless ($editingCampaign)
        <input type="hidden" name="name" value="{{ old('name', 'Campaign '.strtoupper(substr($idempotencyKey, 0, 8))) }}">
        <input type="hidden" name="kind" value="marketing">
        @endunless

        <nav class="campaign-stage-track" aria-label="Campaign setup stages">
            <ol>
                <li class="is-current" aria-current="step"><span class="campaign-stage-number">1</span><span><strong>Setup</strong><small>Offer &amp; list</small></span></li>
                <li><span class="campaign-stage-number">2</span><span><strong>Configuration</strong><small>Headers &amp; content</small></span></li>
                <li><span class="campaign-stage-number">3</span><span><strong>Sending</strong><small>Routes &amp; delivery</small></span></li>
                <li><span class="campaign-stage-number">4</span><span><strong>Review</strong><small>Summary &amp; create</small></span></li>
            </ol>
        </nav>

        <div class="campaign-create-layout">
        <div class="campaign-create-main">
        @unless ($editingCampaign)
        <div id="campaign-targeting">
            <div class="row">
                <div class="col-sm-12 col-md-6">
                    <div class="block block-rounded">
                        <div class="block-header"><h3 class="block-title"><i class="fas fa-tag" aria-hidden="true"></i> Select Offer</h3></div>
                        <div class="block-content">
                            <div class="form-group">
                                <label for="send-sponsor"><i class="fas fa-users campaign-field-icon" aria-hidden="true"></i>Sponsor:</label>
                                <select id="send-sponsor" name="send_setup[sponsor_id]" class="form-control selectpicker" data-live-search="true" data-allow-clear="true" title="Select Sponsor" required>
                                    <option value="">Select Sponsor</option>
                                    @foreach ($sendOptions['sponsors'] as $sponsor)
                                        <option value="{{ $sponsor['id'] }}" @selected($setup['sponsor_id'] === (string) $sponsor['id'])>{{ $sponsor['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="send-offer-country"><i class="fas fa-globe campaign-field-icon" aria-hidden="true"></i>Offer Countries:</label>
                                <select id="send-offer-country" name="send_setup[offer_country]" class="form-control selectpicker" data-live-search="true" data-size="6" title="Select Countries" required>
                                    <option value="ALL" @selected($setup['offer_country'] === 'ALL')>ALL</option>
                                    @foreach ($countries as $code => $countryName)
                                        <option value="{{ $code }}" @selected($setup['offer_country'] === $code)>{{ $countryName }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group mb-0">
                                <label for="send-offer"><i class="fas fa-tag campaign-field-icon" aria-hidden="true"></i>Offers:</label>
                                <select id="send-offer" name="send_setup[offer_id]" class="form-control selectpicker" data-live-search="true" data-size="6" title="Select Offer" required>
                                    <option value="">Select Offer</option>
                                    @foreach ($sendOptions['offers'] as $offer)
                                        <option value="{{ $offer['id'] }}" @selected($setup['offer_id'] === (string) $offer['id'])>({{ $offer['offer_id'] }}) {{ $offer['name'] }} — {{ $offer['vertical'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-sm-12 col-md-6">
                    <div class="block block-rounded">
                        <div class="block-header"><h3 class="block-title"><i class="fas fa-list-ul" aria-hidden="true"></i> Select List</h3></div>
                        <div class="block-content">
                            <div class="row">
                                <div class="col-sm-12 col-md-6">
                                    <div class="form-group">
                                        <label for="send-isp"><i class="fas fa-broadcast-tower campaign-field-icon" aria-hidden="true"></i>ISPs:</label>
                                        <select id="send-isp" name="send_setup[isp_id]" class="form-control selectpicker" data-live-search="true" data-allow-clear="true" title="Select ISP" required>
                                            <option value="">Select ISP</option>
                                            @foreach ($sendOptions['isps'] as $isp)
                                                <option value="{{ $isp['id'] }}" @selected($setup['isp_id'] === (string) $isp['id'])>{{ $isp['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="col-sm-12 col-md-6">
                                    <div class="form-group">
                                        <label for="send-country"><i class="fas fa-globe campaign-field-icon" aria-hidden="true"></i>Country:</label>
                                        <select id="send-country" name="send_setup[country]" class="form-control selectpicker" data-live-search="true" data-allow-clear="false" data-size="6" title="Select Country" required>
                                            <option value="ALL" @selected($setup['country'] === 'ALL')>ALL</option>
                                            @foreach ($countries as $code => $countryName)
                                                <option value="{{ $code }}" @selected($setup['country'] === $code)>{{ $countryName }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <div class="campaign-target-label-row">
                                    <label for="send-lists"><i class="fas fa-list-ul campaign-field-icon" aria-hidden="true"></i>Lists / email segments:</label>
                                    <details class="campaign-create-help">
                                        <summary title="Suppression details"><i class="fas fa-info-circle campaign-field-icon" aria-hidden="true"></i><span class="sr-only">Suppression details</span></summary>
                                        <small class="form-text text-muted">Select unlocked email types. Saving automatically matches the selected offer’s saved suppression file against every selected List/email type and leaves matches out of the campaign snapshot. Drops → <a href="{{ route('console.drops.suppression', $workspace->id) }}">Suppression</a> separately applies that file to the chosen ISP/List memberships for reusable Offer/List exclusions; you do not need to run it first for campaign creation. Workspace suppressions and duplicate addresses are also excluded.</small>
                                    </details>
                                </div>
                                <select id="send-lists" name="send_setup[audience_segments][]" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 3" title="Select Lists" multiple required>
                                    @foreach ($sendOptions['segments'] as $segment)
                                        <option value="{{ $segment['id'] }}" @selected(in_array((string) $segment['id'], $setup['audience_segments'], true))>{{ $segment['name'] }} — {{ $segment['country'] }} — {{ strtoupper($segment['email_type']) }} — {{ number_format($segment['recipient_count']) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-row mb-0">
                                <div class="form-group col-sm-6">
                                    <label for="send-order-by"><i class="fas fa-sort campaign-field-icon" aria-hidden="true"></i>Order By:</label>
                                    <select id="send-order-by" name="send_setup[order_by]" class="form-control" required>
                                        @foreach (['default' => 'DEFAULT', 'asc' => 'ASC', 'desc' => 'DESC', 'random' => 'RANDOM'] as $value => $label)
                                            <option value="{{ $value }}" @selected($setup['order_by'] === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group col-sm-6">
                                    <label for="send-start-from"><i class="fas fa-cube campaign-field-icon" aria-hidden="true"></i>Start From:</label>
                                    <input id="send-start-from" name="send_setup[start_from]" type="number" min="0" class="form-control" value="{{ $setup['start_from'] }}" required>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @endunless
        <div id="campaign-data" tabindex="-1">
        <div class="alert alert-primary alert-dismissible text-center py-2" id="campaign-usable-tags">
            <strong><i class="fas fa-code campaign-field-icon" aria-hidden="true"></i>USABLE TAGS:</strong>
            <span class="campaign-usable-tag-list">[server] [main_domain] [ip] [domain] [rdns] [to] [date] [name] [rnd] [smtp] [smtp_name] [rnd(N|A|L|U|S|LU|LN|NU)_NUMBER] [text:a|b|c]</span>
            <small class="sr-only">[smtp] and [smtp_name] fill message text; Option SMTP selects the outbound relay. Start a saved campaign from My Campaigns after suppression and recipient preparation finish.</small>
            <button type="button" class="btn btn-link btn-sm p-0 ml-1" id="campaign-tags-help" title="SHOW EXAMPLE" aria-label="Show tag examples"><i class="fas fa-question-circle" aria-hidden="true"></i></button>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">×</span></button>
        </div>
        <div class="block block-rounded campaign-email-card">
            <div class="block-header">
                <h3 class="block-title"><i class="fas fa-envelope" aria-hidden="true"></i> Email Configuration</h3>
                <span class="campaign-section-caption">Configure return path, headers, and email content.</span>
            </div>
            <div class="block-content">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="campaign-return-path"><i class="fas fa-paper-plane campaign-field-icon" aria-hidden="true"></i>Return path:</label>
                        <input id="campaign-return-path" name="return_path" type="text" maxlength="320" class="form-control" value="{{ old('return_path', $savedContent['return_path'] ?? '[rnd]@[domain]') }}">
                    </div>
                    <div class="form-group col-md-4" id="campaign-saved-emails" data-store-url="{{ route('console.campaign-test-addresses.store', $workspace->id) }}" data-delete-url-template="{{ route('console.campaign-test-addresses.destroy', [$workspace->id, '__ADDRESS_ID__']) }}">
                        <label for="saved-email-addresses"><i class="fas fa-envelope campaign-field-icon" aria-hidden="true"></i>Saved Emails:</label>
                        <div class="input-group">
                            <select id="saved-email-addresses" class="form-control selectpicker" data-live-search="true" data-actions-box="true" title="Select Emails" multiple>
                                @foreach ($savedEmailAddresses as $savedAddress)
                                    <option value="{{ $savedAddress->email }}" data-address-id="{{ $savedAddress->id }}">{{ $savedAddress->email }}</option>
                                @endforeach
                            </select>
                            <div class="input-group-append"><button id="saved-email-manage" type="button" class="btn btn-success" aria-label="Manage saved email addresses" title="Manage saved email addresses"><i class="fas fa-plus" aria-hidden="true"></i></button></div>
                        </div>
                        <div id="saved-email-manager" class="mt-2" hidden>
                            <label for="saved-email-new"><i class="fas fa-plus-square campaign-field-icon" aria-hidden="true"></i>Add a test address</label>
                            <div class="input-group"><input id="saved-email-new" type="email" maxlength="255" class="form-control"><div class="input-group-append"><button id="saved-email-add" type="button" class="btn btn-success">Add</button><button id="saved-email-remove" type="button" class="btn btn-outline-danger">Delete selected</button></div></div>
                            <div id="saved-email-status" role="status" aria-live="polite" class="small mt-1"></div>
                        </div>
                    </div>
                    <div class="form-group col-md-4">
                        <label for="campaign-email-tests"><i class="fas fa-envelope campaign-field-icon" aria-hidden="true"></i>Email Test addresses:</label>
                        <textarea id="campaign-email-tests" name="email_test_addresses" rows="1" class="form-control" autocomplete="off">{{ old('email_test_addresses', implode("\n", $savedContent['email_tests'] ?? [])) }}</textarea>
                    </div>
                </div>
                <div class="form-row">
                    @include('console.campaigns.partials.header-library', ['workspace' => $workspace, 'textareaId' => 'campaign-header', 'idPrefix' => 'campaign', 'containerClass' => 'form-group col-sm-12 col-md-4'])
                    <div class="form-group col-sm-12 col-md-4">
                        <label for="campaign-domain"><i class="fas fa-globe campaign-field-icon" aria-hidden="true"></i>Domain:</label>
                        <select id="campaign-domain" name="domain" class="form-control selectpicker" data-live-search="true" data-allow-clear="true" title="Select Domain">
                            <option value="">Select Domain</option>
                            @foreach ($sendOptions['campaign_domains'] as $domain)
                                <option value="{{ $domain['value'] }}" @selected(old('domain', $savedSetup['domain'] ?? '') === $domain['value'])>{{ $domain['name'] }} — {{ $domain['dns'] }}</option>
                            @endforeach
                        </select>
                        <small id="campaign-domain-route-hint" class="form-text text-muted" hidden></small>
                    </div>
                    <div class="form-group col-sm-12 col-md-4">
                        <label for="campaign-negative"><i class="fas fa-ban campaign-field-icon" aria-hidden="true"></i>Negative:</label>
                        <select id="campaign-negative" name="send_setup[negative_id]" class="form-control selectpicker" data-live-search="true" data-allow-clear="true" title="Select Negative">
                            <option value="">Select Negative</option>
                            @foreach ($sendOptions['negatives'] as $negative)
                                <option value="{{ $negative['id'] }}" @selected($setup['negative_id'] === (string) $negative['id'])>{{ $negative['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-sm-12 col-md-3">
                        <label for="send-from-assets"><i class="fas fa-user campaign-field-icon" aria-hidden="true"></i>From values (optional):
                            <button type="button" class="btn btn-link btn-sm p-0" data-copy-selected="send-from-assets" title="Copy selected From values" aria-label="Copy selected From values"><i class="fas fa-copy" aria-hidden="true"></i></button>
                        </label>
                        <select id="send-from-assets" name="send_setup[from_asset_ids][]" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 2" title="Select optional From values" multiple>
                            @foreach (($sendOptions['offers']->firstWhere('id', (int) $setup['offer_id'])['assets'] ?? []) as $asset)
                                @if ($asset['type'] === 'from')
                                    <option value="{{ $asset['id'] }}" @selected(in_array((string) $asset['id'], $setup['from_asset_ids'], true))>{{ $asset['value'] }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-sm-12 col-md-3">
                        <label for="send-subject-assets"><i class="fas fa-file-alt campaign-field-icon" aria-hidden="true"></i>Subjects (optional):
                            <button type="button" class="btn btn-link btn-sm p-0" data-copy-selected="send-subject-assets" title="Copy selected subjects" aria-label="Copy selected subjects"><i class="fas fa-copy" aria-hidden="true"></i></button>
                        </label>
                        <select id="send-subject-assets" name="send_setup[subject_asset_ids][]" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 2" title="Select optional subjects" multiple>
                            @foreach (($sendOptions['offers']->firstWhere('id', (int) $setup['offer_id'])['assets'] ?? []) as $asset)
                                @if ($asset['type'] === 'subject')
                                    <option value="{{ $asset['id'] }}" @selected(in_array((string) $asset['id'], $setup['subject_asset_ids'], true))>{{ $asset['value'] }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-sm-12 col-md-3">
                        <label for="send-creative-asset"><i class="fas fa-image campaign-field-icon" aria-hidden="true"></i>Creatives:</label>
                        <select id="send-creative-asset" name="send_setup[creative_asset_id]" class="form-control selectpicker" data-live-search="true" title="Select Creative">
                            <option value="">Select Creative</option>
                            @foreach ($selectedOfferAssets as $asset)
                                @if ($asset['type'] === 'creative')
                                    <option value="{{ $asset['id'] }}" data-asset-value="{{ $asset['value'] }}" data-public-url="{{ $asset['public_url'] ?? '' }}" data-unsubscribe-value="{{ collect($selectedOfferAssets)->firstWhere('type', 'unsubscribe')['value'] ?? '' }}" @selected($setup['creative_asset_id'] === (string) $asset['id'])>{{ $asset['value'] }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-sm-12 col-md-3">
                        <label for="send-html-asset"><i class="fas fa-code campaign-field-icon" aria-hidden="true"></i>HTML:</label>
                        <select id="send-html-asset" name="send_setup[html_asset_id]" class="form-control selectpicker" data-live-search="true" title="Select HTML">
                            <option value="">Select HTML</option>
                            @foreach ($selectedOfferAssets as $asset)
                                @if ($asset['type'] === 'html')
                                    <option value="{{ $asset['id'] }}" data-asset-value="{{ $asset['value'] }}" @selected($setup['html_asset_id'] === (string) $asset['id'])>{{ \Illuminate\Support\Str::limit($asset['value'], 50) }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <small class="sr-only form-text text-muted col-12">Optional rotation values. Leave these blank to use the literal From and Subject values in the Header.</small>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="campaign-header"><i class="fas fa-heading campaign-field-icon" aria-hidden="true"></i>Header:
                            <button type="button" class="btn btn-link btn-sm p-0" id="campaign-header-shuffle" title="Shuffle header lines" aria-label="Shuffle header lines"><i class="fas fa-random" aria-hidden="true"></i></button>
                            <button type="button" class="btn btn-link btn-sm p-0" id="campaign-header-encode" title="Encoding tools" aria-label="Encoding tools"><i class="fas fa-pencil-ruler" aria-hidden="true"></i></button>
                            <button type="button" class="btn btn-link btn-sm p-0" id="campaign-header-use-smtp" title="Put the selected Workspace SMTP account in the From address" aria-label="Use selected SMTP account as From"><i class="fas fa-plug" aria-hidden="true"></i></button>
                        </label>
                        <textarea id="campaign-header" name="header" rows="30" class="form-control campaign-header-editor" spellcheck="false" required>{{ old('header', $savedContent['header'] ?? $defaultCampaignHeader) }}</textarea>
                        <small class="sr-only form-text text-muted">This inserts <code>From: [smtp]@[domain]</code> and switches routing to SMTP. Select the Workspace account under Available SMTP routes; full email usernames and the supported form <code>info@[smtp]</code> are supported.</small>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="campaign-body"><i class="fas fa-code campaign-field-icon" aria-hidden="true"></i>Body:
                            <button type="button" id="campaign-body-preview-button" class="btn btn-link btn-sm p-0" title="Preview body" aria-controls="campaign-body-preview" aria-expanded="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
                            @if ($editingCampaign)
                                <button type="button" id="campaign-body-generate-links" class="btn btn-link btn-sm p-0" data-generate-url="{{ route('console.campaigns.generate-body', [$workspace->id, $editingCampaign->id]) }}" title="Generate tracking links" aria-label="Generate tracking links"><i class="fas fa-link" aria-hidden="true"></i></button>
                            @endif
                            <button type="button" id="campaign-shortlink-open" class="btn btn-link btn-sm p-0" title="Shortlink tools" aria-label="Shortlink tools"><i class="fas fa-unlink" aria-hidden="true"></i></button>
                        </label>
                        <div class="custom-control custom-switch custom-control-inline ml-2">
                            <input id="campaign-body-map" type="checkbox" class="custom-control-input">
                            <label for="campaign-body-map" class="custom-control-label"><i class="fas fa-map-marked-alt campaign-label-icon" aria-hidden="true"></i> MAP BODY</label>
                            <button type="button" id="campaign-body-insert-open-pixel" class="btn btn-link btn-sm p-0 ml-1" title="Insert open tracking pixel" aria-label="Insert open tracking pixel"><i class="fas fa-bullseye" aria-hidden="true"></i></button>
                        </div>
                        <textarea id="campaign-body" name="body" rows="28" class="form-control" spellcheck="false">{{ old('body', $savedContent['body'] ?? '') }}</textarea>
                        <dialog id="campaign-body-preview" class="campaign-body-preview-dialog" data-campaign-dialog-width="min(1100px, 96vw)" aria-labelledby="campaign-body-preview-title">
                            <div class="campaign-body-preview-header">
                                <h2 id="campaign-body-preview-title" class="h5 mb-0">HTML Preview</h2>
                                <button type="button" id="campaign-body-preview-close" class="btn btn-sm btn-outline-secondary" aria-label="Close HTML preview"><i class="fas fa-times" aria-hidden="true"></i></button>
                            </div>
                            <iframe id="campaign-body-preview-frame" title="Campaign body preview" sandbox=""></iframe>
                        </dialog>
                    </div>
                </div>
            </div>
        </div>
        <div class="block block-rounded campaign-delivery-card">
            <div class="block-header">
                <h3 class="block-title"><i class="fas fa-paper-plane" aria-hidden="true"></i> Placeholders &amp; Sending</h3>
                <span class="campaign-section-caption">Define message variations, routes, and delivery rules.</span>
            </div>
            <div class="block-content">
                <div class="form-row">
                    @include('console.campaigns.partials.placeholder-editor', [
                        'idPrefix' => 'campaign',
                        'rows' => old('send_setup.placeholder_inputs', collect($savedSetup['placeholders'] ?? [])->map(fn (array $p): array => ['data_text' => implode("\n", $p['data'] ?? []), 'rotate_every' => $p['rotate_every'] ?? 1])->all()),
                        'combination' => old('send_setup.placeholder_combination', $savedSetup['placeholder_combination'] ?? false),
                        'allCombination' => old('send_setup.placeholder_all_combination', $savedSetup['placeholder_all_combination'] ?? false),
                        'showHelp' => false,
                    ])
                </div>
                <div id="campaign-routing">
                    <div class="row">
                        <div class="col-sm-12 col-md-8">
                            <div class="form-row">
                                <div class="form-group col-sm-6 col-md-2">
                                    <label for="send-route"><i class="fas fa-sitemap campaign-field-icon" aria-hidden="true"></i>Option:</label>
                                    <select id="send-route" name="send_setup[route_option]" class="form-control" required>
                                        <option value="ip" @selected($setup['route_option'] === 'ip')>IP</option>
                                        <option value="vmta" @selected($setup['route_option'] === 'vmta')>VMTA</option>
                                        <option value="smtp" @selected($setup['route_option'] === 'smtp')>SMTP</option>
                                    </select>
                                </div>
                                <div class="form-group col-sm-6 col-md-2">
                                    <label for="send-ip-type"><i class="fas fa-shield-alt campaign-field-icon" aria-hidden="true"></i>Type IP:</label>
                                    <select id="send-ip-type" name="send_setup[ip_type]" class="form-control" required>
                                        <option value="all" @selected($setup['ip_type'] === 'all')>ALL</option>
                                        <option value="ipv4" @selected($setup['ip_type'] === 'ipv4')>IPv4</option>
                                        <option value="ipv6" @selected($setup['ip_type'] === 'ipv6')>IPv6</option>
                                    </select>
                                </div>
                                <div class="form-group col-sm-12 col-md-8">
                                    <label for="send-servers"><i class="fas fa-server campaign-field-icon" aria-hidden="true"></i>Servers:
                                        <button type="button" class="btn btn-link btn-sm p-0" id="campaign-servers-reload" title="Reload servers and IPs" aria-label="Reload servers and IPs"><i class="fas fa-sync-alt" aria-hidden="true"></i></button>
                                        <button type="button" class="btn btn-link btn-sm p-0" id="campaign-servers-search" title="Search servers" aria-label="Search servers"><i class="fas fa-search" aria-hidden="true"></i></button>
                                    </label>
                                    <select id="send-servers" name="send_setup[server_ids][]" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 3" title="Select Servers" multiple required>
                                        @foreach ($sendOptions['servers'] as $server)
                                            <option value="{{ $server['id'] }}" data-panel-url="{{ $server['pmta_panel_url'] }}" data-monitor-url="{{ $server['pmta_panel_display_url'] }}" @selected(in_array((string) $server['id'], $setup['server_ids'], true))>{{ $server['name'] }} | {{ $server['ip'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="send-ips-selected"><i class="fas fa-link campaign-field-icon" aria-hidden="true"></i><span id="send-route-choice-heading">{{ match ($setup['route_option']) { 'smtp' => 'SMTP routes', 'vmta' => 'VMTAs', default => 'IPs' } }}</span>:
                                    <button type="button" class="btn btn-link btn-sm p-0" data-copy-ip-list="available" title="Copy available IPs" aria-label="Copy available IPs"><i class="fas fa-copy" aria-hidden="true"></i></button>
                                    <button type="button" class="btn btn-link btn-sm p-0" data-copy-ip-list="selected" title="Copy selected IPs" aria-label="Copy selected IPs"><i class="fas fa-copy" aria-hidden="true"></i></button>
                                </label>
                                <select id="send-ips" name="send_setup[ip_ids][]" class="sr-only" aria-hidden="true" tabindex="-1" multiple>
                                    @foreach ($sendOptions['ips'] as $ip)
                                        @php
                                            $routeChoiceLabel = match ($setup['route_option']) {
                                                'vmta' => implode(' | ', array_filter([$ip['ip'], $ip['dns'] ?? '', $ip['name'] ?? ''], static fn ($value) => filled($value))),
                                                'smtp' => implode(' | ', array_filter([$ip['ip'], $ip['username'] ?? '', $ip['name'] ?? '', $ip['dns'] ?? ''], static fn ($value) => filled($value))),
                                                default => implode(' | ', array_filter([$ip['ip'], $ip['dns'] ?? '', $ip['username'] ?? null], static fn ($value) => filled($value))),
                                            };
                                        @endphp
                                        <option value="{{ $ip['id'] }}" data-route="{{ $setup['route_option'] }}" data-route-choice-id="{{ $ip['id'] }}" data-server-id="{{ $ip['server_id'] }}" data-managed-server-ip-id="{{ $ip['managed_server_ip_id'] ?? '' }}" data-pmta-name="{{ $ip['pmta_name'] ?? '' }}" data-username="{{ $ip['username'] ?? '' }}" data-name="{{ $ip['name'] ?? '' }}" data-ip="{{ $ip['ip'] }}" data-dns="{{ $ip['dns'] ?? '' }}" data-vmta-domain="{{ $ip['vmta_domain'] ?? '' }}" @selected(in_array((string) $ip['id'], $setup['ip_ids'], true))>{{ $routeChoiceLabel }}</option>
                                    @endforeach
                                </select>
                                <div class="row">
                                    <div class="col-md-5">
                                        <label class="small mb-1" for="send-ips-available"><i class="fas fa-list campaign-field-icon" aria-hidden="true"></i><span id="send-ips-available-label">Available {{ match ($setup['route_option']) { 'smtp' => 'SMTP routes', 'vmta' => 'VMTAs', default => 'IPs' } }}</span></label>
                                        <input class="form-control form-control-sm mb-1" id="send-ips-available-filter" type="search" placeholder="Filter available IPs" aria-label="Filter available IPs">
                                        <select id="send-ips-available" class="form-control" multiple size="10" aria-label="Available {{ match ($setup['route_option']) { 'smtp' => 'SMTP routes', 'vmta' => 'VMTAs', default => 'IPs' } }}" aria-describedby="send-ips-transfer-help"></select>
                                    </div>
                                    <div class="col-md-2 d-flex flex-column justify-content-center align-items-center">
                                        <button type="button" class="btn btn-outline-secondary btn-sm mb-1" data-ip-move="all-right" aria-label="Move all IPs to selected">»</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm mb-1" data-ip-move="right" aria-label="Move selected IPs to selected">›</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm mb-1" data-ip-move="left" aria-label="Move IPs back to available">‹</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-ip-move="all-left" aria-label="Move all IPs back to available">«</button>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="small mb-1" for="send-ips-selected"><i class="fas fa-check-circle campaign-field-icon" aria-hidden="true"></i><span id="send-ips-selected-label">Selected {{ match ($setup['route_option']) { 'smtp' => 'SMTP routes', 'vmta' => 'VMTAs', default => 'IPs' } }}</span> (<span id="send-ips-selected-count">0</span>)</label>
                                        <input class="form-control form-control-sm mb-1" id="send-ips-selected-filter" type="search" placeholder="Filter selected IPs" aria-label="Filter selected IPs">
                                        <select id="send-ips-selected" class="form-control" multiple size="10" aria-label="Selected {{ match ($setup['route_option']) { 'smtp' => 'SMTP routes', 'vmta' => 'VMTAs', default => 'IPs' } }}" aria-describedby="send-ips-transfer-help"></select>
                                    </div>
                                </div>
                                <small id="send-ips-transfer-help" class="sr-only form-text text-muted">Double-click an entry to move it between lists. You can also select entries and use the arrow buttons.</small>
                                <small class="sr-only form-text text-muted">Campaign Save requires at least one selected IP, VMTA, or SMTP route. Test Campaign uses the saved route on this revision.</small>
                                <details class="campaign-create-help campaign-route-help">
                                    <summary title="Route behavior"><i class="fas fa-info-circle campaign-field-icon" aria-hidden="true"></i><span class="sr-only">Route behavior</span></summary>
                                    <small class="form-text text-info" id="send-route-behavior">Option IP sends through the selected IP route. <code>[smtp]</code> and <code>[smtp_name]</code> fill message text from one linked account; they do not choose the outbound route. To send through an SMTP relay, choose Option SMTP and select that account. AlphaLink connects to the selected server’s PMTA listener; that server IP is the PMTA ingress, while the selected route controls outbound delivery. Start a saved campaign from My Campaigns after recipient preparation finishes.</small>
                                </details>
                            </div>
                            <div id="campaign-pmta-panels" class="row" aria-live="polite">
                                @foreach ($sendOptions['servers'] as $server)
                                    @if (in_array((string) $server['id'], $setup['server_ids'], true))
                                        @php
                                            $pmtaDisplayUrl = $server['pmta_panel_display_url'] ?? $server['pmta_panel_url'];
                                            $pmtaUsesReadOnlyProxy = $pmtaDisplayUrl !== $server['pmta_panel_url'];
                                        @endphp
                                        <div class="col-sm-6 col-lg-4"><a href="{{ $pmtaDisplayUrl }}" target="_blank" rel="noopener noreferrer">Open PMTA monitor ({{ $pmtaUsesReadOnlyProxy ? 'HTTPS read-only proxy' : (str_starts_with($server['pmta_panel_url'], 'https://') ? 'HTTPS' : 'HTTP/IP') }}) — {{ $server['name'] }}:{{ $sendOptions['settings']['pmta_admin_port'] }}</a></div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                        <div class="col-sm-12 col-md-4">
                            <div class="row">
                                @if ($editingCampaign)
                                    @php
                                        $sendCount = \App\Models\CampaignRecipientSnapshot::query()->where('campaign_id', $editingCampaign->id)->max('recipient_count') ?? 0;
                                    @endphp
                                    <div class="form-group col-12">
                                        <label for="campaign-start-progress"><i class="fas fa-play campaign-field-icon" aria-hidden="true"></i>Start From: <span class="text-info">(TOTAL: {{ number_format($sendCount) }})</span></label>
                                        <div class="input-group">
                                            <input id="campaign-start-progress" class="form-control" type="number" min="0" max="{{ $sendCount }}" value="{{ old('send_progress', $editingCampaign->send_progress ?? ($savedSetup['start_from'] ?? 0)) }}">
                                            <div class="input-group-append"><button type="button" class="btn btn-info" id="campaign-start-progress-change" data-progress-url="{{ route('console.campaigns.progress', [$workspace->id, $editingCampaign->id]) }}">CHANGE</button></div>
                                        </div>
                                        <small id="campaign-start-progress-status" class="form-text text-muted" role="status" aria-live="polite"></small>
                                    </div>
                                @endif
                                <div class="form-group col-6">
                                    <label for="send-type"><i class="fas fa-paper-plane campaign-field-icon" aria-hidden="true"></i>Send Type:</label>
                                    <select id="send-type" name="send_setup[send_type]" class="form-control" required>
                                        @foreach (['delay' => 'DELAY', 'delayp' => 'DELAYP (parallel)', 'delayps' => 'DELAYPS (parallel)', 'hs' => 'HS', 'hsp' => 'HSP (parallel)', 'hsps' => 'HSPS (parallel by server)', 'pickup' => 'PICKUP (parallel)', 'pickups' => 'PICKUPS (parallel by server)'] as $value => $label)
                                            <option value="{{ $value }}" @selected($setup['send_type'] === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @foreach ([
                                    'duplicate_data' => ['Duplicate Data', 1],
                                    'total_send' => ['Total Send', 1],
                                    'fraction_ip' => ['Fraction IP', 1],
                                    'sleep_email' => ['Sleep Between Emails (ms)', 0],
                                    'sleep_ip' => ['Sleep Between IPs (ms)', 0],
                                    'seed_every' => ['Test After', 0],
                                    'bounce_limit' => ['Bounce Limit', 0],
                                ] as $field => [$label, $minimum])
                                    <div class="form-group col-6">
                                        <label for="send-{{ $field }}"><i class="fas {{ $campaignParameterIcons[$field] ?? 'fa-sliders-h' }} campaign-field-icon" aria-hidden="true"></i>{{ $label }}:</label>
                                        <input id="send-{{ $field }}" name="send_setup[{{ $field }}]" type="number" min="{{ $minimum }}" class="form-control" value="{{ $setup[$field] }}" required>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        </div>
        </div>
        <aside class="campaign-create-summary" id="campaign-summary"
            data-initial-audiences="{{ count($setup['audience_segments']) }}"
            data-initial-routes="{{ count($setup['ip_ids']) }}"
            data-initial-offer="{{ $setup['offer_id'] !== '' ? 'true' : 'false' }}"
            data-initial-send-total="{{ $setup['total_send'] }}">
            <section class="campaign-summary-card" aria-labelledby="campaign-summary-title">
                <div class="campaign-summary-heading">
                    <span class="campaign-summary-icon" aria-hidden="true"><i class="fas fa-chart-bar"></i></span>
                    <div><h2 id="campaign-summary-title">Campaign Summary</h2><p>Review your setup before creating.</p></div>
                </div>
                <ul class="campaign-summary-checklist">
                    <li><span><i class="fas fa-tag" aria-hidden="true"></i> Offer</span><strong data-campaign-summary="offer-status">Not selected</strong></li>
                    <li><span><i class="fas fa-list-ul" aria-hidden="true"></i> Audience</span><strong data-campaign-summary="audience-status">Not selected</strong></li>
                    <li><span><i class="fas fa-envelope" aria-hidden="true"></i> Email config</span><strong data-campaign-summary="email-status">Incomplete</strong></li>
                    <li><span><i class="fas fa-layer-group" aria-hidden="true"></i> Placeholders</span><strong class="is-optional">Optional</strong></li>
                    <li><span><i class="fas fa-share-alt" aria-hidden="true"></i> Sending</span><strong data-campaign-summary="route-status">Not selected</strong></li>
                </ul>
            </section>
            <section class="campaign-summary-card" aria-labelledby="campaign-quick-stats-title">
                <h2 id="campaign-quick-stats-title">Quick Stats</h2>
                <div class="campaign-summary-stats">
                    <div><i class="fas fa-paper-plane" aria-hidden="true"></i><strong data-campaign-summary="send-total">{{ $setup['total_send'] }}</strong><span>Total send</span></div>
                    <div><i class="fas fa-users" aria-hidden="true"></i><strong data-campaign-summary="audiences">{{ count($setup['audience_segments']) }}</strong><span>Audiences</span></div>
                    <div><i class="fas fa-server" aria-hidden="true"></i><strong data-campaign-summary="routes">{{ count($setup['ip_ids']) }}</strong><span>Routes</span></div>
                </div>
            </section>
            <section class="campaign-summary-card campaign-readiness-card" aria-labelledby="campaign-readiness-title">
                <div class="campaign-readiness-heading"><h2 id="campaign-readiness-title">Campaign Readiness</h2><strong data-campaign-summary="readiness-label">0%</strong></div>
                <div class="campaign-readiness-track" role="progressbar" aria-label="Campaign setup readiness" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                    <span data-campaign-summary="readiness-bar"></span>
                </div>
                <small data-campaign-summary="readiness-detail">0 of 4 setup checks complete</small>
            </section>
            <section class="campaign-summary-actions" aria-label="Campaign actions">
                <p>{{ $editingCampaign ? 'Saving creates a new campaign revision and preserves its audience snapshot.' : 'Creating saves the campaign and prepares its recipient snapshot. It does not send.' }}</p>
                <button class="btn btn-primary campaign-create-submit" id="campaign-save-button" type="submit">
                    <i class="fas fa-paper-plane" aria-hidden="true"></i> {{ $editingCampaign ? 'Save Campaign Changes' : 'Create Campaign' }}
                </button>
                <a class="btn btn-outline-secondary campaign-cancel-link" href="{{ route('console.campaigns.index', $workspace->id) }}">Cancel</a>
                <a class="btn btn-primary ml-2" id="campaign-new-campaign" href="{{ route('console.campaigns.create', $workspace->id) }}" hidden>Start a new campaign</a>
            </section>
        </aside>
        </div>

        <dialog id="campaign-encoding-dialog" data-encode-url="{{ route('console.campaign-tools.encode', $workspace->id) }}" aria-labelledby="campaign-encoding-title">
            <div class="p-3" style="min-width:min(720px,90vw)">
                <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h5 mb-0" id="campaign-encoding-title">Encoding</h3><button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close="campaign-encoding-dialog" aria-label="Close encoding tools">×</button></div>
                <ul class="nav nav-tabs" role="tablist"><li class="nav-item"><button type="button" class="nav-link active" data-encoding-tab="string">STRING</button></li><li class="nav-item"><button type="button" class="nav-link" data-encoding-tab="ip">IP</button></li></ul>
                <div class="pt-3" data-encoding-panel="string">
                    <div class="form-row"><div class="form-group col-md-6"><label for="campaign-encoding-text"><i class="fas fa-font campaign-field-icon" aria-hidden="true"></i>Your String</label><input class="form-control" id="campaign-encoding-text"></div><div class="form-group col-md-3"><label for="campaign-encoding-schema"><i class="fas fa-code campaign-field-icon" aria-hidden="true"></i>Schema</label><select class="form-control" id="campaign-encoding-schema"><option value="Q">Quoted-Printable</option><option value="HQ">Hex-Quoted-Printable</option><option value="B">Base64</option></select></div><div class="form-group col-md-3"><label for="campaign-encoding-charset"><i class="fas fa-language campaign-field-icon" aria-hidden="true"></i>Charset</label><select class="form-control" id="campaign-encoding-charset"><option>UTF-8</option><option>UTF-7</option><option>ISO-8859-1</option><option>ISO-8859-15</option><option>windows-1252</option><option>US-ASCII</option><option>CP1256</option></select></div></div>
                    <button type="button" class="btn btn-primary mb-2" id="campaign-encoding-run">ENCODE</button><label for="campaign-encoding-result"><i class="fas fa-file-alt campaign-field-icon" aria-hidden="true"></i>Result</label><textarea class="form-control" id="campaign-encoding-result" rows="4" readonly></textarea>
                </div>
                <div class="pt-3" data-encoding-panel="ip" hidden><div class="form-group"><label for="campaign-encoding-ip"><i class="fas fa-network-wired campaign-field-icon" aria-hidden="true"></i>IP</label><input class="form-control" id="campaign-encoding-ip" placeholder="192.0.2.1"></div><button type="button" class="btn btn-primary mb-2" id="campaign-encoding-ip-run">ENCODE</button><label for="campaign-encoding-ip-result"><i class="fas fa-file-alt campaign-field-icon" aria-hidden="true"></i>Result</label><textarea class="form-control" id="campaign-encoding-ip-result" rows="7" readonly></textarea></div>
            </div>
        </dialog>
        <dialog id="campaign-shortlink-dialog" data-shortlink-url-template="{{ route('console.campaign-tools.shortlinks', [$workspace->id, '__PROVIDER__']) }}" aria-labelledby="campaign-shortlink-title">
            <div class="p-3" style="min-width:min(760px,90vw)">
                <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h5 mb-0" id="campaign-shortlink-title">SHORTLINKS</h3><button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close="campaign-shortlink-dialog" aria-label="Close short-link tools">×</button></div>
                <ul class="nav nav-tabs"><li class="nav-item"><button type="button" class="nav-link active" data-shortlink-provider="tinyurl">TINYURL</button></li><li class="nav-item"><button type="button" class="nav-link" data-shortlink-provider="isgd">ISGD</button></li></ul>
                <label class="mt-3" for="campaign-shortlink-input"><i class="fas fa-link campaign-field-icon" aria-hidden="true"></i>LINKS:</label><textarea class="form-control" id="campaign-shortlink-input" rows="5" placeholder="One http or https link per line"></textarea>
                <button type="button" class="btn btn-primary btn-block mt-2" id="campaign-shortlink-generate">GENERATE LINKS</button>
                <div id="campaign-shortlink-status" class="small mt-2" role="status" aria-live="polite"></div>
                <div class="table-responsive mt-2"><table class="table table-striped table-sm"><thead><tr><th>LINK</th><th>SHORTLINK</th></tr></thead><tbody id="campaign-shortlink-results"></tbody></table></div>
            </div>
        </dialog>
        <dialog id="campaign-tags-dialog" data-campaign-dialog-width="1140px" style="box-sizing:border-box;border:0;border-radius:.35rem;padding:0;max-width:92vw;max-height:90vh;overflow:auto;overflow-x:hidden;width:min(1140px,92vw)" aria-labelledby="campaign-tags-title">
            <div class="p-3" style="box-sizing:border-box;width:100%">
                <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h5 mb-0" id="campaign-tags-title">TAGS</h3><button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close="campaign-tags-dialog" aria-label="Close tag examples">×</button></div>
                <div class="row"><div class="col-md-6">
                    <h4 class="h6">Message and server tags</h4>
                    <p><code>[ip]</code> IP · <code>[server]</code> Server name · <code>[main_domain]</code> Server domain · <code>[domain]</code> IP domain with TLD · <code>[domain_sld]</code> IP domain without the final TLD · <code>[rdns]</code> IP reverse DNS</p>
                    <p><code>[from]</code> Random From · <code>[subject]</code> Random Subject · <code>[ids]</code> Server ID · <code>[idd]</code> Domain ID · <code>[idi]</code> IP ID · <code>[ide]</code> Email ID · <code>[to]</code> Destination email · <code>[negative]</code> Negative file · <code>[name]</code> Email username · <code>[date]</code> Current date</p>
                    <p><code>[rnd]</code> 18 random characters · <code>[text:a|b|c]</code> Choose one value · <code>[base64:text]</code> Base64 encode · <code>[printable:text]</code> Quoted-printable encode</p>
                    <p><code>[boundary]</code> 30 static characters · <code>[bnd]</code> 30 static characters · <code>[smtp]</code> SMTP username · <code>[smtp_name]</code> SMTP name · <code>[tag]</code> Tag reply extractor · <code>#[token]</code> Track redirect/unsubscribe short links</p>
                    <h4 class="h6">Random tags</h4>
                    <p><code>[rnd]</code> generates 18 random characters. Replace <code>N</code> in the other tags with the desired length:</p>
                    <ul class="mb-3">
                        <li><code>[rndn_N]</code> digits (0–9)</li>
                        <li><code>[rnda_N]</code> letters and digits</li>
                        <li><code>[rndl_N]</code> lowercase letters · <code>[rndu_N]</code> uppercase letters</li>
                        <li><code>[rnds_N]</code> symbols · <code>[rndlu_N]</code> lowercase and uppercase letters</li>
                        <li><code>[rndln_N]</code> lowercase letters and digits · <code>[rndun_N]</code> uppercase letters and digits</li>
                    </ul>
                    <h4 class="h6">Language random tags</h4>
                    <ul class="mb-0">
                        <li><code>[Ingreek_N]</code> Greek · <code>[Ingemojicons_N]</code> / <code>[Ingemoticons_N]</code> emoticons</li>
                        <li><code>[Ingdevanagari_N]</code> Devanagari · <code>[Ingthai_N]</code> Thai · <code>[Ingarabic_N]</code> Arabic</li>
                        <li><code>[Inglatin_N]</code> Latin A–Z/a–z · <code>[Ingchinese_N]</code> CJK ideographs</li>
                        <li><code>[Inghiragana_N]</code> Hiragana · <code>[Ingcyrrilic_N]</code> Cyrillic</li>
                        <li><code>[Ingarmenian_N]</code> Armenian · <code>[Inggeorgian_N]</code> Georgian</li>
                    </ul>
                </div><div class="col-md-6">
                    <h4 class="h6">Boundary tags</h4>
                    <p>Replace <code>N</code> with the desired fixed length:</p>
                    <ul class="mb-3">
                        <li><code>[bndn_N]</code> digits (0–9)</li>
                        <li><code>[bnda_N]</code> letters and digits</li>
                        <li><code>[bndl_N]</code> lowercase letters · <code>[bndu_N]</code> uppercase letters</li>
                        <li><code>[bnds_N]</code> symbols · <code>[bndlu_N]</code> lowercase and uppercase letters</li>
                        <li><code>[bndln_N]</code> lowercase letters and digits · <code>[bndun_N]</code> uppercase letters and digits</li>
                    </ul>
                    <h4 class="h6">Placeholders</h4>
                    <p><code>[placeholder1]</code><br><code>[placeholder2]</code><br>Continue with the row number. Values follow the corresponding Placeholders row and its rotation interval.</p>
                    <h4 class="h6">Campaign Test behavior</h4>
                    <p>Campaign templates accept <code>[boundary]</code> and <code>[bnd]</code>. <code>[boundary]</code> comes from message context; <code>[bnd]</code> defaults to a cached 18-character value.</p>
                    <p>Campaign Test resolves <code>[smtp]</code> and <code>[smtp_name]</code> from the selected SMTP route, or from the single active SMTP account linked to a selected IP. In SMTP mode, it sends one test message through every selected SMTP route and reuses the saved Email Test addresses as needed; this lets you verify each selected account. Use <code>[smtp]</code> by itself when the account username is already an email address; <code>[smtp]@[domain]</code> also works and adds the selected domain only when the account username is a local part. These tags only fill message text: Option IP still routes through the selected IP, and Option SMTP routes through the selected SMTP account. If an IP has multiple linked accounts, select one with Option SMTP before using its tags. The tags are empty for VMTA routes and IPs without a linked account. <code>[tag]</code> is empty in Campaign Test because the reply-tag value is unavailable in that flow.</p>
                    <p>Campaign Test replaces <code>[token]</code> with a test token. Save the campaign before testing click, open, or unsubscribe links: an unsaved campaign has no ID for those URLs. Test opens and clicks do not create recipient analytics. Campaign Test does not require sender-domain DNS verification.</p>
                    <p class="mb-0">The editor keeps message HTML as written. Campaign Test and Send resolve <code>[domain]</code> at send time; selected VMTAs supply their own pushed domain, while IP/server tests without a configured DNS name use the selected Domain. Other URL hosts in the HTML stay unchanged.</p>
                </div></div>
            </div>
        </dialog>
        <dialog id="campaign-server-search-dialog" aria-labelledby="campaign-server-search-title">
            <div class="p-3" style="min-width:min(520px,90vw)">
                <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h5 mb-0" id="campaign-server-search-title">Search Servers</h3><button type="button" class="btn btn-sm btn-outline-secondary" data-dialog-close="campaign-server-search-dialog" aria-label="Close server search">×</button></div>
                <label for="campaign-server-search-input"><i class="fas fa-server campaign-field-icon" aria-hidden="true"></i>Server names or IP addresses, one per line</label>
                <textarea class="form-control" id="campaign-server-search-input" rows="7" placeholder="Server name or IP address"></textarea>
                <div class="d-flex justify-content-end mt-3"><button type="button" class="btn btn-alt-secondary mr-2" data-dialog-close="campaign-server-search-dialog">Cancel</button><button type="button" class="btn btn-primary" id="campaign-server-search-apply">Select Servers</button></div>
            </div>
        </dialog>
        </div>
    </form>

    <div id="campaign-options-status" class="alert d-none" role="status" aria-live="polite"></div>
    <button type="button" id="campaign-options-retry" class="btn btn-alt-secondary d-none mb-4">Retry loading choices</button>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('alphalink/campaign-header-library.js') }}" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const container = document.getElementById('campaign-saved-emails');
            const saved = document.getElementById('saved-email-addresses');
            const manager = document.getElementById('saved-email-manager');
            const status = document.getElementById('saved-email-status');
            const address = document.getElementById('saved-email-new');
            const tests = document.getElementById('campaign-email-tests');
            const csrf = document.querySelector('#campaign-create-form input[name="_token"]')?.value;
            const refresh = () => { if (window.jQuery?.fn.selectpicker) window.jQuery(saved).selectpicker('refresh'); };
            saved.addEventListener('change', () => { tests.value = Array.from(saved.selectedOptions).map(option => option.value).join('\n'); });
            document.getElementById('saved-email-manage').addEventListener('click', () => { manager.hidden = !manager.hidden; if (!manager.hidden) address.focus(); });
            document.getElementById('saved-email-add').addEventListener('click', async () => {
                if (!address.value.trim()) { status.textContent = 'Enter an email address.'; return; }
                const response = await fetch(container.dataset.storeUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({email: address.value.trim()}) });
                if (!response.ok) { status.textContent = 'Could not save the address. Check its format and try again.'; return; }
                const result = await response.json();
                const option = new Option(result.data.email, result.data.email);
                option.dataset.addressId = result.data.id;
                saved.add(option); refresh(); address.value = ''; status.textContent = 'Address saved.';
            });
            document.getElementById('saved-email-remove').addEventListener('click', async () => {
                const selected = Array.from(saved.selectedOptions);
                if (!selected.length) { status.textContent = 'Select a saved address to delete.'; return; }
                for (const option of selected) {
                    const url = container.dataset.deleteUrlTemplate.replace('__ADDRESS_ID__', option.dataset.addressId);
                    const response = await fetch(url, { method: 'DELETE', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf } });
                    if (!response.ok) { status.textContent = 'Could not delete the selected address.'; return; }
                    option.remove();
                }
                refresh(); tests.value = Array.from(saved.selectedOptions).map(option => option.value).join('\n'); status.textContent = 'Selected addresses deleted.';
            });
        });
    </script>
    <script>
        (() => {
            const form = document.getElementById('campaign-create-form');
            if (!form) return;
            const trigger = document.getElementById('campaign-actions-trigger');
            const floatingPanel = document.getElementById('campaign-actions-panel');
            const contextPanel = document.getElementById('campaign-context-menu');
            const backdrop = document.getElementById('campaign-action-backdrop');
            const actionStatus = document.getElementById('campaign-action-status');
            const servers = document.getElementById('send-servers');
            const floatingActions = document.getElementById('campaign-floating-actions');
            const contextArea = document.getElementById('campaign-data');
            let contextTarget = null;
            const closeMenus = () => {
                floatingPanel.hidden = true;
                contextPanel.hidden = true;
                backdrop.hidden = true;
                trigger.setAttribute('aria-expanded', 'false');
                trigger.setAttribute('aria-label', 'Open campaign actions');
                trigger.querySelector('i')?.classList.replace('fa-times', 'fa-bars');
            };
            const contextFocusTarget = (target) => {
                const focusableTarget = target instanceof Element
                    ? target.closest('a[href], button:not([disabled]):not([aria-disabled="true"]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [contenteditable="true"], [tabindex]:not([tabindex="-1"])')
                    : null;

                return focusableTarget ?? contextArea;
            };
            const openFloatingMenu = () => {
                actionStatus.hidden = true;
                contextPanel.hidden = true;
                floatingPanel.hidden = false;
                backdrop.hidden = false;
                trigger.setAttribute('aria-expanded', 'true');
                trigger.setAttribute('aria-label', 'Close campaign actions');
                trigger.querySelector('i')?.classList.replace('fa-bars', 'fa-times');
                const viewportHeight = document.documentElement.clientHeight || window.innerHeight;
                const triggerBottom = trigger.getBoundingClientRect().bottom;
                const panelHeight = floatingPanel.scrollHeight;
                floatingPanel.style.top = `${Math.max(4, Math.min(triggerBottom + 6, viewportHeight - panelHeight - 4))}px`;
                floatingPanel.querySelector('[role="menuitem"]')?.focus();
            };
            const openContextMenu = (event) => {
                closeMenus();
                actionStatus.hidden = true;
                contextTarget = contextFocusTarget(event.target);
                contextPanel.hidden = false;
                const bounds = contextPanel.getBoundingClientRect();
                const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
                const viewportHeight = document.documentElement.clientHeight || window.innerHeight;
                contextPanel.style.left = `${Math.max(4, Math.min(event.clientX, viewportWidth - bounds.width - 4))}px`;
                contextPanel.style.top = `${Math.max(4, Math.min(event.clientY, viewportHeight - bounds.height - 4))}px`;
                contextPanel.querySelector('[role="menuitem"]')?.focus();
            };
            const announce = (message, tone = 'info') => {
                actionStatus.className = `alert alert-${tone} mb-0`;
                actionStatus.textContent = message;
                actionStatus.hidden = false;
            };
            const testLogLink = document.getElementById('campaign-test-log-link');
            const runCampaignTest = async (item) => {
                closeMenus();
                const testItems = Array.from(document.querySelectorAll('[data-campaign-menu-action="test"]'));
                const originalMarkup = item.innerHTML;
                testItems.forEach((button) => {
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                });
                item.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i><span class="sr-only">Testing campaign</span>';
                announce('Campaign Test is running. This page will stay open while PMTA responds.', 'info');
                actionStatus.scrollIntoView({ block: 'nearest' });
                if (testLogLink) testLogLink.hidden = true;

                try {
                    const formData = new FormData(form);
                    const response = await fetch(item.dataset.testUrl, {
                        method: 'POST',
                        body: formData,
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    const result = await response.json().catch(() => ({}));
                    if (result.next_idempotency_key) {
                        const idempotencyInput = form.querySelector('input[name="idempotency_key"]');
                        if (idempotencyInput) idempotencyInput.value = result.next_idempotency_key;
                    }
                    if (!response.ok) {
                        const validationError = Object.values(result.errors || {}).flat()[0];
                        const message = result.data || result.message || validationError || 'Campaign Test failed.';
                        announce(message, result.outcome === 'outcome_unknown' || result.outcome === 'partially_accepted' ? 'warning' : 'danger');
                        window.AlphaLinkToast?.show(message, 'danger');
                    } else {
                        const message = result.data || 'Campaign Test completed.';
                        const accepted = result.outcome === 'accepted_by_transport';
                        announce(accepted ? `${message} The attempt is saved in Campaign test logs.` : message, accepted ? 'success' : 'warning');
                        window.AlphaLinkToast?.show(message, accepted ? 'success' : 'warning');
                    }
                    if (testLogLink && result.attempt_id) testLogLink.hidden = false;
                } catch (error) {
                    const message = error instanceof Error ? error.message : 'AlphaLink could not complete the campaign test request.';
                    announce(message, 'danger');
                    window.AlphaLinkToast?.show(message, 'danger');
                } finally {
                    testItems.forEach((button) => {
                        button.disabled = false;
                        button.removeAttribute('aria-busy');
                    });
                    item.innerHTML = originalMarkup;
                }
            };
            const selectedServerOptions = () => Array.from(servers?.selectedOptions ?? []).filter((option) => option.value);
            const activateAction = (action) => {
                closeMenus();
                actionStatus.hidden = true;
                if (action === 'create' || action === 'edit') {
                    form.requestSubmit(document.getElementById('campaign-save-button'));
                    return;
                }
                if (action === 'create-send') {
                    announce('Save the campaign and let suppression and recipient preparation finish. Then use SEND from My Campaigns.', 'warning');
                    return;
                }
                const selected = selectedServerOptions();
                if (selected.length === 0) {
                    announce('Select at least one server first.', 'warning');
                    servers?.focus();
                    return;
                }
                if (action === 'pmta-admin') {
                    const query = new URLSearchParams();
                    selected.forEach((option) => query.append('server_ids[]', option.value));
                    const opened = window.open(`${floatingActions.dataset.pmtaAdminUrl}?${query.toString()}`, '_blank');
                    if (opened) opened.opener = null;
                    if (!opened) announce('The PMTA Admin page was blocked by the browser. Allow pop-ups for AlphaLink.', 'warning');
                    return;
                }
                if (action === 'pmta-panel') {
                    let opened = 0;
                    selected.forEach((option) => {
                        if (option.dataset.panelUrl) {
                            const panel = window.open(option.dataset.panelUrl, '_blank');
                            if (panel) {
                                panel.opener = null;
                                opened++;
                            }
                        }
                    });
                    if (opened === 0) announce('No PMTA panel URL is available for the selected servers.', 'warning');
                }
            };

            trigger.addEventListener('click', () => floatingPanel.hidden ? openFloatingMenu() : closeMenus());
            backdrop.addEventListener('click', closeMenus);
            contextArea.addEventListener('contextmenu', (event) => {
                event.preventDefault();
                openContextMenu(event);
            });
            document.addEventListener('click', (event) => {
                if (!contextPanel.hidden && !contextPanel.contains(event.target)) closeMenus();
            });
            document.addEventListener('keydown', (event) => {
                const activeMenu = floatingPanel.hidden ? (contextPanel.hidden ? null : contextPanel) : floatingPanel;
                if (event.key === 'Escape' && activeMenu) {
                    const returnFocusTarget = activeMenu === contextPanel ? contextTarget : trigger;
                    closeMenus();
                    returnFocusTarget?.focus({preventScroll: true});
                    return;
                }
                if (activeMenu && ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
                    const items = Array.from(activeMenu.querySelectorAll('[role="menuitem"]'));
                    const currentIndex = items.indexOf(document.activeElement);
                    const nextIndex = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (currentIndex + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
                    event.preventDefault();
                    items[nextIndex]?.focus();
                }
            });
            document.querySelectorAll('[data-campaign-menu-action]').forEach((item) => {
                item.addEventListener('click', (event) => {
                    if (item.dataset.campaignMenuAction === 'test') {
                        event.preventDefault();
                        event.stopPropagation();
                        void runCampaignTest(item);
                        return;
                    }
                    activateAction(item.dataset.campaignMenuAction);
                });
            });
            const previewButton = document.getElementById('campaign-body-preview-button');
            const preview = document.getElementById('campaign-body-preview');
            const header = document.getElementById('campaign-header');
            const body = document.getElementById('campaign-body');
            const globalStatus = document.getElementById('campaign-options-status');
            const csrf = form.querySelector('input[name="_token"]')?.value ?? '';
            const setToolStatus = (message, tone = 'info') => {
                if (!globalStatus) return;
                globalStatus.className = message ? `alert alert-${tone}` : 'alert d-none';
                globalStatus.textContent = message;
            };
            const insertTextAtSelection = (textarea, text, useSelection = true) => {
                const closingBody = textarea.value.toLowerCase().lastIndexOf('</body>');
                const start = useSelection
                    ? (textarea.selectionStart ?? textarea.value.length)
                    : (closingBody >= 0 ? closingBody : textarea.value.length);
                const end = useSelection ? (textarea.selectionEnd ?? start) : start;
                textarea.setRangeText(text, start, end, 'end');
                textarea.dispatchEvent(new Event('input', {bubbles: true}));
                textarea.focus({preventScroll: true});
            };
            let campaignBodyHasFocus = false;
            body.addEventListener('focus', () => { campaignBodyHasFocus = true; });
            const openPixelButton = document.getElementById('campaign-body-insert-open-pixel');
            openPixelButton?.addEventListener('mousedown', event => event.preventDefault());
            const insertOpenTrackingPixel = () => {
                insertTextAtSelection(body, '<img src="[domain]/[rndUN_20]o[token]" width="1" height="1" style="display:block;">', campaignBodyHasFocus);
                setToolStatus('Open tracking pixel inserted.', 'success');
            };
            openPixelButton?.addEventListener('click', insertOpenTrackingPixel);
            const closeDialog = (dialog) => {
                if (!dialog) return;
                const fallback = dialog.dataset.campaignDialogFallback === 'true';
                if (fallback) {
                    dialog.removeAttribute('open');
                    dialog.removeAttribute('aria-modal');
                    dialog.removeAttribute('style');
                    dialog.removeAttribute('data-campaign-dialog-fallback');
                    document.querySelectorAll('[data-campaign-dialog-backdrop]').forEach((backdrop) => {
                        if (backdrop.dataset.campaignDialogBackdrop === dialog.id) backdrop.remove();
                    });
                } else if (typeof dialog.close === 'function' && dialog.open) {
                    dialog.close();
                }
                const openerId = dialog.dataset.openerId;
                if (openerId) {
                    const opener = document.getElementById(openerId);
                    if (opener?.hasAttribute('aria-expanded')) opener.setAttribute('aria-expanded', 'false');
                    opener?.focus();
                }
            };
            const showDialog = (dialogId) => {
                const dialog = document.getElementById(dialogId);
                if (!dialog) return;
                dialog.dataset.openerId = document.activeElement?.id ?? '';
                if (typeof dialog.showModal === 'function') {
                    dialog.showModal();
                    return;
                }

                const backdrop = document.createElement('div');
                backdrop.dataset.campaignDialogBackdrop = dialogId;
                backdrop.setAttribute('aria-hidden', 'true');
                Object.assign(backdrop.style, {
                    background: 'rgb(52 58 64 / 45%)',
                    inset: '0',
                    position: 'fixed',
                    zIndex: '1050',
                });
                backdrop.addEventListener('click', () => closeDialog(dialog));
                document.body.append(backdrop);
                dialog.dataset.campaignDialogFallback = 'true';
                dialog.setAttribute('open', '');
                dialog.setAttribute('aria-modal', 'true');
                Object.assign(dialog.style, {
                    background: 'white',
                    border: '0',
                    borderRadius: '.25rem',
                    boxShadow: '0 8px 28px rgb(52 58 64 / 22%)',
                    display: 'block',
                    left: '50%',
                    margin: '0',
                    maxHeight: '90vh',
                    maxWidth: '90vw',
                    overflow: 'auto',
                    position: 'fixed',
                    top: '50%',
                    transform: 'translate(-50%, -50%)',
                    width: dialog.dataset.campaignDialogWidth || '760px',
                    zIndex: '1051',
                });
                dialog.querySelector('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])')?.focus();
            };
            document.addEventListener('keydown', (event) => {
                const dialog = Array.from(document.querySelectorAll('dialog[open]')).find((candidate) => candidate.dataset.campaignDialogFallback === 'true');
                if (!dialog) return;
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeDialog(dialog);
                    return;
                }
                if (event.key !== 'Tab') return;
                const focusable = Array.from(dialog.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [href], [tabindex]:not([tabindex="-1"])'))
                    .filter((element) => !element.hidden && element.getAttribute('aria-hidden') !== 'true');
                if (!focusable.length) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            });
            const copyText = async (text) => {
                if (!text) return false;
                try {
                    await navigator.clipboard.writeText(text);
                    return true;
                } catch {
                    const temporary = document.createElement('textarea');
                    temporary.value = text;
                    temporary.style.position = 'fixed';
                    temporary.style.opacity = '0';
                    document.body.append(temporary);
                    temporary.select();
                    const copied = document.execCommand('copy');
                    temporary.remove();
                    return copied;
                }
            };
            const previewFrame = document.getElementById('campaign-body-preview-frame');
            const openBodyPreview = () => {
                previewFrame.srcdoc = body.value;
                showDialog('campaign-body-preview');
                previewButton.setAttribute('aria-expanded', 'true');
            };
            previewButton.addEventListener('click', openBodyPreview);
            preview.addEventListener('close', () => previewButton.setAttribute('aria-expanded', 'false'));
            document.getElementById('campaign-body-preview-close').addEventListener('click', () => closeDialog(preview));
            document.getElementById('campaign-header-shuffle').addEventListener('click', () => {
                const lines = header.value.replace(/\r\n/g, '\n').split('\n');
                for (let index = lines.length - 1; index > 0; index--) {
                    const swap = Math.floor(Math.random() * (index + 1));
                    [lines[index], lines[swap]] = [lines[swap], lines[index]];
                }
                header.value = lines.join('\n');
                setToolStatus('Header lines shuffled.');
            });
            document.getElementById('campaign-header-encode').addEventListener('click', () => showDialog('campaign-encoding-dialog'));
            document.getElementById('campaign-shortlink-open').addEventListener('click', () => showDialog('campaign-shortlink-dialog'));
            document.getElementById('campaign-tags-help').addEventListener('click', () => showDialog('campaign-tags-dialog'));
            document.querySelectorAll('[data-dialog-close]').forEach((button) => {
                button.addEventListener('click', () => closeDialog(document.getElementById(button.dataset.dialogClose)));
            });
            document.querySelectorAll('[data-copy-selected]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const values = Array.from(document.getElementById(button.dataset.copySelected).selectedOptions).map((option) => option.textContent.trim());
                    setToolStatus(await copyText(values.join('\n')) ? 'Selected values copied.' : 'Select at least one value to copy.', values.length ? 'success' : 'warning');
                });
            });
            document.querySelectorAll('[data-encoding-tab]').forEach((button) => {
                button.addEventListener('click', () => {
                    document.querySelectorAll('[data-encoding-tab]').forEach((tab) => tab.classList.toggle('active', tab === button));
                    document.querySelectorAll('[data-encoding-panel]').forEach((panel) => { panel.hidden = panel.dataset.encodingPanel !== button.dataset.encodingTab; });
                });
            });
            document.getElementById('campaign-encoding-run').addEventListener('click', async () => {
                const dialog = document.getElementById('campaign-encoding-dialog');
                const response = await fetch(dialog.dataset.encodeUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                    body: JSON.stringify({string: document.getElementById('campaign-encoding-text').value, schema: document.getElementById('campaign-encoding-schema').value, charset: document.getElementById('campaign-encoding-charset').value}),
                });
                if (!response.ok) { document.getElementById('campaign-encoding-result').value = 'Encoding failed. Check the text and selected charset.'; return; }
                document.getElementById('campaign-encoding-result').value = await response.json();
            });
            document.getElementById('campaign-encoding-ip-run').addEventListener('click', () => {
                const input = document.getElementById('campaign-encoding-ip').value.trim();
                const octets = input.split('.').map(Number);
                if (octets.length !== 4 || octets.some((part, index) => !/^\d+$/.test(input.split('.')[index]) || part < 0 || part > 255)) {
                    document.getElementById('campaign-encoding-ip-result').value = 'Enter a valid IPv4 address.';
                    return;
                }
                const pad = (part, radix, width) => part.toString(radix).padStart(width, '0');
                const integer = octets.reduce((value, part) => value * 256 + part, 0);
                const hex = octets.map((part) => pad(part, 16, 2)).join('').toUpperCase();
                document.getElementById('campaign-encoding-ip-result').value = [
                    `Dotted Decimal IP : ${input}`,
                    `Octal IP : ${octets.map((part) => pad(part, 8, 4)).join('.')}`,
                    `Integer IP : ${integer}`,
                    `Hexadecimal IP : 0x${hex}`,
                    `OctalHexa IP : ${pad(octets[0], 8, 4)}.${pad(octets[1], 8, 4)}.0x${pad(octets[2], 16, 2).toUpperCase()}${pad(octets[3], 16, 2).toUpperCase()}`,
                    `DecimalHexa IP : ${octets[0]}.${octets[1]}.0x${pad(octets[2], 16, 2).toUpperCase()}${pad(octets[3], 16, 2).toUpperCase()}`,
                    `DecimalOctal IP : ${octets[0]}.${octets[1]}.${pad(octets[2], 8, 4)}.${pad(octets[3], 8, 4)}`,
                ].join('\n');
            });
            let shortlinkProvider = 'tinyurl';
            document.querySelectorAll('[data-shortlink-provider]').forEach((button) => {
                button.addEventListener('click', () => {
                    shortlinkProvider = button.dataset.shortlinkProvider;
                    document.querySelectorAll('[data-shortlink-provider]').forEach((tab) => tab.classList.toggle('active', tab === button));
                });
            });
            document.getElementById('campaign-shortlink-generate').addEventListener('click', async () => {
                const dialog = document.getElementById('campaign-shortlink-dialog');
                const links = [...new Set(document.getElementById('campaign-shortlink-input').value.split(/\r?\n/).map((link) => link.trim()).filter(Boolean))];
                const status = document.getElementById('campaign-shortlink-status');
                const results = document.getElementById('campaign-shortlink-results');
                results.replaceChildren();
                if (!links.length) { status.textContent = 'Enter at least one link.'; return; }
                status.textContent = 'Generating links…';
                const url = dialog.dataset.shortlinkUrlTemplate.replace('__PROVIDER__', shortlinkProvider);
                const response = await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify({links})});
                const data = await response.json().catch(() => ({}));
                if (!response.ok) { status.textContent = data.message || 'The short-link provider request failed.'; return; }
                data.forEach((result) => {
                    const row = document.createElement('tr');
                    [result.link, result.shortlink].forEach((value) => { const cell = document.createElement('td'); cell.textContent = value; row.append(cell); });
                    results.append(row);
                });
                status.textContent = `${data.length} short link${data.length === 1 ? '' : 's'} created.`;
            });
            const ipSource = document.getElementById('send-ips');
            const availableIps = document.getElementById('send-ips-available');
            const chosenIps = document.getElementById('send-ips-selected');
            const routeChoiceNames = {ip: 'IPs', vmta: 'VMTAs', smtp: 'SMTP routes'};
            const syncRouteChoiceLabels = () => {
                const route = document.getElementById('send-route')?.value || 'ip';
                const routeName = routeChoiceNames[route] || 'IPs';
                document.getElementById('send-route-choice-heading').textContent = routeName;
                document.getElementById('send-ips-available-label').textContent = `Available ${routeName}`;
                document.getElementById('send-ips-selected-label').textContent = `Selected ${routeName}`;
                availableIps.setAttribute('aria-label', `Available ${routeName}`);
                chosenIps.setAttribute('aria-label', `Selected ${routeName}`);
                document.getElementById('send-ips-available-filter').placeholder = `Filter available ${routeName.toLowerCase()}`;
                document.getElementById('send-ips-selected-filter').placeholder = `Filter selected ${routeName.toLowerCase()}`;
                const behavior = document.getElementById('send-route-behavior');
                if (behavior) {
                    behavior.innerHTML = route === 'smtp'
                        ? 'Option SMTP selects the outbound relay. <code>[smtp]</code> is its username and <code>[smtp_name]</code> is its label. Use <code>[smtp]</code> or <code>[smtp]@[domain]</code> for a local-part username. AlphaLink reaches PMTA through the selected server’s IP and listener; PMTA applies the selected SMTP virtual-MTA route. PMTA acceptance does not confirm mailbox delivery.'
                        : route === 'vmta'
                            ? 'VMTA mode uses the selected virtual MTA for outbound routing. <code>[smtp]</code> and <code>[smtp_name]</code> are empty. AlphaLink submits through the selected server’s PMTA listener.'
                        : 'Option IP sends through the selected source-IP route. <code>[smtp]</code> and <code>[smtp_name]</code> fill message text from one linked account; they do not change the route. To send through an SMTP relay, choose Option SMTP and select that account. The server IP is the PMTA ingress; the selected route controls outbound delivery. Start a saved campaign from My Campaigns after recipient preparation finishes.';
                }
                const moveLabels = {
                    'all-right': `Move all ${routeName} to selected`,
                    right: `Move selected ${routeName} to selected`,
                    left: `Move selected ${routeName} back to available`,
                    'all-left': `Move all ${routeName} back to available`,
                };
                document.querySelectorAll('[data-ip-move]').forEach((button) => {
                    button.setAttribute('aria-label', moveLabels[button.dataset.ipMove]);
                });
            };
            const renderIpLists = () => {
                if (!ipSource || !availableIps || !chosenIps) return;
                const selectedIds = new Set(Array.from(ipSource.options).filter((option) => option.selected).map((option) => option.value));
                const availableFilter = document.getElementById('send-ips-available-filter').value.toLowerCase();
                const chosenFilter = document.getElementById('send-ips-selected-filter').value.toLowerCase();
                availableIps.replaceChildren();
                chosenIps.replaceChildren();
                Array.from(ipSource.options).forEach((source) => {
                    const visibleText = source.textContent.toLowerCase();
                    const target = selectedIds.has(source.value) ? chosenIps : availableIps;
                    const filter = target === chosenIps ? chosenFilter : availableFilter;
                    if (filter && !visibleText.includes(filter)) return;
                    target.add(new Option(source.textContent, source.value));
                });
                document.getElementById('send-ips-selected-count').textContent = String(selectedIds.size);
            };
            const setIpSelection = (mode) => {
                const selectedIds = new Set(Array.from(ipSource.options).filter((option) => option.selected).map((option) => option.value));
                const move = (options, toSelected) => options.forEach((option) => toSelected ? selectedIds.add(option.value) : selectedIds.delete(option.value));
                if (mode === 'right') move(Array.from(availableIps.selectedOptions), true);
                if (mode === 'left') move(Array.from(chosenIps.selectedOptions), false);
                if (mode === 'all-right') move(Array.from(ipSource.options).filter((option) => !selectedIds.has(option.value)), true);
                if (mode === 'all-left') selectedIds.clear();
                Array.from(ipSource.options).forEach((option) => { option.selected = selectedIds.has(option.value); });
                renderIpLists();
                ipSource.dispatchEvent(new Event('change', {bubbles: true}));
            };
            document.querySelectorAll('[data-ip-move]').forEach((button) => button.addEventListener('click', () => setIpSelection(button.dataset.ipMove)));
            availableIps.addEventListener('dblclick', () => setIpSelection('right'));
            chosenIps.addEventListener('dblclick', () => setIpSelection('left'));
            document.getElementById('send-ips-available-filter').addEventListener('input', renderIpLists);
            document.getElementById('send-ips-selected-filter').addEventListener('input', renderIpLists);
            document.getElementById('send-route')?.addEventListener('change', syncRouteChoiceLabels);
            syncRouteChoiceLabels();
            document.querySelectorAll('[data-copy-ip-list]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const selectedIds = new Set(Array.from(ipSource.options).filter((option) => option.selected).map((option) => option.value));
                    const options = Array.from(ipSource.options).filter((option) => button.dataset.copyIpList === 'selected' ? selectedIds.has(option.value) : !selectedIds.has(option.value));
                    setToolStatus(await copyText(options.map((option) => option.dataset.ip || option.textContent.split(/[|,]/)[0]).join('\n')) ? 'IP addresses copied.' : 'There are no IP addresses to copy.', options.length ? 'success' : 'warning');
                });
            });
            renderIpLists();
            const progressButton = document.getElementById('campaign-start-progress-change');
            progressButton?.addEventListener('click', async () => {
                const status = document.getElementById('campaign-start-progress-status');
                status.textContent = 'Saving progress…';
                const response = await fetch(progressButton.dataset.progressUrl, {
                    method: 'PUT', credentials: 'same-origin',
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                    body: JSON.stringify({send_progress: document.getElementById('campaign-start-progress').value}),
                });
                const result = await response.json().catch(() => ({}));
                status.textContent = response.ok ? `Progress updated (${result.send_progress} of ${result.send_count}).` : (result.message || 'Progress could not be updated. Check the maximum shown above.');
            });
            document.getElementById('campaign-servers-search').addEventListener('click', () => {
                showDialog('campaign-server-search-dialog');
            });
            document.getElementById('campaign-server-search-apply').addEventListener('click', () => {
                const queries = [...new Set(document.getElementById('campaign-server-search-input').value.split(/\r?\n/).map((query) => query.trim()).filter(Boolean))];
                if (!queries.length) {
                    closeDialog(document.getElementById('campaign-server-search-dialog'));
                    return;
                }
                const serverSelect = document.getElementById('send-servers');
                const matches = new Set();
                queries.forEach((query) => {
                    const normalized = query.toLowerCase();
                    Array.from(serverSelect.options).forEach((option) => {
                        const [serverName = '', ...ipParts] = option.textContent.split('|');
                        const ipAddress = ipParts.join('|').trim();
                        if (serverName.trim().toLowerCase() === normalized || ipAddress.includes(query)) {
                            matches.add(option);
                        }
                    });
                });
                Array.from(serverSelect.options).forEach((option) => { option.selected = matches.has(option); });
                serverSelect.dispatchEvent(new Event('change', {bubbles: true}));
                closeDialog(document.getElementById('campaign-server-search-dialog'));
                if (!matches.size) { setToolStatus('No matching servers found.', 'warning'); return; }
                setToolStatus(`${matches.size} matching server${matches.size === 1 ? '' : 's'} selected.`);
            });
            document.getElementById('campaign-body-generate-links')?.addEventListener('click', async (event) => {
                const button = event.currentTarget;
                if (!body.value.trim()) { setToolStatus('Body is empty.', 'warning'); return; }
                button.disabled = true;
                try {
                    const response = await fetch(button.dataset.generateUrl, {
                        method: 'POST', credentials: 'same-origin',
                        headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                        body: JSON.stringify({body: body.value}),
                    });
                    const result = await response.json().catch(() => ({}));
                    if (!response.ok) { setToolStatus(result.message || 'Tracking links could not be generated.', 'danger'); return; }
                    body.value = result.data;
                    setToolStatus('Tracking token and random tags generated for this campaign.');
                } finally {
                    button.disabled = false;
                }
            });
            const campaignDomainField = document.getElementById('campaign-domain');
            const campaignRouteField = document.getElementById('send-route');
            const campaignIpsField = document.getElementById('send-ips');
            const campaignDomainHint = document.getElementById('campaign-domain-route-hint');
            const refreshCampaignDomainOptions = (records) => {
                const selected = campaignDomainField.value;
                const domains = new Map((records || []).map((record) => [String(record.value), record]));
                campaignDomainField.replaceChildren(new Option('Select Domain', ''));
                domains.forEach((record, value) => campaignDomainField.add(new Option(`${record.name} — ${record.dns}`, value)));
                campaignDomainField.value = domains.has(selected) ? selected : '';
                if (window.jQuery?.fn.selectpicker) window.jQuery(campaignDomainField).selectpicker('refresh');
            };
            const syncCampaignDomainToRoute = () => {
                const route = campaignRouteField?.value;
                const selectedChoices = Array.from(campaignIpsField?.selectedOptions ?? []);
                if (route === 'vmta') {
                    const selectedServerIds = new Set(Array.from(document.getElementById('send-servers')?.selectedOptions ?? []).map((option) => String(option.value)));
                    const vmtaChoices = selectedChoices.length
                        ? selectedChoices
                        : Array.from(campaignIpsField?.options ?? []).filter((option) => option.dataset.route === 'vmta' && selectedServerIds.has(String(option.dataset.serverId)));
                    const domain = String(vmtaChoices[0]?.dataset.vmtaDomain || '').trim();
                    if (!domain) {
                        campaignDomainField.value = '';
                        if (window.jQuery?.fn.selectpicker) window.jQuery(campaignDomainField).selectpicker('refresh');
                        campaignDomainHint.hidden = false;
                        campaignDomainHint.textContent = vmtaChoices.length
                            ? 'The selected VMTA has no configured domain. Choose or push a VMTA with a domain before saving.'
                            : 'Select a VMTA for the chosen server to use its configured domain.';
                        return;
                    }
                    campaignDomainHint.hidden = false;
                    campaignDomainHint.textContent = vmtaChoices.some((option) => option.dataset.vmtaDomain !== domain)
                        ? `Showing ${domain}; Campaign Test resolves [domain] from each selected VMTA.`
                        : `Using the selected VMTA domain ${domain}.`;
                    if (!Array.from(campaignDomainField.options).some((option) => option.value === domain)) {
                        campaignDomainField.add(new Option(`VMTA — ${domain}`, domain));
                    }
                    campaignDomainField.value = domain;
                    if (window.jQuery?.fn.selectpicker) window.jQuery(campaignDomainField).selectpicker('refresh');
                    return;
                }
                campaignDomainHint.hidden = true;
                if (route === 'ip') {
                    const domain = selectedChoices.find((option) => option.dataset.dns)?.dataset.dns || '';
                    if (domain && Array.from(campaignDomainField.options).some((option) => option.value === domain)) {
                        campaignDomainField.value = domain;
                        if (window.jQuery?.fn.selectpicker) window.jQuery(campaignDomainField).selectpicker('refresh');
                    }
                }
            };
            const selectedCreative = () => document.getElementById('send-creative-asset').selectedOptions[0];
            const publicAssetUrl = (option) => {
                const value = option?.dataset.publicUrl || option?.dataset.assetValue || 'NA';
                return /^https?:\/\//i.test(value) ? value : `https://[domain]/${value}`;
            };
            const applySelectedHtml = () => {
                const option = document.getElementById('send-html-asset').selectedOptions[0];
                if (option?.value && option.dataset.assetValue) body.value = option.dataset.assetValue;
            };
            const applySelectedCreative = () => {
                const option = selectedCreative();
                if (!option?.value) return;
                const source = publicAssetUrl(option);
                body.value = body.value.replace(/src="[^"]*"/i, `src="${source}"`);
            };
            document.getElementById('send-creative-asset').addEventListener('change', applySelectedCreative);
            document.getElementById('send-html-asset').addEventListener('change', applySelectedHtml);
            campaignDomainField.addEventListener('change', () => {
                if (campaignRouteField?.value === 'vmta') {
                    syncCampaignDomainToRoute();
                    return;
                }
            });
            document.getElementById('campaign-body-map').addEventListener('change', async (event) => {
                const creative = document.getElementById('send-creative-asset').selectedOptions[0];
                if (!creative?.value) { event.target.checked = false; setToolStatus('Please select a creative first.', 'warning'); return; }
                const source = publicAssetUrl(creative);
                const unsubscribeValue = creative.dataset.unsubscribeValue || 'NA';
                const unsubscribe = /^https?:\/\//i.test(unsubscribeValue) ? unsubscribeValue : `https://[domain]/${unsubscribeValue}`;
                const dimensions = (url) => new Promise((resolve) => {
                    const image = new Image();
                    image.onload = () => resolve([image.naturalWidth || image.width, image.naturalHeight || image.height]);
                    image.onerror = () => resolve([0, 0]);
                    image.src = url;
                });
                const [creativeWidth, creativeHeight] = event.target.checked ? await dimensions(source) : [0, 0];
                const [unsubscribeWidth, unsubscribeHeight] = event.target.checked ? await dimensions(unsubscribe) : [0, 0];
                const bodyStart = '<html>\n<body>\n<center>\n<a href="https://[domain]/[rndUN_10]r[token]">\n';
                const trackingEnd = '<img src="https://[domain]/[rndUN_10]o[token]">\n</center>\n</body>\n</html>\n';
                const imageTag = event.target.checked
                    ? `<img src="${source}" usemap="#r[ide]" style="width: ${creativeWidth}px; height: ${creativeHeight}px" />\n<br/>\n<map name="r[ide]">\n<area shape="rect" coords="0,0,2000,2000" target="_blank" href="https://[domain]/[rndUN_10]r[token]"/>\n</map>\n<img src="${unsubscribe}" usemap="#u[ide]" style="width: ${unsubscribeWidth}px; height: ${unsubscribeHeight}px"/>\n<br/>\n<map name="u[ide]">\n<area shape="rect" coords="0,0,2000,2000" target="_blank" href="https://[domain]/[rndUN_10]u[token]"/>\n</map>\n`
                    : `<img src="${source}" />\n</a><br>\n<a href="https://[domain]/[rndUN_10]u[token]">\n<img src="${unsubscribe}" />\n</a><br>\n`;
                body.value = bodyStart + (event.target.checked ? '<h2 style="font-size:35px; color:#324ab2">TITLE</h2>\n</a>\n<br/>\n' : '<font size="4">TTILE</font><br>\n') + imageTag + trackingEnd;
            });
            const headerField = document.getElementById('campaign-header');
            document.getElementById('campaign-header-use-smtp')?.addEventListener('click', () => {
                const lines = headerField.value.split(/\r\n|\r|\n/);
                let replaced = false;
                const updated = lines.map((line) => {
                    if (!replaced && /^\s*from\s*:/i.test(line)) {
                        replaced = true;
                        return 'From: [smtp]@[domain]';
                    }
                    return line;
                });
                if (!replaced) updated.push('From: [smtp]@[domain]');
                headerField.value = updated.join('\n');
                const route = document.getElementById('send-route');
                if (route && route.value !== 'smtp') {
                    route.value = 'smtp';
                    route.dispatchEvent(new Event('change', {bubbles: true}));
                }
                headerField.focus();
                setToolStatus('Header From now uses the SMTP username. Select the intended Workspace account under Available SMTP routes before saving or testing.');
            });
            const fromAssets = document.getElementById('send-from-assets');
            const subjectAssets = document.getElementById('send-subject-assets');
            fromAssets?.addEventListener('change', () => {
                const values = Array.from(fromAssets.selectedOptions).map((option) => option.textContent.trim());
                const replacement = values.length === 1 ? `From: ${values[0]} <` : 'From: [from] <';
                headerField.value = headerField.value.replace(/from.*:.*</gi, replacement);
            });
            subjectAssets?.addEventListener('change', () => {
                const values = Array.from(subjectAssets.selectedOptions).map((option) => option.textContent.trim());
                const replacement = values.length === 1 ? `Subject: ${values[0]}` : 'Subject: [subject]';
                headerField.value = headerField.value.replace(/subject.*:.*/gi, replacement);
            });
            campaignIpsField?.addEventListener('change', syncCampaignDomainToRoute);

            if (@json((bool) $editingCampaign)) {
                if (window.jQuery && window.jQuery.fn.selectpicker) window.jQuery('.selectpicker').selectpicker();
                const servers = document.getElementById('send-servers');
                const ips = document.getElementById('send-ips');
                const route = document.getElementById('send-route');
                const type = document.getElementById('send-ip-type');
                const panels = document.getElementById('campaign-pmta-panels');
                const refresh = (element) => { if (window.jQuery?.fn.selectpicker) window.jQuery(element).selectpicker('refresh'); };
                const routeChoiceIdentity = (option) => [
                    option.dataset.route || '',
                    option.dataset.serverId || '',
                    option.dataset.routeChoiceId || option.value,
                    option.dataset.managedServerIpId || '',
                    option.dataset.pmtaName || '',
                    option.dataset.username || '',
                    option.dataset.name || '',
                    option.dataset.vmtaDomain || '',
                ].join('|');
                const routeChoiceLabel = (ip, routeOption) => {
                    if (routeOption === 'vmta') return [ip.ip, ip.dns, ip.name].filter((value) => value !== null && value !== undefined && String(value) !== '').join(' | ');
                    if (routeOption === 'smtp') return [ip.ip, ip.username, ip.name, ip.dns].filter((value) => value !== null && value !== undefined && String(value) !== '').join(' | ');
                    const label = [ip.ip, ip.dns].filter((value) => value !== null && value !== undefined && String(value) !== '');
                    if (ip.smtp_account_count === 1) {
                        label.push(`SMTP: ${ip.username || 'linked account'}`);
                    } else if (ip.smtp_account_count > 1) {
                        label.push('Multiple SMTP accounts — choose SMTP mode');
                    }

                    return label.join(' | ');
                };
                let loadedRoute = route.value;
                const reloadIps = async () => {
                    const requestedRoute = route.value;
                    const selectedServerIds = new Set(Array.from(servers.selectedOptions).map((option) => String(option.value)));
                    const query = new URLSearchParams({route: requestedRoute, ip_type: type.value});
                    selectedServerIds.forEach((serverId) => query.append('server_ids[]', serverId));
                    const response = await fetch(`${form.dataset.optionsUrl}?${query}`, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
                    if (!response.ok) return;
                    const result = (await response.json()).data;
                    const selected = requestedRoute === loadedRoute
                        ? new Set(Array.from(ips.selectedOptions)
                            .filter((option) => selectedServerIds.has(String(option.dataset.serverId)))
                            .map(routeChoiceIdentity))
                        : new Set();
                    ips.replaceChildren();
                    result.ips.forEach(ip => {
                        const option = new Option(routeChoiceLabel(ip, requestedRoute), ip.id);
                        option.dataset.route = requestedRoute;
                        option.dataset.routeChoiceId = String(ip.id);
                        option.dataset.serverId = String(ip.server_id);
                        option.dataset.managedServerIpId = String(ip.managed_server_ip_id || '');
                        option.dataset.pmtaName = String(ip.pmta_name || '');
                    option.dataset.username = String(ip.username || '');
                    option.dataset.name = String(ip.name || '');
                    option.dataset.ip = String(ip.ip || '');
                    option.dataset.dns = String(ip.dns || '');
                    option.dataset.vmtaDomain = String(ip.vmta_domain || '');
                    option.selected = selected.has(routeChoiceIdentity(option));
                        ips.add(option);
                    });
                    loadedRoute = requestedRoute;
                    refresh(ips);
                    renderIpLists();
                    refreshCampaignDomainOptions(result.campaign_domains);
                    syncCampaignDomainToRoute();
                    panels.replaceChildren();
                    const selectedServers = new Set(Array.from(servers.selectedOptions).map(option => option.value));
                    result.servers.filter(server => selectedServers.has(String(server.id))).forEach(server => {
                        const link = document.createElement('a');
                        const monitorUrl = server.pmta_panel_display_url || server.pmta_panel_url;
                        link.href = monitorUrl; link.target = '_blank'; link.rel = 'noopener noreferrer';
                        link.className = 'mr-3';
                        const usesReadOnlyProxy = monitorUrl !== server.pmta_panel_url;
                        const panelScheme = new URL(monitorUrl, window.location.href).protocol === 'https:' ? 'HTTPS' : 'HTTP/IP';
                        link.textContent = `Open PMTA monitor (${usesReadOnlyProxy ? 'HTTPS read-only proxy' : panelScheme}) — ${server.name}`;
                        panels.append(link);
                    });
                };
                syncCampaignDomainToRoute();
                [servers, route, type].forEach(element => element.addEventListener('change', () => { reloadIps().catch(() => {}); }));
                document.getElementById('campaign-servers-reload').addEventListener('click', () => {
                    reloadIps().then(() => setToolStatus('Servers and IPs reloaded.')).catch(() => setToolStatus('Servers and IPs could not be reloaded.', 'danger'));
                });
                return;
            }

            const url = form.dataset.optionsUrl;
            const saveButton = document.getElementById('campaign-save-button');
            const status = document.getElementById('campaign-options-status');
            const retry = document.getElementById('campaign-options-retry');
            const panels = document.getElementById('campaign-pmta-panels');
            const snapshotProgress = document.getElementById('campaign-snapshot-progress');
            const snapshotStage = document.getElementById('campaign-snapshot-stage');
            const snapshotPercent = document.getElementById('campaign-snapshot-percent');
            const snapshotBar = document.getElementById('campaign-snapshot-bar');
            const snapshotDetail = document.getElementById('campaign-snapshot-detail');
            const snapshotOpen = document.getElementById('campaign-snapshot-open');
            const snapshotError = document.getElementById('campaign-snapshot-error');
            const snapshotRetry = document.getElementById('campaign-snapshot-retry');
            const submitError = document.getElementById('campaign-submit-error');
            const newCampaignLink = document.getElementById('campaign-new-campaign');
            const stageLabels = {
                queued: 'Waiting for a background worker',
                suppression_file: 'Applying offer suppression file',
                counting_audience: 'Counting selected list recipients',
                audience: 'Filtering selected recipients and removing duplicates',
                writing_snapshot: 'Saving the campaign recipient snapshot',
                complete: 'Suppression and recipient preparation complete',
                failed: 'Campaign preparation failed',
            };
            let snapshotPollTimer = null;
            let snapshotPollInFlight = false;
            let campaignSaved = Boolean(snapshotProgress.dataset.progressUrl);
            const ids = {
                sponsor: 'send-sponsor',
                offerCountry: 'send-offer-country',
                offer: 'send-offer',
                fromAssets: 'send-from-assets',
                subjectAssets: 'send-subject-assets',
                creativeAsset: 'send-creative-asset',
                htmlAsset: 'send-html-asset',
                isp: 'send-isp',
                country: 'send-country',
                lists: 'send-lists',
                route: 'send-route',
                ipType: 'send-ip-type',
                servers: 'send-servers',
                ips: 'send-ips',
            };
            let controller = null;
            let sequence = 0;
            let optionsReady = true;

            const select = (key) => document.getElementById(ids[key]);
            const selectedValues = (element) => Array.from(element.selectedOptions).map((option) => option.value).filter(Boolean);
            const campaignBody = document.getElementById('campaign-body');
            const campaignDomain = document.getElementById('campaign-domain');
            let lastOfferId = select('offer').value;
            const pickerRefresh = (element) => {
                if (window.jQuery && window.jQuery.fn.selectpicker) window.jQuery(element).selectpicker('refresh');
            };
            const applyHtmlAsset = () => {
                const option = select('htmlAsset').selectedOptions[0];
                if (!option?.value) return false;
                campaignBody.value = option.dataset.assetValue || '';
                return true;
            };
            const applyCreativeAsset = () => {
                const option = select('creativeAsset').selectedOptions[0];
                if (!option?.value) return;
                const creativePath = option.dataset.assetValue || 'NA';
                const creativeUrl = /^https?:\/\//i.test(creativePath) ? creativePath : 'https://[domain]/' + creativePath;
                campaignBody.value = campaignBody.value.replace(/src="[^"]*"/i, 'src="' + creativeUrl + '"');
            };
            const assetImageUrl = (asset) => /^https?:\/\//i.test(String(asset || '')) ? String(asset) : 'https://[domain]/' + (asset || 'NA');
            const defaultCampaignBody = (creative, unsubscribe) => [
                '<html>',
                '<body>',
                '<center>',
                '<a href="https://[domain]/[rndUN_10]r[token]">',
                '<font size="4">TTILE</font><br>',
                '<img src="' + assetImageUrl(creative) + '" />',
                '</a><br>',
                '<a href="https://[domain]/[rndUN_10]u[token]">',
                '<img src="' + assetImageUrl(unsubscribe) + '" />',
                '</a><br>',
                '<img src="https://[domain]/[rndUN_10]o[token]" />',
                '</center>',
                '</body>',
                '</html>',
                '',
            ].join('\n');
            const setStatus = (message = '', tone = 'info') => {
                status.className = message ? `alert alert-${tone}` : 'alert d-none';
                status.textContent = message;
            };
            const getFilters = () => ({
                sponsor_id: select('sponsor').value,
                offer_country: select('offerCountry').value || 'ALL',
                isp_id: select('isp').value,
                country: select('country').value || 'ALL',
                server_ids: selectedValues(select('servers')),
                route: select('route').value || 'ip',
                ip_type: select('ipType').value || 'all',
            });
            let lastPaintedRoute = select('route').value || 'ip';
            const fill = (key, records, getValue, getLabel, selected, placeholder) => {
                const element = select(key);
                const previous = selected ?? (element.multiple ? selectedValues(element) : element.value);
                const wanted = new Set(Array.isArray(previous) ? previous.map(String) : [String(previous || '')]);
                element.replaceChildren();
                if (!element.multiple) {
                    const empty = document.createElement('option');
                    empty.value = '';
                    empty.textContent = placeholder;
                    element.append(empty);
                }
                let retained = 0;
                records.forEach((record) => {
                    const option = document.createElement('option');
                    option.value = String(getValue(record));
                    option.textContent = String(getLabel(record));
                    if (key === 'servers') {
                        option.dataset.panelUrl = String(record.pmta_panel_url ?? record.pmta_panel_display_url ?? '');
                        option.dataset.monitorUrl = String(record.pmta_panel_display_url ?? record.pmta_panel_url ?? '');
                    }
                    if (key === 'ips') {
                        option.dataset.route = select('route').value || 'ip';
                        option.dataset.serverId = String(record.server_id ?? '');
                        option.dataset.ip = String(record.ip ?? '');
                        option.dataset.dns = String(record.dns ?? '');
                        option.dataset.vmtaDomain = String(record.vmta_domain ?? '');
                    }
                    if (key === 'creativeAsset') option.dataset.assetValue = String(record.public_url ?? record.value ?? '');
                    if (key === 'htmlAsset') option.dataset.assetValue = String(record.value ?? '');
                    if (wanted.has(option.value)) {
                        option.selected = true;
                        retained++;
                    }
                    element.append(option);
                });
                if (element.multiple) {
                    element.required = ['lists', 'servers', 'ips'].includes(key)
                        && !document.getElementById('campaign-targeting').hidden;
                } else if (retained === 0) {
                    element.value = '';
                }
                pickerRefresh(element);
            };
            const paintOptions = (data) => {
                const selectedRoute = select('route').value || 'ip';
                const routeChanged = selectedRoute !== lastPaintedRoute;
                const current = {
                    ...getFilters(),
                    offer_id: select('offer').value,
                    audience_segments: selectedValues(select('lists')),
                    from_asset_ids: selectedValues(select('fromAssets')),
                    subject_asset_ids: selectedValues(select('subjectAssets')),
                    creative_asset_id: select('creativeAsset').value,
                    html_asset_id: select('htmlAsset').value,
                    ip_ids: routeChanged ? [] : selectedValues(select('ips')),
                };
                lastPaintedRoute = selectedRoute;
                fill('sponsor', data.sponsors, (item) => item.id, (item) => item.name, current.sponsor_id, 'Select Sponsor');
                fill('offer', data.offers, (item) => item.id, (item) => `(${item.offer_id}) ${item.name} — ${item.vertical}`, current.offer_id, 'Select Offer');
                const selectedOffer = data.offers.find((offer) => String(offer.id) === String(select('offer').value));
                const selectedAssets = selectedOffer?.assets ?? [];
                fill('fromAssets', selectedAssets.filter((asset) => asset.type === 'from'), (asset) => asset.id, (asset) => asset.value, current.from_asset_ids, 'Select optional From values');
                fill('subjectAssets', selectedAssets.filter((asset) => asset.type === 'subject'), (asset) => asset.id, (asset) => asset.value, current.subject_asset_ids, 'Select optional subjects');
                fill('creativeAsset', selectedAssets.filter((asset) => asset.type === 'creative'), (asset) => asset.id, (asset) => asset.value, current.creative_asset_id, 'Select Creative');
                fill('htmlAsset', selectedAssets.filter((asset) => asset.type === 'html'), (asset) => asset.id, (asset) => String(asset.value).slice(0, 50) + '…', current.html_asset_id, 'Select HTML');
                const selectedOfferId = select('offer').value;
                if (selectedOffer && (selectedOfferId !== lastOfferId || !campaignBody.value.trim())) {
                    const creative = selectedAssets.find((asset) => String(asset.id) === String(select('creativeAsset').value))
                        ?? selectedAssets.find((asset) => asset.type === 'creative');
                    const unsubscribe = selectedAssets.find((asset) => asset.type === 'unsubscribe');
                    campaignBody.value = defaultCampaignBody(creative?.public_url ?? creative?.value, unsubscribe?.public_url ?? unsubscribe?.value);
                    applyHtmlAsset();
                }
                lastOfferId = selectedOfferId;
                fill('isp', data.isps, (item) => item.id, (item) => item.name, current.isp_id, 'Select ISP');
                fill('lists', data.segments, (item) => item.id, (item) => `${item.name} — ${item.country} — ${String(item.email_type).toUpperCase()} — ${Number(item.recipient_count).toLocaleString()}`, current.audience_segments, 'Select Lists');
                fill('servers', data.servers, (item) => item.id, (item) => `${item.name} | ${item.ip}`, current.server_ids, 'Select Servers');
                refreshCampaignDomainOptions(data.campaign_domains);
                syncRouteChoiceLabels();
                const routeChoiceLabel = selectedRoute === 'smtp'
                    ? (item) => [item.ip, item.username, item.name, item.dns || 'no DNS'].filter((value) => value !== null && value !== undefined && String(value) !== '').join(' | ')
                    : selectedRoute === 'vmta'
                        ? (item) => [item.ip, item.dns || 'no DNS', item.name].filter((value) => value !== null && value !== undefined && String(value) !== '').join(' | ')
                        : (item) => `${item.ip} | ${String(item.type).toUpperCase()} | ${item.dns || 'no DNS'}`;
                fill('ips', data.ips, (item) => item.id, (item) => `${routeChoiceLabel(item)} (server ${item.server_id})`, current.ip_ids, `Select ${routeChoiceNames[selectedRoute] || 'IPs'}`);
                renderIpLists();
                syncCampaignDomainToRoute();

                panels.replaceChildren();
                const selectedServers = new Set(selectedValues(select('servers')));
                data.servers.filter((server) => selectedServers.has(String(server.id))).forEach((server) => {
                    const column = document.createElement('div');
                    column.className = 'col-sm-6 col-lg-4';
                    const link = document.createElement('a');
                    const monitorUrl = server.pmta_panel_display_url || server.pmta_panel_url;
                    link.href = monitorUrl;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    const usesReadOnlyProxy = monitorUrl !== server.pmta_panel_url;
                    const panelScheme = new URL(monitorUrl, window.location.href).protocol === 'https:' ? 'HTTPS' : 'HTTP/IP';
                    link.textContent = `Open PMTA monitor (${usesReadOnlyProxy ? 'HTTPS read-only proxy' : panelScheme}) — ${server.name}:${data.settings.pmta_admin_port}`;
                    column.append(link);
                    panels.append(column);
                });

                const sponsorMissing = data.sponsors.length === 0;
                const offerMissing = current.sponsor_id && data.offers.length === 0;
                const ispMissing = current.sponsor_id && data.isps.length === 0;
                const listsMissing = current.isp_id && data.lists.length === 0;
                const serversMissing = data.servers.length === 0;
                if (sponsorMissing || offerMissing || ispMissing || listsMissing || serversMissing) {
                    const missing = [];
                    if (sponsorMissing) missing.push('active sponsor with an eligible offer');
                    if (offerMissing) missing.push('eligible offer for the selected sponsor and country');
                    if (ispMissing) missing.push('active ISP available to the selected sponsor');
                    if (listsMissing) missing.push('active list for the selected ISP and country');
                    if (serversMissing) missing.push('active installed server assigned to your user');
                    setStatus(`No ${missing.join(', ')} is available in this workspace. Check its active state, access assignment, and source data.`, 'warning');
                } else if (!current.sponsor_id) {
                    setStatus('Choose a sponsor to load its eligible offers and the ISPs assigned to that sponsor.', 'info');
                } else if (!current.isp_id) {
                    setStatus('Choose an ISP for the selected sponsor to load its active lists.', 'info');
                } else {
                    setStatus('Choices loaded. Saving creates the suppression-filtered recipient snapshot. Start the campaign from My Campaigns after preparation finishes; scheduling is not available.', 'info');
                }
            };
            const loadOptions = async () => {
                const ticket = ++sequence;
                controller?.abort();
                controller = new AbortController();
                let httpErrorStatus = null;
                const query = new URLSearchParams();
                const filters = getFilters();
                Object.entries(filters).forEach(([key, value]) => {
                    if (Array.isArray(value)) value.forEach((entry) => query.append(`${key}[]`, entry));
                    else if (value !== '') query.set(key, value);
                });
                optionsReady = false;
                retry.classList.add('d-none');
                saveButton.disabled = true;
                saveButton.setAttribute('aria-busy', 'true');
                setStatus('Loading updated campaign choices…', 'info');
                try {
                    const response = await fetch(`${url}?${query.toString()}`, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        signal: controller.signal,
                    });
                    if (!response.ok) {
                        httpErrorStatus = response.status;
                        throw new Error(`Request failed with status ${response.status}`);
                    }
                    const payload = await response.json();
                    if (ticket !== sequence) return;
                    paintOptions(payload.data);
                    optionsReady = true;
                } catch (error) {
                    if (error.name === 'AbortError' || ticket !== sequence) return;
                    console.error('Campaign choices refresh failed', error);
                    optionsReady = false;
                    const statusHint = httpErrorStatus ? ` (HTTP ${httpErrorStatus})` : '';
                    setStatus(`Campaign choices could not be refreshed${statusHint}. Your current selections are still on the form; retry before saving.`, 'danger');
                    retry.classList.remove('d-none');
                } finally {
                    if (ticket === sequence) {
                        saveButton.disabled = !optionsReady || campaignSaved;
                        saveButton.removeAttribute('aria-busy');
                    }
                }
            };
            const applySnapshotProgress = (payload) => {
                const stage = String(payload.stage || (payload.ready ? 'complete' : 'queued'));
                const percent = Math.max(0, Math.min(100, Number.parseInt(payload.percent ?? 0, 10) || 0));
                const processed = Math.max(0, Number.parseInt(payload.processed ?? 0, 10) || 0);
                const total = payload.total === null || payload.total === undefined ? null : Math.max(0, Number.parseInt(payload.total, 10) || 0);
                snapshotProgress.hidden = false;
                snapshotStage.textContent = stageLabels[stage] || 'Preparing the campaign audience';
                snapshotPercent.textContent = `${percent}%`;
                snapshotBar.style.width = `${percent}%`;
                snapshotBar.textContent = `${percent}%`;
                snapshotBar.parentElement.setAttribute('aria-valuenow', String(percent));
                snapshotDetail.textContent = total > 0
                    ? `${processed.toLocaleString()} of ${total.toLocaleString()} ${stage === 'suppression_file' ? 'bytes checked' : 'records processed'}.`
                    : 'The campaign composer stays open while the selected lists are processed.';

                const complete = payload.ready === true || stage === 'complete' || payload.status === 'draft';
                const failed = payload.status === 'snapshot_failed' || stage === 'failed';
                if (complete) {
                    snapshotStage.textContent = 'Suppression and recipient preparation complete. Returning to campaigns…';
                    snapshotBar.classList.remove('progress-bar-animated');
                    snapshotPercent.textContent = '100%';
                    snapshotBar.style.width = '100%';
                    snapshotBar.textContent = '100%';
                    snapshotBar.parentElement.setAttribute('aria-valuenow', '100');
                    saveButton.hidden = true;
                    newCampaignLink.hidden = false;
                    clearInterval(snapshotPollTimer);
                    snapshotPollTimer = null;
                    window.setTimeout(() => window.location.assign(snapshotProgress.dataset.campaignsUrl), 700);
                } else if (failed) {
                    snapshotStage.textContent = 'Campaign preparation failed. Your composer is still open.';
                    snapshotBar.classList.remove('progress-bar-animated');
                    snapshotError.textContent = payload.error || 'The selected suppression file or audience could not be processed. Review the saved campaign and retry preparation.';
                    snapshotError.hidden = false;
                    snapshotRetry.hidden = false;
                    snapshotRetry.href = snapshotProgress.dataset.campaignUrl;
                    saveButton.hidden = true;
                    newCampaignLink.hidden = false;
                    clearInterval(snapshotPollTimer);
                    snapshotPollTimer = null;
                }
            };
            const pollSnapshotProgress = async () => {
                if (snapshotPollInFlight || !snapshotProgress.dataset.progressUrl) return;
                snapshotPollInFlight = true;
                try {
                    const response = await fetch(snapshotProgress.dataset.progressUrl, {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                    });
                    if (!response.ok) throw new Error(`Progress request failed (${response.status}).`);
                    applySnapshotProgress(await response.json());
                } catch (error) {
                    snapshotStage.textContent = 'Preparation is still running; reconnecting to progress…';
                    snapshotDetail.textContent = error.message || 'The latest progress update could not be loaded.';
                } finally {
                    snapshotPollInFlight = false;
                }
            };
            const startSnapshotProgress = () => {
                snapshotProgress.hidden = false;
                if (snapshotPollTimer === null) snapshotPollTimer = window.setInterval(pollSnapshotProgress, 1500);
                void pollSnapshotProgress();
            };
            const submitMarketingCampaign = async () => {
                submitError.hidden = true;
                snapshotError.hidden = true;
                snapshotOpen.hidden = true;
                snapshotRetry.hidden = true;
                snapshotProgress.hidden = false;
                snapshotStage.textContent = 'Saving the campaign and starting suppression…';
                snapshotDetail.textContent = 'This page will stay open while suppression and recipient preparation run.';
                snapshotPercent.textContent = '0%';
                snapshotBar.style.width = '0%';
                snapshotBar.textContent = '0%';
                snapshotBar.classList.add('progress-bar-animated');
                saveButton.disabled = true;
                saveButton.setAttribute('aria-busy', 'true');
                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                        body: new FormData(form),
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        const details = Object.values(payload.errors || {}).flat();
                        throw new Error(details.join('\n') || payload.message || `Campaign save failed (${response.status}).`);
                    }
                    snapshotProgress.dataset.progressUrl = payload.snapshot_progress_url;
                    snapshotProgress.dataset.campaignUrl = payload.campaign_url;
                    snapshotProgress.dataset.editUrl = payload.edit_url;
                    campaignSaved = true;
                    const pageUrl = new URL(window.location.href);
                    pageUrl.searchParams.set('preparing_campaign', payload.campaign_id);
                    window.history.replaceState(window.history.state, '', pageUrl.toString());
                    startSnapshotProgress();
                } catch (error) {
                    submitError.textContent = error.message || 'The campaign could not be saved. Review the form and try again.';
                    submitError.hidden = false;
                    snapshotProgress.hidden = true;
                    saveButton.disabled = false;
                } finally {
                    saveButton.removeAttribute('aria-busy');
                }
            };
            ['sponsor', 'offerCountry', 'offer', 'isp', 'country', 'route', 'ipType', 'servers'].forEach((key) => {
                select(key).addEventListener('change', () => {
                    loadOptions();
                });
            });
            retry.addEventListener('click', loadOptions);
            document.getElementById('campaign-servers-reload').addEventListener('click', loadOptions);
            form.addEventListener('submit', (event) => {
                if (event.submitter?.hasAttribute('formnovalidate')) return;
                if (!form.reportValidity()) {
                    event.preventDefault();
                    return;
                }
                if (!optionsReady) {
                    event.preventDefault();
                    retry.focus();
                    return;
                }
                if (floatingActions.dataset.editing === 'false'
                    && form.querySelector('input[name="kind"]')?.value === 'marketing') {
                    event.preventDefault();
                    void submitMarketingCampaign();
                    return;
                }
                saveButton.disabled = true;
                saveButton.setAttribute('aria-busy', 'true');
            });

            if (window.jQuery && window.jQuery.fn.selectpicker) window.jQuery('.selectpicker').selectpicker();
            if (snapshotProgress.dataset.progressUrl) {
                campaignSaved = true;
                saveButton.disabled = true;
                saveButton.textContent = 'Draft saved';
                startSnapshotProgress();
            }
            loadOptions();
        })();

        (() => {
            const form = document.getElementById('campaign-create-form');
            const summary = document.getElementById('campaign-summary');

            if (!form || !summary) return;

            const field = (id) => document.getElementById(id);
            const summaryValue = (name) => summary.querySelector('[data-campaign-summary="' + name + '"]');
            const initialAudienceCount = Number.parseInt(summary.dataset.initialAudiences || '0', 10) || 0;
            const initialRouteCount = Number.parseInt(summary.dataset.initialRoutes || '0', 10) || 0;

            const setStatus = (name, ready, readyLabel, pendingLabel) => {
                const target = summaryValue(name);

                if (!target) return;

                target.textContent = ready ? readyLabel : pendingLabel;
                target.classList.toggle('is-ready', ready);
                target.classList.toggle('is-pending', !ready);
            };

            const updateSummary = () => {
                const offerSelect = field('send-offer');
                const listSelect = field('send-lists');
                const visibleRoutes = field('send-ips-selected');
                const hiddenRoutes = field('send-ips');
                const header = field('campaign-header');
                const totalSend = field('send-total_send');
                const audienceCount = listSelect
                    ? listSelect.selectedOptions.length
                    : initialAudienceCount;
                const visibleRouteCount = visibleRoutes ? visibleRoutes.selectedOptions.length : 0;
                const hiddenRouteCount = hiddenRoutes ? hiddenRoutes.selectedOptions.length : 0;
                const routeCount = visibleRoutes || hiddenRoutes
                    ? Math.max(visibleRouteCount, hiddenRouteCount)
                    : initialRouteCount;
                const offerReady = offerSelect
                    ? Boolean(offerSelect.value)
                    : summary.dataset.initialOffer === 'true';
                const audienceReady = audienceCount > 0;
                const emailBodyReady = Boolean(field('campaign-body')?.value.trim() || field('send-html-asset')?.value);
                const emailReady = Boolean(header?.value.trim()) && emailBodyReady;
                const routeReady = routeCount > 0;
                const completeChecks = [offerReady, audienceReady, emailReady, routeReady].filter(Boolean).length;
                const readiness = Math.round((completeChecks / 4) * 100);
                const routeMode = field('send-route')?.selectedOptions[0]?.textContent.trim() || 'Routes';

                setStatus('offer-status', offerReady, 'Selected', 'Not selected');
                setStatus('audience-status', audienceReady, audienceCount + ' selected', 'Not selected');
                setStatus('email-status', emailReady, 'Content ready', 'Incomplete');
                setStatus('route-status', routeReady, routeCount + ' ' + routeMode.toLowerCase() + ' selected', 'Not selected');

                const audienceValue = summaryValue('audiences');
                const routesValue = summaryValue('routes');
                const sendValue = summaryValue('send-total');
                const readinessLabel = summaryValue('readiness-label');
                const readinessDetail = summaryValue('readiness-detail');
                const readinessBar = summaryValue('readiness-bar');
                const progress = summary.querySelector('[role="progressbar"]');

                if (audienceValue) audienceValue.textContent = String(audienceCount);
                if (routesValue) routesValue.textContent = String(routeCount);
                if (sendValue) sendValue.textContent = totalSend?.value || summary.dataset.initialSendTotal || '0';
                if (readinessLabel) readinessLabel.textContent = readiness + '%';
                if (readinessDetail) readinessDetail.textContent = completeChecks + ' of 4 setup checks complete';
                if (readinessBar) readinessBar.style.width = readiness + '%';
                if (progress) progress.setAttribute('aria-valuenow', String(readiness));
            };

            form.addEventListener('change', updateSummary);
            form.addEventListener('input', updateSummary);
            updateSummary();
        })();
    </script>
@endpush
