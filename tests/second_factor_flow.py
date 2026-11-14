"""Fictional two-step sign-in workflow over a local TLS proxy; never accesses Laravel Cloud.
Uses disposable SQLite with database sessions/cache. Does not certify MySQL or S3.
"""
from pathlib import Path
import argparse, base64, hashlib, hmac, struct, http.client, http.cookiejar, http.server, json, os, re, secrets, signal, socket, sqlite3, ssl, subprocess, tempfile, threading, time
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

with tempfile.TemporaryDirectory(prefix='gta-second-factor-') as temporary:
    temp=Path(temporary);db=temp/'fictional.sqlite';db.touch();storage=temp/'storage'
    for part in ['app/private','framework/sessions','framework/cache/data','framework/cache/locks','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
    php_port=free_port();tls_port=free_port();origin=f'https://127.0.0.1:{tls_port}'
    private_password='Fictional-private-'+secrets.token_urlsafe(24)
    env=os.environ.copy();env.update(APP_ENV='staging',APP_DEBUG='false',APP_URL=origin,APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),DB_CONNECTION='sqlite',DB_DATABASE=str(db),SESSION_DRIVER='database',SESSION_ENCRYPT='true',SESSION_SECURE_COOKIE='true',CACHE_STORE='database',MAIL_MAILER='array',QUEUE_CONNECTION='sync',GTA_ENABLE_STAGING_PORTAL='true',GTA_ALLOW_INITIAL_ADMIN='true',GTA_SCHEDULE_PORTAL_REMINDERS='false',LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(temp/'config.php'),APP_ROUTES_CACHE=str(temp/'routes.php'))
    env['GTA_SOURCE_TEST_PASSWORD']=private_password
    env.update(GTA_MFA_ENROLMENT_ENABLED='false',GTA_MFA_PILOT_EMAILS='admin@example.test')
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
        seed=r"require 'vendor/autoload.php';$a=require 'bootstrap/app.php';$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();foreach(['admin@example.test'=>'admin','outside@example.test'=>'tutor']as$email=>$role){App\Models\User::factory()->create(['email'=>$email,'role'=>$role,'password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);}App\Models\Apprentice::factory()->create(['first_name'=>'FICTIONAL-RETAINED-APPRENTICE']);"
        seeded=subprocess.run([args.php,'-r',seed],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert seeded.returncode==0,'Fictional setup failed'
        with sqlite3.connect(db)as c:original_passwords=c.execute('SELECT id,password FROM users ORDER BY id').fetchall();original_people=c.execute('SELECT * FROM apprentices').fetchall()
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            status,text,target,headers=req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password});assert status==200
            return c,text,target
        def post(c,path,values):
            status,text,_,_=req(c,path);assert status==200
            return req(c,path,{'_token':csrf(text),**values})
        def codes(text):return re.findall(r'<li><code>([A-F0-9]{8}(?:-[A-F0-9]{8}){3})</code></li>',text)
        admin,_,_=login('admin@example.test');older,_,_=login('admin@example.test');outside,_,_=login('outside@example.test')
        assert req(admin,'/account/two-step')[0]==403
        passed('Default enrolment OFF preserves password sign-in and refuses setup')
        env['GTA_MFA_ENROLMENT_ENABLED']='true';stop_backend();configure();start_backend()
        assert req(admin,'/account/two-step')[0]==200 and req(outside,'/account/two-step')[0]==403
        passed('Cached staging pilot allows only the explicitly listed verified staff account')
        assert req(admin,'/account/two-step',{'action':'start','current_password':private_password})[0]==419
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM account_second_factors').fetchone()[0]==0
        status,text,_,_=post(admin,'/account/two-step',{'action':'start','current_password':'WRONG-FICTIONAL-PASSWORD'});assert status==200 and 'not accepted'in text
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM account_second_factors').fetchone()[0]==0
        passed('Missing CSRF and wrong current password cannot start enrolment')
        status,text,_,headers=post(admin,'/account/two-step',{'action':'start','current_password':private_password});assert status==200 and headers['Referrer-Policy']=='no-referrer' and 'no-store'in headers['Cache-Control']
        secret=re.search(r'id="mfa-setup-key">([A-Z2-7]{32})</code>',text)[1]
        with sqlite3.connect(db)as c:
            row=c.execute('SELECT secret,confirmed_at,setup_binding FROM account_second_factors').fetchone();assert row[0]!=secret and secret not in row[0] and row[1] is None and len(row[2])==64
        assert secret not in req(older,'/account/two-step')[1]
        passed('Manual setup key is encrypted in the database, private/no-referrer, and shown only to the starting session')
        # Independent Python implementation, not the application helper.
        counter=int(time.time())//30;digest=hmac.new(base64.b32decode(secret),struct.pack('>Q',counter),hashlib.sha1).digest();offset=digest[-1]&15;confirmation=str((struct.unpack('>I',digest[offset:offset+4])[0]&0x7fffffff)%1000000).zfill(6)
        status,text,_,_=post(admin,'/account/two-step',{'action':'confirm','current_password':private_password,'code':confirmation});recovery=codes(text);assert status==200 and len(recovery)==10 and len(set(recovery))==10 and secret not in text
        with sqlite3.connect(db)as c:
            row=c.execute('SELECT secret,confirmed_at,setup_binding,recovery_hashes FROM account_second_factors').fetchone();assert row[1] and row[2]is None and recovery[0]not in row[3] and len(json.loads(row[3]))==10
        assert not codes(req(admin,'/account/two-step')[1])
        assert artisan('migrate:rollback','--step=1','--force').returncode!=0
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM account_second_factors WHERE confirmed_at IS NOT NULL').fetchone()[0]==1
        passed('Migration rollback refuses to drop an enrolled factor even with an explicit force flag')
        passed('Independent authenticator code confirms enrolment; ten recovery codes are hashed and displayed once')
        assert req(older,'/help')[2].endswith('/login')
        stop_backend();start_backend();assert req(admin,'/help')[2].endswith('/help')
        passed('Enrolment invalidates an older password-only session while the proved database session survives PHP restart')
        env['GTA_MFA_ENROLMENT_ENABLED']='false';env['GTA_MFA_PILOT_EMAILS']='';stop_backend();configure();start_backend()
        challenge,text,target=login('admin@example.test');assert target.endswith('/two-step') and 'security code'in text
        assert req(challenge,'/apprentices')[2].endswith('/login')
        passed('Disabling enrolment and removing allowlist cannot bypass enrolled-account password-plus-code sign-in')
        status,text,_,_=post(challenge,'/two-step',{'code':confirmation});assert status==200 and 'not accepted'in text
        # Regardless of a time-step boundary, the used counter is never accepted again.
        with sqlite3.connect(db)as c:assert len(json.loads(c.execute('SELECT recovery_hashes FROM account_second_factors').fetchone()[0]))==10
        passed('Previously consumed authenticator code cannot be replayed to complete another sign-in')
        status,text,target,_=post(challenge,'/two-step',{'code':recovery[0]});assert status==200 and not target.endswith('/two-step')
        with sqlite3.connect(db)as c:assert len(json.loads(c.execute('SELECT recovery_hashes FROM account_second_factors').fetchone()[0]))==9
        passed('Password plus a saved recovery code completes real HTTPS sign-in and consumes exactly one code')
        status,text,_,_=req(challenge,'/help');req(challenge,'/logout',{'_token':csrf(text)})
        challenge,text,target=login('admin@example.test');status,text,_,_=post(challenge,'/two-step',{'code':recovery[0]});assert status==200 and 'not accepted'in text
        status,text,target,_=post(challenge,'/two-step',{'code':recovery[1]});assert status==200 and not target.endswith('/two-step')
        pending,_,target=login('admin@example.test');assert target.endswith('/two-step')
        passed('Consumed recovery code is refused across fresh sessions; another unused code remains valid')
        status,text,_,_=post(challenge,'/account/two-step',{'action':'regenerate','current_password':private_password,'code':recovery[2]});replacement=codes(text);assert len(replacement)==10 and not set(replacement)&set(recovery)
        status,text,_,_=post(pending,'/two-step',{'code':replacement[0]});assert status==200 and 'not accepted'in text
        with sqlite3.connect(db)as c:assert len(json.loads(c.execute('SELECT recovery_hashes FROM account_second_factors').fetchone()[0]))==10
        passed('Replacing recovery codes requires password and factor, revokes old codes, and stales another pending sign-in without consuming its offered code')
        status,text,target,_=post(challenge,'/account/two-step',{'action':'disable','current_password':private_password,'code':replacement[0]});assert status==200 and target.endswith('/help')
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT COUNT(*) FROM account_second_factors').fetchone()[0]==0
            assert c.execute('SELECT id,password FROM users ORDER BY id').fetchall()==original_passwords and c.execute('SELECT * FROM apprentices').fetchall()==original_people
            events=json.dumps(c.execute('SELECT * FROM account_access_events').fetchall());assert secret not in events and private_password not in events and all(code not in events for code in recovery+replacement)
        passed('Password-plus-factor disable preserves accounts, passwords and apprentice records; audit events contain no setup key or recovery codes')
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='account_second_factors'").fetchone()[0]==0
            assert c.execute('SELECT id,password FROM users ORDER BY id').fetchall()==original_passwords and c.execute('SELECT * FROM apprentices').fetchall()==original_people
        assert artisan('migrate','--force').returncode==0
        passed('New migration rolls back/reapplies only after all fictional factors have been disabled; existing account/apprentice data remains intact')
        configure('production');start_backend();assert req(client(),'/two-step')[0]==503
        passed('Existing production access block remains enforced on challenge routes')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Local client-verified HTTPS, independent Python TOTP, disposable SQLite database sessions/cache and cached configuration','realDataUsed':False,'externalMessagesSent':False,'actualAuthenticatorAppTested':False,'actualCloudAccess':False,'actualNativeEngine':False,'limits':['Not production security approval or independent security assessment','No actual app/browser/device/Cloud or native concurrency acceptance','No notification delivery or administrator factor-reset workflow']};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
