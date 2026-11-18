@extends('layouts.admin')
@section('title', 'Configuración comercial')
@section('content')
<div class="admin-form-surface"><form method="POST" action="{{ route('admin.commerce.settings.save') }}" class="panel">@csrf @method('PUT')
<h2>Datos del emisor</h2><p>Completa información real. Las versiones ya emitidas conservarán sus datos originales.</p>
<div class="field"><label for="pdf_review_preference">Preferencia de diseño para revisión</label><select id="pdf_review_preference" name="pdf_review_preference"><option value="">Pendiente de selección</option>@foreach(\App\Commerce\QuoteDesigns::CONCEPTS as $key=>$name)<option value="{{ $key }}" @selected(($settings['pdf_review_preference'] ?? '') === $key)>{{ strtoupper($key) }} · {{ $name }}</option>@endforeach</select><p class="field-hint">Registrar preferencia no sustituye la plantilla de emisión actual: {{ \App\Commerce\QuotePdf::VERSION }}.</p>@if(app()->environment(['local','testing']))<a href="{{ route('admin.pdf-designs.index') }}">Abrir laboratorio de propuestas PDF</a>@endif</div>
@foreach(['issuer_name'=>'Nombre comercial / razón social','issuer_contact'=>'Datos de contacto del emisor','terms'=>'Condiciones predeterminadas de cotización','public_email'=>'Correo público','public_phone'=>'Teléfono público','about'=>'Información corporativa pública'] as $key=>$label)
<div class="field"><label for="{{ $key }}">{{ $label }}</label><textarea id="{{ $key }}" name="{{ $key }}" rows="{{ in_array($key, ['terms','about','issuer_contact']) ? 5 : 2 }}" @required(in_array($key, ['issuer_name','issuer_contact']))>{{ old($key, $settings[$key] ?? '') }}</textarea>@error($key)<p class="field-error">{{ $message }}</p>@enderror</div>
@endforeach
<button class="button button-primary">Guardar configuración</button></form></div>
@endsection
