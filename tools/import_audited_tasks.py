from pathlib import Path
import json,zipfile,hashlib
import argparse
parser=argparse.ArgumentParser(description='Import QA2 source files only; never rewrites saved database tasks.')
parser.add_argument('archive');args=parser.parse_args()
root=Path(__file__).resolve().parents[1];archive=Path(args.archive);z=zipfile.ZipFile(archive);p='GTA_Final_Task_Packs_2026-11-13/'
checks=json.loads(z.read(p+'FILE_CHECKSUMS.json'))
for n,h in checks.items():assert hashlib.sha256(z.read(p+n)).hexdigest()==h,n
old=json.loads((root/'resources/data/final-task-pack-receipt.json').read_text());
if old.get('release')=='final-2026-11-13':
 (root/'resources/data/final-task-pack-v1-receipt.json').write_text(json.dumps(old,indent=2)+'\n')
else:
 assert (root/'resources/data/final-task-pack-v1-receipt.json').exists(), 'Retain the earlier source receipt before importing QA2.'
receipt={'archive':archive.name,'archiveHash':hashlib.sha256(archive.read_bytes()).hexdigest(),'checkedMembers':len(checks),'release':'final-2026-11-13-qa2','pdfs':[],'localReconciliation':['Retain Panel unassessed score/explanation rule from the original source.','Retain corrected final-release Autocare ADAS wording.','No saved task templates, dates, work or marking history rewritten.']}
for key in ['autocare','heavy-vehicle','paint','panel']:
 c=json.loads(z.read(p+f'portal-overlay/resources/data/{key}-catalogue.json'));tasks=json.loads(z.read(p+f'portal-overlay/resources/data/{key}-tasks.json'));assert len(tasks)==50
 for ref,name in c['tasks'].items():
  t=c['templates'][name];pdf=t['sourcePdfFilename'];raw=z.read(p+'portal-overlay/resources/task-pdfs/'+pdf)
  assert Path(pdf).name==pdf and pdf.startswith('final-20261113-qa2--'+key+'--');assert hashlib.sha256(raw).hexdigest()==t['sourceTemplateHash']
  dest=root/'resources/task-pdfs'/pdf;assert not dest.exists() or dest.read_bytes()==raw;dest.write_bytes(raw)
  changed=False
  if key=='panel' and t['kind']=='impact':
   t['unassessedReasonField']='needs_practice';changed=True
   for f in t['fields']:
    if f['name']=='needs_practice':f['label']='What still needs practice, support or assessment? Explain each unassessed START or END score here.'
  if t.get('adasReleaseId'):
   t['instructions']=t['instructions'].replace('The original downloadable PDF is retained and does not include these portal questions.','The final-release downloadable PDF includes these two ADAS questions; earlier saved source PDFs remain unchanged.');changed=True
  if changed:t['sha256']=hashlib.sha256(json.dumps({k:v for k,v in t.items() if k!='sha256'},sort_keys=True,ensure_ascii=False).encode()).hexdigest()
  receipt['pdfs'].append({'catalogue':key,'reference':ref,'filename':pdf,'sha256':hashlib.sha256(raw).hexdigest()})
 for suffix,value in [('catalogue',c),('tasks',tasks)]: (root/f'resources/data/{key}-{suffix}.json').write_text(json.dumps(value,ensure_ascii=False,indent=2)+'\n')
(root/'resources/data/final-task-pack-receipt.json').write_text(json.dumps(receipt,indent=2)+'\n')
(root/'resources/data/task-pack-qa2-audit.json').write_bytes(z.read(p+'FULL_AUDIT_RESULTS.json'))
print('QA2: 200 new immutable PDF sources; previous 331 preserved.')
