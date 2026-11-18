document.querySelectorAll('[data-quote-editor]').forEach(form => {
    const lines = form.querySelector('[data-quote-lines]');
    const pricingRows = new Map();
    const applyZero = form.querySelector('[data-pricing-apply-zero]');
    const summary = form.querySelector('[data-pricing-summary]');
    function updateSummary() {
        const count = [...pricingRows.entries()].filter(([row, pricing]) => row.isConnected && pricing.eligible()).length;
        if (applyZero) applyZero.disabled = count === 0;
        if (summary) summary.textContent = count ? `${count} partida${count === 1 ? '' : 's'} en cero con propuesta disponible. Los demás precios se conservan.` : 'No hay partidas en cero pendientes con propuesta disponible. Los precios capturados se conservan.';
    }
    applyZero?.addEventListener('click', () => {
        let count = 0;
        for (const [row, pricing] of pricingRows) if (row.isConnected && pricing.eligible()) { pricing.apply(); count++; }
        updateSummary();
        if (summary) summary.textContent = `Se aplicaron ${count} propuestas al formulario. Pulsa «Guardar y calcular» para conservar los precios y su auditoría.`;
    });
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
        if (button && lines.children.length > 1) { button.closest('[data-quote-line]').remove(); updateSummary(); }
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
        const panel = row.querySelector('[data-pricing-panel]');
        const savedPrice = Number(panel.dataset.savedPrice);
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
        const show = (name, value) => { panel.querySelector(`[data-price-${name}]`).textContent = value; };
        function display(result) {
            const available = result?.available === true;
            proposal = available ? {...result, requested: query()} : null;
            use.hidden = !available;
            show('cost', Number.isInteger(result?.context?.cost_minor) ? money(result.context.cost_minor) : '—');
            show('quantity', result?.context?.quantity ?? '—');
            show('scale', available ? `${result.basis.scale_name} · v${result.basis.scale_revision}` : 'Sin regla disponible');
            show('increment', available ? `${result.basis.increment_basis_points / 100}%` : '—');
            show('proposed', available ? money(result.price_minor) : '—');
            show('difference', available ? money(result.price_minor - savedPrice) : '—');
            panel.querySelector('[data-price-source]').hidden = !available;
            show('source-text', available ? result.basis.source_reference : '');
            status.textContent = available ? `Propuesta disponible: ${money(result.price_minor)} MXN por pieza. El precio guardado no cambió.` : (result?.reason ?? 'Selecciona una variante para obtener su propuesta.');
            updateSummary();
        }
        function apply() {
            if (!proposal || proposal.requested !== query()) return;
            price.value = proposal.price; action.value = 'automatic'; fingerprint.value = proposal.fingerprint;
            panel.querySelector('[data-pricing-mode]').textContent = 'Propuesta aceptada · pendiente de guardar';
            status.textContent = `Precio propuesto ${money(proposal.price_minor)} MXN aceptado. Pulsa «Guardar y calcular» para conservarlo junto con su procedencia.`;
            use.hidden = true; updateSummary();
        }
        pricingRows.set(row, {apply, eligible: () => savedPrice === 0 && /^0+(?:\.0{1,2})?$/.test(price.value.trim()) && proposal?.requested === query()});
        async function consult() {
            clearTimeout(timer); controller?.abort(); controller = new AbortController();
            const current = ++generation, requested = query();
            display(null);
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
                display(result);
            } catch { if (current === generation) status.textContent = 'No se pudo obtener la propuesta. El precio capturado se conserva; puedes volver a consultar.'; }
            finally { clearTimeout(timeout); }
        }
        function changed() {
            ++generation; controller?.abort(); clearTimeout(timer); display(null);
            action.value = 'keep'; fingerprint.value = '';
            status.textContent = 'La cantidad o variante cambió. Se conserva el precio capturado mientras se consulta una nueva propuesta.';
            timer = setTimeout(consult, 350);
        }
        variant.addEventListener('change', changed); quantity.addEventListener('input', changed);
        check.addEventListener('click', consult);
        price.addEventListener('input', () => {
            action.value = 'keep'; fingerprint.value = ''; use.hidden = !proposal;
            panel.querySelector('[data-pricing-mode]').textContent = 'Edición manual · pendiente de guardar'; updateSummary();
        });
        use.addEventListener('click', apply);
        if (panel.dataset.initialProposal) {
            try { display(JSON.parse(panel.dataset.initialProposal)); }
            catch { display(null); }
        }
        updateSummary();
    }
    [...lines.children].forEach(preparePricing);
});
