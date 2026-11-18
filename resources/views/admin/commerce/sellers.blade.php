@extends('layouts.admin')
@section('title', 'Vendedores comerciales')
@section('content')
<div class="admin-form-surface admin-form-surface-wide"><x-admin.alert>Directorio interno para confirmar asociaciones comerciales. Los visitantes escriben un nombre libre; este directorio no se muestra en el catálogo y no concede acceso administrativo.</x-admin.alert>
@if($errors->any())<x-admin.alert variant="error">{{ $errors->first() }}</x-admin.alert>@endif
@foreach(collect([null])->concat($sellers->items()) as $seller)
<section class="seller-record"><form method="POST" action="{{ route('admin.sellers.save', $seller ? [$seller] : []) }}">@csrf
<div class="field"><label for="seller-name-{{ $seller?->id ?? 'new' }}">{{ $seller ? 'Nombre del vendedor' : 'Registrar vendedor' }}</label><input id="seller-name-{{ $seller?->id ?? 'new' }}" name="name" value="{{ $seller?->name }}" maxlength="180" required></div>
@if(!$seller)<input type="hidden" name="is_active" value="1">@endif
<button class="button {{ $seller ? 'button-secondary' : 'button-primary' }}">{{ $seller ? 'Guardar cambios' : 'Registrar vendedor' }}</button></form>
@if($seller)<x-admin.status-toggle :active="$seller->is_active" :action="route('admin.sellers.status', $seller)" :label="'Cambiar disponibilidad de '.$seller->name" />@endif</section>
@endforeach
{{ $sellers->links() }}</div>
@endsection
