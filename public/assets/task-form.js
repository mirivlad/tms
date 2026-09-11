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

    const customerInput = document.querySelector('[data-customer-autocomplete]');
    const results = document.getElementById('customer-suggestions');
    if (!(customerInput instanceof HTMLInputElement) || !(results instanceof HTMLElement)) {
        return;
    }

    const searchUrl = customerInput.dataset.searchUrl || '';
    let timer = 0;
    let request = null;
    let activeIndex = -1;

    const hideResults = () => {
        results.hidden = true;
        results.replaceChildren();
        customerInput.setAttribute('aria-expanded', 'false');
        activeIndex = -1;
    };

    const options = () => Array.from(results.querySelectorAll('.autocomplete-option'));

    const activate = (index) => {
        const items = options();
        items.forEach((item) => item.classList.remove('active'));
        if (items.length === 0) {
            activeIndex = -1;
            return;
        }

        activeIndex = Math.max(0, Math.min(index, items.length - 1));
        const item = items[activeIndex];
        item.classList.add('active');
        item.scrollIntoView({ block: 'nearest' });
    };

    const choose = (name) => {
        customerInput.value = name;
        hideResults();
        customerInput.focus();
    };

    const render = (items) => {
        results.replaceChildren();
        activeIndex = -1;

        for (const item of items) {
            if (!item || typeof item.name !== 'string') {
                continue;
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'autocomplete-option';
            button.setAttribute('role', 'option');
            button.textContent = item.name;
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => choose(item.name));
            results.append(button);
        }

        const hasResults = results.childElementCount > 0;
        results.hidden = !hasResults;
        customerInput.setAttribute('aria-expanded', hasResults ? 'true' : 'false');
    };

    customerInput.addEventListener('input', () => {
        window.clearTimeout(timer);
        if (request instanceof AbortController) {
            request.abort();
        }

        const query = customerInput.value.trim();
        if (query.length < 2 || searchUrl === '') {
            hideResults();
            return;
        }

        timer = window.setTimeout(async () => {
            request = new AbortController();
            try {
                const response = await fetch(`${searchUrl}?q=${encodeURIComponent(query)}`, {
                    headers: { Accept: 'application/json' },
                    signal: request.signal,
                    credentials: 'same-origin',
                });
                if (!response.ok) {
                    hideResults();
                    return;
                }

                const payload = await response.json();
                if (customerInput.value.trim() !== query || !Array.isArray(payload)) {
                    return;
                }
                render(payload);
            } catch (error) {
                if (!(error instanceof DOMException && error.name === 'AbortError')) {
                    hideResults();
                }
            }
        }, 180);
    });

    customerInput.addEventListener('keydown', (event) => {
        const items = options();
        if (items.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            activate(activeIndex + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            activate(activeIndex <= 0 ? items.length - 1 : activeIndex - 1);
        } else if (event.key === 'Enter' && activeIndex >= 0) {
            event.preventDefault();
            const item = items[activeIndex];
            choose(item.textContent || '');
        } else if (event.key === 'Escape') {
            hideResults();
        }
    });

    customerInput.addEventListener('blur', () => {
        window.setTimeout(hideResults, 120);
    });
})();
