@extends('layouts.admin')
@section('title', 'Cotizaciones')
@section('content')
<div class="admin-form-surface admin-form-surface-wide"><section class="panel"><div class="panel-heading"><h2>Cotizaciones comerciales</h2>@if(\App\Commerce\CommerceAccess::allows(auth()->user(), 'quotes.manage'))<a class="button button-primary" href="{{ route('admin.quotes.create') }}">Nueva cotización</a>@endif</div>
<form method="GET" class="field-grid"><div class="field"><label for="q">Folio</label><input id="q" name="q" value="{{ request('q') }}"></div><div class="field"><label for="status">Estado</label><select id="status" name="status"><option value="">Todos</option><option value="draft" @selected(request('status') === 'draft')>Borradores</option><option value="issued" @selected(request('status') === 'issued')>Emitidas</option></select></div><button class="button button-secondary">Buscar</button></form>
<div class="table-wrap"><table><thead><tr><th>Folio</th><th>Cliente</th><th>Estado</th><th>Total MXN</th><th>Actualizada</th></tr></thead><tbody>
@forelse($quotes as $quote)<tr><td><a href="{{ route('admin.quotes.show', $quote) }}">{{ $quote->label }}</a></td><td>{{ $quote->customer['name'] }}</td><td>{{ $quote->status === 'draft' ? 'Borrador' : 'Emitida' }}</td><td>{{ \App\Commerce\Money::format($quote->totals['total']) }}</td><td>{{ $quote->updated_at->format('d/m/Y') }}</td></tr>@empty<tr><td colspan="5">Aún no hay cotizaciones con estos filtros.</td></tr>@endforelse
</tbody></table></div>{{ $quotes->links() }}</section></div>
@endsection
