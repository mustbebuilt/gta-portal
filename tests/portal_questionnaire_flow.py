"""Fictional Pack25 native questionnaire workflows over a local TLS proxy; never accesses Laravel Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-apprentice-experience-') as temporary:
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
        seed=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$users=[];foreach(['admin@example.test'=>'admin','checker@example.test'=>'ceo','tutor@example.test'=>'tutor','learner@example.test'=>'apprentice','other@example.test'=>'apprentice','inclusion@example.test'=>'inclusion_member']as$email=>$role){$users[$email]=App\Models\User::factory()->create(['email'=>$email,'role'=>$role,'password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);}$a=App\Models\Apprentice::factory()->create(['reference'=>'PQ-FICTIONAL','user_id'=>$users['learner@example.test']->id,'tutor_id'=>$users['tutor@example.test']->id]);$b=App\Models\Apprentice::factory()->create(['user_id'=>$users['other@example.test']->id]);echo json_encode(['apprentice'=>$a->id,'other'=>$b->id,'users'=>array_map(fn($u)=>$u->id,$users)]);"
        seeded=subprocess.run([args.php,'-r',seed],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert seeded.returncode==0,'Fictional questionnaire setup failed';ids=json.loads(seeded.stdout)
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            assert req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password})[0]==200
            return c
        def post(c,source,path,values):
            status,text,_,_=req(c,source);assert status==200,('Source page failed',source,status)
            return req(c,path,{'_token':csrf(text),**values})
        admin=login('admin@example.test');checker=login('checker@example.test');tutor=login('tutor@example.test');learner=login('learner@example.test');inclusion=login('inclusion@example.test');other=login('other@example.test')
        status,text,url,headers=req(learner,'/portal-questionnaires');assert status==200 and 'My portal questionnaires'in text and 'no-store'in headers['Cache-Control'] and headers['Referrer-Policy']=='no-referrer'
        passed('Actual native questionnaire worklist renders under private no-store and no-referrer policies')
        assert req(client(),'/portal-questionnaires')[2].endswith('/login') and req(tutor,'/portal-questionnaires')[0]==403
        passed('Guest must sign in; ordinary tutor has no Inclusion questionnaire access')
        assert req(admin,'/portal-questionnaires/forms',{'title':'NO CSRF'})[0]==419
        passed('Real POST without CSRF cannot create a form')
        form_data={'kind':'induction','title':'Fictional questionnaire v1','introduction':'Fictional provider introduction','privacy_notice':'Fictional privacy wording; approve real wording before use.','questions[0][key]':'q1','questions[0][label]':'Fictional training support question','questions[0][type]':'text','questions[0][required]':1,'questions[0][options_text]':'','questions[1][key]':'q2','questions[1][label]':'Fictional contact request','questions[1][type]':'choice','questions[1][options_text]':'Yes\nNo'}
        # Supply actual newline-separated options, not literal backslash characters.
        form_data['questions[1][options_text]']='Yes'+chr(10)+'No'
        status,text,url,_=post(admin,'/portal-questionnaires/forms','/portal-questionnaires/forms',form_data);assert status==200 and 'Draft saved'in text
        with sqlite3.connect(db)as c:form=c.execute('SELECT id,version,state FROM portal_questionnaire_forms').fetchone()
        assert form[1:]==(1,'Draft');form_path='/portal-questionnaires/forms/'+form[0]
        status,text,_,_=post(admin,form_path,form_path+'/state',{'version':1,'action':'publish'});assert status==200 and 'different administrator'in text
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM portal_questionnaire_forms').fetchone()[0]=='Draft'
        status,text,_,_=post(checker,form_path,form_path+'/state',{'version':1,'action':'publish'});assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT state,version FROM portal_questionnaire_forms').fetchone()==('Published',2)
        passed('Actual author form creates draft; self-publication fails; second authorised manager publishes frozen revision')
        assignment_data={'form_id':form[0],'version':2,'cycle_code':'fictional_induction','apprentice_ids[0]':ids['apprentice'],'due_date':time.strftime('%Y-%m-%d')}
        status,text,_,_=post(inclusion,'/portal-questionnaires/assign','/portal-questionnaires/assign',assignment_data);assert status==200 and '1 assignment(s) created'in text
        status,text,_,_=post(inclusion,'/portal-questionnaires/assign','/portal-questionnaires/assign',assignment_data);assert status==200 and '0 assignment(s) created'in text
        with sqlite3.connect(db)as c:row=c.execute('SELECT id,state,version FROM portal_questionnaire_assignments').fetchone();assert c.execute('SELECT COUNT(*) FROM portal_questionnaire_assignments').fetchone()[0]==1
        path='/portal-questionnaires/'+row[0]
        passed('Scoped Inclusion form assigns approved revision once; repeated same series/cycle does not duplicate')
        assert req(other,path)[0]==403 and req(tutor,path)[0]==403 and 'Fictional questionnaire v1'not in req(other,'/portal-questionnaires?filter=all')[1]
        assert req(learner,path,{'version':1,'action':'save','answers[q1]':'NO CSRF'})[0]==419
        passed('Other learner and ordinary tutor cannot access response; response writes enforce CSRF')
        status,text,_,_=post(learner,path,path,{'version':1,'action':'save','answers[q1]':'PRIVATE-DRAFT-HTTP','answers[q2]':''});assert status==200 and 'Draft saved'in text
        with sqlite3.connect(db)as c:state,v,draft=c.execute('SELECT state,version,draft_answers FROM portal_questionnaire_assignments').fetchone();assert state=='In progress'and v==2 and 'PRIVATE-DRAFT-HTTP'not in draft
        assert 'PRIVATE-DRAFT-HTTP'not in req(inclusion,path)[1] and 'PRIVATE-DRAFT-HTTP'in req(learner,path)[1]
        passed('Real draft save encrypts unfinished answers and hides them from Inclusion staff')
        status,text,_,_=post(learner,path,path,{'version':2,'action':'submit','answers[q1]':'PRIVATE-SUBMITTED-HTTP','answers[q2]':'Yes','confirm':1});assert status==200 and 'Submitted. Your answers'in text
        with sqlite3.connect(db)as c:assert c.execute('SELECT state,version,submission_count FROM portal_questionnaire_assignments').fetchone()==('Submitted',3,1);payload=c.execute('SELECT answer_payload FROM portal_questionnaire_submissions').fetchone()[0];assert 'PRIVATE-SUBMITTED-HTTP'not in payload
        assert 'PRIVATE-SUBMITTED-HTTP'in req(inclusion,path)[1]
        passed('Explicit real submission freezes encrypted response and makes it readable only to current authorised Inclusion scope and own learner')
        status,text,_,_=post(learner,path,path,{'version':2,'action':'submit','answers[q1]':'REPLAY','confirm':1});assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM portal_questionnaire_submissions').fetchone()[0]==1
        passed('Stale submit replay does not create another response or overwrite original answers')
        status,text,_,_=post(inclusion,path,path+'/check',{'version':3,'action':'return','shared_note':'Please clarify the fictional answer.','private_note':'PRIVATE-RETURN-HTTP'});assert status==200
        text=req(learner,path)[1];assert 'Please clarify the fictional answer.'in text and 'PRIVATE-RETURN-HTTP'not in text
        status,text,_,_=post(learner,path,path,{'version':4,'action':'submit','answers[q1]':'SECOND-HTTP-RESPONSE','answers[q2]':'No','confirm':1});assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT state,version,submission_count FROM portal_questionnaire_assignments').fetchone()==('Submitted',5,2);assert c.execute('SELECT COUNT(*) FROM portal_questionnaire_submissions').fetchone()[0]==2
        passed('Return message is apprentice-visible; private note stays private; resubmission preserves earlier submitted revision')
        status,text,_,_=post(inclusion,path,path+'/check',{'version':5,'action':'check','followup':'Required','private_note':'PRIVATE-CHECK-HTTP','shared_note':'We received your fictional response.'});assert status==200
        text=req(learner,path)[1];assert 'We received your fictional response.'in text and 'PRIVATE-CHECK-HTTP'not in text and 'Private Inclusion check'not in text
        with sqlite3.connect(db)as c:assert c.execute('SELECT state,version FROM portal_questionnaire_assignments').fetchone()==('Checked',6);assert c.execute('SELECT COUNT(*) FROM survey_receipts').fetchone()[0]==0;assert c.execute('SELECT COUNT(*) FROM support_concerns').fetchone()[0]==0
        passed('Independent Inclusion check records follow-up separately from external receipt records and never creates/closes support cases automatically')
        status,csv,_,headers=req(inclusion,'/portal-questionnaires/status.csv?filter=followup');assert status==200 and row[0]in csv and 'PRIVATE-'not in csv and 'SECOND-HTTP-RESPONSE'not in csv and 'We received'not in csv
        assert req(learner,'/portal-questionnaires/status.csv')[0]==403 and req(learner,'/portal-questionnaires?filter=followup')[0]==403
        passed('Actual status CSV contains assignment metadata only; answers and notes excluded; apprentice cannot export or inspect private follow-up filter')
        status,text,_,_=post(inclusion,path,path+'/followup',{'version':6,'action':'resolve','note':'PRIVATE-RESOLUTION-HTTP'});assert status==200
        assert row[0]not in req(inclusion,'/portal-questionnaires/status.csv?filter=followup')[1]
        assert 'PRIVATE-RESOLUTION-HTTP'not in req(learner,path)[1]
        status,text,_,_=post(inclusion,path,path+'/followup',{'version':7,'action':'reopen','note':'PRIVATE-REOPEN-HTTP'});assert status==200
        assert row[0]in req(inclusion,'/portal-questionnaires/status.csv?filter=followup')[1]
        passed('Actual follow-up resolve/reopen preserves private outcome history and updates pending filter without changing submitted answers')
        assert '0 open questionnaire(s)'in req(learner,'/my-next-step')[1]
        passed('Apprentice homepage links questionnaire work and reflects submitted completion without changing training tasks')
        stop_backend();start_backend();assert 'SECOND-HTTP-RESPONSE'in req(learner,path)[1] and 'PRIVATE-CHECK-HTTP'in req(inclusion,path)[1]
        passed('Encrypted submissions, checks and database sessions persist across backend restart')
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('apprentice_intake_batches','apprentice_intake_events','apprentice_setup_cases','apprentice_setup_case_events')").fetchone()[0]==0
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('apprentice_alert_deliveries','apprentice_alert_controls')").fetchone()[0]==2
        assert artisan('migrate','--force').returncode==0
        passed('Disposable latest rollback/reapply removes only Pack32 acceptance tables and retains Pack24 reminder tables; real rollback needs data backup')
        configure('production');start_backend();assert req(learner,'/portal-questionnaires')[0]==503 and req(inclusion,'/portal-questionnaires/forms')[0]==503
        passed('Existing production gate remains enforced on all new questionnaire screens')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Local client-verified HTTPS, cached staging configuration and disposable SQLite database sessions/cache','realDataUsed':False,'externalMessagesSent':False,'actualCloudAccess':False,'actualNativeEngine':False,'actualBrowserOrPhoneTested':False};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps(output))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
