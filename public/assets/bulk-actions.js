(() => {
    const form = document.querySelector('[data-bulk-form]');
    if (!form) return;

    const rows = [...document.querySelectorAll('[data-bulk-task]')];
    const toggleAll = document.querySelector('[data-bulk-select-all]');
    const count = form.querySelector('[data-bulk-count]');
    const submit = form.querySelector('[data-bulk-submit]');
    const actionRadios = [...form.querySelectorAll('input[name="action"]')];
    const actionFields = [...form.querySelectorAll('[data-bulk-field]')];
    const deadlineRadios = [...form.querySelectorAll('input[name="deadline_type"]')];
    const customDeadline = form.querySelector('[data-bulk-custom-deadline]');

    const selected = () => rows.filter((row) => row.checked);
    const refreshSelection = () => {
        const n = selected().length;
        if (count) count.textContent = String(n);
        if (submit) submit.disabled = n === 0;
        if (toggleAll) {
            toggleAll.checked = rows.length > 0 && n === rows.length;
            toggleAll.indeterminate = n > 0 && n < rows.length;
        }
    };

    const refreshAction = () => {
        const action = actionRadios.find((radio) => radio.checked)?.value || '';
        actionFields.forEach((field) => { field.hidden = field.dataset.bulkField !== action; });
    };

    const refreshDeadline = () => {
        if (!customDeadline) return;
        customDeadline.disabled = !deadlineRadios.some((radio) => radio.checked && radio.value === 'custom');
    };

    toggleAll?.addEventListener('change', () => {
        rows.forEach((row) => { row.checked = toggleAll.checked; });
        refreshSelection();
    });
    rows.forEach((row) => row.addEventListener('change', refreshSelection));
    actionRadios.forEach((radio) => radio.addEventListener('change', refreshAction));
    deadlineRadios.forEach((radio) => radio.addEventListener('change', refreshDeadline));

    form.addEventListener('submit', (event) => {
        if (selected().length === 0) {
            event.preventDefault();
            return;
        }
        const action = actionRadios.find((radio) => radio.checked)?.value || '';
        if (action === 'delete' && !window.confirm(form.dataset.deleteConfirm || 'Delete selected tasks?')) {
            event.preventDefault();
        }
    });

    refreshSelection();
    refreshAction();
    refreshDeadline();
})();
