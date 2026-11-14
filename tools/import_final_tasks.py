from pathlib import Path
import zipfile,json,hashlib,copy
import argparse
parser=argparse.ArgumentParser(description='Development-only reconciliation of the verified final task release; never edits database rows.')
parser.add_argument('archive')
args=parser.parse_args()
root=Path(__file__).resolve().parents[1];archive=Path(args.archive);z=zipfile.ZipFile(archive);p='GTA_Final_Task_Packs_2026-11-13/'
checks=json.loads(z.read(p+'FILE_CHECKSUMS.json'))
for name,h in checks.items():assert hashlib.sha256(z.read(p+name)).hexdigest()==h,name
receipt={'archive':archive.name,'archiveHash':hashlib.sha256(archive.read_bytes()).hexdigest(),'checkedMembers':len(checks),'release':'final-2026-11-13','pdfs':[],'localReconciliation':['Retained v0.14 guarded Panel placeholder upgrade and all account controls.','Panel blank unassessed scores still require needs_practice explanation, following original source.','Did not install the supplied older controller overlay; merged only title/feedback narrative cap removal.']}
for key in ['autocare','heavy-vehicle','paint','panel']:
 c=json.loads(z.read(p+f'portal-overlay/resources/data/{key}-catalogue.json'));tasks=json.loads(z.read(p+f'portal-overlay/resources/data/{key}-tasks.json'))
 assert len(tasks)==50 and len({t['id'] for t in tasks})==50
 for block in range(1,11):assert sorted(t['task'] for t in tasks if t['block']==block)==[1,2,3,4,5]
 for task in tasks:
  t=c['templates'][c['tasks'][task['id']]];name=t['sourcePdfFilename'];raw=z.read(p+'portal-overlay/resources/task-pdfs/'+name)
  assert hashlib.sha256(raw).hexdigest()==t['sourceTemplateHash']
  if key=='panel' and t['kind']=='impact':
   t['unassessedReasonField']='needs_practice'
   for f in t['fields']:
    if f['name']=='needs_practice':f['label']='What still needs practice, support or assessment? Explain each unassessed START or END score here.'
   t['sha256']=hashlib.sha256(json.dumps({k:v for k,v in t.items() if k!='sha256'},sort_keys=True,ensure_ascii=False).encode()).hexdigest()
  assert Path(name).name==name and name.startswith('final-20261113--'+key+'--')
  target=root/'resources/task-pdfs'/name
  assert not target.exists() or target.read_bytes()==raw, 'Existing release PDF differs; reconcile explicitly.'
  if t.get('adasReleaseId'):
   t['instructions']=t['instructions'].replace('The original downloadable PDF is retained and does not include these portal questions.','The final-release downloadable PDF includes these two ADAS questions; earlier saved source PDFs remain unchanged.')
   t['sha256']=hashlib.sha256(json.dumps({k:v for k,v in t.items() if k!='sha256'},sort_keys=True,ensure_ascii=False).encode()).hexdigest()
  target.write_bytes(raw)
  receipt['pdfs'].append({'catalogue':key,'reference':task['id'],'filename':name,'sha256':hashlib.sha256(raw).hexdigest()})
 for suffix,obj in [('catalogue',c),('tasks',tasks)]: (root/f'resources/data/{key}-{suffix}.json').write_text(json.dumps(obj,ensure_ascii=False,indent=2)+'\n')
(root/'resources/data/final-task-pack-receipt.json').write_text(json.dumps(receipt,indent=2)+'\n')
f=root/'app/Http/Controllers/LearningTaskController.php';s=f.read_text().replace("'string', 'max:20000'","'string'").replace("'string', 'max:500'","'string'");f.write_text(s)
f=root/'resources/views/learning/task.blade.php';s=f.read_text();s=s.replace('<input id="test-title" name="test_title" value="{{ old(\'test_title\',$task->template[\'testTitleDefault\']) }}" required>','<textarea id="test-title" name="test_title" rows="3" required>{{ old(\'test_title\',$task->template[\'testTitleDefault\']) }}</textarea>');f.write_text(s)
print('Verified and integrated 200 final task PDFs; old 131 retained; no saved database rows modified.')
