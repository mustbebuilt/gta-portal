import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../public/assets/portal.js',import.meta.url),'utf8');
const checks=[];
function element(){return {hidden:true,value:'',textContent:'',dataset:{},attributes:{},children:[],handlers:{},setAttribute(k,v){this.attributes[k]=v;},addEventListener(k,fn){this.handlers[k]=fn;},append(o){this.children.push(o);if(!this.value)this.value=o.value;},replaceChildren(){this.children=[];this.value='';}};}
function fixture({voices=[],stored='',blocked=false,supported=true,help=true}={}){
 const read=element();read.dataset.readHelp='guide';const stop=element(),select=element(),controls=element(),accent=element();
 const speech=[],events={},engine={voices,getVoices(){return this.voices;},cancel(){},speak(value){speech.push(value);},addEventListener(k,fn){events[k]=fn;}};
 const guide={textContent:'General how-to guide only'};
 const document={querySelector(q){return {'[data-stop-help]':stop,'[data-help-voice]':select,'[data-help-voice-controls]':controls,'[data-help-accent-note]':accent}[q]||null;},querySelectorAll(q){return q==='[data-read-help]'&&help?[read]:[];},getElementById(id){return id==='guide'?guide:null;},createElement(){return element();}};
 const storedValues=new Map([['gta-help-voice',stored]]);const storage={getItem(k){if(blocked)throw Error('blocked');return storedValues.get(k)||null;},setItem(k,v){if(blocked)throw Error('blocked');storedValues.set(k,v);}};
 class Utterance{constructor(text){this.text=text;}}
 const window={addEventListener(k,fn){events[k]=fn;}};if(supported){window.speechSynthesis=engine;window.SpeechSynthesisUtterance=Utterance;}
 vm.runInNewContext(source,{document,window,localStorage:storage,SpeechSynthesisUtterance:Utterance,console});
 return {read,stop,select,controls,accent,speech,events,engine,storedValues};
}
const uk={name:'Fictional UK voice',lang:'en-GB',voiceURI:'uk'};
const us={name:'Fictional US voice',lang:'en-US',voiceURI:'us'};
const york={name:'Fictional Yorkshire voice',lang:'en-GB',voiceURI:'york'};
let f=fixture({voices:[us,uk,york]});assert.equal(f.speech.length,0);assert.equal(f.select.value,'york');assert.equal(f.controls.hidden,false);checks.push('Available Yorkshire-labelled voice is offered first, with no autoplay');
f.read.handlers.click();assert.equal(f.speech.length,1);assert.equal(f.speech[0].voice.voiceURI,'york');assert.equal(f.speech[0].text,'General how-to guide only');assert.equal(f.stop.hidden,false);checks.push('Explicit help button speaks only its general guide using the selected voice');
f.select.value='uk';f.select.handlers.change();assert.equal(f.storedValues.get('gta-help-voice'),'uk');assert.equal(f.stop.hidden,true);f.read.handlers.click();assert.equal(f.speech[1].voice.voiceURI,'uk');checks.push('Changing voice stops speech and remembers the explicit choice');
f=fixture({voices:[york,uk],stored:'uk'});assert.equal(f.select.value,'uk');assert.equal(f.speech.length,0);checks.push('Saved choice wins over the suggested regional voice without starting speech');
f=fixture({voices:[us,uk]});assert.equal(f.select.value,'uk');assert.match(f.accent.textContent,/No Yorkshire/);checks.push('Absent Yorkshire voice is stated honestly and UK English is preferred');
f=fixture({voices:[],blocked:true});f.read.handlers.click();assert.equal(f.speech[0].lang,'en-GB');assert.equal(f.speech[0].voice,undefined);f.engine.voices=[uk];f.events.voiceschanged();assert.equal(f.select.value,'uk');checks.push('Late device voices and blocked local storage work with a UK-language fallback');
f=fixture({supported:false});assert.equal(f.read.hidden,true);assert.equal(f.controls.hidden,true);checks.push('Unsupported speech leaves readable help and hidden voice controls');
f=fixture({help:false});assert.equal(f.speech.length,0);assert.equal(f.events.voiceschanged,undefined);assert.equal(f.events.pagehide,undefined);checks.push('Non-help pages do not initialise speech or read record content');
console.log(JSON.stringify({passed:checks.length,failed:0,checks,scope:'Node DOM/speech stubs; not actual browser/audio/accent acceptance'}));
