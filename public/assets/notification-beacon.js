(() => {
    const root = document.querySelector('[data-notification-beacon]');
    if (!(root instanceof HTMLElement)) {
        return;
    }

    const endpoint = '/api/navigation-alerts';
    const pollDelayMs = 20000;
    let timer = null;
    let inFlight = false;
    let stopped = false;

    const countValue = (value) => Number.isInteger(value) && value > 0 ? value : 0;

    const badge = (kind, count, label = '') => {
        const element = document.createElement('span');
        element.className = `nav-badge nav-badge-${kind}`;
        element.dataset.alertCount = kind;
        element.textContent = String(count);
        if (label !== '') {
            element.setAttribute('aria-label', `${label}: ${count}`);
        }
        return element;
    };

    const updateLinkBadge = (kind, count) => {
        const link = root.querySelector(`[data-alert-kind="${kind}"]`);
        if (!(link instanceof HTMLElement)) {
            return;
        }

        let current = link.querySelector(`[data-alert-count="${kind}"]`);
        if (count <= 0) {
            current?.remove();
            return;
        }

        const label = link.dataset.alertLabel || '';
        if (!(current instanceof HTMLElement)) {
            current = badge(kind, count, label);
            link.append(current);
            return;
        }

        current.textContent = String(count);
        if (label !== '') {
            current.setAttribute('aria-label', `${label}: ${count}`);
        }
    };

    const updateSummaryBadges = (notificationCount, invitationCount) => {
        const summary = root.querySelector('[data-alert-summary]');
        if (!(summary instanceof HTMLElement)) {
            return;
        }

        let wrapper = summary.querySelector('[data-alert-summary-badges]');
        if (notificationCount <= 0 && invitationCount <= 0) {
            wrapper?.remove();
            return;
        }

        if (!(wrapper instanceof HTMLElement)) {
            wrapper = document.createElement('span');
            wrapper.className = 'user-menu-badges';
            wrapper.dataset.alertSummaryBadges = '';
            wrapper.setAttribute('aria-hidden', 'true');
            const chevron = summary.querySelector('.nav-dropdown-chevron');
            summary.insertBefore(wrapper, chevron);
        }

        const counts = {
            notifications: notificationCount,
            invitations: invitationCount,
        };
        Object.entries(counts).forEach(([kind, count]) => {
            let current = wrapper.querySelector(`[data-alert-count="${kind}"]`);
            if (count <= 0) {
                current?.remove();
                return;
            }
            if (!(current instanceof HTMLElement)) {
                current = badge(kind, count);
                wrapper.append(current);
                return;
            }
            current.textContent = String(count);
        });
    };

    const applyCounts = (payload) => {
        const notificationCount = countValue(payload?.notification_count);
        const invitationCount = countValue(payload?.team_invitation_count);
        updateLinkBadge('notifications', notificationCount);
        updateLinkBadge('invitations', invitationCount);
        updateSummaryBadges(notificationCount, invitationCount);
    };

    const schedule = () => {
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
        if (!stopped && !document.hidden) {
            timer = window.setTimeout(refresh, pollDelayMs);
        }
    };

    const refresh = async () => {
        if (stopped || document.hidden || inFlight) {
            return;
        }

        inFlight = true;
        try {
            const response = await fetch(endpoint, {
                headers: {'Accept': 'application/json'},
                cache: 'no-store',
                credentials: 'same-origin',
            });
            if (response.status === 401 || response.status === 403) {
                stopped = true;
                return;
            }
            if (!response.ok) {
                return;
            }
            applyCounts(await response.json());
        } catch (_error) {
            // A transient polling failure must not disturb the current page state.
        } finally {
            inFlight = false;
            schedule();
        }
    };

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            if (timer !== null) {
                window.clearTimeout(timer);
                timer = null;
            }
            return;
        }
        void refresh();
    });

    schedule();
})();
