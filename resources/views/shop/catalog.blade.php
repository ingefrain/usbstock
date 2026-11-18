@extends('layouts.shop')
@section('title', 'Catálogo de memorias USB')
@section('content')
<div class="wrap"><div class="page-heading"><div><span class="eyebrow">Encuentra el estilo de tu marca</span><h1>Tu próxima idea<br>empieza aquí.</h1><p>Explora modelos, colores y capacidades. Nosotros preparamos tu cotización.</p></div><a class="text-link" href="{{ route('shop.request') }}">Ver mi selección <x-shop.icon name="bag"/></a></div>
<div class="catalog-layout" data-catalog data-endpoint="{{ route('shop.catalog.block') }}" data-url="{{ route('shop.catalog', $filters + (request('cursor') ? ['cursor'=>request('cursor')] : [])) }}" data-revision="{{ $revision }}">
<aside><details id="catalog-filters" class="filters" open><summary><x-shop.icon name="sliders"/> Encuentra tu USB <span>⌄</span></summary><form method="GET" action="{{ route('shop.catalog') }}" data-catalog-filters>
<div class="field"><label for="search">Modelo o descripción</label><input id="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Buscar memorias…" maxlength="120" type="search"></div>
<div class="field"><label for="category">Categoría</label><select id="category" name="category"><option value="">Todos los estilos</option>@foreach($categories as $category)<option value="{{ $category->slug }}" @selected(($filters['category'] ?? '') === $category->slug)>{{ $category->name }}</option>@endforeach</select></div>
<div class="field"><label for="capacity">Capacidad</label><select id="capacity" name="capacity"><option value="">Todas las capacidades</option>@foreach($capacities as $capacity)<option value="{{ $capacity }}" @selected(($filters['capacity'] ?? '') == $capacity)>{{ $capacity }} GB</option>@endforeach</select></div>
<label class="checkbox"><input type="checkbox" name="available" value="1" @checked($filters['available'] ?? false)> Con disponibilidad</label><button class="button">Aplicar filtros <x-shop.icon/></button><a class="clear" href="{{ route('shop.catalog') }}" data-catalog-clear>Limpiar filtros</a></form><p class="filter-note">Desde 50 piezas por variante.<br>Los precios se confirman en tu cotización.</p></details></aside>
{{-- Run during parsing, before the first paint; without JavaScript the filters stay open. --}}
<script>if (window.matchMedia('(max-width:600px)').matches) document.getElementById('catalog-filters').open = false;</script>
<section class="catalog-results" aria-label="Resultados del catálogo">
@if(session('catalog_notice'))<p class="notice" role="status">{{ session('catalog_notice') }}</p>@endif
<div class="result-bar"><p data-catalog-count>{{ $products->count() }} modelos cargados</p><span>Hechos para llevar tu marca</span><a class="footer-jump" href="#catalog-footer">Ir al pie de página ↓</a></div>
<div class="product-grid catalog-grid" data-catalog-grid aria-busy="false">@include('shop.cards')</div>
<div class="catalog-continuation" data-catalog-sentinel><span class="loading-dots" aria-hidden="true">•••</span><p data-catalog-status role="status" aria-live="polite" aria-atomic="true">{{ !$nextUrl && $products->isNotEmpty() ? 'Llegaste al final del catálogo.' : '' }}</p><a class="button secondary" data-catalog-next @if($nextUrl) href="{{ $nextUrl }}" rel="next" @else hidden @endif>Cargar más modelos</a><noscript><p>El enlace muestra el siguiente grupo de modelos y conserva tus filtros.</p></noscript></div>
</section></div></div>
@endsection
