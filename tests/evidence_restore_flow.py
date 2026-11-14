"""Actual restore console processes on disposable SQLite/private local files only.
Simulated original loss is confined to the fictional fixture; no live data, relink or cutover.
"""
from pathlib import Path
import argparse,base64,hashlib,json,os,secrets,sqlite3,subprocess,tempfile
parser=argparse.ArgumentParser();parser.add_argument('--php',default='php');parser.add_argument('--output',required=True);args=parser.parse_args()
project=Path(__file__).resolve().parent.parent;checks=[]
def passed(name):checks.append({'name':name,'passed':True})
with tempfile.TemporaryDirectory(prefix='gta-evidence-restore-')as temporary:
    temp=Path(temporary);db=temp/'fictional.sqlite';db.touch();storage=temp/'storage'
    for part in ['app/private','restore-target','framework/cache/data','framework/cache/locks','framework/views','framework/sessions','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
    env=os.environ.copy();env.update(APP_ENV='staging',APP_DEBUG='false',APP_URL='https://staging.example.test',APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),DB_CONNECTION='sqlite',DB_DATABASE=str(db),SESSION_DRIVER='database',SESSION_ENCRYPT='true',SESSION_SECURE_COOKIE='true',CACHE_STORE='database',MAIL_MAILER='array',QUEUE_CONNECTION='sync',LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(temp/'config.php'),APP_ROUTES_CACHE=str(temp/'routes.php'),GTA_ENABLE_STAGING_PORTAL='true',GTA_ENABLE_EVIDENCE_COPY='true',GTA_ENABLE_EVIDENCE_RESTORE_REHEARSAL='true')
    bootstrap="require 'vendor/autoload.php';$a=require 'bootstrap/app.php';$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
    def php(code):
        result=subprocess.run([args.php,'-r',bootstrap+code],cwd=project,env=env,capture_output=True,text=True,timeout=60);assert result.returncode==0,'Fictional private fixture setup failed';return result.stdout
    def artisan(*command):return subprocess.run([args.php,'artisan',*command,'--no-interaction'],cwd=project,env=env,capture_output=True,text=True,timeout=60)
    def snapshot():
        with sqlite3.connect(db)as c:return {table:c.execute('SELECT * FROM '+table+' ORDER BY id').fetchall()for table in ['users','apprentices','learning_tasks','task_evidence','qualifications','evidence_copy_plans']}
    try:
        php("$c=$a['config']->all();foreach(['copy_target'=>'copy-target','restore_target'=>'restore-target']as$name=>$directory){$c['filesystems']['disks'][$name]=['driver'=>'local','root'=>storage_path($directory),'visibility'=>'private','serve'=>false,'throw'=>true];}$c['evidence']=['disk'=>'local','legacy_disk'=>'local','private_disks'=>['local','copy_target','restore_target']];$c['evidence_restore']['targets']=['restore_target'];file_put_contents(getenv('APP_CONFIG_CACHE'),'<?php return '.var_export($c,true).';');")
        assert artisan('migrate','--force').returncode==0
        meta=json.loads(php("$u=App\\Models\\User::factory()->create(['role'=>'admin']);$a=App\\Models\\Apprentice::factory()->create();$t=App\\Models\\LearningTask::factory()->create(['apprentice_id'=>$a->id]);$m=App\\Support\\PrivateEvidence::store(Illuminate\\Http\\UploadedFile::fake()->createWithContent('PRIVATE-RECOVERY-NAME.txt','Fictional retained recovery bytes'),'task-evidence',$u->id);App\\Models\\TaskEvidence::create(['learning_task_id'=>$t->id,'uploaded_by'=>$u->id,...collect($m)->only('disk','path','name','mime','sha256','bytes')->all(),'kind'=>'supporting-evidence']);App\\Models\\Qualification::factory()->create(['apprentice_id'=>$a->id,'files'=>[$m],'history'=>[['before'=>['files'=>[$m]]]]]);echo json_encode($m);"))
        p=json.loads(artisan('gta:evidence-copy-plan','--source=local','--target=copy_target').stdout)
        plan=json.loads(artisan('gta:evidence-copy-plan','--source=local','--target=copy_target','--record','--expected-proposal='+p['proposal_hash'],'--reference=Fictional protected backup').stdout)
        copied=artisan('gta:evidence-copy',plan['plan_id'],'--copy','--expected-version=1','--expected-hash='+plan['manifest_hash']);assert copied.returncode==0
        before=snapshot();original=storage/'app/private'/meta['path'];expected_bytes=original.read_bytes()
        with sqlite3.connect(db)as c:copy_manifest=json.loads(c.execute('SELECT manifest FROM evidence_copy_plans').fetchone()[0])
        backup=storage/'copy-target'/copy_manifest['items'][0]['destination']['path']
        passed('Disposable staging schema creates a verified retained copy with unchanged registered evidence links')
        inspect=artisan('gta:evidence-backup',plan['plan_id']);assert inspect.returncode==0 and not json.loads(inspect.stdout)['file_reads'] and not list((storage/'restore-target').iterdir())
        passed('Default retained-backup inspection does not read evidence bytes or create restore files')
        # Simulated loss is a test-fixture action, never an application restore behavior.
        original.unlink();verified=artisan('gta:evidence-backup',plan['plan_id'],'--verify');assert verified.returncode==0 and json.loads(verified.stdout)['result']=='verified_retained_copies' and not json.loads(verified.stdout)['originals_read']
        passed('Explicit retained-copy verification succeeds after simulated loss of the fictional original')
        result=artisan('gta:evidence-restore-plan',plan['plan_id'],'--target=restore_target');proposal=json.loads(result.stdout);assert result.returncode==0 and proposal['objects']==1 and not proposal['file_reads'] and not proposal['writes_performed']
        for value in [meta['name'],meta['path'],str(storage),copy_manifest['items'][0]['destination']['path']]:assert value not in result.stdout
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM evidence_restore_runs').fetchone()[0]==0
        passed('Actual restore preview redacts private locators and writes neither files nor run records')
        result=artisan('gta:evidence-restore-plan',plan['plan_id'],'--target=restore_target','--record','--expected-hash='+proposal['proposal_hash'],'--reference=Fictional private restore reference');run=json.loads(result.stdout);assert result.returncode==0 and run['state']=='Prepared' and not list((storage/'restore-target').iterdir())
        inspected=artisan('gta:evidence-restore',run['run_id']);assert inspected.returncode==0 and json.loads(inspected.stdout)['state']=='Prepared'
        passed('Exact preview recording persists a protected run and fresh-process inspection performs no transfer')
        wrong=artisan('gta:evidence-restore',run['run_id'],'--restore','--expected-version=99','--expected-hash='+run['manifest_hash']);assert wrong.returncode==1 and not list((storage/'restore-target').iterdir())
        passed('Stale execution version is refused before any destination write')
        execute=['gta:evidence-restore',run['run_id'],'--restore','--expected-version=1','--expected-hash='+run['manifest_hash']]
        result=artisan(*execute);assert result.returncode==0 and json.loads(result.stdout)['state']=='Verified restore rehearsal'
        with sqlite3.connect(db)as c:
            manifest=json.loads(c.execute('SELECT manifest FROM evidence_restore_runs WHERE id=?',(run['run_id'],)).fetchone()[0]);events=c.execute('SELECT event FROM evidence_restore_events WHERE run_id=? ORDER BY id',(run['run_id'],)).fetchall()
        restored=storage/'restore-target'/manifest['items'][0]['destination']['path'];assert restored.read_bytes()==expected_bytes and restored.stat().st_mode&0o777==0o600 and backup.read_bytes()==expected_bytes and not original.exists() and snapshot()==before
        assert events==[(name,)for name in ['prepared','started','object_intended','object_verified','finished']]
        passed('Actual exclusive restore reads retained backup, writes private verified bytes and preserves all registered links/source records')
        assert artisan(*execute).returncode==1 and restored.read_bytes()==expected_bytes
        recheck=artisan('gta:evidence-restore',run['run_id'],'--verify');assert recheck.returncode==0 and json.loads(recheck.stdout)['result']=='verified_rehearsal_objects'
        passed('Replay refuses and fresh-process explicit recheck independently verifies backup and restored objects')
        with sqlite3.connect(db)as c:history_before=c.execute('SELECT * FROM evidence_restore_runs').fetchall(),c.execute('SELECT * FROM evidence_restore_events').fetchall()
        restored.write_bytes(b'Changed fictional restored bytes');recheck=artisan('gta:evidence-restore',run['run_id'],'--verify');assert recheck.returncode==1 and json.loads(recheck.stdout)['result']=='needs_checks'
        with sqlite3.connect(db)as c:assert history_before==(c.execute('SELECT * FROM evidence_restore_runs').fetchall(),c.execute('SELECT * FROM evidence_restore_events').fetchall())
        passed('Read-only recheck detects restored-byte drift without overwriting the recorded historical result')
        second=json.loads(artisan('gta:evidence-restore-plan',plan['plan_id'],'--target=restore_target','--record','--expected-hash='+proposal['proposal_hash'],'--reference=Second fictional rehearsal').stdout)
        with sqlite3.connect(db)as c:second_manifest=json.loads(c.execute('SELECT manifest FROM evidence_restore_runs WHERE id=?',(second['run_id'],)).fetchone()[0])
        collision=storage/'restore-target'/second_manifest['items'][0]['destination']['path'];collision.parent.mkdir(parents=True,exist_ok=True);collision.write_bytes(b'Existing protected destination')
        result=artisan('gta:evidence-restore',second['run_id'],'--restore','--expected-version=1','--expected-hash='+second['manifest_hash']);assert result.returncode==1 and json.loads(result.stdout)['state']=='Needs checks' and collision.read_bytes()==b'Existing protected destination' and backup.read_bytes()==expected_bytes
        passed('A pre-existing destination is retained and never adopted, overwritten or deleted')
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM evidence_restore_runs').fetchone()[0]==2
        with sqlite3.connect(db)as c:rollback_count=c.execute("SELECT COUNT(*) FROM migrations WHERE id >= (SELECT id FROM migrations WHERE migration=?)",('2026_11_14_020000_create_evidence_restore_runs',)).fetchone()[0]
        assert rollback_count>0 and artisan('migrate:rollback','--step='+str(rollback_count),'--force').returncode==0 and snapshot()==before and backup.read_bytes()==expected_bytes
        assert artisan('migrate','--force').returncode==0
        passed('Restore and any later fixture migrations roll back/reapply on the disposable fixture while source records and retained backup bytes remain unchanged')
        output={'passed':len(checks),'failed':0,'checks':checks,'engine':'Disposable SQLite and real local private filesystem across separate PHP/Artisan processes','applicationLinksChanged':False,'sourceRecordsChanged':False,'originalLossSimulatedInFixtureOnly':True,'cutoverApproved':False,'actualCloudAccess':False,'actualNativeEngine':False,'remoteStorageVerified':False,'limits':['Not a database/application backup or live restore/cutover','Native engine/concurrency, remote bucket/privacy/provider, browser and actual hosted acceptance remain separate']};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0,'cutoverApproved':False}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks,'cutoverApproved':False},indent=2)+'\n');raise
