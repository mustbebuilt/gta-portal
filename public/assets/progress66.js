// Progressive feedback only. No data, analytics, external requests or role selection in the browser.
for (const form of document.querySelectorAll('[data-p66-filter]')) {
  form.addEventListener('submit', () => {
    form.setAttribute('aria-busy', 'true');
    const status = form.querySelector('[role=status]');
    if (status) status.hidden = false;
  });
}
window.addEventListener('pageshow', () => {
  for (const form of document.querySelectorAll('[data-p66-filter]')) {
    form.removeAttribute('aria-busy');
    const status = form.querySelector('[role=status]');
    if (status) status.hidden = true;
  }
});
document.addEventListener('keydown', event => {
  if (event.key !== 'Escape' || !(event.target instanceof Element)) return;
  const details = event.target.closest('.progress66 details[open]');
  if (details) { details.open = false; details.querySelector('summary')?.focus(); event.preventDefault(); }
});
