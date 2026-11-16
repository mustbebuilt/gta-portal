/* Optional pointer shortcut only: all writes stay in existing checked server forms. */
(() => {
 const tools=document.querySelector('[data-workload-tools]');if(!tools)return;
 const toggle=tools.querySelector('[data-enable-workload-drag]');const status=tools.querySelector('[data-workload-drag-status]');
 const sources=[...document.querySelectorAll('[data-new-workload],[data-move-workload]')];const targets=[...document.querySelectorAll('[data-workload-target]')];
 const types=new Set(['Teaching','Review','Admin','Travel','Non-working','Marking','Preparation','Learner support','Staff development']);
 function route(payload,target){
  if(payload.version!==1)return;let url;
  if(payload.action==='new'&&types.has(payload.kind)){url=new URL(tools.dataset.addUrl,window.location.href);url.searchParams.set('kind',payload.kind);}
  else if(payload.action==='move'&&/^[a-f0-9-]{36}$/i.test(payload.plan??'')){url=new URL(tools.dataset.moveUrl+'/'+payload.plan,window.location.href);}
  else return;
  if(target){url.searchParams.set('staff_id',target.dataset.staff);url.searchParams.set('date',target.dataset.date);}
  window.location.assign(url.href);
 }
 for(const el of sources){const isNew=el.hasAttribute('data-new-workload');const payload={version:1,action:isNew?'new':'move',...(isNew?{kind:el.dataset.newWorkload}:{plan:el.dataset.moveWorkload})};if(isNew)el.addEventListener('click',()=>route(payload));
  el.draggable=false;el.addEventListener('dragstart',e=>{if(!toggle.checked){e.preventDefault();return;}e.dataTransfer.setData('application/x-gta-workload',JSON.stringify(payload));e.dataTransfer.effectAllowed='link';if(status)status.textContent='Drop on a staff/date cell to open a form. Nothing will be saved automatically.';});
 }
 toggle.addEventListener('change',()=>{for(const el of sources)el.draggable=toggle.checked;for(const cell of targets)cell.classList.toggle('workload-drop-enabled',toggle.checked);if(status)status.textContent=toggle.checked?'Drag shortcuts enabled. Normal buttons and links remain available.':'Drag shortcuts off.';});
 for(const cell of targets){cell.addEventListener('dragover',e=>{if(toggle.checked&&Array.from(e.dataTransfer.types).includes('application/x-gta-workload')){e.preventDefault();e.dataTransfer.dropEffect='link';}});cell.addEventListener('drop',e=>{if(!toggle.checked)return;e.preventDefault();const raw=e.dataTransfer.getData('application/x-gta-workload');if(raw.length>500)return;try{route(JSON.parse(raw),cell);}catch{if(status)status.textContent='That item cannot be used. Choose Add workload or Move workload instead.';}});}
})();
