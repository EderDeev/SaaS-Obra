<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="app-name" content="{{ config('app.name', 'SigWorks') }}">
        <meta name="theme-color" content="#2563eb">
        <link rel="icon" type="image/svg+xml" href="{{ asset('brand/sigworks-mark.svg') }}">
        <link rel="apple-touch-icon" href="{{ asset('brand/sigworks-mark.png') }}">

        <title inertia>{{ config('app.name', 'SigWorks') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
