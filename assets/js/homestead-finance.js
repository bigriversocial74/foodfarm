(() => {
    const search = document.querySelector('[data-finance-search]');
    const buttons = [...document.querySelectorAll('[data-finance-filter]')];
    const items = [...document.querySelectorAll('[data-finance-list] .finance-purchase')];
    let filter = 'all';
    const apply = () => {
        const query = (search?.value || '').trim().toLowerCase();
        items.forEach((item) => {
            const matchesFilter = filter === 'all' || (filter === 'high' && item.dataset.high === '1') || (filter === 'supplier' && item.dataset.supplier === '1');
            const matchesSearch = !query || (item.dataset.search || '').includes(query);
            item.hidden = !(matchesFilter && matchesSearch);
        });
    };
    buttons.forEach((button) => button.addEventListener('click', () => {
        filter = button.dataset.financeFilter || 'all';
        buttons.forEach((candidate) => candidate.classList.toggle('active', candidate === button));
        apply();
    }));
    search?.addEventListener('input', apply);
})();
