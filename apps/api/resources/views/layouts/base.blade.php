<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>@yield('title', 'AlphaLink') · AlphaLink</title>
        @if (request()->routeIs('login'))
            <script>
                try {
                    document.documentElement.dataset.commandTheme = localStorage.getItem('alphalink-command-os-theme') === 'dark' ? 'dark' : 'light';
                } catch {
                    document.documentElement.dataset.commandTheme = 'light';
                }
            </script>
        @endif
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        @yield('body')
    </body>
</html>
