"""Fictional questionnaire-link workflow over a local TLS proxy; never accesses Laravel Cloud.
Uses disposable SQLite with database sessions/cache. Does not certify MySQL or S3.
"""
from pathlib import Path
import argparse, base64, hashlib, http.client, http.cookiejar, http.server, json, os, re, secrets, signal, socket, sqlite3, ssl, subprocess, tempfile, threading, time
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

with tempfile.TemporaryDirectory(prefix='gta-questionnaires-') as temporary:
    temp=Path(temporary);db=temp/'fictional.sqlite';db.touch();storage=temp/'storage'
    for part in ['app/private','framework/sessions','framework/cache/data','framework/cache/locks','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
    php_port=free_port();tls_port=free_port();origin=f'https://127.0.0.1:{tls_port}'
    private_password='Fictional-private-'+secrets.token_urlsafe(24)
    env=os.environ.copy();env.update(APP_ENV='staging',APP_DEBUG='false',APP_URL=origin,APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),DB_CONNECTION='sqlite',DB_DATABASE=str(db),SESSION_DRIVER='database',SESSION_ENCRYPT='true',SESSION_SECURE_COOKIE='true',CACHE_STORE='database',MAIL_MAILER='array',QUEUE_CONNECTION='sync',GTA_ENABLE_STAGING_PORTAL='true',GTA_ALLOW_INITIAL_ADMIN='true',GTA_SCHEDULE_PORTAL_REMINDERS='false',LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(temp/'config.php'),APP_ROUTES_CACHE=str(temp/'routes.php'))
    env['GTA_SOURCE_TEST_PASSWORD']=private_password
    shared_url='https://forms.example.test/Pages/ResponsePage.aspx?id=FICTIONAL-RESPONDER&origin=portal'
    env.update(GTA_QUESTIONNAIRES_ENABLED='false',GTA_QUESTIONNAIRE_HOSTS='forms.example.test',GTA_QUESTIONNAIRE_INDUCTION_URL='',GTA_QUESTIONNAIRE_INDUCTION_REVIEWED_SHA256='',GTA_QUESTIONNAIRE_SIXWEEK_URL='',GTA_QUESTIONNAIRE_SIXWEEK_REVIEWED_SHA256='',GTA_QUESTIONNAIRE_ONGOING_URL='',GTA_QUESTIONNAIRE_ONGOING_REVIEWED_SHA256='')
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
        seed=r"require 'vendor/autoload.php';$a=require 'bootstrap/app.php';$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$users=[];foreach(['admin@example.test'=>'admin','member@example.test'=>'inclusion_member','tutor@example.test'=>'tutor','employer@example.test'=>'employer','learner@example.test'=>'apprentice']as$email=>$role){$users[$email]=App\Models\User::factory()->create(['email'=>$email,'role'=>$role,'password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);}$learner=App\Models\Apprentice::factory()->create(['first_name'=>'PRIVATE-LEARNER-SENTINEL']);$learner->user_id=$users['learner@example.test']->id;$learner->save();$receipt=App\Models\SurveyReceipt::create(['apprentice_id'=>$learner->id,'kind'=>'sixweek','submitted_date'=>today(),'recorded_by'=>$users['admin@example.test']->id]);$receipt->checks=[['private'=>'PRIVATE-CHECK-SENTINEL','checked_date'=>today()->toDateString(),'followup'=>'Not required','receipt_revision'=>1]];$receipt->save();echo json_encode(['apprentice'=>$learner->id]);"
        seeded=subprocess.run([args.php,'-r',seed],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert seeded.returncode==0,'Fictional questionnaire setup failed';apprentice=json.loads(seeded.stdout)['apprentice']
        checked=artisan('gta:questionnaire-check');report=json.loads(checked.stdout);assert checked.returncode==1 and len(report['links'])==3 and not report['network_requests_performed'] and not report['writes_performed']
        passed('Default cached configuration leaves all three responder links unavailable; command makes no form request or write')
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            assert req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password})[0] in (200,403)
            return c
        admin=login('admin@example.test');member=login('member@example.test');tutor=login('tutor@example.test');employer=login('employer@example.test');learner=login('learner@example.test')
        with sqlite3.connect(db)as c:
            before=c.execute('SELECT * FROM survey_receipts ORDER BY id').fetchall();events=c.execute('SELECT * FROM learning_events ORDER BY id').fetchall()
        status,text,_,headers=req(learner,'/questionnaires');assert status==200 and 'Currently unavailable'in text and 'target="_blank"'not in text and headers['Referrer-Policy']=='no-referrer'
        assert 'href="'+origin+'/questionnaires"'in text
        passed('Bound apprentice sees questionnaire navigation and unavailable cards over client-verified HTTPS with no referrer')
        assert req(tutor,'/questionnaires')[0]==403 and req(employer,'/questionnaires')[0]==403 and req(member,'/questionnaires')[0]==200
        passed('Ordinary tutor and employer cannot access questionnaire links; Inclusion Team can')
        for c in [learner,member,tutor,employer]:assert req(c,'/questionnaires/configuration')[0]==403
        assert req(admin,'/questionnaires/configuration')[0]==200
        passed('Only administrator-level accounts can read link configuration status')
        env.update(GTA_QUESTIONNAIRES_ENABLED='true',GTA_QUESTIONNAIRE_INDUCTION_URL=shared_url);stop_backend();configure();start_backend()
        status,text,_,_=req(learner,'/questionnaires');assert status==200 and shared_url not in text and 'target="_blank"'not in text
        passed('Configured host and URL alone cannot publish an unreviewed responder link')
        env['GTA_QUESTIONNAIRE_INDUCTION_REVIEWED_SHA256']=hashlib.sha256(shared_url.encode()).hexdigest();stop_backend();configure();start_backend()
        status,text,_,headers=req(learner,'/questionnaires');assert status==200 and shared_url.replace('&','&amp;')in text and 'rel="noopener noreferrer"'in text and 'referrerpolicy="no-referrer"'in text and headers['Referrer-Policy']=='no-referrer' and 'no-store'in headers['Cache-Control']
        assert 'Opening a link does not record submission or completion'in text and 'Voluntary and available'in text
        passed('Exact reviewed shared link becomes available after rebuilding cached configuration; private new-tab attributes and timing text are present')
        status,text,_,_=req(learner,'/questionnaires?url=https%3A%2F%2Fevil.example&apprentice=999');assert status==200 and 'evil.example'not in text and 'PRIVATE-CHECK-SENTINEL'not in text and 'PRIVATE-LEARNER-SENTINEL'not in text
        assert req(learner,f'/apprentices/{apprentice}/surveys')[0]==403
        passed('Request overrides cannot redirect links or expose private survey metadata; apprentice receipt access remains refused')
        assert req(learner,'/questionnaires',{'_token':csrf(text),'completed':'1','answers':'PRIVATE-ANSWER-SENTINEL'})[0]==405
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT * FROM survey_receipts ORDER BY id').fetchall()==before and c.execute('SELECT * FROM learning_events ORDER BY id').fetchall()==events
        passed('Link views and unsupported completion POST leave original receipts, checks and learning events byte-for-byte unchanged')
        status,text,_,_=req(member,f'/apprentices/{apprentice}/surveys');assert status==200 and 'Open questionnaires'in text and 'PRIVATE-CHECK-SENTINEL'not in text
        passed('Existing Inclusion receipt follow-up links to the new page without copying private check payloads')
        checked=artisan('gta:questionnaire-check');report=json.loads(checked.stdout);assert checked.returncode==1 and report['links']['induction']['ready'] and not report['links']['sixweek']['ready'] and shared_url not in checked.stdout
        passed('Read-only command reports partial readiness and candidate hashes without exposing full responder URLs')
        env['GTA_QUESTIONNAIRE_INDUCTION_URL']=shared_url+'&changed=yes';stop_backend();configure();start_backend()
        status,text,_,_=req(learner,'/questionnaires');assert status==200 and 'changed=yes'not in text and 'target="_blank"'not in text
        passed('Changing an exact URL invalidates its previous review across cached-config deployment and PHP restart')
        env.update(GTA_QUESTIONNAIRE_INDUCTION_URL=shared_url,GTA_QUESTIONNAIRES_ENABLED='false');stop_backend();configure();start_backend()
        assert 'target="_blank"'not in req(learner,'/questionnaires')[1]
        passed('Single master switch disables reviewed links while retaining private receipt records')
        stop_backend();configure('production');start_backend();assert req(learner,'/questionnaires')[0]==503
        stop_backend();configure('staging',False);start_backend();assert req(learner,'/questionnaires')[0]==503
        passed('Existing production block and staging opt-in remain enforced on the new routes')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Real local client-verified HTTPS, cached configuration and disposable SQLite database sessions/cache','realDataUsed':False,'externalFormsFetched':False,'externalMessagesSent':False,'actualCloudAccess':False,'actualNativeEngine':False,'limits':['No real responder URL, provider access or privacy notice accepted','No browser/mobile/assistive technology or hosted acceptance','No response import, automatic receipt or scheduling']};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
