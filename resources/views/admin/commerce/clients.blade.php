@extends('layouts.admin')
@section('title', 'Clientes')
@section('content')
<div class="admin-form-surface admin-form-surface-wide">
<p class="muted">Contactos comerciales. Abre un registro para consultar o actualizar sus datos.</p>
@if($errors->any())<x-admin.alert variant="error">{{ $errors->first() }}</x-admin.alert>@endif
@foreach(collect([null])->concat($clients->items()) as $client)
<details class="record-row" @if(!$client) open @endif><summary><span><strong>{{ $client ? $client->name : 'Registrar cliente' }}</strong><small>{{ $client ? ($client->company ?: $client->email) : 'Datos de contacto para preparar cotizaciones' }}</small></span></summary>
<form method="POST" action="{{ route('admin.clients.save', $client ? [$client] : []) }}">@csrf<div class="field-grid">
@foreach(['name'=>'Nombre','company'=>'Empresa','email'=>'Correo','phone'=>'Teléfono','address'=>'Dirección','country'=>'País'] as $key=>$label)
<div class="field"><label for="client_{{ $client?->id ?? 'new' }}_{{ $key }}">{{ $label }}</label><input id="client_{{ $client?->id ?? 'new' }}_{{ $key }}" name="{{ $key }}" value="{{ $client?->$key }}" @required(in_array($key, ['name','email'])) type="{{ $key === 'email' ? 'email' : 'text' }}"></div>
@endforeach
</div><div class="form-actions"><button class="button {{ $client ? 'button-secondary' : 'button-primary' }}">{{ $client ? 'Guardar cambios' : 'Crear cliente' }}</button></div></form></details>
@endforeach
{{ $clients->links() }}</div>
@endsection
