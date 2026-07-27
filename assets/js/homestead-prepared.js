(() => {
    const search = document.querySelector('[data-prepared-search]');
    const buttons = [...document.querySelectorAll('[data-prepared-filter]')];
    const items = [...document.querySelectorAll('[data-prepared-list] .prepared-batch')];
    if (!items.length) return;

    let filter = 'all';
    const apply = () => {
        const query = (search?.value || '').trim().toLowerCase();
        items.forEach((item) => {
            const status = item.dataset.status || '';
            const matchesFilter = filter === 'all'
                || (filter === 'priority' && item.dataset.priority === '1')
                || (filter === 'active' && status === 'active')
                || (filter === 'frozen' && status === 'frozen')
                || (filter === 'closed' && !['active', 'frozen'].includes(status));
            const matchesSearch = !query || (item.dataset.search || '').includes(query);
            item.hidden = !(matchesFilter && matchesSearch);
        });
    };
    buttons.forEach((button) => button.addEventListener('click', () => {
        filter = button.dataset.preparedFilter || 'all';
        buttons.forEach((candidate) => candidate.classList.toggle('active', candidate === button));
        apply();
    }));
    search?.addEventListener('input', apply);
    document.addEventListener('click', (event) => {
        document.querySelectorAll('.prepared-batch__action details[open]').forEach((details) => {
            if (!details.contains(event.target)) details.removeAttribute('open');
        });
    });
})();
