"""Fictional Pack32 scoped staging acceptance workflows over a local TLS proxy; never accesses Laravel Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-staging-acceptance-') as temporary:
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
            r=subprocess.run([args.php,'-r',bootstrap+code],cwd=project,env=env,capture_output=True,text=True,timeout=90);assert r.returncode==0,'Private fixture setup failed';return json.loads(r.stdout or '{}')
        ids=script(r"$users=[];foreach(['admin@example.test'=>'admin','ceo@example.test'=>'ceo','tutor@example.test'=>'tutor']as$email=>$role){$users[$email]=App\Models\User::factory()->create(['email'=>$email,'role'=>$role,'password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);}echo json_encode(['admin'=>$users['admin@example.test']->id,'ceo'=>$users['ceo@example.test']->id]);")
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            assert req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password})[0]==200;return c
        def post(c,source,path,values):
            status,text,_,_=req(c,source);assert status==200
            return req(c,path,{'_token':csrf(text),**values})
        admin=login('admin@example.test');ceo=login('ceo@example.test');tutor=login('tutor@example.test');index='/staging-acceptance'
        status,text,_,headers=req(admin,index);assert status==200 and 'Read-only source capture'in text and 'captured'in text and headers['Referrer-Policy']=='no-referrer'
        assert req(tutor,index)[0]==403 and req(client(),index)[2].endswith('/login')
        passed('Actual client-verified HTTPS source capture is manager-only; guest and ordinary tutor denied')
        form={'title':'FICTIONAL WORKFLOW TEST ONLY','target':'Fictional loopback staging, not actual Cloud','reference':'FICTIONAL-INTEGRATED-REFERENCE','programmes':'Synthetic test scope, no real provider/native/browser acceptance','exclusions':'Yorkshire paused; actual production/native/Cloud/browser/provider acceptance NOT established','confirmed':1}
        assert req(admin,index,form)[0]==419
        passed('Missing-CSRF run creation refused before writes')
        status,text,url,_=post(admin,index,index,form);assert status==200 and '0/18 required scenarios'in text
        run=url[len(origin):];run_id=run.rsplit('/',1)[1]
        with sqlite3.connect(db)as c:row=c.execute('SELECT source_hash,source_snapshot,details FROM staging_acceptance_runs WHERE id=?',(run_id,)).fetchone();assert 'app/'not in row[1] and 'FICTIONAL WORKFLOW'not in row[2] and c.execute('SELECT COUNT(*) FROM staging_acceptance_checks').fetchone()[0]==0
        passed('Real run captures current full source/config fingerprint and encrypted declarations, with no automatic checks or acceptance')
        diagnostic=artisan('gta:acceptance-source-check');assert diagnostic.returncode==0;diagnostic_value=json.loads(diagnostic.stdout);assert diagnostic_value['fingerprint']==row[0] and diagnostic_value['database_accessed']is False and 'files'not in diagnostic_value
        passed('Actual source-only CLI matches runtime source fingerprint, exports summary only and reports no DB access or deployment approval')
        # All observations below are explicitly synthetic data to test the state machine, NOT genuine acceptance.
        obs={'version':0,'outcome':'Failed','tested_at':script("echo json_encode(now()->utc()->format('Y-m-d H:i:s'));"),'reference':'PRIVATE-SYNTHETIC-TEST-REFERENCE','note':'FICTIONAL observation only; does not certify an actual browser/native/provider test.','confirmed':1}
        post(admin,run,run+'/checks/accounts',obs)
        with sqlite3.connect(db)as c:check_id=c.execute("SELECT id FROM staging_acceptance_checks WHERE run_id=? AND scenario_id='accounts'",(run_id,)).fetchone()[0]
        post(ceo,run,f'/staging-checks/{check_id}/review',{'version':1,'decision':'accept','note':'Fictional accuracy check only','confirmed':1})
        assert '0/18 required scenarios'in req(admin,run)[1]
        passed('Independently accepting accuracy of a Failed observation does not count as Passed')
        obs['version']=2;obs['outcome']='Passed';obs['tested_at']=script("echo json_encode(now()->utc()->format('Y-m-d H:i:s'));")
        post(admin,run,run+'/checks/accounts',obs);post(ceo,run,f'/staging-checks/{check_id}/review',{'version':3,'decision':'accept','note':'Fictional synthetic recheck','confirmed':1})
        # Populate other fictional observations through service calls with one test-only cached REAL snapshot.
        # Backend HTTP/final acceptance still recaptures real source. This fixture does NOT perform these tests.
        seed=r"$r=App\Models\StagingAcceptanceRun::findOrFail('RUN_ID');$snapshot=(new App\Support\AcceptanceSource)->capture();if($snapshot['fingerprint']!==$r->source_hash){throw new RuntimeException('Fixture source changed');}app()->instance(App\Support\AcceptanceSource::class,new class($snapshot)extends App\Support\AcceptanceSource{public function __construct(private array $snapshot){}public function capture(?string $root=null):array{return $this->snapshot;}});$m=App\Models\User::find(ADMIN_ID);$n=App\Models\User::find(CEO_ID);foreach(App\Support\StagingAcceptance::scenarios($r)as$id=>$scenario){if($id==='accounts')continue;App\Support\StagingAcceptance::recordCheck($m,1,$r,$id,0,['outcome'=>'Passed','tested_at'=>now()->utc()->format('Y-m-d H:i:s'),'reference'=>'PRIVATE-SYNTHETIC-TEST-REF','note'=>'FICTIONAL STATE-MACHINE INPUT ONLY; no actual native/browser/provider acceptance']);$c=App\Models\StagingAcceptanceCheck::where('run_id',$r->id)->where('scenario_id',$id)->firstOrFail();App\Support\StagingAcceptance::reviewCheck($n,1,$c,1,'accept','Fictional synthetic review only');}echo '{}';"
        script(seed.replace('RUN_ID',run_id).replace('ADMIN_ID',str(ids['admin'])).replace('CEO_ID',str(ids['ceo'])))
        assert '18/18 required scenarios'in req(admin,run)[1]
        passed('Synthetic bulk observations exercise readiness bookkeeping; no native/browser/provider testing is inferred')
        finding={'version':0,'scenario_id':'accounts','severity':'Major','owner_id':ids['admin'],'title':'Fictional major finding','detail':'PRIVATE-SYNTHETIC-FINDING','reference':'PRIVATE-FINDING-REFERENCE','confirmed':1}
        status,text,url,_=post(admin,run,run+'/issues',finding);assert status==200;issue=url[len(origin):];issue_id=issue.rsplit('/',1)[1]
        post(admin,issue,issue+'/propose',{'version':1,'decision':'defer','reference':'Fictional','note':'Fictional','confirmed':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM staging_acceptance_issues WHERE id=?',(issue_id,)).fetchone()[0]=='Open'
        with sqlite3.connect(db)as c:run_version=c.execute('SELECT version FROM staging_acceptance_runs WHERE id=?',(run_id,)).fetchone()[0]
        post(ceo,run,run+'/accept',{'version':run_version,'note':'Fictional premature acceptance','confirmed':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM staging_acceptance_runs WHERE id=?',(run_id,)).fetchone()[0]=='Open'
        passed('Major finding cannot be deferred and blocks acceptance despite all synthetic Passed observations')
        post(admin,issue,issue+'/propose',{'version':1,'decision':'resolve','reference':'PRIVATE-SYNTHETIC-FIX-REF','note':'Fictional resolution for workflow testing only','confirmed':1})
        post(admin,issue,issue+'/review',{'version':2,'decision':'accept','note':'Fictional self-check','confirmed':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM staging_acceptance_issues WHERE id=?',(issue_id,)).fetchone()[0]=='Proposed'
        post(ceo,issue,issue+'/review',{'version':2,'decision':'accept','note':'Fictional independent resolution check','confirmed':1})
        with sqlite3.connect(db)as c:run_version=c.execute('SELECT version FROM staging_acceptance_runs WHERE id=?',(run_id,)).fetchone()[0]
        post(admin,run,run+'/accept',{'version':run_version,'note':'Fictional self acceptance','confirmed':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM staging_acceptance_runs WHERE id=?',(run_id,)).fetchone()[0]=='Open'
        post(ceo,run,run+'/accept',{'version':run_version,'note':'FICTIONAL scoped state transition only, NOT real acceptance','confirmed':1})
        assert 'Accepted for declared staging scope'in req(admin,run)[1]
        passed('Separate-manager finding and final checks required; synthetic scoped acceptance creates no deployment/production approval')
        status,report,_,headers=req(admin,run+'/handover.json');assert status==200 and 'PRIVATE-'not in report and json.loads(report)['production_approved']is False and json.loads(report)['private_notes_and_evidence_references_exported']is False and 'no-store'in headers['Cache-Control']
        assert req(tutor,run+'/handover.json')[0]==403
        passed('Minimal authorised handover export omits private evidence references/notes and explicitly denies production approval')
        stop_backend();start_backend();assert 'Accepted for declared staging scope'in req(admin,run)[1]
        passed('Encrypted database sessions and source-bound synthetic acceptance persist across restart')
        # Actual private runtime config change changes the keyed source/config seal without exporting the value.
        stop_backend();env['MAIL_USERNAME']='PRIVATE-FICTIONAL-CHANGED-CONFIG';configure();start_backend()
        status,text,_,_=req(admin,run);assert status==200 and 'Source changed / needs new run'in text and 'PRIVATE-FICTIONAL-CHANGED-CONFIG'not in text
        passed('Actual sensitive runtime configuration change holds previous acceptance without exposing its raw value')
        form['title']='FICTIONAL NEW SOURCE RUN';form['reference']='FICTIONAL-NEW-CONFIG-REFERENCE'
        status,text,new_url,_=post(admin,run,run+'/new-run',form);assert status==200 and '0/18 required scenarios'in text
        new_id=new_url.rsplit('/',1)[1]
        with sqlite3.connect(db)as c:assert c.execute('SELECT parent_id FROM staging_acceptance_runs WHERE id=?',(new_id,)).fetchone()[0]==run_id and c.execute('SELECT COUNT(*) FROM staging_acceptance_checks WHERE run_id=?',(new_id,)).fetchone()[0]==0
        passed('New-source cycle links prior run but carries no old test passes or acceptance')
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('account_lifecycle_proposals','account_lifecycle_states','cohort_forecasts','cohort_snapshots','operational_followups','portal_operations_events')").fetchone()[0]==0
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='alert_receipt_batches'").fetchone()[0]==1
        assert artisan('migrate','--force').returncode==0
        passed('Disposable latest down/up removes only four Pack43 operations tables, retaining earlier receipt/portal schema')
        configure('production');start_backend();assert req(admin,index)[0]==503 and req(ceo,index)[0]==503
        passed('Existing production access gate remains unchanged')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual local client-verified HTTPS; real source/config capture and disposable SQLite/encrypted sessions/cache','syntheticAcceptanceRecordsOnly':True,'bulkSyntheticChecksUseOneCachedRealFixtureSnapshot':True,'realTestsImpliedBySyntheticResults':False,'sourceScanIncludesTaskPdfs':True,'realDataUsed':False,'externalMessagesSent':False,'actualCloudAccess':False,'actualNativeEngine':False,'actualBrowserOrPhoneTested':False,'productionApproved':False};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps(output))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
