(() => {
    const path = window.location.pathname.replace(/\/+$/, '') || '/';
    let best = null;
    for (const link of document.querySelectorAll('[data-nav-path]')) {
        const target = link.getAttribute('data-nav-path') || '';
        const matches = path === target || (target !== '/' && path.startsWith(`${target}/`));
        if (matches && (!best || target.length > best.target.length)) best = {link, target};
    }

    if (best) {
        best.link.classList.add('active');
        best.link.setAttribute('aria-current', 'page');
        best.link.closest('[data-nav-menu]')?.classList.add('active');
    }

    const menus = [...document.querySelectorAll('[data-nav-menu]')];
    for (const menu of menus) {
        menu.addEventListener('toggle', () => {
            if (!menu.open) return;
            for (const other of menus) {
                if (other !== menu) other.open = false;
            }
        });
    }

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Node)) return;
        for (const menu of menus) {
            if (menu.open && !menu.contains(target)) menu.open = false;
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        for (const menu of menus) menu.open = false;
    });
})();
