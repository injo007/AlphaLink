<div class="form-group mailer-ip-selector">
    <div class="row align-items-end">
        <div class="col-md-5">
            <label for="available-send-ips">IPs</label>
            <select id="available-send-ips" class="form-control" multiple size="8" aria-label="Available IPs or MTAs" aria-describedby="send-ips-help">
                @foreach ($sendOptions['ips'] as $ip)
                    @if (! in_array((string) $ip['id'], $selectedIps, true))
                        <option value="{{ $ip['id'] }}" data-server-id="{{ $ip['server_id'] }}">{{ $ip['ip'] }} | {{ strtoupper($ip['type']) }} | {{ $ip['dns'] ?: 'no DNS' }} — server {{ $ip['server_id'] }}</option>
                    @endif
                @endforeach
            </select>
        </div>
        <div class="col-md-2 mailer-ip-selector-actions" role="group" aria-label="Move IPs between lists">
            <button type="button" class="btn btn-alt-secondary" id="mailer-ip-add" title="Add selected IPs" aria-label="Add selected IPs"><i class="fas fa-chevron-right"></i></button>
            <button type="button" class="btn btn-alt-secondary" id="mailer-ip-add-all" title="Add all IPs" aria-label="Add all IPs"><i class="fas fa-angle-double-right"></i></button>
            <button type="button" class="btn btn-alt-secondary" id="mailer-ip-remove" title="Remove selected IPs" aria-label="Remove selected IPs"><i class="fas fa-chevron-left"></i></button>
            <button type="button" class="btn btn-alt-secondary" id="mailer-ip-remove-all" title="Remove all selected IPs" aria-label="Remove all selected IPs"><i class="fas fa-angle-double-left"></i></button>
        </div>
        <div class="col-md-5">
            <label for="selected-send-ips">Selected Ips <span class="text-muted">(<span id="selected-send-ips-count">{{ count($selectedIps) }}</span>)</span></label>
            <select id="selected-send-ips" class="form-control" multiple size="8" aria-label="Selected IPs or MTAs" aria-describedby="send-ips-help">
                @foreach ($sendOptions['ips'] as $ip)
                    @if (in_array((string) $ip['id'], $selectedIps, true))
                        <option value="{{ $ip['id'] }}" data-server-id="{{ $ip['server_id'] }}">{{ $ip['ip'] }} | {{ strtoupper($ip['type']) }} | {{ $ip['dns'] ?: 'no DNS' }} — server {{ $ip['server_id'] }}</option>
                    @endif
                @endforeach
            </select>
        </div>
    </div>
    <small id="send-ips-help" class="form-text text-muted">Choose sender IPs or MTAs from the selected servers. This only saves the draft selection.</small>
    <small id="send-ips-empty" class="form-text text-muted" @if ($sendOptions['ips'] !== []) hidden @endif>No IPs or MTAs are available for the selected servers and route.</small>
    <select id="send-ips" name="{{ $inputName }}" multiple hidden aria-hidden="true" tabindex="-1">
        @foreach ($sendOptions['ips'] as $ip)
            <option value="{{ $ip['id'] }}" data-server-id="{{ $ip['server_id'] }}" @selected(in_array((string) $ip['id'], $selectedIps, true))>{{ $ip['ip'] }} | {{ strtoupper($ip['type']) }} | {{ $ip['dns'] ?: 'no DNS' }} — server {{ $ip['server_id'] }}</option>
        @endforeach
    </select>
</div>
