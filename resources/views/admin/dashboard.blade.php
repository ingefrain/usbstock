@extends('layouts.admin')

@section('title', 'Resumen')

@section('content')
    <div class="page-intro">
        <p class="muted">Estado general de la administración.</p>
    </div>
    <section class="metric-grid" aria-label="Operación comercial">
        @if(\App\Commerce\CommerceAccess::allows(auth()->user(), 'products.view'))<article class="metric-card"><span>Productos publicados</span><strong>{{ \App\Models\Product::published()->count() }}</strong><a href="{{ route('admin.products.index') }}">Administrar catálogo</a></article>@endif
        @if(\App\Commerce\CommerceAccess::allows(auth()->user(), 'quotes.view'))<article class="metric-card"><span>Cotizaciones en borrador</span><strong>{{ \App\Models\Quote::where('status','draft')->count() }}</strong><a href="{{ route('admin.quotes.index') }}">Abrir cotizaciones</a></article>@endif
        @if(\App\Commerce\CommerceAccess::allows(auth()->user(), 'quote_requests.manage'))<article class="metric-card"><span>Solicitudes por atender</span><strong>{{ \App\Models\QuoteRequest::whereIn('status',['received','review'])->count() }}</strong><a href="{{ route('admin.quote-requests.index') }}">Atender solicitudes</a></article>@endif
    </section>
    <section class="metric-grid" aria-label="Resumen administrativo">
        <article class="metric-card"><span>Usuarios activos</span><strong>{{ number_format($activeUsers) }}</strong><small>Con acceso vigente</small></article>
        <article class="metric-card"><span>Usuarios inactivos</span><strong>{{ number_format($inactiveUsers) }}</strong><small>Sin acceso al panel</small></article>
        <article class="metric-card"><span>Roles activos</span><strong>{{ number_format($activeRoles) }}</strong><small>Disponibles para asignación</small></article>
    </section>
@endsection
