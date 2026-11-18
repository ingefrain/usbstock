@extends('layouts.shop')
@section('title', 'Solicitar cotización')
@section('content')
@if(session('selection_notice'))<div class="wrap"><p class="notice" role="status" data-reveal>{{ session('selection_notice') }}</p></div>@endif
<div class="wrap"><div class="page-heading"><div><span class="eyebrow">Tu proyecto, a tu medida</span><h1>Vamos a darle forma<br>a tu idea.</h1><p>Revisa tu selección y cuéntanos cómo podemos ayudarte.</p></div><a class="text-link" href="{{ route('shop.catalog') }}">Seguir explorando <x-shop.icon/></a></div>
<div class="quote-layout {{ $variants->isEmpty() ? 'is-empty' : '' }}" data-selection-layout>
<section id="seleccion" class="selection-panel" data-selection-summary>@include('shop.selection')</section>
<section class="contact-panel" data-request-contact @if($variants->isEmpty()) hidden @endif><h2>Hablemos de tu proyecto</h2><p>Costos revisará tu selección y preparará una cotización para ti.</p>
<form method="POST" action="{{ route('shop.request.submit') }}">@csrf<input type="hidden" name="request_key" value="{{ session('quote_request_key') }}">
@foreach(['name'=>'Nombre','email'=>'Correo electrónico','phone'=>'Teléfono','company'=>'Empresa (opcional)','country'=>'País (opcional)'] as $key=>$label)<div class="field"><label for="{{ $key }}">{{ $label }}</label><input id="{{ $key }}" name="{{ $key }}" value="{{ old($key) }}" type="{{ $key === 'email' ? 'email' : ($key === 'phone' ? 'tel' : 'text') }}" maxlength="{{ $key === 'phone' ? 50 : ($key === 'country' ? 100 : 180) }}" @required(in_array($key,['name','email','phone'])) autocomplete="{{ ['phone'=>'tel','company'=>'organization','country'=>'country-name'][$key] ?? $key }}"></div>@endforeach
<fieldset class="seller-choice"><legend>¿Tienes un vendedor o asesor asignado?</legend><label class="checkbox"><input type="radio" name="has_seller" value="1" required @checked(old('has_seller', $sellerPreference !== null ? '1' : '') === '1')> Sí, tengo un asesor</label><label class="checkbox"><input type="radio" name="has_seller" value="0" required @checked(old('has_seller') === '0')> No tengo uno asignado</label></fieldset>
<div class="field" data-seller-field><label for="seller_name">Nombre de tu vendedor o asesor</label><input id="seller_name" name="seller_name" value="{{ old('seller_name', $sellerPreference ?? '') }}" maxlength="180" autocomplete="off" spellcheck="false" aria-describedby="seller-help"><p id="seller-help" class="field-hint">Escríbelo como lo conoces. Recordaremos este nombre en este navegador para tu próxima solicitud. Puedes cambiarlo o borrarlo.</p></div>
<div class="field"><label for="message">Observaciones de tu proyecto (opcional)</label><textarea id="message" name="message" maxlength="2000" rows="4">{{ old('message') }}</textarea></div><div class="honeypot" aria-hidden="true"><label for="website">Sitio web</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div><button class="button quote-button">Enviar solicitud <x-shop.icon/></button></form>
@if($sellerPreference !== null)<form method="POST" action="{{ route('shop.seller.forget') }}" class="forget-seller">@csrf @method('DELETE')<button class="text-button">Borrar el vendedor recordado</button></form>@endif
@if(session('seller_forgotten'))<p role="status">Borramos el nombre recordado en este navegador.</p>@endif
</section></div></div>
@endsection
