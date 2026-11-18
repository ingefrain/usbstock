@extends('layouts.admin')
@section('title', $quote->exists ? $quote->label : 'Nueva cotización')
@section('content')
@php
    $editable = $quote->status === 'draft' && \App\Commerce\CommerceAccess::allows(auth()->user(), 'quotes.manage');
    $lines = old('items', $quote->items->map(fn ($i) => ['variant_id' => $i->product_variant_id, 'quantity' => $i->quantity, 'unit_price' => \App\Commerce\Money::decimal($i->unit_minor), 'printing_price' => \App\Commerce\Money::decimal($i->printing_minor), 'discount' => \App\Commerce\Money::decimal($i->discount_minor), 'printing' => $i->printing])->all());
    if (!$lines) $lines = [['quantity' => 50, 'unit_price' => '0.00', 'printing_price' => '0.00', 'discount' => '0.00']];
@endphp
<div class="admin-form-surface admin-form-surface-wide"><p><a href="{{ route('admin.quotes.index') }}">← Cotizaciones</a></p>
@if($errors->any())<x-admin.alert variant="error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-admin.alert>@endif
@if($quote->sourceRequest)<section class="panel"><p class="eyebrow">Origen de la cotización</p><h2>Solicitud {{ substr($quote->sourceRequest->uuid,0,8) }}</h2><p>Vendedor informado: <strong class="literal-name">{{ $quote->seller_original_name ?? 'Sin vendedor asignado' }}</strong></p><p class="request-observations">{{ $quote->sourceRequest->message }}</p>@if(\App\Commerce\CommerceAccess::allows(auth()->user(),'quote_requests.manage'))<a href="{{ route('admin.quote-requests.index',['status'=>'converted']) }}#request-{{ $quote->sourceRequest->uuid }}">Consultar solicitud original</a>@endif</section>@endif
<form class="panel" method="POST" action="{{ $quote->exists ? route('admin.quotes.update', $quote) : route('admin.quotes.store') }}" data-quote-editor>@csrf
@if($quote->exists) @method('PUT') @endif
<input type="hidden" name="revision" value="{{ $quote->revision }}">
<fieldset @disabled(!$editable)><legend>Cliente y condiciones</legend>
<div class="field"><label for="client_id">Cliente guardado (opcional)</label><select id="client_id" name="client_id" data-quote-client><option value="">Captura manual</option>@foreach($clients as $client)<option value="{{ $client->id }}" data-customer="{{ json_encode($client->only(['name','company','email','phone','address','country'])) }}" @selected(old('client_id', $quote->commercial_client_id) == $client->id)>{{ $client->name }} · {{ $client->email }}</option>@endforeach</select></div>
<div class="field-grid">
@foreach(['name'=>'Nombre del cliente','company'=>'Empresa','email'=>'Correo','phone'=>'Teléfono','address'=>'Dirección','country'=>'País'] as $key=>$label)
<div class="field"><label for="customer_{{ $key }}">{{ $label }}</label><input id="customer_{{ $key }}" name="customer_{{ $key }}" value="{{ old('customer_'.$key, $quote->customer[$key] ?? '') }}" @required(in_array($key, ['name','email'])) type="{{ $key === 'email' ? 'email' : 'text' }}" maxlength="{{ $key === 'address' ? 2000 : ($key === 'phone' ? 50 : 180) }}"></div>
@endforeach
<div class="field"><label for="seller_id">Vendedor interno confirmado</label><select id="seller_id" name="seller_id"><option value="">Sin asociación interna</option>@foreach($sellers as $seller)<option value="{{ $seller->id }}" @selected(old('seller_id',$quote->seller_id)==$seller->id)>{{ $seller->name }}{{ $seller->is_active ? '' : ' (inactivo; revisar)' }}</option>@endforeach</select><p class="field-hint">Cambiar esta asociación queda registrado en auditoría.</p></div>
<div class="field"><label for="seller">Nombre comercial si no hay asociación</label><input id="seller" name="seller" value="{{ old('seller', $quote->seller) }}" maxlength="180"><p class="field-hint">Puede quedar pendiente en el borrador. El nombre original del cliente no se sobrescribe.</p></div>
<div class="field"><label for="validity_days">Vigencia en días</label><input id="validity_days" type="number" name="validity_days" min="1" max="365" value="{{ old('validity_days', $quote->validity_days) }}" required></div>
<div class="field"><label for="tax_percent">IVA (%)</label><input id="tax_percent" type="number" step="0.01" name="tax_percent" min="0" max="100" value="{{ old('tax_percent', \App\Commerce\Money::decimal($quote->tax_basis_points)) }}" required></div>
<div class="field"><label for="shipping">Envío MXN</label><input id="shipping" name="shipping" inputmode="decimal" value="{{ old('shipping', \App\Commerce\Money::decimal($quote->shipping_minor)) }}" required></div></div>
<h2>Partidas</h2><p class="muted">Captura precios de venta sin IVA. El descuento es un importe por partida, aplicado después del IVA. Al guardar se recalculan los totales.</p>
<div data-quote-lines>
@foreach($lines as $i=>$line)
@include('admin.commerce.quote-line', ['index'=>$i, 'line'=>$line])
@endforeach
</div>
@if($editable)<button type="button" class="button button-secondary" data-add-quote-line>Agregar partida</button>@endif
<div class="field"><label for="terms">Condiciones comerciales</label><textarea id="terms" name="terms" rows="6" maxlength="6000">{{ old('terms', $quote->terms) }}</textarea></div>
@if($editable)<div class="form-actions"><button class="button button-primary">Guardar y calcular</button></div>@endif
</fieldset></form>
<template data-quote-line-template>@include('admin.commerce.quote-line', ['index'=>'__INDEX__', 'line'=>['quantity'=>50,'unit_price'=>'0.00','printing_price'=>'0.00','discount'=>'0.00']])</template>
@if($quote->exists)
@if($quote->status === 'draft')
<section class="panel"><h2>Fotografías y propuestas visuales</h2><p>Se utiliza la imagen del catálogo salvo que cargues una fotografía específica para esta cotización. Cada emisión conserva copias privadas. La plantilla actual no cambia: estas fotos se muestran en las propuestas de revisión.</p>
@if(app()->environment(['local','testing']))<p>Vista previa del borrador guardado: @foreach(\App\Commerce\QuoteDesigns::CONCEPTS as $key=>$name)<a target="_blank" rel="noopener" href="{{ route('admin.quotes.preview', ['quote'=>$quote,'concept'=>$key]) }}">{{ strtoupper($key) }} · {{ $name }}</a> @endforeach</p>@endif
@if($editable)<form method="POST" enctype="multipart/form-data" action="{{ route('admin.quotes.photo', $quote) }}">@csrf<input type="hidden" name="revision" value="{{ $quote->revision }}"><div class="field-grid"><div class="field"><label for="photo_variant">Partida</label><select id="photo_variant" name="variant_id" required>@foreach($quote->items as $item)<option value="{{ $item->product_variant_id }}">{{ $item->product['sku'] }} · {{ $item->product['name'] }}</option>@endforeach</select></div><div class="field"><x-admin.dropzone name="image" label="Fotografía específica" accept="image/jpeg,image/png,image/webp" :mime-types="['image/jpeg','image/png','image/webp']" :max-size-kb="5120" /><p class="field-hint">JPEG, PNG o WebP; máximo 5 MiB. No altera la foto del catálogo.</p></div></div><label><input type="checkbox" name="remove" value="1"> Quitar la imagen específica y volver a la del catálogo</label><div class="form-actions"><button class="button button-secondary">Actualizar fotografía</button></div></form>@endif</section>
@endif
<section class="panel"><h2>Totales guardados</h2><div class="table-wrap"><table><tbody>@foreach(['subtotal'=>'Subtotal','tax'=>'IVA','discount'=>'Descuentos','shipping'=>'Envío','total'=>'Total MXN'] as $key=>$label)<tr><th>{{ $label }}</th><td>{{ \App\Commerce\Money::format($quote->totals[$key]) }}</td></tr>@endforeach</tbody></table></div></section>
@if($quote->status === 'draft' && \App\Commerce\CommerceAccess::allows(auth()->user(), 'quotes.issue'))
<form class="panel" method="POST" action="{{ route('admin.quotes.issue', $quote) }}">@csrf<input type="hidden" name="revision" value="{{ $quote->revision }}"><input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}"><h2>Emitir documento</h2><p>Se emitirá el borrador guardado. Guarda primero cualquier cambio pendiente.</p><label><input type="checkbox" name="confirm" value="1" required> Revisé cliente, precios y condiciones</label><div class="form-actions"><button class="button button-primary">Emitir cotización PDF</button></div></form>
@endif
@if($quote->status === 'issued' && \App\Commerce\CommerceAccess::allows(auth()->user(), 'quotes.manage'))
<form class="panel" method="POST" action="{{ route('admin.quotes.revise', $quote) }}">@csrf<input type="hidden" name="revision" value="{{ $quote->revision }}"><label><input type="checkbox" name="confirm" value="1" required> Crear una nueva revisión conservando todos los PDF anteriores</label><button class="button button-secondary">Abrir revisión</button></form>
@endif
<section class="panel"><h2>Versiones emitidas</h2>@forelse($quote->versions as $version)<p><a href="{{ route('admin.quotes.download', [$quote, $version]) }}">Descargar versión {{ $version->version }}</a> · {{ $version->created_at->format('d/m/Y H:i') }} · Plantilla {{ $version->renderer_version }} · Cálculo {{ $version->snapshot['calculation_version'] ?? 'legacy_v1' }} · SHA-256 {{ substr($version->sha256,0,12) }}…</p>@empty<p>Aún no hay documentos emitidos.</p>@endforelse</section>
@endif
</div>
@endsection
