<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self' data:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; form-action 'self'; object-src 'none'; base-uri 'self'">
    <title>@yield('title', 'Encoding')</title>
    <link rel="stylesheet" href="{{ asset('dms-reference/fonts/inter.css') }}">
    <link rel="stylesheet" href="{{ asset('dms-reference/assets/css/theme.css') }}">
    @stack('styles')
    <style>
        body.encoding-popup-page { background: #fff; padding: 14px; }
    </style>
</head>
<body class="encoding-popup-page">
    @yield('content')
</body>
</html>
