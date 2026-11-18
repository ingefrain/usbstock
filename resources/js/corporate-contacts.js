const panel = document.querySelector('[data-contacts]');
const trigger = document.querySelector('[data-contacts-open]');
if (panel && trigger && window.fetch) {
    const list = panel.querySelector('[data-contacts-list]');
    const status = panel.querySelector('[data-contacts-status]');
    const recovery = panel.querySelector('[data-contacts-recovery]');
    const closeButton = panel.querySelector('[data-contacts-close]');
    const clicked = new Set();
    let controller;
    let generation = 0;
    trigger.hidden = false;
    document.body.classList.add('contacts-ready');
    const safeContact = value => {
        try { const url = new URL(value); return url.origin === 'https://wa.me' && /^\/[1-9][0-9]{9,14}$/.test(url.pathname) && !url.username && !url.password ? url.href : null; } catch { return null; }
    };
    // Fixed icons only. All service text is inserted with textContent.
    const icon = kind => {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true');
        svg.innerHTML = kind === 'person'
            ? '<circle cx="12" cy="8" r="4" fill="currentColor"/><path d="M4 22v-3a8 8 0 0 1 16 0v3" fill="currentColor"/>'
            : kind === 'online'
                ? '<g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 5a10 10 0 0 0 0 14M19 5a10 10 0 0 1 0 14M8 8a6 6 0 0 0 0 8M16 8a6 6 0 0 1 0 8"/></g><circle cx="12" cy="12" r="2" fill="currentColor"/>'
                : '<g fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="m6 6 12 12"/></g>';
        return svg;
    };
    function render(sellers) {
        const nodes = sellers.map(seller => {
            const item = document.createElement('li');
            const url = seller.available === true && safeContact(seller.url);
            const row = document.createElement(url ? 'a' : 'div');
            row.className = 'corporate-seller';
            const avatar = document.createElement('span');
            avatar.className = 'corporate-avatar'; avatar.append(icon('person'));
            if (typeof seller.photo === 'string' && /^https:\/\/grupotalento\.com\.mx\//.test(seller.photo)) {
                const img = document.createElement('img');
                img.alt = ''; img.width = 50; img.height = 50; img.referrerPolicy = 'no-referrer';
                img.addEventListener('error', () => img.remove(), {once: true}); img.src = seller.photo; avatar.append(img);
            }
            const text = document.createElement('div'); text.className = 'corporate-person';
            const role = document.createElement('span'); role.className = 'corporate-role'; role.textContent = 'ventas';
            const name = document.createElement('strong'); name.textContent = seller.name;
            const state = document.createElement('span'); state.className = 'corporate-status';
            state.append(icon(url ? 'online' : 'offline'), document.createTextNode(url ? 'Online' : 'Offline'));
            item.classList.toggle('is-available', Boolean(url)); text.append(role, name, state);
            row.append(avatar, text);
            if (url) {
                row.dataset.contactId = String(seller.id); row.href = url; row.target = '_blank'; row.rel = 'noopener noreferrer';
                row.setAttribute('aria-label', `Conversar por WhatsApp con ${seller.name} (Online)`);
                row.addEventListener('click', () => {
                    if (typeof seller.ticket !== 'string' || clicked.has(seller.id)) return;
                    clicked.add(seller.id);
                    // Navigation remains a normal link; metrics can fail without blocking it.
                    fetch(panel.dataset.clickUrl, {method: 'POST', credentials: 'same-origin', keepalive: true,
                        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
                        body: JSON.stringify({ticket: seller.ticket})}).catch(() => {}).finally(() => {
                        if (!panel.hidden) load(true);
                    });
                });
            }
            item.append(row);
            return item;
        });
        list.replaceChildren(...nodes);
    }
    async function load(refresh = false) {
        controller?.abort(); controller = new AbortController();
        const abort = controller, current = ++generation;
        const timeout = setTimeout(() => abort.abort(), 7000);
        const focusedLink = document.activeElement?.closest('[data-contact-id]');
        status.textContent = 'Consultando disponibilidad…'; status.classList.toggle('sr-only', refresh && list.children.length > 0); recovery.hidden = true;
        if (!refresh) list.replaceChildren();
        try {
            const response = await fetch(panel.dataset.listUrl, {headers: {'Accept': 'application/json'}, credentials: 'same-origin', cache: 'no-store', signal: abort.signal});
            if (!response.ok) throw new Error();
            const data = await response.json();
            if (current !== generation) return;
            if (!Array.isArray(data.sellers) || data.sellers.length > 80 || !data.sellers.every(s => typeof s.name === 'string' && Number.isInteger(s.id))) throw new Error();
            const restoreFocus = refresh && focusedLink && document.activeElement === focusedLink;
            render(data.sellers);
            status.textContent = data.sellers.length ? 'Disponibilidad actual de nuestro equipo.' : 'No hay asesores disponibles por el momento.';
            status.classList.toggle('sr-only', data.sellers.length > 0); recovery.hidden = data.sellers.length > 0;
            if (restoreFocus && !panel.hidden) {
                const replacement = [...list.querySelectorAll('a')].find(link => link.dataset.contactId === focusedLink.dataset.contactId);
                (replacement ?? closeButton).focus({preventScroll: true});
            }
        } catch {
            if (current === generation) { list.replaceChildren(); status.classList.remove('sr-only'); status.textContent = 'No pudimos consultar el directorio. Puedes volver a intentar o visitar Atención comercial.'; recovery.hidden = false; }
        } finally { clearTimeout(timeout); }
    }
    function close(returnFocus = true) {
        if (panel.hidden) return;
        generation++; controller?.abort(); panel.hidden = true;
        trigger.hidden = false; trigger.setAttribute('aria-expanded', 'false');
        if (returnFocus) trigger.focus({preventScroll: true});
    }
    trigger.addEventListener('click', () => {
        if (!panel.hidden) return;
        document.dispatchEvent(new Event('contacts:opening'));
        document.querySelector('[data-cart-dismiss]')?.click();
        panel.hidden = false; trigger.hidden = true; trigger.setAttribute('aria-expanded', 'true');
        if (!matchMedia('(prefers-reduced-motion:reduce)').matches) panel.animate([{opacity: 0, transform: 'translateY(6px)'}, {opacity: 1, transform: 'translateY(0)'}], {duration: 160});
        closeButton.focus({preventScroll: true}); load();
    });
    closeButton.addEventListener('click', () => close());
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && !panel.hidden) { event.preventDefault(); close(); } });
    // A nonmodal panel never traps focus or locks page scroll. Yield to navigation and forms.
    document.addEventListener('focusin', event => {
        if (!panel.contains(event.target) && event.target.matches('input, select, textarea, [contenteditable="true"], dialog *, [aria-modal="true"] *')) close(false);
    });
    document.addEventListener('toggle', event => { if (event.target.matches('.category-group, dialog') && event.target.open) close(false); }, true);
    document.querySelector('.mobile-menu')?.addEventListener('click', () => close(false));
    panel.querySelector('[data-contacts-retry]').addEventListener('click', () => load());
}
