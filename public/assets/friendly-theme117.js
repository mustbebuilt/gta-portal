(() => {
  let theme = document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
  try { const saved = localStorage.getItem('gta-local-theme'); theme = saved === 'dark' ? 'dark' : 'light'; } catch { theme = 'light'; }
  const paint = () => {
    document.documentElement.dataset.theme = theme;
    document.querySelectorAll('#theme-toggle, [data-signin-theme]').forEach(button => {
      button.textContent = theme === 'dark' ? 'Light mode' : 'Dark mode';
      button.setAttribute('aria-pressed', String(theme === 'dark'));
    });
  };
  // Own the toggle in capture phase so older theme scripts cannot double-toggle.
  document.addEventListener('click', event => {
    const button = event.target.closest('#theme-toggle, [data-signin-theme]');
    if (!button) return;
    event.preventDefault(); event.stopImmediatePropagation();
    theme = theme === 'dark' ? 'light' : 'dark'; paint();
    try { localStorage.setItem('gta-local-theme', theme); } catch {}
  }, true);
  paint();
})();
