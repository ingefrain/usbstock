@extends('layouts.admin')
@section('title', 'Configuración comercial')
@section('content')
<div class="admin-form-surface"><form method="POST" action="{{ route('admin.commerce.settings.save') }}" class="panel">@csrf @method('PUT')
<h2>Datos del emisor</h2><p>Completa información real. Las versiones ya emitidas conservarán sus datos originales.</p>
@foreach(['issuer_name'=>'Nombre comercial / razón social','issuer_contact'=>'Datos de contacto del emisor','terms'=>'Condiciones predeterminadas de cotización','public_email'=>'Correo público','public_phone'=>'Teléfono público','about'=>'Información corporativa pública'] as $key=>$label)
<div class="field"><label for="{{ $key }}">{{ $label }}</label><textarea id="{{ $key }}" name="{{ $key }}" rows="{{ in_array($key, ['terms','about','issuer_contact']) ? 5 : 2 }}" @required(in_array($key, ['issuer_name','issuer_contact']))>{{ old($key, $settings[$key] ?? '') }}</textarea>@error($key)<p class="field-error">{{ $message }}</p>@enderror</div>
@endforeach
<button class="button button-primary">Guardar configuración</button></form></div>
@endsection
