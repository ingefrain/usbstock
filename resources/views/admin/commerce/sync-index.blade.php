@extends('layouts.admin')
@section('title', 'Sincronizaciones de proveedor')
@section('content')
<div class="admin-form-surface admin-form-surface-wide"><p class="muted">Carga el archivo local, revisa los cambios y decide cuándo aplicarlos.</p>
@if(\App\Commerce\CommerceAccess::allows(auth()->user(), 'supplier_sync.create'))
<form method="POST" enctype="multipart/form-data" action="{{ route('admin.supplier-sync.store') }}" class="panel">@csrf<div class="field"><label for="file">Archivo del proveedor</label><input id="file" type="file" name="file" accept=".csv,text/csv" required><p class="field-hint">UTF-8, hasta 8 MiB. Encabezados: SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA. Esta acción no modifica productos.</p><x-admin.field-error name="file" /></div><div class="form-actions"><button class="button button-primary">Revisar archivo</button></div></form>@endif
<section class="panel"><h2>Historial</h2><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Archivo</th><th>Estado</th><th>Registros</th><th></th></tr></thead><tbody>@forelse($runs as $run)<tr><td>{{ $run->created_at->format('d/m/Y H:i') }}</td><td>{{ $run->filename }}</td><td>{{ ['ready'=>'Lista para aplicar','review'=>'Revisión adicional','invalid'=>'Inválida','completed'=>'Aplicada'][$run->status] ?? $run->status }}</td><td>{{ $run->row_count }}</td><td><a href="{{ route('admin.supplier-sync.show', $run) }}">Ver detalles</a></td></tr>@empty<tr><td colspan="5">Todavía no hay sincronizaciones.</td></tr>@endforelse</tbody></table></div>{{ $runs->links() }}</section></div>
@endsection
