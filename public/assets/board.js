(() => {
    const board = document.querySelector('[data-board]');
    if (!board) {
        return;
    }

    let dragged = null;

    const desktopBoard = window.matchMedia('(min-width: 721px)');
    let resizeFrame = 0;

    const syncBoardViewportHeight = () => {
        if (!desktopBoard.matches) {
            board.style.removeProperty('--board-available-height');
            return;
        }

        const page = board.closest('.page');
        const footer = document.querySelector('.page-footer');
        const pageStyle = page instanceof HTMLElement ? window.getComputedStyle(page) : null;
        const pageBottomPadding = pageStyle ? Number.parseFloat(pageStyle.paddingBottom) || 0 : 0;
        const footerHeight = footer instanceof HTMLElement ? footer.getBoundingClientRect().height : 0;
        const boardTop = board.getBoundingClientRect().top;
        const available = Math.floor(window.innerHeight - boardTop - pageBottomPadding - footerHeight);

        board.style.setProperty('--board-available-height', `${Math.max(320, available)}px`);
    };

    const scheduleBoardViewportSync = () => {
        window.cancelAnimationFrame(resizeFrame);
        resizeFrame = window.requestAnimationFrame(syncBoardViewportHeight);
    };

    scheduleBoardViewportSync();
    window.addEventListener('resize', scheduleBoardViewportSync, {passive: true});
    desktopBoard.addEventListener?.('change', scheduleBoardViewportSync);
    document.fonts?.ready.then(scheduleBoardViewportSync).catch(() => {});

    const canScrollVertically = (container, delta) => {
        if (!(container instanceof HTMLElement) || container.scrollHeight <= container.clientHeight + 1) {
            return false;
        }
        if (delta > 0) {
            return container.scrollTop + container.clientHeight < container.scrollHeight - 1;
        }
        return delta < 0 && container.scrollTop > 1;
    };

    const canScrollHorizontally = (delta) => {
        if (board.scrollWidth <= board.clientWidth + 1) {
            return false;
        }
        if (delta > 0) {
            return board.scrollLeft + board.clientWidth < board.scrollWidth - 1;
        }
        return delta < 0 && board.scrollLeft > 1;
    };

    board.addEventListener('wheel', (event) => {
        if (event.ctrlKey || event.metaKey) {
            return;
        }
        const target = event.target;
        if (target instanceof Element && target.closest('select, input, textarea')) {
            return;
        }

        const cards = target instanceof Element ? target.closest('.board-cards') : null;
        const verticalDelta = event.deltaY;
        const horizontalDelta = Math.abs(event.deltaX) > Math.abs(verticalDelta) ? event.deltaX : verticalDelta;

        if (!event.shiftKey && Math.abs(event.deltaX) <= Math.abs(verticalDelta) && canScrollVertically(cards, verticalDelta)) {
            return;
        }

        if (canScrollHorizontally(horizontalDelta)) {
            event.preventDefault();
            board.scrollLeft += horizontalDelta;
        }
    }, {passive: false});


    const updateColumn = (container) => {
        const column = container.closest('.board-column');
        if (!column) {
            return;
        }

        const cards = container.querySelectorAll('.task-card').length;
        const badge = column.querySelector('[data-count]');
        const empty = container.querySelector('.board-empty');
        if (badge) {
            badge.textContent = String(cards);
        }
        if (empty) {
            empty.hidden = cards !== 0;
        }
    };

    const restore = (card, source) => {
        if (source && card.parentElement !== source) {
            source.appendChild(card);
        }
        if (source) {
            updateColumn(source);
        }
        card.classList.remove('dragging');
    };

    board.querySelectorAll('.task-card[draggable="true"]').forEach((card) => {
        card.addEventListener('dragstart', (event) => {
            const taskId = card.dataset.taskId;
            if (!taskId || !event.dataTransfer) {
                event.preventDefault();
                return;
            }

            dragged = {card, source: card.parentElement, taskId};
            card.classList.add('dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', taskId);
        });

        card.addEventListener('dragend', () => {
            card.classList.remove('dragging');
            board.querySelectorAll('.board-cards').forEach((container) => container.classList.remove('drop-target'));
            dragged = null;
        });
    });

    board.querySelectorAll('.board-cards[data-status-id]').forEach((container) => {
        container.addEventListener('dragover', (event) => {
            if (!dragged) {
                return;
            }
            event.preventDefault();
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }
            container.classList.add('drop-target');
        });

        container.addEventListener('dragleave', (event) => {
            if (!container.contains(event.relatedTarget)) {
                container.classList.remove('drop-target');
            }
        });

        container.addEventListener('drop', async (event) => {
            event.preventDefault();
            container.classList.remove('drop-target');
            if (!dragged) {
                return;
            }

            const {card, source, taskId} = dragged;
            const statusId = container.dataset.statusId;
            const form = card.querySelector('.move-form');
            const csrf = form?.querySelector('input[name="_csrf"]')?.value;
            const select = form?.querySelector('select[name="status_id"]');

            if (!statusId || !csrf || !source) {
                restore(card, source);
                return;
            }

            if (source === container) {
                card.classList.remove('dragging');
                return;
            }

            container.appendChild(card);
            updateColumn(source);
            updateColumn(container);

            try {
                const body = new URLSearchParams({_csrf: csrf, status_id: statusId});
                const response = await fetch(`/tasks/${encodeURIComponent(taskId)}/status`, {
                    method: 'POST',
                    body,
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    },
                });

                if (!response.ok) {
                    throw new Error(`Status update failed with HTTP ${response.status}`);
                }

                const result = await response.json();
                if (result.status !== 'ok') {
                    throw new Error('Status update returned an unexpected result.');
                }

                if (select) {
                    select.value = statusId;
                }
                card.classList.remove('dragging');
            } catch (error) {
                restore(card, source);
                updateColumn(container);
                console.error(error);
            }
        });
    });
})();
