(() => {
  const cards = [...document.querySelectorAll('[data-kit-card]')];
  const search = document.querySelector('[data-kit-search]');
  const filters = [...document.querySelectorAll('[data-kit-filter]')];
  let status = 'all';
  const apply = () => {
    const term = (search?.value || '').trim().toLowerCase();
    cards.forEach(card => {
      const statusMatch = status === 'all' || card.dataset.status === status;
      const textMatch = !term || (card.dataset.search || '').includes(term);
      card.hidden = !(statusMatch && textMatch);
    });
  };
  filters.forEach(button => button.addEventListener('click', () => {
    status = button.dataset.kitFilter || 'all';
    filters.forEach(item => item.classList.toggle('active', item === button));
    apply();
  }));
  search?.addEventListener('input', apply);
  document.querySelectorAll('[data-copy-target]').forEach(button => button.addEventListener('click', async () => {
    const input = document.getElementById(button.dataset.copyTarget || '');
    if (!input) return;
    input.select();
    try { await navigator.clipboard.writeText(input.value); button.textContent = 'Copied'; }
    catch { document.execCommand('copy'); button.textContent = 'Copied'; }
  }));
})();
