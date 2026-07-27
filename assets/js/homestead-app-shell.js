(() => {
    const body = document.body;
    const toggles = document.querySelectorAll('[data-shell-menu-toggle]');
    const closers = document.querySelectorAll('[data-shell-menu-close]');

    const setOpen = (open) => {
        body.classList.toggle('shell-menu-open', open);
        toggles.forEach((toggle) => toggle.setAttribute('aria-expanded', open ? 'true' : 'false'));
    };

    toggles.forEach((toggle) => {
        toggle.addEventListener('click', () => setOpen(!body.classList.contains('shell-menu-open')));
    });
    closers.forEach((closer) => closer.addEventListener('click', () => setOpen(false)));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setOpen(false);
            document.querySelectorAll('.homestead-mobile-nav[open]').forEach((menu) => menu.removeAttribute('open'));
        }
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 960) {
            setOpen(false);
        }
    });
})();
