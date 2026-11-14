"""Support-plan reminders, setup guidance and controlled mail over local HTTPS and capture-only SMTP; never accesses Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-support-review-wire-') as temporary:
    temp=Path(temporary);db=temp/'fictional.sqlite';db.touch();storage=temp/'storage'
    for part in ['app/private','framework/sessions','framework/cache/data','framework/cache/locks','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
    php_port=free_port();tls_port=free_port();origin=f'https://127.0.0.1:{tls_port}'
    private_password='Fictional-private-'+secrets.token_urlsafe(24)
    env=os.environ.copy();env.update(APP_ENV='staging',APP_DEBUG='false',APP_URL=origin,APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),DB_CONNECTION='sqlite',DB_DATABASE=str(db),SESSION_DRIVER='database',SESSION_ENCRYPT='true',SESSION_SECURE_COOKIE='true',CACHE_STORE='database',MAIL_MAILER='array',QUEUE_CONNECTION='sync',GTA_ENABLE_STAGING_PORTAL='true',GTA_ALLOW_INITIAL_ADMIN='true',GTA_SCHEDULE_PORTAL_REMINDERS='false',LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(temp/'config.php'),APP_ROUTES_CACHE=str(temp/'routes.php'))
    env.update(DB_URL='',GTA_ALLOW_INITIAL_ADMIN='false',GTA_STAGING_PASSWORD_RECOVERY_ENABLED='true',GTA_STAGING_RECOVERY_RECIPIENTS='pilot@example.test,failure@example.test',MAIL_MAILER='smtp',MAIL_SCHEME='smtp',MAIL_URL='',MAIL_HOST='127.0.0.1',MAIL_PORT='2525',MAIL_TIMEOUT='5',MAIL_USERNAME='',MAIL_PASSWORD='',MAIL_FROM_ADDRESS='portal@example.test',GTA_TEST_OLD_PASSWORD=private_password)
    env.update(GTA_STAGING_ONBOARDING_ENABLED='true',GTA_STAGING_ONBOARDING_RECIPIENTS='staff@example.test,verify@example.test,failure@example.test')
    env.update(GTA_STAGING_REMINDER_MAIL_ENABLED='true',GTA_STAGING_REMINDER_MAIL_RECIPIENTS='tutor@example.test,failure@example.test,cancel@example.test')
    env.update(GTA_STAGING_REMINDER_MAIL_RECIPIENTS='support@example.test',GTA_SCHEDULE_SUPPORT_PLAN_REMINDERS='false',GTA_MFA_ENROLMENT_ENABLED='false',GTA_MFA_PILOT_EMAILS='')
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
        seed=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$users=[];foreach(['admin@example.test'=>'admin','lead@example.test'=>'inclusion_lead','support@example.test'=>'inclusion_member','other@example.test'=>'inclusion_member','tutor@example.test'=>'tutor','learner@example.test'=>'apprentice']as$email=>$role){$users[$email]=App\Models\User::factory()->create(['email'=>$email,'role'=>$role,'password'=>getenv('GTA_TEST_OLD_PASSWORD')]);}$a=App\Models\Apprentice::factory()->create(['reference'=>'FICTIONAL-SUPPORT-COHORT','first_name'=>'PRIVATE-APPRENTICE','last_name'=>'PRIVATE-SURNAME']);$a->user_id=$users['learner@example.test']->id;$a->save();$fields=['type'=>'full','frequency'=>'Every 4 weeks','funding'=>'not_funded','referral'=>today()->subDays(11)->toDateString(),'review'=>today()->subDay()->toDateString(),'referred'=>'PRIVATE-REFERRAL','source'=>'PRIVATE-SOURCE','barriers'=>'PRIVATE-BARRIERS','strengths'=>'PRIVATE-STRENGTHS','view'=>'PRIVATE-VIEW','support'=>'PRIVATE-SUPPORT-CONTENT','rationale'=>'PRIVATE-RATIONALE'];$p=App\Models\SupportPlan::create(['apprentice_id'=>$a->id,'concern_ids'=>[],'draft'=>['fields'=>[...$fields,'review'=>'2035-01-01'],'codes'=>[]]]);$p->status='Active';$p->reviews=[];$p->active_plan=['approved_at'=>now()->subDays(10)->toIso8601String(),'approved_by'=>$users['lead@example.test']->id,'fields'=>$fields,'codes'=>[],'concern_ids'=>[],'reason'=>'PRIVATE-APPROVAL','draft_hash'=>'fictional-existing-approved-snapshot'];$p->save();echo json_encode(['plan'=>$p->id,'apprentice'=>$a->id,'users'=>array_map(fn($u)=>$u->id,$users)]);"
        seeded=subprocess.run([args.php,'-r',seed],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert seeded.returncode==0,'Fictional support setup failed';ids=json.loads(seeded.stdout);plan=ids['plan'];person=ids['apprentice'];plan_path=f'/apprentices/{person}/support-plans/{plan}';assign=f'/support-plans/{plan}/review-coordinator'
        with sqlite3.connect(db)as c:original_active=c.execute('SELECT active_plan FROM support_plans WHERE id=?',(plan,)).fetchone()[0]
        preview=artisan('gta:support-plan-reminders');assert preview.returncode==0 and json.loads(preview.stdout)['counts']['coordinator_checks']==1 and not captured
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM support_plan_reminders').fetchone()[0]==0
        passed('Default command previews the unassigned due plan without guessing an owner, writing a reminder or sending mail')
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
        admin=login('admin@example.test');lead=login('lead@example.test');owner=login('support@example.test');other=login('other@example.test');tutor=login('tutor@example.test');learner=login('learner@example.test')
        status,text,_,_=req(admin,'/portal-setup');assert status==200 and 'ordinary Staff screen does not create'in text and 'one primary role per account'in text and '/directories/staff'in text
        assert req(learner,'/portal-setup')[0]==403 and req(tutor,'/portal-setup')[0]==403
        status,text,_,_=req(admin,'/help');assert 'Read-aloud voice'in text and 'Nothing is read automatically'in text and 'Set up your account'in text
        status,text,_,_=req(learner,'/help');assert 'Read-aloud voice'in text and 'Set up your account'not in text
        passed('Admin setup guide uses real staff/assignment links and privileged-account limits; role-scoped help exposes optional voice choice without claiming an installed accent')
        status,text,_,_=req(lead,plan_path);assert status==200 and 'Review coordinator'in text
        assert req(lead,assign,{'version':'1','coordinator':str(ids['users']['support@example.test'])})[0]==419
        assert req(owner,assign,{'_token':csrf(req(owner,plan_path)[1]),'version':'1','coordinator':str(ids['users']['support@example.test'])})[0]==403
        status,text,_,_=post(lead,plan_path,assign,{'version':'1','coordinator':str(ids['users']['support@example.test'])});assert status==200
        with sqlite3.connect(db)as c:
            row=c.execute('SELECT active_plan,reviews,review_owner_id,review_owner_revision,version FROM support_plans WHERE id=?',(plan,)).fetchone();assert row[0]==original_active and json.loads(row[1])==[] and row[2]==ids['users']['support@example.test'] and row[3:]==(1,2)
        passed('Coordinator assignment requires real CSRF, lead authority and current version; approved support/review history remains unchanged')
        assert artisan('gta:support-plan-reminders','--record').returncode==0
        duplicate=json.loads(artisan('gta:support-plan-reminders','--record').stdout);assert duplicate['counts']['existing']==1
        with sqlite3.connect(db)as c:
            reminder=c.execute('SELECT id,source FROM support_plan_reminders').fetchone();first=reminder[0];assert 'PRIVATE-'not in reminder[1] and c.execute('SELECT COUNT(*) FROM support_plan_reminders').fetchone()[0]==1
        assert not captured
        status,text,_,_=req(owner,'/support-review-reminders');assert status==200 and 'FICTIONAL-SUPPORT-COHORT'in text and 'PRIVATE-SUPPORT-CONTENT'not in text
        assert req(tutor,'/support-review-reminders')[0]==403 and req(learner,'/support-review-reminders')[0]==403 and 'FICTIONAL-SUPPORT-COHORT'not in req(other,'/support-review-reminders')[1]
        passed('Explicit recording is deduplicated, metadata-only and private to the coordinator; recording sends no mail')
        def prepare():
            before=len(captured);status,text,_,_=req(admin,'/reminder-mail');assert status==200 and 'PRIVATE-SUPPORT-CONTENT'not in text
            forms=re.findall(r'<form method="post" action="[^"]+/reminder-mail">(.*?)</form>',text,re.S)
            form=next(f for f in forms if re.search(r'name="recipient_id" value="'+str(ids['users']['support@example.test'])+r'"',f))
            fingerprint=re.search(r'name="fingerprint" value="([a-f0-9]{64})"',form)[1]
            status,text,target,_=req(admin,'/reminder-mail',{'_token':csrf(form),'recipient_id':str(ids['users']['support@example.test']),'fingerprint':fingerprint});assert status==200 and len(captured)==before
            return int(urlparse(target).path.rsplit('/',1)[1]),text
        def review(identifier,text):
            status,text,_,_=req(admin,f'/reminder-mail/{identifier}',{'_token':csrf(text),'action':'review','version':'1','confirmed':'1'});assert status==200
        delivery,text=prepare();assert artisan('gta:reminder-mail',f'--send={delivery}').returncode==1 and not captured
        review(delivery,text);assert not captured
        sent=artisan('gta:reminder-mail',f'--send={delivery}');assert sent.returncode==0 and len(captured)==1 and captured[0]['recipients']==['support@example.test']
        message=email.message_from_bytes(captured[0]['body'],policy=email.policy.default);body=html.unescape(' '.join(part.get_content()for part in message.walk()if part.get_content_type()in['text/plain','text/html']))
        assert origin+'/login'in body
        for private in ['PRIVATE-','FICTIONAL-SUPPORT-COHORT',private_password,'/support-plans/','review-coordinator']:assert private not in body
        assert artisan('gta:reminder-mail',f'--send={delivery}').returncode==1 and len(captured)==1
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT seen_at FROM support_plan_reminders WHERE id=?',(first,)).fetchone()[0]is None
            assert json.loads(c.execute('SELECT reviews FROM support_plans WHERE id=?',(plan,)).fetchone()[0])==[]
        passed('Existing prepare/review/explicit-dispatch pilot sends once only to loopback capture; message is generic with no learner/plan details and completes no review')
        status,text,_,_=post(owner,'/support-review-reminders',f'/support-review-reminders/{first}/seen',{});assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT seen_at FROM support_plan_reminders WHERE id=?',(first,)).fetchone()[0]is not None and json.loads(c.execute('SELECT reviews FROM support_plans WHERE id=?',(plan,)).fetchone()[0])==[]
        passed('Actual acknowledgement marks only the prompt seen, leaving formal review records untouched')
        today=time.strftime('%Y-%m-%d',time.gmtime())
        status,text,_,_=post(owner,plan_path,f'/support-plans/{plan}/review',{'version':'2','date':today,'next':today,'baseline':'PRIVATE-BASELINE','delivered':'PRIVATE-DELIVERED','impact':'PRIVATE-IMPACT','evidence':'PRIVATE-EVIDENCE','direction':'continue','notes':'PRIVATE-REVIEW-NOTES'});assert status==200
        generated=json.loads(artisan('gta:support-plan-reminders','--record').stdout);assert generated['counts']['created']==1
        with sqlite3.connect(db)as c:second=c.execute('SELECT MAX(id) FROM support_plan_reminders').fetchone()[0];assert second!=first
        pending,text=prepare();review(pending,text)
        status,text,_,_=post(lead,plan_path,assign,{'version':'3','coordinator':str(ids['users']['other@example.test'])});assert status==200
        assert artisan('gta:reminder-mail',f'--send={pending}').returncode==1 and len(captured)==1
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM reminder_mail_outbox WHERE id=?',(pending,)).fetchone()[0]=='stale'
        passed('A formal review starts a new exact due cycle; subsequent coordinator change makes the reviewed old email stale before SMTP')
        status,text,_,_=post(owner,'/support-review-reminders',f'/support-review-reminders/{second}/dismiss',{});assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT dismissed_at FROM support_plan_reminders WHERE id=?',(second,)).fetchone()[0]is not None
        passed('Outdated owned prompt can be dismissed without acknowledging it as a current review')
        stop_backend();start_backend();assert req(owner,'/support-review-reminders')[0]==200
        passed('Encrypted database sessions and private prompt history survive a PHP restart')
        stop_backend()
        with sqlite3.connect(db)as c:
            cols=[row[1]for row in c.execute('PRAGMA table_info(support_plans)')if row[1]not in ['review_owner_id','review_owner_revision']];select_cols=','.join(cols);before=c.execute('SELECT '+select_cols+' FROM support_plans').fetchall();accounts=c.execute('SELECT * FROM users ORDER BY id').fetchall()
        assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT '+select_cols+' FROM support_plans').fetchall()==before and c.execute('SELECT * FROM users ORDER BY id').fetchall()==accounts
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('support_plan_reminders','support_plan_reminder_events')").fetchone()[0]==0
        assert artisan('migrate','--force').returncode==0
        passed('Pack11 migration rolls back/reapplies on disposable SQLite while preserving original plan/review/history/account fields and earlier MFA tables')
        configure('production');start_backend();assert req(owner,'/support-review-reminders')[0]==503 and req(admin,'/portal-setup')[0]==503
        passed('Existing production gate remains enforced on all new pages')
        assert not smtp_state['errors']
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Local client-verified HTTPS plus capture-only loopback SMTP, disposable SQLite sessions/cache and cached configuration','smtp_messages_captured':len(captured),'externalMessagesSent':False,'realDataUsed':False,'actualCloudAccess':False,'actualNativeEngine':False,'actualBrowserOrYorkshireVoiceTested':False,'limits':['No actual provider/inbox/Cloud/native concurrency acceptance','No actual voice/accent/browser/mobile acceptance','Synthetic pre-existing approved plan; existing tests separately cover source approval workflow','No account was provisioned for Jon; named privileged account needs Martin on the actual target']};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0,'loopback_messages':len(captured)}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
        if smtp:smtp.shutdown();smtp.server_close()
