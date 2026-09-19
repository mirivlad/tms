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
                if (!(button instanceof HTMLButtonElement)) return;

                button.addEventListener('mousedown', (event) => event.preventDefault());
                button.addEventListener('click', () => {
                    surface.focus();
                    const command = button.dataset.command || '';
                    let value = button.dataset.value || null;

                    if (command === 'createLink') {
                        const entered = window.prompt(editor.dataset.linkPrompt || '', 'https://');
                        if (!entered) return;
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

    const initRequiredCheckboxLists = (root = document) => {
        root.querySelectorAll('[data-required-checkbox-list]').forEach((fieldset) => {
            if (!(fieldset instanceof HTMLFieldSetElement) || fieldset.dataset.validationReady === '1') return;

            const checkboxes = Array.from(fieldset.querySelectorAll('input[type="checkbox"]'));
            const first = checkboxes[0];
            if (!(first instanceof HTMLInputElement)) return;

            fieldset.dataset.validationReady = '1';
            const message = fieldset.dataset.requiredMessage || 'Select at least one option.';
            const sync = () => {
                first.setCustomValidity(checkboxes.some((item) => item.checked) ? '' : message);
            };
            checkboxes.forEach((item) => item.addEventListener('change', sync));
            const form = fieldset.closest('form');
            if (form instanceof HTMLFormElement) {
                form.addEventListener('submit', sync);
            }
            sync();
        });
    };

    initRequiredCheckboxLists();

    const taskForm = document.querySelector('[data-task-form]');
    if (!(taskForm instanceof HTMLFormElement)) return;

    const projectSelect = taskForm.querySelector('[data-project-select]');
    const statusSelect = taskForm.querySelector('[data-status-select]');
    const fieldsContainer = taskForm.querySelector('[data-custom-fields-container]');
    const statusUrl = taskForm.dataset.statusOptionsUrl || '';
    const fieldsUrl = taskForm.dataset.customFieldsUrl || '';
    const taskId = taskForm.dataset.taskId || '';

    if (!(projectSelect instanceof HTMLSelectElement)
        || !(statusSelect instanceof HTMLSelectElement)
        || !(fieldsContainer instanceof HTMLElement)
        || !statusUrl
        || !fieldsUrl) {
        return;
    }

    const syncPersonalMetadata = () => {
        const selected = projectSelect.selectedOptions[0];
        const teamOwned = selected instanceof HTMLOptionElement && selected.dataset.teamOwned === '1';
        taskForm.querySelectorAll('[data-personal-metadata]').forEach((container) => {
            if (!(container instanceof HTMLElement)) return;
            container.hidden = teamOwned;
            container.querySelectorAll('input, select, textarea').forEach((control) => {
                if (control instanceof HTMLInputElement
                    || control instanceof HTMLSelectElement
                    || control instanceof HTMLTextAreaElement) {
                    control.disabled = teamOwned;
                    if (teamOwned && control instanceof HTMLInputElement && control.type === 'text') {
                        control.value = '';
                    }
                    if (teamOwned && control instanceof HTMLSelectElement) {
                        control.value = '';
                    }
                }
            });
        });
        const help = taskForm.querySelector('[data-team-metadata-help]');
        if (help instanceof HTMLElement) help.hidden = !teamOwned;
    };

    syncPersonalMetadata();

    let requestSerial = 0;
    projectSelect.addEventListener('change', async () => {
        syncPersonalMetadata();
        const serial = ++requestSerial;
        const statusEndpoint = new URL(statusUrl, window.location.origin);
        const fieldsEndpoint = new URL(fieldsUrl, window.location.origin);
        if (projectSelect.value) {
            statusEndpoint.searchParams.set('project_id', projectSelect.value);
            fieldsEndpoint.searchParams.set('project_id', projectSelect.value);
        }
        if (taskId) {
            fieldsEndpoint.searchParams.set('task_id', taskId);
        }

        projectSelect.disabled = true;
        statusSelect.disabled = true;
        fieldsContainer.setAttribute('aria-busy', 'true');

        try {
            const [statusResponse, fieldsResponse] = await Promise.all([
                fetch(statusEndpoint.toString(), {
                    headers: {'Accept': 'application/json'},
                    credentials: 'same-origin',
                }),
                fetch(fieldsEndpoint.toString(), {
                    headers: {'Accept': 'text/html'},
                    credentials: 'same-origin',
                }),
            ]);
            if (!statusResponse.ok || !fieldsResponse.ok) {
                throw new Error('Unable to load project task settings.');
            }

            const [payload, fieldsHtml] = await Promise.all([
                statusResponse.json(),
                fieldsResponse.text(),
            ]);
            if (serial !== requestSerial || !Array.isArray(payload.statuses)) return;

            statusSelect.replaceChildren();
            payload.statuses.forEach((status) => {
                const option = document.createElement('option');
                option.value = String(status.id);
                option.textContent = String(status.name || status.id);
                if (payload.default_status_id === status.id) option.selected = true;
                statusSelect.append(option);
            });

            fieldsContainer.innerHTML = fieldsHtml;
            initRequiredCheckboxLists(fieldsContainer);
            document.dispatchEvent(new CustomEvent('tms:dynamic-fields', {detail: {root: fieldsContainer}}));
        } catch (error) {
            console.error(error);
        } finally {
            if (serial === requestSerial) {
                projectSelect.disabled = false;
                statusSelect.disabled = false;
                fieldsContainer.removeAttribute('aria-busy');
            }
        }
    });
})();
