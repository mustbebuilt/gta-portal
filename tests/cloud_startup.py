"""Fictional staging startup over a local TLS proxy; never accesses Laravel Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-staging-startup-') as temporary:
    temp=Path(temporary);db=temp/'fictional.sqlite';db.touch();storage=temp/'storage'
    for part in ['app/private','framework/sessions','framework/cache/data','framework/cache/locks','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
    php_port=free_port();tls_port=free_port();origin=f'https://127.0.0.1:{tls_port}'
    private_password='Fictional-private-'+secrets.token_urlsafe(24)
    env=os.environ.copy();env.update(APP_ENV='staging',APP_DEBUG='false',APP_URL=origin,APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),DB_CONNECTION='sqlite',DB_DATABASE=str(db),SESSION_DRIVER='database',SESSION_ENCRYPT='true',SESSION_SECURE_COOKIE='true',CACHE_STORE='database',MAIL_MAILER='array',QUEUE_CONNECTION='sync',GTA_ENABLE_STAGING_PORTAL='true',GTA_ALLOW_INITIAL_ADMIN='true',GTA_SCHEDULE_PORTAL_REMINDERS='false',LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(temp/'config.php'),APP_ROUTES_CACHE=str(temp/'routes.php'))
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
        configure();migration=artisan('migrate','--force');assert migration.returncode==0,'Disposable migration failed'
        passed('Fresh staging fixture migrates without demonstration accounts')
        preview=artisan('gta:initial-admin');assert preview.returncode==0 and json.loads(preview.stdout)['available']
        with sqlite3.connect(db) as c:assert c.execute('SELECT COUNT(*) FROM users').fetchone()[0]==0
        passed('Actual CLI administrator preview creates no account')
        missing=artisan('gta:initial-admin','--create','--name=Fictional Staging Administrator','--email=staging-admin@example.test');assert missing.returncode==1
        with sqlite3.connect(db) as c:assert c.execute('SELECT COUNT(*) FROM users').fetchone()[0]==0
        passed('Non-interactive creation refuses a missing private credential input')
        credentials=json.dumps({'password':private_password,'password_confirmation':private_password})
        created=artisan('gta:initial-admin','--create','--name=Fictional Staging Administrator','--email=staging-admin@example.test','--password-stdin',input_value=credentials)
        assert created.returncode==0 and 'Initial staging administrator created' in created.stdout
        assert private_password not in created.stdout+created.stderr
        with sqlite3.connect(db) as c:
            first=c.execute('SELECT id,password,role,active,email_verified_at FROM users').fetchone();assert first[2:]==('admin',1,None)
            assert c.execute('SELECT completed_by FROM portal_bootstrap_states WHERE id=1').fetchone()[0]==first[0]
            assert c.execute("SELECT COUNT(*) FROM account_access_events WHERE event='initial_admin_created'").fetchone()[0]==1
        passed('Actual private-stdin bootstrap creates one active administrator and atomic audit without exposing password or inferring email verification')
        again=artisan('gta:initial-admin','--create','--name=Fictional Second','--email=second@example.test','--password-stdin',input_value=credentials);assert again.returncode==1
        with sqlite3.connect(db) as c:assert c.execute('SELECT id,password,role,active,email_verified_at FROM users').fetchone()==first
        passed('Repeated actual bootstrap refuses to replace or duplicate the existing account')
        cert=temp/'cert.pem';key=temp/'key.pem'
        result=subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30);assert result.returncode==0,'Local fixture certificate generation failed'
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True)
        thread=threading.Thread(target=proxy.serve_forever,daemon=True);thread.start();start_backend()
        jar=http.cookiejar.CookieJar();client=build_opener(HTTPCookieProcessor(jar),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        status,text,_,headers=req(client,'/login');assert status==200 and 'GTA staging portal' in text and 'authorised staging account' in text
        assert 'no-store' in headers.get('Cache-Control','') and headers.get('X-Frame-Options')=='DENY'
        session_cookies=[c for c in jar if 'session' in c.name];assert session_cookies and all(c.secure for c in session_cookies)
        assert 'httponly' in str(headers.get_all('Set-Cookie')).lower()
        passed('Actual HTTPS staging login loads through the explicit gate with secure encrypted database-session cookies and private headers')
        conn=http.client.HTTPConnection('127.0.0.1',php_port,timeout=10);conn.request('GET','/login',headers={'Host':f'127.0.0.1:{tls_port}'});response=conn.getresponse();response.read();conn.close();assert response.status==503
        passed('Actual plain HTTP backend request cannot bypass staging HTTPS')
        assert req(client,'/login',{'email':'staging-admin@example.test','password':private_password})[0]==419
        passed('Actual staging sign-in rejects missing CSRF token')
        status,text,_,_=req(client,'/login')
        status,text,_,_=req(client,'/login',{'_token':csrf(text),'email':'staging-admin@example.test','password':'Fictional-wrong-password'})
        assert status==200 and 'were not recognised' in text
        with sqlite3.connect(db) as c:assert c.execute('SELECT COUNT(*) FROM cache').fetchone()[0]>0
        passed('Actual failed HTTPS login uses the shared database rate-limit cache')
        status,text,_,_=req(client,'/login');status,text,target,_=req(client,'/login',{'_token':csrf(text),'email':'staging-admin@example.test','password':private_password});assert status==200 and target==origin+'/apprentices' and 'Staging portal' in text
        passed('New bootstrap password signs in through actual HTTPS and database-backed session')
        status,text,_,_=req(client,'/apprentices/create');assert status==200
        status,text,target,_=req(client,'/apprentices',{'_token':csrf(text),'reference':'STAGING-001','first_name':'Fictional','last_name':'Startup Apprentice','email':'learner@example.test','programme':'Fictional startup proof','start_date':'2026-11-01','planned_end_date':'2028-11-01','employer':'Fictional Workshop','group_name':'Fictional Group','tutor_id':''})
        assert status==200 and re.search(r'/apprentices/\d+$',target)
        path=target.removeprefix(origin)
        with sqlite3.connect(db) as c:
            saved=c.execute("SELECT * FROM apprentices WHERE reference='STAGING-001'").fetchone();assert saved
            assert c.execute('SELECT COUNT(*) FROM sessions WHERE user_id=?',(first[0],)).fetchone()[0]>=1
        passed('Bootstrapped administrator saves a fictional apprentice through actual staging form and validation')
        stop_backend();start_backend();status,text,_,_=req(client,path);assert status==200 and 'Startup Apprentice' in text
        with sqlite3.connect(db) as c:assert c.execute("SELECT * FROM apprentices WHERE reference='STAGING-001'").fetchone()==saved
        passed('Authenticated staging session and saved record survive PHP restart using database sessions')
        stop_backend();configure(enabled=False);start_backend();assert req(client,'/login')[0]==503;assert req(client,path)[0]==503
        passed('Disabling staging configuration blocks both public login and an already authenticated session')
        stop_backend();configure(mode='production',enabled=True);start_backend();assert req(client,'/login')[0]==503
        refused=artisan('gta:initial-admin');assert refused.returncode==1
        passed('Production remains inaccessible and bootstrap refuses it even when staging flags remain enabled')
        result={'passed':len(checks),'failed':0,'checks':checks,'transport':'Local HTTPS TLS-terminating loopback proxy with a fixture certificate verified by the client','database':'Disposable SQLite with database sessions and cache','actualCloudAccess':False,'actualMySqlAccess':False,'realDataOrMail':False,'limits':['No real Laravel Cloud deployment, database engine, S3 bucket, browser or production acceptance','Loopback proxy trust exists only in the temporary test config; source proxy settings are unchanged','No genuine account identities, personal learner records, external messages or real credentials used']}
        Path(args.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0,'actualCloudAccess':False}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks,'actualCloudAccess':False},indent=2)+'\n');raise
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
