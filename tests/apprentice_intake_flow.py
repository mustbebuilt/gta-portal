"""Fictional Pack35 apprentice intakes workflows over a local TLS proxy; never accesses Laravel Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-apprentice-intake-') as temporary:
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
        ids=script(r"$out=[];foreach(['admin','ceo','tutor']as$key){$u=App\Models\User::factory()->create(['role'=>$key,'email'=>$key.'@example.test','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);$out[$key]=$u->id;}$p=App\Models\Programme::factory()->create(['standard_code'=>'ST-FICTIONAL','version'=>'1.0']);$e=App\Models\Employer::factory()->create(['reference'=>'SYNTHETIC-EMPLOYER']);$g=App\Models\TutorGroup::factory()->create(['reference'=>'SYNTHETIC-GROUP','programme_id'=>$p->id,'tutor_id'=>$out['tutor']]);echo json_encode($out);")
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            assert req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password})[0]==200;return c
        def post(c,source,path,values):
            status,text,_,_=req(c,source);assert status==200;return req(c,path,{'_token':csrf(text),**values})
        admin=login('admin@example.test');ceo=login('ceo@example.test');tutor=login('tutor@example.test');index='/apprentice-intakes';form=index+'/new'
        status,header,_,headers=req(admin,index+'/template.csv');assert status==200 and header.startswith('reference,first_name,last_name,email,') and headers['Referrer-Policy']=='no-referrer';assert req(tutor,index)[0]==403 and req(client(),index)[2].endswith('/login')
        assert req(admin,index,{'title':'Missing CSRF'})[0]==419
        passed('Actual client-verified HTTPS template/intake are manager-only, guest/ordinary role refused and missing CSRF writes nothing')
        import csv,io,uuid
        fields=header.strip().split(',');base=dict.fromkeys(fields,'');base.update(reference='SYNTHETIC-NEW-A',first_name='Fictional',last_name='Intake A',email='private-a@example.test',programme_code='ST-FICTIONAL',programme_version='1.0',employer_reference='SYNTHETIC-EMPLOYER',group_reference='SYNTHETIC-GROUP',tutor_email='tutor@example.test',start_date='2027-01-11',planned_end_date='2030-01-11')
        second={**base,'reference':'SYNTHETIC-NEW-B','last_name':'Intake B','email':'private-b@example.test'}
        def csv_bytes(rows):
            f=io.StringIO(newline='');writer=csv.DictWriter(f,fieldnames=fields,lineterminator='\n');writer.writeheader();writer.writerows(rows);return f.getvalue().encode()
        def upload(c,rows,request):
            status,text,_,_=req(c,form);assert status==200;boundary='SyntheticBoundary'+uuid.uuid4().hex;parts=[]
            for key,value in {'_token':csrf(text),'request_token':request,'title':'Fictional complete intake','reference':'PRIVATE-SOURCE-REFERENCE','confirmed':'1'}.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="intake.csv"\r\nContent-Type: text/csv\r\n\r\n'.encode()+csv_bytes(rows)+f'\r\n--{boundary}--\r\n'.encode())
            try:
                with c.open(Request(origin+index,data=b''.join(parts),headers={'Content-Type':f'multipart/form-data; boundary={boundary}'}),timeout=30)as response:return response.status,response.read().decode(),response.url,response.headers
            except HTTPError as error:return error.code,error.read().decode(),error.url,error.headers
        status,text,_,_=req(admin,index+'/catalogue?kind=staff');assert status==200 and 'tutor@example.test'in text
        passed('Actual active catalogue lookup supplies exact reference/email choices without creating directory entries')
        status,text,url,_=upload(admin,[{**base,'programme_code':'MISSING'}],str(uuid.uuid4()));assert status==200 and 'Blocked:'in text;bad=url[len(origin):]
        post(ceo,bad,bad,{'version':1,'decision':'apply','note':'Fictional invalid attempt','confirmed':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM apprentices').fetchone()[0]==0
        passed('Real multipart CSV upload retains semantic row errors; attempted application creates no partial profiles')
        request=str(uuid.uuid4());status,text,url,_=upload(admin,[base,second],request);assert status==200 and 'ready for independent check'in text;detail=url[len(origin):];batch=detail.rsplit('/',1)[1]
        status,text,same_url,_=upload(admin,[base,second],request);assert same_url==url
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM apprentice_intake_batches').fetchone()[0]==2;assert c.execute('SELECT COUNT(*) FROM apprentices').fetchone()[0]==0;assert 'private-a'not in c.execute('SELECT source FROM apprentice_intake_batches WHERE id=?',(batch,)).fetchone()[0]
        passed('Encrypted two-person intake preview is idempotent under upload replay and creates no profiles yet')
        decision={'version':1,'decision':'apply','note':'PRIVATE-INDEPENDENT-CHECK','confirmed':1};post(admin,detail,detail,decision)
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM apprentice_intake_batches WHERE id=?',(batch,)).fetchone()[0]=='Pending'
        script(r"$u=App\Models\User::find(TUTOR);$u->record_version++;$u->save();echo '{}';".replace('TUTOR',str(ids['tutor'])))
        post(ceo,detail,detail,decision)
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM apprentices').fetchone()[0]==0
        passed('Creator cannot self-apply; actual staff record drift blocks whole preview until a fresh upload')
        status,text,url,_=upload(admin,[base,second],str(uuid.uuid4()));assert status==200;current=url[len(origin):];current_id=current.rsplit('/',1)[1];post(ceo,current,current,decision)
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM apprentices').fetchone()[0]==2;assert c.execute('SELECT COUNT(*) FROM assignment_change_events').fetchone()[0]==2;assert c.execute('SELECT COUNT(*) FROM users').fetchone()[0]==3;assert c.execute('SELECT COUNT(*) FROM learning_tasks').fetchone()[0]==0;assert c.execute('SELECT COUNT(*) FROM programme_outcomes').fetchone()[0]==0
        status,text,_,_=req(admin,current);assert 'Live setup for this intake'in text and 'Login not linked'in text and 'No task workspace allocated'in text and 'Link account / invitation setup'in text
        passed('Independent application creates all named profiles and canonical assignment history; no accounts/tasks/Active programme status inferred; live setup actions shown')
        post(ceo,detail,detail,decision)
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM apprentices').fetchone()[0]==2
        passed('Earlier same-reference preview cannot create duplicates after checked application')
        status,text,_,_=req(admin,current+'/setup.json');report=json.loads(text);assert status==200 and report['applied_proof_verified']is True and report['private_source_names_emails_and_notes_exported']is False and 'private-a'not in text and 'PRIVATE-'not in text and report['production_approved']is False
        assert req(tutor,current+'/setup.json')[0]==403
        passed('Minimal manager-only setup report omits private names/emails/source notes and denies production approval')
        stop_backend();start_backend();assert 'Live setup for this intake'in req(admin,current)[1]
        passed('Encrypted session/cache and historical applied proof survive controlled restart')

        readiness=index+'/readiness?search=SYNTHETIC-NEW-A&issue=account'
        status,text,_,_=req(admin,readiness);assert status==200 and 'Cohort setup readiness'in text and 'private-a@example.test'not in text
        source_hash=re.search(r'name="source_hash" value="([a-f0-9]{64})"',text)[1]
        setup=script(r"$a=App\Models\Apprentice::where('reference','SYNTHETIC-NEW-A')->firstOrFail();echo json_encode(['id'=>$a->id,'due'=>today()->addDays(7)->toDateString()]);")
        case_data={'version':0,'source_hash':source_hash,'issue':'account','owner_id':ids['ceo'],'due_on':setup['due'],'note':'PRIVATE-SETUP-FOLLOWUP','confirmed':1}
        status,text,case_url,_=post(admin,readiness,f"/apprentices/{setup['id']}/setup-actions",case_data);assert status==200 and 'PRIVATE-SETUP-FOLLOWUP'in text;case=case_url[len(origin):];case_id=case.rsplit('/',1)[1]
        assert req(tutor,readiness)[0]==403 and req(tutor,case)[0]==403
        with sqlite3.connect(db)as c:assert 'PRIVATE-SETUP'not in c.execute('SELECT details FROM apprentice_setup_cases WHERE id=?',(case_id,)).fetchone()[0]
        passed('Existing-cohort readiness filters real saved profiles; manager-only source-bound owned follow-up encrypts notes without learner-email exposure')
        post(admin,case,case+'/decision',{'version':1,'source_hash':source_hash,'decision':'resolve','note':'Fictional invalid override','confirmed':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM apprentice_setup_cases WHERE id=?',(case_id,)).fetchone()[0]=='Open'
        post(admin,case,case+'/decision',{'version':1,'source_hash':source_hash,'decision':'defer','note':'Fictional pending account setup','confirmed':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM apprentice_setup_cases WHERE id=?',(case_id,)).fetchone()[0]=='Deferred'
        assert 'Deferred; setup flag remains'in req(admin,case)[1]
        passed('Present automatic flag prevents false resolution; checked deferral keeps actual flag visible')
        script(r"$a=App\Models\Apprentice::where('reference','SYNTHETIC-NEW-A')->firstOrFail();$u=App\Models\User::factory()->create(['role'=>'apprentice','email'=>$a->email,'email_verified_at'=>now()]);$a->user_id=$u->id;$a->version++;$a->save();echo '{}';")
        post(admin,case,case+'/decision',{'version':2,'source_hash':source_hash,'decision':'resolve','note':'Fictional stale form','confirmed':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM apprentice_setup_cases WHERE id=?',(case_id,)).fetchone()[0]=='Deferred'
        status,text,_,_=req(admin,case);fresh_hash=re.search(r'name="source_hash" value="([a-f0-9]{64})"',text)[1]
        post(admin,case,case+'/decision',{'version':2,'source_hash':fresh_hash,'decision':'resolve','note':'Fictional actual account source checked','confirmed':1})
        assert 'Resolved against current source'in req(admin,case)[1]
        passed('Actual corrected account source permits resolution only after reloading current hash; stale pre-fix decision is refused')
        script(r"$a=App\Models\Apprentice::where('reference','SYNTHETIC-NEW-A')->firstOrFail();$u=App\Models\User::findOrFail($a->user_id);$u->email_verified_at=null;$u->save();echo '{}';")
        assert 'Flag present / needs recheck'in req(admin,case)[1]
        status,text,_,_=req(admin,index+'/readiness.json?search=SYNTHETIC-NEW-A');value=json.loads(text);assert status==200 and value['private_names_emails_notes_exported']is False and 'private-a'not in text and 'PRIVATE-SETUP'not in text
        assert req(admin,index+'/actions?owner='+str(ids['ceo']))[0]==200
        passed('Later source drift invalidates old resolution; owner worklist and filtered minimal export preserve automatic flags without private notes')
        script(r"App\Models\Apprentice::where('reference','SYNTHETIC-NEW-A')->update(['reference'=>'CHANGED-IDENTITY']);echo '{}';")
        assert 'Created profile identity changed or is unavailable'in req(admin,current)[1]
        passed('Current cohort detects changed created-profile identity instead of silently relinking by name')
        post(admin,bad,bad,{'version':1,'decision':'withdraw','note':'Fictional corrected source needed','confirmed':1})
        passed('Invalid retained preview can be explicitly withdrawn without rewriting created learner data')
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('timetable_delivery_records')").fetchone()[0]==0;assert c.execute('SELECT COUNT(*) FROM apprentices').fetchone()[0]==2;assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='staff_handover_batches'").fetchone()[0]==1
        assert artisan('migrate','--force').returncode==0
        passed('Disposable latest down/up drops four Pack41 delivery attribution table; existing created profiles and Pack34 schema remain')
        configure('production');start_backend();assert req(admin,index)[0]==503
        passed('Existing production access gate remains unchanged')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual client-verified local HTTPS with multipart CSV uploads; disposable SQLite/encrypted sessions/cache','realDataUsed':False,'externalMessagesSent':False,'actualNativeEngine':False,'actualCloudAccess':False,'actualBrowserOrPhoneTested':False,'productionApproved':False};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps(output))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
