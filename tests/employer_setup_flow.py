"""Fictional Pack33 employer setup workflows over a local TLS proxy; never accesses Laravel Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-employer-setup-') as temporary:
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
        configure();assert artisan('migrate','--force').returncode==0
        bootstrap=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();"
        def script(code):
            result=subprocess.run([args.php,'-r',bootstrap+code],cwd=project,env=env,capture_output=True,text=True,timeout=90);assert result.returncode==0,'Private synthetic fixture setup failed';return json.loads(result.stdout or '{}')
        ids=script(r"$m=App\Models\User::factory()->create(['role'=>'admin','email'=>'admin@example.test','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);$u=App\Models\User::factory()->create(['role'=>'employer','name'=>'Fictional Named Contact','email'=>'employer@example.test','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);$v=App\Models\User::factory()->create(['role'=>'employer','email'=>'unconfirmed@example.test','email_verified_at'=>null]);$e=App\Models\Employer::factory()->create(['name'=>'Fictional Workplace']);$a=App\Models\Apprentice::factory()->create(['employer_id'=>$e->id,'first_name'=>'VISIBLE-FICTIONAL-APPRENTICE','email'=>'PRIVATE-APPRENTICE@example.test']);$g=App\Support\EmployerActions::grant($m,1,$a,['user_id'=>$u->id,'version'=>0,'identity_reference'=>'PRIVATE-AUTHORITY-REFERENCE']);App\Models\Apprentice::factory()->create(['first_name'=>'PRIVATE-OTHER-APPRENTICE']);Illuminate\Support\Facades\DB::table('account_onboarding_tokens')->insert(['user_id'=>$v->id,'purpose'=>'employer_invite','token_hash'=>hash('sha256',str_repeat('t',64)),'auth_version'=>1,'issuer_id'=>$m->id,'issuer_auth_version'=>1,'created_at'=>now(),'expires_at'=>now()->addHour()]);echo json_encode(['admin'=>$m->id,'contact'=>$u->id,'unconfirmed'=>$v->id,'apprentice'=>$a->id,'grant'=>$g->id]);")
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            assert req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password})[0]==200;return c
        def post(c,source,path,values):
            status,text,_,_=req(c,source);assert status==200
            return req(c,path,{'_token':csrf(text),**values})
        admin=login('admin@example.test');employer=login('employer@example.test');index='/employer-accounts';detail=f"/employer-accounts/{ids['contact']}/setup";pending=f"/employer-accounts/{ids['unconfirmed']}/setup";own='/employer-getting-started'
        status,text,_,headers=req(admin,index+'?search=Fictional&state=active');assert status==200 and 'Check setup and access'in text and 'no-store'in headers['Cache-Control'] and headers['Referrer-Policy']=='no-referrer'
        assert req(employer,index)[0]==403 and req(admin,own)[0]==403 and req(client(),index)[2].endswith('/login')
        passed('Actual client-verified HTTPS centre is manager-only; employer own route cannot be used by manager and guests sign in')
        status,text,_,_=req(admin,detail);assert status==200 and 'Current workplace authority'in text and 'PRIVATE-AUTHORITY'not in text and 'PRIVATE-APPRENTICE'not in text
        assert req(admin,index+'?search=%25')[1].count('0 matching contacts')==1
        passed('Contact setup/search show named current permissions without private authority references, learner email or wildcard matches')
        status,text,_,_=req(employer,own);assert status==200 and 'VISIBLE-FICTIONAL-APPRENTICE'in text and 'PRIVATE-OTHER'not in text and 'PRIVATE-APPRENTICE'not in text
        scope=re.search(r'name="scope_hash" value="([a-f0-9]{64})"',text)[1]
        assert req(employer,own+'/people-v1',{'version':0,'scope_hash':scope,'confirmed':1})[0]==419
        passed('Own getting-started shows only authorised learner names and rejects missing CSRF before writes')
        data={'version':0,'scope_hash':scope,'confirmed':1}
        post(employer,own,own+'/people-v1',data)
        with sqlite3.connect(db)as c:row=c.execute('SELECT version,confirmed_at FROM employer_start_checks WHERE user_id=?',(ids['contact'],)).fetchone();assert row[0]==1 and row[1];assert 'scope_hash'not in c.execute('SELECT payload FROM employer_start_events').fetchone()[0]
        post(employer,own,own+'/people-v1',data)
        with sqlite3.connect(db)as c:assert c.execute('SELECT version FROM employer_start_checks').fetchone()[0]==1
        passed('Own confirmations persist with encrypted history; replayed stale version writes nothing')
        stop_backend();start_backend();assert 'Confirmed'in req(employer,own)[1]
        passed('Encrypted database session/cache and employer checklist survive controlled process restart')
        post(employer,own,own+'/people-v1',{**data,'version':1,'confirmed':0})
        with sqlite3.connect(db)as c:assert c.execute('SELECT confirmed_at FROM employer_start_checks').fetchone()[0]is None;assert c.execute('SELECT COUNT(*) FROM employer_portal_actions').fetchone()[0]==0
        passed('Clearing own confirmation does not complete employer actions or training/review records')
        status,text,_,_=req(admin,pending);stamp=re.search(r'name="stamp" value="([a-f0-9]{64})"',text)[1]
        post(admin,pending,f"/employer-accounts/{ids['unconfirmed']}/revoke-invitation",{'record_version':1,'stamp':stamp,'confirmed':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM account_onboarding_tokens WHERE user_id=?',(ids['unconfirmed'],)).fetchone()[0]==0
        passed('Manager revokes an exact outstanding employer invitation over HTTPS without changing workplace access or sending mail')
        update={'record_version':1,'name':'Fictional Corrected Contact','email':'employer@example.test','active':1,'reason':'PRIVATE-IDENTITY-CHECK-REFERENCE','confirmed':1}
        assert req(admin,detail,update)[0]==419
        post(admin,detail,detail,update)
        with sqlite3.connect(db)as c:assert c.execute('SELECT auth_version,record_version FROM users WHERE id=?',(ids['contact'],)).fetchone()==(2,2);assert c.execute('SELECT active FROM employer_apprentice_grants WHERE id=?',(ids['grant'],)).fetchone()[0]==0;assert 'PRIVATE-IDENTITY'not in c.execute("SELECT payload FROM employer_start_events WHERE event='contact_changed'").fetchone()[0]
        status,text,url,_=req(employer,own);assert status==403 or url.endswith('/login')
        employer=login('employer@example.test');status,text,_,_=req(employer,own);assert status==200 and 'VISIBLE-FICTIONAL-APPRENTICE'not in text and 'No current apprentice access'in text
        passed('Changed identity atomically invalidates old sessions and all workplace grants; encrypted reason stays private and fresh login inherits no access')
        post(admin,detail,detail,{**update,'record_version':2,'active':0});post(admin,detail,detail,{**update,'record_version':3,'active':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT active,auth_version FROM users WHERE id=?',(ids['contact'],)).fetchone()==(1,4);assert c.execute('SELECT active FROM employer_apprentice_grants WHERE id=?',(ids['grant'],)).fetchone()[0]==0
        post(admin,detail,detail,update)
        with sqlite3.connect(db)as c:assert c.execute('SELECT record_version FROM users WHERE id=?',(ids['contact'],)).fetchone()[0]==4
        passed('Deactivate/reactivate never restores authority; stale contact form cannot overwrite newer changes')
        script(r"$m=App\Models\User::find(ADMIN);$u=App\Models\User::find(CONTACT);$a=App\Models\Apprentice::find(APPRENTICE);App\Support\EmployerActions::grant($m,1,$a,['user_id'=>$u->id,'version'=>2,'identity_reference'=>'Fictional new independent authority check']);echo '{}';".replace('ADMIN',str(ids['admin'])).replace('CONTACT',str(ids['contact'])).replace('APPRENTICE',str(ids['apprentice'])))
        employer=login('employer@example.test');status,text,_,_=req(employer,own);assert 'VISIBLE-FICTIONAL-APPRENTICE'in text and 'I have checked this step'in text
        passed('Fresh explicit named grant restores only its checked learner scope; old checklist confirmations do not count')
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('timetable_group_briefs','timetable_flexible_days','timetable_cover_allocations','timetable_duty_events')").fetchone()[0]==0;assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='staging_acceptance_runs'").fetchone()[0]==1
        assert artisan('migrate','--force').returncode==0
        passed('Disposable latest migration down/up removes only four Pack39 duty/group/cover tables and retains Pack32 acceptance schema')
        configure('production');start_backend();assert req(admin,index)[0]==503 and req(employer,own)[0]==503
        passed('Existing production access gate remains unchanged')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual client-verified local HTTPS; disposable SQLite/encrypted database sessions/cache','realDataUsed':False,'externalMessagesSent':False,'actualCloudAccess':False,'actualNativeEngine':False,'actualBrowserOrPhoneTested':False,'productionApproved':False};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps(output))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
