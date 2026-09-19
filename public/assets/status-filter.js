(() => {
    document.querySelectorAll('[data-status-filter-project]').forEach((projectSelect) => {
        const form = projectSelect.closest('form');
        const statusSelect = form?.querySelector('[data-status-filter-select]');
        const endpoint = projectSelect.dataset.statusOptionsUrl;
        if (!form || !statusSelect || !endpoint) {
            return;
        }

        let controller = null;

        const refreshStatuses = async () => {
            controller?.abort();
            controller = new AbortController();

            const url = new URL(endpoint, window.location.origin);
            const project = projectSelect.value;
            if (project === 'all') {
                url.searchParams.set('scope', 'all');
            } else if (/^[1-9][0-9]*$/.test(project)) {
                url.searchParams.set('project_id', project);
            }

            const wasDisabled = statusSelect.disabled;
            statusSelect.disabled = true;
            statusSelect.setAttribute('aria-busy', 'true');

            try {
                const response = await fetch(url, {
                    credentials: 'same-origin',
                    headers: {Accept: 'application/json'},
                    signal: controller.signal,
                });
                if (!response.ok) {
                    throw new Error(`Unable to load statuses: ${response.status}`);
                }

                const payload = await response.json();
                const statuses = Array.isArray(payload.statuses) ? payload.statuses : [];
                const fragment = document.createDocumentFragment();

                if (statusSelect.dataset.statusPlaceholder !== undefined) {
                    const placeholder = document.createElement('option');
                    placeholder.value = '';
                    placeholder.textContent = statusSelect.dataset.statusPlaceholder;
                    placeholder.selected = true;
                    fragment.appendChild(placeholder);
                }

                statuses.forEach((status) => {
                    if (!status || !Number.isInteger(Number(status.id)) || typeof status.name !== 'string') {
                        return;
                    }
                    const option = document.createElement('option');
                    option.value = String(status.id);
                    option.textContent = status.name;
                    fragment.appendChild(option);
                });

                statusSelect.replaceChildren(fragment);
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    console.error(error);
                }
            } finally {
                if (!controller.signal.aborted) {
                    statusSelect.disabled = wasDisabled;
                    statusSelect.removeAttribute('aria-busy');
                }
            }
        };

        projectSelect.addEventListener('change', refreshStatuses);
    });
})();
