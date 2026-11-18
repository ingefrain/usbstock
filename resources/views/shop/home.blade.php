@extends('layouts.shop')
@section('content')
<section class="editorial-hero wrap">
    <div class="hero-copy" data-reveal>
        <span class="eyebrow"><span class="brand-dash"></span> Memorias USB · Ideas con identidad</span>
        <h1>Tu marca.<br><em>En sus manos.</em></h1>
        <p>Pequeñas memorias. Grandes ideas. Encuentra la forma, el color y la capacidad para llevar tu marca más lejos.</p>
        <div class="hero-actions"><a class="button primary" href="{{ route('shop.catalog') }}">Encuentra tu USB <x-shop.icon/></a><a class="text-link" href="#como-cotizar">Cómo cotizar <span aria-hidden="true">↘</span></a></div>
        <div class="hero-foot"><span class="mini-symbol" aria-hidden="true">↗</span><span>De tu primera idea<br><strong>a una cotización a tu medida.</strong></span></div>
    </div>
    <div class="hero-stage" data-reveal>
        <div class="stage-rings" aria-hidden="true"></div><span class="stage-word" aria-hidden="true">USB.</span>
        <span class="stage-caption">DISEÑADA PARA LLEVAR TU MARCA</span>
        <img class="hero-product" src="{{ asset('brand/catalog-hero.jpg') }}" width="640" height="640" fetchpriority="high" alt="Memoria USB giratoria azul, fotografía de referencia">
        <div class="stage-detail"><span class="detail-cross" aria-hidden="true">+</span><span>Un clásico.<br><strong>Tu propia identidad.</strong></span><span class="detail-index" aria-hidden="true">01 / USB</span></div>
    </div>
</section>
<div class="discovery-bar wrap" data-reveal>
    <div><span class="eyebrow">Tu próxima idea empieza aquí</span><strong>¿Qué tienes en mente?</strong></div>
    <form class="search" role="search" action="{{ route('shop.catalog') }}"><label for="home-search" class="sr-only">Buscar modelo o estilo</label><x-shop.icon name="search"/><input id="home-search" name="q" type="search" maxlength="120" placeholder="Busca un modelo, estilo o capacidad"><button aria-label="Buscar"><x-shop.icon/></button></form>
    <a class="text-link" href="{{ route('shop.catalog') }}">Ver catálogo <x-shop.icon/></a>
</div>
@if($categories->isNotEmpty())
<section class="section wrap categories-section">
    <div class="section-heading" data-reveal><div><span class="eyebrow">01 / Explora posibilidades</span><h2>Una idea.<br>Muchas formas.</h2></div><p>Descubre un estilo que hable<br>el mismo idioma que tu marca.</p></div>
    <div class="category-grid">
    @foreach($categories as $category)
        <a class="category-card" data-reveal href="{{ route('shop.category', $category->slug) }}"><span class="category-index">0{{ $loop->iteration }}</span>
        @if($category->previewProduct?->image)<img src="{{ route('shop.thumbnail', $category->previewProduct->slug) }}" alt="" width="480" height="480" loading="lazy">@else<div class="placeholder" aria-hidden="true">USB</div>@endif
        <span class="category-caption"><strong>{{ $category->name }}</strong><span class="round-arrow"><x-shop.icon/></span></span></a>
    @endforeach
    </div>
</section>
@endif
<section class="featured-section"><div class="wrap section">
    <div class="section-heading" data-reveal><div><span class="eyebrow">02 / Encuentra tu estilo</span><h2>Objetos pequeños.<br><em>Grandes posibilidades.</em></h2></div><a class="text-link" href="{{ route('shop.catalog') }}">Ver todos los modelos <x-shop.icon/></a></div>
    <div class="product-grid featured-grid">@forelse($products as $product) @include('shop.card') @empty<div class="empty" data-reveal><span class="empty-mark" aria-hidden="true">↗</span><h3>Estamos preparando el catálogo</h3><p>Los modelos estarán aquí cuando hayan sido revisados. Mientras tanto, cuéntanos qué tienes en mente.</p><a class="button primary" href="{{ route('shop.info') }}">Contactar a USB Stock <x-shop.icon/></a></div>@endforelse</div>
</div></section>
<section class="brand-statement wrap section" data-reveal><span class="eyebrow">Hecho para tu proyecto</span><h2>El detalle que<br><em>lleva tu identidad.</em></h2><div class="benefit-list"><div><x-shop.icon name="sliders"/><h3>Elige a tu medida</h3><p>Compara modelos, capacidades y variantes desde el catálogo.</p></div><div><x-shop.icon name="plus"/><h3>Cuéntanos tu idea</h3><p>Indica la impresión o personalización que necesitas en cada partida.</p></div><div><x-shop.icon name="check"/><h3>Cotiza con claridad</h3><p>El equipo comercial revisa tu selección, precios y condiciones.</p></div></div></section>
<section class="process-band" id="como-cotizar"><div class="wrap process section">
    <div data-reveal><span class="eyebrow">03 / De la idea a tu cotización</span><h2>Lo hacemos<br><em>paso a paso.</em></h2><p>Tú eliges las posibilidades.<br>Nosotros preparamos tu cotización.</p><a class="button light" href="{{ route('shop.catalog') }}">Empezar mi selección <x-shop.icon/></a></div>
    <ol><li data-reveal><span>01</span><div><h3>Encuentra tu modelo</h3><p>Explora estilos, capacidades y disponibilidad.</p></div><x-shop.icon/></li><li data-reveal><span>02</span><div><h3>Hazlo parte de tu proyecto</h3><p>Elige color y cantidad, desde 50 piezas por variante.</p></div><x-shop.icon/></li><li data-reveal><span>03</span><div><h3>Envía tu solicitud</h3><p>Comparte tus datos, observaciones y el nombre de tu asesor, si tienes uno asignado.</p></div><x-shop.icon/></li></ol>
</div></section>
<section class="contact-strip wrap" data-reveal><span class="eyebrow">Tu próximo proyecto</span><h2>Hagamos espacio<br>para tu <em>próxima idea.</em></h2><div><a class="button primary" href="{{ route('shop.request') }}">Preparar mi cotización <x-shop.icon/></a><a class="text-link" href="{{ route('shop.info') }}">Hablemos de tu proyecto <x-shop.icon/></a></div></section>
@endsection
