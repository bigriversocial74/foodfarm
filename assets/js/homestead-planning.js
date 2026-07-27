(() => {
    const page = document.querySelector('.planning-page');
    if (!page) return;

    const cards = [...page.querySelectorAll('[data-task-card]')];
    const search = page.querySelector('[data-planning-search]');
    const filters = [...page.querySelectorAll('[data-task-filter]')];
    const empty = page.querySelector('[data-filter-empty]');
    let activeFilter = 'all';

    const refresh = () => {
        const term = (search?.value || '').trim().toLowerCase();
        let visible = 0;
        cards.forEach((card) => {
            const filterValues = card.dataset.taskFilterValue || '';
            const searchText = card.dataset.searchText || '';
            const filterMatch = activeFilter === 'all' || filterValues.split(' ').includes(activeFilter);
            const searchMatch = term === '' || searchText.includes(term);
            const show = filterMatch && searchMatch;
            card.hidden = !show;
            if (show) visible += 1;
        });
        if (empty) empty.hidden = visible !== 0 || cards.length === 0;
    };

    filters.forEach((button) => {
        button.addEventListener('click', () => {
            activeFilter = button.dataset.taskFilter || 'all';
            filters.forEach((item) => item.classList.toggle('active', item === button));
            refresh();
        });
    });
    search?.addEventListener('input', refresh);

    document.addEventListener('click', (event) => {
        page.querySelectorAll('.planning-action-menu[open]').forEach((menu) => {
            if (!menu.contains(event.target)) menu.removeAttribute('open');
        });
    });

    page.querySelectorAll('.planning-action-menu form').forEach((form) => {
        form.addEventListener('click', (event) => event.stopPropagation());
    });
})();
