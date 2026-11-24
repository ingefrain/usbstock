@extends('layouts.shop')
@section('title', 'Tu marca, siempre presente')
@section('content')
<section class="lv-hero">
    <div class="lv-tech-grid lv-hero-grid" aria-hidden="true"></div>
    <div class="wrap lv-hero-inner">
        <div class="lv-hero-copy">
            <p class="lv-hero-eyebrow" data-h-chip><span aria-hidden="true"></span> Productos promocionales tecnológicos</p>
            <h1 class="lv-hero-title"><span data-h-line><span>Tu marca,</span></span><span data-h-line><span>siempre <em>presente.</em></span></span></h1>
            <p class="lv-hero-description" data-h-sub>Memorias USB y productos tecnológicos personalizados para hacer destacar tu marca.</p>
            <div class="lv-hero-actions" data-h-cta>
                <a class="button primary" href="{{ route('shop.catalog') }}">Explorar productos <x-shop.icon/></a>
                <a class="button secondary" href="{{ route('shop.request') }}">Solicitar cotización</a>
            </div>
        </div>
        <div class="lv-hero-art">
            <div class="lv-hero-image" data-h-img><img src="{{ asset('brand/showcase/hero-usb-clean.jpg') }}" alt="Memoria USB metálica con acento rojo, imagen de inspiración" width="1200" height="800" fetchpriority="high" decoding="async"></div>
            @php($heroCategory = $categories->first(fn ($category) => $category->previewProduct?->image))
            @if($heroCategory)
            <a class="lv-hero-style" data-h-chip href="{{ route('shop.category', $heroCategory->slug) }}"><img src="{{ route('shop.thumbnail', $heroCategory->previewProduct->slug) }}" alt="" width="56" height="56" loading="lazy"><span><small>Explora</small><strong>{{ $heroCategory->name }}</strong></span></a>
            @endif
            <span class="lv-hero-label" data-h-chip><x-shop.icon name="sliders"/> Personalización para tu marca</span>
            <div class="lv-hero-minimum" data-h-chip><small>Pedido mínimo</small><strong>50 piezas</strong><span>por variante</span></div>
        </div>
    </div>
</section>

@if($categories->isNotEmpty())
<section class="wrap lv-home-section lv-categories">
    <div class="lv-section-heading"><div data-reveal><p class="lv-kicker">Categorías destacadas</p><h2>Un estilo para<br>cada marca.</h2></div><a class="text-link" data-reveal href="{{ route('shop.catalog') }}">Ver todo el catálogo <x-shop.icon class="lv-arrow-up"/></a></div>
    <div class="lv-category-grid" data-stagger>
        @foreach($categories->take(4) as $category)
        <a class="lv-category-tile lv-category-tile-{{ $loop->iteration }}" href="{{ route('shop.category', $category->slug) }}">
            @if($category->previewProduct?->image)<img src="{{ route('shop.thumbnail', $category->previewProduct->slug) }}" alt="" width="480" height="480" loading="lazy" decoding="async">@else<span class="lv-category-placeholder" aria-hidden="true">USB.</span>@endif
            <span class="lv-category-caption"><strong>{{ $category->name }}</strong><span class="lv-category-arrow"><x-shop.icon class="lv-arrow-up"/></span></span>
        </a>
        @endforeach
    </div>
    @if($categories->count() > 4)<nav class="lv-more-categories" aria-label="Más categorías">@foreach($categories->skip(4) as $category)<a href="{{ route('shop.category', $category->slug) }}">{{ $category->name }} <x-shop.icon class="lv-arrow-up"/></a>@endforeach</nav>@endif
</section>
@endif

<section class="lv-selection lv-home-section"><div class="wrap">
    <div class="lv-section-heading" data-reveal><h2>Selección para<br>tu próxima idea.</h2><p>Modelos disponibles para personalizar desde 50 piezas por variante.</p></div>
    <div class="product-grid featured-grid lv-featured-grid" data-stagger>
        @forelse($products as $product) @include('shop.card')
        @empty<div class="empty"><h3>Estamos preparando el catálogo</h3><p>Los modelos estarán aquí cuando hayan sido revisados. Mientras tanto, cuéntanos qué tienes en mente.</p><a class="button primary" href="{{ route('shop.info') }}">Contactar a USB Stock <x-shop.icon/></a></div>@endforelse
    </div>
