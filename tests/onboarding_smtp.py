"""Controlled staging onboarding over local HTTPS and capture-only SMTP; never accesses Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-onboarding-wire-') as temporary:
    temp=Path(temporary);db=temp/'fictional.sqlite';db.touch();storage=temp/'storage'
    for part in ['app/private','framework/sessions','framework/cache/data','framework/cache/locks','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
    php_port=free_port();tls_port=free_port();origin=f'https://127.0.0.1:{tls_port}'
    private_password='Fictional-private-'+secrets.token_urlsafe(24)
    env=os.environ.copy();env.update(APP_ENV='staging',APP_DEBUG='false',APP_URL=origin,APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),DB_CONNECTION='sqlite',DB_DATABASE=str(db),SESSION_DRIVER='database',SESSION_ENCRYPT='true',SESSION_SECURE_COOKIE='true',CACHE_STORE='database',MAIL_MAILER='array',QUEUE_CONNECTION='sync',GTA_ENABLE_STAGING_PORTAL='true',GTA_ALLOW_INITIAL_ADMIN='true',GTA_SCHEDULE_PORTAL_REMINDERS='false',LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(temp/'config.php'),APP_ROUTES_CACHE=str(temp/'routes.php'))
    env.update(DB_URL='',GTA_ALLOW_INITIAL_ADMIN='false',GTA_STAGING_PASSWORD_RECOVERY_ENABLED='true',GTA_STAGING_RECOVERY_RECIPIENTS='pilot@example.test,failure@example.test',MAIL_MAILER='smtp',MAIL_SCHEME='smtp',MAIL_URL='',MAIL_HOST='127.0.0.1',MAIL_PORT='2525',MAIL_TIMEOUT='5',MAIL_USERNAME='',MAIL_PASSWORD='',MAIL_FROM_ADDRESS='portal@example.test',GTA_TEST_OLD_PASSWORD=private_password)
    env.update(GTA_STAGING_ONBOARDING_ENABLED='true',GTA_STAGING_ONBOARDING_RECIPIENTS='staff@example.test,verify@example.test,failure@example.test')
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
        code=r"require 'vendor/autoload.php';$a=require 'bootstrap/app.php';$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();foreach(['admin@example.test'=>'admin','staff@example.test'=>'tutor','verify@example.test'=>'staff','failure@example.test'=>'mentor','outside@example.test'=>'tutor']as$email=>$role){App\Models\User::factory()->create(['email'=>$email,'role'=>$role,'email_verified_at'=>$role==='admin'?now():null,'password'=>getenv('GTA_TEST_OLD_PASSWORD')]);}"
        seeded=subprocess.run([args.php,'-r',code],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert seeded.returncode==0,'Fictional setup failed'
        check=artisan('gta:onboarding-check');assert check.returncode==0 and json.loads(check.stdout)['result']=='configuration_checks_only' and not captured
        passed('Onboarding preflight sends nothing on the configured loopback pilot')
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(email_value,password=private_password):
            session=client();status,text,_,_=req(session,'/login');assert status==200
            assert req(session,'/login',{'_token':csrf(text),'email':email_value,'password':password})[0]==200
            return session
        def capture_link(index,path):
            message=email.message_from_bytes(captured[index]['body'],policy=email.policy.default);texts=[]
            for part in message.walk():
                if part.get_content_type()in['text/plain','text/html']:texts.append(part.get_content())
            links=re.findall(re.escape(origin)+path+r'[^\s<>"\)]+',html.unescape(' '.join(texts)));assert links,'Expected account link missing'
            return urlparse(links[0]).path
        with sqlite3.connect(db)as c:
            ids=dict(c.execute('SELECT email,id FROM users').fetchall())
        admin=login('admin@example.test');staff_old=login('staff@example.test');guest=client();verifier=login('verify@example.test')
        assert req(staff_old,'/staff-onboarding')[0]==403 and req(guest,'/staff-onboarding')[2].endswith('/login')
        passed('Real HTTP onboarding queue denies staff and guests')
        status,text,_,_=req(admin,'/staff-onboarding');assert status==200 and 'staff@example.test'in text
        invite_path=f"/staff-onboarding/{ids['staff@example.test']}/invite"
        assert req(admin,invite_path,{'record_version':'1'})[0]==419 and not captured
        passed('Invitation POST requires a real CSRF token before sending')
        status,text,_,_=req(admin,invite_path,{'_token':csrf(text),'record_version':'1'});assert status==200 and len(captured)==1 and captured[0]['recipients']==['staff@example.test']
        path=capture_link(0,r'/staff-invitation/');raw_token=path.rsplit('/',1)[1]
        with sqlite3.connect(db)as c:stored=c.execute("SELECT token_hash FROM account_onboarding_tokens WHERE purpose='invite'").fetchone()[0]
        assert stored!=raw_token and len(stored)==64 and private_password not in captured[0]['body'].decode(errors='ignore')
        passed('Administrator invitation uses actual SMTP capture and stores only token digest')
        status,text,_,headers=req(guest,path);assert status==200 and headers.get('Referrer-Policy')=='no-referrer'
        with sqlite3.connect(db)as c:assert c.execute('SELECT email_verified_at FROM users WHERE id=?',(ids['staff@example.test'],)).fetchone()[0]is None
        passed('Opening invitation is read-only and has no-referrer protection')
        new_password='Fictional-invite-'+secrets.token_urlsafe(20)
        status,text,target,_=req(guest,path,{'_token':csrf(text),'password':new_password,'password_confirmation':new_password});assert status==200 and target==origin+'/login'
        with sqlite3.connect(db)as c:
            role,active,version,verified=c.execute('SELECT role,active,auth_version,email_verified_at FROM users WHERE id=?',(ids['staff@example.test'],)).fetchone();assert(role,active,version)==('tutor',1,2) and verified
            assert c.execute('SELECT COUNT(*) FROM account_onboarding_tokens').fetchone()[0]==0
        assert req(guest,path)[0]==403
        passed('Invitation acceptance sets password and mailbox confirmation once, with no role escalation')
        assert req(staff_old,'/account/password')[2]==origin+'/login'
        passed('Invitation acceptance invalidates existing database-authenticated sessions')
        stop_backend();start_backend();new_session=login('staff@example.test',new_password);assert req(new_session,'/account/password')[0]==200
        passed('Invited staff can sign in with new password after PHP restart')
        status,text,_,_=req(verifier,'/account/email');assert status==200
        status,text,_,_=req(verifier,'/account/email',{'_token':csrf(text)});assert status==200 and len(captured)==2 and captured[1]['recipients']==['verify@example.test']
        verification_path=capture_link(1,r'/account/email/confirm/');verification_token=verification_path.rsplit('/',1)[1]
        assert req(new_session,verification_path)[0]==403
        status,text,_,headers=req(verifier,verification_path);assert status==200 and headers.get('Referrer-Policy')=='no-referrer'
        with sqlite3.connect(db)as c:assert c.execute('SELECT email_verified_at FROM users WHERE id=?',(ids['verify@example.test'],)).fetchone()[0]is None
        status,text,target,_=req(verifier,verification_path,{'_token':csrf(text)});assert status==200 and target==origin+'/login'
        with sqlite3.connect(db)as c:assert c.execute('SELECT email_verified_at FROM users WHERE id=?',(ids['verify@example.test'],)).fetchone()[0]
        passed('SMTP verification requires the matching signed-in account and explicit POST confirmation')
        smtp_state['reject']=True;status,text,_,_=req(admin,'/staff-onboarding');failure_path=f"/staff-onboarding/{ids['failure@example.test']}/invite"
        status,text,_,_=req(admin,failure_path,{'_token':csrf(text),'record_version':'1'});assert status==200 and 'could not be prepared'in text and 'PRIVATE-WIRE-SMTP-ERROR'not in text and len(captured)==2
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM account_onboarding_tokens').fetchone()[0]==0
        passed('Actual SMTP rejection rolls back invitation and hides provider error')
        smtp_state['reject']=False;status,text,_,_=req(admin,'/staff-onboarding');status,text,_,_=req(admin,failure_path,{'_token':csrf(text),'record_version':'1'});assert status==200 and len(captured)==3
        revoke_path=capture_link(2,r'/staff-invitation/');status,text,_,_=req(admin,'/staff-onboarding');assert req(admin,f"/staff-onboarding/{ids['failure@example.test']}/revoke",{'_token':csrf(text)})[0]==200;assert req(guest,revoke_path)[0]==403
        passed('Administrator revocation makes a captured invitation unusable over real HTTP')
        logs=''.join(q.read_text(errors='ignore')for q in(storage/'logs').glob('*.log'))
        with sqlite3.connect(db)as c:audits=json.dumps(c.execute('SELECT * FROM account_access_events').fetchall())
        for secret in [raw_token,verification_token,new_password,private_password,'PRIVATE-WIRE-SMTP-ERROR']:assert secret not in logs+audits
        passed('Application logs and account audit contain no link tokens, passwords or SMTP transcript')
        stop_backend();env['GTA_STAGING_ONBOARDING_ENABLED']='false';configure();start_backend();status,text,_,_=req(admin,'/staff-onboarding');assert status==200 and 'Sending and acceptance are blocked'in text and 'Send staff invitation'not in text
        stop_backend();configure(mode='production');start_backend();assert req(guest,path)[0]==503
        passed('Disabling onboarding blocks sends and production gate stays closed')
        stop_backend();configure()
        with sqlite3.connect(db)as c:rollback_count=c.execute("SELECT COUNT(*) FROM migrations WHERE id >= (SELECT id FROM migrations WHERE migration=?)",('2026_11_14_000000_create_account_onboarding_tokens',)).fetchone()[0]
        assert rollback_count>0 and artisan('migrate:rollback','--step='+str(rollback_count),'--force').returncode==0
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM users').fetchone()[0]==5 and c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='account_onboarding_tokens'").fetchone()[0]==0
        assert artisan('migrate','--force').returncode==0
        passed('Onboarding and any later fixture migrations roll back and reapply on disposable SQLite without deleting staff accounts')
        result={'passed':len(checks),'failed':0,'checks':checks,'transport':'Real local HTTPS with a client-verified fixture certificate and actual Laravel/Symfony SMTP to an in-memory loopback capture','database':'Disposable SQLite with database sessions/cache','externalEmailSent':False,'actualCloudAccess':False,'actualMySqlAccess':False,'realDataUsed':False,'limits':['No actual provider/DNS/remote TLS/inbox delivery verified','Mailbox possession is not identity verification, MFA or production acceptance','Browser/native database/real repository integration pending']};Path(args.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0,'externalEmailSent':False}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks,'externalEmailSent':False},indent=2)+'\n');raise
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
        if smtp:smtp.shutdown();smtp.server_close()
