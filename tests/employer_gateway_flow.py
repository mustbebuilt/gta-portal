"""Fictional Pack23 employer/Gateway workflow workflows over a local TLS proxy; never accesses Laravel Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-employer-gateway-') as temporary:
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
        seed=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$users=[];foreach(['admin@example.test'=>'admin','tutor@example.test'=>'tutor','other@example.test'=>'tutor','learner@example.test'=>'apprentice','employer@example.test'=>'employer','wrong-employer@example.test'=>'employer']as$email=>$role){$users[$email]=App\Models\User::factory()->create(['email'=>$email,'role'=>$role,'password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);}$e=App\Models\Employer::factory()->create();$p=App\Models\Programme::factory()->create(['standard_code'=>'ST0499','version'=>'1.3']);$a=App\Models\Apprentice::factory()->create(['reference'=>'EG-FICTIONAL','first_name'=>'Fictional Employer Gateway Learner','user_id'=>$users['learner@example.test']->id,'tutor_id'=>$users['tutor@example.test']->id,'employer_id'=>$e->id,'programme_id'=>$p->id,'start_date'=>today()->subDays(100)]);App\Models\ProgrammeOutcome::factory()->create(['apprentice_id'=>$a->id,'context'=>['status'=>'Active','sourceRef'=>'Fictional active status source']]);$target=App\Models\SmartAction::factory()->create(['apprentice_id'=>$a->id,'owner'=>'Employer / workplace mentor','support'=>'PRIVATE-TARGET-SUPPORT']);echo json_encode(['apprentice'=>$a->id,'target'=>$target->id,'users'=>array_map(fn($u)=>$u->id,$users)]);"
        seeded=subprocess.run([args.php,'-r',seed],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert seeded.returncode==0,'Fictional employer/Gateway setup failed';ids=json.loads(seeded.stdout)
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
        admin=login('admin@example.test');tutor=login('tutor@example.test');learner=login('learner@example.test');employer=login('employer@example.test');wrong=login('wrong-employer@example.test');other=login('other@example.test')
        base=f'/apprentices/{ids["apprentice"]}';grants=base+'/employer-access';staff=base+'/employer-actions';gateway=base+'/gateway-preparation'
        status,text,_,_=req(employer,'/employer-actions');assert status==200 and 'No current requests'in text
        assert req(employer,base)[0]==403 and req(tutor,grants)[0]==403
        passed('Named employer login initially sees no requests and gains no general apprentice access; main tutor cannot verify identity grants')
        status,_,_,_=post(admin,grants,grants,{'user_id':ids['users']['employer@example.test'],'version':0,'identity_reference':'Fictional identity/authority verified for this apprentice','confirmed':1});assert status==200
        with sqlite3.connect(db)as c:grant=c.execute('SELECT id,version,active FROM employer_apprentice_grants').fetchone();assert grant and grant[1:]==(1,1)
        passed('Real management POST verifies one named employer login for the linked employer and apprentice, with reference/history')
        import uuid
        token=str(uuid.uuid4());data={'request_token':token,'grant_id':grant[0],'source_choice':'target:'+str(ids['target']),'title':'Fictional shareable workplace request','detail':'SHARED FICTIONAL SUMMARY ONLY','confirmed':1}
        status,text,_,_=post(tutor,staff,staff,data);assert status==200 and 'Fictional shareable workplace request'in text
        with sqlite3.connect(db)as c:action=c.execute('SELECT id,state,version FROM employer_portal_actions').fetchone();assert action==(token,'Pending',1)
        status,_,_,_=post(tutor,staff,staff,data);assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM employer_portal_actions').fetchone()[0]==1
        passed('Staff publishes only checked public summary against a valid source; repeated token does not create duplicate request')
        path='/employer-actions/'+token;status,text,_,_=req(employer,path);assert status==200 and 'SHARED FICTIONAL SUMMARY ONLY'in text and 'PRIVATE-TARGET-SUPPORT'not in text
        assert req(wrong,path)[0]==403 and 'Fictional shareable workplace request'not in req(wrong,'/employer-actions')[1]
        passed('Correct employer sees exact shareable snapshot; other employer is denied direct link and request-list title')
        assert req(employer,path,{'version':1,'response':'Confirmed','note':'No CSRF','confirmed':1})[0]==419
        passed('Employer reply without actual session CSRF is refused')
        status,text,_,_=post(employer,path,path,{'version':1,'response':'Confirmed','note':'Fictional workplace response checked by employer','confirmed':1});assert status==200 and 'Your recorded response'in text
        with sqlite3.connect(db)as c:assert c.execute('SELECT state,version FROM employer_portal_actions').fetchone()==('Responded',2) and c.execute('SELECT status FROM smart_actions WHERE id=?',(ids['target'],)).fetchone()[0]=='To do'
        assert 'Employer reply awaiting staff check'in req(tutor,'/needs-attention?reason=employer')[1]
        passed('Employer response is stored once and appears in staff attention without completing the original target')
        status,_,_,_=post(tutor,staff,path+'/check',{'version':2,'state':'Checked','note':'Fictional staff source follow-up'});assert status==200
        assert 'Employer reply awaiting staff check'not in req(tutor,'/needs-attention?reason=employer')[1]
        passed('Staff reply check clears the attention reason while retaining original source workflow and response history')
        status,text,_,_=req(learner,gateway);assert status==200 and 'readiness not assessed'in text
        status,text,_,_=req(tutor,'/gateway-preparation');assert status==200 and 'EG-FICTIONAL'in text and 'not Gateway approvals'in text
        assert 'EG-FICTIONAL'not in req(other,'/gateway-preparation')[1] and req(employer,gateway)[0]==403
        passed('Gateway starts unassessed with no inferred criteria; staff worklist and apprentice page enforce separate current scope')
        criteria={'cohort':'Fictional2026 acceptance cohort','source_reference':'FICTIONAL TEST CRITERIA ONLY; not actual standard requirements','confirmed':1}
        for i,category in enumerate(['tasks','qualifications','evidence','approvals','ksbs','otj']):
            criteria[f'criteria[{i}][label]']='Fictional sourced '+category+' requirement';criteria[f'criteria[{i}][category]']=category;criteria[f'criteria[{i}][kind]']='manual'
        status,text,_,_=post(tutor,gateway,gateway,criteria);assert status==200 and 'Criteria draft saved'in text
        with sqlite3.connect(db)as c:plan=c.execute('SELECT id,state,version FROM gateway_readiness_plans').fetchone();assert plan and plan[1:]==('Draft',1)
        assert 'readiness not assessed'in req(learner,gateway)[1]
        passed('Provider criteria draft records all six categories, source/cohort and exact programme binding, but remains unassessed until independent approval')
        status,_,_,_=post(admin,gateway,f'/gateway-criteria/{plan[0]}/state',{'version':1,'state':'Approved','note':'Fictional independent source/applicability review','confirmed':1});assert status==200
        status,text,_,_=req(learner,gateway);assert status==200 and '0 of 6 configured checks met'in text and 'Fictional sourced tasks requirement'in text
        passed('Different management approver publishes criteria without approving learner readiness or marking any requirement met')
        for i in range(1,7):
            values={'plan_version':2,'version':0,'criterion_key':'c'+str(i),'state':'Met','evidence_reference':'Fictional shareable evidence reference '+str(i),'note':'Fictional checked acceptance evidence; not a final Gateway decision','checked_on':'2026-11-14','confirmed':1}
            status,_,_,_=post(tutor,gateway,f'/gateway-criteria/{plan[0]}/checks',values);assert status==200
        status,text,_,_=req(learner,gateway);assert status==200 and '6 of 6 configured checks met'in text and 'provider Gateway review still required'in text
        passed('Six explicit shareable staff checks show configured requirements met while final provider/EPAO Gateway decision stays separate')
        with learner.open(origin+gateway+'.pdf',timeout=30)as response:assert response.status==200 and response.headers['Content-Type']=='application/pdf' and response.read().startswith(b'%PDF-')
        assert req(employer,gateway+'.pdf')[0]==403
        passed('Authorised learner downloads private preparation PDF; employer cannot download it; this is not PDF visual acceptance')
        stop_backend();start_backend();assert '6 of 6 configured checks met'in req(learner,gateway)[1] and 'Your recorded response'in req(employer,path)[1]
        passed('Employer responses, configured readiness and encrypted database sessions persist across backend restart')
        status,_,_,_=post(admin,grants,f'/employer-access/{grant[0]}/revoke',{'version':1,'reason':'Fictional workplace authority withdrawn'});assert status==200
        assert req(employer,path)[0]==403 and 'Fictional shareable workplace request'not in req(employer,'/employer-actions')[1]
        status,text,_,_=req(learner,gateway);assert status==200 and '0 of 6 configured checks met'in text and 'manual check needs repeating'in text
        passed('Revoked employer authority removes direct/list access and invalidates existing manual readiness evidence bindings')
        with sqlite3.connect(db)as c:c.execute('UPDATE programme_outcomes SET context=? WHERE apprentice_id=?',(json.dumps({'status':'Withdrawn','sourceRef':'Fictional withdrawal'}),ids['apprentice']))
        assert 'readiness not assessed'in req(learner,gateway)[1]
        passed('Changed recorded programme status invalidates published readiness basis instead of preserving a ready claim')
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('timetable_planner_access','timetable_rooms','timetable_staff_windows','timetable_plans','timetable_plan_entries','timetable_planning_events')").fetchone()[0]==0
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('review_preparation_briefs','apprentice_experience_entries','apprentice_mobile_challenges')").fetchone()[0]==3
            assert c.execute('SELECT status FROM smart_actions WHERE id=?',(ids['target'],)).fetchone()[0]=='To do'
        assert artisan('migrate','--force').returncode==0
        passed('Disposable latest reminder migration round-trip preserves employer/Gateway and earlier review/apprentice/mobile schema and original target state; real used data requires backup')
        configure('production');start_backend();assert req(admin,'/employer-accounts')[0]==503 and req(employer,'/employer-actions')[0]==503 and req(learner,gateway)[0]==503
        passed('Existing production gate remains enforced on account, employer and Gateway routes')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual local client-verified HTTPS, cached staging config, disposable SQLite/encrypted database sessions/cache','realDataUsed':False,'externalMessagesSent':False,'actualCloudAccess':False,'actualNativeEngine':False,'actualBrowserOrPhoneTested':False,'actualGatewayCriteriaSupplied':False,'actualParticipantSignatures':False};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps(output))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
