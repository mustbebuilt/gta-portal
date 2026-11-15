"""Fictional Pack36 session operations workflows over a local TLS proxy; never accesses Laravel Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-session-operations-') as temporary:
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
        ids=script(r"$out=[];foreach(['admin','ceo','tutor','other']as$key){$u=App\Models\User::factory()->create(['role'=>in_array($key,['admin','ceo'])?$key:'tutor','email'=>$key.'@example.test','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);$out[$key]=$u->id;}$g=App\Models\TutorGroup::factory()->create(['tutor_id'=>$out['tutor'],'name'=>'SYNTHETIC-GROUP']);$out['group']=$g->id;$a=App\Models\Apprentice::factory()->create(['reference'=>'SYNTHETIC-A','tutor_id'=>$out['tutor'],'tutor_group_id'=>$g->id,'email'=>'PRIVATE-LEARNER@example.test']);$b=App\Models\Apprentice::factory()->create(['reference'=>'SYNTHETIC-B','tutor_id'=>$out['tutor'],'tutor_group_id'=>$g->id,'start_date'=>today()->addDays(30)]);$out['a']=$a->id;$out['b']=$b->id;foreach(['s1'=>14,'s2'=>21,'past'=>-2]as$key=>$days){$s=App\Models\TrainingSession::factory()->create(['tutor_group_id'=>$g->id,'created_by'=>$out['admin'],'session_date'=>today()->addDays($days),'members'=>[$a->id],'name'=>'SYNTHETIC-'.$key]);$out[$key]=$s->id;}$og=App\Models\TutorGroup::factory()->create(['tutor_id'=>$out['other']]);$oa=App\Models\Apprentice::factory()->create(['tutor_id'=>$out['other'],'tutor_group_id'=>$og->id]);App\Models\TrainingSession::factory()->create(['tutor_group_id'=>$og->id,'session_date'=>today()->addDays(25),'members'=>[$oa->id],'name'=>'PRIVATE-OTHER-SESSION']);echo json_encode($out);")
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            assert req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password})[0]==200;return c
        def post(c,source,path,values):
            status,text,_,_=req(c,source);assert status==200;pairs=[('_token',csrf(text))]
            for key,value in values.items():
                if isinstance(value,list):pairs.extend((key,str(x))for x in value)
                else:pairs.append((key,str(value)))
            return req(c,path,pairs)
        import uuid
        admin=login('admin@example.test');ceo=login('ceo@example.test');tutor=login('tutor@example.test');other=login('other@example.test');calendar='/session-operations';changes=calendar+'/changes';form=changes+'/new?group='+str(ids['group'])
        status,text,_,headers=req(tutor,calendar);assert status==200 and 'SYNTHETIC-s1'in text and 'PRIVATE-OTHER-SESSION'not in text and 'PRIVATE-LEARNER'not in text and headers['Referrer-Policy']=='no-referrer'
        assert req(tutor,form)[0]==403 and req(admin,form)[0]==200 and req(admin,changes,{'mode':'move'})[0]==419
        passed('Actual client-verified HTTPS calendar is scope-filtered; ordinary tutors cannot preview changes and missing CSRF writes nothing')
        move={'request_token':str(uuid.uuid4()),'mode':'move','group_id':ids['group'],'sessions[]':[ids['s1']],'shift_days':1,'reason':'PRIVATE-MOVE-REFERENCE','confirmed':1}
        status,text,url,_=post(admin,form,changes,move);assert status==200 and 'Exact session'in text;detail=url[len(origin):];batch=detail.rsplit('/',1)[1]
        with sqlite3.connect(db)as c:before=c.execute('SELECT session_date,start_time,end_time,breaks,members FROM training_sessions WHERE id=?',(ids['s1'],)).fetchone();assert c.execute('SELECT COUNT(*) FROM attendance_marks').fetchone()[0]==0;assert 'PRIVATE-MOVE'not in c.execute('SELECT source FROM timetable_change_batches WHERE id=?',(batch,)).fetchone()[0]
        decision={'version':1,'decision':'apply','note':'PRIVATE-INDEPENDENT-CHECK','confirmed':1};post(admin,detail,detail,decision)
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM timetable_change_batches WHERE id=?',(batch,)).fetchone()[0]=='Pending'
        post(ceo,detail,detail,decision)
        with sqlite3.connect(db)as c:after=c.execute('SELECT session_date,start_time,end_time,breaks,members FROM training_sessions WHERE id=?',(ids['s1'],)).fetchone();assert after[0]!=before[0] and after[1:]==before[1:];assert c.execute('SELECT COUNT(*) FROM attendance_marks').fetchone()[0]==0
        passed('Encrypted exact date preview requires another manager; apply preserves times/breaks/roster and creates no attendance or OTJ')
        roster={**move,'request_token':str(uuid.uuid4()),'mode':'roster','sessions[]':[ids['s1'],ids['s2']],'shift_days':0,'reason':'PRIVATE-ROSTER-REFERENCE'}
        status,text,url,_=post(admin,form,changes,roster);assert status==200 and 'SYNTHETIC-B'in text and 'Check training-date context'in text;old_roster=url[len(origin):]
        status,text,same_url,_=post(admin,form,changes,roster);assert same_url==url
        script(r"App\Models\Apprentice::factory()->create(['reference'=>'SYNTHETIC-C','tutor_id'=>TUTOR,'tutor_group_id'=>GROUP]);echo '{}';".replace('TUTOR',str(ids['tutor'])).replace('GROUP',str(ids['group'])))
        post(ceo,old_roster,old_roster,decision)
        with sqlite3.connect(db)as c:assert len(json.loads(c.execute('SELECT members FROM training_sessions WHERE id=?',(ids['s1'],)).fetchone()[0]))==1
        passed('Roster preview shows exact additions/training-date warnings and replay is idempotent; new group member makes old whole-batch preview stale')
        roster['request_token']=str(uuid.uuid4());status,text,url,_=post(admin,form,changes,roster);assert status==200;fresh_roster=url[len(origin):];post(ceo,fresh_roster,fresh_roster,decision)
        with sqlite3.connect(db)as c:
            for sid in [ids['s1'],ids['s2']]:assert len(json.loads(c.execute('SELECT members FROM training_sessions WHERE id=?',(sid,)).fetchone()[0]))==3
            assert len(json.loads(c.execute('SELECT members FROM training_sessions WHERE id=?',(ids['past'],)).fetchone()[0]))==1
            assert c.execute('SELECT COUNT(*) FROM attendance_marks').fetchone()[0]==0
        passed('Fresh independently checked roster reconciliation changes both future saved rosters atomically while the historical roster remains intact')
        cancel={**move,'request_token':str(uuid.uuid4()),'mode':'cancel','sessions[]':[ids['s2']],'shift_days':0,'reason':'Fictional future cancellation'}
        status,text,url,_=post(admin,form,changes,cancel);assert status==200;cancel_detail=url[len(origin):];post(ceo,cancel_detail,cancel_detail,decision)
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM training_sessions WHERE id=?',(ids['s2'],)).fetchone()[0]=='Cancelled';assert c.execute('SELECT COUNT(*) FROM attendance_marks').fetchone()[0]==0
        passed('Future cancellation is separately previewed and checked, without inventing absences, register marks or hours')
        with sqlite3.connect(db)as c:batch_count=c.execute('SELECT COUNT(*) FROM timetable_change_batches').fetchone()[0]
        post(admin,form,changes,{**move,'request_token':str(uuid.uuid4()),'sessions[]':[ids['past']]})
        script(r"App\Models\AttendanceMark::create(['training_session_id'=>SESSION,'apprentice_id'=>PERSON,'recorded_by'=>TUTOR,'code'=>null]);echo '{}';".replace('SESSION',str(ids['s1'])).replace('PERSON',str(ids['a'])).replace('TUTOR',str(ids['tutor'])))
        post(admin,form,changes,{**move,'request_token':str(uuid.uuid4())})
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM timetable_change_batches').fetchone()[0]==batch_count
        passed('Past and already-marked future sessions are refused before preview; existing mark rows are never cleared or moved')
        action=f"/sessions/{ids['past']}/follow-up";status,text,_,_=req(tutor,action);assert status==200;source_hash=re.search(r'name="source_hash" value="([a-f0-9]{64})"',text)[1]
        due=script("echo json_encode(today()->addDays(7)->toDateString());")
        post(tutor,action,action,{'version':0,'source_hash':source_hash,'owner_id':ids['tutor'],'due_on':due,'note':'PRIVATE-REGISTER-FOLLOWUP','confirmed':1})
        assert req(other,action)[0]==403
        with sqlite3.connect(db)as c:assert 'PRIVATE-REGISTER'not in c.execute('SELECT details FROM register_followup_cases').fetchone()[0];assert c.execute('SELECT COUNT(*) FROM attendance_marks WHERE training_session_id=?',(ids['past'],)).fetchone()[0]==0
        passed('Current scoped tutor creates an encrypted owned register action; other tutor is denied and no absence/attendance is inferred')
        post(tutor,action,action+'/decision',{'version':1,'source_hash':source_hash,'decision':'resolve','note':'Fictional invalid override','confirmed':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM register_followup_cases').fetchone()[0]=='Open'
        post(tutor,action,action+'/decision',{'version':1,'source_hash':source_hash,'decision':'defer','note':'Fictional actual source pending','confirmed':1})
        assert 'Deferred; register flag remains'in req(tutor,action)[1]
        passed('Register action cannot falsely resolve while codes are missing; deferral keeps actual source flag visible')
        register=f"/sessions/{ids['past']}";post(tutor,register,register,{'version':1,'delivered_learning':'Fictional actual register context','marks['+str(ids['a'])+'][code]':'A'})
        with sqlite3.connect(db)as c:assert c.execute('SELECT code,otj_confirmed FROM attendance_marks WHERE training_session_id=?',(ids['past'],)).fetchone()==('A',0)
        post(tutor,action,action+'/decision',{'version':2,'source_hash':source_hash,'decision':'resolve','note':'Fictional stale form','confirmed':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM register_followup_cases').fetchone()[0]=='Deferred'
        status,text,_,_=req(tutor,action);new_hash=re.search(r'name="source_hash" value="([a-f0-9]{64})"',text)[1]
        post(tutor,action,action+'/decision',{'version':2,'source_hash':new_hash,'decision':'resolve','note':'Fictional actual register source checked','confirmed':1})
        assert 'Resolved against current register'in req(tutor,action)[1]
        passed('Actual existing register-save endpoint clears missing-code source; stale action form refuses and fresh checked resolution creates no OTJ')
        post(tutor,register,register,{'version':2,'correction_reason':'Fictional checked register correction','delivered_learning':'Fictional actual register context','marks['+str(ids['a'])+'][code]':'W'})
        assert 'Source changed / needs recheck'in req(tutor,action)[1]
        passed('Later guarded register correction invalidates old follow-up resolution while preserving actual attendance history')
        status,text,_,_=req(tutor,calendar+'/calendar.json');value=json.loads(text);assert status==200 and value['learner_names_comments_and_case_notes_exported']is False and 'PRIVATE-'not in text
        status,text,_,_=req(admin,detail+'/handover.json');assert status==200 and json.loads(text)['historical_application_verified']is True and 'PRIVATE-'not in text and req(tutor,detail+'/handover.json')[0]==403
        passed('Scoped calendar and manager-only historical handover exports omit private learner identities, comments, rosters and action notes')
        stop_backend();start_backend();assert 'Source changed / needs recheck'in req(tutor,action)[1]
        passed('Encrypted shared sessions/cache and source-bound timetable/register history persist across controlled restart')
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('timetable_change_batches','timetable_change_events','register_followup_cases','register_followup_events')").fetchone()[0]==0;assert c.execute('SELECT code FROM attendance_marks WHERE training_session_id=?',(ids['past'],)).fetchone()[0]=='W';assert c.execute('SELECT state FROM training_sessions WHERE id=?',(ids['s2'],)).fetchone()[0]=='Cancelled';assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='apprentice_setup_cases'").fetchone()[0]==1
        assert artisan('migrate','--force').returncode==0
        passed('Disposable latest down/up drops only four operations-history tables; changed dates/rosters/cancellations and actual register facts are not undone')
        configure('production');start_backend();assert req(admin,calendar)[0]==503 and req(tutor,action)[0]==503
        passed('Existing production access gate remains unchanged')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual local client-verified HTTPS with existing canonical register-save/correction endpoints; disposable SQLite/encrypted sessions/cache','realDataUsed':False,'externalMessagesSent':False,'actualNativeEngine':False,'actualCloudAccess':False,'actualBrowserOrPhoneTested':False,'productionApproved':False};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps(output))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
