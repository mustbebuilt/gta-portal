"""Fictional curriculum source workflow over a local TLS proxy; never accesses Laravel Cloud.
Uses disposable SQLite with database sessions/cache. Does not certify MySQL or S3.
"""
from pathlib import Path
import argparse, base64, http.client, http.cookiejar, http.server, json, os, re, secrets, signal, socket, sqlite3, ssl, subprocess, tempfile, threading, time
from urllib.request import Request, HTTPSHandler, HTTPCookieProcessor, build_opener
from urllib.parse import urlencode
from urllib.error import HTTPError

parser=argparse.ArgumentParser()
parser.add_argument('--php',default='php');parser.add_argument('--output',required=True)
args=parser.parse_args();project=Path(__file__).resolve().parent.parent
checks=[];server=None;proxy=None

def passed(name):checks.append({'name':name,'passed':True})
def free_port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]

with tempfile.TemporaryDirectory(prefix='gta-curriculum-source-') as temporary:
    temp=Path(temporary);db=temp/'fictional.sqlite';db.touch();storage=temp/'storage'
    for part in ['app/private','framework/sessions','framework/cache/data','framework/cache/locks','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
    php_port=free_port();tls_port=free_port();origin=f'https://127.0.0.1:{tls_port}'
    private_password='Fictional-private-'+secrets.token_urlsafe(24)
    env=os.environ.copy();env.update(APP_ENV='staging',APP_DEBUG='false',APP_URL=origin,APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),DB_CONNECTION='sqlite',DB_DATABASE=str(db),SESSION_DRIVER='database',SESSION_ENCRYPT='true',SESSION_SECURE_COOKIE='true',CACHE_STORE='database',MAIL_MAILER='array',QUEUE_CONNECTION='sync',GTA_ENABLE_STAGING_PORTAL='true',GTA_ALLOW_INITIAL_ADMIN='true',GTA_SCHEDULE_PORTAL_REMINDERS='false',LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(temp/'config.php'),APP_ROUTES_CACHE=str(temp/'routes.php'))
    env['GTA_SOURCE_TEST_PASSWORD']=private_password
    def artisan(*command,input_value=None):
        return subprocess.run([args.php,'artisan',*command,'--no-interaction'],cwd=project,env=env,input=input_value,capture_output=True,text=True,timeout=60)
    def configure(mode='staging',enabled=True):
        config_env=env.copy();config_env['APP_ENV']=mode;config_env['GTA_ENABLE_STAGING_PORTAL']='true' if enabled else 'false'
        # A private test-only config snapshot trusts only this loopback TLS proxy.
        # No proxy trust or resource configuration is added to the application source.
        script="require 'vendor/autoload.php';$a=require 'bootstrap/app.php';$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$c=$a['config']->all();$c['trustedproxy']['proxies']=['127.0.0.1'];$c['evidence']['disk']='local';$c['evidence']['legacy_disk']='local';$c['evidence']['private_disks']=['local'];file_put_contents(getenv('APP_CONFIG_CACHE'), '<?php return '.var_export($c,true).';');"
        cache=Path(env['APP_CONFIG_CACHE']);cache.unlink(missing_ok=True)
        result=subprocess.run([args.php,'-r',script],cwd=project,env=config_env,capture_output=True,text=True,timeout=30)
        assert result.returncode==0,'Private fixture configuration failed'
    def start_backend():
        global server
        server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{php_port}','-t','public'],cwd=project,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        for _ in range(100):
            try:
                conn=http.client.HTTPConnection('127.0.0.1',php_port,timeout=1);conn.request('GET','/up');r=conn.getresponse();r.read();conn.close()
                if r.status==200:return
            except (OSError,http.client.HTTPException):pass
            time.sleep(.05)
        raise AssertionError('Local backend did not start')
    def stop_backend():
        global server
        if server:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10);server=None
    class Proxy(http.server.BaseHTTPRequestHandler):
        def log_message(self,*args):pass
        def forward(self):
            body=self.rfile.read(int(self.headers.get('Content-Length','0')))
            excluded={'host','connection','x-forwarded-for','x-forwarded-proto','x-forwarded-host','x-forwarded-port','forwarded'}
            headers={k:v for k,v in self.headers.items() if k.lower() not in excluded}
            headers.update({'Host':f'127.0.0.1:{tls_port}','X-Forwarded-Proto':'https','X-Forwarded-Port':str(tls_port),'X-Forwarded-For':'127.0.0.1'})
            connection=http.client.HTTPConnection('127.0.0.1',php_port,timeout=30)
            try:
                connection.request(self.command,self.path,body=body,headers=headers);response=connection.getresponse();payload=response.read();self.send_response(response.status)
                for k,v in response.getheaders():
                    if k.lower() not in ['connection','transfer-encoding','content-length']:self.send_header(k,v)
                self.send_header('Content-Length',str(len(payload)));self.end_headers();self.wfile.write(payload)
            finally:connection.close()
        do_GET=forward;do_POST=forward
    def req(client,path,data=None):
        try:
            with client.open(Request(origin+path,data=None if data is None else urlencode(data).encode()),timeout=30) as response:return response.status,response.read().decode(),response.url,response.headers
        except HTTPError as error:return error.code,error.read().decode(),error.url,error.headers
    def csrf(text):
        match=re.search(r'name="_token"\s+value="([^"]+)"',text);assert match,'No CSRF token on real form';return match[1]
    try:
        configure();assert artisan('migrate','--force').returncode==0,'Disposable migration failed'
        seed=r"require 'vendor/autoload.php';$a=require 'bootstrap/app.php';$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$users=[];foreach(['admin@example.test'=>'admin','reviewer@example.test'=>'ceo','tutor@example.test'=>'tutor','outside@example.test'=>'tutor','learner@example.test'=>'apprentice']as$email=>$role){$users[$email]=App\Models\User::factory()->create(['email'=>$email,'role'=>$role,'password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);}$p=App\Models\Programme::factory()->create(['standard_code'=>'ST0448','version'=>'1.2']);$learner=App\Models\Apprentice::factory()->create(['programme_id'=>$p->id,'tutor_id'=>$users['tutor@example.test']->id,'start_date'=>today()->subMonths(6)]);$learner->user_id=$users['learner@example.test']->id;$learner->save();foreach(App\Support\TaskCatalogue::tasks('paint')as$t){$row=App\Models\LearningTask::create(['apprentice_id'=>$learner->id,'reference'=>$t['id'],'block'=>$t['block'],'title'=>$t['title'],'theme'=>$t['theme'],'template'=>App\Support\TaskCatalogue::template($t['id'],'paint')]);if($t['id']==='paint-b1-t1'){$row->answers=['private'=>'PRIVATE-APPRENTICE-ANSWER'];$row->save();}}echo json_encode(['apprentice'=>$learner->id]);"
        seeded=subprocess.run([args.php,'-r',seed],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert seeded.returncode==0,'Fictional source setup failed';apprentice=json.loads(seeded.stdout)['apprentice'];path=f'/apprentices/{apprentice}/source-comparison'
        checked=artisan('gta:curriculum-source-check');report=json.loads(checked.stdout);assert checked.returncode==0 and len(report['releases'])==4 and all(r['task_count']==50 and r['pdfs_checked']==50 for r in report['releases']) and not report['curriculum_approval']
        with sqlite3.connect(db)as c:before=c.execute('SELECT * FROM learning_tasks ORDER BY id').fetchall();assert c.execute('SELECT COUNT(*) FROM source_reconciliations').fetchone()[0]==0
        passed('Actual read-only command checks all four 50-task catalogues and 200 blank PDF hashes without creating source decisions')
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            assert req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password})[0]==200
            return c
        admin=login('admin@example.test');reviewer=login('reviewer@example.test');tutor=login('tutor@example.test');outside=login('outside@example.test');learner=login('learner@example.test')
        status,text,_,_=req(admin,'/curriculum-source-checks');assert status==200 and 'Controlled Paint SOW version'in text and 'Existing Autocare KSB reconciliation'in text
        passed('Administrator HTTPS source overview keeps the unresolved Paint and Autocare notices visible')
        status,text,_,_=req(tutor,path);assert status==200 and 'Matches current task source'in text and 'PRIVATE-APPRENTICE-ANSWER'not in text
        assert req(outside,path)[0]==403 and req(learner,path)[0]==403 and req(tutor,'/curriculum-source-checks')[0]==403
        passed('Assigned tutor can compare their cohort while unrelated tutor, apprentice and global-overview scopes are refused')
        status,text,_,_=req(admin,path);binding=re.search(r'name="binding" value="([a-f0-9]{64})"',text)[1]
        values={'binding':binding,'decision':'retain','source_reference':'Fictional controlled source reference v1','scope_note':'Fictional Paint cohort review; actual applicability unresolved','reason':'Fictional retained-source assessment with follow-up required','confirmed':'1'}
        assert req(admin,path,values)[0]==419
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM source_reconciliations').fetchone()[0]==0
        passed('Actual assessment submission refuses missing CSRF before writing any decision')
        status,text,target,_=req(admin,path,{**values,'_token':csrf(text)});assert status==200 and 'Recorded staff assessment'in text and 'PRIVATE-APPRENTICE-ANSWER'not in text
        record=int(target.rsplit('/',1)[1]);record_path=path+'/'+str(record)
        with sqlite3.connect(db)as c:
            snapshot=json.loads(c.execute('SELECT snapshot FROM source_reconciliations WHERE id=?',(record,)).fetchone()[0]);assert len(snapshot['items'])==50 and 'PRIVATE-APPRENTICE-ANSWER'not in json.dumps(snapshot)
        passed('Submission saves an immutable 50-task source snapshot with no apprentice answers or marking copied')
        assert req(admin,record_path,{'_token':csrf(text),'version':'1','action':'record','note':'Self-review must fail','confirmed':'1'})[0]==403
        status,text,_,_=req(reviewer,record_path);status,text,_,_=req(reviewer,record_path,{'_token':csrf(text),'version':'0','action':'record','note':'Stale version must fail','confirmed':'1'});assert status==200 and 'record changed'in text
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM source_reconciliations WHERE id=?',(record,)).fetchone()[0]=='Submitted'
        passed('Self-review and stale-version independent review cannot change the submitted assessment')
        status,text,_,_=req(reviewer,record_path);status,text,_,_=req(reviewer,record_path,{'_token':csrf(text),'version':'1','action':'record','note':'Fictional independent review; no curriculum or funding approval','confirmed':'1'});assert status==200 and 'Review recorded'in text and 'Controlled Paint SOW version'in text
        with sqlite3.connect(db)as c:assert c.execute('SELECT * FROM learning_tasks ORDER BY id').fetchall()==before and c.execute('SELECT COUNT(*) FROM source_reconciliation_events').fetchone()[0]==2
        passed('Independent review persists exact-source history while preserving all saved task records and the unresolved source notice')
        with sqlite3.connect(db)as c:c.execute("UPDATE learning_tasks SET answers=?, status='Approved', version=version+1 WHERE reference='paint-b1-t1'",(json.dumps({'private':'NEW-PRIVATE-ANSWER'}),));c.commit()
        status,text,_,_=req(reviewer,record_path);assert status==200 and 'Matches current source position'in text and 'NEW-PRIVATE-ANSWER'not in text
        passed('Answer/marking changes do not invalidate the source-only binding or leak into the assessment page')
        with sqlite3.connect(db)as c:c.execute("UPDATE learning_tasks SET title=title || ' changed source title' WHERE reference='paint-b1-t1'");c.commit();after_fixture=c.execute('SELECT * FROM learning_tasks ORDER BY id').fetchall()
        status,text,_,_=req(reviewer,record_path);assert status==200 and 'Source position changed or needs checking'in text
        status,text,_,_=req(reviewer,record_path,{'_token':csrf(text),'version':'2','action':'withdraw','note':'Source changed; record withdrawn for follow-up','confirmed':'1'});assert status==200 and 'Withdrawn'in text
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM source_reconciliation_events').fetchone()[0]==3 and c.execute('SELECT * FROM learning_tasks ORDER BY id').fetchall()==after_fixture
        passed('Changed task-source content stales the snapshot and withdrawal retains history without rewriting any tasks')
        stop_backend();start_backend();status,text,_,_=req(reviewer,record_path);assert status==200 and 'Withdrawn'in text and 'Source changed; record withdrawn for follow-up'in text
        passed('Database sessions and reviewed/withdrawn source history survive a PHP restart')
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:assert c.execute('SELECT * FROM learning_tasks ORDER BY id').fetchall()==after_fixture and c.execute('SELECT COUNT(*) FROM users').fetchone()[0]==5 and c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('source_reconciliations','source_reconciliation_events')").fetchone()[0]==0
        assert artisan('migrate','--force').returncode==0
        passed('New source-review migration rolls back/reapplies on disposable SQLite without changing task or account records')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Real local client-verified HTTPS with separate PHP/Artisan processes and disposable SQLite sessions/cache','realDataUsed':False,'externalMessagesSent':False,'actualCloudAccess':False,'actualNativeEngine':False,'curriculumApproval':False,'limits':['Not a real browser/phone/accessibility or hosted acceptance run','No authoritative source/cohort/KSB/funding decisions made','Actual native DDL/locking/concurrency and real repository/source reconciliation remain separate']};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0,'curriculumApproval':False}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks,'curriculumApproval':False},indent=2)+'\n');raise
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
