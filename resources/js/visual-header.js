const mega = document.querySelector('[data-public-categories]');
const menu = document.querySelector('.mobile-menu');
const mobile = document.querySelector('.mobile-nav');
if (mega) {
    const summary = mega.querySelector('summary');
    const desktop = matchMedia('(min-width:1100px)');
    let closing;
    const close = () => { clearTimeout(closing); mega.open = false; };
    mega.addEventListener('toggle', () => {
        summary.setAttribute('aria-expanded', String(mega.open));
        if (mega.open && !mega.querySelector('.category-group[open]')) {
            const first = mega.querySelector('.category-group');
            if (first) first.open = true;
        }
    });
    mega.addEventListener('pointerenter', event => {
        clearTimeout(closing);
        if (event.pointerType === 'mouse' && desktop.matches) mega.open = true;
    });
    mega.addEventListener('pointerleave', event => {
        if (event.pointerType === 'mouse' && !mega.contains(document.activeElement)) closing = setTimeout(close, 180);
    });
    mega.addEventListener('focusout', () => setTimeout(() => { if (!mega.contains(document.activeElement)) close(); }, 0));
    mega.addEventListener('keydown', event => {
        if (event.key === 'Escape' && mega.open) { close(); summary.focus(); }
    });
    document.addEventListener('click', event => { if (!mega.contains(event.target)) close(); });
    document.addEventListener('contacts:opening', close);
    desktop.addEventListener('change', () => {
        const focusedDesktop = mega.contains(document.activeElement);
        const focusedMobile = mobile?.contains(document.activeElement) || document.activeElement === menu;
        close();
        if (desktop.matches && mobile && menu) {
            mobile.hidden = true;
            menu.setAttribute('aria-expanded', 'false');
            menu.setAttribute('aria-label', 'Abrir navegación');
        }
        if (desktop.matches && focusedMobile) summary.focus({ preventScroll: true });
        if (!desktop.matches && focusedDesktop) menu?.focus({ preventScroll: true });
    });
}
