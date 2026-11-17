@extends('layouts.shop')
@section('title', 'Catálogo de memorias USB')
@section('content')
<section class="page-title container"><p class="eyebrow">ELIGE. PERSONALIZA. CONECTA.</p><h1>Catálogo de memorias USB</h1><p>Encuentra el modelo para tu proyecto y consulta las variantes disponibles.</p></section>
<div class="container catalog-layout" data-catalog data-endpoint="{{ route('shop.catalog.block') }}" data-url="{{ route('shop.catalog', $filters + (request('cursor') ? ['cursor'=>request('cursor')] : [])) }}" data-revision="{{ $revision }}">
<aside><form method="GET" action="{{ route('shop.catalog') }}" class="filters" data-catalog-filters><h2>Encuentra tu USB</h2>
<label for="search">Modelo o descripción</label><input id="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Buscar memorias…" maxlength="120" type="search">
<label for="category">Categoría</label><select id="category" name="category"><option value="">Todas las categorías</option>@foreach($categories as $category)<option value="{{ $category->slug }}" @selected(($filters['category'] ?? '') === $category->slug)>{{ $category->name }}</option>@endforeach</select>
<label for="capacity">Capacidad</label><select id="capacity" name="capacity"><option value="">Todas las capacidades</option>@foreach($capacities as $capacity)<option value="{{ $capacity }}" @selected(($filters['capacity'] ?? '') == $capacity)>{{ $capacity }} GB</option>@endforeach</select>
<label class="check"><input type="checkbox" name="available" value="1" @checked($filters['available'] ?? false)> Con disponibilidad</label><button class="btn btn-dark">Aplicar filtros</button><a href="{{ route('shop.catalog') }}" data-catalog-clear>Limpiar filtros</a></form>
@if($categories->isNotEmpty())<nav class="category-links" aria-label="Explorar categorías"><h2>Categorías</h2>@foreach($categories as $category)<a href="{{ route('shop.category', $category->slug) }}">{{ $category->name }}</a>@endforeach</nav>@endif
</aside>
<section class="catalog-results" aria-label="Resultados del catálogo">
@if(session('catalog_notice'))<p class="notice" role="status">{{ session('catalog_notice') }}</p>@endif
<a class="catalog-footer-link" href="#catalog-footer">Ir al pie de página</a>
<p class="result-count" data-catalog-count>{{ $products->count() }} modelos cargados</p>
<div class="product-grid catalog-grid" data-catalog-grid aria-busy="false">@include('shop.cards')</div>
<div class="catalog-continuation" data-catalog-sentinel>
<p data-catalog-status role="status" aria-live="polite" aria-atomic="true">{{ !$nextUrl && $products->isNotEmpty() ? 'Llegaste al final del catálogo.' : '' }}</p>
<a class="btn btn-dark" data-catalog-next @if($nextUrl) href="{{ $nextUrl }}" rel="next" @else hidden @endif>Cargar más modelos</a>
<noscript><p>El enlace muestra el siguiente grupo de modelos y conserva tus filtros.</p></noscript>
</div>
</section></div>
@endsection
