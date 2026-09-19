(() => {
    'use strict';

    document.querySelectorAll('[data-discussion-editor]').forEach((editor) => {
        if (!(editor instanceof HTMLElement)) return;
        const source = editor.querySelector('.discussion-source');
        const surface = editor.querySelector('.discussion-surface');
        const form = editor.closest('form');
        if (!(source instanceof HTMLTextAreaElement)
            || !(surface instanceof HTMLElement)
            || !(form instanceof HTMLFormElement)) {
            return;
        }

        surface.innerHTML = source.value;
        surface.hidden = false;
        source.hidden = true;

        editor.querySelectorAll('[data-discussion-command]').forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) return;
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => {
                surface.focus();
                const command = button.dataset.discussionCommand || '';
                let value = button.dataset.discussionValue || null;
                if (command === 'createLink') {
                    const entered = window.prompt(editor.dataset.linkPrompt || '', 'https://');
                    if (!entered) return;
                    value = entered.trim();
                }
                document.execCommand(command, false, value);
            });
        });

        form.addEventListener('submit', (event) => {
            source.value = surface.innerHTML.trim();
            const plain = (surface.textContent || '').replace(/\u00a0/g, ' ').trim();
            if (!plain) {
                event.preventDefault();
                surface.focus();
            }
        });
    });
})();
