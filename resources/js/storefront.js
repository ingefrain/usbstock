// Progressive enhancement only. Cart and requests remain server-owned.
const filters = document.querySelector('.shop details.filters');
if (filters && window.matchMedia('(max-width:600px)').matches) filters.open = false;
const menu = document.querySelector('.shop .mobile-menu');
const mobileNav = document.querySelector('.shop .mobile-nav');
if (menu && mobileNav) {
    mobileNav.hidden = true;
    menu.addEventListener('click', () => {
        mobileNav.hidden = !mobileNav.hidden;
        menu.setAttribute('aria-expanded', String(!mobileNav.hidden));
        menu.setAttribute('aria-label', mobileNav.hidden ? 'Abrir navegación' : 'Cerrar navegación');
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
    form.addEventListener('change', event => {
        if (event.target.name === 'variant') form.elements.quantity.max = event.target.dataset.stock;
    });
});
