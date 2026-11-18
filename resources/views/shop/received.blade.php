@extends('layouts.shop')
@section('title', 'Solicitud recibida')
@section('content')
<section class="wrap section receipt"><span class="success-icon"><x-shop.icon name="check"/></span><span class="eyebrow">El siguiente paso ya está en marcha</span><h1>Tu idea está<br>en buenas manos.</h1><h2>Recibimos tu solicitud</h2><p>Referencia <strong class="receipt-reference">{{ $receipt['uuid'] }}</strong></p><p>El departamento de costos revisará tus productos y datos de contacto para preparar tu cotización.</p><p>Tu solicitud no reserva existencias ni confirma precios.</p><form method="POST" action="{{ route('shop.request.restart') }}">@csrf<button class="button">Comenzar otra solicitud <x-shop.icon/></button></form><a class="text-link" href="{{ route('shop.home') }}">Volver al inicio</a></section>
@endsection
