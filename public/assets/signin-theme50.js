(() => {
 const button=document.querySelector('[data-signin-theme]');
 if(!button)return;
 let theme='dark';
 try { const saved=localStorage.getItem('gta-local-theme');if(['light','dark'].includes(saved))theme=saved; } catch {}
 const paint=()=>{document.documentElement.dataset.theme=theme;button.textContent=theme==='dark'?'Light mode':'Dark mode';button.setAttribute('aria-pressed',String(theme==='dark'));};
 paint();button.addEventListener('click',()=>{theme=theme==='dark'?'light':'dark';paint();try{localStorage.setItem('gta-local-theme',theme);}catch{}});
})();
