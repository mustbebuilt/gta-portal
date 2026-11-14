// Progressive, explicit playback of approved general-help recordings only.
(() => {
  const rows=[...document.querySelectorAll('[data-help-recording]')];
  if(!rows.length)return;
  let current=null,epoch=0;
  const stopAll=()=>{
    const previous=current;epoch++;current=null;
    for(const row of rows){const audio=row.querySelector('[data-help-audio]'),play=row.querySelector('[data-play-recording]'),stop=row.querySelector('[data-stop-recording]');
      if(audio){audio.pause();audio.removeAttribute('src');audio.load();audio.hidden=true;}
      if(audio===previous){const status=row.querySelector('[data-recording-status]');if(status)status.textContent='Recording stopped. The transcript remains available.';}
      play?.setAttribute('aria-pressed','false');if(stop)stop.hidden=true;
    }
  };
  for(const row of rows){
    const audio=row.querySelector('[data-help-audio]'),play=row.querySelector('[data-play-recording]'),stop=row.querySelector('[data-stop-recording]'),status=row.querySelector('[data-recording-status]');
    if(!audio||!play||!stop||typeof audio.play!=='function')continue;
    let url;try{url=new URL(play.dataset.recordingUrl,window.location.href);if(url.origin!==window.location.origin)continue;}catch{continue;}
    play.hidden=false;
    play.addEventListener('click',()=>{
      stopAll();window.speechSynthesis?.cancel();const token=epoch;current=audio;
      audio.src=url.href;audio.hidden=false;stop.hidden=false;play.setAttribute('aria-pressed','true');if(status)status.textContent='Loading recording. You can pause or stop at any time.';
      try{const started=audio.play();Promise.resolve(started).then(()=>{if(token===epoch&&current===audio&&status)status.textContent=audio.paused?'Recording paused. The transcript remains available.':'Playing the guide recording. The transcript remains available.';}).catch(()=>{if(token!==epoch||current!==audio)return;stopAll();if(status)status.textContent='The recording could not play. Read the transcript or choose device read-aloud.';});}
      catch{stopAll();if(status)status.textContent='The recording could not play. Read the transcript or choose device read-aloud.';}
    });
    stop.addEventListener('click',()=>{stopAll();if(status)status.textContent='Recording stopped. The transcript remains available.';});
    audio.addEventListener('pause',()=>{if(current===audio){play.setAttribute('aria-pressed','false');if(status)status.textContent='Recording paused. The transcript remains available.';}});
    audio.addEventListener('play',()=>{if(current===audio){play.setAttribute('aria-pressed','true');if(status)status.textContent='Playing the guide recording. The transcript remains available.';}});
    audio.addEventListener('ended',()=>{if(current!==audio)return;stopAll();if(status)status.textContent='Recording finished. Nothing has been marked complete.';});
    audio.addEventListener('error',()=>{if(current!==audio)return;stopAll();if(status)status.textContent='The recording is unavailable. Read the transcript or choose device read-aloud.';});
  }
  for(const button of document.querySelectorAll('[data-read-help]'))button.addEventListener('click',stopAll);
  window.addEventListener('pagehide',stopAll);
})();
