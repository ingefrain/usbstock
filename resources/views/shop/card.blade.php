<article class="product-card" data-product-id="{{ $product->uuid }}">
    <a class="product-image" href="{{ route('shop.product', $product->slug) }}" tabindex="-1" aria-hidden="true">
        @if($product->image)<img src="{{ route('shop.thumbnail', $product->slug) }}" alt="" width="480" height="480" loading="lazy" decoding="async">@else<div class="placeholder"><span>USB</span><small>Fotografía no disponible</small></div>@endif
        @if($product->capacity_gb)<span class="image-tag">{{ $product->capacity_gb }} GB</span>@endif<span class="lv-stock-tag"><span></span>{{ $product->available_variants_count ? 'Disponible' : 'Consultar' }}</span>
    </a>
    <div class="card-copy"><div class="card-meta"><span>{{ $product->category_name ?? $product->assigned_category_name ?? 'Catálogo USB Stock' }}</span><span>{{ $product->external_key }}</span></div><h3><a href="{{ route('shop.product', $product->slug) }}">{{ $product->name }}</a></h3><p class="card-description">{{ \Illuminate\Support\Str::limit($product->description, 105) }}</p><div class="card-bottom"><span>{{ $product->available_variants_count }} <small>variantes {{ config('commerce.review_mode') ? 'de prueba' : 'disponibles' }}</small></span><a href="{{ route('shop.product', $product->slug) }}#variantes" aria-label="Ver variantes y cotizar {{ $product->name }}"><x-shop.icon name="plus"/> Ver y cotizar</a></div></div>
</article>
