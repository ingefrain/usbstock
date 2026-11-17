@extends('layouts.admin')
@section('title', 'Revisión del archivo')
@section('content')
<div class="admin-form-surface admin-form-surface-wide"><p><a href="{{ route('admin.supplier-sync.index') }}">← Sincronizaciones</a></p><section class="panel"><h2>{{ $run->filename }}</h2><p>{{ $run->row_count }} registros · {{ $run->created_at->format('d/m/Y H:i') }}</p><p>Nuevas variantes: <strong>{{ $run->summary['new'] }}</strong> · Cambios: <strong>{{ $run->summary['changed'] }}</strong> · Sin cambios: {{ $run->summary['unchanged'] }} · Ausentes: {{ $run->summary['missing'] }}</p>
@if($run->status === 'review')<x-admin.alert variant="warning">El archivo presenta una disminución importante de registros o existencias, o cambios grandes de precio. Comprueba su integridad antes de aprobarlo.</x-admin.alert>@endif
@if($run->status === 'invalid')
<x-admin.alert variant="error">El archivo es inválido. Corrígelo y carga una nueva versión.</x-admin.alert>
@foreach($run->errors as $error)<p>Registro {{ $error['row'] }}: {{ $error['message'] }}</p>@endforeach
@endif
@if($run->status === 'completed')<x-admin.alert variant="success">Aplicada el {{ $run->applied_at->format('d/m/Y H:i') }}. Variantes desactivadas: {{ $run->summary['deactivated'] }}.</x-admin.alert>@endif
</section>
@if($rows)<section class="panel"><h2>Muestra de hasta 25 variantes</h2><div class="table-wrap"><table><thead><tr><th>SKU</th><th>Descripción del proveedor</th><th>Existencias</th><th>Costo sin IVA</th></tr></thead><tbody>@foreach($rows as $row)<tr><td>{{ $row['sku'] }}</td><td style="white-space:pre-wrap">{{ $row['supplier_description'] }}</td><td>{{ $row['stock'] }}</td><td>{{ \App\Commerce\Money::format($row['supplier_price_minor']) }}</td></tr>@endforeach</tbody></table></div></section>@endif
@if(in_array($run->status, ['ready','review']) && \App\Commerce\CommerceAccess::allows(auth()->user(), 'supplier_sync.apply'))
<form class="panel" method="POST" action="{{ route('admin.supplier-sync.apply', $run) }}">@csrf
<p>Las personalizaciones y la publicación se conservan. Los productos nuevos se crean sin publicar.</p><div class="field"><label><input type="checkbox" name="confirm" value="1" required> Revisé los cambios y quiero aplicarlos</label></div>
@if($run->summary['missing'])<div class="field"><label><input type="checkbox" name="deactivate_missing" value="1"> Desactivar disponibilidad del proveedor en las variantes ausentes</label><p class="field-hint">Si no marcas esta opción, las variantes ausentes conservan su estado.</p></div>@endif
@if($run->status === 'review')<div class="field"><label><input type="checkbox" name="approve_large_changes" value="1" required> Confirmo que los cambios importantes son correctos (requiere permiso adicional)</label></div>@endif
<x-admin.confirm-dialog id="apply-sync" title="Aplicar cambios del proveedor" message="Se actualizarán costos, descripciones y existencias del catálogo." consequence="Las cotizaciones emitidas conservarán sus datos históricos." confirm-label="Aplicar cambios" variant="warning" />
<div class="form-actions"><button class="button button-primary">Aplicar cambios</button></div></form>@endif</div>
@endsection
