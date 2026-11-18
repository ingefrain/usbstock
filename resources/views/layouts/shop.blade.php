<!doctype html>
<html lang="es-MX">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Memorias USB para tu marca') · USB Stock</title>
    <meta name="description" content="@yield('description', 'Explora memorias USB, consulta disponibilidad y solicita una cotización para tu proyecto.')">
    <meta name="robots" content="{{ app()->environment('production') && !request()->routeIs('shop.request*') && !array_intersect(['q','category','capacity','available','page','cursor'], array_keys(request()->query())) ? 'index, follow' : 'noindex, follow' }}">
    <link rel="canonical" href="{{ url()->current() }}"><meta property="og:title" content="@yield('title', 'Memorias USB para tu marca') · USB Stock"><meta property="og:type" content="website"><meta property="og:image" content="{{ asset('brand/catalog-hero.jpg') }}">
    <link rel="stylesheet" href="{{ asset('assets/usb-tokens.css') }}?v={{ filemtime(public_path('assets/usb-tokens.css')) }}">
    <link rel="preload" href="{{ asset('brand/fonts/manrope-variable.ttf') }}" as="font" type="font/ttf" crossorigin>
    @vite(['resources/css/app.css','resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('assets/usb-motion.css') }}?v={{ filemtime(public_path('assets/usb-motion.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/usb-selection.css') }}?v={{ filemtime(public_path('assets/usb-selection.css')) }}">
    <script src="{{ asset('assets/usb-motion.js') }}?v={{ filemtime(public_path('assets/usb-motion.js')) }}" defer></script>
    <noscript><style>@media(max-width:1099px){.shop .mobile-nav{display:block!important;position:static!important;max-height:none!important}}</style></noscript>
    @stack('shop-head')
</head>
<body class="shop theme-b" data-cart-state="{{ route('shop.cart.state') }}" data-cart-revision="{{ (int) session('commerce_cart_revision', 0) }}">
<a class="skip-link" href="#main">Saltar al contenido</a>
<x-shop.development-notice />
<header class="site-header">
    <div class="header-inner">
        <a @if(request()->routeIs('shop.home')) data-hero-step="0" @endif class="brand" href="{{ route('shop.home') }}"><img src="{{ asset('brand/logo.webp') }}" width="220" height="50" alt="USB Stock"></a>
        <nav class="desktop-nav" aria-label="Principal"><a href="{{ route('shop.catalog') }}" @if(request()->routeIs('shop.catalog','shop.category','shop.product')) aria-current="page" @endif>Catálogo</a><a href="{{ route('shop.home') }}#como-cotizar">Cómo cotizar</a><a href="{{ route('shop.info') }}">Contacto</a></nav>
        <a class="quote-nav" href="{{ route('shop.request') }}"><x-shop.icon name="bag"/><span>Mi cotización</span><b data-cart-count>{{ session()->has('quote_request_receipt') ? 0 : count(session('commerce_cart', [])) }}</b></a>
        <button class="mobile-menu" aria-label="Abrir navegación" aria-controls="mobile-nav" aria-expanded="false" hidden><x-shop.icon name="menu"/></button>
    </div>
    <x-shop.category-nav />
    <div id="mobile-nav" class="mobile-nav"><nav aria-label="Navegación móvil"><a href="{{ route('shop.catalog') }}">Todo el catálogo</a><a href="{{ route('shop.home') }}#como-cotizar">Cómo cotizar</a><a href="{{ route('shop.info') }}">Contacto</a></nav><x-shop.category-nav :mobile="true" /></div>
</header>
<main id="main" tabindex="-1">
    @if($errors->any())<div class="wrap"><div class="notice error" role="alert"><strong>Revisa la información</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
    @yield('content')
</main>
<footer id="catalog-footer" class="shop-footer" tabindex="-1">
    <div class="footer-top"><div><img src="{{ asset('brand/logo.webp') }}" width="190" height="43" alt="USB Stock"><p>Una memoria.<br>Muchas posibilidades.</p></div><nav aria-label="Catálogo"><h2>Encuentra tu USB</h2><a href="{{ route('shop.catalog') }}">Todos los modelos</a><a href="{{ route('shop.catalog') }}">Consultar disponibilidad</a></nav><nav aria-label="Atención"><h2>Tu próximo proyecto</h2><a href="{{ route('shop.home') }}#como-cotizar">Cómo cotizar</a><a href="{{ route('shop.info') }}">Atención comercial</a><a href="{{ route('admin.login') }}">Administración ↗</a></nav></div>
    <div class="footer-bottom"><span>© {{ date('Y') }} USB Stock</span><span>El precio y las condiciones se confirman en tu cotización.</span></div>
</footer>
<x-shop.quote-tab />
<x-shop.corporate-contacts />
<div class="sr-only" data-cart-announcement role="status" aria-live="polite" aria-atomic="true"></div>
<div class="cart-feedback" data-cart-feedback hidden><span data-cart-message></span><button type="button" aria-label="Cerrar aviso" data-cart-dismiss><x-shop.icon name="close"/></button></div>
</body></html>
