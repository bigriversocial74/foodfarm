(() => {
    const search = document.querySelector('[data-lifecycle-search]');
    const cards = [...document.querySelectorAll('[data-lifecycle-card]')];
    const filters = [...document.querySelectorAll('[data-lifecycle-filter]')];
    let activeFilter = 'all';

    const apply = () => {
        const query = (search?.value || '').trim().toLowerCase();
        cards.forEach((card) => {
            const statusMatches = activeFilter === 'all' || card.dataset.status === activeFilter;
            const searchMatches = !query || (card.dataset.search || '').includes(query);
            card.hidden = !(statusMatches && searchMatches);
        });
    };

    filters.forEach((button) => button.addEventListener('click', () => {
        activeFilter = button.dataset.lifecycleFilter || 'all';
        filters.forEach((item) => item.classList.toggle('active', item === button));
        apply();
    }));
    search?.addEventListener('input', apply);

    document.addEventListener('click', (event) => {
        document.querySelectorAll('.lifecycle-version__action details[open]').forEach((details) => {
            if (!details.contains(event.target)) details.removeAttribute('open');
        });
    });
})();
