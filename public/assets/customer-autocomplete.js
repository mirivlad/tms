(() => {
    'use strict';

    document.querySelectorAll('[data-customer-autocomplete]').forEach((input) => {
        if (!(input instanceof HTMLInputElement)) return;

        const resultsId = input.getAttribute('aria-controls') || '';
        const results = resultsId ? document.getElementById(resultsId) : null;
        if (!(results instanceof HTMLElement)) return;

        const searchUrl = input.dataset.searchUrl || '';
        let timer = 0;
        let request = null;
        let activeIndex = -1;

        const options = () => Array.from(results.querySelectorAll('.autocomplete-option'));
        const hide = () => {
            results.hidden = true;
            results.replaceChildren();
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
        };
        const activate = (index) => {
            const items = options();
            items.forEach((item) => item.classList.remove('active'));
            if (items.length === 0) { activeIndex = -1; return; }
            activeIndex = Math.max(0, Math.min(index, items.length - 1));
            items[activeIndex].classList.add('active');
            items[activeIndex].scrollIntoView({ block: 'nearest' });
        };
        const choose = (name) => {
            input.value = name;
            hide();
            input.focus();
        };
        const render = (items) => {
            results.replaceChildren();
            activeIndex = -1;
            for (const item of items) {
                if (!item || typeof item.name !== 'string') continue;
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
            input.setAttribute('aria-expanded', hasResults ? 'true' : 'false');
        };

        input.addEventListener('input', () => {
            window.clearTimeout(timer);
            if (request instanceof AbortController) request.abort();
            const query = input.value.trim();
            if (query.length < 2 || searchUrl === '') { hide(); return; }
            timer = window.setTimeout(async () => {
                request = new AbortController();
                try {
                    const response = await fetch(`${searchUrl}?q=${encodeURIComponent(query)}`, {
                        headers: { Accept: 'application/json' },
                        signal: request.signal,
                        credentials: 'same-origin',
                    });
                    if (!response.ok) { hide(); return; }
                    const payload = await response.json();
                    if (input.value.trim() === query && Array.isArray(payload)) render(payload);
                } catch (error) {
                    if (!(error instanceof DOMException && error.name === 'AbortError')) hide();
                }
            }, 180);
        });

        input.addEventListener('keydown', (event) => {
            const items = options();
            if (items.length === 0) return;
            if (event.key === 'ArrowDown') { event.preventDefault(); activate(activeIndex + 1); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); activate(activeIndex <= 0 ? items.length - 1 : activeIndex - 1); }
            else if (event.key === 'Enter' && activeIndex >= 0) { event.preventDefault(); choose(items[activeIndex].textContent || ''); }
            else if (event.key === 'Escape') hide();
        });
        input.addEventListener('blur', () => window.setTimeout(hide, 120));
    });
})();
