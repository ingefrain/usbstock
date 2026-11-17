@extends('layouts.admin')
@section('title', 'Categorías comerciales')
@section('content')
<div class="admin-form-surface admin-form-surface-wide"><p><a href="{{ route('admin.products.index') }}">← Productos</a></p>
@php($manage = \App\Commerce\CommerceAccess::allows(auth()->user(), 'products.manage'))
@foreach($categories->concat($manage ? [new \App\Models\ProductCategory] : []) as $category)
<form method="POST" action="{{ route('admin.product-categories.save', $category->exists ? $category : null) }}" class="panel">@csrf
<h2>{{ $category->exists ? $category->name.' · '.$category->products_count.' productos' : 'Nueva categoría' }}</h2>
<fieldset @disabled(!$manage)><div class="field-grid"><div class="field"><label for="name-{{ $loop->index }}">Nombre</label><input id="name-{{ $loop->index }}" name="name" value="{{ $category->name }}" required maxlength="120"></div><div class="field"><label for="slug-{{ $loop->index }}">Slug</label><input id="slug-{{ $loop->index }}" name="slug" value="{{ $category->slug }}" required maxlength="140" pattern="[A-Za-z0-9_-]+"></div><div class="field"><label for="visibility-{{ $loop->index }}">Publicación</label><select id="visibility-{{ $loop->index }}" name="is_published"><option value="0" @selected(!$category->is_published)>Sin publicar</option><option value="1" @selected($category->is_published)>Publicada</option></select></div></div><div class="field"><label for="desc-{{ $loop->index }}">Descripción</label><textarea id="desc-{{ $loop->index }}" name="description" maxlength="2000">{{ $category->description }}</textarea></div>@if($manage)<div class="form-actions"><button class="button button-primary">Guardar categoría</button></div>@endif</fieldset></form>
@endforeach</div>
@endsection
