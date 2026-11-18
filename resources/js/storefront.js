// Progressive enhancement only. Cart and requests remain server-owned.
const menu = document.querySelector('.shop .mobile-menu');
const mobileNav = document.querySelector('.shop .mobile-nav');
if (menu && mobileNav) {
    document.body.classList.add('navigation-ready');
    menu.hidden = false;
    mobileNav.hidden = true;
    menu.addEventListener('click', () => {
        mobileNav.hidden = !mobileNav.hidden;
        menu.setAttribute('aria-expanded', String(!mobileNav.hidden));
        menu.setAttribute('aria-label', mobileNav.hidden ? 'Abrir navegación' : 'Cerrar navegación');
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape' || mobileNav.hidden) return;
        mobileNav.hidden = true;
        menu.setAttribute('aria-expanded', 'false');
        menu.setAttribute('aria-label', 'Abrir navegación');
        menu.focus();
    });
}
const sellerField = document.querySelector('[data-seller-field]');
if (sellerField) {
    const form = sellerField.closest('form');
    const input = sellerField.querySelector('input');
    const update = () => {
        const choice = form.querySelector('[name="has_seller"]:checked')?.value;
        sellerField.hidden = choice === '0';
        input.required = choice === '1';
        input.disabled = choice === '0';
    };
    form.querySelectorAll('[name="has_seller"]').forEach(radio => radio.addEventListener('change', update));
    update();
}
document.querySelectorAll('[data-product-selection]').forEach(form => {
    const initial = form.querySelector('[name="variant"]:checked');
    if (initial) form.elements.quantity.max = initial.dataset.stock;
    form.addEventListener('change', event => {
        if (event.target.name === 'variant') form.elements.quantity.max = event.target.dataset.stock;
    });
});
