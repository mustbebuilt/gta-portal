(() => {
 const controls=document.querySelector('[data-visual-controls]');
 const select=document.querySelector('[data-visual-mode]');
 const panels=[...document.querySelectorAll('[data-visual-panel]')];
 if(!controls||!select||!panels.length)return;
 const valid=value=>['both','charts','table'].includes(value);
 let mode='both';try{const value=localStorage.getItem('gta-visual-display');if(valid(value))mode=value;}catch{}
 const paint=()=>{select.value=mode;for(const panel of panels){panel.dataset.displayMode=mode;}};
 select.addEventListener('change',()=>{if(!valid(select.value))return;mode=select.value;paint();try{localStorage.setItem('gta-visual-display',mode);}catch{}});
 controls.hidden=false;paint();
})();
