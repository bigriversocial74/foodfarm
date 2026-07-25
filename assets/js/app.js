(() => {
    'use strict';

    const sidebar = document.getElementById('sidebar');
    const menuButton = document.getElementById('menuButton');
    const toast = document.getElementById('toast');
    let toastTimer;

    const showToast = (message = 'Demo action recorded.') => {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('show');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => toast.classList.remove('show'), 2200);
    };

    menuButton?.addEventListener('click', () => sidebar?.classList.toggle('open'));

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;

        if (window.innerWidth <= 980 && sidebar?.classList.contains('open')) {
            const insideSidebar = target.closest('#sidebar');
            const isMenuButton = target.closest('#menuButton');
            if (!insideSidebar && !isMenuButton) sidebar.classList.remove('open');
        }

        const demoButton = target.closest('.button, .quick-action, .recipe-meta button');
        if (demoButton) {
            event.preventDefault();
            showToast('Application shell action simulated.');
        }
    });

    document.querySelectorAll('.task-row input, .shopping-row input').forEach((input) => {
        input.addEventListener('change', () => {
            const row = input.closest('label');
            row?.classList.toggle('completed', input.checked);
            showToast(input.checked ? 'Item marked complete.' : 'Item reopened.');
        });
    });

    document.querySelectorAll('.top-search input, .search-field').forEach((input) => {
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                showToast(`Search simulated for “${input.value || 'all records'}”.`);
            }
        });
    });
})();
