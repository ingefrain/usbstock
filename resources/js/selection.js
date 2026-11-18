// Progressive enhancement: the same forms remain ordinary POSTs without JavaScript.
const shop = document.querySelector('.shop[data-cart-state]');
if (shop && window.fetch) {
    const panel = document.querySelector('[data-selection-summary]');
    const tab = document.querySelector('[data-quote-tab]');
    const feedback = document.querySelector('[data-cart-feedback]');
    const channel = 'BroadcastChannel' in window ? new BroadcastChannel('usb-selection') : null;
    let revision = Number(shop.dataset.cartRevision || 0);
    let busy = false;
    let refreshPending = false;
    let feedbackTimer;
    let refreshSequence = 0;

    function message(text, error = false) {
        clearTimeout(feedbackTimer);
        feedback.querySelector('[data-cart-message]').textContent = text;
        const announcement = document.querySelector('[data-cart-announcement]');
        announcement.textContent = '';
        requestAnimationFrame(() => { announcement.textContent = text; });
        feedback.classList.toggle('is-error', error);
        feedback.hidden = false;
        if (!error) feedbackTimer = setTimeout(() => { feedback.hidden = true; }, 6500);
    }
    document.querySelector('[data-cart-dismiss]')?.addEventListener('click', () => { feedback.hidden = true; });

    function apply(data, replacePanel = false) {
        const state = data.selection;
        if (!state || !Number.isInteger(state.revision) || state.revision < revision) return false;
        revision = state.revision;
        shop.dataset.cartRevision = revision;
        document.querySelectorAll('[data-cart-count]').forEach(node => { node.textContent = state.line_count; });
        tab.hidden = state.line_count === 0;
        tab.setAttribute('aria-label', `Revisar cotización, ${state.line_count} partidas`);
        document.querySelectorAll('input[name="request_key"]').forEach(node => { node.value = state.request_key || ''; });
        if (replacePanel && panel && typeof data.html === 'string') {
            // Only our same-origin, Blade-escaped fragment is accepted, never an error page.
            panel.innerHTML = data.html;
            document.querySelector('[data-request-contact]').hidden = state.line_count === 0;
            document.querySelector('[data-selection-layout]').classList.toggle('is-empty', state.line_count === 0);
        }
        return true;
    }

    const errors = {
        401: 'No pudimos autorizar esta acción. Actualiza la página e inténtalo de nuevo.',
        403: 'No pudimos autorizar esta acción. Actualiza la página e inténtalo de nuevo.',
        419: 'Tu sesión caducó. Actualiza la página antes de continuar.',
        422: 'Revisa la variante, la cantidad mínima de 50 piezas y la impresión (máximo 500 caracteres).',
        409: 'Cambió tu selección o la disponibilidad. Revisa la cantidad y las variantes antes de continuar.',
        429: 'Has realizado varias acciones seguidas. Espera un momento antes de continuar.',
    };
    const uncertain = 'No pudimos confirmar el cambio. Revisa tu cotización antes de intentarlo de nuevo.';
    const headers = () => ({ Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        ...(panel ? { 'X-Selection-View': 'review' } : {}) });

    async function refresh() {
        if (busy) { refreshPending = true; return; }
        const sequence = ++refreshSequence;
        try {
            const response = await fetch(shop.dataset.cartState, { credentials: 'same-origin', headers: headers(), cache: 'no-store' });
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) return;
            const data = await response.json();
            if (busy || sequence !== refreshSequence) { refreshPending = true; return; }
            // Preserve unsubmitted edits when no other tab has changed the selection.
            const changed = data.selection?.revision > revision;
            if (apply(data, changed) && changed && panel) message('Tu selección se actualizó desde otra pestaña. Revisa las cantidades.');
        } catch { /* A failed background read never clears the selection or repeats a mutation. */ }
    }

    document.addEventListener('submit', async event => {
        if (busy && event.target.closest('[data-request-contact]')) { event.preventDefault(); return; }
        const form = event.target.closest('form[data-cart-action]');
        if (!form) return;
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        const body = new FormData(form);
        body.set('revision', String(revision));
        const focused = document.activeElement;
        const focusedId = focused?.id;
        const rowUuid = form.closest('[data-selection-variant]')?.dataset.selectionVariant;
        const action = form.dataset.cartAction;
        const disabled = [...document.querySelectorAll('[data-cart-action] input, [data-cart-action] button, [data-cart-action] select, [data-request-contact] button[type="submit"], [data-request-contact] .quote-button')]
            .map(node => [node, node.disabled]);
        busy = true;
        ++refreshSequence; // Ignore a GET started before this mutation.
        disabled.forEach(([node]) => { node.disabled = true; });
        form.setAttribute('aria-busy', 'true');
        const button = event.submitter || form.querySelector('button');
        button?.classList.add('is-cart-pending');
        try {
            const response = await fetch(form.action, { method: 'POST', body, credentials: 'same-origin', headers: headers(), cache: 'no-store' });
            const data = response.headers.get('content-type')?.includes('application/json') ? await response.json() : null;
            if (response.ok && data?.success === true && data.selection) {
                apply(data, true);
                message(action === 'remove' ? 'Quitamos la variante de tu cotización.' : action === 'update' ? 'Actualizamos tu selección.' : 'Agregamos la variante a tu cotización.');
                tab.classList.remove('is-confirmed');
                requestAnimationFrame(() => tab.classList.add('is-confirmed'));
                channel?.postMessage({ revision });
            } else {
                if (response.status === 409 && data?.success === false) apply(data, true);
                const availability = data?.availability;
                const quantity = availability?.maximum_quantity;
                const stockMessage = response.status === 409 && Number.isInteger(quantity) && quantity >= 0
                    ? availability.code === 'minimum_stock' ? 'Este producto no cuenta actualmente con las 50 piezas mínimas disponibles para cotizar.'
                        : availability.code === 'stock_exceeded' ? `La cantidad supera las existencias. El máximo disponible es de ${quantity.toLocaleString('es-MX')} piezas.` : null
                    : null;
                message(stockMessage || errors[response.status] || uncertain, true);
            }
        } catch {
            message(uncertain, true);
        } finally {
            disabled.forEach(([node, wasDisabled]) => { node.disabled = wasDisabled; });
            form.removeAttribute('aria-busy');
            button?.classList.remove('is-cart-pending');
            busy = false;
            // Restore focus only when replacing the focused review row detached that control.
            if (panel && focused && !focused.isConnected && rowUuid) {
                const row = [...panel.querySelectorAll('[data-selection-variant]')].find(node => node.dataset.selectionVariant === rowUuid);
                const destination = (focusedId && document.getElementById(focusedId)) || row?.querySelector('button') || panel.querySelector('input, button, a');
                destination?.focus({ preventScroll: true });
            }
            if (refreshPending) { refreshPending = false; refresh(); }
        }
    });
    channel?.addEventListener('message', event => { if (event.data?.revision > revision) refresh(); });
    window.addEventListener('pageshow', event => { if (event.persisted) refresh(); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    // Hide the mobile affordance while a text control/virtual keyboard or dialog needs the space.
    const keyboard = () => {
        const control = document.activeElement;
        const editing = control?.matches('textarea, select, input:not([type="hidden"]):not([type="radio"]):not([type="checkbox"]):not([type="submit"])');
        shop.classList.toggle('cart-keyboard', Boolean(editing || (window.visualViewport && window.innerHeight - window.visualViewport.height > 140)));
    };
    document.addEventListener('focusin', keyboard);
    document.addEventListener('focusout', () => setTimeout(keyboard, 0));
    window.visualViewport?.addEventListener('resize', keyboard);
}
