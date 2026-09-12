(() => {
    const dialog = document.getElementById('quick-add-dialog');
    if (!(dialog instanceof HTMLDialogElement)) {
        return;
    }

    const form = dialog.querySelector('[data-quick-add-form]');
    const titleInput = dialog.querySelector('[data-quick-add-title-input]');
    const result = dialog.querySelector('[data-quick-add-result]');
    const closeButton = dialog.querySelector('[data-quick-add-close]');

    if (!(form instanceof HTMLFormElement) || !(titleInput instanceof HTMLInputElement)) {
        return;
    }

    const clearResult = () => {
        if (result) {
            result.hidden = true;
            result.textContent = '';
            result.classList.remove('error');
        }
    };

    const open = () => {
        form.reset();
        clearResult();
        dialog.showModal();
        requestAnimationFrame(() => titleInput.focus());
    };

    document.querySelectorAll('[data-quick-add-trigger]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            open();
        });
    });

    closeButton?.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    document.addEventListener('keydown', (event) => {
        const target = event.target;
        const editing = target instanceof HTMLElement && (
            target.matches('input, textarea, select') || target.isContentEditable
        );
        if (editing) {
            return;
        }

        const quickKey = ['n', 'N', 'т', 'Т'].includes(event.key);
        if (quickKey && (event.altKey || event.metaKey)) {
            event.preventDefault();
            open();
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearResult();
        const submit = form.querySelector('button[type="submit"]');
        if (submit instanceof HTMLButtonElement) {
            submit.disabled = true;
        }

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            const payload = await response.json();
            if (!response.ok || payload.success !== true) {
                throw new Error(payload.message || 'Unable to create task.');
            }

            dialog.close();
            window.location.reload();
        } catch (error) {
            if (result) {
                result.hidden = false;
                result.classList.add('error');
                result.textContent = error instanceof Error ? error.message : 'Unable to create task.';
            }
        } finally {
            if (submit instanceof HTMLButtonElement) {
                submit.disabled = false;
            }
        }
    });
})();
