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
    @vite(['resources/css/visual.css','resources/js/visual.js'])
    <link rel="stylesheet" href="{{ asset('assets/usb-selection.css') }}?v={{ filemtime(public_path('assets/usb-selection.css')) }}">
    <noscript><style>@media(max-width:1099px){.shop .mobile-nav{display:block!important;position:static!important;max-height:none!important}}</style></noscript>
    @stack('shop-head')
</head>
<body class="shop theme-b visual-public" data-cart-state="{{ route('shop.cart.state') }}" data-cart-revision="{{ (int) session('commerce_cart_revision', 0) }}">
<a class="skip-link" href="#main">Saltar al contenido</a>
<x-shop.development-notice />
<header class="site-header">
    <div class="header-inner">
        <a class="brand" href="{{ route('shop.home') }}"><img src="{{ asset('brand/logo.webp') }}" width="220" height="50" alt="USB Stock"></a>
        <div class="desktop-nav">
            <details class="lv-mega" data-public-categories><summary>Categorías</summary><div class="lv-mega-panel"><x-shop.category-nav /></div></details>
            <nav aria-label="Principal"><a href="{{ route('shop.catalog') }}" @if(request()->routeIs('shop.catalog','shop.category','shop.product')) aria-current="page" @endif>Catálogo</a><a href="{{ route('shop.home') }}#como-cotizar">Cómo cotizar</a><a href="{{ route('shop.info') }}">Contacto</a></nav>
        </div>
        <a class="lv-header-search" href="{{ route('shop.catalog') }}#search" aria-label="Buscar productos"><x-shop.icon name="search"/></a>
        <a class="quote-nav" href="{{ route('shop.request') }}"><x-shop.icon name="bag"/><span>Cotización</span><b data-cart-count>{{ session()->has('quote_request_receipt') ? 0 : count(session('commerce_cart', [])) }}</b></a>
        <button class="mobile-menu" aria-label="Abrir navegación" aria-controls="mobile-nav" aria-expanded="false" hidden><x-shop.icon name="menu"/></button>
    </div>
    <div id="mobile-nav" class="mobile-nav"><nav aria-label="Navegación móvil"><a href="{{ route('shop.catalog') }}">Todo el catálogo</a><a href="{{ route('shop.home') }}#como-cotizar">Cómo cotizar</a><a href="{{ route('shop.info') }}">Contacto</a></nav><x-shop.category-nav :mobile="true" /></div>
</header>
<main id="main" tabindex="-1">
    @if($errors->any())<div class="wrap"><div class="notice error" role="alert"><strong>Revisa la información</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
    @yield('content')
</main>
<footer id="catalog-footer" class="shop-footer" tabindex="-1">
    <div class="wrap"><div class="footer-top"><div class="lv-footer-brand"><a href="{{ route('shop.home') }}"><img src="{{ asset('brand/logo.webp') }}" width="190" height="43" alt="USB Stock"></a><p>Productos tecnológicos que llevan<br>tu marca a todas partes.</p><span class="lv-footer-minimum">Desde 50 piezas por variante</span></div><nav aria-label="Catálogo"><h2>Explora</h2><a href="{{ route('shop.catalog') }}">Todos los productos</a>@foreach($navigationCategories->take(3) as $footerCategory)<a href="{{ route('shop.category', $footerCategory->slug) }}">{{ $footerCategory->name }}</a>@endforeach</nav><nav aria-label="Atención"><h2>Tu proyecto</h2><a href="{{ route('shop.home') }}#como-cotizar">Cómo cotizar</a><a href="{{ route('shop.request') }}">Mi cotización</a><a href="{{ route('shop.info') }}">Atención comercial</a></nav><div class="lv-footer-contact"><h2>Hablemos de tu idea.</h2><p>Elige tus productos.<br>Nosotros preparamos tu propuesta.</p><a class="text-link" href="{{ route('shop.info') }}">Contactar al equipo <x-shop.icon/></a></div></div>
    <div class="footer-bottom"><span>© {{ date('Y') }} USB Stock. Todos los derechos reservados.</span><span>El precio y las condiciones se confirman en tu cotización.</span><a href="{{ route('admin.login') }}">Administración ↗</a></div></div>
</footer>
<x-shop.quote-tab />
<x-shop.corporate-contacts />
<div class="sr-only" data-cart-announcement role="status" aria-live="polite" aria-atomic="true"></div>
<div class="cart-feedback" data-cart-feedback hidden><span data-cart-message></span><button type="button" aria-label="Cerrar aviso" data-cart-dismiss><x-shop.icon name="close"/></button></div>
</body></html>
