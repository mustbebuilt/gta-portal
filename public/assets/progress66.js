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

// Local display filter over the server's safe event projection; no raw records are fetched.
for(const roadmap of document.querySelectorAll('[data-p67-roadmap]')){
 const select=roadmap.querySelector('[data-p67-category]');
 select?.addEventListener('change',()=>{
  let visible=0;
  for(const event of roadmap.querySelectorAll('[data-p67-event]')){event.hidden=!!select.value&&event.dataset.category!==select.value;if(!event.hidden)visible++;}
  const count=roadmap.querySelector('[data-p67-count]');if(count)count.textContent=visible+' matching journey event(s)';
  const empty=roadmap.querySelector('[data-p67-empty]');if(empty)empty.hidden=visible>0;
 });
}
