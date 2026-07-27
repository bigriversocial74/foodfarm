(() => {
    'use strict';

    const search = document.querySelector('[data-pantry-search]');
    const rows = Array.from(document.querySelectorAll('[data-inventory-row]'));
    const buttons = Array.from(document.querySelectorAll('[data-pantry-filter]'));
    const count = document.querySelector('[data-pantry-count]');

    if (!search || rows.length === 0) {
        return;
    }

    let category = 'all';

    const update = () => {
        const query = search.value.trim().toLowerCase();
        let visible = 0;

        rows.forEach((row) => {
            const matchesCategory = category === 'all' || row.dataset.category === category;
            const matchesQuery = query === '' || (row.dataset.search || '').includes(query);
            const show = matchesCategory && matchesQuery;
            row.hidden = !show;
            if (show) {
                visible += 1;
            }
        });

        if (count) {
            count.textContent = `${visible.toLocaleString()} ${visible === 1 ? 'item' : 'items'}`;
        }
    };

    search.addEventListener('input', update);
    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            category = button.dataset.pantryFilter || 'all';
            buttons.forEach((candidate) => candidate.classList.toggle('is-active', candidate === button));
            update();
        });
    });
})();
