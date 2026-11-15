"""Fictional Pack34 staff handovers workflows over a local TLS proxy; never accesses Laravel Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-staff-handover-') as temporary:
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
            result=subprocess.run([args.php,'-r',bootstrap+code],cwd=project,env=env,capture_output=True,text=True,timeout=90);assert result.returncode==0,'Private synthetic setup failed';return json.loads(result.stdout or '{}')
        ids=script(r"$out=[];foreach(['admin','ceo','old','target']as$key){$u=App\Models\User::factory()->create(['role'=>in_array($key,['admin','ceo'])?$key:'tutor','email'=>$key.'@example.test','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);$out[$key]=$u->id;}$a=App\Models\Apprentice::factory()->create(['tutor_id'=>$out['old'],'reference'=>'SYNTHETIC-A','first_name'=>'SYNTHETIC-LEARNER-A','email'=>'PRIVATE-LEARNER@example.test']);$b=App\Models\Apprentice::factory()->create(['tutor_id'=>$out['old'],'reference'=>'SYNTHETIC-B','first_name'=>'SYNTHETIC-LEARNER-B']);$out['a']=$a->id;$out['b']=$b->id;echo json_encode($out);")
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            assert req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password})[0]==200;return c
        def post(c,source,path,values):
            status,text,_,_=req(c,source);assert status==200
            # Support a deliberate PHP array only in this isolated fixture.
            pairs=[('_token',csrf(text))]
            for key,value in values.items():
                if isinstance(value,list):pairs.extend((key,str(x))for x in value)
                else:pairs.append((key,str(value)))
            return req(c,path,pairs)
        admin=login('admin@example.test');ceo=login('ceo@example.test');old=login('old@example.test');target=login('target@example.test');index='/staff-handovers';create=f"{index}/new?slot=tutor&from={ids['old']}";incoming='/my-staff-handovers'
        status,text,_,headers=req(admin,index+'/workload');assert status==200 and 'not FTE'in text and 'no-store'in headers['Cache-Control'] and headers['Referrer-Policy']=='no-referrer';assert req(target,index)[0]==403 and req(admin,incoming)[0]==403
        passed('Actual client-verified HTTPS workload/management are manager-only and do not claim FTE or capacity')
        data={'slot':'tutor','from':ids['old'],'target':ids['target'],'apprentices[]':[ids['a'],ids['b']],'reason':'PRIVATE-HANDOVER-REFERENCE','confirmed':1}
        assert req(admin,index,{'slot':'tutor'})[0]==419
        status,text,url,_=post(admin,create,index,data);assert status==200 and 'Exact preview'in text;detail=url[len(origin):];batch=detail.rsplit('/',1)[1]
        status,text,duplicate_url,_=post(admin,create,index,data);assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM apprentices WHERE tutor_id=?',(ids['old'],)).fetchone()[0]==2;assert 'PRIVATE-HANDOVER'not in c.execute('SELECT source FROM staff_handover_batches WHERE id=?',(batch,)).fetchone()[0]
        passed('Missing CSRF refuses; named two-record previews are encrypted and do not change assignments')
        decision={'version':1,'decision':'apply','note':'PRIVATE-INDEPENDENT-CHECK','confirmed':1}
        post(admin,detail,detail,decision)
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM staff_handover_batches WHERE id=?',(batch,)).fetchone()[0]=='Pending'
        post(ceo,detail,detail,decision)
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM apprentices WHERE tutor_id=?',(ids['target'],)).fetchone()[0]==2;assert c.execute('SELECT COUNT(*) FROM assignment_change_events').fetchone()[0]==2
        assert req(old,f"/apprentices/{ids['a']}")[0]==403 and req(target,f"/apprentices/{ids['a']}")[0]==200
        passed('Different-manager apply changes all selected current tutor permissions and canonical history atomically; creator cannot self-apply')
        duplicate=duplicate_url[len(origin):];post(ceo,duplicate,duplicate,decision)
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM staff_handover_batches WHERE id=?',(duplicate.rsplit('/',1)[1],)).fetchone()[0]=='Pending';assert c.execute('SELECT COUNT(*) FROM assignment_change_events').fetchone()[0]==2
        passed('Second same-source preview becomes stale and cannot apply or duplicate changes')
        status,text,_,_=req(target,incoming);assert status==200 and 'SYNTHETIC-A'in text and 'SYNTHETIC-B'in text and 'PRIVATE-HANDOVER'not in text and 'PRIVATE-INDEPENDENT'not in text
        scope=re.search(r'name="scope_hash" value="([a-f0-9]{64})"',text)[1];ack={'version':0,'scope_hash':scope,'confirmed':1}
        post(target,incoming,f'{incoming}/{batch}',ack)
        with sqlite3.connect(db)as c:assert c.execute('SELECT acknowledged_at FROM staff_handover_acknowledgements').fetchone()[0];assert 'scope'not in c.execute('SELECT proof FROM staff_handover_acknowledgements').fetchone()[0]
        post(target,incoming,f'{incoming}/{batch}',ack)
        with sqlite3.connect(db)as c:assert c.execute('SELECT version FROM staff_handover_acknowledgements').fetchone()[0]==1
        passed('Own acknowledgement binds current visible scope with encrypted proof; management reasons stay private and stale replay refuses')
        stop_backend();start_backend();assert 'Acknowledged'in req(target,incoming)[1]
        passed('Encrypted sessions/cache and proof-checked handover acknowledgement persist over controlled restart')
        reverse_create=f"{index}/new?slot=tutor&from={ids['target']}";reverse={**data,'from':ids['target'],'target':ids['old'],'apprentices[]':[ids['a']],'reason':'PRIVATE-REVERSE-HANDOVER'}
        status,text,url,_=post(admin,reverse_create,index,reverse);assert status==200;back=url[len(origin):];post(ceo,back,back,decision)
        status,text,_,_=req(target,incoming);assert 'SYNTHETIC-A'not in text and 'SYNTHETIC-B'in text and 'check the current visible assignments again'in text
        post(target,incoming,f'{incoming}/{batch}',{**ack,'version':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT version FROM staff_handover_acknowledgements').fetchone()[0]==1
        scope=re.search(r'name="scope_hash" value="([a-f0-9]{64})"',text)[1];post(target,incoming,f'{incoming}/{batch}',{'version':1,'scope_hash':scope,'confirmed':1})
        passed('Deliberate independently checked reverse handover removes old recipient visibility and invalidates acknowledgement; current subset can be rechecked')
        status,text,_,_=req(admin,detail+'/handover.json');assert status==200 and json.loads(text)['applied_proof_verified']is True and 'PRIVATE-'not in text and 'profile_hash'not in text and json.loads(text)['production_approved']is False
        assert req(target,detail+'/handover.json')[0]==403
        passed('Minimal manager-only JSON preserves historical applied proof without private notes or identity seals')
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM employer_portal_actions').fetchone()[0]==0;assert c.execute('SELECT COUNT(*) FROM progress_review_drafts').fetchone()[0]==0
        passed('Transfer/acknowledgement creates no employer action or review completion')
        post(admin,duplicate,duplicate,{'version':1,'decision':'withdraw','note':'Fictional stale preview withdrawn','confirmed':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM staff_handover_batches WHERE id=?',(duplicate.rsplit('/',1)[1],)).fetchone()[0]=='Withdrawn'
        passed('Stale preview can be explicitly withdrawn without changing applied history')
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('timetable_change_batches','timetable_change_events','register_followup_cases','register_followup_events')").fetchone()[0]==0;assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='employer_start_checks'").fetchone()[0]==1
        assert artisan('migrate','--force').returncode==0
        passed('Disposable latest down/up removes only four Pack36 tables, preserving employer setup and earlier schema')
        configure('production');start_backend();assert req(admin,index)[0]==503 and req(target,incoming)[0]==503
        passed('Existing production access gate remains unchanged')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual local client-verified HTTPS; disposable SQLite/encrypted database sessions/cache','realDataUsed':False,'externalMessagesSent':False,'actualNativeEngine':False,'actualCloudAccess':False,'actualBrowserOrPhoneTested':False,'productionApproved':False};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps(output))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
