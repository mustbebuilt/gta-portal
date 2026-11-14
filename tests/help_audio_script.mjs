import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../public/assets/help-audio.js',import.meta.url),'utf8');
const checks=[];
function item(){return {hidden:true,textContent:'Initial transcript is always available',attributes:{},dataset:{},handlers:{},setAttribute(k,v){this.attributes[k]=v;},addEventListener(k,fn){this.handlers[k]=fn;}};}
function fixture({supported=true,url='https://portal.example.test/help/guides/start/recordings/fictional',count=1,fail=false,deferred=false}={}){
 const rows=[];const pending=[];let cancels=0;
 for(let n=0;n<count;n++){
  const play=item(),stop=item(),status=item(),audio=item();play.dataset.recordingUrl=url+'/'+n;audio.src='';audio.paused=true;audio.playCount=0;audio.pauseCount=0;audio.loadCount=0;
  audio.play=function(){this.playCount++;this.paused=false;if(deferred)return new Promise((resolve,reject)=>pending.push({resolve,reject}));return fail?Promise.reject(Error('fictional decode error')):Promise.resolve();};
  if(!supported)audio.play=undefined;
  audio.pause=function(){this.pauseCount++;this.paused=true;};audio.removeAttribute=function(k){if(k==='src')this.src='';};audio.load=function(){this.loadCount++;};
  rows.push({play,stop,status,audio,querySelector(q){return {'[data-help-audio]':audio,'[data-play-recording]':play,'[data-stop-recording]':stop,'[data-recording-status]':status}[q]??null;}});
 }
 const read=item(),events={};const window={location:{origin:'https://portal.example.test',href:'https://portal.example.test/help'},speechSynthesis:{cancel(){cancels++;}},addEventListener(k,fn){events[k]=fn;}};
 const document={querySelectorAll(q){return q==='[data-help-recording]'?rows:q==='[data-read-help]'?[read]:[];}};
 vm.runInNewContext(source,{document,window,URL,Promise,console});return {rows,read,events,pending,get cancels(){return cancels;}};
}
const tick=async()=>{await Promise.resolve();await Promise.resolve();};
let f=fixture();assert.equal(f.rows[0].audio.playCount,0);assert.equal(f.rows[0].audio.src,'');assert.equal(f.rows[0].audio.loadCount,0);assert.equal(f.rows[0].play.hidden,false);checks.push('Player initialises without loading audio, autoplay or a speech-provider call');
f.rows[0].play.handlers.click();await tick();assert.equal(f.rows[0].audio.playCount,1);assert.equal(f.rows[0].audio.hidden,false);assert.equal(f.cancels,1);assert.match(f.rows[0].status.textContent,/Playing/);checks.push('Explicit approved-recording button sets only its same-origin source and stops device speech');
f.rows[0].audio.handlers.pause();assert.equal(f.rows[0].play.attributes['aria-pressed'],'false');assert.match(f.rows[0].status.textContent,/paused/);f.rows[0].audio.handlers.play();assert.equal(f.rows[0].play.attributes['aria-pressed'],'true');checks.push('Native pause/resume updates accessible player state');
f.rows[0].stop.handlers.click();assert.equal(f.rows[0].audio.src,'');assert.equal(f.rows[0].audio.hidden,true);assert.equal(f.rows[0].stop.hidden,true);assert.match(f.rows[0].status.textContent,/stopped/);checks.push('Stop clears source, stops network/playback and leaves the transcript available');
f=fixture({count:2});f.rows[0].play.handlers.click();await tick();f.rows[1].play.handlers.click();await tick();assert.equal(f.rows[0].audio.src,'');assert.equal(f.rows[1].audio.playCount,1);assert.match(f.rows[0].status.textContent,/stopped/);checks.push('Choosing another recording stops the first and updates its old status');
f.read.handlers.click();assert.equal(f.rows[1].audio.src,'');checks.push('Choosing device read-aloud stops recorded audio without choosing a device voice automatically');
f.rows[1].play.handlers.click();await tick();f.events.pagehide();assert.equal(f.rows[1].audio.src,'');checks.push('Leaving the page stops the active recording');
f=fixture();f.rows[0].play.handlers.click();await tick();f.rows[0].audio.handlers.ended();assert.match(f.rows[0].status.textContent,/Nothing has been marked complete/);assert.equal(f.rows[0].audio.src,'');checks.push('Ending a clip clears playback and does not complete training, a review or a questionnaire');
f=fixture({fail:true});f.rows[0].play.handlers.click();await tick();assert.match(f.rows[0].status.textContent,/Read the transcript/);assert.equal(f.rows[0].audio.src,'');checks.push('Play rejection gives readable fallback without automatically starting device speech');
f=fixture({deferred:true});f.rows[0].play.handlers.click();f.rows[0].stop.handlers.click();f.pending[0].resolve();await tick();assert.match(f.rows[0].status.textContent,/stopped/);assert.equal(f.rows[0].audio.src,'');checks.push('Late play promise cannot restart or relabel a deliberately stopped recording');
f=fixture({url:'https://unapproved.example.test/audio'});assert.equal(f.rows[0].play.hidden,true);assert.equal(f.rows[0].audio.playCount,0);checks.push('A different-origin media destination never becomes playable');
f=fixture({supported:false});assert.equal(f.rows[0].play.hidden,true);assert.equal(f.rows[0].audio.src,'');checks.push('Unsupported playback leaves transcript-first help without a broken active button');
f=fixture({count:0});assert.equal(f.events.pagehide,undefined);checks.push('Pages without recording controls do not initialise the recording player');
console.log(JSON.stringify({passed:checks.length,failed:0,checks,scope:'Node DOM/audio stubs only; no actual browser, audio listening, accent or phone acceptance'}));
