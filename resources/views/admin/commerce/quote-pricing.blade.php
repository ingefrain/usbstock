@php
    $proposal = $proposals[$index] ?? null;
    $saved = $quote->items->firstWhere('product_variant_id', $line['variant_id'] ?? null);
    $savedPrice = $saved?->unit_minor ?? 0;
    $available = $proposal['available'] ?? false;
    $mode = !$saved || $savedPrice === 0 ? 'Sin precio guardado' : (['automatic'=>'Propuesta aceptada', 'manual'=>'Ajuste manual', 'retained'=>'Precio conservado'][$saved->pricing['mode'] ?? 'manual'] ?? 'Precio manual');
@endphp
<section class="quote-pricing" data-pricing-panel data-saved-price="{{ $savedPrice }}" data-initial-proposal="{{ $proposal ? json_encode($proposal) : '' }}" aria-label="Revisión de precio comercial">
    <div class="quote-pricing-heading"><h3>Precio comercial</h3><span class="commercial-badge" data-pricing-mode>{{ $mode }}</span></div>
    <dl class="quote-pricing-grid">
        <div><dt>Costo unitario del proveedor</dt><dd data-price-cost>{{ isset($proposal['context']['cost_minor']) ? \App\Commerce\Money::format($proposal['context']['cost_minor']) : '—' }}</dd></div>
        <div><dt>Cantidad evaluada</dt><dd data-price-quantity>{{ $proposal['context']['quantity'] ?? '—' }}</dd></div>
        <div><dt>Escala propuesta</dt><dd data-price-scale>{{ $proposal['basis']['scale_name'] ?? 'Sin regla disponible' }}</dd></div>
        <div><dt>Incremento sobre costo</dt><dd data-price-increment>{{ $available ? \App\Commerce\Money::decimal($proposal['basis']['increment_basis_points']).'%' : '—' }}</dd></div>
        <div class="quote-proposed"><dt>Venta unitaria propuesta</dt><dd data-price-proposed>{{ $available ? \App\Commerce\Money::format($proposal['price_minor']) : '—' }}</dd></div>
        <div><dt>Venta unitaria guardada</dt><dd data-price-saved>{{ \App\Commerce\Money::format($savedPrice) }}</dd></div>
        <div><dt>Diferencia contra guardado</dt><dd data-price-difference>{{ $available ? \App\Commerce\Money::format($proposal['price_minor'] - $savedPrice) : '—' }}</dd></div>
    </dl>
    <p class="field-hint">Importes unitarios en MXN, sin IVA ni impresión.</p>
    <p role="status" aria-live="polite" data-pricing-status>{{ $available ? 'Propuesta disponible. Puedes aplicarla y guardar; el precio guardado todavía no cambia.' : ($proposal['reason'] ?? 'Selecciona una variante para obtener su propuesta.') }}</p>
    <details class="pricing-provenance" @if(!$available) hidden @endif data-price-source><summary>Ver regla y procedencia de la propuesta</summary><p data-price-source-text>{{ $proposal['basis']['source_reference'] ?? '' }}</p></details>
    <div class="form-actions"><button class="button button-secondary" type="button" data-pricing-check>Actualizar propuesta</button><button class="button button-primary" type="button" data-pricing-use @if(!$available) hidden @endif>Usar precio propuesto</button></div>
    <div class="field"><label for="pricing_reason_{{ $index }}">Motivo de ajuste manual</label><input id="pricing_reason_{{ $index }}" name="items[{{ $index }}][pricing_reason]" value="{{ $line['pricing_reason'] ?? '' }}" maxlength="500"><p class="field-hint">Obligatorio si cambias manualmente una propuesta guardada.</p></div>
</section>
