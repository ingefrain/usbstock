@if($variants->isEmpty())
<section class="empty"><h2>Tu selección está vacía</h2><p>Explora el catálogo y agrega las variantes que te interesan.</p><a class="button" href="{{ route('shop.catalog') }}">Explorar catálogo</a></section>
@else
<div class="section-heading"><h2>Tu selección</h2><span>{{ $variants->count() }} {{ $variants->count() === 1 ? 'variante' : 'variantes' }}</span></div>
@foreach($variants as $variant)
<article class="selection-item" data-selection-variant="{{ $variant->uuid }}">
    @if($variant->product->image)<img src="{{ route('shop.thumbnail', $variant->product->slug) }}" alt="" width="72" height="85">@else<div class="placeholder" aria-hidden="true">USB</div>@endif
    <div><h3>{{ $variant->product->name }}</h3><p>{{ $variant->sku }} · {{ $variant->color }} · {{ $cart[$variant->id] }} piezas</p>
        <form class="selection-edit" data-cart-action="update" method="POST" action="{{ route('shop.cart.add') }}">
            @csrf<input type="hidden" name="variant" value="{{ $variant->uuid }}">
            <div class="selection-actions"><label for="qty-{{ $variant->uuid }}">Cantidad</label><input id="qty-{{ $variant->uuid }}" type="number" name="quantity" min="50" max="{{ max(50, $variant->stock) }}" value="{{ $cart[$variant->id] }}" required><button>Actualizar</button></div>
            <div class="field"><label for="printing-{{ $variant->uuid }}">Impresión (opcional)</label><input id="printing-{{ $variant->uuid }}" name="printing" maxlength="500" value="{{ session('commerce_cart_printing.'.$variant->id) }}"></div>
        </form>
        <form method="POST" data-cart-action="remove" action="{{ route('shop.cart.remove', $variant->uuid) }}">@csrf @method('DELETE')<button class="text-button" aria-label="Quitar {{ $variant->sku }}">Quitar</button></form>
    </div>
</article>
@endforeach
<div class="selection-total"><span>Total de piezas</span><strong>{{ array_sum($cart) }}</strong></div><a class="text-link" href="{{ route('shop.catalog') }}"><x-shop.icon name="plus"/> Agregar otro producto</a><p class="field-hint">La solicitud no reserva inventario ni confirma precios.</p>
@endif
