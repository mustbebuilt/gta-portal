// Native details work without JavaScript. Escape closes the containing action group.
document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape' || !(event.target instanceof Element)) return;
  const details = event.target.closest('.portal-page details.action-disclosure64[open]');
  if (!details) return;
  details.open = false;
  details.querySelector('summary')?.focus();
  event.preventDefault();
});
