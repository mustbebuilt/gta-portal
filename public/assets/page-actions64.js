// Native details work without JavaScript. Escape closes the containing action group.
document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape' || !(event.target instanceof Element)) return;
  const details = event.target.closest('.portal-page details.action-disclosure64[open]');
  if (!details) return;
  details.open = false;
  details.querySelector('summary')?.focus();
  event.preventDefault();
});

// Existing task and action links can target a record inside a collapsed history.
function revealLinkedRecord() {
  const target = document.getElementById(window.location.hash.slice(1));
  if (!target) return;
  let revealed = false;
  for (let panel = target; panel; panel = panel.parentElement) {
    if (panel instanceof HTMLDetailsElement && panel.hasAttribute('data-reveal-on-link') && !panel.open) {
      panel.open = true;
      revealed = true;
    }
  }
  if (revealed) target.scrollIntoView();
}
window.addEventListener('hashchange', revealLinkedRecord);
revealLinkedRecord();
