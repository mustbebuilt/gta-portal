import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
const source=fs.readFileSync('public/assets/report-visuals.js','utf8');let passed=0;
function fixture(saved=null,broken=false,empty=false){const handlers={};const select={value:'',addEventListener:(name,fn)=>handlers[name]=fn};const controls={hidden:true};const panels=empty?[]:[{dataset:{}},{dataset:{}}];const writes=[];const localStorage={getItem(){if(broken)throw Error('denied');return saved;},setItem(k,v){if(broken)throw Error('denied');writes.push([k,v]);}};const document={querySelector:q=>q==='[data-visual-controls]'?controls:q==='[data-visual-mode]'?select:null,querySelectorAll:()=>panels};vm.runInNewContext(source,{document,localStorage});return{select,controls,panels,writes,change:()=>handlers.change()};}
let f=fixture();assert.equal(f.controls.hidden,false);assert.equal(f.select.value,'both');assert.deepEqual(f.panels.map(p=>p.dataset.displayMode),['both','both']);passed++;
f=fixture('table');assert.equal(f.select.value,'table');assert.equal(f.panels[0].dataset.displayMode,'table');passed++;
f=fixture('invented');assert.equal(f.select.value,'both');passed++;
f=fixture();f.select.value='charts';f.change();assert.equal(f.panels[0].dataset.displayMode,'charts');assert.deepEqual(f.writes,[['gta-visual-display','charts']]);passed++;
f=fixture(null,true);f.select.value='table';assert.doesNotThrow(()=>f.change());assert.equal(f.panels[0].dataset.displayMode,'table');passed++;
f=fixture(null,false,true);assert.equal(f.controls.hidden,true);passed++;
f=fixture();f.select.value='bad';f.change();assert.equal(f.panels[0].dataset.displayMode,'both');assert.equal(f.writes.length,0);passed++;
const css=fs.readFileSync('public/assets/portal.css','utf8');assert.match(css,/@media print\{\.visual-controls/);assert.match(css,/\[data-visual-chart\],\.visual-panel \[data-visual-table\]\{display:block!important/);passed++;
console.log(JSON.stringify({passed,failed:0,renderedBrowser:false,scope:'Minimal DOM display preference and storage behaviour; print CSS contract only'}));
