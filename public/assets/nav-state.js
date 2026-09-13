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
    }
})();
