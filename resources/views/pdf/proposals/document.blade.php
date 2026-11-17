<!doctype html>
<html lang="es"><head><meta charset="utf-8"><title>USB Stock · {{ $name }} · Revisión</title>
<style>
@if(!$pdf)
@font-face { font-family:dejavusans; src:url(data:font/ttf;base64,{{ $fonts['regular'] }}) format('truetype'); font-weight:normal; }
@font-face { font-family:dejavusans; src:url(data:font/ttf;base64,{{ $fonts['bold'] }}) format('truetype'); font-weight:bold; }
@page { size: A4; margin: 14mm 14mm 17mm; }
@endif
body { font-family: dejavusans, sans-serif; font-size:9pt; color:#26282c; line-height:1.45; margin:0; }
p { margin:0 0 2mm; } table { border-collapse:collapse; width:100%; } td { vertical-align:top; } h1,h2,h3 { margin:0; font-weight:bold; }
.kicker { font-size:7.5pt; letter-spacing:1pt; text-transform:uppercase; color:#b3262d; }
.muted { color:#6b7077; font-size:8pt; } .right { text-align:right; } .number { font-size:10pt; font-weight:bold; }
.header { border:0.3mm solid #e1e3e6; border-radius:4mm; padding:4mm 5mm; margin-bottom:4mm; background-color:#ffffff; }
.header h1 { font-size:22pt; letter-spacing:-0.5pt; margin-top:4mm; } .logo { width:43mm; }
.title-meta { color:#a9252c; font-size:9pt; font-weight:bold; }
.client { border:0.3mm solid #e1e3e6; border-radius:4mm; padding:4mm; margin-bottom:4mm; background-color:#f7f7f8; }
.client td { width:50%; padding-right:3mm; } .client strong { font-size:10pt; }
.section-title { font-size:11pt; margin:5mm 0 3mm; }
.product { border:0.3mm solid #dedfe3; border-radius:4mm; padding:5mm; margin-bottom:4mm; page-break-inside:avoid; background-color:#ffffff; }
.product h2 { font-size:14pt; margin:1mm 0 2mm; } .product .photo { width:32mm; height:32mm; }
.photo-cell { width:43mm; padding-right:4mm; } .photo-fallback { width:35mm; height:30mm; padding-top:7mm; background-color:#f1f2f4; border:0.3mm solid #e0e2e6; border-radius:3mm; text-align:center; color:#7c828b; font-size:8pt; }
.description { font-size:8.5pt; color:#545962; margin:3mm 0; }
.amounts { margin-top:3mm; font-size:8pt; } .amounts td { border-top:0.2mm solid #e1e3e6; padding:3mm 1mm 0; }
.label { color:#6b7077; font-size:7pt; } .line-total { color:#a9252c; font-size:11pt; font-weight:bold; white-space:nowrap; }
.summary { border:0.3mm solid #b3262d; border-radius:4mm; padding:4mm; margin:4mm 0; background-color:#fff7f7; page-break-inside:avoid; }
.summary td { padding:1mm 0; } .total { color:#ac242b; font-size:21pt; font-weight:bold; } .summary-label { font-size:11pt; font-weight:bold; }
.terms-heading { border:0.3mm solid #dedfe3; border-radius:3mm; padding:3mm 4mm; margin:5mm 0 3mm; background-color:#f7f7f8; font-size:10pt; }
.terms p { font-size:8pt; margin-bottom:3mm; } .test { font-size:7pt; color:#805500; border:0.2mm solid #e4d6b3; border-radius:2mm; padding:2mm 3mm; margin-bottom:4mm; }
@if($concept === 'b')
.header { background-color:#b3262d; border-color:#b3262d; color:white; } .header .kicker,.header .muted,.title-meta { color:#ffffff; }
.header h1 { font-size:21pt; margin-top:2mm; } .brand-cell { background-color:white; padding:1mm 2mm; width:47mm; } .product { padding:4mm; border-color:#e4cbcd; border-radius:5mm; }
.product .photo { width:36mm; height:36mm; } .photo-cell { width:42mm; } .product h2 { font-size:17pt; color:#a9252c; }
.line-total { font-size:12pt; } .summary { background-color:#b3262d; color:white; padding:3mm; } .summary .total { color:white; }
.summary td { color:white; } .summary .muted { color:#ffe8e8; }
@endif
@if($concept === 'c')
body { font-size:8pt; } .header { padding:4mm; border-radius:3mm; } .header h1 { font-size:19pt; margin-top:2mm; }
.client { padding:4mm; margin-bottom:3mm; } .product { padding:3mm 4mm; border-radius:3mm; margin-bottom:3mm; }
.product h2 { font-size:10pt; margin:0 0 1mm; } .product .photo { width:19mm; height:19mm; } .photo-cell { width:24mm; }
.photo-fallback { width:18mm; height:13mm; padding-top:4mm; font-size:6pt; } .description { font-size:8pt; margin:2mm 0; }
.amounts { margin-top:1mm; } .amounts td { padding-top:2mm; } .summary { background-color:#f7f7f8; border-color:#d8dadd; padding:4mm; }
@endif
@if(!$pdf)
body { background:#e9ebef; } .sheet { box-sizing:border-box; width:210mm; min-height:297mm; padding:14mm 14mm 17mm; margin:10mm auto; background:white; box-shadow:0 2mm 7mm #c7cbd2; }
@media print { body { background:white; } .sheet { width:auto; min-height:0; margin:0; padding:0; box-shadow:none; } }
@endif
</style></head><body class="concept-{{ $concept }}"><div class="sheet">
<div class="test">{{ ($s['is_fixture'] ?? false) ? 'DATOS FICTICIOS · DOCUMENTO DE PRUEBA · SIN VALIDEZ COMERCIAL' : 'VISTA PREVIA DE BORRADOR · NO EMITIDA' }} · PROPUESTA {{ strtoupper($concept) }}</div>
<div class="header"><table><tr><td class="brand-cell"><img class="logo" src="{{ $images['logo'] }}" alt="USB Stock"></td><td class="right"><span class="kicker">{{ $name }}</span><br><span class="title-meta">{{ $s['folio'] }}</span><br><span class="muted">Versión {{ $s['version'] }} · {{ substr($s['issued_at'], 0, 10) }}</span></td></tr></table><h1>{{ $concept === 'b' ? 'Tu marca, en cada detalle.' : ($concept === 'c' ? 'Propuesta comercial' : 'Una propuesta para conectar.') }}</h1><p class="muted">{{ $s['issuer']['issuer_name'] }}</p><p class="muted">{!! nl2br(e($s['issuer']['issuer_contact'])) !!}</p></div>
<div class="client"><table><tr><td><p class="kicker">Preparada para</p><strong>{{ $s['customer']['name'] }}</strong><p>{{ $s['customer']['company'] ?? '' }}</p><p class="muted">{{ $s['customer']['email'] }}<br>{{ $s['customer']['phone'] ?? '' }}</p></td><td><p class="kicker">Tu contacto comercial</p><strong>{{ $s['seller'] }}</strong><p class="muted">Válida hasta {{ $s['valid_until'] }}<br>Moneda: MXN · Precios de venta sin IVA</p></td></tr></table></div>
<h2 class="section-title">{{ $concept === 'b' ? 'Selección de productos' : 'Detalle de la propuesta' }} · {{ count($s['items']) }} {{ count($s['items']) === 1 ? 'partida' : 'partidas' }}</h2>
@foreach($s['items'] as $i => $line)
<div class="product"><table><tr><td class="photo-cell">@if(isset($images['photo'.$i]))<img class="photo" src="{{ $images['photo'.$i] }}" alt="Fotografía comercial">@else<img class="photo" src="{{ $images['placeholder'] }}" alt="Fotografía no disponible"><p class="muted">Sin foto</p>@endif</td><td><p class="kicker">{{ str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) }} / {{ $line['product']['sku'] }}</p><h2>{{ $line['product']['name'] }}</h2><p><strong>{{ $line['product']['capacity_gb'] ? $line['product']['capacity_gb'].' GB' : 'Capacidad por confirmar' }}</strong> · {{ $line['product']['color'] }}</p><p class="muted">{{ $line['printing'] ?? 'Sin personalización' }}</p>@if($concept === 'b')<p class="number">{{ number_format($line['quantity']) }} piezas</p>@endif</td></tr></table>
<p class="description">{!! nl2br(e($line['product']['description'])) !!}</p>
<table class="amounts"><tr><td><span class="label">CANTIDAD</span><br><strong>{{ number_format($line['quantity']) }}</strong></td><td><span class="label">UNITARIO</span><br>{{ \App\Commerce\Money::format($line['unit_minor']) }}</td><td><span class="label">IMPRESIÓN / U.</span><br>{{ \App\Commerce\Money::format($line['printing_minor']) }}</td><td><span class="label">DESCUENTO *</span><br>{{ \App\Commerce\Money::format($line['discount_minor']) }}</td><td class="right"><span class="label">IMPORTE SIN IVA</span><br><span class="line-total">{{ \App\Commerce\Money::format($s['totals']['line_totals'][$i]) }}</span></td></tr></table></div>
@endforeach
<div class="summary"><table><tr><td style="width:48%"><p class="summary-label">Tu inversión</p><p>Una propuesta pensada para tu marca.</p><p class="muted">* Descuentos aplicados después del IVA.<br>Envío añadido al final según la regla vigente.</p></td><td><table>@foreach(['subtotal'=>'Subtotal','tax'=>'IVA','discount'=>'Descuentos','shipping'=>'Envío'] as $key=>$label)<tr><td>{{ $label }}{{ $key === 'tax' ? ' ('.\App\Commerce\Money::decimal($s['tax_basis_points']).'%)' : '' }}</td><td class="right">{{ $key === 'discount' ? '− ' : '' }}{{ \App\Commerce\Money::format($s['totals'][$key]) }}</td></tr>@endforeach<tr><td colspan="2" class="right"><span class="muted">TOTAL MXN</span><br><span class="total">{{ \App\Commerce\Money::format($s['totals']['total']) }}</span></td></tr></table></td></tr></table></div>
<h2 class="terms-heading">Condiciones comerciales</h2><div class="terms">@foreach(preg_split('/\R\s*\R/u', $s['terms']) as $paragraph)<p>{!! nl2br(e($paragraph)) !!}</p>@endforeach</div>
</div></body></html>
