"""Non-rendering source checks. Does not certify browser/mobile/print appearance."""
from pathlib import Path
import argparse, hashlib, json, subprocess
parser=argparse.ArgumentParser()
parser.add_argument('--php',default='php')
parser.add_argument('--node',default='node')
parser.add_argument('--output')
args=parser.parse_args()
root=Path(__file__).resolve().parent.parent
def run(command):
    p=subprocess.run(command,cwd=root,capture_output=True,text=True)
    assert p.returncode==0,p.stdout+p.stderr
run([args.php,'artisan','view:cache'])
files=[]
for folder in ['app','bootstrap','config','database','routes','tests']:
    files.extend((root/folder).rglob('*.php'))
files.extend((root/'storage/framework/views').glob('*.php'))
for file in files:run([args.php,'-l',str(file)])
run([args.node,'--check','public/assets/portal.js'])
pdfs=json.loads((root/'resources/data/original-pdf-hashes.json').read_text())
assert len(pdfs)==41
for name,digest in pdfs.items():
    assert hashlib.sha256((root/'resources/task-pdfs'/name).read_bytes()).hexdigest()==digest,name
programme_pdfs = 0
for key in ['heavy-vehicle', 'paint', 'panel']:
    receipt=json.loads((root/f'resources/data/{key}-source-receipt.json').read_text())
    tasks=json.loads((root/f'resources/data/{key}-tasks.json').read_text())
    catalogue=json.loads((root/f'resources/data/{key}-catalogue.json').read_text())
    assert len(tasks)==50 and len({t['id'] for t in tasks})==50
    for number in range(1,11):assert sorted(t['task'] for t in tasks if t['block']==number)==[1,2,3,4,5]
    for pdf in receipt['pdfs']:
        assert hashlib.sha256((root/'resources/task-pdfs'/pdf['storedFilename']).read_bytes()).hexdigest()==pdf['sourcePdfHash']
        programme_pdfs+=1
final_receipt=json.loads((root/'resources/data/final-task-pack-receipt.json').read_text())
assert len(final_receipt['pdfs'])==200
for source in final_receipt['pdfs']:
    key=source['catalogue']
    c=json.loads((root/f'resources/data/{key}-catalogue.json').read_text())
    t=c['templates'][c['tasks'][source['reference']]]
    assert t['sourcePdfFilename']==source['filename'] and t['sourceTemplateHash']==source['sha256']
    assert hashlib.sha256((root/'resources/task-pdfs'/source['filename']).read_bytes()).hexdigest()==source['sha256']
prior_final=json.loads((root/'resources/data/final-task-pack-v1-receipt.json').read_text())
assert len(prior_final['pdfs'])==200
for source in prior_final['pdfs']:
    assert hashlib.sha256((root/'resources/task-pdfs'/source['filename']).read_bytes()).hexdigest()==source['sha256']
assert not any("Storage::disk('local')" in p.read_text() for p in (root/'app/Http/Controllers').glob('*.php'))
assets=json.loads((root/'tests/original-brand-hashes.json').read_text())
for name,digest in assets.items():assert hashlib.sha256((root/name).read_bytes()).hexdigest()==digest,name
assert 'name="viewport"' in (root/'resources/views/layout.blade.php').read_text()
result={'phpSyntaxFiles':len(files),'javascriptSyntaxFiles':1,'bladeCompilation':'passed','compiledBladeSyntax':'passed','originalPdfs':41,'pdfHashMatches':41,'additionalProgrammePdfs':programme_pdfs,'finalReleasePdfs':200,'priorFinalReleasePdfsRetained':200,'totalRetainedSourcePdfs':41+programme_pdfs+400,'additionalProgrammePdfHashes':'all match source receipts','privateEvidenceCallers':'all use the shared configured service','originalBrandAssets':'unchanged','brandAssetFiles':len(assets),'responsiveViewport':'present; layout not rendered','composerValidation':'Not performed by this script; see checkpoint-specific TEST_RESULTS.json','platformRequirements':'Not performed by this script; see checkpoint-specific TEST_RESULTS.json','visualBrowserVerification':False}
if args.output:Path(args.output).write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result))
