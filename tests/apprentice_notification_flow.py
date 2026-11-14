"""Fictional Pack24 apprentice reminder workflow workflows over a local TLS proxy; never accesses Laravel Cloud.
Uses disposable SQLite with database sessions/cache. Does not certify MySQL or S3.
"""
from pathlib import Path
import socketserver,email
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

with tempfile.TemporaryDirectory(prefix='gta-apprentice-alerts-') as temporary:
    temp=Path(temporary);db=temp/'fictional.sqlite';db.touch();storage=temp/'storage'
    for part in ['app/private','framework/sessions','framework/cache/data','framework/cache/locks','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
    php_port=free_port();tls_port=free_port();smtp_port=free_port();origin=f'https://127.0.0.1:{tls_port}'
    private_password='Fictional-private-'+secrets.token_urlsafe(24)
    env=os.environ.copy();env.update(APP_ENV='staging',APP_DEBUG='false',APP_URL=origin,APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),DB_CONNECTION='sqlite',DB_DATABASE=str(db),SESSION_DRIVER='database',SESSION_ENCRYPT='true',SESSION_SECURE_COOKIE='true',CACHE_STORE='database',MAIL_MAILER='array',QUEUE_CONNECTION='sync',GTA_ENABLE_STAGING_PORTAL='true',GTA_ALLOW_INITIAL_ADMIN='true',GTA_SCHEDULE_PORTAL_REMINDERS='false',LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(temp/'config.php'),APP_ROUTES_CACHE=str(temp/'routes.php'))
    env['GTA_SOURCE_TEST_PASSWORD']=private_password
    env.update(GTA_APPRENTICE_ALERTS_ENABLED='true',GTA_APPRENTICE_ALERTS_SCHEDULED='false',GTA_APPRENTICE_ALERT_EMAIL_ENABLED='true',GTA_APPRENTICE_ALERT_SMS_ENABLED='false',GTA_APPRENTICE_ALERT_EMAIL_RECIPIENTS='learner@example.test,second@example.test',GTA_APPRENTICE_ALERT_MAX_ATTEMPTS_24H='50',MAIL_MAILER='smtp',MAIL_HOST='127.0.0.1',MAIL_PORT=str(smtp_port),MAIL_SCHEME='smtp',MAIL_USERNAME='',MAIL_PASSWORD='',MAIL_TIMEOUT='5',MAIL_FROM_ADDRESS='portal@example.test')
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
    captures=[];reservation_proof=[];smtp_mode={'drop_once':False};smtp_server=None
    class SMTP(socketserver.StreamRequestHandler):
        def handle(self):
            self.wfile.write(b'220 fictional-local-smtp ESMTP\r\n');self.wfile.flush()
            while True:
                line=self.rfile.readline()
                if not line:return
                command=line.decode('ascii','replace').strip().upper()
                if command.startswith(('EHLO','HELO')):self.wfile.write(b'250-fictional-local-smtp\r\n250 8BITMIME\r\n')
                elif command.startswith(('MAIL FROM','RCPT TO','RSET','NOOP')):self.wfile.write(b'250 OK\r\n')
                elif command=='DATA':
                    self.wfile.write(b'354 End with dot\r\n');self.wfile.flush();parts=[]
                    while True:
                        part=self.rfile.readline()
                        if part in [b'.\r\n',b'.\n',b'']:break
                        parts.append(part[1:]if part.startswith(b'..')else part)
                    raw=b''.join(parts);captures.append(raw);message=email.message_from_bytes(raw);delivery=message.get('X-GTA-Delivery-ID')
                    with sqlite3.connect(db)as connection:row=connection.execute('SELECT state,reserved_at FROM apprentice_alert_deliveries WHERE id=?',(delivery,)).fetchone()
                    reservation_proof.append(bool(row and row[0]=='Dispatching' and row[1]))
                    if smtp_mode['drop_once']:smtp_mode['drop_once']=False;return
                    self.wfile.write(b'250 Accepted into fictional capture\r\n')
                elif command=='QUIT':self.wfile.write(b'221 Bye\r\n');self.wfile.flush();return
                else:self.wfile.write(b'502 Unsupported\r\n')
                self.wfile.flush()
    class SMTPServer(socketserver.ThreadingTCPServer):allow_reuse_address=True;daemon_threads=True
    try:
        smtp_server=SMTPServer(('127.0.0.1',smtp_port),SMTP);threading.Thread(target=smtp_server.serve_forever,daemon=True).start()
        configure();assert artisan('migrate','--force').returncode==0,'Disposable migration failed'
        seed=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$users=[];foreach(['admin@example.test'=>'admin','tutor@example.test'=>'tutor','learner@example.test'=>'apprentice','second@example.test'=>'apprentice']as$email=>$role){$users[$email]=App\Models\User::factory()->create(['email'=>$email,'role'=>$role,'password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);}$ids=[];foreach(['learner@example.test','second@example.test']as$email){$a=App\Models\Apprentice::factory()->create(['reference'=>$email==='learner@example.test'?'REM-FIRST':'REM-SECOND','user_id'=>$users[$email]->id]);$a->alert_channel='email';$a->alerts_enabled=$email==='learner@example.test';$a->save();App\Models\ProgrammeOutcome::factory()->create(['apprentice_id'=>$a->id,'context'=>['status'=>'Active','sourceRef'=>'Fictional enrolment']]);App\Models\LearningTask::factory()->create(['apprentice_id'=>$a->id,'title'=>'PRIVATE-TASK-TITLE-NOT-FOR-MESSAGE','due_date'=>today()->subDay()]);$ids[$email]=$a->id;}echo json_encode(['apprentices'=>$ids,'users'=>array_map(fn($u)=>$u->id,$users)]);"
        seeded=subprocess.run([args.php,'-r',seed],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert seeded.returncode==0,'Fictional apprentice notification setup failed';ids=json.loads(seeded.stdout)
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
        admin=login('admin@example.test');learner=login('learner@example.test');second=login('second@example.test');tutor=login('tutor@example.test')
        ops='/apprentice-reminder-delivery';inbox='/my-reminders'
        status,text,_,headers=req(admin,ops+'?preview=1');assert status==200 and 'Current source preview'in text and 'REM-FIRST'in text and 'no-store'in headers['Cache-Control'] and not captures
        assert req(tutor,ops)[0]==403 and req(learner,ops)[0]==403 and req(admin,inbox)[0]==403
        passed('Actual management preview is private/read-only; learner/tutor cannot operate delivery and management cannot impersonate apprentice inbox')
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM apprentice_alert_deliveries').fetchone()[0]==0
        default=artisan('gta:apprentice-alerts');assert default.returncode==0;check=json.loads(default.stdout);assert not check['writes_performed'] and not check['sends_performed'] and 'learner@example.test'not in default.stdout
        passed('Default CLI diagnostics return safe configuration checks without querying contacts, creating rows or connecting to SMTP')
        assert req(admin,ops,{'confirmed':1})[0]==419
        passed('Preparation mutation without actual CSRF is refused')
        status,_,_,_=post(admin,ops,ops,{'confirmed':1});assert status==200 and not captures
        with sqlite3.connect(db)as c:first=c.execute('SELECT id,state,reserved_at FROM apprentice_alert_deliveries WHERE recipient_id=?',(ids['users']['learner@example.test'],)).fetchone();assert first and first[1:]==('Prepared',None)
        passed('Explicit preparation creates one current digest only for opted-in apprentice, without any transport call')
        status,_,_,_=post(learner,'/account/alerts','/account/alerts',{'alert_revision':0,'alert_channel':'email','alerts_enabled':0,'alert_mobile':''});assert status==200
        status,_,_,_=post(admin,ops,ops+'/'+first[0]+'/send',{'confirmed':1});assert status==200 and not captures
        with sqlite3.connect(db)as c:assert c.execute('SELECT state,reserved_at FROM apprentice_alert_deliveries WHERE id=?',(first[0],)).fetchone()==('Suppressed',None)
        passed('Opt-out after preparation stops the actual send before reservation and before SMTP connection')
        status,_,_,_=post(learner,'/account/alerts','/account/alerts',{'alert_revision':1,'alert_channel':'email','alerts_enabled':1,'alert_mobile':''});assert status==200
        status,_,_,_=post(admin,ops,ops,{'confirmed':1});assert status==200
        status,_,_,_=post(admin,ops,'/apprentice-reminder-control',{'version':1,'paused':1,'note':'PRIVATE-OPS-MAINTENANCE'});assert status==200
        blocked=artisan('gta:apprentice-alerts','--send');assert blocked.returncode==0 and not captures
        with sqlite3.connect(db)as c:assert c.execute('SELECT state,reserved_at FROM apprentice_alert_deliveries WHERE id=?',(first[0],)).fetchone()==('Prepared',None)
        passed('Current apprentice can opt back in; unsent slot refreshes, while durable management pause blocks new attempts without changing consent')
        status,_,_,_=post(admin,ops,'/apprentice-reminder-control',{'version':2,'paused':0,'note':'Fictional resumed'});assert status==200
        sent=artisan('gta:apprentice-alerts','--send');assert sent.returncode==0 and len(captures)==1 and reservation_proof==[True]
        with sqlite3.connect(db)as c:row=c.execute('SELECT state,reserved_at,completed_at,provider_id,source,history FROM apprentice_alert_deliveries WHERE id=?',(first[0],)).fetchone();assert row[0]=='Accepted' and row[1] and row[2] and len(row[3])==64 and 'learner@example.test'not in json.dumps(row) and 'PRIVATE-TASK-TITLE'not in json.dumps(row)
        passed('Real local SMTP capture accepts one generic email; independent SQLite connection observes committed reservation before SMTP accepts DATA')
        message=email.message_from_bytes(captures[0]);text=message.get_payload(decode=True).decode();assert '/my-next-step'in text and 'PRIVATE-TASK-TITLE'not in text and 'REM-FIRST'not in text and message['To']=='learner@example.test'
        assert 'learner@example.test'not in sent.stdout and 'PRIVATE-OPS'not in sent.stdout
        passed('SMTP envelope uses verified login address; message contains only generic portal link and management/CLI output does not print contacts or provider secrets')
        assert artisan('gta:apprentice-alerts','--run').returncode==0 and len(captures)==1
        passed('Repeated prepare/send run cannot resend accepted current-cycle slot')
        status,text,_,_=req(learner,inbox);assert status==200 and 'Task past its recorded deadline'in text and 'Accepted'in text and 'PRIVATE-OPS-MAINTENANCE'not in text
        status,_,_,_=post(learner,inbox,inbox+'/'+first[0]+'/seen',{});assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT seen_at FROM apprentice_alert_deliveries WHERE id=?',(first[0],)).fetchone()[0] and c.execute('SELECT status FROM learning_tasks WHERE apprentice_id=?',(ids['apprentices']['learner@example.test'],)).fetchone()[0]=='Draft'
        assert 'Task past its recorded deadline'not in req(second,inbox)[1]
        passed('Apprentice sees own daily inbox and marks read without completing work; another apprentice cannot see that digest or private operations notes')
        status,_,_,_=post(second,'/account/alerts','/account/alerts',{'alert_revision':0,'alert_channel':'email','alerts_enabled':1,'alert_mobile':''});assert status==200
        status,_,_,_=post(admin,ops,ops,{'confirmed':1});assert status==200
        smtp_mode['drop_once']=True;uncertain=artisan('gta:apprentice-alerts','--send');assert uncertain.returncode==0 and len(captures)==2 and all(reservation_proof)
        with sqlite3.connect(db)as c:second_delivery=c.execute('SELECT id,state,reserved_at FROM apprentice_alert_deliveries WHERE recipient_id=?',(ids['users']['second@example.test'],)).fetchone();assert second_delivery and second_delivery[1]=='Uncertain' and second_delivery[2]
        assert artisan('gta:apprentice-alerts','--run').returncode==0 and len(captures)==2
        passed('Actual SMTP disconnect after DATA records uncertainty and retained reservation; next CLI run performs no duplicate transport attempt')
        status,_,_,_=post(admin,ops,ops+'/'+second_delivery[0]+'/reconcile',{'note':'PRIVATE-PROVIDER-INVESTIGATION-NOTE'});assert status==200 and len(captures)==2
        status,text,_,_=req(second,inbox);assert status==200 and 'Uncertain'in text and 'PRIVATE-PROVIDER-INVESTIGATION-NOTE'not in text
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM apprentice_alert_deliveries WHERE id=?',(second_delivery[0],)).fetchone()[0]=='Uncertain'
        passed('Management reconciliation adds an investigation record without resetting attempt or exposing private note in apprentice inbox')
        stop_backend();start_backend();assert 'Accepted'in req(learner,inbox)[1] and 'Uncertain'in req(second,inbox)[1] and artisan('gta:apprentice-alerts','--send').returncode==0 and len(captures)==2
        passed('Delivery states, read receipts, session binding and no-retry behavior persist across HTTP/CLI process restart')
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('electronic_review_packets','electronic_review_signers','electronic_review_events','review_signing_authorities')").fetchone()[0]==0
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('employer_portal_actions','gateway_readiness_plans','review_preparation_briefs','apprentice_mobile_challenges')").fetchone()[0]==4
            assert c.execute('SELECT status FROM learning_tasks ORDER BY id LIMIT 1').fetchone()[0]=='Draft'
        assert artisan('migrate','--force').returncode==0
        passed('Disposable latest migration round-trip removes only Pack26 signing tables; prior reminder/employer/Gateway/review/mobile/task work remains')
        configure('production');start_backend();assert req(admin,ops)[0]==503 and req(learner,inbox)[0]==503 and len(captures)==2
        passed('Existing production gate remains enforced; all captured messages used fictional local SMTP only')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual local client-verified HTTPS and local SMTP DATA/accept/disconnect capture with disposable SQLite/database sessions/cache','localFictionalMessagesCaptured':len(captures),'independentReservationProofs':reservation_proof,'realDataUsed':False,'externalMessagesSent':False,'actualCloudAccess':False,'actualNativeEngine':False,'actualBrowserOrPhoneTested':False,'actualRemoteSMTPTLS':False,'actualVonageOrHandset':False};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps(output))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
        if smtp_server:smtp_server.shutdown();smtp_server.server_close()
