<div class="form-group campaign-route-container mb-3">
    <div class="campaign-route-pools-heading">
        <div>
            <i class="fas fa-share-alt campaign-field-icon" aria-hidden="true"></i>
            <span>Delivery routes</span>
            <small>Choose the outbound route or accounts for this draft.</small>
        </div>
        <span class="campaign-route-kind" id="send-route-choice-heading">{{ match ($selectedRoute ?? 'ip') { 'smtp' => 'SMTP routes', 'vmta' => 'VMTAs', default => 'IPs' } }}</span>
    </div>

    <select id="send-ips" name="{{ $inputName }}" class="sr-only" aria-hidden="true" tabindex="-1" multiple>
        @foreach ($sendOptions['ips'] as $ip)
            <option value="{{ $ip['id'] }}" data-server-id="{{ $ip['server_id'] }}" @selected(in_array((string) $ip['id'], $selectedIps, true))>{{ $ip['ip'] }} | {{ strtoupper($ip['type']) }} | {{ $ip['dns'] ?: 'no DNS' }} — server {{ $ip['server_id'] }}</option>
        @endforeach
    </select>

    <div class="campaign-route-picker">
        <div class="campaign-route-selector">
            <div class="campaign-route-pane-heading">
                <label for="available-send-ips">
                    <span id="available-send-ips-label">Available {{ match ($selectedRoute ?? 'ip') { 'smtp' => 'SMTP routes', 'vmta' => 'VMTAs', default => 'IPs' } }}</span>
                    <span class="campaign-route-count" id="available-send-ips-count">{{ count($sendOptions['ips']) - count($selectedIps) }}</span>
                </label>
                <button type="button" class="campaign-editor-icon-button" id="copy-available-ips" data-copy-ip-list="available" title="Copy available routes" aria-label="Copy available routes">
                    <i class="fas fa-copy" aria-hidden="true"></i>
                </button>
            </div>
            <input class="form-control form-control-sm campaign-route-filter" id="available-send-ips-filter" type="search" placeholder="Filter available IPs" aria-label="Filter available IPs">
            <select id="available-send-ips" class="form-control" multiple size="10" aria-label="Available IPs" aria-describedby="send-ips-help">
                @foreach ($sendOptions['ips'] as $ip)
                    @if (! in_array((string) $ip['id'], $selectedIps, true))
                        <option value="{{ $ip['id'] }}" data-server-id="{{ $ip['server_id'] }}">{{ $ip['ip'] }} | {{ strtoupper($ip['type']) }} | {{ $ip['dns'] ?: 'no DNS' }} — server {{ $ip['server_id'] }}</option>
                    @endif
                @endforeach
            </select>
        </div>

        <div class="campaign-route-transfer-controls" role="group" aria-label="Move routes between lists">
            <button type="button" class="btn btn-outline-secondary btn-sm mb-1" id="mailer-ip-add-all" data-ip-move="all-right" aria-label="Move all IPs to selected" title="Move all to selected">
                <i class="fas fa-angle-double-right" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm mb-1" id="mailer-ip-add" data-ip-move="right" aria-label="Move selected IPs to selected" title="Move selected to selected">
                <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm mb-1" id="mailer-ip-remove" data-ip-move="left" aria-label="Move IPs back to available" title="Move selected back">
                <i class="fas fa-chevron-left" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="mailer-ip-remove-all" data-ip-move="all-left" aria-label="Move all IPs back to available" title="Move all back">
                <i class="fas fa-angle-double-left" aria-hidden="true"></i>
            </button>
        </div>

        <div class="campaign-route-selector">
            <div class="campaign-route-pane-heading">
                <label for="selected-send-ips">
                    <span id="selected-send-ips-label">Selected {{ match ($selectedRoute ?? 'ip') { 'smtp' => 'SMTP routes', 'vmta' => 'VMTAs', default => 'IPs' } }}</span>
                    <span class="campaign-route-count" id="selected-send-ips-count">{{ count($selectedIps) }}</span>
                </label>
                <button type="button" class="campaign-editor-icon-button" id="copy-selected-ips" data-copy-ip-list="selected" title="Copy selected routes" aria-label="Copy selected routes">
                    <i class="fas fa-copy" aria-hidden="true"></i>
                </button>
            </div>
            <input class="form-control form-control-sm campaign-route-filter" id="selected-send-ips-filter" type="search" placeholder="Filter selected IPs" aria-label="Filter selected IPs">
            <select id="selected-send-ips" class="form-control" multiple size="10" aria-label="Selected IPs" aria-describedby="send-ips-help">
                @foreach ($sendOptions['ips'] as $ip)
                    @if (in_array((string) $ip['id'], $selectedIps, true))
                        <option value="{{ $ip['id'] }}" data-server-id="{{ $ip['server_id'] }}">{{ $ip['ip'] }} | {{ strtoupper($ip['type']) }} | {{ $ip['dns'] ?: 'no DNS' }} — server {{ $ip['server_id'] }}</option>
                    @endif
                @endforeach
            </select>
        </div>
    </div>

    <div class="d-flex align-items-center justify-content-between mt-2">
        <small id="send-ips-help" class="form-text text-muted mb-0">Double-click an entry to transfer between lists. You can also filter or use the transfer buttons.</small>
        <small id="send-ips-empty" class="form-text text-danger mb-0" @if ($sendOptions['ips'] !== []) hidden @endif><i class="fas fa-exclamation-triangle mr-1"></i>No IPs or MTAs are available for the selected servers and route.</small>
    </div>
</div>
