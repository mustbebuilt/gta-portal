/* Decorative travel only. Recorded dates, milestones, URLs and progress are never modified. */
(() => {
 const road=document.querySelector('[data-journey-road]');if(!road)return;
 const marker=road.querySelector('[data-journey-vehicle]');const current=road.querySelector('[data-journey-current-stop]');const path=road.querySelector('[data-journey-path]');
 const button=document.querySelector('[data-journey-motion]');const note=document.querySelector('[data-journey-motion-note]');if(!marker||!current||!path||!button)return;
 const image=marker.querySelector('img');const previous=road.querySelector('[data-journey-previous-stop]');const media=window.matchMedia('(prefers-reduced-motion: reduce)');let paused=false,frame=null,travelled=false;
 try{paused=localStorage.getItem('gta-journey-motion')==='paused';}catch{}
 const cancel=()=>{if(frame!==null)window.cancelAnimationFrame(frame);frame=null;};
 function geometry(){const matrix=path.ownerSVGElement.getScreenCTM();const total=path.getTotalLength();if(!matrix||!Number.isFinite(total)||total<=0)return null;
  const position=el=>{const r=el.getBoundingClientRect();const y=new DOMPoint(r.left+r.width/2,r.top+r.height/2).matrixTransform(matrix.inverse()).y;let lo=0,hi=total;for(let i=0;i<20;i++){const mid=(lo+hi)/2;if(path.getPointAtLength(mid).y<y)lo=mid;else hi=mid;}return(lo+hi)/2;};
  return{matrix,total,target:position(current),start:previous?position(previous):position(current)};
 }
 function paint(g,distance){const point=path.getPointAtLength(distance);const screen=new DOMPoint(point.x,point.y).matrixTransform(g.matrix);const box=current.getBoundingClientRect();marker.style.left=(screen.x-box.left)+'px';marker.style.top=(screen.y-box.top)+'px';if(image){const a=path.getPointAtLength(Math.max(0,distance-1));const b=path.getPointAtLength(Math.min(g.total,distance+1));image.style.transform='rotate('+(Math.atan2((b.y-a.y)*g.matrix.d,(b.x-a.x)*g.matrix.a)*180/Math.PI-90)+'deg)';}}
 function snap(){cancel();try{const g=geometry();if(g)paint(g,g.target);}catch{} }
 const allowed=()=>!paused&&!media.matches&&!document.hidden;
 function update(travel=false){snap();road.classList.add('journey-motion-enabled');road.classList.toggle('journey-motion-paused',!allowed());button.hidden=false;button.disabled=media.matches;button.setAttribute('aria-pressed',String(paused||media.matches));button.textContent=media.matches?'Motion off (reduced motion)':(paused?'Resume marker pulse':'Pause marker animation');if(note)note.textContent=media.matches?'Your reduced-motion preference keeps the marker still.':'The vehicle marks the latest recorded date; animation changes no records.';
  if(!travel||travelled||!allowed())return;travelled=true;let g;try{g=geometry();}catch{return;}if(!g||Math.abs(g.target-g.start)<1)return;let start=null;const tick=time=>{if(!allowed()){snap();return;}if(start===null)start=time;const p=Math.min(1,(time-start)/2200);paint(g,g.start+(g.target-g.start)*(1-Math.pow(1-p,3)));if(p<1)frame=window.requestAnimationFrame(tick);else frame=null;};frame=window.requestAnimationFrame(tick);
 }
 button.addEventListener('click',()=>{paused=!paused;try{localStorage.setItem('gta-journey-motion',paused?'paused':'on');}catch{}update(false);});
 media.addEventListener?.('change',()=>update(false));document.addEventListener('visibilitychange',()=>update(false));window.addEventListener('resize',snap);
 if(typeof ResizeObserver==='function'){let initial=true;const observer=new ResizeObserver(()=>{if(initial){initial=false;return;}snap();});observer.observe(road);observer.observe(current);}update(true);
})();
