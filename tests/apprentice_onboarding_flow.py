"""Fictional Pack28 apprentice onboarding workflows over a local TLS proxy; never accesses Laravel Cloud.
Uses disposable SQLite with database sessions/cache. Does not certify MySQL or S3.
"""
from pathlib import Path
import email, socketserver
import argparse, base64, http.client, http.cookiejar, http.server, json, os, re, secrets, signal, socket, sqlite3, ssl, subprocess, tempfile, threading, time
from urllib.request import Request, HTTPSHandler, HTTPCookieProcessor, build_opener
from urllib.parse import urlencode
from urllib.error import HTTPError

parser=argparse.ArgumentParser()
parser.add_argument('--php',default='php');parser.add_argument('--output',required=True)
args=parser.parse_args();project=Path(__file__).resolve().parent.parent
checks=[];server=None;proxy=None;smtp_server=None;captures=[]

def passed(name):checks.append({'name':name,'passed':True})
def free_port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]

with tempfile.TemporaryDirectory(prefix='gta-apprentice-onboarding-') as temporary:
    temp=Path(temporary);db=temp/'fictional.sqlite';db.touch();storage=temp/'storage'
    for part in ['app/private','framework/sessions','framework/cache/data','framework/cache/locks','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
    php_port=free_port();tls_port=free_port();smtp_port=free_port();origin=f'https://127.0.0.1:{tls_port}'
    private_password='Fictional-private-'+secrets.token_urlsafe(24)
    env=os.environ.copy();env.update(APP_ENV='staging',APP_DEBUG='false',APP_URL=origin,APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),DB_CONNECTION='sqlite',DB_DATABASE=str(db),SESSION_DRIVER='database',SESSION_ENCRYPT='true',SESSION_SECURE_COOKIE='true',CACHE_STORE='database',MAIL_MAILER='array',QUEUE_CONNECTION='sync',GTA_ENABLE_STAGING_PORTAL='true',GTA_ALLOW_INITIAL_ADMIN='true',GTA_SCHEDULE_PORTAL_REMINDERS='false',LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(temp/'config.php'),APP_ROUTES_CACHE=str(temp/'routes.php'))
    env['GTA_SOURCE_TEST_PASSWORD']=private_password
    env.update(MAIL_MAILER='smtp',MAIL_SCHEME='smtp',MAIL_HOST='127.0.0.1',MAIL_PORT=str(smtp_port),MAIL_URL='',MAIL_TIMEOUT='10',MAIL_FROM_ADDRESS='portal@example.test',GTA_STAGING_ONBOARDING_ENABLED='false',GTA_STAGING_ONBOARDING_RECIPIENTS='newlearner@example.test')
    env.update(GTA_MFA_ENROLMENT_ENABLED='false',GTA_MFA_PILOT_EMAILS='')
    def artisan(*command,input_value=None):
        return subprocess.run([args.php,'artisan',*command,'--no-interaction'],cwd=project,env=env,input=input_value,capture_output=True,text=True,timeout=60)
    def configure(mode='staging',enabled=True):
        config_env=env.copy();config_env['APP_ENV']=mode;config_env['GTA_ENABLE_STAGING_PORTAL']='true' if enabled else 'false'
        # A private test-only config snapshot trusts only this loopback TLS proxy.
        # No proxy trust or resource configuration is added to the application source.
        script="require 'vendor/autoload.php';$a=require 'bootstrap/app.php';$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$c=$a['config']->all();$c['trustedproxy']['proxies']=['127.0.0.1'];$c['mail']['mailers']['smtp']['timeout']=10;$c['evidence']['disk']='local';$c['evidence']['legacy_disk']='local';$c['evidence']['private_disks']=['local'];file_put_contents(getenv('APP_CONFIG_CACHE'), '<?php return '.var_export($c,true).';');"
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

    class SMTP(socketserver.StreamRequestHandler):
        def handle(self):
            self.wfile.write(b'220 fictional-local ESMTP\r\n');self.wfile.flush()
            while True:
                line=self.rfile.readline()
                if not line:return
                command=line.decode(errors='replace').strip().split(' ',1)[0].upper()
                if command in ['EHLO','HELO']:self.wfile.write(b'250-fictional\r\n250 PIPELINING\r\n')
                elif command in ['MAIL','RCPT','RSET']:self.wfile.write(b'250 OK\r\n')
                elif command=='DATA':
                    self.wfile.write(b'354 End with dot\r\n');self.wfile.flush();parts=[]
                    while True:
                        part=self.rfile.readline()
                        if part in [b'.\r\n',b'.\n',b'']:break
                        parts.append(part[1:]if part.startswith(b'..')else part)
                    captures.append(b''.join(parts));self.wfile.write(b'250 Captured\r\n')
                elif command=='QUIT':self.wfile.write(b'221 Bye\r\n');self.wfile.flush();return
                else:self.wfile.write(b'502 Unsupported\r\n')
                self.wfile.flush()
    class SMTPServer(socketserver.ThreadingTCPServer):allow_reuse_address=True;daemon_threads=True
    try:
        smtp_server=SMTPServer(('127.0.0.1',smtp_port),SMTP);threading.Thread(target=smtp_server.serve_forever,daemon=True).start()
        configure();assert artisan('migrate','--force').returncode==0,'Disposable migration failed'
        bootstrap=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();"
        def script(code):
            r=subprocess.run([args.php,'-r',bootstrap+code],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert r.returncode==0,'Private fixture operation failed';return json.loads(r.stdout or '{}')
        ids=script(r"$m=App\Models\User::factory()->create(['role'=>'admin','email'=>'admin@example.test','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);$t=App\Models\User::factory()->create(['role'=>'tutor','email'=>'tutor@example.test','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);$p=App\Models\Programme::factory()->create();$a=App\Models\Apprentice::factory()->create(['reference'=>'ONBOARD-FICTIONAL','first_name'=>'Fictional New Apprentice','email'=>'newlearner@example.test','user_id'=>null,'tutor_id'=>$t->id,'programme_id'=>$p->id,'start_date'=>today(),'planned_end_date'=>today()->addYears(3)]);App\Models\Apprentice::factory()->create(['reference'=>'OTHER-PRIVATE-PROFILE','user_id'=>null]);echo json_encode(['manager'=>$m->id,'tutor'=>$t->id,'apprentice'=>$a->id]);")
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address,password=None):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            assert req(c,'/login',{'_token':csrf(text),'email':address,'password':password or private_password})[0]==200
            return c
        def post(c,source,path,values):
            status,text,_,_=req(c,source);assert status==200
            return req(c,path,{'_token':csrf(text),**values})
        detail=f'/apprentice-onboarding/{ids["apprentice"]}'
        admin=login('admin@example.test');tutor=login('tutor@example.test')
        status,text,_,headers=req(admin,'/apprentice-onboarding?state=unlinked&search=ONBOARD');assert status==200 and 'ONBOARD-FICTIONAL'in text and 'no-store'in headers['Cache-Control']
        assert req(tutor,'/apprentice-onboarding')[0]==403 and req(client(),detail)[2].endswith('/login')
        passed('Verified local HTTPS renders filtered manager-only onboarding list; tutor and guest denied')
        assert req(admin,detail+'/link',{'version':1,'mode':'new','confirmed':1,'identity_reference':'Fictional without CSRF'})[0]==419
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM users').fetchone()[0]==2
        passed('Actual missing-CSRF POST cannot provision a login')
        status,text,_,_=post(admin,detail,detail+'/link',{'version':1,'mode':'new','confirmed':1,'identity_reference':'FICTIONAL-PRIVATE-IDENTITY'});assert status==200 and 'Linked account'in text and len(captures)==0 and 'FICTIONAL-PRIVATE-IDENTITY'not in text
        with sqlite3.connect(db)as c:
            user_id=c.execute('SELECT user_id FROM apprentices WHERE id=?',(ids['apprentice'],)).fetchone()[0]
            assert c.execute('SELECT role,email_verified_at FROM users WHERE id=?',(user_id,)).fetchone()==('apprentice',None)
            encrypted=c.execute('SELECT identity_reference FROM apprentice_onboarding_events').fetchone()[0];assert 'FICTIONAL-PRIVATE'not in encrypted
        passed('Real form creates and links one apprentice account, privately records identity reference and sends no mail')
        post(admin,detail,detail+'/link',{'version':1,'mode':'new','confirmed':1,'identity_reference':'Fictional stale'})
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM apprentice_onboarding_events').fetchone()[0]==1
        passed('Repeated stale create form cannot overwrite the link or create duplicate login')
        assert post(admin,detail,detail+'/invite',{'version':2,'record_version':1})[0]==403 and len(captures)==0
        passed('Mail switch defaults off and actual invite POST sends nothing while disabled')
        stop_backend();env['GTA_STAGING_ONBOARDING_ENABLED']='true';configure();start_backend()
        status,text,_,_=post(admin,detail,detail+'/invite',{'version':2,'record_version':1});assert status==200 and len(captures)==1
        message=email.message_from_bytes(captures[0]);assert message['To']=='newlearner@example.test'
        parts='\n'.join(part.get_payload(decode=True).decode(errors='replace')for part in message.walk()if part.get_content_type()in ['text/plain','text/html'])
        match=re.search(re.escape(origin)+r'/apprentice-invitation/[0-9]+/[A-Za-z0-9]{64}',parts);assert match,'No configured-origin link in fictional capture';invite=match[0][len(origin):]
        assert 'OTHER-PRIVATE-PROFILE'not in parts and 'FICTIONAL-PRIVATE-IDENTITY'not in parts
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT token_hash FROM account_onboarding_tokens').fetchone()[0]!=invite.rsplit('/',1)[1]
        passed('Actual loopback SMTP captures one apprentice invitation with configured HTTPS origin, no password or private identity reference; only token hash stored')
        guest=client();status,text,_,headers=req(guest,invite);assert status==200 and headers['Referrer-Policy']=='no-referrer' and 'newlearner@example.test'not in text
        with sqlite3.connect(db)as c:assert c.execute('SELECT email_verified_at FROM users WHERE id=?',(user_id,)).fetchone()[0]is None
        passed('GET of invitation reveals no mailbox address and cannot confirm account; no-referrer header survives middleware')
        assert req(guest,invite,{'password':'weak','password_confirmation':'weak'})[0]==419
        status,text,_,_=req(guest,invite);status,_,_,_=req(guest,invite,{'_token':csrf(text),'password':'weak','password_confirmation':'weak'});assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM account_onboarding_tokens').fetchone()[0]==1
        passed('Missing CSRF and weak password do not consume the invitation')
        apprentice_password='Fictional-apprentice-'+secrets.token_urlsafe(20)
        status,text,_,_=req(guest,invite);status,text,url,_=req(guest,invite,{'_token':csrf(text),'password':apprentice_password,'password_confirmation':apprentice_password});assert status==200 and url.endswith('/login')
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT email_verified_at FROM users WHERE id=?',(user_id,)).fetchone()[0]is not None
            assert c.execute('SELECT COUNT(*) FROM account_onboarding_tokens').fetchone()[0]==0 and c.execute('SELECT COUNT(*) FROM apprentice_invitation_bindings').fetchone()[0]==0
        assert req(client(),invite)[0]==403
        passed('Explicit password POST confirms mailbox once, consumes token and returns to login without automatic sign-in')
        learner=login('newlearner@example.test',apprentice_password);status,text,_,_=req(learner,'/getting-started');assert status==200 and 'ONBOARD-FICTIONAL'in text and 'OTHER-PRIVATE-PROFILE'not in text and '0/6 confirmed'in text
        passed('Fresh apprentice sign-in shows own six-item getting started checklist without other profiles')
        before=script(f"echo json_encode(App\\Models\\Apprentice::findOrFail({ids['apprentice']})->getAttributes());")
        status,text,_,_=post(learner,'/getting-started','/getting-started/alerts-v1',{'version':0,'confirmed':1});assert status==200 and '1/6 confirmed'in text
        after=script(f"echo json_encode(App\\Models\\Apprentice::findOrFail({ids['apprentice']})->getAttributes());");assert before==after
        passed('Apprentice acknowledgement persists without changing profile, training or alert preference fields')
        post(learner,'/getting-started','/getting-started/alerts-v1',{'version':0,'confirmed':0})
        with sqlite3.connect(db)as c:assert c.execute('SELECT version,confirmed_at FROM apprentice_start_checks').fetchone()[0]==1
        assert post(admin,detail,'/getting-started/details-v1',{'version':0,'confirmed':1})[0]==403
        passed('Stale acknowledgement does not overwrite current state; manager cannot tick for apprentice')
        stop_backend();start_backend();assert '1/6 confirmed'in req(learner,'/getting-started')[1]
        post(learner,'/getting-started','/getting-started/alerts-v1',{'version':1,'confirmed':0})
        with sqlite3.connect(db)as c:assert c.execute('SELECT confirmed_at FROM apprentice_start_checks').fetchone()[0]is None and c.execute('SELECT COUNT(*) FROM apprentice_start_check_events').fetchone()[0]==2
        passed('Encrypted database session and acknowledgement survive restart; apprentice can undo with retained history')
        status,text,_,_=post(admin,detail,detail+'/detach',{'version':2,'identity_reference':'FICTIONAL CORRECTION','confirmed':1});assert status==200
        assert req(learner,'/getting-started')[2].endswith('/login')
        with sqlite3.connect(db)as c:assert c.execute('SELECT user_id FROM apprentices WHERE id=?',(ids['apprentice'],)).fetchone()[0]is None and c.execute('SELECT COUNT(*) FROM apprentice_start_check_events').fetchone()[0]==2
        passed('Wrong-link correction invalidates the old signed-in session immediately and retains historical checklist events')
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('apprentice_intake_batches','apprentice_intake_events','apprentice_setup_cases','apprentice_setup_case_events')").fetchone()[0]==0
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='help_narration_records'").fetchone()[0]==1
            assert c.execute('SELECT COUNT(*) FROM apprentices').fetchone()[0]==2
        assert artisan('migrate','--force').returncode==0
        passed('Disposable latest rollback/reapply removes only four Pack35 tables and retains earlier schema/profile data; real new data requires backup')
        configure('production');start_backend();assert req(admin,'/apprentice-onboarding')[0]==503 and req(client(),invite)[0]==503
        passed('Production access gate still blocks both new account screens and invitation acceptance')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual local client-verified HTTPS and loopback SMTP capture; disposable SQLite/database sessions/cache','localFictionalMessagesCaptured':len(captures),'realDataUsed':False,'externalMessagesSent':False,'actualCloudAccess':False,'actualNativeEngine':False,'actualBrowserOrPhoneTested':False,'actualRemoteSMTPTLS':False};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps(output))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
        if smtp_server:smtp_server.shutdown();smtp_server.server_close()
