"""Fictional task library and four-programme workflow over a local TLS proxy; never accesses Laravel Cloud.
Uses disposable SQLite with database sessions/cache. Does not certify MySQL or S3.
"""
from pathlib import Path
import argparse, base64, hashlib, http.client, http.cookiejar, http.server, json, os, re, secrets, signal, socket, sqlite3, ssl, subprocess, tempfile, threading, time
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

with tempfile.TemporaryDirectory(prefix='gta-task-library-') as temporary:
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
        seed=r"require 'vendor/autoload.php';$a=require 'bootstrap/app.php';$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$tutor=App\Models\User::factory()->create(['email'=>'tutor@example.test','role'=>'tutor','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);App\Models\User::factory()->create(['email'=>'admin@example.test','role'=>'admin','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);App\Models\User::factory()->create(['email'=>'outside@example.test','role'=>'tutor','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);$ids=[];foreach(App\Support\TaskCatalogue::PROGRAMMES as$key=>$source){$p=App\Models\Programme::factory()->create(['standard_code'=>$source['code'],'version'=>$source['version']]);$user=App\Models\User::factory()->create(['email'=>$key.'@example.test','role'=>'apprentice','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);$learner=App\Models\Apprentice::factory()->create(['programme_id'=>$p->id,'tutor_id'=>$tutor->id]);$learner->user_id=$user->id;$learner->save();$ids[$key]=$learner->id;}$unmapped=App\Models\Apprentice::factory()->create(['programme'=>'Light Vehicle Level 3']);$legacy=App\Models\Apprentice::factory()->create();App\Models\LearningTask::create(['apprentice_id'=>$legacy->id,'reference'=>'b1-t4','block'=>1,'title'=>'Retained fictional legacy task','theme'=>'Retained','template'=>App\Support\TaskCatalogue::template('b1-t4'),'answers'=>['private'=>'PRIVATE-RETAINED-ANSWER']]);echo json_encode(['ids'=>$ids,'unmapped'=>$unmapped->id,'legacy'=>$legacy->id]);"
        seeded=subprocess.run([args.php,'-r',seed],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert seeded.returncode==0,'Fictional task setup failed';records=json.loads(seeded.stdout)
        with sqlite3.connect(db)as c:legacy_before=c.execute('SELECT * FROM learning_tasks WHERE apprentice_id=?',(records['legacy'],)).fetchall()
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            assert req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password})[0]==200
            return c
        def post(c,path,values):
            status,text,_,_=req(c,path);assert status==200
            return req(c,path,{'_token':csrf(text),**values})
        def binary(c,path):
            with c.open(Request(origin+path),timeout=30)as r:return r.status,r.read(),r.headers
        def task_row(identifier):
            with sqlite3.connect(db)as c:return c.execute('SELECT version,status,submissions,answers FROM learning_tasks WHERE id=?',(identifier,)).fetchone()
        def save(c,identifier,intent,answers):
            return post(c,f'/tasks/{identifier}',{'version':str(task_row(identifier)[0]),'intent':intent,'own_work':'1',**answers})
        def decision(c,identifier,outcome):
            path=f'/tasks/{identifier}';status,text,_,_=req(c,path);assert status==200
            return req(c,path+'/decision',{'_token':csrf(text),'version':str(task_row(identifier)[0]),'decision':outcome,'decision_date':time.strftime('%Y-%m-%d',time.gmtime()),'feedback':'Fictional full-task feedback','confirmed':'1'})
        png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6yZkAAAAASUVORK5CYII=')
        def upload(c,identifier):
            path=f'/tasks/{identifier}';status,text,_,_=req(c,path);assert status==200
            values={'_token':csrf(text),'version':str(task_row(identifier)[0]),'kind':'supporting-evidence'};boundary='gta-'+secrets.token_hex(16);parts=[]
            for k,v in values.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="fictional.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+png+b'\r\n');parts.append(f'--{boundary}--\r\n'.encode())
            with c.open(Request(origin+path+'/evidence',data=b''.join(parts),headers={'Content-Type':f'multipart/form-data; boundary={boundary}'}),timeout=30)as r:assert r.status==200
        admin=login('admin@example.test');tutor=login('tutor@example.test');outside=login('outside@example.test')
        learners={key:login(key+'@example.test')for key in records['ids']}
        for key in records['ids']:
            status,text,_,headers=req(tutor,'/task-library?programme='+key+'&block=1');assert status==200 and text.count('>Preview task</a>')==5 and 'PRIVATE-RETAINED-ANSWER'not in text and 'no-store'in headers['Cache-Control']
            ref=(''if key=='autocare'else key+'-')+'b1-t4';status,text,_,_=req(tutor,f'/task-library/{key}/{ref}');assert status==200 and 'fieldset disabled'in text and 'name="intent"'not in text
            status,payload,headers=binary(tutor,f'/task-library/{key}/{ref}/source.pdf');catalogue=json.loads((project/f'resources/data/{key}-catalogue.json').read_text());template=catalogue['templates'][catalogue['tasks'][ref]];assert status==200 and hashlib.sha256(payload).hexdigest()==template['sourceTemplateHash']
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM learning_tasks').fetchone()[0]==1 and c.execute('SELECT COUNT(*) FROM learning_events').fetchone()[0]==0
        passed('All four source libraries show five Block1 tasks, blank disabled forms and hash-matching PDF downloads without creating assignments')
        assert req(learners['paint'],'/task-library')[0]==403 and req(learners['paint'],'/task-library/paint/paint-b1-t4/source.pdf')[0]==403
        passed('Apprentice accounts cannot browse the staff source library or its PDF endpoint')
        unlinked=f"/apprentices/{records['unmapped']}/learning";status,text,_,_=req(admin,unlinked);assert 'An unlinked record does not default to Autocare'in text
        status,text,_,_=req(admin,unlinked,{'_token':csrf(text),'confirmed':'1','programme':'autocare'});assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM learning_tasks WHERE apprentice_id=?',(records['unmapped'],)).fetchone()[0]==0
        passed('Missing programme link cannot receive guessed Autocare tasks, including an injected programme request field')
        first_id=next(iter(records['ids'].values()));assert req(admin,f'/apprentices/{first_id}/learning',{'confirmed':'1'})[0]==419
        passed('Actual allocation POST still requires CSRF')
        for key,person in records['ids'].items():
            path=f'/apprentices/{person}/learning';status,text,_,_=post(admin,path,{'confirmed':'1'});assert status==200
            with sqlite3.connect(db)as c:
                rows=c.execute('SELECT id,reference,block,template FROM learning_tasks WHERE apprentice_id=? ORDER BY block,reference',(person,)).fetchall();assert len(rows)==50 and all(sum(row[2]==b for row in rows)==5 for b in range(1,11));assert all(json.loads(row[3])['catalogueId']==key for row in rows)
            status,text,_,_=post(admin,path,{'confirmed':'1'});assert status==200
            with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM learning_tasks WHERE apprentice_id=?',(person,)).fetchone()[0]==50
            task=next(row for row in rows if row[1].endswith('b1-t4'));identifier=task[0];schema=json.loads(task[3]);learner=learners[key]
            answers={'answers['+f['name']+']':'Fictional Apprentice'for f in schema['fields']if f['role']=='apprentice'and f['key']in ['apprentice_name','sign_apprentice_name','apprentice_signoff']};assert answers
            assert save(learner,identifier,'draft',answers)[0]==200
            upload(learner,identifier)
            with sqlite3.connect(db)as c:evidence=c.execute('SELECT id,sha256 FROM task_evidence WHERE learning_task_id=?',(identifier,)).fetchone();assert evidence and evidence[1]==hashlib.sha256(png).hexdigest()
            status,payload,_=binary(learner,f'/tasks/{identifier}/evidence/{evidence[0]}');assert payload==png
            assert req(outside,f'/tasks/{identifier}')[0]==403
            assert save(learner,identifier,'submit',answers)[0]==200 and task_row(identifier)[1]=='Awaiting marking'
            before=task_row(identifier);save(learner,identifier,'draft',answers);assert task_row(identifier)==before
            assert decision(tutor,identifier,'Referred')[0]==200 and task_row(identifier)[1]=='Referred'
            assert save(learner,identifier,'submit',answers)[0]==200
            assert decision(tutor,identifier,'Approved')[0]==200 and task_row(identifier)[1]=='Approved' and len(json.loads(task_row(identifier)[2]))==2
            status,payload,_=binary(tutor,f'/tasks/{identifier}/recorded-work.pdf');assert status==200 and payload.startswith(b'%PDF-')
            paper=next(row for row in rows if row[1].endswith('b1-t1'));status,text,_,_=req(tutor,f'/tasks/{paper[0]}');assert 'completed on paper'in text
            status,text,_,_=req(tutor,f'/tasks/{paper[0]}/decision',{'_token':csrf(text),'version':'1','decision':'Approved','decision_date':time.strftime('%Y-%m-%d',time.gmtime()),'confirmed':'1','test_title':'Fictional controlled paper test','marks_achieved':'20','marks_available':'25','feedback':'Actual fictional paper result'});assert status==200 and task_row(paper[0])[1]=='Approved'
            passed(key+': exact 50-task allocation, duplicate protection, draft, private upload/download, unrelated-tutor refusal, locked submission, refer/resubmit/approve history, recorded PDF and separate paper-test result work over HTTPS')
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT * FROM learning_tasks WHERE apprentice_id=?',(records['legacy'],)).fetchall()==legacy_before
            assert c.execute('SELECT COUNT(*) FROM learning_tasks').fetchone()[0]==201 and c.execute('SELECT COUNT(*) FROM task_evidence').fetchone()[0]==4
        passed('Existing unmapped legacy task/answers remain byte-for-byte unchanged alongside 200 new explicitly linked task records')
        stop_backend();start_backend();assert req(tutor,'/task-library?programme=panel&block=10')[0]==200
        passed('Source-library access and database-backed authenticated sessions survive a PHP restart')
        stop_backend();configure('production');start_backend();assert req(tutor,'/task-library')[0]==503
        passed('Existing production access gate also blocks the new library routes')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Local client-verified HTTPS, multipart uploads, PDF downloads and disposable SQLite sessions/cache','realDataUsed':False,'externalMessagesSent':False,'actualCloudAccess':False,'actualNativeEngine':False,'limits':['No Martin repository or actual Cloud acceptance','No browser/mobile rendering or native concurrency acceptance','Knowledge assessments remain paper-based result records; Light Vehicle is not loaded','No curriculum/cohort/KSB or qualification approval']};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
