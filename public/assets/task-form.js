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

    const taskForm = document.querySelector('[data-task-form]');
    if (taskForm instanceof HTMLFormElement) {
        const projectSelect = taskForm.querySelector('[data-project-select]');
        const statusSelect = taskForm.querySelector('[data-status-select]');
        const statusUrl = taskForm.dataset.statusOptionsUrl || '';

        if (projectSelect instanceof HTMLSelectElement && statusSelect instanceof HTMLSelectElement && statusUrl) {
            let requestSerial = 0;
            projectSelect.addEventListener('change', async () => {
                const serial = ++requestSerial;
                const url = new URL(statusUrl, window.location.origin);
                if (projectSelect.value) {
                    url.searchParams.set('project_id', projectSelect.value);
                }

                projectSelect.disabled = true;
                statusSelect.disabled = true;
                try {
                    const response = await fetch(url.toString(), {
                        headers: {'Accept': 'application/json'},
                        credentials: 'same-origin',
                    });
                    if (!response.ok) {
                        throw new Error('Unable to load task statuses.');
                    }
                    const payload = await response.json();
                    if (serial !== requestSerial || !Array.isArray(payload.statuses)) {
                        return;
                    }

                    statusSelect.replaceChildren();
                    payload.statuses.forEach((status) => {
                        const option = document.createElement('option');
                        option.value = String(status.id);
                        option.textContent = String(status.name || status.id);
                        if (payload.default_status_id === status.id) {
                            option.selected = true;
                        }
                        statusSelect.append(option);
                    });
                } catch (error) {
                    console.error(error);
                } finally {
                    if (serial === requestSerial) {
                        projectSelect.disabled = false;
                        statusSelect.disabled = false;
                    }
                }
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
