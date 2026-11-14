// Exercise the shipped navigation script in a small DOM fixture. Not browser/layout proof.
import vm from 'node:vm';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const checks=[];
const pass=(name)=>checks.push({name,passed:true});
function fixture({compact=true,authenticated=true,hasCurrent=true,errorSummary=false,closedCurrentGroup=false}={}) {
  const document={activeElement:{},querySelector:()=>null,querySelectorAll:()=>[]};
  const summary={focus(){document.activeElement=this;}};
  document.querySelector=(selector)=>selector==='[data-error-summary]' && errorSummary?summary:null;
  const listeners={};
  const button={hidden:true,textContent:'Hide menu',attributes:{'aria-expanded':'true'},handlers:{},setAttribute(k,v){this.attributes[k]=v;},addEventListener(k,fn){this.handlers[k]=fn;},focus(){document.activeElement=this;}};
  const group={open:false,parentElement:null};
  const link={closest(){return closedCurrentGroup?group:null;},focus(){assert.equal(nav.hidden,false,'Cannot focus a hidden link');if(closedCurrentGroup)assert.equal(group.open,true,'Cannot focus a link in a closed group');document.activeElement=this;}};
  const firstLink={focus(){assert.equal(nav.hidden,false,'Cannot focus a hidden link');document.activeElement=this;}};
  const nav={hidden:false,handlers:{},contains(item){return item===link || item===firstLink;},querySelector(selector){if(selector==='[aria-current="page"]')return hasCurrent?link:null;if(selector==='a' || selector.includes(','))return firstLink;return null;},addEventListener(k,fn){this.handlers[k]=fn;}};
  const media={matches:compact,addEventListener(k,fn){listeners[k]=fn;}};
  document.getElementById=(id)=>authenticated?({'navigation-toggle':button,'portal-navigation':nav}[id]??null):null;
  const window={addEventListener(){},matchMedia(){return media;}};
  vm.runInNewContext(fs.readFileSync(new URL('../public/assets/portal.js',import.meta.url),'utf8'),{document,window,localStorage:{},console});
  return {document,button,nav,link,firstLink,summary,group,media,resize(value){media.matches=value;listeners.change();}};
}
let f=fixture();assert.equal(f.button.hidden,false);assert.equal(f.nav.hidden,true);assert.equal(f.button.attributes['aria-expanded'],'false');assert.equal(f.button.textContent,'Show menu');pass('Compact initial state exposes Show menu and hides navigation');
f.button.handlers.click();assert.equal(f.nav.hidden,false);assert.equal(f.button.attributes['aria-expanded'],'true');assert.equal(f.button.textContent,'Hide menu');pass('Menu activation opens navigation and updates its accessible state');
f.link.focus();let prevented=false;f.nav.handlers.keydown({key:'Escape',preventDefault(){prevented=true;}});assert.equal(prevented,true);assert.equal(f.document.activeElement,f.button);assert.equal(f.nav.hidden,true);pass('Escape closes compact navigation and returns focus to its control');
f.resize(false);assert.equal(f.nav.hidden,false);assert.equal(f.button.hidden,true);assert.equal(f.document.activeElement,f.link);pass('Desktop resize shows navigation before moving focus off the disappearing control');
f.resize(true);assert.equal(f.nav.hidden,false);assert.equal(f.button.hidden,false);assert.equal(f.document.activeElement,f.link);pass('A focused navigation link remains visible when the viewport shrinks');
f.document.activeElement={};f.resize(false);f.resize(true);assert.equal(f.nav.hidden,true);pass('A later compact viewport collapses normally when focus is outside navigation');
f=fixture({compact:false});assert.equal(f.nav.hidden,false);assert.equal(f.button.hidden,true);f.link.focus();prevented=false;f.nav.handlers.keydown({key:'Escape',preventDefault(){prevented=true;}});assert.equal(prevented,false);assert.equal(f.nav.hidden,false);pass('Desktop navigation stays visible and Escape does not intercept normal keyboard behaviour');
f=fixture({hasCurrent:false});f.button.focus();f.resize(false);assert.equal(f.document.activeElement,f.firstLink);pass('Without a current section the first navigation link is an explicit fallback focus target');
f=fixture({authenticated:false});assert.equal(f.button.hidden,true);pass('Signed-out pages tolerate absent navigation without script errors');
f=fixture({errorSummary:true});assert.equal(f.document.activeElement,f.summary);pass('A server-rendered rejected-form summary receives focus after page setup');
f=fixture({closedCurrentGroup:true});f.button.focus();f.resize(false);assert.equal(f.group.open,true);assert.equal(f.document.activeElement,f.link);pass('Desktop focus transfer opens a manually closed current group before focusing its link');
console.log(JSON.stringify({passed:checks.length,failed:0,checks,runtime:'Node VM with a minimal DOM fixture executing shipped portal.js',browserVerified:false,limits:['Does not execute real browser default keyboard behaviour, layout, media CSS or assistive technology']}));