</div></section>

<section id="personalizacion" class="wrap lv-home-section lv-personalization">
    <div class="lv-personalization-intro"><p class="lv-kicker" data-reveal>Personalización</p><h2 data-reveal>Detalles que hablan por tu marca.</h2><div class="lv-personalization-image" data-reveal-img><img src="{{ asset('brand/showcase/usb-metal.jpg') }}" alt="Memoria USB metálica, referencia de estilo ejecutivo" width="1200" height="1200" loading="lazy" decoding="async" data-parallax="18"></div></div>
    <ol class="lv-benefits" data-stagger>
        <li><span class="lv-benefit-number">01</span><span class="lv-benefit-icon"><x-shop.icon name="sliders"/></span><div><h3>Un estilo propio</h3><p>Compara formas, colores y capacidades para encontrar el modelo que represente a tu marca.</p></div></li>
        <li><span class="lv-benefit-number">02</span><span class="lv-benefit-icon"><x-shop.icon name="plus"/></span><div><h3>Tu identidad, presente</h3><p>Indica la impresión o personalización que necesitas en cada partida de tu selección.</p></div></li>
        <li><span class="lv-benefit-number">03</span><span class="lv-benefit-icon"><x-shop.icon name="bag"/></span><div><h3>A la medida de tu proyecto</h3><p>Define las cantidades y comparte los detalles de tu evento, equipo o próxima campaña.</p></div></li>
        <li><span class="lv-benefit-number">04</span><span class="lv-benefit-icon"><x-shop.icon name="check"/></span><div><h3>Una propuesta clara</h3><p>El equipo comercial revisa tu solicitud y confirma precios, personalización y condiciones.</p></div></li>
    </ol>
</section>

<section id="como-cotizar" class="lv-process lv-home-section"><div class="lv-tech-grid" aria-hidden="true"></div><div class="wrap">
    <p class="lv-kicker" data-reveal>Proceso de cotización</p><h2 data-reveal>De la idea a tu propuesta en cuatro pasos.</h2>
    <ol class="lv-process-grid" data-stagger>
        <li><span>1</span><h3>Elige tus productos</h3><p>Explora el catálogo y agrega los modelos que te interesan a tu selección.</p></li>
        <li><span>2</span><h3>Define cantidades</h3><p>Desde 50 piezas por variante, según la disponibilidad de cada modelo.</p></li>
        <li><span>3</span><h3>Envía tu solicitud</h3><p>Comparte tus datos, la personalización y el nombre de tu asesor, si ya tienes uno.</p></li>
        <li><span>4</span><h3>Recibe tu propuesta</h3><p>Un asesor revisa tu proyecto y prepara una cotización a tu medida.</p></li>
    </ol>
</div></section>

<section class="wrap lv-home-section lv-editorial"><div class="lv-editorial-stage">
    <div class="lv-editorial-image" data-reveal-img><img src="{{ asset('brand/showcase/editorial-desk.jpg') }}" alt="Inspiración de productos tecnológicos en un escritorio corporativo" width="1200" height="800" loading="lazy" decoding="async" data-parallax="30"></div>
    <div class="lv-editorial-panel" data-reveal><p class="lv-kicker">Ideas para tu equipo</p><h2>Una línea completa con tu identidad.</h2><p>Explora los productos del catálogo y cuéntanos cómo te gustaría combinarlos para tu evento, equipo o regalo corporativo.</p><a class="text-link" href="{{ route('shop.catalog') }}">Explorar productos <x-shop.icon/></a></div>
</div></section>

<section class="wrap lv-final-section"><div class="lv-final-cta" data-reveal>
    <div class="lv-tech-grid" aria-hidden="true"></div><img class="lv-final-image" src="{{ asset('brand/showcase/hero-usb-clean.jpg') }}" alt="" width="1200" height="800" loading="lazy" decoding="async">
    <div class="lv-final-copy"><h2>Hagamos algo memorable.</h2><p>Cuéntanos tu proyecto y recibe una propuesta personalizada.</p><div class="lv-hero-actions"><a class="button light" href="{{ route('shop.request') }}">Solicitar cotización <x-shop.icon/></a><a class="button lv-button-outline" href="{{ route('shop.catalog') }}">Ver catálogo</a></div></div>
</div></section>
@endsection
