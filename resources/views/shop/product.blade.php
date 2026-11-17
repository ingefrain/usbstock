@extends('layouts.shop')
@section('title', $product->name.' · '.$product->external_key)
@section('description', \Illuminate\Support\Str::limit($product->description, 160))
@push('shop-head')
<script type="application/ld+json">{!! json_encode(['@context'=>'https://schema.org','@type'=>'Product','name'=>$product->name,'sku'=>$product->external_key,'description'=>$product->description,'brand'=>['@type'=>'Brand','name'=>'USB Stock']], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) !!}</script>
@endpush
@section('content')
<div class="container"><nav class="breadcrumbs" aria-label="Ruta"><a href="{{ route('shop.home') }}">Inicio</a> / <a href="{{ route('shop.catalog') }}">Catálogo</a> / {{ $product->external_key }}</nav>
<section class="product-detail"><div class="detail-picture">@if($product->image)<img src="{{ route('shop.image', $product->slug) }}" alt="{{ $product->name }}" width="700" height="600">@else<div class="placeholder"><span>USB</span><small>Imagen no disponible</small></div>@endif</div><div class="detail-copy"><p class="eyebrow">{{ $product->external_key }} @if($product->category) / {{ $product->category->name }} @endif</p><h1>{{ $product->name }}</h1>@if($product->capacity_gb)<span class="tag">{{ $product->capacity_gb }} GB</span>@endif<p class="description">{{ $product->description }}</p><h2>Elige tu variante</h2><p class="muted">Desde 50 piezas por variante. El precio se confirma en tu cotización.</p>
@forelse($product->variants as $variant)
<form method="POST" action="{{ route('shop.cart.add') }}" class="variant-row">@csrf<input type="hidden" name="variant" value="{{ $variant->uuid }}"><div><strong>{{ $variant->color }}</strong><small>{{ $variant->sku }} · {{ number_format($variant->stock) }} piezas</small></div><label for="qty-{{ $variant->id }}" class="sr-only">Cantidad {{ $variant->sku }}</label><input id="qty-{{ $variant->id }}" aria-label="Cantidad {{ $variant->sku }}" type="number" name="quantity" min="50" max="{{ max(50,$variant->stock) }}" value="50" required @disabled($variant->stock < 50)><button class="btn btn-red" @disabled($variant->stock < 50)>{{ $variant->stock >= 50 ? 'Cotizar' : 'No disponible' }}</button></form>
@empty<div class="notice">Este modelo no tiene variantes disponibles por el momento.</div>@endforelse
</div></section>
@if($related->isNotEmpty())<section class="section"><div class="section-heading"><h2>También te puede interesar</h2></div><div class="product-grid">@foreach($related as $product) @include('shop.card') @endforeach</div></section>@endif
</div>
@endsection
