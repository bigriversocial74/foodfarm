(() => {
    'use strict';

    const toggle = document.querySelector('[data-nav-toggle]');
    const panel = document.querySelector('[data-nav-panel]');

    if (!(toggle instanceof HTMLButtonElement) || !(panel instanceof HTMLElement)) {
        return;
    }

    const closeNavigation = () => {
        toggle.setAttribute('aria-expanded', 'false');
        panel.classList.remove('is-open');
    };

    toggle.addEventListener('click', () => {
        const expanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!expanded));
        panel.classList.toggle('is-open', !expanded);
    });

    panel.addEventListener('click', (event) => {
        if (event.target instanceof HTMLAnchorElement) {
            closeNavigation();
        }
    });

    window.addEventListener('resize', () => {
        if (window.matchMedia('(min-width: 861px)').matches) {
            closeNavigation();
        }
    });
})();
