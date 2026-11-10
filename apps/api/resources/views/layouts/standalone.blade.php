<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self' data:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; form-action 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'">
    <title>@yield('title', 'Add Servers')</title>
    <script>
        (() => {
            const getInitialTheme = () => {
                try {
                    return window.parent?.document?.documentElement?.getAttribute('data-command-theme')
                        || localStorage.getItem('alphalink-command-os-theme')
                        || localStorage.getItem('alphalink_command_os_theme')
                        || 'light';
                } catch (e) {
                    return 'light';
                }
            };
            const applyTheme = (theme) => {
                document.documentElement.setAttribute('data-command-theme', theme);
                document.documentElement.classList.remove('command-theme-light', 'command-theme-dark');
                document.documentElement.classList.add('command-theme-' + theme);
            };
            const currentTheme = getInitialTheme();
            applyTheme(currentTheme);

            window.addEventListener('storage', (e) => {
                if (e.key === 'alphalink-command-os-theme' || e.key === 'alphalink_command_os_theme') {
                    applyTheme(e.newValue || 'light');
                }
            });

            // Also check parent observer if accessible
            try {
                if (window.parent && window.parent !== window && window.parent.document) {
                    const observer = new MutationObserver(() => {
                        const t = window.parent.document.documentElement.getAttribute('data-command-theme');
                        if (t) applyTheme(t);
                    });
                    observer.observe(window.parent.document.documentElement, { attributes: true, attributeFilter: ['data-command-theme'] });
                }
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="{{ asset('console-assets/fonts/inter.css') }}">
    <link rel="stylesheet" href="{{ asset('console-assets/plugins/bootstrap_select/bootstrap-select.css') }}">
    <link rel="stylesheet" href="{{ asset('console-assets/assets/css/theme.css') }}">
    @vite(['resources/css/command-os.css'])
    <style>
        body {
            background-color: transparent !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow-x: hidden;
        }
        #page-container {
            background: transparent !important;
            min-height: auto !important;
            padding: 0 !important;
        }
        #main-container {
            background: transparent !important;
            padding: 0 !important;
        }
        .content {
            padding: 0 !important;
            margin: 0 !important;
        }
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
            .content { padding: 0 !important; }
            .block-content { padding-left: .875rem; padding-right: .875rem; }
        }
    </style>
    @stack('styles')
</head>
<body class="command-os-shell command-os-standalone-popup">
    <div id="page-container">
        <main id="main-container">
            <div class="content">
                @yield('content')
            </div>
        </main>
    </div>
    <script src="{{ asset('console-assets/assets/js/script.js') }}"></script>
    <script src="{{ asset('console-assets/assets/js/app.js') }}"></script>
    <script src="{{ asset('console-assets/plugins/bootstrap_select/bootstrap-select.js') }}"></script>
    <script>
        (() => {
            const reportHeight = () => {
                try {
                    const h = Math.max(
                        document.body.scrollHeight,
                        document.documentElement.scrollHeight,
                        document.body.offsetHeight
                    );
                    window.parent?.postMessage({ type: 'alphalink-dialog-resize', height: h }, '*');
                } catch (e) {}
            };
            window.addEventListener('load', () => setTimeout(reportHeight, 50));
            window.addEventListener('resize', reportHeight);
            if (typeof ResizeObserver === 'function') {
                new ResizeObserver(reportHeight).observe(document.body);
            }
        })();
    </script>
    @stack('scripts')
</body>
</html>
