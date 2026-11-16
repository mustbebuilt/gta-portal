"""Fictional Pack53 shared v2 theme plus Pack52 common planned-duration analysis plus Pack51 reviewed nearby-date planning plus Pack50 visual/login refinements and Pack49 reviewed Centre Manager grants and scoped coordination plus retained portal journeys over a local TLS proxy; never accesses Laravel Cloud.
Uses disposable SQLite with database sessions/cache. Does not certify MySQL or S3.
"""
from pathlib import Path
import concurrent.futures, statistics, argparse, base64, http.client, http.cookiejar, http.server, json, os, re, secrets, signal, socket, sqlite3, ssl, subprocess, tempfile, threading, time
from urllib.request import Request, HTTPSHandler, HTTPCookieProcessor, build_opener
from urllib.parse import urlencode
from urllib.error import HTTPError

parser=argparse.ArgumentParser()
parser.add_argument('--php',default='php');parser.add_argument('--output',required=True);parser.add_argument('--browser',action='store_true');parser.add_argument('--browser-executable',default='/usr/bin/chromium')
args=parser.parse_args();project=Path(__file__).resolve().parent.parent
checks=[];server=None;proxy=None

def passed(name):checks.append({'name':name,'passed':True})
def free_port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]

with tempfile.TemporaryDirectory(prefix='gta-flexible-duties-') as temporary:
    temp=Path(temporary);db=temp/'fictional.sqlite';db.touch();storage=temp/'storage'
    for part in ['app/private','framework/sessions','framework/cache/data','framework/cache/locks','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
    php_port=free_port();tls_port=free_port();origin=f'https://127.0.0.1:{tls_port}'
    private_password='Fictional-private-'+secrets.token_urlsafe(24)
    env=os.environ.copy();env.update(APP_ENV='staging',APP_DEBUG='false',APP_URL=origin,APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),DB_CONNECTION='sqlite',DB_DATABASE=str(db),SESSION_DRIVER='database',SESSION_ENCRYPT='true',SESSION_SECURE_COOKIE='true',CACHE_STORE='database',MAIL_MAILER='array',QUEUE_CONNECTION='sync',GTA_ENABLE_STAGING_PORTAL='true',GTA_ALLOW_INITIAL_ADMIN='true',GTA_SCHEDULE_PORTAL_REMINDERS='false',LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(temp/'config.php'),APP_ROUTES_CACHE=str(temp/'routes.php'))
    env['GTA_SOURCE_TEST_PASSWORD']=private_password
    env['PHP_CLI_SERVER_WORKERS']='4'
    backend_log=open(temp/'backend.log','w+')
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
        server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{php_port}','-t','public'],cwd=project,env=env,stdout=backend_log,stderr=backend_log,start_new_session=True)
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
        seed=r"$roles=['admin','admin','admin','tutor','tutor','tutor','tutor','mentor','mentor','functional_skills_tutor','functional_skills_tutor','careers_adviser','careers_adviser','inclusion_lead','inclusion_member','employer','employer','manager','manager','manager'];$hash=Illuminate\Support\Facades\Hash::make(getenv('GTA_SOURCE_TEST_PASSWORD'));$users=[];foreach($roles as$i=>$role){$users[]=App\Models\User::factory()->create(['role'=>$role,'name'=>'Fictional concurrent61 '.$i,'email'=>'concurrent'.$i.'@example.test','password'=>$hash]);}$programmes=[];foreach(App\Support\TaskCatalogue::PROGRAMMES as$key=>$d){$programmes[$key]=App\Models\Programme::factory()->create(['standard_code'=>$d['code'],'version'=>$d['version'],'active'=>true]);}$keys=array_keys($programmes);$people=[];foreach(range(0,99)as$i){$u=App\Models\User::factory()->create(['role'=>'apprentice','password'=>$hash]);$p=$keys[$i%4];$t=$users[3+$i%4];$a=App\Models\Apprentice::factory()->create(['reference'=>'CONCURRENT61-'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'first_name'=>'Fictional concurrent','last_name'=>'Learner '.$i,'tutor_id'=>$t->id,'programme_id'=>$programmes[$p]->id,'user_id'=>$u->id,'email'=>$u->email,'start_date'=>'2026-09-01']);foreach(App\Support\TaskCatalogue::tasks($p)as$x){App\Models\LearningTask::create(['apprentice_id'=>$a->id,'reference'=>$x['id'],'block'=>$x['block'],'title'=>$x['title'],'theme'=>$x['theme'],'template'=>[...App\Support\TaskCatalogue::template($x['id'],$p),'catalogueId'=>$p]]);}$people[]=$a->id;}echo json_encode(['users'=>array_map(fn($u)=>$u->only('id','email','role'),$users),'profiles'=>count($people),'tasks'=>App\Models\LearningTask::count(),'accounts'=>App\Models\User::count()]);"
        result=subprocess.run([args.php,'-r',bootstrap+seed],cwd=project,env=env,capture_output=True,text=True,timeout=120);assert result.returncode==0,'Fictional seed failed';data=json.loads(result.stdout);assert data['accounts']==120 and data['profiles']==100 and data['tasks']==5000
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        clients=[]
        for u in data['users']:
            c=build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
            status,text,_,_=req(c,'/login');assert status==200
            status,text,url,_=req(c,'/login',{'_token':csrf(text),'email':u['email'],'password':private_password});assert status==200,(u['role'],status);clients.append(c)
        gate=threading.Barrier(20);results=[];started=time.perf_counter()
        def work(i):
            u=data['users'][i];gate.wait(timeout=15);rows=[]
            for turn in range(3):
                tick=time.perf_counter();status,text,url,headers=req(clients[i],'/apprentices');expected=200 if u['role']in ['admin','tutor'] else 403
                assert status==expected,(u['role'],status,expected)
                if status==200:
                    assert 'CONCURRENT61-'in text
                    if u['role']=='tutor':
                        ids=[n for n in re.findall(r'CONCURRENT61-(\d{3})',text)];assert ids and all(int(n)%4==i-3 for n in ids),'Tutor sees another caseload'
                rows.append({'user':i,'role':u['role'],'turn':turn+1,'status':status,'expected':expected,'milliseconds':round((time.perf_counter()-tick)*1000,2)})
            return rows
        with concurrent.futures.ThreadPoolExecutor(max_workers=20)as pool:
            for rows in pool.map(work,range(20)):results.extend(rows)
        duration=time.perf_counter()-started;backend_log.flush();backend_log.seek(0);log=backend_log.read();pids=sorted(set(re.findall(r'^\[(\d+)\]',log,re.M)))
        times=sorted(x['milliseconds']for x in results);report={'passed':len(results),'failed':0,'users':20,'profiles':100,'tasks':5000,'total_accounts':120,'requested_php_workers':4,'observed_backend_process_ids':pids,'concurrent_start_barrier':20,'requests':results,'elapsed_seconds':round(duration,3),'p50_ms':statistics.median(times),'p95_ms':times[int(.95*(len(times)-1))],'max_ms':max(times),'actual_cloud':False,'actual_mysql':False,'browser_rendered':False,'external_delivery':False,'fixture_allocation':'Direct catalogue-backed fixture insert; actual allocation route separately covered by cohort test.','limits':['Bounded localhost HTTPS/SQLite read/session test, not a hosted capacity/SLA benchmark.','The four-worker setting is recorded; observed process ids retained separately.','No concurrent financial/Gateway/signature/other write-path certification. Native-engine and actual staging testing remain required.']};Path(args.output).write_text(json.dumps(report,indent=2)+'\n');print(json.dumps({k:v for k,v in report.items()if k not in ['requests','limits']}))
    finally:
        if proxy:proxy.shutdown();proxy.server_close()
        stop_backend();backend_log.close()
