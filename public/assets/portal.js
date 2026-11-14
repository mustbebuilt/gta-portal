(() => {const button=document.querySelector('#theme-toggle');if(!button)return;let dark=false;try{dark=localStorage.getItem('gta-local-theme')==='dark'}catch{}function paint(){document.documentElement.dataset.theme=dark?'dark':'light';button.textContent=dark?'Light mode':'Dark mode';button.setAttribute('aria-pressed',String(dark));}paint();button.addEventListener('click',()=>{dark=!dark;paint();try{localStorage.setItem('gta-local-theme',dark?'dark':'light')}catch{}})})();

for(const form of document.querySelectorAll('[data-smart-form]')){const method=form.querySelector('[name=method]');const update=()=>{for(const section of form.querySelectorAll('[data-smart-section]')){section.hidden=section.dataset.smartSection!==method.value;for(const control of section.querySelectorAll('input,select,textarea'))control.disabled=section.hidden;}};method.addEventListener('change',update);update();}

for(const button of document.querySelectorAll('[data-print]'))button.addEventListener('click',()=>window.print());

for(const form of document.querySelectorAll('form[method=post]')){let dirty=false;form.addEventListener('input',()=>{dirty=true});form.addEventListener('change',()=>{dirty=true});form.addEventListener('submit',()=>{dirty=false});window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});}

for(const form of document.querySelectorAll('[data-staff-entry]')){const kind=form.querySelector('[name=kind]');const update=()=>{for(const group of form.querySelectorAll('[data-staff-kinds]')){group.hidden=!group.dataset.staffKinds.split(' ').includes(kind.value);for(const input of group.querySelectorAll('input,textarea,select'))input.disabled=group.hidden;}};kind.addEventListener('change',update);update();}

// Optional general help read-aloud. Only an explicit button click can start speech.
(() => {
  const buttons = [...document.querySelectorAll('[data-read-help]')];
  if (!buttons.length) return;
  const controls = document.querySelector('[data-help-voice-controls]');
  const select = document.querySelector('[data-help-voice]');
  const accent = document.querySelector('[data-help-accent-note]');
  const stop = document.querySelector('[data-stop-help]');
  if (!('speechSynthesis' in window) || !('SpeechSynthesisUtterance' in window)) return;
  const engine = window.speechSynthesis;
  let voices = [];
  let preferred = '';
  try { preferred = localStorage.getItem('gta-help-voice') || ''; } catch {}
  const voiceId = voice => voice.voiceURI || `${voice.name}|${voice.lang}`;
  const finish = () => {
    buttons.forEach(button => button.setAttribute('aria-pressed', 'false'));
    if (stop) stop.hidden = true;
  };
  const cancel = () => { engine.cancel(); finish(); };
  const populate = () => {
    voices = [...engine.getVoices()].sort((a,b) => {
      const rank = voice => /yorkshire/i.test(voice.name) ? 0 : /^en[-_]GB$/i.test(voice.lang) ? 1 : /^en[-_]/i.test(voice.lang) ? 2 : 3;
      return rank(a) - rank(b) || a.name.localeCompare(b.name);
    });
    if (!select) return;
    select.replaceChildren();
    if (!voices.length) {
      const option = document.createElement('option'); option.value = ''; option.textContent = 'Device default UK English request'; select.append(option);
    } else {
      voices.forEach(voice => { const option = document.createElement('option'); option.value = voiceId(voice); option.textContent = `${voice.name} — ${voice.lang}`; select.append(option); });
      select.value = voices.some(voice => voiceId(voice) === preferred) ? preferred : voiceId(voices[0]);
    }
    if (accent) accent.textContent = voices.some(voice => /yorkshire/i.test(voice.name))
      ? 'A Yorkshire-labelled voice is available on this device. Select it to try it.'
      : 'No Yorkshire-labelled voice is listed on this device. The exact accent depends on the voice you choose.';
  };
  populate();
  if (controls) controls.hidden = false;
  engine.addEventListener('voiceschanged', populate);
  select?.addEventListener('change', () => {
    cancel(); preferred = select.value;
    try { localStorage.setItem('gta-help-voice', preferred); } catch {}
  });
  buttons.forEach(button => {
    button.hidden = false; button.setAttribute('aria-pressed', 'false');
    button.addEventListener('click', () => {
      cancel();
      const text = document.getElementById(button.dataset.readHelp);
      if (!text) return;
      const speech = new SpeechSynthesisUtterance(text.textContent);
      const voice = voices.find(voice => voiceId(voice) === select?.value);
      if (voice) speech.voice = voice;
      speech.lang = voice?.lang || 'en-GB';
      speech.onend = finish; speech.onerror = finish;
      button.setAttribute('aria-pressed', 'true');
      if (stop) stop.hidden = false;
      engine.speak(speech);
    });
  });
  stop?.addEventListener('click', cancel);
  window.addEventListener('pagehide', cancel);
})();


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
      // A user may have closed the current group manually. Reveal it before focus.
      let group = target?.closest?.('details');
      while (group) {
        group.open = true;
        group = group.parentElement?.closest?.('details');
      }
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
