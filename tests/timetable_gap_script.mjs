import vm from 'node:vm';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const script=fs.readFileSync(new URL('../public/assets/timetable-management.js',import.meta.url),'utf8');
let handler;let pressed='false';const classes=new Set();const button={getAttribute:()=>pressed,setAttribute:(k,v)=>{assert.equal(k,'aria-pressed');pressed=v;},addEventListener:(event,fn)=>{assert.equal(event,'click');handler=fn;}};
const grid={classList:{toggle:(k,v)=>v?classes.add(k):classes.delete(k)}};
vm.runInNewContext(script,{document:{getElementById:id=>id==='planning-gap-toggle'?button:grid}});handler();assert.equal(pressed,'true');assert.ok(classes.has('planning-focus-gaps'));handler();assert.equal(pressed,'false');assert.ok(!classes.has('planning-focus-gaps'));vm.runInNewContext(script,{document:{getElementById:()=>null}});
console.log(JSON.stringify({passed:3,failed:0,checks:['Enable checked-gap emphasis','Disable emphasis','No controls on shared view is safe'],rendered_browser_tested:false}));
