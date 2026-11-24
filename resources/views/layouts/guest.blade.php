<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php($branding = \App\Models\ApplicationBranding::current())
    <title>@yield('title', 'Acceso') &middot; {{ $branding->displayName() }}</title>
    <link rel="stylesheet" href="{{ asset('assets/base-admin/admin.css') }}?v={{ filemtime(public_path('assets/base-admin/admin.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/commerce-admin.css') }}?v={{ filemtime(public_path('assets/commerce-admin.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/usb-tokens.css') }}?v={{ filemtime(public_path('assets/usb-tokens.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/commerce-workspace.css') }}?v={{ filemtime(public_path('assets/commerce-workspace.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/admin-guest.css') }}?v={{ filemtime(public_path('assets/admin-guest.css')) }}">
</head>
<body class="guest-body">
    <main class="guest-card" id="main-content">
        <a class="guest-brand" href="{{ route('admin.login') }}">
            @if($branding->logo)<img class="brand-logo" src="{{ route('branding.logo') }}?v={{ substr($branding->logo->checksum, 0, 16) }}" alt="USB Stock">@else<img class="brand-logo" src="{{ asset('brand/logo.webp') }}" width="220" height="50" alt="USB Stock">@endif
        </a>
        @yield('content')
    </main>
</body>
</html>
