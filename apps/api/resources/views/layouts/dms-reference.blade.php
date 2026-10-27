<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self' data:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; form-action 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'">
    <title>@yield('title', 'Add Servers - DMS')</title>
    <link rel="stylesheet" href="{{ asset('dms-reference/plugins/bootstrap_select/bootstrap-select.css') }}">
    <link rel="stylesheet" href="{{ asset('dms-reference/fonts/inter.css') }}">
    <link rel="stylesheet" href="{{ asset('dms-reference/assets/css/theme.css') }}">
    <style>
        body { background-color: #fff; }
        .content { padding: 0 .875rem; }
        .form-control.selectpicker { background-color: #f6f7f8; border-color: #f6f7f8; font-weight: 600; }
        .form-control[type="date"] { min-height: calc(1.5em + .75rem + 2px); }
        .server-dialog { border: 0; border-radius: .25rem; box-shadow: 0 8px 28px rgb(52 58 64 / 22%); max-width: 560px; padding: 1.5rem; width: calc(100% - 2rem); }
        .server-dialog::backdrop { background: rgb(52 58 64 / 45%); }
        .server-dialog h2 { font-size: 1.25rem; margin-bottom: .75rem; }
        .server-dialog p { margin-bottom: .75rem; }
        .server-dialog-pin { border-top: 1px solid #e1e6e9; margin-top: 1rem; padding-top: .75rem; }
        .server-dialog-pin code { display: block; margin-bottom: .5rem; overflow-wrap: anywhere; }
        .server-dialog-actions { display: flex; gap: .5rem; justify-content: flex-end; margin-top: 1rem; }
        .server-dialog-actions .btn { min-width: 112px; }
        .server-form-actions { margin-top: .25rem; }
        .server-form-actions .btn + .btn { margin-top: .5rem; }
        @media (max-width: 575.98px) {
            .content { padding: 0 .5rem; }
            .block-content { padding-left: .875rem; padding-right: .875rem; }
        }
    </style>
    @stack('styles')
</head>
<body>
    <div id="page-container">
        <main id="main-container">
            <div class="content p-3">
                @yield('content')
            </div>
        </main>
    </div>
    <script src="{{ asset('dms-reference/assets/js/script.js') }}"></script>
    <script src="{{ asset('dms-reference/assets/js/app.js') }}"></script>
    <script src="{{ asset('dms-reference/plugins/bootstrap_select/bootstrap-select.js') }}"></script>
    @stack('scripts')
</body>
</html>
