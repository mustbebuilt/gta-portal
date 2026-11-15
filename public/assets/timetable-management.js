'use strict';
(()=>{const button=document.getElementById('planning-gap-toggle');const grid=document.getElementById('planning-board-grid');if(!button||!grid)return;button.addEventListener('click',()=>{const active=button.getAttribute('aria-pressed')!=='true';button.setAttribute('aria-pressed',String(active));grid.classList.toggle('planning-focus-gaps',active);});})();
