(() => {
    const timezoneSelect = document.querySelector('[data-timezone-select]');
    const timezonePreview = document.querySelector('[data-timezone-preview]');
    if (timezoneSelect instanceof HTMLSelectElement && timezonePreview instanceof HTMLElement) {
        const updateTime = () => {
            try {
                timezonePreview.textContent = new Intl.DateTimeFormat(undefined, {
                    timeZone: timezoneSelect.value,
                    dateStyle: 'medium',
                    timeStyle: 'medium',
                }).format(new Date());
            } catch (_) {
                timezonePreview.textContent = '—';
            }
        };
        timezoneSelect.addEventListener('change', updateTime);
        updateTime();
        window.setInterval(updateTime, 60000);
    }

    const picker = document.querySelector('[data-theme-picker]');
    if (!(picker instanceof HTMLElement)) return;
    const body = document.body;
    const initialTheme = body.dataset.theme || 'graphite';
    picker.addEventListener('change', (event) => {
        const input = event.target;
        if (input instanceof HTMLInputElement && input.name === 'theme' && input.checked) {
            body.dataset.theme = input.value;
        }
    });
    const form = picker.closest('form');
    form?.addEventListener('reset', () => { body.dataset.theme = initialTheme; });
})();
