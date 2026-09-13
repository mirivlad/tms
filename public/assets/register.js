(() => {
    const image = document.querySelector('[data-registration-captcha]');
    const refresh = document.querySelector('[data-registration-captcha-refresh]');
    if (!(image instanceof HTMLImageElement)) return;
    const reload = (event) => {
        event?.preventDefault();
        image.src = `/captcha?nonce=${Date.now()}-${Math.random()}`;
    };
    image.addEventListener('click', reload);
    refresh?.addEventListener('click', reload);
})();
