const panel = document.querySelector('[data-contacts]');
const trigger = document.querySelector('[data-contacts-open]');
if (panel?.showModal && trigger && window.fetch) {
    const list = panel.querySelector('[data-contacts-list]');
    const status = panel.querySelector('[data-contacts-status]');
    const retry = panel.querySelector('[data-contacts-retry]');
    const clicked = new Set();
    let controller;
    let generation = 0;
    trigger.hidden = false;
    const safeContact = value => {
        try { const url = new URL(value); return url.origin === 'https://wa.me' && /^\/[1-9][0-9]{9,14}$/.test(url.pathname) && !url.username && !url.password ? url.href : null; } catch { return null; }
    };
    function render(sellers) {
        const nodes = sellers.map(seller => {
            const item = document.createElement('li');
            const avatar = document.createElement('span');
            avatar.className = 'corporate-avatar';
            avatar.textContent = seller.name.trim().slice(0, 1).toUpperCase();
            if (typeof seller.photo === 'string' && /^https:\/\/grupotalento\.com\.mx\//.test(seller.photo)) {
                const img = document.createElement('img');
                img.alt = ''; img.width = 52; img.height = 52; img.referrerPolicy = 'no-referrer';
                img.addEventListener('error', () => img.remove(), {once: true}); img.src = seller.photo; avatar.append(img);
            }
            const text = document.createElement('div');
            const name = document.createElement('strong'); name.textContent = seller.name;
            const state = document.createElement('span'); state.className = 'corporate-status';
            const url = seller.available === true && safeContact(seller.url);
            state.textContent = url ? 'Disponible para conversar' : 'No disponible por el momento';
            item.classList.toggle('is-available', Boolean(url)); text.append(name, state);
            item.append(avatar, text);
            if (url) {
                const link = document.createElement('a'); link.dataset.contactId = String(seller.id); link.href = url; link.target = '_blank'; link.rel = 'noopener noreferrer';
                link.textContent = 'Conversar ↗'; link.setAttribute('aria-label', `Conversar por WhatsApp con ${seller.name}`);
                link.addEventListener('click', () => {
                    if (typeof seller.ticket !== 'string' || clicked.has(seller.id)) return;
                    clicked.add(seller.id);
                    // Navigation remains a normal link; metrics can fail without blocking it.
                    fetch(panel.dataset.clickUrl, {method: 'POST', credentials: 'same-origin', keepalive: true,
                        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
                        body: JSON.stringify({ticket: seller.ticket})}).catch(() => {}).finally(() => {
                        if (panel.open) load(true);
                    });
                });
                item.append(link);
            }
            return item;
        });
        list.replaceChildren(...nodes);
    }
    async function load(refresh = false) {
        controller?.abort(); controller = new AbortController();
        const abort = controller, current = ++generation;
        const timeout = setTimeout(() => abort.abort(), 7000);
        const focusedId = document.activeElement?.dataset.contactId;
        status.textContent = 'Consultando disponibilidad…'; retry.hidden = true;
        if (!refresh) list.replaceChildren();
        try {
            const response = await fetch(panel.dataset.listUrl, {headers: {'Accept': 'application/json'}, credentials: 'same-origin', cache: 'no-store', signal: abort.signal});
            if (!response.ok) throw new Error();
            const data = await response.json();
            if (current !== generation) return;
            if (!Array.isArray(data.sellers) || data.sellers.length > 80 || !data.sellers.every(s => typeof s.name === 'string' && Number.isInteger(s.id))) throw new Error();
            render(data.sellers);
            status.textContent = data.sellers.length ? 'Disponibilidad actual de nuestro equipo.' : 'No hay asesores disponibles por el momento.';
            retry.hidden = data.sellers.length > 0;
            if (refresh && focusedId && panel.open) {
                const replacement = [...list.querySelectorAll('a')].find(link => link.dataset.contactId === focusedId);
                (replacement ?? panel.querySelector('[data-contacts-close]')).focus({preventScroll: true});
            }
        } catch {
            if (current === generation) { list.replaceChildren(); status.textContent = 'No pudimos consultar el directorio. Puedes volver a intentar o visitar Atención comercial.'; retry.hidden = false; }
        } finally { clearTimeout(timeout); }
    }
    trigger.addEventListener('click', () => { document.dispatchEvent(new Event('contacts:opening')); panel.showModal(); load(); });
    panel.querySelector('[data-contacts-close]').addEventListener('click', () => panel.close());
    panel.addEventListener('close', () => { generation++; controller?.abort(); trigger.focus({preventScroll: true}); });
    retry.addEventListener('click', () => load());
}
