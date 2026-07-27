(() => {
    'use strict';

    const search = document.querySelector('[data-alert-search]');
    const buttons = Array.from(document.querySelectorAll('[data-alert-filter]'));
    const cards = Array.from(document.querySelectorAll('.alerts-item'));
    const calendar = document.querySelector('#shared-calendar');
    let activeFilter = 'active';

    const applyFilters = () => {
        const query = (search?.value || '').trim().toLowerCase();
        cards.forEach((card) => {
            const status = card.dataset.status || '';
            const urgent = card.dataset.urgent === '1';
            const haystack = card.dataset.search || '';
            const matchesSearch = query === '' || haystack.includes(query);
            const matchesFilter = activeFilter === 'active'
                || (activeFilter === 'unread' && status === 'unread')
                || (activeFilter === 'urgent' && urgent);
            card.hidden = activeFilter === 'calendar' || !matchesSearch || !matchesFilter;
        });
    };

    search?.addEventListener('input', applyFilters);

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            activeFilter = button.dataset.alertFilter || 'active';
            buttons.forEach((candidate) => candidate.classList.toggle('active', candidate === button));
            applyFilters();
            if (activeFilter === 'calendar' && calendar) {
                calendar.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    document.querySelectorAll('.alerts-controls details').forEach((details) => {
        details.addEventListener('toggle', () => {
            if (!details.open) return;
            document.querySelectorAll('.alerts-controls details').forEach((candidate) => {
                if (candidate !== details) candidate.open = false;
            });
        });
    });

    applyFilters();
})();
