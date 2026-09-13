(() => {
    'use strict';

    const editor = document.querySelector('[data-rich-editor]');
    if (editor) {
        const source = editor.querySelector('.rich-source');
        const surface = editor.querySelector('.rich-surface');
        const form = editor.closest('form');

        if (source instanceof HTMLTextAreaElement && surface instanceof HTMLElement && form instanceof HTMLFormElement) {
            surface.innerHTML = source.value;
            surface.hidden = false;
            source.hidden = true;

            editor.querySelectorAll('[data-command]').forEach((button) => {
                if (!(button instanceof HTMLButtonElement)) {
                    return;
                }

                button.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                });

                button.addEventListener('click', () => {
                    surface.focus();
                    const command = button.dataset.command || '';
                    let value = button.dataset.value || null;

                    if (command === 'createLink') {
                        const promptText = editor.dataset.linkPrompt || '';
                        const entered = window.prompt(promptText, 'https://');
                        if (!entered) {
                            return;
                        }
                        value = entered.trim();
                    }

                    document.execCommand(command, false, value);
                });
            });

            form.addEventListener('submit', () => {
                source.value = surface.innerHTML.trim();
            });
        }
    }

    document.querySelectorAll('[data-required-checkbox-list]').forEach((fieldset) => {
        if (!(fieldset instanceof HTMLFieldSetElement)) return;
        const checkboxes = Array.from(fieldset.querySelectorAll('input[type="checkbox"]'));
        const first = checkboxes[0];
        if (!(first instanceof HTMLInputElement)) return;
        const message = fieldset.dataset.requiredMessage || 'Select at least one option.';
        const sync = () => {
            first.setCustomValidity(checkboxes.some((item) => item.checked) ? '' : message);
        };
        checkboxes.forEach((item) => item.addEventListener('change', sync));
        const form = fieldset.closest('form');
        if (form instanceof HTMLFormElement) {
            form.addEventListener('submit', () => sync());
        }
        sync();
    });

})();
