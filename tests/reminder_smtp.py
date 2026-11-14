"""Controlled staging reminder delivery over local HTTPS and capture-only SMTP; never accesses Cloud.
Uses disposable SQLite with database sessions/cache. Does not certify a real mail provider, MySQL or S3.
"""
from pathlib import Path
import argparse, base64, email, email.policy, html, socketserver, queue, http.client, http.cookiejar, http.server, json, os, re, secrets, signal, socket, sqlite3, ssl, subprocess, tempfile, threading, time
from urllib.request import Request, HTTPSHandler, HTTPCookieProcessor, build_opener
from urllib.parse import urlencode,urlparse,parse_qs
from urllib.error import HTTPError

parser=argparse.ArgumentParser()
parser.add_argument('--php',default='php');parser.add_argument('--output',required=True)
args=parser.parse_args();project=Path(__file__).resolve().parent.parent
checks=[];server=None;proxy=None;smtp=None;captured=[];smtp_state={'reject':False,'commands':[],'errors':[]}

def passed(name):checks.append({'name':name,'passed':True})
def free_port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]

with tempfile.TemporaryDirectory(prefix='gta-reminder-wire-') as temporary:
    temp=Path(temporary);db=temp/'fictional.sqlite';db.touch();storage=temp/'storage'
    for part in ['app/private','framework/sessions','framework/cache/data','framework/cache/locks','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
    php_port=free_port();tls_port=free_port();origin=f'https://127.0.0.1:{tls_port}'
    private_password='Fictional-private-'+secrets.token_urlsafe(24)
    env=os.environ.copy();env.update(APP_ENV='staging',APP_DEBUG='false',APP_URL=origin,APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),DB_CONNECTION='sqlite',DB_DATABASE=str(db),SESSION_DRIVER='database',SESSION_ENCRYPT='true',SESSION_SECURE_COOKIE='true',CACHE_STORE='database',MAIL_MAILER='array',QUEUE_CONNECTION='sync',GTA_ENABLE_STAGING_PORTAL='true',GTA_ALLOW_INITIAL_ADMIN='true',GTA_SCHEDULE_PORTAL_REMINDERS='false',LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(temp/'config.php'),APP_ROUTES_CACHE=str(temp/'routes.php'))
    env.update(DB_URL='',GTA_ALLOW_INITIAL_ADMIN='false',GTA_STAGING_PASSWORD_RECOVERY_ENABLED='true',GTA_STAGING_RECOVERY_RECIPIENTS='pilot@example.test,failure@example.test',MAIL_MAILER='smtp',MAIL_SCHEME='smtp',MAIL_URL='',MAIL_HOST='127.0.0.1',MAIL_PORT='2525',MAIL_TIMEOUT='5',MAIL_USERNAME='',MAIL_PASSWORD='',MAIL_FROM_ADDRESS='portal@example.test',GTA_TEST_OLD_PASSWORD=private_password)
    env.update(GTA_STAGING_ONBOARDING_ENABLED='true',GTA_STAGING_ONBOARDING_RECIPIENTS='staff@example.test,verify@example.test,failure@example.test')
    env.update(GTA_STAGING_REMINDER_MAIL_ENABLED='true',GTA_STAGING_REMINDER_MAIL_RECIPIENTS='tutor@example.test,failure@example.test,cancel@example.test')
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
    class SMTPHandler(socketserver.StreamRequestHandler):
        def reply(self,line):self.wfile.write((line+'\r\n').encode());self.wfile.flush()
        def handle(self):
            self.reply('220 Fictional loopback capture');recipients=[]
            while True:
                line=self.rfile.readline(8192)
                if not line:return
                value=line.decode(errors='replace').strip();command=value.split(' ',1)[0].upper();smtp_state['commands'].append(command)
                if command in ['EHLO','HELO']:self.reply('250 Fictional capture')
                elif command=='MAIL':
                    recipients=[];self.reply('550 PRIVATE-WIRE-SMTP-ERROR' if smtp_state['reject'] else '250 Sender accepted')
                elif command=='RCPT':recipients.append(value.split(':',1)[1].strip().strip('<>'));self.reply('250 Recipient accepted')
                elif command=='DATA':
                    self.reply('354 Send fictional message');body=[];size=0
                    while True:
                        part=self.rfile.readline(8192)
                        if not part or part==b'.\r\n':break
                        size+=len(part)
                        if size>262144:raise AssertionError('Fictional capture limit exceeded')
                        body.append(part[1:] if part.startswith(b'..') else part)
                    captured.append({'recipients':recipients[:],'body':b''.join(body)});self.reply('250 Captured locally')
                elif command=='QUIT':self.reply('221 Closing');return
                elif command=='RSET':recipients=[];self.reply('250 Reset')
                else:self.reply('250 OK')
    class SMTPServer(socketserver.ThreadingTCPServer):
        allow_reuse_address=True;daemon_threads=True
        def handle_error(self,*args):
            import sys
            smtp_state['errors'].append(sys.exc_info()[0].__name__)
    try:
        smtp=SMTPServer(('127.0.0.1',0),SMTPHandler);env['MAIL_PORT']=str(smtp.server_address[1]);threading.Thread(target=smtp.serve_forever,daemon=True).start()
        configure();assert artisan('migrate','--force').returncode==0,'Disposable migration failed'
        code=r"require 'vendor/autoload.php';$a=require 'bootstrap/app.php';$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();App\Models\User::factory()->create(['email'=>'admin@example.test','role'=>'admin','password'=>getenv('GTA_TEST_OLD_PASSWORD')]);foreach(['tutor@example.test','failure@example.test','cancel@example.test']as$email){$u=App\Models\User::factory()->create(['email'=>$email,'role'=>'tutor','password'=>getenv('GTA_TEST_OLD_PASSWORD')]);$a=App\Models\Apprentice::factory()->create(['tutor_id'=>$u->id,'first_name'=>'PRIVATE-APPRENTICE','last_name'=>'PRIVATE-SURNAME']);$t=App\Models\LearningTask::factory()->create(['apprentice_id'=>$a->id]);$at=now()->subDays(8);$t->status='Awaiting marking';$t->submitted_at=$at;$t->submissions=[['version'=>1,'submitted_at'=>$at->toIso8601String(),'template_hash'=>$t->template['sha256'],'answers'=>['private'=>'PRIVATE-LEARNER-ANSWER'],'decision'=>null]];$t->save();}App\Support\MarkingReminders::generate(true);"
        seeded=subprocess.run([args.php,'-r',code],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert seeded.returncode==0,'Fictional setup failed'
        check=artisan('gta:reminder-mail-check');assert check.returncode==0 and json.loads(check.stdout)['result']=='configuration_checks_only' and not captured
        default=artisan('gta:reminder-mail');assert default.returncode==0 and json.loads(default.stdout)['current_candidates']==3 and not captured
        passed('Preflight and default command are read-only and never send')
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            assert req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password})[0]==200
            return c
        with sqlite3.connect(db)as c:ids=dict(c.execute('SELECT email,id FROM users').fetchall())
        admin=login('admin@example.test');tutor=login('tutor@example.test');guest=client()
        assert req(tutor,'/reminder-mail')[0]==403 and req(guest,'/reminder-mail')[2].endswith('/login')
        passed('Private delivery queue refuses tutor and guest access over real HTTPS')
        status,text,_,_=req(admin,'/reminder-mail');assert status==200 and 'PRIVATE-APPRENTICE'not in text and 'PRIVATE-LEARNER-ANSWER'not in text
        assert req(admin,'/reminder-mail',{'recipient_id':ids['tutor@example.test'],'fingerprint':'a'*64})[0]==419
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM reminder_mail_outbox').fetchone()[0]==0
        passed('Real preparation POST requires CSRF and preview exposes no apprentice details')
        def prepare(address):
            before=len(captured)
            status,text,_,_=req(admin,'/reminder-mail');assert status==200
            forms=re.findall(r'<form method="post" action="[^"]+/reminder-mail">(.*?)</form>',text,re.S)
            form=next(f for f in forms if re.search(r'name="recipient_id" value="'+str(ids[address])+r'"',f))
            fingerprint=re.search(r'name="fingerprint" value="([a-f0-9]{64})"',form)[1]
            status,text,target,_=req(admin,'/reminder-mail',{'_token':csrf(form),'recipient_id':ids[address],'fingerprint':fingerprint});assert status==200 and '/reminder-mail/'in target and len(captured)==before
            return int(urlparse(target).path.rsplit('/',1)[1]),text
        def review(delivery_id,text):
            status,text,_,_=req(admin,f'/reminder-mail/{delivery_id}',{'_token':csrf(text),'action':'review','version':'1','confirmed':'1'});assert status==200
            return text
        first,text=prepare('tutor@example.test')
        rejected=artisan('gta:reminder-mail',f'--send={first}');assert rejected.returncode==1 and json.loads(rejected.stdout)['result']=='not_dispatchable' and not captured
        passed('Prepared but unreviewed delivery cannot dispatch')
        review(first,text);assert not captured
        passed('Explicit browser review changes the record without sending')
        sent=artisan('gta:reminder-mail',f'--send={first}');assert sent.returncode==0 and json.loads(sent.stdout)=={'result':'accepted','mail_attempted':True} and len(captured)==1
        assert captured[0]['recipients']==['tutor@example.test']
        message=email.message_from_bytes(captured[0]['body'],policy=email.policy.default);texts=[]
        for part in message.walk():
            if part.get_content_type()in['text/plain','text/html']:texts.append(part.get_content())
        body=html.unescape(' '.join(texts));assert origin+'/login'in body
        for private in ['PRIVATE-APPRENTICE','PRIVATE-SURNAME','PRIVATE-LEARNER-ANSWER',private_password,'reset-password','staff-invitation']:assert private not in body
        passed('Explicit CLI uses real SMTP only to loopback capture with generic fixed-origin login prompt')
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT state FROM reminder_mail_outbox WHERE id=?',(first,)).fetchone()[0]=='accepted'
            assert c.execute('SELECT COUNT(*) FROM marking_reminders WHERE seen_at IS NOT NULL').fetchone()[0]==0
            assert c.execute("SELECT COUNT(*) FROM learning_tasks WHERE status='Awaiting marking'").fetchone()[0]==3
        again=artisan('gta:reminder-mail',f'--send={first}');assert again.returncode==1 and len(captured)==1
        stop_backend();start_backend();assert artisan('gta:reminder-mail',f'--send={first}').returncode==1 and len(captured)==1
        passed('Committed accepted state survives restart and never repeats or completes source work')
        # Prepare another candidate without assuming a globally empty capture after the first send.
        second,text=prepare('failure@example.test');review(second,text)
        smtp_state['reject']=True;failed=artisan('gta:reminder-mail',f'--send={second}');assert failed.returncode==1 and json.loads(failed.stdout)=={'result':'needs_check','mail_attempted':True} and 'PRIVATE-WIRE-SMTP-ERROR'not in failed.stdout+failed.stderr and len(captured)==1
        assert artisan('gta:reminder-mail',f'--send={second}').returncode==1 and len(captured)==1
        passed('Actual SMTP rejection records a terminal needs-check result and cannot auto-retry')
        smtp_state['reject']=False;third,text=prepare('cancel@example.test')
        status,text,_,_=req(admin,f'/reminder-mail/{third}',{'_token':csrf(text),'action':'cancel','version':'1','confirmed':'1'});assert status==200
        assert artisan('gta:reminder-mail',f'--send={third}').returncode==1 and len(captured)==1
        passed('Administrator cancellation prevents dispatch and preserves delivery history')
        status,text,_,_=req(admin,'/reminder-mail?state=needs_check');assert status==200 and f'Delivery #{second}'in text and 'do not retry'in text
        logs=''.join(q.read_text(errors='ignore')for q in(storage/'logs').glob('*.log'))
        with sqlite3.connect(db)as c:audits=json.dumps(c.execute('SELECT * FROM reminder_mail_events').fetchall());assert c.execute('SELECT COUNT(*) FROM reminder_mail_outbox').fetchone()[0]==3
        for private in [private_password,'PRIVATE-WIRE-SMTP-ERROR','PRIVATE-LEARNER-ANSWER']:assert private not in logs+audits
        passed('Private state filtering works and application logs/audit exclude credentials, answers and SMTP transcript')
        stop_backend();env['GTA_STAGING_REMINDER_MAIL_ENABLED']='false';configure();assert artisan('gta:reminder-mail',f'--send={first}').returncode==1
        start_backend();status,text,_,_=req(admin,'/reminder-mail');assert status==200 and 'Reminder mail is disabled'in text
        stop_backend();configure(mode='production');start_backend();assert req(guest,'/reminder-mail')[0]==503
        passed('Disabled pilot blocks dispatch and production web access stays closed')
        stop_backend();configure()
        with sqlite3.connect(db)as c:rollback_count=c.execute("SELECT COUNT(*) FROM migrations WHERE id >= (SELECT id FROM migrations WHERE migration=?)",('2026_11_14_010000_create_reminder_mail_outbox',)).fetchone()[0]
        assert rollback_count>0 and artisan('migrate:rollback','--step='+str(rollback_count),'--force').returncode==0
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM users').fetchone()[0]==4 and c.execute('SELECT COUNT(*) FROM marking_reminders').fetchone()[0]==3
        assert artisan('migrate','--force').returncode==0
        passed('Outbox and any later fixture migrations roll back and reapply without deleting source reminders or users in disposable SQLite')
        result={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual Laravel/Symfony SMTP to capture-only loopback with local client-verified HTTPS and committed disposable SQLite','externalEmailSent':False,'actualCloudAccess':False,'actualMySqlAccess':False,'realDataUsed':False,'limits':['No native database locking/migration/concurrency certification','No actual provider/remote TLS/DNS/inbox delivery or browser verification','Reservation prevents automatic retries; SMTP/inbox/database completion are not an atomic exactly-once guarantee']};Path(args.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0,'externalEmailSent':False}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks,'externalEmailSent':False},indent=2)+'\n');raise
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
        if smtp:smtp.shutdown();smtp.server_close()
