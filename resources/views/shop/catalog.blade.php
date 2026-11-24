@extends('layouts.shop')
@section('title', 'Catálogo de memorias USB')
@section('content')
<div class="lv-catalog" data-catalog data-endpoint="{{ route('shop.catalog.block') }}" data-url="{{ route('shop.catalog', $filters + (request('cursor') ? ['cursor'=>request('cursor')] : [])) }}" data-revision="{{ $revision }}">
    <section class="lv-catalog-hero">
        <div class="wrap">
            <div class="lv-tech-grid" aria-hidden="true"></div>
            <div class="lv-catalog-heading"><div><span class="eyebrow">Catálogo{{ $activeCategory ? ' · '.$activeCategory->name : '' }}</span><h1>{{ $activeCategory?->name ?? 'Memorias USB para tu marca.' }}</h1><p>Elige tus modelos y cuéntanos cómo personalizarlos. Pedido mínimo de 50 piezas por variante.</p></div><span class="lv-minimum"><x-shop.icon name="bag"/> Desde 50 piezas por variante</span></div>
            <form class="catalog-toolbar" method="GET" action="{{ route('shop.catalog') }}" role="search" data-catalog-filters>
                <div class="field lv-catalog-search"><label for="search" class="sr-only">Modelo o descripción</label><x-shop.icon name="search"/><input id="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Busca un modelo, estilo o producto…" maxlength="120" type="search"></div>
                <input type="hidden" name="category" value="{{ $filters['category'] ?? '' }}">
                @if($capacities->isNotEmpty())<div class="field"><label for="capacity" class="sr-only">Capacidad</label><select id="capacity" name="capacity"><option value="">Todas las capacidades</option>@foreach($capacities as $capacity)<option value="{{ $capacity }}" @selected(($filters['capacity'] ?? '') == $capacity)>{{ $capacity }} GB</option>@endforeach</select></div>@endif
                <button class="button primary" aria-label="Buscar productos"><x-shop.icon/></button>
            </form>
        </div>
    </section>
    <nav class="lv-filter-band" aria-label="Filtrar por familia"><div class="wrap lv-filter-pills">
        <a href="{{ route('shop.catalog') }}" @if(!$activeCategory) aria-current="page" @endif>Todos los productos</a>
        @foreach($navigationCategories as $filterCategory)<a href="{{ route('shop.category', $filterCategory->slug) }}" @if($activeCategory && ($activeCategory->id === $filterCategory->id || $filterCategory->children->contains('id', $activeCategory->id))) aria-current="page" @endif>{{ $filterCategory->name }}</a>@endforeach
    </div></nav>
    <div class="wrap catalog-layout catalog-wide">
        <div class="catalog-context"><a href="{{ route('shop.catalog') }}">Catálogo</a>@if($activeCategory)<span aria-hidden="true"> / </span><strong>{{ $activeCategory->name }}</strong>@endif</div>
        @php($catalogChildren = $navigationCategories->firstWhere('id', $activeCategory?->id)?->children ?? collect())
        @if($catalogChildren->isNotEmpty())<nav class="lv-subcategories" aria-label="Subcategorías">@foreach($catalogChildren as $subcategory)<a href="{{ route('shop.category', $subcategory->slug) }}">{{ $subcategory->name }} <span aria-hidden="true">↗</span></a>@endforeach</nav>@endif
        <section class="catalog-results" aria-label="Resultados del catálogo">
            @if(session('catalog_notice'))<p class="notice" role="status">{{ session('catalog_notice') }}</p>@endif
            <div class="result-bar"><p data-catalog-count>{{ $products->count() }} modelos cargados</p><a class="clear" href="{{ route('shop.catalog') }}" data-catalog-clear>Ver todo</a><span>Selecciona un modelo para cotizar</span><a class="footer-jump" href="#catalog-footer">Ir al pie de página ↓</a></div>
            <div class="product-grid catalog-grid" data-catalog-grid aria-busy="false">@include('shop.cards')</div>
            <div class="catalog-continuation" data-catalog-sentinel><span class="loading-dots" aria-hidden="true">•••</span><p data-catalog-status role="status" aria-live="polite" aria-atomic="true">{{ !$nextUrl && $products->isNotEmpty() ? 'Llegaste al final del catálogo.' : '' }}</p><a class="button secondary" data-catalog-next @if($nextUrl) href="{{ $nextUrl }}" rel="next" @else hidden @endif>Cargar más modelos <x-shop.icon name="plus"/></a><noscript><p>El enlace muestra el siguiente grupo de modelos y conserva tus filtros.</p></noscript></div>
        </section>
    </div>
</div>
@endsection
