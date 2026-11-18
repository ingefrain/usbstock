@extends('layouts.shop')
@section('title', 'Catálogo de memorias USB')
@section('content')
<div class="wrap"><div class="page-heading"><div><span class="eyebrow">Encuentra el estilo de tu marca</span><h1>Tu próxima idea<br>empieza aquí.</h1><p>Explora modelos, colores y capacidades. Nosotros preparamos tu cotización.</p></div><a class="text-link" href="{{ route('shop.request') }}">Ver mi selección <x-shop.icon name="bag"/></a></div>
<div class="catalog-layout catalog-wide" data-catalog data-endpoint="{{ route('shop.catalog.block') }}" data-url="{{ route('shop.catalog', $filters + (request('cursor') ? ['cursor'=>request('cursor')] : [])) }}" data-revision="{{ $revision }}">
<div class="catalog-context"><a href="{{ route('shop.catalog') }}">Catálogo</a>@if($activeCategory)<span aria-hidden="true"> / </span><strong>{{ $activeCategory->name }}</strong>@endif<span>Desde 50 piezas por variante</span></div>
<form class="catalog-toolbar" method="GET" action="{{ route('shop.catalog') }}" data-catalog-filters>
<div class="field"><label for="search">Modelo o descripción</label><input id="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Buscar memorias…" maxlength="120" type="search"></div>
<input type="hidden" name="category" value="{{ $filters['category'] ?? '' }}">
@if($capacities->isNotEmpty())<div class="field"><label for="capacity">Capacidad</label><select id="capacity" name="capacity"><option value="">Todas las capacidades</option>@foreach($capacities as $capacity)<option value="{{ $capacity }}" @selected(($filters['capacity'] ?? '') == $capacity)>{{ $capacity }} GB</option>@endforeach</select></div>@endif
<button class="button">Buscar <x-shop.icon name="search"/></button><a class="clear" href="{{ route('shop.catalog') }}" data-catalog-clear>Ver todo</a></form>
<section class="catalog-results" aria-label="Resultados del catálogo">
@if(session('catalog_notice'))<p class="notice" role="status">{{ session('catalog_notice') }}</p>@endif
<div class="result-bar"><p data-catalog-count>{{ $products->count() }} modelos cargados</p><span>Hechos para llevar tu marca</span><a class="footer-jump" href="#catalog-footer">Ir al pie de página ↓</a></div>
<div class="product-grid catalog-grid" data-catalog-grid aria-busy="false">@include('shop.cards')</div>
<div class="catalog-continuation" data-catalog-sentinel><span class="loading-dots" aria-hidden="true">•••</span><p data-catalog-status role="status" aria-live="polite" aria-atomic="true">{{ !$nextUrl && $products->isNotEmpty() ? 'Llegaste al final del catálogo.' : '' }}</p><a class="button secondary" data-catalog-next @if($nextUrl) href="{{ $nextUrl }}" rel="next" @else hidden @endif>Cargar más modelos</a><noscript><p>El enlace muestra el siguiente grupo de modelos y conserva tus filtros.</p></noscript></div>
</section></div></div>
@endsection
