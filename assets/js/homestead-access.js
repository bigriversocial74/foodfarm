(() => {
    const search = document.querySelector('[data-member-search]');
    const members = Array.from(document.querySelectorAll('[data-member]'));
    const empty = document.querySelector('[data-member-empty]');

    const filterMembers = () => {
        const query = (search?.value || '').trim().toLowerCase();
        let visible = 0;
        members.forEach((member) => {
            const matches = query === '' || (member.dataset.search || '').includes(query);
            member.hidden = !matches;
            if (matches) visible += 1;
        });
        if (empty) empty.hidden = visible !== 0;
    };
    search?.addEventListener('input', filterMembers);

    document.querySelectorAll('[data-copy-target]').forEach((button) => {
        button.addEventListener('click', async () => {
            const target = document.querySelector(button.dataset.copyTarget || '');
            if (!(target instanceof HTMLInputElement)) return;
            target.select();
            try {
                await navigator.clipboard.writeText(target.value);
                const original = button.textContent;
                button.textContent = 'Copied';
                window.setTimeout(() => { button.textContent = original; }, 1600);
            } catch {
                document.execCommand('copy');
            }
        });
    });

    document.addEventListener('click', (event) => {
        document.querySelectorAll('.access-permissions[open]').forEach((details) => {
            if (!details.contains(event.target)) details.removeAttribute('open');
        });
    });
})();
