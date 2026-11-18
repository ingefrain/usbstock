/* Progressive motion: content is visible by default; no business state is owned here. */
(() => {
    const reduced = matchMedia('(prefers-reduced-motion: reduce)');
    const active = new Set();
    const seen = new WeakSet();
    const animate = (element, frames, options = {}) => {
        if (reduced.matches || !element?.animate) return;
        const animation = element.animate(frames, { duration: 460, easing: 'cubic-bezier(.22,1,.36,1)', ...options });
        active.add(animation);
        animation.finished.catch(() => {}).finally(() => active.delete(animation));
    };
    const observer = 'IntersectionObserver' in window ? new IntersectionObserver(entries => {
        let stagger = 0;
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            observer.unobserve(entry.target);
            if (seen.has(entry.target)) continue;
            seen.add(entry.target);
            if (stagger >= 8 || document.visibilityState !== 'visible') continue;
            animate(entry.target, [{ opacity: .35, transform: 'translateY(14px)' }, { opacity: 1, transform: 'translateY(0)' }], { delay: stagger++ * 35 });
        }
    }, { threshold: .08 }) : null;
    const observe = root => {
        if (reduced.matches || !observer) return;
        const nodes = root.matches?.('[data-reveal], .product-card') ? [root] : root.querySelectorAll('[data-reveal], .product-card');
        for (const element of nodes) if (!seen.has(element)) observer.observe(element);
    };
    observe(document);
    const grid = document.querySelector('[data-catalog-grid]');
    if (grid) new MutationObserver(records => records.forEach(r => r.addedNodes.forEach(n => { if (n.nodeType === 1) observe(n); }))).observe(grid, { childList: true });

    const menu = document.querySelector('.mobile-nav');
    if (menu) new MutationObserver(() => { if (!menu.hidden) animate(menu, [{ opacity: 0, transform: 'translateY(-8px)' }, { opacity: 1, transform: 'translateY(0)' }], { duration: 180 }); }).observe(menu, { attributes: true, attributeFilter: ['hidden'] });
    document.querySelectorAll('details').forEach(details => details.addEventListener('toggle', () => {
        if (details.open) for (const child of [...details.children].filter(n => n.tagName !== 'SUMMARY')) animate(child, [{ opacity: .5, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }], { duration: 200 });
    }));
    document.querySelectorAll('dialog').forEach(dialog => new MutationObserver(() => {
        if (dialog.open) animate(dialog, [{ opacity: 0, transform: 'translateY(10px) scale(.985)' }, { opacity: 1, transform: 'none' }], { duration: 200 });
    }).observe(dialog, { attributes: true, attributeFilter: ['open'] }));
    document.querySelectorAll('[data-file-dropzone]').forEach(field => field.querySelector('input')?.addEventListener('change', () => {
        const selection = field.querySelector('[data-selection]');
        if (!selection.hidden) animate(selection, [{ opacity: .3, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }], { duration: 220 });
    }));
    const reset = () => document.querySelectorAll('[data-visual-busy]').forEach(form => {
        delete form.dataset.visualBusy;
        form.querySelectorAll('[data-visual-submitter]').forEach(button => { button.removeAttribute('aria-busy'); button.classList.remove('is-submitting'); delete button.dataset.visualSubmitter; });
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        if (event.defaultPrevented || !form.matches('form') || form.method.toLowerCase() !== 'post' || form.matches('[data-status-toggle], [data-cart-action]')) return;
        if (form.dataset.visualBusy) { event.preventDefault(); return; }
        form.dataset.visualBusy = 'true';
        const button = event.submitter;
        if (button) { button.dataset.visualSubmitter = 'true'; button.setAttribute('aria-busy', 'true'); button.classList.add('is-submitting'); }
        // Preserve native form serialization, including the submitter. No disabled fields.
        setTimeout(reset, 12000);
    });
    addEventListener('pageshow', reset);
    reduced.addEventListener('change', () => { if (reduced.matches) { observer?.disconnect(); active.forEach(a => a.cancel()); } else observe(document); });
})();
