"""Actual console processes and private filesystem copy on disposable fictional SQLite.
No real portal, remote service, learner data, relink or cutover is used.
"""
from pathlib import Path
import argparse,base64,hashlib,json,os,secrets,sqlite3,subprocess,tempfile
p=argparse.ArgumentParser();p.add_argument('--php',default='php');p.add_argument('--output',required=True);args=p.parse_args()
project=Path(__file__).resolve().parent.parent;checks=[]
def passed(name):checks.append({'name':name,'passed':True})
with tempfile.TemporaryDirectory(prefix='gta-evidence-copy-') as temporary:
    temp=Path(temporary);storage=temp/'storage';db=temp/'fictional.sqlite';db.touch()
    for part in ['app/private','framework/cache/data','framework/cache/locks','framework/views','framework/sessions','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
    env=os.environ.copy();env.update(APP_ENV='staging',APP_DEBUG='false',APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),DB_CONNECTION='sqlite',DB_DATABASE=str(db),SESSION_DRIVER='database',CACHE_STORE='database',MAIL_MAILER='array',QUEUE_CONNECTION='sync',LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(temp/'config.php'),APP_ROUTES_CACHE=str(temp/'routes.php'),GTA_ENABLE_EVIDENCE_COPY='true')
    bootstrap="require 'vendor/autoload.php';$a=require 'bootstrap/app.php';$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
    def php(code):
        r=subprocess.run([args.php,'-r',bootstrap+code],cwd=project,env=env,capture_output=True,text=True,timeout=60);assert r.returncode==0,'Private fictional fixture failed';return r.stdout
    def artisan(*command):
        r=subprocess.run([args.php,'artisan',*command,'--no-interaction'],cwd=project,env=env,capture_output=True,text=True,timeout=60)
        return r
    def snapshot():
        with sqlite3.connect(db) as c:return {table:c.execute('SELECT * FROM '+table+' ORDER BY id').fetchall() for table in ['users','apprentices','learning_tasks','task_evidence','qualifications']}
    try:
        php("$c=$a['config']->all();$c['filesystems']['disks']['copy_target']=['driver'=>'local','root'=>storage_path('copy-target'),'visibility'=>'private','serve'=>false,'throw'=>true];$c['evidence']=['disk'=>'local','legacy_disk'=>'local','private_disks'=>['local','copy_target']];file_put_contents(getenv('APP_CONFIG_CACHE'),'<?php return '.var_export($c,true).';');")
        assert artisan('migrate','--force').returncode==0;passed('Fresh disposable staging schema migrates with copy plans and restricted technical audit')
        meta=json.loads(php("$u=App\\Models\\User::factory()->create();$ap=App\\Models\\Apprentice::factory()->create();$t=App\\Models\\LearningTask::factory()->create(['apprentice_id'=>$ap->id]);$f=Illuminate\\Http\\UploadedFile::fake()->createWithContent('Fictional private name.txt','Fictional retained evidence bytes');$m=App\\Support\\PrivateEvidence::store($f,'task-evidence',$u->id);App\\Models\\TaskEvidence::create(['learning_task_id'=>$t->id,'uploaded_by'=>$u->id,...collect($m)->only('disk','path','name','mime','sha256','bytes')->all(),'kind'=>'supporting-evidence']);App\\Models\\Qualification::factory()->create(['apprentice_id'=>$ap->id,'files'=>[$m],'history'=>[['before'=>['files'=>[$m]]]]]);echo json_encode($m);"))
        before=snapshot();source=storage/'app/private'/meta['path'];source_bytes=source.read_bytes();passed('Fictional saved upload and retained duplicate metadata exist on the private source disk')
        r=artisan('gta:evidence-copy-plan','--source=local','--target=copy_target');preview=json.loads(r.stdout);assert r.returncode==0 and preview['objects']==1 and not preview['writes_performed'] and not preview['file_reads'];assert not (storage/'copy-target').exists();passed('Actual preview command reports one deduplicated object and creates no destination')
        r=artisan('gta:evidence-copy-plan','--source=local','--target=copy_target','--record','--expected-proposal='+preview['proposal_hash'],'--reference=Fictional controlled rehearsal');plan=json.loads(r.stdout);assert r.returncode==0 and plan['state']=='Planned' and plan['integrity']=='intact';passed('Actual record command pins exact preview and saves one protected plan')
        command=['gta:evidence-copy',plan['plan_id']];r=artisan(*command);assert r.returncode==0 and json.loads(r.stdout)['state']=='Planned' and not (storage/'copy-target').exists();passed('Inspection in a fresh process performs no copy')
        r=artisan(*command,'--copy','--expected-version=99','--expected-hash='+plan['manifest_hash']);assert r.returncode==1 and not (storage/'copy-target').exists();passed('A stale expected version refuses before any destination write')
        execute=command+['--copy','--expected-version='+str(plan['version']),'--expected-hash='+plan['manifest_hash']];r=artisan(*execute);result=json.loads(r.stdout);assert r.returncode==0 and result['state']=='Verified staging copies' and result['result']['verified_copies']==1
        with sqlite3.connect(db) as c:
            manifest=json.loads(c.execute('SELECT manifest FROM evidence_copy_plans').fetchone()[0]);events=c.execute('SELECT event,origin FROM evidence_copy_events ORDER BY id').fetchall()
        dest=storage/'copy-target'/manifest['items'][0]['destination']['path'];assert dest.read_bytes()==source_bytes and dest.stat().st_mode&0o777==0o600;assert events==[(e,'console') for e in ['plan_recorded','copy_started','object_intended','object_verified','copy_finished']];passed('Actual execution writes exclusively, hash-verifies private bytes and records ordered technical events')
        r=artisan(*execute);assert r.returncode==1 and dest.read_bytes()==source_bytes;passed('Replaying the same command refuses and retains the destination unchanged')
        r=artisan(*command);persisted=json.loads(r.stdout);assert r.returncode==0 and persisted['state']=='Verified staging copies' and persisted['version']==3;assert snapshot()==before and source.read_bytes()==source_bytes;passed('Fresh-process inspection preserves plan state and all original source records and file bytes')
        for value in [meta['name'],meta['path'],manifest['items'][0]['destination']['path'],'Fictional controlled rehearsal']:
            assert value not in r.stdout
        inventory=artisan('gta:evidence-inventory','--verify-files');assert inventory.returncode==0 and json.loads(inventory.stdout)['totals']['verified_objects']==1;passed('CLI summaries omit private locators and original registered evidence still verifies after the copy')
        output={'passed':len(checks),'failed':0,'checks':checks,'engine':'SQLite disposable fictional schema','applicationLinksChanged':False,'sourcesDeleted':False,'cutoverApproved':False,'actualCloudAccess':False}
        Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
