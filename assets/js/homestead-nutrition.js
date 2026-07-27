(() => {
  'use strict';
  const input = document.querySelector('[data-nutrition-search]');
  const cards = Array.from(document.querySelectorAll('[data-nutrition-list] > article'));
  if (input) {
    input.addEventListener('input', () => {
      const query = input.value.trim().toLowerCase();
      cards.forEach(card => {
        card.hidden = query !== '' && !(card.dataset.search || '').includes(query);
      });
    });
  }
  document.querySelectorAll('.nutrition-controls details').forEach(detail => {
    detail.addEventListener('toggle', () => {
      if (!detail.open) return;
      document.querySelectorAll('.nutrition-controls details[open]').forEach(other => {
        if (other !== detail) other.open = false;
      });
    });
  });
})();
