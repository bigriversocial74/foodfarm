(() => {
    'use strict';

    const root = document.querySelector('.shopping-page');
    if (!root) return;

    const tabs = Array.from(root.querySelectorAll('[data-shopping-tab]'));
    const rows = Array.from(root.querySelectorAll('[data-shopping-item]'));
    const groups = Array.from(root.querySelectorAll('[data-shopping-group]'));
    const search = root.querySelector('[data-shopping-search]');
    const count = root.querySelector('[data-shopping-count]');
    let activeTab = 'restock';

    const applyFilters = () => {
        const query = (search?.value || '').trim().toLowerCase();
        let visible = 0;

        rows.forEach((row) => {
            const source = row.dataset.shoppingSource || 'restock';
            const text = row.dataset.shoppingSearchText || '';
            const tabMatch = activeTab === 'all' || source === activeTab;
            const searchMatch = query === '' || text.includes(query);
            const show = tabMatch && searchMatch;
            row.hidden = !show;
            if (show) visible += 1;
        });

        groups.forEach((group) => {
            const source = group.dataset.shoppingGroup || 'restock';
            const groupKey = group.dataset.shoppingGroupKey || '';
            const hasVisibleRows = rows.some((row) => !row.hidden && (row.dataset.shoppingGroupKey || '') === groupKey);
            group.hidden = activeTab !== 'all' ? source !== activeTab || !hasVisibleRows : !hasVisibleRows;
        });

        if (count) count.textContent = `${visible} item${visible === 1 ? '' : 's'} shown`;
    };

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            activeTab = tab.dataset.shoppingTab || 'all';
            tabs.forEach((candidate) => candidate.classList.toggle('active', candidate === tab));
            applyFilters();
        });
    });

    search?.addEventListener('input', applyFilters);
    applyFilters();
})();
