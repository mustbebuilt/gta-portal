(() => {const button=document.querySelector('#theme-toggle');if(!button)return;let dark=false;try{dark=localStorage.getItem('gta-local-theme')==='dark'}catch{}function paint(){document.documentElement.dataset.theme=dark?'dark':'light';button.textContent=dark?'Light mode':'Dark mode';button.setAttribute('aria-pressed',String(dark));}paint();button.addEventListener('click',()=>{dark=!dark;paint();try{localStorage.setItem('gta-local-theme',dark?'dark':'light')}catch{}})})();

for(const form of document.querySelectorAll('[data-smart-form]')){const method=form.querySelector('[name=method]');const update=()=>{for(const section of form.querySelectorAll('[data-smart-section]')){section.hidden=section.dataset.smartSection!==method.value;for(const control of section.querySelectorAll('input,select,textarea'))control.disabled=section.hidden;}};method.addEventListener('change',update);update();}

for(const button of document.querySelectorAll('[data-print]'))button.addEventListener('click',()=>window.print());

for(const form of document.querySelectorAll('form[method=post]')){let dirty=false;form.addEventListener('input',()=>{dirty=true});form.addEventListener('change',()=>{dirty=true});form.addEventListener('submit',()=>{dirty=false});window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});}

for(const form of document.querySelectorAll('[data-staff-entry]')){const kind=form.querySelector('[name=kind]');const update=()=>{for(const group of form.querySelectorAll('[data-staff-kinds]')){group.hidden=!group.dataset.staffKinds.split(' ').includes(kind.value);for(const input of group.querySelectorAll('input,textarea,select'))input.disabled=group.hidden;}};kind.addEventListener('change',update);update();}

if('speechSynthesis' in window && 'SpeechSynthesisUtterance' in window){const stop=document.querySelector('[data-stop-help]');const buttons=document.querySelectorAll('[data-read-help]');const finish=()=>{for(const button of buttons)button.setAttribute('aria-pressed','false');if(stop)stop.hidden=true;};for(const button of buttons){button.hidden=false;button.setAttribute('aria-pressed','false');button.addEventListener('click',()=>{window.speechSynthesis.cancel();finish();const text=document.getElementById(button.dataset.readHelp);if(!text)return;const speech=new SpeechSynthesisUtterance(text.textContent);speech.lang='en-GB';speech.onend=finish;speech.onerror=finish;button.setAttribute('aria-pressed','true');if(stop)stop.hidden=false;window.speechSynthesis.speak(speech);});}if(stop)stop.addEventListener('click',()=>{window.speechSynthesis.cancel();finish();});if(buttons.length)window.addEventListener('pagehide',()=>window.speechSynthesis.cancel());}

// Print the full text of task answers, including content outside a textarea's visible box.
window.addEventListener('beforeprint',()=>{
  for(const textarea of document.querySelectorAll('textarea[data-print-full-answer]')){
    let copy=textarea.nextElementSibling;
    if(!copy?.classList.contains('print-full-answer')){
      copy=document.createElement('p');
      copy.className='print-full-answer';
      textarea.after(copy);
    }
    copy.textContent=textarea.value||'Not recorded';
    textarea.classList.add('has-print-answer');
  }
});

// Progressive enhancement: navigation remains visible if JavaScript is unavailable.
(() => {
  const button = document.getElementById('navigation-toggle');
  const navigation = document.getElementById('portal-navigation');
  if (!button || !navigation) return;
  const compact = window.matchMedia('(max-width: 850px)');
  const show = (expanded) => {
    navigation.hidden = !expanded;
    button.setAttribute('aria-expanded', String(expanded));
    button.textContent = expanded ? 'Hide menu' : 'Show menu';
  };
  const resize = () => {
    const focusInside = navigation.contains(document.activeElement);
    // Never hide the focused navigation item when the viewport shrinks.
    // When expanding to desktop, move focus away from the disappearing toggle.
    const toggleFocused = document.activeElement === button;
    show(!compact.matches || focusInside);
    if (!compact.matches && toggleFocused) {
      const target = navigation.querySelector('[aria-current="page"]') || navigation.querySelector('a');
      target?.focus();
    }
    button.hidden = !compact.matches;
  };
  button.addEventListener('click', () => show(navigation.hidden));
  navigation.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && compact.matches && !navigation.hidden) {
      event.preventDefault();
      button.focus();
      show(false);
    }
  });
  compact.addEventListener('change', resize);
  resize();
})();

// A rejected apprentice/directory submission lands at the server-rendered summary.
// Its native fragment links remain usable when JavaScript is unavailable.
document.querySelector('[data-error-summary]')?.focus();
