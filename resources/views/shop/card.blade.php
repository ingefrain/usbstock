<article class="product-card" data-product-id="{{ $product->uuid }}">
<a class="product-picture" href="{{ route('shop.product', $product->slug) }}" tabindex="-1" aria-hidden="true">
@if($product->image)<img src="{{ route('shop.thumbnail', $product->slug) }}" alt="" width="480" height="360" loading="lazy" decoding="async">@else<div class="placeholder"><span>USB</span><small>Fotografía próximamente</small></div>@endif
@if($product->capacity_gb)<span class="capacity">{{ $product->capacity_gb }} GB</span>@endif
</a>
<div class="product-copy"><p class="eyebrow">{{ $product->external_key }}</p><h3><a href="{{ route('shop.product', $product->slug) }}">{{ $product->name }}</a></h3><p class="line-clamp">{{ $product->description }}</p>
<div class="card-bottom"><span class="availability {{ $product->available_variants_count ? 'is-available' : '' }}">{{ $product->available_variants_count ? $product->available_variants_count.' variantes disponibles' : 'Consultar disponibilidad' }}</span><a href="{{ route('shop.product', $product->slug) }}#variantes" aria-label="Ver variantes y cotizar {{ $product->name }}">Ver y cotizar <span aria-hidden="true">↗</span></a></div></div>
</article>
