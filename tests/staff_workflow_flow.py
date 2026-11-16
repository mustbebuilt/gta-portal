"""Fictional Pack22 staff workflow workflows over a local TLS proxy; never accesses Laravel Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-staff-workflow-') as temporary:
    temp=Path(temporary);db=temp/'fictional.sqlite';db.touch();storage=temp/'storage'
    for part in ['app/private','framework/sessions','framework/cache/data','framework/cache/locks','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
    php_port=free_port();tls_port=free_port();origin=f'https://127.0.0.1:{tls_port}'
    private_password='Fictional-private-'+secrets.token_urlsafe(24)
    env=os.environ.copy();env.update(APP_ENV='staging',APP_DEBUG='false',APP_URL=origin,APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),DB_CONNECTION='sqlite',DB_DATABASE=str(db),SESSION_DRIVER='database',SESSION_ENCRYPT='true',SESSION_SECURE_COOKIE='true',CACHE_STORE='database',MAIL_MAILER='array',QUEUE_CONNECTION='sync',GTA_ENABLE_STAGING_PORTAL='true',GTA_ALLOW_INITIAL_ADMIN='true',GTA_SCHEDULE_PORTAL_REMINDERS='false',LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(temp/'config.php'),APP_ROUTES_CACHE=str(temp/'routes.php'))
    env['GTA_SOURCE_TEST_PASSWORD']=private_password
    env.update(GTA_MFA_ENROLMENT_ENABLED='false',GTA_MFA_PILOT_EMAILS='')
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
        seed=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$users=[];foreach(['admin@example.test'=>'admin','tutor@example.test'=>'tutor','other@example.test'=>'tutor','learner@example.test'=>'apprentice','mentor@example.test'=>'mentor']as$email=>$role){$users[$email]=App\Models\User::factory()->create(['email'=>$email,'role'=>$role,'password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);}$a=App\Models\Apprentice::factory()->create(['reference'=>'WORKFLOW-FICTIONAL','first_name'=>'Fictional Workflow Learner','user_id'=>$users['learner@example.test']->id,'tutor_id'=>$users['tutor@example.test']->id,'mentor_id'=>$users['mentor@example.test']->id,'start_date'=>today()->subDays(100)]);App\Models\ProgrammeOutcome::factory()->create(['apprentice_id'=>$a->id,'context'=>['status'=>'Active','sourceRef'=>'Fictional status source']]);$task=App\Models\LearningTask::factory()->create(['apprentice_id'=>$a->id,'title'=>'Fictional overdue inspection task','due_date'=>today()->subDay()]);$approved=App\Models\LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>'b1-t4','status'=>'Approved']);$approved->submissions=[['decision'=>['date'=>today()->subDay()->toDateString(),'feedback'=>'Fictional shareable tutor feedback']]];$approved->save();$fields=['summary'=>'ORIGINAL SAVED SUMMARY','providerPrepared'=>true,'trainingChecked'=>true,'targetsAgreed'=>true];$review=App\Models\ProgressReviewDraft::factory()->create(['apprentice_id'=>$a->id,'author_id'=>$users['tutor@example.test']->id,'fields'=>$fields,'source_snapshot'=>App\Support\ReviewSnapshot::capture($a,$fields)]);echo json_encode(['apprentice'=>$a->id,'review'=>$review->id,'task'=>$task->id,'users'=>array_map(fn($u)=>$u->id,$users)]);"
        seeded=subprocess.run([args.php,'-r',seed],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert seeded.returncode==0,'Fictional staff workflow setup failed';ids=json.loads(seeded.stdout)
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            assert req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password})[0]==200
            return c
        def post(c,source,path,values):
            status,text,_,_=req(c,source);assert status==200
            return req(c,path,{'_token':csrf(text),**values})
        admin=login('admin@example.test');tutor=login('tutor@example.test');learner=login('learner@example.test');mentor=login('mentor@example.test');other=login('other@example.test')
        root=f'/apprentices/{ids["apprentice"]}/reviews/{ids["review"]}';prepare=root+'/preparation'
        status,text,_,headers=req(tutor,'/needs-attention');assert status==200 and 'WORKFLOW-FICTIONAL'in text and 'Task due: b1-t3'in text and 'no-store'in headers['Cache-Control']
        assert 'WORKFLOW-FICTIONAL'not in req(other,'/needs-attention')[1]
        passed('Actual HTTPS attention page shows scoped own task/review reasons and private cache policy, with no unrelated tutor identity leak')
        assert req(learner,'/needs-attention')[0]==403 and req(learner,prepare)[0]==403
        passed('Apprentice account cannot access staff attention or preparation source records')
        status,text,_,_=req(mentor,'/needs-attention');assert status==200 and 'Completed-review history needs checking'in text and 'Task due: b1-t3'not in text
        passed('Assigned review mentor gets review follow-up without main-training task links/reasons')
        status,text,_,_=req(tutor,'/needs-attention?reason=task&view=overdue');assert status==200 and 'Task due: b1-t3'in text and 'Completed-review history needs checking'not in text
        status,text,_,_=req(tutor,'/needs-attention?q=NO-SUCH-PERSON');assert status==200 and '0 apprentices match'in text
        passed('Rendered reason/priority/name filters retain scope and show only matching reasons')
        assert req(tutor,prepare,{'version':1,'period_from':'2026-10-01','period_to':'2026-11-14'})[0]==419
        passed('Preparation mutation without CSRF is rejected')
        status,text,_,_=req(tutor,root);assert status==200 and 'Prepare factual review notes'in text
        status,text,url,_=post(tutor,prepare,prepare,{'version':1,'period_from':'2026-10-01','period_to':'2026-11-14'});assert status==200 and 'Fictional shareable tutor feedback'in text and 'ORIGINAL SAVED SUMMARY'in text
        with sqlite3.connect(db)as c:
            brief=c.execute('SELECT id,source_hash,applied_at FROM review_preparation_briefs').fetchone();assert brief and len(brief[1])==64 and brief[2]is None;fields,version=c.execute('SELECT fields,version FROM progress_review_drafts WHERE id=?',(ids['review'],)).fetchone();assert json.loads(fields)['summary']=='ORIGINAL SAVED SUMMARY' and version==1
        brief_path=prepare+'/'+brief[0]
        passed('Real generate form stores source-bound editable factual notes while preserving the saved review exactly')
        assert req(other,brief_path)[0]==403 and req(learner,brief_path)[0]==403 and req(mentor,brief_path)[0]==200
        passed('Brief source page enforces current review authority for tutor/mentor and denies unrelated/apprentice access')
        common={'version':1,'selected[]':'summary','text[summary]':'Checked factual notes from HTTPS fixture','confirmed':'1'}
        status,_,_,_=post(tutor,brief_path,brief_path,common);assert status==200
        with sqlite3.connect(db)as c:assert json.loads(c.execute('SELECT fields FROM progress_review_drafts WHERE id=?',(ids['review'],)).fetchone()[0])['summary']=='ORIGINAL SAVED SUMMARY' and c.execute('SELECT applied_at FROM review_preparation_briefs WHERE id=?',(brief[0],)).fetchone()[0]is None
        passed('Existing review text cannot be replaced without the separate explicit replacement confirmation')
        status,text,_,_=post(tutor,brief_path,brief_path,{**common,'replace_existing':'1'});assert status==200 and 'Checked factual notes from HTTPS fixture'in text
        with sqlite3.connect(db)as c:
            fields,version,state,history=c.execute('SELECT fields,version,state,history FROM progress_review_drafts WHERE id=?',(ids['review'],)).fetchone();fields=json.loads(fields);assert version==2 and state=='Draft' and fields['summary']=='Checked factual notes from HTTPS fixture' and not fields['providerPrepared'] and not fields['trainingChecked'] and not fields['targetsAgreed'];assert json.loads(history)[0]['fields']['summary']=='ORIGINAL SAVED SUMMARY';assert c.execute('SELECT applied_review_version FROM review_preparation_briefs WHERE id=?',(brief[0],)).fetchone()[0]==2
        passed('Checked edited notes enter only selected draft section, preserve earlier text/history and clear manual preparation confirmations without signing')
        status,_,_,_=post(tutor,root,brief_path,{**common,'version':2,'replace_existing':1});assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT version FROM progress_review_drafts WHERE id=?',(ids['review'],)).fetchone()[0]==2
        passed('Used preparation capture cannot be replayed to create another review revision')
        status,text,new_url,_=post(tutor,prepare,prepare,{'version':2,'period_from':'2026-10-01','period_to':'2026-11-14'});assert status==200
        with sqlite3.connect(db)as c:
            stale=c.execute('SELECT id FROM review_preparation_briefs WHERE applied_at IS NULL').fetchone()[0];c.execute('UPDATE learning_tasks SET due_date=? WHERE id=?',('2026-11-01',ids['task']))
        stale_path=prepare+'/'+stale;status,text,_,_=req(tutor,stale_path);assert status==200 and 'Generate fresh notes'in text
        status,_,_,_=post(tutor,stale_path,stale_path,{**common,'version':2,'replace_existing':1});assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT version FROM progress_review_drafts WHERE id=?',(ids['review'],)).fetchone()[0]==2 and c.execute('SELECT applied_at FROM review_preparation_briefs WHERE id=?',(stale,)).fetchone()[0]is None
        passed('Source deadline change is detected on read and blocks stale-note insertion even when review version is unchanged')
        stop_backend();start_backend();assert 'Checked factual notes from HTTPS fixture'in req(tutor,root)[1] and 'WORKFLOW-FICTIONAL'in req(tutor,'/needs-attention')[1]
        passed('Saved review notes, staff scope and encrypted database sessions persist after backend restart')
        with sqlite3.connect(db)as c:c.execute('UPDATE apprentices SET tutor_id=NULL WHERE id=?',(ids['apprentice'],))
        assert req(tutor,stale_path)[0]==403 and 'WORKFLOW-FICTIONAL'not in req(tutor,'/needs-attention')[1]
        passed('Current tutor reassignment removes brief access and apprentice attention identity on the next request')
        with sqlite3.connect(db) as c: before_tables={row[0] for row in c.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='account_lifecycle_states'").fetchone()[0]==1
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('apprentice_experience_entries','apprentice_mobile_challenges')").fetchone()[0]==2
            assert 'description'in [r[1]for r in c.execute('PRAGMA table_info(task_evidence)')]
            assert c.execute('SELECT version FROM progress_review_drafts WHERE id=?',(ids['review'],)).fetchone()[0]==2
        with sqlite3.connect(db) as c:
            after_tables={row[0] for row in c.execute("SELECT name FROM sqlite_master WHERE type='table'")}
            assert before_tables-after_tables=={'timetable_optimisation_runs'} and not(after_tables-before_tables)
        assert artisan('migrate','--force').returncode==0
        passed('Latest Pack47 down/up removes only the timetable optimisation run table while all prior schema and business-data checks are retained')
        configure('production');start_backend();assert req(admin,'/needs-attention')[0]==503 and req(mentor,prepare)[0]==503
        passed('Production gate remains enforced for new attention/preparation routes')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual local client-verified HTTPS, cached staging config, disposable SQLite and encrypted database sessions/cache','realDataUsed':False,'externalMessagesSent':False,'actualCloudAccess':False,'actualNativeEngine':False,'actualBrowserOrPhoneTested':False,'externalAIServiceUsed':False};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps(output))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
