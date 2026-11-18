const root = document.querySelector('[data-catalog]');
if (root && window.fetch && window.AbortController) {
    const form = root.querySelector('[data-catalog-filters]');
    const grid = root.querySelector('[data-catalog-grid]');
    const countLabel = root.querySelector('[data-catalog-count]');
    const status = root.querySelector('[data-catalog-status]');
    const more = root.querySelector('[data-catalog-next]');
    const sentinel = root.querySelector('[data-catalog-sentinel]');
    let base = root.dataset.url;
    let next = more.getAttribute('href');
    let revision = Number(root.dataset.revision);
    let controller;
    let generation = 0;
    let loading = false;
    let stopped = false;
    let restart = false;
    let autoBudget = 3;
    let timer;
    const ids = () => new Set([...grid.querySelectorAll('[data-product-id]')].map(el => el.dataset.productId));
    const endpoint = url => {
        const target = new URL(root.dataset.endpoint, location.origin);
        target.search = new URL(url, location.origin).search;
        return target.href;
    };
    const continuation = () => {
        more.hidden = !next && !restart;
        more.href = next || base;
        more.textContent = restart ? 'Actualizar resultados' : stopped ? 'Reintentar carga' : 'Cargar más modelos';
        more.setAttribute('aria-disabled', String(loading));
    };
    const observe = () => { observer?.disconnect(); if (next && !stopped && !loading && autoBudget > 0) observer?.observe(sentinel); };
    const invalidate = () => {
        generation++;
        controller?.abort();
        loading = false;
        grid.setAttribute('aria-busy', 'false');
        observer?.disconnect();
    };
    const stale = () => {
        grid.replaceChildren();
        countLabel.textContent = 'Catálogo actualizado';
        status.textContent = 'El catálogo cambió o el enlace venció. Actualiza para consultar la disponibilidad vigente.';
        next = null;
        restart = true;
        stopped = true;
        const fresh = new URL(base);
        fresh.searchParams.delete('cursor');
        base = fresh.href;
        try { sessionStorage.removeItem('usbstock.catalog.return'); } catch { /* Optional storage. */ }
    };
    async function load(replace = false, manual = false) {
        if (loading || (!replace && !next)) return;
        loading = true;
        observer?.disconnect();
        const current = generation;
        controller = new AbortController();
        const abort = controller;
        const timeout = setTimeout(() => abort.abort(), 15000);
        grid.setAttribute('aria-busy', 'true');
        status.textContent = 'Cargando modelos…';
        continuation();
        try {
            const response = await fetch(endpoint(replace ? base : next), {signal: abort.signal, headers: {'Accept': 'application/json'}, credentials: 'same-origin', cache: 'no-store'});
            if (current !== generation) return;
            if (response.status === 409) { stale(); return; }
            if (!response.ok) throw new Error(response.status === 429 ? 'Hay demasiadas solicitudes. Espera un momento y vuelve a intentar.' : 'No pudimos cargar los modelos. Revisa tu conexión y vuelve a intentar.');
            const data = await response.json();
            if (current !== generation) return;
            if (typeof data.html !== 'string' || !Number.isInteger(data.revision) || !(data.next === null || typeof data.next === 'string')) throw new Error('La respuesta no es válida. Vuelve a intentar.');
            if (!replace && revision !== data.revision) { stale(); return; }
            if (data.next && (new URL(data.next, location.origin).origin !== location.origin || (!replace && data.next === next))) {
                const error = new Error('No se pudo continuar el catálogo. Actualiza los resultados.');
                error.name = 'CatalogProtocolError';
                throw error;
            }
            const template = document.createElement('template');
            template.innerHTML = data.html;
            const seen = replace ? new Set() : ids();
            let firstLink;
            let added = 0;
            template.content.querySelectorAll('[data-product-id]').forEach(card => {
                if (seen.has(card.dataset.productId)) { card.remove(); return; }
                seen.add(card.dataset.productId);
                firstLink ??= card.querySelector('h3 a');
                added++;
            });
            if (replace) grid.replaceChildren();
            if (replace || added) grid.append(template.content);
            if (replace && Array.isArray(data.capacities)) {
                let field = form.querySelector('[name="capacity"]')?.closest('.field');
                if (data.capacities.length) {
                    if (!field) {
                        field = document.createElement('div'); field.className = 'field';
                        const label = document.createElement('label'); label.htmlFor = 'capacity'; label.textContent = 'Capacidad';
                        const select = document.createElement('select'); select.id = 'capacity'; select.name = 'capacity';
                        field.append(label, select); form.querySelector('button').before(field);
                    }
                    const select = field.querySelector('select'), selected = new URL(base).searchParams.get('capacity') || '';
                    select.replaceChildren(new Option('Todas las capacidades', ''), ...data.capacities.filter(n => Number.isInteger(n) && n > 0).map(n => new Option(`${n} GB`, String(n))));
                    select.value = selected;
                } else field?.remove();
            }
            revision = data.revision;
            next = data.next;
            if (next && !replace && !added) {
                const error = new Error('No se pudo continuar el catálogo. Actualiza los resultados.');
                error.name = 'CatalogProtocolError';
                throw error;
            }
            restart = false;
            stopped = false;
            countLabel.textContent = ids().size + ' modelos cargados';
            status.textContent = next ? added + ' modelos añadidos.' : ids().size ? 'Llegaste al final del catálogo.' : 'No hay resultados para mostrar.';
            if (manual && firstLink) firstLink.focus({preventScroll: true});
        } catch (error) {
            if (current !== generation) return;
            stopped = true;
            restart = replace || error.name === 'CatalogProtocolError';
            if (restart) {
                const fresh = new URL(base);
                fresh.searchParams.delete('cursor');
                base = fresh.href;
            }
            status.textContent = error.name === 'AbortError' ? 'La carga tardó demasiado. Vuelve a intentar.' : error.message;
        } finally {
            clearTimeout(timeout);
            if (current === generation) {
                loading = false;
                grid.setAttribute('aria-busy', 'false');
                continuation();
                observe();
            }
        }
    }
    const observer = 'IntersectionObserver' in window ? new IntersectionObserver(entries => {
        if (!entries.some(entry => entry.isIntersecting) || loading || stopped || autoBudget <= 0) return;
        // Keep the footer and manual link usable by keyboard.
        if (more.contains(document.activeElement) || document.querySelector('.shop-footer')?.contains(document.activeElement)) return;
        autoBudget--;
        load();
    }, {rootMargin: '350px 0px'}) : null;
    function filters(push = true) {
        clearTimeout(timer);
        invalidate();
        const url = new URL(form.action);
        for (const [key, value] of new FormData(form)) if (String(value).trim()) url.searchParams.set(key, String(value).trim());
        base = url.href;
        next = null;
        restart = true;
        stopped = false;
        autoBudget = 3;
        grid.replaceChildren();
        countLabel.textContent = 'Buscando modelos…';
        if (push && location.href !== base) history.pushState({}, '', base);
        load(true);
    }
    form.addEventListener('submit', event => { event.preventDefault(); filters(); });
    form.addEventListener('input', event => {
        if (event.target.name !== 'q') return;
        invalidate();
        stopped = true;
        next = null;
        clearTimeout(timer);
        timer = setTimeout(() => filters(), 350);
    });
    form.addEventListener('change', event => { if (event.target.name !== 'q') filters(); });
    root.addEventListener('click', event => {
        // A fresh navigation also resets category context and capacity options.
        if (event.target.closest('[data-catalog-clear]')) return;
    });
    more.addEventListener('click', event => { event.preventDefault(); autoBudget = 3; load(restart, true); });
    window.addEventListener('popstate', () => {
        const params = new URL(location.href).searchParams;
        for (const name of ['q', 'category', 'capacity']) if (form.elements[name]) form.elements[name].value = params.get(name) || '';
        filters(false);
    });
    for (const name of ['wheel', 'touchmove']) window.addEventListener(name, () => { autoBudget = 3; observe(); }, {passive: true});
    const save = () => {
        try {
            // An append in flight does not invalidate the rendered block; its cursor can resume.
            if (restart || ids().size > 240 || grid.innerHTML.length > 600000) return;
            sessionStorage.setItem('usbstock.catalog.return', JSON.stringify({url: location.href, base, html: grid.innerHTML, next, revision, y: scrollY, at: Date.now(), focus: document.activeElement?.closest('[data-product-id]')?.dataset.productId}));
        } catch { /* Browsers may deny storage; normal links still work. */ }
    };
    window.addEventListener('pagehide', () => { save(); invalidate(); });
    async function validateReturn(cached) {
        invalidate();
        const current = generation;
        loading = true;
        grid.setAttribute('aria-busy', 'true');
        try {
            controller = new AbortController();
            const abort = controller;
            const timeout = setTimeout(() => abort.abort(), 15000);
            let response;
            try { response = await fetch(endpoint(base), {signal: abort.signal, headers: {'Accept': 'application/json'}, cache: 'no-store'}); }
            finally { clearTimeout(timeout); }
            if (current !== generation) return;
            if (response.status === 409) { stale(); return; }
            if (!response.ok) throw new Error();
            const data = await response.json();
            if (current !== generation) return;
            if (data.revision !== (cached?.revision ?? revision)) { stale(); return; }
            if (cached) {
                grid.innerHTML = cached.html;
                next = cached.next;
                revision = cached.revision;
                countLabel.textContent = ids().size + ' modelos cargados';
                status.textContent = next ? 'Retomamos tu selección de modelos.' : 'Llegaste al final del catálogo.';
                requestAnimationFrame(() => {
                    if (cached.focus) [...grid.querySelectorAll('[data-product-id]')].find(el => el.dataset.productId === cached.focus)?.querySelector('h3 a')?.focus({preventScroll: true});
                    window.scrollTo({top: cached.y, behavior: 'instant'});
                });
            }
        } catch {
            if (current === generation) { stale(); status.textContent = 'No pudimos verificar la disponibilidad. Actualiza los resultados.'; }
        } finally {
            if (current === generation) { loading = false; grid.setAttribute('aria-busy', 'false'); continuation(); observe(); }
        }
    }
    window.addEventListener('pageshow', event => { if (event.persisted) validateReturn(); });
    let cached;
    try {
        const saved = JSON.parse(sessionStorage.getItem('usbstock.catalog.return') || 'null');
        if (performance.getEntriesByType('navigation')[0]?.type === 'back_forward' && saved?.url === location.href && Date.now() - saved.at < 600000) cached = saved;
    } catch { /* No restoration without a valid bounded snapshot. */ }
    if (cached) validateReturn(cached); else observe();
}
