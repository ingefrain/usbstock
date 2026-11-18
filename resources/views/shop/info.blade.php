@extends('layouts.shop')
@section('title', 'Contacto y atención comercial')
@section('content')
<section class="wrap section info-page"><p class="eyebrow">USB STOCK</p><h1>Hablemos de tu proyecto.</h1><p>Explora nuestro catálogo y selecciona los modelos que te interesan para solicitar una cotización.</p>@if($settings['about'] ?? null)<div class="description">{{ $settings['about'] }}</div>@endif<div class="contact-options">@if($settings['public_email'] ?? null)<div><h2>Correo</h2><a href="mailto:{{ $settings['public_email'] }}">{{ $settings['public_email'] }}</a></div>@endif @if($settings['public_phone'] ?? null)<div><h2>Teléfono</h2><p>{{ $settings['public_phone'] }}</p></div>@endif</div><a class="button" href="{{ route('shop.catalog') }}">Elegir productos para cotizar ↗</a></section>
@endsection
