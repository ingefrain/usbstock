@extends('layouts.shop')
@section('title', 'Solicitar cotización')
@section('content')
<section class="container section"><p class="eyebrow">TU PROYECTO, A TU MEDIDA</p><h1>Mi solicitud de cotización</h1>
@if(session('request_received'))<div class="notice success" role="status"><h2>Recibimos tu solicitud</h2><p>Referencia {{ session('request_received') }}. El equipo comercial revisará tu selección y los datos de contacto.</p></div>@endif
@if($variants->isEmpty())<div class="empty"><h2>Tu selección está vacía</h2><p>Explora el catálogo y agrega las variantes que te interesan.</p><a class="btn btn-red" href="{{ route('shop.catalog') }}">Explorar catálogo</a></div>@else
<div class="request-layout"><section class="selection"><h2>Tu selección</h2>@foreach($variants as $variant)<article><div><h3>{{ $variant->product->name }}</h3><p>{{ $variant->sku }} · {{ $variant->color }} · {{ $cart[$variant->id] }} piezas</p><a href="{{ route('shop.product', $variant->product->slug) }}">Cambiar cantidad</a></div><form method="POST" action="{{ route('shop.cart.remove', $variant->id) }}">@csrf @method('DELETE')<button class="text-button" aria-label="Quitar {{ $variant->sku }}">Quitar</button></form></article>@endforeach<a href="{{ route('shop.catalog') }}">+ Agregar otro producto</a><p class="muted">La solicitud no reserva inventario ni confirma precios. El equipo comercial preparará la cotización.</p></section>
<form method="POST" action="{{ route('shop.request.submit') }}" class="request-form">@csrf<input type="hidden" name="request_key" value="{{ session('quote_request_key') }}"><h2>Datos de contacto</h2>
@foreach(['name'=>'Nombre','email'=>'Correo electrónico','phone'=>'Teléfono'] as $key=>$label)<label for="{{ $key }}">{{ $label }}</label><input id="{{ $key }}" name="{{ $key }}" value="{{ old($key) }}" type="{{ $key === 'email' ? 'email' : ($key === 'phone' ? 'tel' : 'text') }}" maxlength="{{ $key === 'phone' ? 50 : 180 }}" required autocomplete="{{ $key === 'phone' ? 'tel' : $key }}">@endforeach
<label for="message">Cuéntanos sobre tu proyecto o impresión</label><textarea id="message" name="message" maxlength="2000" rows="5">{{ old('message') }}</textarea><div class="honeypot" aria-hidden="true"><label for="website">Sitio web</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div><button class="btn btn-red">Enviar solicitud ↗</button></form></div>
@endif
</section>
@endsection
