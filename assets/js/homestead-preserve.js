(() => {
    const rows = [...document.querySelectorAll('[data-preserve-row]')];
    if (rows.length === 0) return;

    const search = document.querySelector('[data-preserve-search]');
    const method = document.querySelector('[data-preserve-select="method"]');
    const status = document.querySelector('[data-preserve-select="status"]');
    const location = document.querySelector('[data-preserve-select="location"]');
    const groupButtons = [...document.querySelectorAll('[data-preserve-method]')];
    const reset = document.querySelector('[data-preserve-reset]');
    const count = document.querySelector('[data-preserve-count]');
    let group = 'all';

    const apply = () => {
        const query = (search?.value || '').trim().toLowerCase();
        const selectedMethod = method?.value || 'all';
        const selectedStatus = status?.value || 'all';
        const selectedLocation = location?.value || 'all';
        let visible = 0;

        rows.forEach((row) => {
            const matches = (!query || row.dataset.name.includes(query))
                && (group === 'all' || row.dataset.group === group)
                && (selectedMethod === 'all' || row.dataset.method === selectedMethod)
                && (selectedStatus === 'all' || row.dataset.status === selectedStatus)
                && (selectedLocation === 'all' || row.dataset.location === selectedLocation);
            row.hidden = !matches;
            if (matches) visible += 1;
        });

        if (count) count.textContent = `${visible} ${visible === 1 ? 'batch' : 'batches'} shown`;
    };

    search?.addEventListener('input', apply);
    [method, status, location].forEach((control) => control?.addEventListener('change', apply));
    groupButtons.forEach((button) => button.addEventListener('click', () => {
        group = button.dataset.preserveMethod || 'all';
        groupButtons.forEach((candidate) => candidate.classList.toggle('active', candidate === button));
        apply();
    }));
    reset?.addEventListener('click', () => {
        group = 'all';
        if (search) search.value = '';
        [method, status, location].forEach((control) => { if (control) control.value = 'all'; });
        groupButtons.forEach((button) => button.classList.toggle('active', button.dataset.preserveMethod === 'all'));
        apply();
    });

    apply();
})();
