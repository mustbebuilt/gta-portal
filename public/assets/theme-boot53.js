(() => {let theme='dark';try{const saved=localStorage.getItem('gta-local-theme');if(saved==='light'||saved==='dark')theme=saved;}catch{}document.documentElement.dataset.theme=theme;})();
