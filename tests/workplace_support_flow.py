"""Fictional Pack29 workplace support sharing workflows over a local TLS proxy; never accesses Laravel Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-workplace-support-') as temporary:
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
        bootstrap=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();"
        def script(code):
            r=subprocess.run([args.php,'-r',bootstrap+code],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert r.returncode==0,'Private fixture setup failed';return json.loads(r.stdout or '{}')
        ids=script(r"$users=[];foreach(['admin@example.test'=>'admin','lead@example.test'=>'inclusion_lead','member@example.test'=>'inclusion_member','learner@example.test'=>'apprentice','employer@example.test'=>'employer','other@example.test'=>'employer','tutor@example.test'=>'tutor']as$email=>$role){$users[$email]=App\Models\User::factory()->create(['email'=>$email,'role'=>$role,'password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);}$e=App\Models\Employer::factory()->create();$a=App\Models\Apprentice::factory()->create(['user_id'=>$users['learner@example.test']->id,'email'=>'learner@example.test','tutor_id'=>$users['tutor@example.test']->id,'employer_id'=>$e->id]);$g=App\Support\EmployerActions::grant($users['admin@example.test'],1,$a,['user_id'=>$users['employer@example.test']->id,'version'=>0,'identity_reference'=>'PRIVATE-EMPLOYER-AUTHORITY','confirmed'=>1]);$p=new App\Models\SupportPlan(['apprentice_id'=>$a->id,'concern_ids'=>[],'draft'=>['fields'=>['support'=>'PRIVATE-DRAFT']]]);$p->status='Active';$p->active_plan=['fields'=>['support'=>'PRIVATE-CLINICAL-DETAIL','funding'=>'PRIVATE-FUNDING'],'codes'=>['IM36'],'approved_at'=>now()->toIso8601String(),'approved_by'=>$users['lead@example.test']->id,'reason'=>'PRIVATE-REASON'];$p->save();echo json_encode(['apprentice'=>$a->id,'plan'=>$p->id,'grant'=>$g->id]);")
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            assert req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password})[0]==200;return c
        def post(c,source,path,values):
            status,text,_,_=req(c,source);assert status==200
            return req(c,path,{'_token':csrf(text),**values})
        lead=login('lead@example.test');member=login('member@example.test');learner=login('learner@example.test');employer=login('employer@example.test');other=login('other@example.test');tutor=login('tutor@example.test');admin=login('admin@example.test')
        create=f'/apprentices/{ids["apprentice"]}/workplace-support'
        status,text,_,headers=req(member,create);assert status==200 and 'PRIVATE-CLINICAL'not in text and headers['Referrer-Policy']=='no-referrer'
        assert req(tutor,create)[0]==403 and req(client(),create)[2].endswith('/login')
        passed('Actual verified HTTPS writer form is scoped; ordinary assigned tutor and guest cannot prepare private summary')
        data={'request_token':'12345678-1234-4234-8234-123456789abc','plan_choice':str(ids['plan'])+':1','grant_choice':str(ids['grant'])+':1','title':'Fictional practical support','adjustments':'Fictional written instructions','employer_actions':'Fictional weekly workplace check','expires_on':'2099-01-01','confirmed':1}
        data['expires_on']=script("echo json_encode(today()->addDays(14)->toDateString());")
        assert req(member,create,data)[0]==419
        passed('Actual missing-CSRF POST cannot prepare a summary')
        status,text,url,_=post(member,create,create,data);assert status==200 and 'Private summary draft prepared'in text and 'PRIVATE-CLINICAL'not in text
        path=url[len(origin):];share_id=path.rsplit('/',1)[1]
        with sqlite3.connect(db)as c:
            row=c.execute('SELECT state,payload,source FROM workplace_support_shares WHERE id=?',(share_id,)).fetchone();assert row[0]=='Draft' and 'Fictional written'not in row[1] and 'learner@example.test'not in row[2]
        assert req(learner,path)[0]==404 and req(employer,path)[0]==404
        passed('Real POST prepares encrypted minimal draft; neither apprentice nor employer can read before lead check')
        assert post(member,path,path+'/check',{'version':1,'confirmed':1})[0]==403
        assert post(admin,path,path+'/check',{'version':1,'confirmed':1})[0]==403
        assert post(lead,path,path+'/check',{'version':1,'confirmed':1})[0]==200
        status,text,_,headers=req(learner,path);assert status==200 and 'employer@example.test'in text and 'Fictional written instructions'in text and 'You can say no'in text and 'PRIVATE-'not in text and 'IM36'not in text and 'no-store'in headers['Cache-Control']
        passed('Only Inclusion lead can request permission; apprentice sees exact minimal wording, named recipient and choice')
        assert req(employer,path)[0]==404 and share_id not in req(employer,'/workplace-support')[1]
        passed('Pending request is absent from employer direct route and queue')
        assert post(learner,path,path+'/decide',{'version':2,'decision':'decline'})[0]==200
        assert req(employer,path)[0]==404
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM workplace_support_shares WHERE id=?',(share_id,)).fetchone()[0]=='Declined'
        passed('Apprentice declines without explanation or automatic employer disclosure')
        data['request_token']='22345678-1234-4234-8234-123456789abc'
        status,_,url,_=post(member,create,create,data);assert status==200;path=url[len(origin):];share_id=path.rsplit('/',1)[1]
        post(lead,path,path+'/check',{'version':1,'confirmed':1})
        post(learner,path,path+'/decide',{'version':2,'decision':'allow'})
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM workplace_support_shares WHERE id=?',(share_id,)).fetchone()[0]=='Awaiting apprentice'
        post(learner,path,path+'/decide',{'version':2,'decision':'allow','confirmed':1})
        status,text,_,headers=req(employer,path);assert status==200 and 'Fictional written instructions'in text and 'PRIVATE-'not in text and 'IM36'not in text and 'Restricted staff history'not in text and '/support-plans/'not in text and headers['Referrer-Policy']=='no-referrer'
        assert req(other,path)[0]==404 and req(tutor,path)[0]==404
        passed('Explicit checked allow grants only the named employer current summary; other employer/tutor and private-plan links excluded')
        with sqlite3.connect(db)as c:
            raw=c.execute('SELECT permission FROM workplace_support_shares WHERE id=?',(share_id,)).fetchone()[0];assert 'allow'not in raw and 'Fictional'not in raw
        passed('Exact source/content/checker/recipient/expiry/wording permission proof is encrypted at rest')
        before=script(f"echo json_encode(App\\Models\\SupportPlan::findOrFail({ids['plan']})->getAttributes());")
        post(employer,path,path+'/acknowledge',{'version':3,'confirmed':1})
        after=script(f"echo json_encode(App\\Models\\SupportPlan::findOrFail({ids['plan']})->getAttributes());");assert before==after
        with sqlite3.connect(db)as c:assert c.execute('SELECT acknowledged_at,version FROM workplace_support_shares WHERE id=?',(share_id,)).fetchone()[1]==4
        passed('Employer receipt persists without changing support plan or confirming action completion')
        stop_backend();start_backend();assert req(employer,path)[0]==200 and 'Receipt acknowledged'in req(learner,path)[1]
        passed('Database-backed encrypted sessions and permission/receipt persist across backend restart')
        post(learner,path,path+'/withdraw',{'version':3,'confirmed':1})
        assert req(employer,path)[0]==200
        post(learner,path,path+'/withdraw',{'version':4,'confirmed':1})
        assert req(employer,path)[0]==404 and share_id not in req(employer,'/workplace-support')[1]
        passed('Stale withdrawal cannot overwrite; current apprentice withdrawal immediately stops future employer access')
        data['request_token']='32345678-1234-4234-8234-123456789abc'
        status,_,url,_=post(member,create,create,data);assert status==200;path=url[len(origin):]
        post(lead,path,path+'/check',{'version':1,'confirmed':1});post(learner,path,path+'/decide',{'version':2,'decision':'allow','confirmed':1});assert req(employer,path)[0]==200
        script(f"$p=App\\Models\\SupportPlan::findOrFail({ids['plan']});$active=$p->active_plan;$active['fields']['support']='NEW-PRIVATE-APPROVED';$p->active_plan=$active;$p->save();echo '{{}}';")
        assert req(employer,path)[0]==404 and 'Needs recheck'in req(learner,'/workplace-support')[1]
        post(learner,path,path+'/withdraw',{'version':3,'confirmed':1})
        passed('Changed approved source holds old access; apprentice can still withdraw a held summary')
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT COUNT(*) FROM workplace_support_share_events').fetchone()[0]>=11
            assert c.execute('SELECT COUNT(*) FROM signed_review_records').fetchone()[0]==0
        passed('Append-only action history remains; no electronic review or completion created')
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('employer_start_checks','employer_start_events')").fetchone()[0]==0
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='apprentice_start_checks'").fetchone()[0]==1
            assert c.execute('SELECT COUNT(*) FROM support_plans').fetchone()[0]==1
        assert artisan('migrate','--force').returncode==0
        passed('Disposable down/up removes only Pack32 acceptance tables and retains earlier support/onboarding schema; real history needs backup')
        configure('production');start_backend();assert req(learner,'/workplace-support')[0]==503 and req(member,create)[0]==503
        passed('Existing production gate still blocks the new workflow')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Local client-verified HTTPS with disposable SQLite/database sessions/cache','realDataUsed':False,'externalMessagesSent':False,'actualCloudAccess':False,'actualNativeEngine':False,'actualBrowserOrPhoneTested':False};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps(output))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
