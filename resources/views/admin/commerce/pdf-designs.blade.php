@extends('layouts.admin')
@section('title', 'Laboratorio de cotizaciones PDF')
@section('content')
<div class="admin-form-surface admin-form-surface-wide">
    <x-admin.alert variant="info">Revisión de gerencia · datos ficticios · carta vertical. La emisión conserva la plantilla {{ \App\Commerce\QuotePdf::VERSION }}. Ninguna propuesta se activa desde este laboratorio.</x-admin.alert>
    <div class="page-intro"><div><p class="eyebrow">Evolución de la dirección B</p><h2>Tres formas de presentar tu propuesta.</h2><p class="muted">Compara fotografía, jerarquía, densidad y lectura de importes sobre el mismo contenido.</p></div></div>
    <div class="pdf-lab-grid">
        @foreach($laboratory as $key => $name)
        <section class="panel pdf-lab-card">
            <div class="pdf-concept-mark {{ $key }}" aria-hidden="true"><span>{{ strtoupper($key) }}</span><small>USB STOCK / LETTER</small></div>
            <p class="eyebrow">{{ strtoupper($key) }} · Laboratorio premium</p><h2>{{ $name }}</h2>
            <p>{{ ['b1'=>'Composición editorial equilibrada, tarjetas redondeadas y cierre económico oscuro.','b2'=>'Fotografías amplias, acento rojo y una presentación centrada en cada producto.','b3'=>'Lectura compacta, fotografías de apoyo y jerarquía ejecutiva para propuestas extensas.'][$key] }}</p>
            <a class="button button-primary" target="_blank" rel="noopener" href="{{ route('admin.pdf-designs.preview', ['concept'=>$key,'items'=>3,'format'=>'pdf']) }}">Ver PDF real · 3 partidas</a>
            <div class="pdf-sample-links">@foreach([1,3,10,35] as $count)<a target="_blank" rel="noopener" href="{{ route('admin.pdf-designs.preview', ['concept'=>$key,'items'=>$count,'format'=>'pdf']) }}">{{ $count }} {{ $count === 1 ? 'partida' : 'partidas' }} ↗</a>@endforeach</div>
            <a class="button button-tertiary" target="_blank" rel="noopener" href="{{ route('admin.pdf-designs.preview', ['concept'=>$key,'items'=>3]) }}">Inspeccionar composición HTML</a>
        </section>
        @endforeach
    </div>
    <section class="panel"><h2>Qué comparar</h2><p>Todos los PDF se generan con mPDF en Letter vertical (8.5 × 11 pulgadas). Las muestras comparten cliente, vendedor, partidas y cálculo. El caso de 35 partidas incorpora importes elevados y condiciones extensas; cada tercera partida carece de fotografía.</p><p>Subtotal, IVA, descuentos, envío y total provienen de legacy_v1. Elegir un diseño requiere aprobación de gerencia y una tarea posterior de integración. Los PDF emitidos y sus fotografías históricas permanecen intactos.</p></section>
    <details class="panel"><summary>Referencias anteriores A / B / C</summary><div class="pdf-lab-grid">
        @foreach($concepts as $key => $name)<section><p class="eyebrow">{{ strtoupper($key) }} · Referencia anterior</p><h2>{{ $name }}</h2><div class="pdf-sample-links">@foreach([1,3,10,35] as $count)<a target="_blank" rel="noopener" href="{{ route('admin.pdf-designs.preview', ['concept'=>$key,'items'=>$count,'format'=>'pdf']) }}">{{ $count }} partidas ↗</a>@endforeach</div><a target="_blank" rel="noopener" href="{{ route('admin.pdf-designs.preview', ['concept'=>$key]) }}">Abrir HTML Letter</a></section>@endforeach
    </div></details>
</div>
@endsection
