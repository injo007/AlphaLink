@extends('layouts.standalone')

@section('title', 'Share Creatives')

@section('content')
<div class="command-os-settings-card m-2 p-3 p-md-4">
    <header class="command-os-card-header mb-3">
        <div class="command-os-card-title">
            <span class="command-os-card-icon icon-purple" aria-hidden="true"><i class="fas fa-share-alt"></i></span>
            <div>
                <h2>SHARE CREATIVES</h2>
                <p>Deploy offer creatives and unsubscribe assets to remote delivery servers</p>
            </div>
        </div>
    </header>

    <div class="form-group mb-3">
        <label class="font-size-xs font-w700 text-uppercase text-muted"><i class="fas fa-tags text-purple mr-1"></i> Target Offers:</label>
        <div class="d-flex flex-wrap gap-1">
            @foreach ($offers as $offer)
                <span class="badge badge-light border text-purple px-2 py-1 font-mono font-w700 mr-1 mb-1">#{{ $offer->id }} &middot; {{ $offer->name }}</span>
            @endforeach
        </div>
    </div>

    <div class="alert alert-info py-2 font-size-sm mb-3" role="status">
        <i class="fas fa-info-circle mr-1"></i> AlphaLink copies each selected offer’s creative and unsubscribe image files into <code>/var/mini/public/{offer-hash}</code>. Folder names are derived from the offer ID and the saved folder hash token; existing files are preserved.
    </div>

    @unless ($hashTokenConfigured)
        <div class="alert alert-warning py-2 font-size-sm mb-3" role="alert">
            <i class="fas fa-exclamation-triangle mr-1"></i> Configure <strong>FOLDERS HASH TOKEN</strong> in <a href="{{ route('console.app-settings.index', $workspace->id) }}" class="font-w700">App Settings</a> before sharing. The share action stays blocked until then.
        </div>
    @endunless

    @if ($servers->isEmpty())
        <div class="alert alert-warning py-2 font-size-sm mb-3" role="status">
            <i class="fas fa-exclamation-circle mr-1"></i> No active, installed servers with verified SSH access are available in this workspace.
        </div>
    @endif

    <div id="share-assets-error" class="alert alert-danger d-none py-2 font-size-sm mb-3" role="alert" aria-live="assertive"></div>

    <div class="form-group mb-3">
        <label for="share-assets-servers" class="font-size-xs font-w700 text-uppercase text-muted"><i class="fas fa-server text-purple mr-1"></i> Select Servers (max 5):</label>
        <div class="command-input-wrap">
            <select id="share-assets-servers" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-max-options="5" multiple size="6" aria-describedby="share-assets-server-help" @disabled($servers->isEmpty())>
                @foreach ($servers as $server)
                    <option value="{{ $server->id }}">{{ $server->name }} | {{ substr((string) $server->id, 0, 8) }}</option>
                @endforeach
            </select>
        </div>
        <small id="share-assets-server-help" class="form-text text-muted font-size-xs mt-1">AlphaLink verifies the saved host key and SSH credential before copying files.</small>
    </div>

    <div class="form-group mb-3">
        <button id="share-assets-submit" class="command-os-btn-filter w-100" type="button" @disabled($servers->isEmpty() || ! $hashTokenConfigured)>
            <i class="fas fa-paper-plane mr-2"></i>SHARE TO SELECTED SERVERS
        </button>
    </div>

    <div class="table-responsive">
        <table id="share-assets-results" class="table table-sm table-hover table-striped w-100 d-none">
            <thead>
                <tr><th scope="col" class="w-25">Server</th><th scope="col">Result</th></tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
    <div id="share-assets-status" class="sr-only" role="status" aria-live="polite"></div>
</div>
@endsection

@push('scripts')
    <script>
        (() => {
            const offers = @json($offers->pluck('id')->map(fn ($id) => (int) $id)->values());
            const url = @json(route('console.offers.share.send', $workspace->id));
            const select = document.querySelector('#share-assets-servers');
            const button = document.querySelector('#share-assets-submit');
            const error = document.querySelector('#share-assets-error');
            const status = document.querySelector('#share-assets-status');
            const table = document.querySelector('#share-assets-results');
            const body = table.querySelector('tbody');
            if (window.jQuery?.fn?.selectpicker) window.jQuery(select).selectpicker();

            const showError = (message) => {
                error.textContent = message;
                error.classList.remove('d-none');
                status.textContent = message;
            };

            const drawPendingRows = (selectedOptions) => {
                body.replaceChildren();
                for (const option of selectedOptions) {
                    const row = document.createElement('tr');
                    const server = document.createElement('td');
                    const result = document.createElement('td');
                    server.textContent = option.textContent.trim();
                    result.textContent = 'Sharing…';
                    result.setAttribute('data-server-id', option.value);
                    row.append(server, result);
                    body.append(row);
                }
                table.classList.remove('d-none');
            };

            button.addEventListener('click', async () => {
                const selectedOptions = [...select.selectedOptions];
                if (selectedOptions.length < 1 || selectedOptions.length > 5) {
                    showError('Select between one and five servers.');
                    return;
                }

                error.classList.add('d-none');
                error.textContent = '';
                drawPendingRows(selectedOptions);
                button.disabled = true;
                button.textContent = 'SHARING…';
                status.textContent = 'Sharing offer assets with the selected servers.';

                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ offers, servers: selectedOptions.map((option) => option.value) }),
                    });
                    const contentType = response.headers.get('content-type') || '';
                    const resultRows = contentType.includes('application/json') ? await response.json() : null;
                    if (!response.ok || !Array.isArray(resultRows)) {
                        const message = resultRows?.data || resultRows?.message || Object.values(resultRows?.errors ?? {}).flat().join(' ');
                        throw new Error(message || 'AlphaLink could not share the selected offer assets.');
                    }

                    for (const result of resultRows) {
                        const cell = body.querySelector(`[data-server-id="${CSS.escape(String(result.id))}"]`);
                        if (!cell) continue;
                        cell.textContent = result.data;
                        cell.classList.add(result.status ? 'text-success' : 'text-danger', 'font-weight-bold');
                    }
                    status.textContent = resultRows.every((result) => result.status)
                        ? 'Offer assets were shared to all selected servers.'
                        : 'Sharing finished with one or more server errors. Review each result before retrying.';
                } catch (failure) {
                    showError(failure instanceof Error ? failure.message : 'AlphaLink could not share the selected offer assets.');
                } finally {
                    button.disabled = false;
                    button.textContent = 'SHARE';
                }
            });
        })();
    </script>
@endpush
