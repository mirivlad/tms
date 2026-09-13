(() => {
    'use strict';
    const optionTypes = new Set(['select', 'checkbox_list']);

    document.querySelectorAll('[data-custom-field-form]').forEach((form) => {
        const type = form.querySelector('select[name="field_type"]');
        const options = form.querySelector('[data-custom-field-options]');
        const textarea = options?.querySelector('textarea[name="options"]');
        if (!(type instanceof HTMLSelectElement) || !(options instanceof HTMLElement)) return;

        const sync = () => {
            const visible = optionTypes.has(type.value);
            options.hidden = !visible;
            options.setAttribute('aria-hidden', visible ? 'false' : 'true');
            if (textarea instanceof HTMLTextAreaElement) {
                textarea.disabled = !visible;
                textarea.required = visible;
            }
        };
        type.addEventListener('change', sync);
        sync();
    });
})();
