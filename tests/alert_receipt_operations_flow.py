"""Fictional Pack31 reminder receipt operations workflows over a local TLS proxy; never accesses Laravel Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-receipt-operations-') as temporary:
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
        ids=script(r"$users=[];foreach(['admin@example.test'=>'admin','ceo@example.test'=>'ceo','tutor@example.test'=>'tutor','learner@example.test'=>'apprentice']as$email=>$role){$users[$email]=App\Models\User::factory()->create(['email'=>$email,'role'=>$role,'password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);}$a=App\Models\Apprentice::factory()->create(['reference'=>'RECEIPT-FICTIONAL','user_id'=>$users['learner@example.test']->id,'tutor_id'=>$users['tutor@example.test']->id]);$source=['apprentice_id'=>$a->id,'recipient_id'=>$a->user_id,'items'=>[['title'=>'PRIVATE-TASK-NOT-FOR-EXPORT']]];$d=App\Models\ApprenticeAlertDelivery::create(['id'=>(string)Illuminate\Support\Str::uuid(),'apprentice_id'=>$a->id,'recipient_id'=>$a->user_id,'channel'=>'sms','cycle_date'=>today(),'source'=>$source,'source_hash'=>App\Support\ReviewedImports::hash($source),'recipient_hash'=>str_repeat('r',64),'preference_revision'=>0,'auth_version'=>1,'config_hash'=>str_repeat('c',64),'state'=>'Accepted','reserved_at'=>now()->subMinutes(10),'completed_at'=>now()->subMinutes(9),'provider_id'=>'FICTIONAL-PROVIDER-123','history'=>[]]);echo json_encode(['delivery'=>$d->id,'apprentice'=>$a->id,'admin'=>$users['admin@example.test']->id,'today'=>today()->toDateString(),'time'=>now()->subMinutes(2)->utc()->format('Y-m-d H:i:s')]);")
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            assert req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password})[0]==200;return c
        def post(c,source,path,values):
            status,text,_,_=req(c,source);assert status==200
            return req(c,path,{'_token':csrf(text),**values})
        admin=login('admin@example.test');ceo=login('ceo@example.test');tutor=login('tutor@example.test');learner=login('learner@example.test')
        index='/receipt-operations';detail='/receipt-deliveries/'+ids['delivery']
        status,text,_,headers=req(admin,index);assert status==200 and 'RECEIPT-FICTIONAL'in text and 'No receipt'in text and headers['Referrer-Policy']=='no-referrer'
        assert req(tutor,index)[0]==403 and req(learner,detail)[0]==403 and req(client(),index)[2].endswith('/login')
        passed('Actual client-verified HTTPS manager workbench shows no inferred receipt; tutor/apprentice/guest denied')
        header='delivery_id,channel,provider_id,outcome,occurred_at_utc,evidence_kind,source_reference'
        status,template,_,_=req(admin,index+'/template.csv');assert status==200 and template.strip()==header
        passed('Authorised CSV template exposes exact normalisation contract without source data')
        def csv(outcome):return header+'\n'+','.join([ids['delivery'],'sms','FICTIONAL-PROVIDER-123',outcome,ids['time'],'sms_delivery_report','PRIVATE-PROVIDER-SOURCE'])+'\n'
        before=script(f"echo json_encode(App\\Models\\ApprenticeAlertDelivery::findOrFail('{ids['delivery']}')->getAttributes());")
        form={'request_token':'12345678-1234-4234-8234-123456789abc','csv':csv('Failed'),'source_reference':'PRIVATE-PROVIDER-MAPPING','confirmed':1}
        assert req(admin,index,form)[0]==419
        passed('Actual missing-CSRF bulk POST is refused before import writes')
        status,text,url,_=post(admin,index,index,form);assert status==200 and 'Preview'in text and 'PRIVATE-PROVIDER-SOURCE'in text
        batch=url[len(origin):];batch_id=batch.rsplit('/',1)[1]
        with sqlite3.connect(db)as c:assert 'PRIVATE-'not in c.execute('SELECT rows FROM alert_receipt_batches WHERE id=?',(batch_id,)).fetchone()[0] and c.execute('SELECT COUNT(*) FROM alert_receipt_records').fetchone()[0]==0
        passed('Real bulk preview stores encrypted normalised records without changing receipts or transport attempts')
        post(admin,batch,batch+'/review',{'version':1,'decision':'apply','note':'Fictional self check','confirmed':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM alert_receipt_batches WHERE id=?',(batch_id,)).fetchone()[0]=='Preview'
        post(ceo,batch,batch+'/review',{'version':1,'decision':'apply','note':'PRIVATE-INDEPENDENT-CHECK','confirmed':1})
        status,text,_,_=req(admin,detail);assert status==200 and 'Recorded provider outcome: Failed'in text and 'Case Open'in text
        with sqlite3.connect(db)as c:case=c.execute('SELECT id,version FROM alert_followup_cases WHERE delivery_id=?',(ids['delivery'],)).fetchone()
        assert script(f"echo json_encode(App\\Models\\ApprenticeAlertDelivery::findOrFail('{ids['delivery']}')->getAttributes());")==before
        passed('Different manager applies source evidence and opens failure follow-up, preserving original Accepted/reservation/seen ledger')
        post(admin,detail,detail+'/case',{'version':case[1],'owner_id':ids['admin'],'due_on':ids['today'],'note':'PRIVATE-FOLLOWUP-ACTION','confirmed':1})
        assert 'PRIVATE-FOLLOWUP-ACTION'in req(admin,detail)[1]
        passed('Versioned management owner/date/private action note persists without automatic contact or retry')
        form['request_token']='22345678-1234-4234-8234-123456789abc';form['csv']=csv('Delivered')
        status,text,url,_=post(admin,index,index,form);assert status==200;second=url[len(origin):]
        post(ceo,second,second+'/review',{'version':1,'decision':'apply','note':'Fictional checked later source','confirmed':1})
        assert 'Recorded provider outcome: Conflict'in req(admin,detail)[1]
        with sqlite3.connect(db)as c:
            case=c.execute('SELECT id,version FROM alert_followup_cases WHERE delivery_id=?',(ids['delivery'],)).fetchone();receipt=c.execute("SELECT id FROM alert_receipt_records WHERE delivery_id=? AND outcome='Delivered'",(ids['delivery'],)).fetchone()[0]
        passed('Conflicting terminal source reports remain Conflict rather than trusting a later row')
        casepath='/receipt-cases/'+str(case[0]);post(admin,detail,casepath+'/propose',{'version':case[1],'record_id':receipt,'note':'PRIVATE-EXACT-SOURCE-RESOLUTION','confirmed':1})
        with sqlite3.connect(db)as c:version=c.execute('SELECT version FROM alert_followup_cases WHERE id=?',(case[0],)).fetchone()[0]
        post(admin,detail,casepath+'/check',{'version':version,'decision':'close','note':'Fictional self check','confirmed':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM alert_followup_cases WHERE id=?',(case[0],)).fetchone()[0]=='Awaiting check'
        post(ceo,detail,casepath+'/check',{'version':version,'decision':'close','note':'PRIVATE-SECOND-SOURCE-CHECK','confirmed':1})
        status,text,_,_=req(admin,detail);assert status==200 and 'Recorded provider outcome: Delivered'in text and 'Case Closed'in text
        passed('Two-person exact-record source selection resolves conflict without inventing a receipt or sending again')
        stop_backend();start_backend();assert 'Recorded provider outcome: Delivered'in req(admin,detail)[1]
        passed('Encrypted database session, evidence, independent case resolution and history survive restart')
        status,report,_,headers=req(admin,index+'/report.csv?search=RECEIPT-FICTIONAL');assert status==200 and 'Delivered'in report and 'Accepted'in report and 'PRIVATE-'not in report and 'learner@example.test'not in report and 'text/csv'in headers['Content-Type']
        assert req(tutor,index+'/report.csv')[0]==403
        passed('Actual scoped operations CSV separates transport/recorded outcome and excludes contacts/source notes/message text')
        post(ceo,second,second+'/withdraw',{'version':2,'note':'Fictional incorrect source withdrawn','confirmed':1})
        status,text,_,_=req(admin,detail);assert status==200 and 'Recorded provider outcome: Failed'in text and 'Case Open'in text
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM alert_receipt_records').fetchone()[0]==2 and c.execute('SELECT COUNT(*) FROM alert_receipt_records WHERE withdrawn_at IS NOT NULL').fetchone()[0]==1
        passed('Incorrect applied source withdrawal retains records, reopens follow-up and invalidates obsolete closure resolution')
        assert script(f"echo json_encode(App\\Models\\ApprenticeAlertDelivery::findOrFail('{ids['delivery']}')->getAttributes());")==before
        passed('Whole import/review/conflict/closure/withdrawal lifecycle leaves original send ledger byte values unchanged')
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('portal_privacy_notices','portal_privacy_state','portal_privacy_acknowledgements','portal_privacy_cases','portal_privacy_events')").fetchone()[0]==0
            assert c.execute('SELECT COUNT(*) FROM apprentice_alert_deliveries').fetchone()[0]==1
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='destination_proposals'").fetchone()[0]==1
        assert artisan('migrate','--force').returncode==0
        passed('Disposable down/up removes only four Pack42 privacy tables; original attempt and earlier leaver schema remain')
        configure('production');start_backend();assert req(admin,index)[0]==503 and req(ceo,detail)[0]==503
        passed('Existing production gate remains enforced on new messaging operations')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual local client-verified HTTPS; disposable SQLite/encrypted database sessions/cache','providerSource':'Fictional normalised records only, not actual provider DLRs','realDataUsed':False,'externalMessagesSent':False,'actualVonageAccountOrProviderAccess':False,'liveWebhookTested':False,'actualCloudAccess':False,'actualNativeEngine':False,'actualBrowserOrPhoneTested':False,'actualMicrosoftExcelTested':False};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps(output))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
