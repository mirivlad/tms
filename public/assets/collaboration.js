(() => {
    const openDialog = (dialog) => {
        if (dialog instanceof HTMLDialogElement && !dialog.open) {
            dialog.showModal();
            const autofocus = dialog.querySelector('[autofocus], input:not([type="hidden"]), textarea, select');
            if (autofocus instanceof HTMLElement) {
                requestAnimationFrame(() => autofocus.focus());
            }
        }
    };

    document.querySelectorAll('[data-dialog-trigger]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            const selector = trigger.getAttribute('data-dialog-trigger');
            if (!selector) return;
            const dialog = document.querySelector(selector);
            if (!(dialog instanceof HTMLDialogElement)) return;
            event.preventDefault();
            openDialog(dialog);
        });
    });

    document.querySelectorAll('dialog[data-collaboration-dialog]').forEach((dialog) => {
        dialog.querySelectorAll('[data-dialog-close]').forEach((button) => {
            button.addEventListener('click', () => dialog.close());
        });
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
        if (dialog.hasAttribute('data-auto-open')) openDialog(dialog);
    });
})();
