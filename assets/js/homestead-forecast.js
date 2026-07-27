(() => {
    const search = document.querySelector('[data-forecast-search]');
    const buttons = [...document.querySelectorAll('[data-forecast-filter]')];
    const items = [...document.querySelectorAll('[data-forecast-list] .forecast-projection')];
    let filter = 'all';
    const apply = () => {
        const query = (search?.value || '').trim().toLowerCase();
        items.forEach((item) => {
            const matchesFilter = filter === 'all'
                || item.dataset.status === filter
                || (filter === 'low' && item.dataset.confidence === 'low');
            const matchesSearch = !query || (item.dataset.search || '').includes(query);
            item.hidden = !(matchesFilter && matchesSearch);
        });
    };
    buttons.forEach((button) => button.addEventListener('click', () => {
        filter = button.dataset.forecastFilter || 'all';
        buttons.forEach((candidate) => candidate.classList.toggle('active', candidate === button));
        apply();
    }));
    search?.addEventListener('input', apply);
    document.addEventListener('click', (event) => {
        document.querySelectorAll('.forecast-season details[open], .forecast-run-control[open]').forEach((details) => {
            if (!details.contains(event.target)) details.removeAttribute('open');
        });
    });
})();
