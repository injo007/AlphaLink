@php($isEncodingPopup = request()->routeIs('console.tools.encoding.popup', 'clean.console.tools.encoding.popup'))

@extends($isEncodingPopup ? 'layouts.encoding-popup' : 'layouts.admin-shell')

@section('title', 'Encoding')

@push('styles')
    <style>
        .encoding-tabs {
            display: inline-flex;
            gap: 8px;
            padding: 4px;
            background: rgba(139, 92, 246, 0.06);
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.12));
        }
        .encoding-tab-btn {
            background: transparent;
            border: none;
            color: var(--command-os-text-muted, #64748b);
            font-size: 0.8125rem;
            font-weight: 700;
            padding: 8px 18px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            letter-spacing: 0.05em;
        }
        .encoding-tab-btn.active {
            background: linear-gradient(135deg, #8b5cf6 0%, #6366f1 100%);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.35);
        }
        .encoding-result-area {
            font-family: 'JetBrains Mono', 'Fira Code', 'SFMono-Regular', Menlo, Monaco, Consolas, monospace;
            font-size: 0.875rem;
            line-height: 1.6;
            color: var(--command-os-text, #1e293b);
            background: var(--command-os-card-bg, #ffffff);
            border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.2));
            border-radius: 10px;
            padding: 12px 14px;
            width: 100%;
            outline: none;
            resize: vertical;
        }
        html[data-command-theme='dark'] .encoding-tabs {
            background: rgba(15, 23, 42, 0.6);
            border-color: rgba(139, 92, 246, 0.22);
        }
        html[data-command-theme='dark'] .encoding-result-area {
            background: #0b1033;
            color: #e2e8f0;
            border-color: rgba(139, 92, 246, 0.25);
        }
    </style>
@endpush

@section('content')
    <div class="{{ $isEncodingPopup ? 'p-3' : 'command-os-report-page' }}">
        @unless ($isEncodingPopup)
            <!-- 1. Hero Header -->
            <header class="command-os-report-hero mb-4">
                <div class="command-os-report-hero-left">
                    <div class="command-os-report-hero-icon" aria-hidden="true">
                        <i class="fas fa-pencil-ruler"></i>
                    </div>
                    <div>
                        <div class="command-os-report-hero-eyebrow">OPERATIONS TOOLKIT // ENCODING ENGINE</div>
                        <h1 class="command-os-report-hero-title">STRING &amp; IP ENCODER</h1>
                        <p class="command-os-report-hero-desc">Encode strings into Quoted-Printable, Hex-QP, or Base64 schemas and convert IP addresses across formats.</p>
                    </div>
                </div>
                <div class="command-os-report-hero-right">
                    <div class="command-os-report-hero-meta">
                        <span class="command-os-report-badge">TOOLKIT</span>
                    </div>
                </div>
            </header>
        @endunless

        <!-- 2. Form Surface Card -->
        <div class="command-os-table-card p-4">
            <div class="encoding-tabs" role="tablist" aria-label="Encoding modes">
                <button class="encoding-tab-btn active" type="button" id="encoding-string-tab" role="tab" aria-selected="true" aria-controls="encoding-string-panel" data-encoding-tab="string">
                    <i class="fas fa-font mr-1"></i> STRING ENCODER
                </button>
                <button class="encoding-tab-btn" type="button" id="encoding-ip-tab" role="tab" aria-selected="false" aria-controls="encoding-ip-panel" data-encoding-tab="ip">
                    <i class="fas fa-network-wired mr-1"></i> IP CONVERTER
                </button>
            </div>

            <div class="tab-content">
                <!-- String Panel -->
                <section class="tab-pane active" id="encoding-string-panel" role="tabpanel" aria-labelledby="encoding-string-tab" data-encoding-panel="string">
                    <div class="row align-items-end mb-3">
                        <div class="col-md-5 mb-3 mb-md-0">
                            <label class="command-form-label" for="encoding-text"><i class="fas fa-i-cursor mr-1 text-purple"></i> Your String:</label>
                            <input class="command-form-control" id="encoding-text" placeholder="Enter text to encode..." style="height: 38px;">
                        </div>
                        <div class="col-md-3 mb-3 mb-md-0">
                            <label class="command-form-label" for="encoding-schema"><i class="fas fa-cog mr-1 text-purple"></i> Schema:</label>
                            <div class="command-select-wrapper">
                                <select id="encoding-schema" class="command-form-control command-select" style="height: 38px;">
                                    <option value="Q" selected>Quoted-Printable</option>
                                    <option value="HQ">Hex-Quoted-Printable</option>
                                    <option value="B">Base64</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2 mb-3 mb-md-0">
                            <label class="command-form-label" for="encoding-charset"><i class="fas fa-globe mr-1 text-purple"></i> Charset:</label>
                            <div class="command-select-wrapper">
                                <select id="encoding-charset" class="command-form-control command-select" style="height: 38px;">
                                    <option>UTF-8</option>
                                    <option>UTF-7</option>
                                    <option>ISO-8859-1</option>
                                    <option>ISO-8859-15</option>
                                    <option>windows-1252</option>
                                    <option value="US-ASCII">ASCII</option>
                                    <option>CP1256</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <button id="encoding-run" class="btn-command-primary w-100" type="button" aria-busy="false" style="height: 38px;">
                                <i class="fas fa-bolt mr-1"></i> ENCODE
                            </button>
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label class="command-form-label" for="encoding-result"><i class="fas fa-check-circle mr-1 text-purple"></i> Result:</label>
                        <textarea class="encoding-result-area" id="encoding-result" rows="6" placeholder="Encoded string output will appear here..." readonly></textarea>
                        <small id="encoding-message" class="form-text text-muted mt-2" role="status"></small>
                    </div>
                </section>

                <!-- IP Panel -->
                <section class="tab-pane d-none" id="encoding-ip-panel" role="tabpanel" aria-labelledby="encoding-ip-tab" data-encoding-panel="ip">
                    <div class="row align-items-end mb-3">
                        <div class="col-md-8 mb-3 mb-md-0">
                            <label class="command-form-label" for="encoding-ip"><i class="fas fa-network-wired mr-1 text-purple"></i> IPv4 Address:</label>
                            <input class="command-form-control" id="encoding-ip" placeholder="e.g. 192.168.1.1" inputmode="decimal" style="height: 38px;">
                        </div>
                        <div class="col-md-4">
                            <button id="encoding-ip-run" class="btn-command-primary w-100" type="button" style="height: 38px;">
                                <i class="fas fa-sync mr-1"></i> CONVERT IP
                            </button>
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label class="command-form-label" for="encoding-ip-result"><i class="fas fa-code mr-1 text-purple"></i> Representations:</label>
                        <textarea class="encoding-result-area" id="encoding-ip-result" rows="8" placeholder="Converted IP formats (Octal, Hex, Integer, etc.) will appear here..." readonly></textarea>
                        <small id="encoding-ip-message" class="form-text text-muted mt-2" role="status"></small>
                    </div>
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
                        : '<i class="fas fa-bolt mr-1"></i> ENCODE';
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
                output.value = `Dotted Decimal IP : ${ip}\nOctal IP : ${toOcta(ip)}\nInteger IP : ${toInteger(ip)}\nHexadecimal IP : ${toHex(ip)}\nOctalHexa IP : ${toOctalHexadecimal(ip)}\nDecimalHexa IP : ${toDecimalHexadecimal(ip)}\nDecimalOctal IP : ${toDecimalOctal(ip)}`;
            });
        })();
    </script>
@endsection
