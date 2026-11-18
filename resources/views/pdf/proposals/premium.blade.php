<!doctype html>
<html lang="es"><head><meta charset="utf-8"><title>USB Stock · {{ strtoupper($concept) }} · {{ $name }}</title>
<style>
@if(!$pdf)
@font-face { font-family:dejavusans; src:url(data:font/ttf;base64,{{ $fonts['regular'] }}) format('truetype'); font-weight:normal; }
@font-face { font-family:dejavusans; src:url(data:font/ttf;base64,{{ $fonts['bold'] }}) format('truetype'); font-weight:bold; }
@page { size:Letter portrait; margin:14mm 14mm 17mm; }
@endif
body { margin:0; font-family:dejavusans,sans-serif; font-size:8.5pt; line-height:1.4; color:#20252b; }
table { width:100%; border-collapse:collapse; } td { vertical-align:top; } p { margin:0 0 2mm; } h1,h2,h3 { margin:0; }
.right { text-align:right; } .muted { color:#59616b; font-size:7.5pt; } .label { color:#59616b; font-size:6.5pt; letter-spacing:.3pt; }
.eyebrow { font-size:7pt; letter-spacing:1.1pt; color:#ac1533; text-transform:uppercase; }
.test { font-size:6.5pt; letter-spacing:.35pt; color:#68501b; border-bottom:.2mm solid #decfae; padding-bottom:2mm; margin-bottom:4mm; }
.masthead { margin-bottom:5mm; } .logo { width:46mm; } .folio { font-size:9pt; font-weight:bold; } .masthead td { width:50%; }
.hero { border:.3mm solid #e0e2e6; border-radius:5mm; padding:5mm 6mm; background-color:#f4f3f0; margin-bottom:4mm; }
.hero h1 { font-size:24pt; line-height:1.12; letter-spacing:-.7pt; margin:1mm 0 3mm; } .hero em { color:#b01634; font-style:normal; }
.hero .issuer { font-size:7.5pt; color:#51565d; }
.client { border:.25mm solid #dfe2e6; border-radius:4mm; padding:4mm 5mm; margin-bottom:5mm; }
.client td { width:50%; padding-right:3mm; } .client strong { font-size:10pt; } .client .eyebrow { margin-bottom:2mm; }
.section-title { font-size:11pt; margin:4mm 0 3mm; page-break-after:avoid; }
.closing-group { page-break-inside:avoid; }
.product { page-break-inside:avoid; border:.25mm solid #dfe2e6; border-radius:5mm; padding:5mm; margin-bottom:4mm; background-color:#fff; }
.photo-cell { width:42mm; padding-right:5mm; } .photo-well { border:.2mm solid #e4e5e6; border-radius:4mm; padding:2mm; text-align:center; background-color:#f6f6f4; }
.photo { width:32mm; height:32mm; } .product h2 { font-size:15pt; line-height:1.2; margin:2mm 0 3mm; letter-spacing:-.3pt; }
.product .number { font-size:10pt; font-weight:bold; margin-top:3mm; } .description { font-size:8pt; color:#4b535e; margin:3mm 0; line-height:1.45; }
.amounts { margin-top:3mm; } .amounts td { padding:3mm 1mm 0; border-top:.2mm solid #dfe2e6; font-size:8pt; }
.amounts td:last-child { width:28%; } .line-total { font-size:11pt; color:#ac1533; font-weight:bold; white-space:nowrap; }
.summary { page-break-inside:avoid; border:.3mm solid #222830; border-radius:5mm; background-color:#222830; color:#fff; padding:5mm; margin:5mm 0; }
.summary td { padding:1mm 0; } .summary .label,.summary .muted { color:#e1e3e7; } .summary h2 { font-size:17pt; line-height:1.2; margin-bottom:3mm; }
.summary td,.summary h2,.summary p { color:#fff; }
.summary .total { font-size:21pt; font-weight:bold; color:#fff; letter-spacing:-.5pt; } .summary .total-cell { border-top:.2mm solid #666c73; padding-top:3mm; }
.conditions-title { font-size:10pt; color:#20252b; border-bottom:.3mm solid #b01634; padding-bottom:2mm; margin:5mm 0 3mm; page-break-after:avoid; }
.terms p { font-size:7.5pt; line-height:1.45; margin-bottom:3mm; }
@if($concept === 'b2')
.hero { background-color:#b51635; border-color:#b51635; color:white; padding:4mm 6mm; } .hero h1 { font-size:24pt; } .hero em,.hero .eyebrow,.hero .issuer { color:white; }
.client { background-color:#f7f7f5; border-color:#e8e8e4; }
.product { padding:4mm; border-color:#d7dadd; border-radius:5mm; }
.photo-cell { width:58mm; padding-right:5mm; } .photo-well { padding:3mm; background-color:#efefec; border-color:#efefec; } .photo { width:46mm; height:46mm; }
.product h2 { font-size:16pt; color:#a91431; } .product .description { font-size:8pt; } .product .number { font-size:12pt; }
.summary { background-color:#b51635; border-color:#b51635; } .summary .total-cell { border-color:#d58897; }
@endif
@if($concept === 'b3')
body { font-size:8pt; } .masthead { margin-bottom:4mm; border-bottom:.5mm solid #b51635; padding-bottom:3mm; }
.hero { padding:0; border:0; border-radius:0; background-color:#fff; margin-bottom:4mm; } .hero h1 { font-size:20pt; margin:0 0 2mm; }
.client { padding:3mm 4mm; background-color:#f5f6f8; margin-bottom:4mm; } .client strong { font-size:9pt; }
.product { padding:3mm 4mm; border-radius:3mm; margin-bottom:3mm; } .photo-cell { width:28mm; padding-right:4mm; } .photo-well { padding:1mm; border-radius:3mm; } .photo { width:22mm; height:22mm; }
.product h2 { font-size:10pt; margin:1mm 0; } .product .description { font-size:7.5pt; margin:2mm 0 0; } .amounts { margin-top:2mm; } .amounts td { padding-top:2mm; font-size:7.5pt; } .line-total { font-size:9pt; }
.summary { padding:4mm; border-color:#d8dce2; background-color:#f5f6f8; color:#20252b; } .summary h2 { font-size:13pt; } .summary .total { color:#ac1533; font-size:20pt; } .summary .muted,.summary .label { color:#59616b; } .summary .total-cell { border-color:#d8dce2; }
.summary td,.summary h2,.summary p { color:#20252b; }
@endif
@if(count($s['items']) === 1)
.masthead { margin-bottom:3mm; } .hero { padding:4mm 5mm; margin-bottom:3mm; } .hero h1 { font-size:22pt; }
.client { padding:3mm 4mm; margin-bottom:3mm; } .product { padding:4mm; margin-bottom:3mm; }
.summary { padding:3mm 4mm; margin:3mm 0; } .summary td { padding:.6mm 0; } .summary .total-cell { padding-top:2mm; }
.conditions-title { margin-top:3mm; } .terms p { margin-bottom:2mm; }
@endif
@if(!$pdf)
body { background:#e8eaed; } .sheet { box-sizing:border-box; width:215.9mm; min-height:279.4mm; padding:14mm 14mm 17mm; margin:10mm auto; background:white; box-shadow:0 2mm 8mm #c8cbd0; }
@media print { body { background:white; } .sheet { width:auto; min-height:0; padding:0; margin:0; box-shadow:none; } }
@endif
</style></head><body><div class="sheet">
<div class="test">{{ ($s['is_fixture'] ?? false) ? 'DATOS FICTICIOS · DOCUMENTO DE PRUEBA · SIN VALIDEZ COMERCIAL' : 'VISTA PREVIA DE BORRADOR · NO EMITIDA' }} · {{ strtoupper($concept) }}</div>
<div class="masthead"><table><tr><td><img class="logo" src="{{ $images['logo'] }}" alt="USB Stock"></td><td class="right"><p class="eyebrow">{{ $name }}</p><p class="folio">{{ $s['folio'] }}</p><p class="muted">Versión {{ $s['version'] }} · {{ substr($s['issued_at'], 0, 10) }}</p></td></tr></table></div>
<div class="hero">
    @if($concept === 'b1')<p class="eyebrow">Una propuesta a tu medida</p><h1>Pequeños detalles.<br><em>Grandes conexiones.</em></h1>
    @elseif($concept === 'b2')<p class="eyebrow">Diseñada para tu proyecto</p><h1>Tu marca, en cada detalle.</h1>
    @else<h1>Propuesta comercial</h1>@endif
    <p class="issuer"><strong>{{ $s['issuer']['issuer_name'] }}</strong><br>{!! nl2br(e($s['issuer']['issuer_contact'])) !!}</p>
</div>
<div class="client"><table><tr><td><p class="eyebrow">Preparada para</p><strong>{{ $s['customer']['name'] }}</strong><p>{{ $s['customer']['company'] ?? '' }}</p><p class="muted">{{ $s['customer']['email'] }}<br>{{ $s['customer']['phone'] ?? '' }}@if(!empty($s['customer']['address']))<br>{{ $s['customer']['address'] }}@endif @if(!empty($s['customer']['country']))<br>{{ $s['customer']['country'] }}@endif</p></td><td><p class="eyebrow">Tu contacto comercial</p><strong>{{ $s['seller'] }}</strong><p class="muted">Vigencia hasta {{ $s['valid_until'] }}<br>Moneda: MXN · Precios de venta sin IVA</p></td></tr></table></div>
<h2 class="section-title">{{ $concept === 'b2' ? 'Tu selección' : 'Detalle de la propuesta' }} · {{ count($s['items']) }} {{ count($s['items']) === 1 ? 'partida' : 'partidas' }}</h2>
@foreach($s['items'] as $i => $line)
@if($loop->last && count($s['items']) > 1)<div class="closing-group">@endif
<div class="product"><table><tr><td class="photo-cell"><div class="photo-well">@if(isset($images['photo'.$i]))<img class="photo" src="{{ $images['photo'.$i] }}" alt="Fotografía comercial">@else<img class="photo" src="{{ $images['placeholder'] }}" alt="Fotografía no disponible">@endif</div>@if(!isset($images['photo'.$i]))<p class="muted">Fotografía no disponible</p>@endif</td><td><p class="eyebrow">{{ str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) }} / {{ $line['product']['sku'] }}</p><h2>{{ $line['product']['name'] }}</h2><p><strong>{{ $line['product']['capacity_gb'] ? $line['product']['capacity_gb'].' GB' : 'Capacidad por confirmar' }}</strong> · {{ $line['product']['color'] }}</p><p class="muted">{{ $line['printing'] ?: 'Sin personalización' }}</p>
@if($concept === 'b2')<p class="number">{{ number_format($line['quantity']) }} piezas</p>@endif
@if($concept !== 'b1')<p class="description">{!! nl2br(e($line['product']['description'])) !!}</p>@endif
</td></tr></table>
@if($concept === 'b1')<p class="description">{!! nl2br(e($line['product']['description'])) !!}</p>@endif
<table class="amounts"><tr><td><span class="label">CANTIDAD</span><br><strong>{{ number_format($line['quantity']) }}</strong></td><td><span class="label">UNITARIO</span><br>{{ \App\Commerce\Money::format($line['unit_minor']) }}</td><td><span class="label">IMPRESIÓN / U.</span><br>{{ \App\Commerce\Money::format($line['printing_minor']) }}</td><td><span class="label">DESCUENTO *</span><br>{{ \App\Commerce\Money::format($line['discount_minor']) }}</td><td class="right"><span class="label">IMPORTE SIN IVA</span><br><span class="line-total">{{ \App\Commerce\Money::format($s['totals']['line_totals'][$i]) }}</span></td></tr></table>
</div>
@endforeach
<div class="summary"><table><tr><td style="width:43%;padding-right:6mm"><p class="label">PROPUESTA ECONÓMICA</p><h2>{{ $concept === 'b3' ? 'Resumen de importes' : 'Tu proyecto, en cifras.' }}</h2><p class="muted">* Descuentos aplicados después del IVA.<br>Envío añadido al final según la regla vigente.</p></td><td><table>@foreach(['subtotal'=>'Subtotal','tax'=>'IVA','discount'=>'Descuentos','shipping'=>'Envío'] as $key=>$label)<tr><td>{{ $label }}{{ $key === 'tax' ? ' ('.\App\Commerce\Money::decimal($s['tax_basis_points']).'%)' : '' }}</td><td class="right">{{ $key === 'discount' ? '− ' : '' }}{{ \App\Commerce\Money::format($s['totals'][$key]) }}</td></tr>@endforeach<tr><td colspan="2" class="right total-cell"><span class="label">TOTAL MXN</span><br><span class="total">{{ \App\Commerce\Money::format($s['totals']['total']) }}</span></td></tr></table></td></tr></table></div>
@if(count($s['items']) > 1)</div>@endif
<h2 class="conditions-title">Condiciones comerciales</h2><div class="terms">@foreach(preg_split('/\R\s*\R/u', $s['terms']) as $paragraph)<p>{!! nl2br(e($paragraph)) !!}</p>@endforeach</div>
</div></body></html>
