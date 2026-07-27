(() => {
    'use strict';

    const cards = document.querySelectorAll('.garden-panel, .garden-metrics article, .zone-card');
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.08 });
        cards.forEach((card) => observer.observe(card));
    }

    document.querySelectorAll('.garden-form').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');
            if (button) {
                button.setAttribute('aria-busy', 'true');
            }
        });
    });
})();
