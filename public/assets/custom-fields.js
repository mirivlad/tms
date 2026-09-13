(() => {
    const optionTypes = new Set(['select', 'checkbox_list']);

    document.querySelectorAll('[data-custom-field-form]').forEach((form) => {
        const type = form.querySelector('select[name="field_type"]');
        const options = form.querySelector('[data-custom-field-options]');
        if (!(type instanceof HTMLSelectElement) || !(options instanceof HTMLElement)) {
            return;
        }

        const sync = () => {
            options.hidden = !optionTypes.has(type.value);
        };

        type.addEventListener('change', sync);
        sync();
    });
})();
