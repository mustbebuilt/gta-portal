/* GTA sign-in animation only. No external requests or dependencies. */
(() => {
 'use strict';
 const root = document.querySelector('.portal-signin');
 if (!root || root.dataset.atmosphereReady) return;
 const scene = root.querySelector('.signin-scene');
 const controls = root.querySelector('[data-atmosphere-controls]');
 if (!scene || !controls) return;
 const motionButton = controls.querySelector('[data-animation-toggle]');
 const picture=scene.querySelector('picture');
 if(!motionButton || !picture)return;
 root.dataset.atmosphereReady='true';
 const read = key => { try { return localStorage.getItem('gta-signin-v2-' + key); } catch { return null; } };
 const write = (key, value) => { try { localStorage.setItem('gta-signin-v2-' + key, String(value)); } catch {} };
 const reduced = matchMedia('(prefers-reduced-motion: reduce)');
 const compact = matchMedia('(max-width:850px)');
 let paused = read('paused') === 'true', disposed = false, pageActive = true;
 controls.hidden = false;
 let canvas, ctx, observer, raf = 0, last = 0, elapsed = 0, width = 0, height = 0, ratio = 1;
 let visible = true;
 try {
  canvas = document.createElement('canvas');
  canvas.className = 'street-atmosphere'; canvas.setAttribute('aria-hidden', 'true');
  ctx = canvas.getContext('2d', {alpha:true});
  if (ctx) picture.after(canvas);
 } catch { ctx = null; }
 const random = n => { const x = Math.sin(n * 127.1 + 31.7) * 43758.5453; return x - Math.floor(x); };
 const glow = (x, y, radius, colour, alpha) => {
  const g = ctx.createRadialGradient(x, y, 0, x, y, radius);
  g.addColorStop(0, 'rgba(' + colour + ',' + alpha + ')');
  g.addColorStop(1, 'rgba(' + colour + ',0)');
  ctx.fillStyle = g; ctx.fillRect(x-radius, y-radius, radius*2, radius*2);
 };
 const resize = () => {
  const rect = scene.getBoundingClientRect(); width = rect.width; height = rect.height;
  ratio = Math.min(devicePixelRatio || 1, compact.matches ? 1 : 1.25);
  if (!ctx || !width || !height) return;
  canvas.width = Math.round(width*ratio); canvas.height = Math.round(height*ratio);
 };
 // Coordinates use the original 1586 x 992 artwork and its existing cover crop.
 const render = time => {
  ctx.setTransform(ratio,0,0,ratio,0,0); ctx.clearRect(0,0,width,height);
  const scale = Math.max(width/1586,height/992), ox = (width-1586*scale)*.4, oy = (height-992*scale)*(compact.matches?.57:.5);
  // Draw rain in viewport coordinates so it remains visible at every crop.
  const seconds=time/1000;
  ctx.lineWidth=1;ctx.strokeStyle='rgba(190,226,247,.21)';ctx.beginPath();
  for(let i=0;i<(compact.matches?9:28);i++){
   const x=((random(i)*width-seconds*(30+random(i+50)*15))%width+width)%width;
   const y=(random(i+80)*height+seconds*(160+random(i+40)*95))%height;
   ctx.moveTo(x,y);ctx.lineTo(x-3,y+13);
  }ctx.stroke();
  ctx.translate(ox,oy); ctx.scale(scale,scale);
  const t = time/1000;
  // A local brief dip, staggered across lights; no rapid repeated flashes.
  const flicker = offset => {
   const phase=(t+offset)%19;
   if(phase>=.42)return 1;
   const dip=phase<.10?phase/.10:phase<.24?1:(.42-phase)/.18;
   return 1-.8*Math.max(0,dip);
  };
  // Most lights continue their slow brightness cycle.
  [[930,130,18],[1006,389,27],[1396,429,18],[1492,180,20],[490,49,25]].forEach(([x,y,r],i) => {
   const a = .20 + .13*Math.sin(t*.65+i*1.8) + .025*Math.sin(t*1.3+i);
   const level=i===1?flicker(8):1;
   glow(x,y,r*2.5,'2,9,17',.68*(1-level));
   glow(x,y,r*2.5,'255,54,177',a*level);
  });
  [[838,163],[905,115],[1430,165],[1079,230],[670,66]].forEach(([x,y],i)=>{
   const a = .16 + .18*(1+Math.sin(t*.38+i*2))/2;
   ctx.fillStyle='rgba(116,222,245,'+a+')';
   for(let j=0;j<3;j++) ctx.fillRect(x+j*7,y+j*10,3,5);
  });
  // Actual lamp heads and wall tubes: a slow 10-second bright/dim cycle.
  [[1164,166,24],[1328,120,27],[300,342,90],[770,300,50],[1264,322,24],[1528,310,24]].forEach(([x,y,r],i)=>{
   const level=i===2?flicker(14):i===3?flicker(2):1;
   const brightness=(.2+.8*(1+Math.sin(t*.62+i*1.9))/2)*level;
   glow(x,y,r*1.6,'2,9,17',.64*(1-brightness));
   glow(x,y,r*2,'90,225,250',.32*brightness);
  });
  // Wet-road light blooms remain behind the parked vehicles.
  ctx.save();ctx.translate(1265,735);ctx.scale(1,.23);
  glow(0,0,220,'67,211,239',.045+.025*Math.sin(t*.5));
  glow(170,180,185,'242,52,170',.04+.025*Math.sin(t*.41+1));ctx.restore();
  // Slow ground haze on the far right, away from the car and welcome text.
  ctx.save();ctx.translate(1440+Math.sin(t*.07)*65,520);ctx.scale(1,.45);
  glow(0,0,180,'156,202,218',.045);ctx.restore();
  // A single scrap crosses the clear road for six seconds, once every 32 seconds.
  const phase=t%32;
  if(phase>23 && phase<29){
   const p=(phase-23)/6;ctx.save();ctx.translate(1080+p*650,772-55*Math.sin(p*Math.PI)+7*Math.sin(p*18));
   ctx.rotate(p*9);ctx.globalAlpha=.22*Math.min(1,p*8,(1-p)*8);
   ctx.fillStyle='#c3c9ce';ctx.beginPath();ctx.moveTo(-7,-3);ctx.lineTo(4,-5);ctx.lineTo(8,3);ctx.lineTo(-5,4);ctx.closePath();ctx.fill();ctx.restore();
  }
 };
 const canAnimate = () => ctx && !disposed && pageActive && !paused && !reduced.matches && !document.hidden && visible && width>0 && height>0;
 const tick = now => {
  raf=0;if(!canAnimate())return;
  const delta=last?Math.min(now-last,100):0;
  if(!last || delta >= (compact.matches?50:1000/30)) {elapsed+=delta;render(elapsed);last=now;}
  raf=requestAnimationFrame(tick);
 };
 const syncMotion = () => {
  motionButton.disabled=!ctx || reduced.matches;
  motionButton.textContent=!ctx?'Static background':reduced.matches?'Reduced motion enabled':paused?'Resume animation':'Pause animation';
  motionButton.setAttribute('aria-pressed',String(paused || reduced.matches));
  motionButton.title=reduced.matches?'Animation follows your device’s reduced-motion setting.':paused?'Street animation is paused.':'Street animation is running.';
  if(!canAnimate()) {cancelAnimationFrame(raf);raf=0;last=0;if(ctx && (paused || reduced.matches)){ctx.setTransform(1,0,0,1,0,0);ctx.clearRect(0,0,canvas.width,canvas.height);}}
  else if(!raf){last=0;raf=requestAnimationFrame(tick);}
 };
 motionButton.addEventListener('click',()=>{paused=!paused;write('paused',paused);syncMotion();});
 const visibility=()=>syncMotion();
 const changed=()=>{resize();syncMotion();};
 const pagehide=()=>{pageActive=false;syncMotion();};
 const pageshow=()=>{pageActive=true;changed();};
 const cleanup=()=>{
  disposed=true;pagehide();observer?.disconnect();resizeObserver?.disconnect();removedObserver.disconnect();
  document.removeEventListener('visibilitychange',visibility);window.removeEventListener('pagehide',pagehide);window.removeEventListener('pageshow',pageshow);window.removeEventListener('resize',changed);
  reduced.removeEventListener('change',changed);compact.removeEventListener('change',changed);
 };
 let resizeObserver;
 if(typeof ResizeObserver!=='undefined'){resizeObserver=new ResizeObserver(changed);resizeObserver.observe(scene);}else window.addEventListener('resize',changed);
 if(typeof IntersectionObserver!=='undefined'){observer=new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;syncMotion();});observer.observe(scene);}
 const removedObserver=new MutationObserver(()=>{if(!root.isConnected)cleanup();});removedObserver.observe(document.body,{childList:true,subtree:true});
 document.addEventListener('visibilitychange',visibility);window.addEventListener('pagehide',pagehide);window.addEventListener('pageshow',pageshow);
 reduced.addEventListener('change',changed);compact.addEventListener('change',changed);
 // Form HTML is already parsed and usable before the first animation frame.
 changed();
})();
