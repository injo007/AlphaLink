@extends('layouts.dms-reference')

@section('title', 'Share Creatives - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">Share Creatives</h3>
        </div>
        <div class="block-content">
            <div class="row justify-content-center py-1">
                <div class="col-sm-10">
                    <div class="form-group">
                        <h6>Selected Offers:</h6>
                        @foreach ($offers as $offer)
                            <span class="badge badge-info p-1">{{ $offer->id }} - {{ $offer->name }}</span>
                        @endforeach
                    </div>

                    <div class="alert alert-info" role="status">
                        AlphaLink copies each selected offer’s creative and unsubscribe image files into <code>/var/mini/public/{offer-hash}</code>. DMS derives the folder from the offer ID with the workspace FOLDERS HASH TOKEN; files already on the server are left unchanged.
                    </div>

                    @unless ($hashTokenConfigured)
                        <div class="alert alert-warning" role="alert">
                            Configure <strong>FOLDERS HASH TOKEN</strong> in <a href="{{ route('console.app-settings.index', $workspace->id) }}">App Settings</a> before sharing. The share action stays blocked until then, so it cannot create an incompatible folder or open an SSH session.
                        </div>
                    @endunless

                    @if ($servers->isEmpty())
                        <div class="alert alert-warning" role="status">
                            No active, installed servers with verified SSH access are available in this workspace.
                        </div>
                    @endif

                    <div id="share-assets-error" class="alert alert-danger d-none" role="alert" aria-live="assertive"></div>
                    <div class="form-group">
                        <label for="share-assets-servers">Select Servers:</label>
                        <select id="share-assets-servers" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-max-options="5" multiple size="6" aria-describedby="share-assets-server-help" @disabled($servers->isEmpty())>
                            @foreach ($servers as $server)
                                <option value="{{ $server->id }}">{{ $server->name }} | {{ substr((string) $server->id, 0, 8) }}</option>
                            @endforeach
                        </select>
                        <small id="share-assets-server-help" class="form-text text-muted">Choose one to five servers. AlphaLink verifies the saved host key and SSH credential before copying files.</small>
                    </div>

                    <div class="form-group">
                        <button id="share-assets-submit" class="btn btn-block btn-info" type="button" @disabled($servers->isEmpty() || ! $hashTokenConfigured)>SHARE</button>
                    </div>

                    <div class="table-responsive">
                        <table id="share-assets-results" class="table table-sm table-bordered d-none">
                            <thead>
                                <tr><th scope="col" class="w-25">Server</th><th scope="col">Result</th></tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div id="share-assets-status" class="sr-only" role="status" aria-live="polite"></div>
                </div>
            </div>
        </div>
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
