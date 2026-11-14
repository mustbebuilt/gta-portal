"""Fictional Pack21 apprentice experience workflows over a local TLS proxy; never accesses Laravel Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-apprentice-experience-') as temporary:
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
        configure();assert artisan('migrate','--force').returncode==0,'Disposable migration failed'
        seed=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$users=[];foreach(['admin@example.test'=>'admin','tutor@example.test'=>'tutor','other@example.test'=>'tutor','learner@example.test'=>'apprentice','inclusion@example.test'=>'inclusion_member']as$email=>$role){$users[$email]=App\Models\User::factory()->create(['email'=>$email,'role'=>$role,'password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);}$a=App\Models\Apprentice::factory()->create(['reference'=>'EXP-FICTIONAL','first_name'=>'Fictional Experience Learner','user_id'=>$users['learner@example.test']->id,'tutor_id'=>$users['tutor@example.test']->id]);$task=App\Models\LearningTask::factory()->create(['apprentice_id'=>$a->id,'title'=>'Fictional next inspection task']);App\Models\TrainingSession::factory()->create(['name'=>'Fictional next training day','session_date'=>today()->addDay(),'members'=>[$a->id]]);echo json_encode(['task'=>$task->id,'apprentice'=>$a->id,'users'=>array_map(fn($u)=>$u->id,$users)]);"
        seeded=subprocess.run([args.php,'-r',seed],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert seeded.returncode==0,'Fictional experience setup failed';ids=json.loads(seeded.stdout)
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
        admin=login('admin@example.test');tutor=login('tutor@example.test');learner=login('learner@example.test');inclusion=login('inclusion@example.test');other=login('other@example.test')
        status,text,url,headers=req(learner,'/my-next-step');assert status==200 and 'Fictional next inspection task'in text and 'Fictional next training day'in text and 'My achievement timeline'in text and 'no-store'in headers['Cache-Control']
        passed('Apprentice home renders own available next task, actual saved training roster, journey and private cache policy')
        assert req(tutor,'/my-next-step')[0]==403 and req(client(),'/my-next-step')[2].endswith('/login')
        passed('Staff cannot use learner-only home; unauthenticated requests require sign-in')
        assert req(learner,'/my-help-and-achievements',{'kind':'help_task','title':'No CSRF','detail':'Fictional'})[0]==419
        passed('Actual POST without CSRF is refused before entry creation')
        import uuid,hashlib
        def entry(kind,title,extra=None):
            values={'request_token':str(uuid.uuid4()),'kind':kind,'title':title,'detail':'Fictional '+title,**(extra or {})}
            status,text,_,_=post(learner,'/my-help-and-achievements','/my-help-and-achievements',values);assert status==200 and title in text
            with sqlite3.connect(db)as c:row=c.execute('SELECT id,version,state FROM apprentice_experience_entries WHERE request_token=?',(values['request_token'],)).fetchone()
            assert row and row[2]=='New';return values,row
        help_data,help_row=entry('help_task','Task request receipt')
        status,text,_,_=post(learner,'/my-help-and-achievements','/my-help-and-achievements',help_data);assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM apprentice_experience_entries').fetchone()[0]==1
        passed('Real form receipt persists once across repeated submission of the same request token')
        assert 'Task request receipt'in req(tutor,'/my-help-and-achievements')[1] and 'Task request receipt'not in req(other,'/my-help-and-achievements?filter=all')[1]
        passed('Current main tutor receives task request; unrelated tutor receives no request content')
        status,text,_,_=post(tutor,'/my-help-and-achievements',f'/my-help-and-achievements/{help_row[0]}',{'version':1,'state':'Received','response':'Fictional receipt acknowledged'});assert status==200
        assert 'Fictional receipt acknowledged'in req(learner,'/my-help-and-achievements?filter=all')[1]
        passed('Tutor acknowledgement is persisted and shown in the apprentice receipt')
        _,private=entry('help_private','PRIVATE FICTIONAL REQUEST')
        for c in [tutor,admin,other]:assert 'PRIVATE FICTIONAL REQUEST'not in req(c,'/my-help-and-achievements?filter=all')[1]
        assert 'PRIVATE FICTIONAL REQUEST'in req(inclusion,'/my-help-and-achievements?filter=all')[1]
        status,_,_,_=post(tutor,'/my-help-and-achievements',f'/my-help-and-achievements/{private[0]}',{'version':1,'state':'Received','response':'Not authorised'});assert status==403
        passed('Private support is absent from ordinary tutor/admin queues and cannot be acknowledged by forged direct POST')
        status,_,_,_=post(inclusion,'/my-help-and-achievements',f'/my-help-and-achievements/{private[0]}',{'version':1,'state':'Closed','response':'Fictional private response'});assert status==200
        assert 'Fictional private response'in req(learner,'/my-help-and-achievements?filter=all')[1]
        passed('Scoped Inclusion Team can close a private request with an apprentice-visible response')
        _,achievement=entry('achievement','Fictional workplace achievement',{'happened_on':'2026-11-01'})
        journey=f'/apprentices/{ids["apprentice"]}/journey'
        assert 'Fictional workplace achievement'not in req(learner,journey)[1]
        status,_,_,_=post(tutor,'/my-help-and-achievements?filter=achievement',f'/my-help-and-achievements/{achievement[0]}',{'version':1,'state':'Approved','response':'Fictional feedback checked'});assert status==200
        status,text,_,_=req(learner,journey);assert status==200 and 'Fictional workplace achievement'in text and 'Checked workplace achievement'in text and 'Separate checks and sign-off required'in text
        passed('Workplace achievement enters timeline only after tutor check; Gateway/EPA remain explicitly separate')
        task_path=f'/tasks/{ids["task"]}';status,text,_,_=req(learner,task_path);assert status==200 and 'capture="environment"'in text
        png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6nRsAAAAASUVORK5CYII=')
        boundary='GTAFictionalBoundary'+secrets.token_hex(8);parts=[]
        for name,value in {'_token':csrf(text),'version':'1','kind':'workplace-photo','description':'Fictional photo explanation'}.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="fictional.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+png+b'\r\n');parts.append(f'--{boundary}--\r\n'.encode())
        with learner.open(Request(origin+task_path+'/evidence',data=b''.join(parts),headers={'Content-Type':'multipart/form-data; boundary='+boundary}),timeout=30)as response:assert response.status==200
        with sqlite3.connect(db)as c:
            file=c.execute('SELECT id,description,path,disk FROM task_evidence WHERE learning_task_id=?',(ids['task'],)).fetchone();assert file and file[1]=='Fictional photo explanation' and file[3]=='local';assert c.execute('SELECT status FROM learning_tasks WHERE id=?',(ids['task'],)).fetchone()[0]=='Draft'
        passed('Actual multipart photo upload stores explanation privately without approving or submitting the task')
        download=f'/tasks/{ids["task"]}/evidence/{file[0]}'
        with tutor.open(origin+download,timeout=30)as response:assert response.status==200 and hashlib.sha256(response.read()).digest()==hashlib.sha256(png).digest()
        assert req(other,download)[0]==403
        passed('Tutor private download returns exact photo bytes; unrelated tutor is denied')
        stop_backend();start_backend();assert 'Fictional private response'in req(learner,'/my-help-and-achievements?filter=all')[1] and 'Fictional workplace achievement'in req(learner,journey)[1]
        passed('Database sessions, help responses and checked achievements persist across backend restart')
        status,_,_,_=post(tutor,'/my-help-and-achievements?filter=all',f'/my-help-and-achievements/{help_row[0]}',{'version':1,'state':'Closed','response':'Stale response'});assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT state,version FROM apprentice_experience_entries WHERE id=?',(help_row[0],)).fetchone()==('Received',2)
        passed('Stale response form is rejected without overwriting the acknowledged request')
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='apprentice_alert_deliveries'").fetchone()[0]==0
            assert 'description'in [r[1]for r in c.execute('PRAGMA table_info(task_evidence)')]
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='apprentice_mobile_challenges'").fetchone()[0]==1
            assert c.execute('SELECT COUNT(*) FROM task_evidence').fetchone()[0]==1
        assert artisan('migrate','--force').returncode==0
        passed('Disposable rollback/reapply removes only Pack23 employer/Gateway schema while retaining earlier brief/experience/mobile schema; new data needs backup for real rollback')
        configure('production');start_backend();assert req(learner,'/my-next-step')[0]==503 and req(tutor,'/my-help-and-achievements')[0]==503
        passed('Existing production gate remains enforced on new screens')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Local client-verified HTTPS, cached staging configuration and disposable SQLite database sessions/cache','realDataUsed':False,'externalMessagesSent':False,'actualCloudAccess':False,'actualNativeEngine':False,'actualBrowserOrPhoneTested':False};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps(output))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
