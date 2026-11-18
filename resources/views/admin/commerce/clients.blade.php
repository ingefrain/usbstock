@extends('layouts.admin')
@section('title', 'Clientes')
@section('content')
<div class="admin-form-surface admin-form-surface-wide">
@if($errors->any())<x-admin.alert variant="error">{{ $errors->first() }}</x-admin.alert>@endif
@foreach(collect([null])->concat($clients->items()) as $client)
<form method="POST" action="{{ route('admin.clients.save', $client ? [$client] : []) }}" class="panel">@csrf<h2>{{ $client ? $client->name : 'Nuevo cliente' }}</h2><div class="field-grid">
@foreach(['name'=>'Nombre','company'=>'Empresa','email'=>'Correo','phone'=>'Teléfono','address'=>'Dirección','country'=>'País'] as $key=>$label)
<div class="field"><label for="client_{{ $client?->id ?? 'new' }}_{{ $key }}">{{ $label }}</label><input id="client_{{ $client?->id ?? 'new' }}_{{ $key }}" name="{{ $key }}" value="{{ $client?->$key }}" @required(in_array($key, ['name','email'])) type="{{ $key === 'email' ? 'email' : 'text' }}"></div>
@endforeach
</div><button class="button button-secondary">{{ $client ? 'Guardar cambios' : 'Crear cliente' }}</button></form>
@endforeach
{{ $clients->links() }}</div>
@endsection
