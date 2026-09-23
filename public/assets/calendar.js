(() => {
    document.querySelectorAll('[data-calendar-add]').forEach((button) => {
        button.addEventListener('click', () => {
            const date = button.dataset.date;
            if (!date) {
                return;
            }
            document.dispatchEvent(new CustomEvent('tms:quick-add', {
                detail: {scheduled_at: `${date}T09:00`},
            }));
        });
    });
})();
