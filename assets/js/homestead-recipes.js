(() => {
    'use strict';

    const searchInput = document.querySelector('[data-recipe-search]');
    const filterButtons = Array.from(document.querySelectorAll('[data-recipe-filter]'));
    const cards = Array.from(document.querySelectorAll('[data-recipe-card]'));
    const resultCount = document.querySelector('[data-recipe-result-count]');
    const emptyState = document.querySelector('[data-recipe-empty]');
    const filterToggle = document.querySelector('[data-recipe-filter-toggle]');
    const filterPanel = document.querySelector('[data-recipe-filters]');
    let activeFilter = 'all';

    const randomCompletionKey = () => {
        const bytes = new Uint8Array(32);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    };

    const prepareRecipeCompletionForms = () => {
        const forms = Array.from(document.querySelectorAll('form')).filter((form) => {
            const action = form.querySelector('input[name="action"]');
            return action?.value === 'complete_recipe';
        });

        forms.forEach((form) => {
            let completionKey = form.querySelector('input[name="completion_key"]');
            if (!completionKey) {
                completionKey = document.createElement('input');
                completionKey.type = 'hidden';
                completionKey.name = 'completion_key';
                form.prepend(completionKey);
            }
            if (!/^[a-f0-9]{64}$/.test(completionKey.value)) {
                completionKey.value = randomCompletionKey();
            }

            const useByDate = form.querySelector('input[name="use_by_date"]');
            if (useByDate) {
                useByDate.required = true;
            }
        });
    };

    const applyFilters = () => {
        const query = (searchInput?.value || '').trim().toLowerCase();
        let visibleCount = 0;

        cards.forEach((card) => {
            const searchText = (card.dataset.search || '').toLowerCase();
            const category = card.dataset.category || '';
            const readiness = card.dataset.readiness || '';
            const matchesQuery = query === '' || searchText.includes(query);
            const matchesFilter = activeFilter === 'all'
                || (activeFilter === 'ready' && readiness === 'ready')
                || category === activeFilter;
            const visible = matchesQuery && matchesFilter;
            card.hidden = !visible;
            if (visible) visibleCount += 1;
        });

        if (resultCount) resultCount.textContent = `${visibleCount} shown`;
        if (emptyState) emptyState.hidden = visibleCount !== 0;
    };

    searchInput?.addEventListener('input', applyFilters);

    filterButtons.forEach((button) => {
        button.addEventListener('click', () => {
            activeFilter = button.dataset.recipeFilter || 'all';
            filterButtons.forEach((candidate) => {
                candidate.classList.toggle('is-active', (candidate.dataset.recipeFilter || 'all') === activeFilter);
            });
            applyFilters();
            document.querySelector('[data-recipe-grid]')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });

    filterToggle?.addEventListener('click', () => {
        if (!filterPanel) return;
        const isHidden = filterPanel.hidden;
        filterPanel.hidden = !isHidden;
        filterToggle.setAttribute('aria-expanded', String(isHidden));
    });

    prepareRecipeCompletionForms();
    applyFilters();
})();
