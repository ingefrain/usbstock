document.querySelectorAll('[data-quote-editor]').forEach(form => {
    const lines = form.querySelector('[data-quote-lines]');
    let next = lines.children.length;
    form.querySelector('[data-add-quote-line]')?.addEventListener('click', () => {
        if (lines.children.length >= 100) return;
        const template = document.querySelector('[data-quote-line-template]');
        lines.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(next++)));
        lines.lastElementChild.querySelector('select')?.focus();
    });
    form.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-quote-line]');
        if (button && lines.children.length > 1) button.closest('[data-quote-line]').remove();
    });
    form.querySelector('[data-quote-client]')?.addEventListener('change', event => {
        const data = event.target.selectedOptions[0].dataset.customer;
        if (!data) return;
        for (const [key, value] of Object.entries(JSON.parse(data))) {
            const input = form.querySelector(`[name="customer_${key}"]`);
            if (input) input.value = value ?? '';
        }
    });
});
