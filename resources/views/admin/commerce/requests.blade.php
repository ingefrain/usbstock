@extends('layouts.admin')
@section('title', 'Solicitudes de cotización')
@section('content')
<div class="admin-form-surface admin-form-surface-wide">@forelse($requests as $request)<section class="panel"><h2>{{ $request->customer['name'] }}</h2><p>{{ $request->customer['email'] }} · {{ $request->customer['phone'] }}</p><p>{{ $request->created_at->format('d/m/Y H:i') }} · {{ ['received'=>'Recibida','review'=>'En revisión','discarded'=>'Descartada','converted'=>'Convertida'][$request->status] }}</p><p style="white-space:pre-wrap">{{ $request->message }}</p><div class="table-wrap"><table><thead><tr><th>SKU</th><th>Producto</th><th>Color</th><th>Cantidad</th></tr></thead><tbody>@foreach($request->items as $item)<tr><td>{{ $item['sku'] }}</td><td>{{ $item['name'] }}</td><td>{{ $item['color'] }}</td><td>{{ $item['quantity'] }}</td></tr>@endforeach</tbody></table></div>
@if($request->quote_id)<a href="{{ route('admin.quotes.show',$request->quote) }}">Abrir cotización</a>@else
@if($request->status !== 'discarded' && \App\Commerce\CommerceAccess::allows(auth()->user(),'quotes.manage'))<form method="POST" action="{{ route('admin.quote-requests.convert',$request) }}">@csrf<button class="button button-primary">Crear borrador de cotización</button></form>@endif
<form method="POST" action="{{ route('admin.quote-requests.status',$request) }}">@csrf<label>Estado <select name="status"><option value="review">En revisión</option><option value="discarded">Descartada</option></select></label><label><input type="checkbox" name="confirm" value="1" required> Confirmo el cambio</label><button class="button button-secondary">Guardar estado</button></form>
@endif
</section>@empty<section class="panel"><p>Aún no hay solicitudes públicas.</p></section>@endforelse{{ $requests->links() }}</div>
@endsection
