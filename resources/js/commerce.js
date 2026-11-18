document.querySelectorAll('[data-quote-editor]').forEach(form => {
    const lines = form.querySelector('[data-quote-lines]');
    let next = lines.children.length;
    form.querySelector('[data-add-quote-line]')?.addEventListener('click', () => {
        if (lines.children.length >= 100) return;
        const template = document.querySelector('[data-quote-line-template]');
        lines.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(next++)));
        preparePricing(lines.lastElementChild);
        lines.lastElementChild.querySelector('select')?.focus();
    });
    form.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-quote-line]');
        if (button && lines.children.length > 1) button.closest('[data-quote-line]').remove();
    });
    form.querySelector('[data-quote-client]')?.addEventListener('change', event => {
        const data = event.target.selectedOptions[0].dataset.customer;
        if (!data) return;
        for (const [key, value] of Object.entries(JSON.parse(data))) {
            const input = form.querySelector(`[name="customer_${key}"]`);
            if (input) input.value = value ?? '';
        }
    });
    function preparePricing(row) {
        const status = row.querySelector('[data-pricing-status]');
        if (!status || !form.dataset.pricingUrl) return;
        const variant = row.querySelector('select');
        const quantity = row.querySelector('[name$="[quantity]"]');
        const price = row.querySelector('[name$="[unit_price]"]');
        const action = row.querySelector('[data-pricing-action]');
        const fingerprint = row.querySelector('[data-pricing-fingerprint]');
        const use = row.querySelector('[data-pricing-use]');
        const check = row.querySelector('[data-pricing-check]');
        let proposal, controller, generation = 0, timer;
        const query = () => `${variant.value}:${quantity.value}`;
        const money = value => new Intl.NumberFormat('es-MX', {style: 'currency', currency: 'MXN'}).format(value / 100);
        async function consult() {
            clearTimeout(timer); controller?.abort(); controller = new AbortController();
            const current = ++generation, requested = query();
            proposal = null; use.hidden = true;
            if (!variant.value || !quantity.validity.valid || Number(quantity.value) < 50) {
                status.textContent = 'Selecciona una variante y una cantidad válida de al menos 50 piezas.'; return;
            }
            const url = new URL(form.dataset.pricingUrl, location.origin);
            url.searchParams.set('variant_id', variant.value); url.searchParams.set('quantity', quantity.value);
            const activeController = controller;
            const timeout = setTimeout(() => activeController.abort(), 7000);
            status.textContent = 'Consultando costo y escala vigentes…';
            try {
                const response = await fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin', cache: 'no-store', signal: activeController.signal});
                if (!response.ok) throw Error();
                const result = await response.json();
                if (current !== generation || requested !== query() || !row.isConnected) return;
                if (!result.available) { status.textContent = result.reason; return; }
                proposal = {...result, requested};
                const b = result.basis;
                status.textContent = `Propuesta: ${money(result.price_minor)} MXN por pieza. ${b.scale_name}, incremento ${b.increment_basis_points / 100}%; costo propio ${money(b.cost_minor)}, cantidad ${b.quantity}, importe base ${money(b.base_minor)}. Procedencia: ${b.source_reference}. El precio capturado no cambió.`;
                use.hidden = false;
            } catch { if (current === generation) status.textContent = 'No se pudo obtener la propuesta. El precio capturado se conserva; puedes volver a consultar.'; }
            finally { clearTimeout(timeout); }
        }
        function changed() {
            ++generation; controller?.abort(); clearTimeout(timer); proposal = null; use.hidden = true;
            action.value = 'keep'; fingerprint.value = '';
            status.textContent = 'La cantidad o variante cambió. Se conserva el precio capturado mientras se consulta una nueva propuesta.';
            timer = setTimeout(consult, 350);
        }
        variant.addEventListener('change', changed); quantity.addEventListener('input', changed);
        check.addEventListener('click', consult);
        price.addEventListener('input', () => { action.value = 'keep'; fingerprint.value = ''; });
        use.addEventListener('click', () => {
            if (!proposal || proposal.requested !== query()) return;
            price.value = proposal.price; action.value = 'automatic'; fingerprint.value = proposal.fingerprint;
            status.textContent = `Precio propuesto ${money(proposal.price_minor)} MXN aceptado para esta partida. Guarda el borrador para conservar el cálculo y su procedencia.`;
            use.hidden = true;
        });
    }
    [...lines.children].forEach(preparePricing);
});
