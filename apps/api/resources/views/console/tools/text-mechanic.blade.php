@extends('layouts.admin-shell')

@section('title', 'Text Mechanic')

@push('styles')
    <style>
        #text {
            display: block;
            width: 100%;
            margin: 0;
            padding: 14px 16px;
            font-family: 'JetBrains Mono', 'Fira Code', 'SFMono-Regular', Menlo, Monaco, Consolas, monospace;
            font-size: 0.875rem;
            line-height: 1.6;
            color: var(--command-os-text, #1e293b);
            background-color: var(--command-os-card-bg, #ffffff);
            border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.2));
            outline: none;
            resize: vertical;
            box-sizing: border-box;
            border-radius: 12px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            min-height: 480px;
        }
        #text:focus {
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.25);
        }
        html[data-command-theme='dark'] #text {
            background-color: #0b1033;
            color: #e2e8f0;
            border-color: rgba(139, 92, 246, 0.25);
        }
        .mechanic-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 10px;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.12));
        }
    </style>
@endpush

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-wrench"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">OPERATIONS TOOLKIT // DATA TRANSFORMATION</div>
                    <h1 class="command-os-report-hero-title">TEXT MECHANIC</h1>
                    <p class="command-os-report-hero-desc">Manipulate, sort, deduplicate, filter, and extract IPs from high-volume text and data streams.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    <span class="command-os-report-badge">TOOLKIT</span>
                </div>
            </div>
        </header>

        <!-- 2. Main Mechanic Surface -->
        <div class="command-os-table-card p-4" id="text-mechanic">
            <div class="mechanic-toolbar" id="sorting" aria-label="Text actions">
                <button id="asc" type="button" class="btn-command-action"><i class="fas fa-sort-alpha-down mr-1"></i> SORT ASC</button>
                <button id="desc" type="button" class="btn-command-action"><i class="fas fa-sort-alpha-up-alt mr-1"></i> SORT DESC</button>
                <button id="length" type="button" class="btn-command-action"><i class="fas fa-ruler-horizontal mr-1"></i> SORT LENGTH</button>
                <button id="random" type="button" class="btn-command-action"><i class="fas fa-random mr-1"></i> SORT RANDOM</button>
                <button id="rduplicate" type="button" class="btn-command-primary"><i class="fas fa-filter mr-1"></i> REMOVE DUPLICATE</button>
                <button id="ips" type="button" class="btn-command-primary"><i class="fas fa-network-wired mr-1"></i> EXTRACT IPS</button>
                <button id="sall" type="button" class="btn-command-action"><i class="fas fa-check-double mr-1"></i> SELECT ALL</button>
                <button id="copy" type="button" class="btn-command-action" data-clipboard-target="#text"><i class="fas fa-copy mr-1"></i> COPY</button>
                <button id="clear" type="button" class="btn-command-danger ml-auto"><i class="fas fa-trash-alt mr-1"></i> CLEAR</button>
            </div>
            
            <label class="sr-only" for="text">Text to process</label>
            <textarea id="text" wrap="off" rows="28" placeholder="PASTE OR TYPE YOUR DATA HERE..." spellcheck="false"></textarea>
            <div id="text-mechanic-status" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('alphalink/tools/text-mechanic.js') }}?v={{ substr(sha1_file(public_path('alphalink/tools/text-mechanic.js')), 0, 12) }}" defer></script>
@endpush
