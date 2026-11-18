<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php($branding = \App\Models\ApplicationBranding::current())
    <title>@yield('title', 'Acceso') · {{ $branding->displayName() }}</title>
    <link rel="stylesheet" href="{{ asset('assets/base-admin/admin.css') }}?v={{ filemtime(public_path('assets/base-admin/admin.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/usb-tokens.css') }}?v={{ filemtime(public_path('assets/usb-tokens.css')) }}">
    <link rel="preload" href="{{ asset('brand/fonts/manrope-variable.ttf') }}" as="font" type="font/ttf" crossorigin>
    <link rel="stylesheet" href="{{ asset('assets/commerce-workspace.css') }}?v={{ filemtime(public_path('assets/commerce-workspace.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/usb-motion.css') }}?v={{ filemtime(public_path('assets/usb-motion.css')) }}">
    <script src="{{ asset('assets/usb-motion.js') }}?v={{ filemtime(public_path('assets/usb-motion.js')) }}" defer></script>
</head>
<body class="guest-body">
    <div class="guest-shell"><aside class="guest-story"><span>USB Stock / Espacio de trabajo</span><div><h2>De una idea<br>a una <em>gran<br>conexión.</em></h2><p>Catálogo, solicitudes y cotizaciones. Tu operación comercial, en un mismo lugar.</p></div><small>Administración de USB Stock</small></aside><div class="guest-main"><main class="guest-card" id="main-content">
        <a class="guest-brand" href="{{ route('admin.login') }}">
            @if($branding->logo)<img class="brand-logo" src="{{ route('branding.logo') }}?v={{ substr($branding->logo->checksum, 0, 16) }}" alt="USB Stock">@else<img class="brand-logo" src="{{ asset('brand/logo.webp') }}" width="220" height="50" alt="USB Stock">@endif
            <span><strong>{{ $branding->displayName() }}</strong><small>Administración</small></span>
        </a>
        @yield('content')
        <a class="guest-back" href="{{ route('shop.home') }}">← Volver al sitio público</a>
    </main></div></div>
</body>
</html>
