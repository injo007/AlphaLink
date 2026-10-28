@php($isEncodingPopup = request()->routeIs('console.tools.encoding.popup', 'clean.console.tools.encoding.popup'))

@extends($isEncodingPopup ? 'layouts.encoding-popup' : 'layouts.dms-admin')

@section('title', 'Encoding - DMS')

@push('styles')
    <style>
        .encoding-tool-block .block-content { padding: 0; }
        .encoding-tool-block .nav-tabs-block { margin-bottom: 0; }
        .encoding-tool-block .tab-content { padding: 21px 20px 7px !important; }
        .encoding-tool-block .tab-content .form-group:last-child { margin-bottom: 0; }
        .encoding-main-block { margin-left: -13px; margin-right: -13px; margin-top: -12px; }
        .encoding-main-block .tab-content { padding-top: 17px !important; }
        .encoding-tool-block #encoding-string-panel .form-row {
            column-gap: 2.23%;
            display: grid;
            grid-template-columns: minmax(0, 49%) minmax(0, 14.8%) minmax(0, 14.8%) minmax(0, 7.2%);
            margin-left: 0;
            margin-right: 0;
        }
        .encoding-tool-block #encoding-string-panel .form-row > .form-group {
            flex: initial;
            max-width: none;
            padding-left: 0;
            padding-right: 0;
        }
        @media (max-width: 767.98px) {
            .encoding-tool-block #encoding-string-panel .form-row {
                column-gap: 16px;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
@endpush

@section('content')
    <div class="block block-rounded mb-0 encoding-tool-block{{ $isEncodingPopup ? ' encoding-popup-block' : ' encoding-main-block' }}">
        <div class="block-content">
            <ul class="nav nav-tabs nav-tabs-block" role="tablist" aria-label="Encoding modes">
                <li class="nav-item"><button class="nav-link active" type="button" id="encoding-string-tab" role="tab" aria-selected="true" aria-controls="encoding-string-panel" data-encoding-tab="string">STRING</button></li>
                <li class="nav-item"><button class="nav-link" type="button" id="encoding-ip-tab" role="tab" aria-selected="false" aria-controls="encoding-ip-panel" data-encoding-tab="ip">IP</button></li>
            </ul>
            <div class="tab-content pt-3">
                <section class="tab-pane active" id="encoding-string-panel" role="tabpanel" aria-labelledby="encoding-string-tab" data-encoding-panel="string">
                    <div class="form-row align-items-end">
                        <div class="form-group col-md-6"><label for="encoding-text">Your String</label><input class="form-control" id="encoding-text" placeholder="Your String"></div>
                        <div class="form-group col-md-2"><label for="encoding-schema">Schema :</label><select id="encoding-schema" class="form-control"><option value="Q" selected>Quoted-Printable</option><option value="HQ">Hex-Quoted-Printable</option><option value="B">Base64</option></select></div>
                        <div class="form-group col-md-2"><label for="encoding-charset">Charset :</label><select id="encoding-charset" class="form-control"><option>UTF-8</option><option>UTF-7</option><option>ISO-8859-1</option><option>ISO-8859-15</option><option>windows-1252</option><option value="US-ASCII">ASCII</option><option>CP1256</option></select></div>
                        <div class="form-group col-md-1"><button id="encoding-run" class="btn btn-primary btn-block" type="button" aria-busy="false">ENCODE</button></div>
                    </div>
                    <div class="form-group"><label for="encoding-result">Result</label><textarea class="form-control" id="encoding-result" rows="5" placeholder="Encoded String" readonly></textarea><small id="encoding-message" class="form-text text-muted" role="status"></small></div>
                </section>
                <section class="tab-pane d-none" id="encoding-ip-panel" role="tabpanel" aria-labelledby="encoding-ip-tab" data-encoding-panel="ip">
                    <div class="form-row align-items-end"><div class="form-group col-md-6"><label for="encoding-ip">Ip</label><input class="form-control" id="encoding-ip" placeholder="Your Ip" inputmode="decimal"></div><div class="form-group col-md-1"><button id="encoding-ip-run" class="btn btn-primary btn-block" type="button">ENCODE</button></div></div>
                    <div class="form-group"><label for="encoding-ip-result">Result</label><textarea class="form-control" id="encoding-ip-result" rows="7" placeholder="Encoded Ip" readonly></textarea><small id="encoding-ip-message" class="form-text text-muted" role="status"></small></div>
                </section>
            </div>
        </div>
    </div>
    <script>
        (() => {
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const encodeUrl = @json(route('console.tools.encoding.encode', $workspace->id));
            document.querySelectorAll('[data-encoding-tab]').forEach((tab) => tab.addEventListener('click', () => {
                document.querySelectorAll('[data-encoding-tab]').forEach((item) => {
                    const active = item === tab;
                    item.classList.toggle('active', active);
                    item.setAttribute('aria-selected', String(active));
                });
                document.querySelectorAll('[data-encoding-panel]').forEach((panel) => {
                    const active = panel.dataset.encodingPanel === tab.dataset.encodingTab;
                    panel.classList.toggle('active', active);
                    panel.classList.toggle('d-none', !active);
                });
            }));

            document.getElementById('encoding-run').addEventListener('click', async (event) => {
                const button = event.currentTarget;
                const message = document.getElementById('encoding-message');
                const result = document.getElementById('encoding-result');
                const text = document.getElementById('encoding-text').value;
                if (!text) { message.textContent = 'Enter a string to encode.'; return; }

                const setBusy = (busy) => {
                    button.disabled = busy;
                    button.setAttribute('aria-busy', String(busy));
                    button.innerHTML = busy
                        ? '<i class="fa fa-spinner fa-spin mr-1" aria-hidden="true"></i>ENCODE'
                        : 'ENCODE';
                };

                setBusy(true);
                result.value = '';
                message.textContent = 'Encoding…';
                try {
                    const response = await fetch(encodeUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token}, body: JSON.stringify({string: text, schema: document.getElementById('encoding-schema').value, charset: document.getElementById('encoding-charset').value})});
                    const data = await response.json();
                    if (!response.ok) { throw new Error(data.message || Object.values(data.errors || {}).flat().join(' ') || 'Encoding failed.'); }
                    result.value = data;
                    message.textContent = 'Encoding complete.';
                } catch (error) { message.textContent = error.message || 'Encoding failed.'; }
                finally { setBusy(false); }
            });

            function toInteger(ipAddress) {
                const values = ipAddress.split('.');
                return parseInt((16777216 * values[0])) + parseInt((65536 * values[1])) + parseInt((256 * values[2])) + parseInt(values[3]);
            }

            function toRadixPart(value, radix, width) {
                let result = parseInt(value).toString(radix);
                for (let index = 0; index < width; index++) {
                    if (result.length < width) result = `0${result}`;
                }
                return result;
            }

            function toHex(ipAddress) {
                const values = ipAddress.split('.');
                return `0x${values.map((value) => toRadixPart(value, 16, 2)).join('').toUpperCase()}`;
            }

            function toOcta(ipAddress) {
                return ipAddress.split('.').map((value) => toRadixPart(value, 8, 4)).join('.');
            }

            function toOctalHexadecimal(ipAddress) {
                const values = ipAddress.split('.');
                return `${toRadixPart(values[0], 8, 4)}.${toRadixPart(values[1], 8, 4)}.0x${toRadixPart(values[2], 16, 2).toUpperCase()}${toRadixPart(values[3], 16, 2).toUpperCase()}`;
            }

            function toDecimalHexadecimal(ipAddress) {
                const values = ipAddress.split('.');
                return `${values[0]}.${values[1]}.0x${toRadixPart(values[2], 16, 2).toUpperCase()}${toRadixPart(values[3], 16, 2).toUpperCase()}`;
            }

            function toDecimalOctal(ipAddress) {
                const values = ipAddress.split('.');
                return `${values[0]}.${values[1]}.${toRadixPart(values[2], 8, 4)}.${toRadixPart(values[3], 8, 4)}`;
            }

            document.getElementById('encoding-ip-run').addEventListener('click', () => {
                const ip = document.getElementById('encoding-ip').value;
                if (!ip) return;

                const output = document.getElementById('encoding-ip-result');
                output.value = `Dotted Decimal IP : ${ip}\nOctal IP : ${toOcta(ip)}\nInteger IP : ${toInteger(ip)}\nHexadecimal IP :${toHex(ip)}\nOctalHexa IP : ${toOctalHexadecimal(ip)}\nDecimalHexa IP : ${toDecimalHexadecimal(ip)}\nDecimalOctal IP : ${toDecimalOctal(ip)}`;
            });
        })();
    </script>
@endsection
