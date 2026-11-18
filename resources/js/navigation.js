const groups = [...document.querySelectorAll('.category-group')];
const closeCategories = except => groups.forEach(group => { if (group !== except) group.open = false; });
groups.forEach(group => {
    const summary = group.querySelector('summary');
    let closing;
    const open = () => { clearTimeout(closing); closeCategories(group); group.open = true; };
    group.addEventListener('toggle', () => {
        summary.setAttribute('aria-expanded', String(group.open));
        if (group.open) closeCategories(group);
    });
    group.addEventListener('pointerenter', event => {
        if (event.pointerType === 'mouse' && matchMedia('(min-width:1100px) and (hover:hover)').matches) open();
    });
    group.addEventListener('pointerleave', event => {
        if (event.pointerType === 'mouse' && !group.contains(document.activeElement)) closing = setTimeout(() => { group.open = false; }, 180);
    });
    group.addEventListener('focusout', () => setTimeout(() => { if (!group.contains(document.activeElement)) group.open = false; }, 0));
    group.addEventListener('keydown', event => {
        if (event.key === 'Escape' && group.open) { event.stopPropagation(); group.open = false; summary.focus(); }
    });
});
document.addEventListener('click', event => { if (!event.target.closest('.category-group')) closeCategories(); });
document.addEventListener('contacts:opening', () => {
    closeCategories();
    const menu = document.querySelector('.mobile-menu');
    if (menu?.getAttribute('aria-expanded') === 'true') menu.click();
});
