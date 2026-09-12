(() => {
    const select = document.querySelector('[data-timezone-select]');
    const preview = document.querySelector('[data-timezone-preview]');
    if (!(select instanceof HTMLSelectElement) || !(preview instanceof HTMLElement)) return;

    const update = () => {
        try {
            preview.textContent = new Intl.DateTimeFormat(undefined, {
                timeZone: select.value,
                dateStyle: 'medium',
                timeStyle: 'medium',
            }).format(new Date());
        } catch (_) {
            preview.textContent = '—';
        }
    };
    select.addEventListener('change', update);
    update();
    window.setInterval(update, 60000);
})();
