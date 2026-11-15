"""Fictional Pack41 integrated teaching-attribution workflows over a local TLS proxy; never accesses Laravel Cloud.
Uses disposable SQLite with database sessions/cache. Does not certify MySQL or S3.
"""
from pathlib import Path
import argparse, base64, http.client, http.cookiejar, http.server, json, os, re, secrets, signal, socket, sqlite3, ssl, subprocess, tempfile, threading, time
from urllib.request import Request, HTTPSHandler, HTTPCookieProcessor, build_opener
from urllib.parse import urlencode
from urllib.error import HTTPError

parser=argparse.ArgumentParser()
parser.add_argument('--php',default='php');parser.add_argument('--output',required=True);parser.add_argument('--browser',action='store_true')
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
            result=subprocess.run([args.php,'-r',bootstrap+code],cwd=project,env=env,capture_output=True,text=True,timeout=90);assert result.returncode==0,'Private fictional setup failed';return json.loads(result.stdout or '{}')
        ids=script(r"$out=[];foreach(['ceo','admin','planner','tutor','cover','learner']as$key){$u=App\Models\User::factory()->create(['role'=>$key==='planner'?'manager':($key==='cover'?'tutor':($key==='learner'?'apprentice':$key)),'name'=>'Fictional '.ucfirst($key),'email'=>$key.'@example.test','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);$out[$key]=$u->id;}$u=App\Models\User::find($out['admin']);$g=App\Models\TutorGroup::factory()->create(['name'=>'Fictional whole-day group','tutor_id'=>$out['tutor']]);$a=App\Models\Apprentice::factory()->create(['user_id'=>$out['learner'],'email'=>'learner@example.test','tutor_group_id'=>$g->id,'tutor_id'=>$out['tutor'],'start_date'=>'2026-09-07']);$room=App\Models\TimetableRoom::where('site','Doncaster')->first();App\Support\CentrePlanning::room($u,1,$room,1,['capacity'=>8,'uses'=>['Teaching','Admin','Review'],'active'=>true]);$date=today()->addDays(7)->startOfWeek()->addDay()->toDateString();$dates=App\Support\CentrePlanning::dates($date,2);foreach(['tutor','cover']as$key){$who=App\Models\User::find($out[$key]);App\Support\CentrePlanning::windows($u,1,$who,$dates,App\Support\ReviewedImports::hash(App\Support\CentrePlanning::windowSource($who,$dates)),true,'08:00','18:00');}$d=['staff_id'=>$out['tutor'],'date'=>$date,'weeks'=>2,'start'=>'09:00','end'=>'16:00','kind'=>'Teaching','site'=>'Doncaster','room_id'=>$room->id,'group_id'=>$g->id,'headcount'=>4,'title'=>'Fictional full-day teaching'];$p=App\Support\CentrePlanning::save($u,1,null,0,(string)Illuminate\Support\Str::uuid(),$d);App\Support\CentrePlanning::check($u,1,$p,1,true);App\Support\CentrePlanning::publish($u,1,$p->refresh(),2);$out['entry']=App\Models\TimetablePlanEntry::where('plan_id',$p->id)->orderBy('work_date')->first()->id;$out['group']=$g->id;$out['a']=$a->id;$out['room']=$room->id;$out['date']=$date;echo json_encode($out);")
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            assert req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password})[0]==200;return c
        def post(c,source,path,values):
            status,text,_,_=req(c,source);assert status==200
            return req(c,path,[('_token',csrf(text)),*[(k,str(v))for k,v in values.items()]])
        def hidden(text,name):
            m=re.search(r'name="'+re.escape(name)+r'"\s+value="([^"]*)"',text);assert m,'Real form field missing';return m[1]
        import uuid
        admin=login('admin@example.test');planner=login('planner@example.test');tutor=login('tutor@example.test');cover=login('cover@example.test');learner=login('learner@example.test');root='/centre-planning';setup=root+'/setup';flex=root+'/flexible-duties?'+urlencode({'staff_id':ids['cover'],'date':ids['date'],'weeks':2});board=root+'?'+urlencode({'week':ids['date'],'resource':ids['cover']})
        assert req(planner,root+'/cover')[0]==403 and req(learner,root)[0]==403 and req(admin,root+'/flexible-duties',{'staff_id':ids['cover']})[0]==419
        assert post(admin,setup,root+'/access/'+str(ids['planner']),{'version':0,'active':1,'confirmed':1})[0]==200
        assert req(planner,root+'/cover')[0]==200 and req(cover,root+'/cover')[0]==403
        passed('Real logins, separate centre-planner grant, active-role checks and missing-CSRF refusal preserve staff/learner boundaries')
        groups=root+'/group-details';assert post(planner,groups,groups+'/'+str(ids['group']),{'version':0,'start_date':'2026-09-01','notes':'SHARED-SAFE-NOTE','confirmed':1})[0]==200
        text=req(tutor,root+'?week='+ids['date'])[1];assert 'SHARED-SAFE-NOTE'in text and 'Recorded group start'in text and 'currently allocated'in text and 'planning-compact'in text
        with sqlite3.connect(db)as c:assert c.execute('SELECT DATE(start_date) FROM apprentices WHERE id=?',(ids['a'],)).fetchone()[0]=='2026-09-07';assert 'SHARED-SAFE-NOTE'not in c.execute('SELECT details FROM timetable_group_briefs').fetchone()[0]
        passed('Shared group brief/count/start context appears on full-day cards, remains encrypted and does not rewrite apprentice starts')
        _,text,_,_=req(planner,flex);hash=hidden(text,'source_hash')
        d={'staff_id':ids['cover'],'owner_id':ids['planner'],'date':ids['date'],'weeks':2,'start':'09:00','end':'16:00','source_hash':hash,'confirmed':1}
        assert post(planner,flex,root+'/flexible-duties',d)[0]==200
        text=req(cover,board)[1];assert 'Flexible Duties Day'in text and 'not a guaranteed non-teaching day'in text and 'Flexible duties — unallocated'in text
        with sqlite3.connect(db)as c:day=c.execute('SELECT id FROM timetable_flexible_days WHERE staff_id=? ORDER BY work_date',(ids['cover'],)).fetchone()[0];assert c.execute('SELECT COUNT(*) FROM timetable_flexible_days').fetchone()[0]==2;assert c.execute('SELECT COUNT(*) FROM training_sessions').fetchone()[0]==0
        passed('Exact two dated Flexible Duties Days use checked working windows and remain unallocated manager capacity rather than a protected non-teaching block')
        form=root+'/plans/new';_,text,_,_=req(planner,form);assert 'value="09:00"'in text and 'value="16:00"'in text
        marking={'request_token':str(uuid.uuid4()),'version':0,'staff_id':ids['cover'],'date':ids['date'],'weeks':1,'start':'10:00','end':'11:00','kind':'Marking','site':'Remote','title':'Fictional allocated marking','confirmed':1}
        status,_,url,_=post(planner,form,root+'/plans',marking);assert status==200;markingdetail=url[len(origin):]
        post(planner,markingdetail,markingdetail+'/decision',{'version':1,'action':'check','confirmed':1});post(planner,markingdetail,markingdetail+'/decision',{'version':2,'action':'publish','confirmed':1})
        assert 'Fictional allocated marking'in req(cover,board)[1]
        passed('Normal planning form offers09:00–16:00 defaults and allocates marking through existing checked publication without completing marking tasks')
        prepare={'entry_id':ids['entry'],'day_id':day,'request_token':str(uuid.uuid4()),'reference':'PRIVATE-COVER-SUITABILITY','suitability_confirmed':1,'confirmed':1}
        status,_,url,_=post(planner,root+'/cover?date='+ids['date'],root+'/cover',prepare);assert status==200;detail=url[len(origin):];cid=detail.rsplit('/',1)[-1]
        post(planner,detail,detail+'/decision',{'version':1,'action':'check','confirmed':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT state,check_proof FROM timetable_cover_allocations WHERE id=?',(cid,)).fetchone()==('Draft',None)
        passed('Cover proposal cannot pass checks while marking overlaps the proposed full-day class')
        post(planner,markingdetail,markingdetail+'/decision',{'version':3,'action':'withdraw','reason':'Marking rescheduled separately','confirmed':1})
        post(planner,detail,detail+'/decision',{'version':1,'action':'check','confirmed':1});post(planner,detail,detail+'/decision',{'version':2,'action':'publish','confirmed':1})
        text=req(cover,board)[1];assert 'Fictional full-day teaching'in text and 'Cover: Fictional Cover'in text and 'PRIVATE-COVER-SUITABILITY'not in text
        assert req(cover,'/apprentices/'+str(ids['a']))[0]==403
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM timetable_cover_allocations WHERE id=?',(cid,)).fetchone()[0]=='Active';assert c.execute('SELECT tutor_id FROM tutor_groups WHERE id=?',(ids['group'],)).fetchone()[0]==ids['tutor'];assert c.execute('SELECT tutor_id FROM apprentices WHERE id=?',(ids['a'],)).fetchone()[0]==ids['tutor'];assert c.execute('SELECT COUNT(*) FROM attendance_marks').fetchone()[0]==0
        passed('Checked publication moves exactly one planned class to named cover Tutor while retaining permanent Tutor, apprentice scope and register boundaries')
        ceo=login('ceo@example.test');gov=root+'/governance'
        def revision():
            with sqlite3.connect(db)as c:return c.execute('SELECT revision FROM timetable_governance_state WHERE id=1').fetchone()[0]
        def decision(c,path,**kw):return post(c,gov,path,dict(version=kw.pop('version',0),revision=revision(),note='Fictional governance review',confirmed=1,**kw))
        text=req(tutor,root+'?scope=staff&week='+ids['date'])[1]
        assert 'Staff timetable'in text and 'SHARED-SAFE-NOTE'not in text and 'Fictional full-day teaching'not in text and 'PRIVATE-COVER-SUITABILITY'not in text
        assert req(tutor,'/centre-planning.json?scope=staff')[0]==403 and req(learner,root+'?scope=staff')[0]==403
        passed('Shared staff timetable withholds colleague titles, group notes and cover references; learner access and shared export remain denied')
        before=revision()
        assert decision(admin,gov+'/section/'+str(ids['cover']),section='other')[0]==200
        assert req(cover,gov)[0]==403
        assert decision(admin,gov+'/role/'+str(ids['tutor']),role='centre_manager')[0]==200
        assert req(tutor,gov)[0]==200 and req(tutor,'/portal-setup')[0]==403
        with sqlite3.connect(db)as c:assert c.execute('SELECT role FROM users WHERE id=?',(ids['tutor'],)).fetchone()[0]=='tutor'
        passed('Display section changes confer no rights; explicit Centre Manager grant enables timetable management without changing the canonical Tutor role or private administration')
        assert post(admin,gov,gov+'/role/'+str(ids['cover']),dict(version=0,revision=before,role='centre_manager',note='Stale',confirmed=1))[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM timetable_governance_roles WHERE user_id=?',(ids['cover'],)).fetchone()[0]==0
        assert decision(ceo,gov+'/role/'+str(ids['tutor']),version=1,role='centre_manager',ceo_locked=1)[0]==200
        assert decision(admin,gov+'/role/'+str(ids['tutor']),version=2,role='viewer')[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT role,version FROM timetable_governance_roles WHERE user_id=?',(ids['tutor'],)).fetchone()==('centre_manager',2)
        passed('Stale global revisions cannot overwrite another manager; subordinate direct requests cannot reverse CEO-protected access')
        assert decision(ceo,gov+'/pause',paused=1)[0]==200
        assert req(planner,root+'/flexible-duties')[0]==403 and req(admin,setup)[0]==403 and req(ceo,setup)[0]==200
        assert decision(ceo,gov+'/pause',paused=0)[0]==200 and req(planner,root+'/flexible-duties')[0]==200
        passed('CEO pause blocks subordinate managers immediately while preserving CEO control and published diary reservations; explicit restore re-enables permitted editing')
        with sqlite3.connect(db)as c:pid=c.execute('SELECT plan_id FROM timetable_plan_entries WHERE id=?',(ids['entry'],)).fetchone()[0]
        assert decision(ceo,gov+'/allocation/'+pid,restricted=1,ceo_locked=1)[0]==200
        assert post(planner,detail,detail+'/decision',dict(version=3,action='withdraw',reason='Blocked direct cover change',confirmed=1))[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM timetable_cover_allocations WHERE id=?',(cid,)).fetchone()[0]=='Active'
        assert decision(ceo,gov+'/allocation/'+pid,version=1)[0]==200
        assert decision(ceo,gov+'/role/'+str(ids['tutor']),version=2,role='viewer')[0]==200 and req(tutor,gov)[0]==403
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT COUNT(*) FROM timetable_governance_events').fetchone()[0]>=9
            assert 'Fictional governance review'not in c.execute('SELECT payload FROM timetable_governance_events ORDER BY id DESC').fetchone()[0]
        passed('Series protection also blocks cover withdrawal; CEO reversals keep encrypted mandatory history and revoked managers lose direct access')
        assert post(planner,flex,root+'/flexible-duties/'+str(day)+'/withdraw',{'version':1,'confirmed':1})[0]==200
        text=req(cover,board)[1];assert 'Cover source needs checking'in text
        text=req(tutor,root+'?week='+ids['date'])[1];assert 'Fictional full-day teaching'in text and 'Source changed'in text
        passed('Changed flexible-day source keeps both original and cover time reserved with visible recheck warnings')
        assert post(planner,detail,detail+'/decision',{'version':3,'action':'withdraw','reason':'Checked planning restored','confirmed':1})[0]==200
        assert 'Cover source needs checking'not in req(cover,board)[1]
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM training_sessions').fetchone()[0]==0;assert c.execute('SELECT COUNT(*) FROM signed_review_records').fetchone()[0]==0
        passed('Explicit future cover withdrawal releases only the cover relation, retaining class history and creating no actual delivery/sign-off')
        assert post(admin,setup,root+'/access/'+str(ids['planner']),{'version':1,'active':0,'confirmed':1})[0]==200
        assert req(planner,root+'/flexible-duties')[0]==403 and req(planner,detail)[0]==403
        passed('Current planning-access revocation removes new duty/group/cover planner routes on the next request')
        actual=script("$s=App\\Models\\TrainingSession::factory()->create(['tutor_group_id'=>"+str(ids['group'])+",'created_by'=>"+str(ids['admin'])+",'session_date'=>today()->subDay()->toDateString(),'start_time'=>'09:00','end_time'=>'16:00','breaks'=>[['start'=>'12:00','end'=>'13:00']],'members'=>["+str(ids['a'])+"],'delivered_learning'=>'Fictional completed practical learning']);App\\Models\\AttendanceMark::create(['training_session_id'=>$s->id,'apprentice_id'=>"+str(ids['a'])+",'code'=>'O','arrival'=>'09:00','departure'=>'16:00','recorded_by'=>"+str(ids['admin'])+"]);echo json_encode(['session'=>$s->id,'date'=>$s->session_date->toDateString()]);")
        measures=root+'/management?'+urlencode({'from':actual['date'],'to':actual['date']});confirm_url=root+'/management/'+str(actual['session'])+'/confirm'
        assert req(cover,measures)[0]==403 and req(learner,measures)[0]==403
        status,text,_,headers=req(admin,measures);assert status==200 and 'Delivery attribution unconfirmed'in text and headers['Referrer-Policy']=='no-referrer'
        hash=hidden(text,'source_hash')
        with sqlite3.connect(db)as c:
            before_session=c.execute('SELECT * FROM training_sessions WHERE id=?',(actual['session'],)).fetchone();before_marks=c.execute('SELECT * FROM attendance_marks WHERE training_session_id=?',(actual['session'],)).fetchall()
        passed('An ended fully recorded class is unconfirmed until an explicit actual-tutor decision; ordinary staff and apprentice management requests are denied')
        payload=dict(version=0,staff_id=ids['cover'],source_hash=hash,cover=1,reason='PRIVATE-ACTUAL-TEACHER-REFERENCE',confirmed=1)
        assert post(admin,measures,confirm_url,payload)[0]==200
        text=req(admin,measures)[1];assert 'Delivery attributed to Fictional Cover'in text and 'PRIVATE-ACTUAL-TEACHER-REFERENCE'not in text
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT staff_id,version FROM timetable_delivery_records').fetchone()==(ids['cover'],1)
            assert c.execute('SELECT * FROM training_sessions WHERE id=?',(actual['session'],)).fetchone()==before_session
            assert c.execute('SELECT * FROM attendance_marks WHERE training_session_id=?',(actual['session'],)).fetchall()==before_marks
            assert 'PRIVATE-ACTUAL'not in c.execute('SELECT details FROM timetable_delivery_records').fetchone()[0]
        passed('Actual cover receives encrypted attribution independently of current group owner and mark recorder, with register and attendance source bytes unchanged')
        assert post(admin,measures,confirm_url,payload)[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT version FROM timetable_delivery_records').fetchone()[0]==1
        passed('Replaying an old attribution version does not duplicate or overwrite the existing decision')
        script("App\\Models\\AttendanceMark::where('training_session_id',"+str(actual['session'])+")->update(['departure'=>'15:00']);")
        text=req(admin,measures)[1];assert 'Delivery attribution unconfirmed'in text
        newhash=hidden(text,'source_hash');assert newhash!=hash
        payload.update(version=1)
        assert post(admin,measures,confirm_url,payload)[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT version FROM timetable_delivery_records').fetchone()[0]==1
        payload.update(source_hash=newhash,staff_id=ids['tutor'],cover=0,reason='Fictional checked correction')
        assert post(admin,measures,confirm_url,payload)[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT staff_id,version FROM timetable_delivery_records').fetchone()==(ids['tutor'],2);assert c.execute("SELECT COUNT(*) FROM timetable_duty_events WHERE event='delivery_confirmed'").fetchone()[0]==2
        passed('Authoritative attendance correction holds the old source, rejects a stale hash and accepts a versioned recheck while retaining both audit decisions')
        assert decision(ceo,gov+'/pause',paused=1)[0]==200
        assert req(admin,measures)[0]==200 and 'Record actual attribution'not in req(admin,measures)[1]
        payload.update(version=2)
        assert post(admin,measures,confirm_url,payload)[0]==403
        assert decision(ceo,gov+'/pause',paused=0)[0]==200
        passed('Pack40 CEO editing pause applies to actual-attribution writes while management read access remains available')
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT COUNT(*) FROM timetable_staff_sections').fetchone()[0]==1
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='timetable_staff_settings'").fetchone()[0]==0
            assert c.execute('SELECT COUNT(*) FROM signed_review_records').fetchone()[0]==0
        passed('The merged code keeps the existing Pack40 sections and avoids the competing staff-settings table, automatic registers, OTJ or review sign-off')
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('account_lifecycle_proposals','account_lifecycle_states','cohort_forecasts','cohort_snapshots','operational_followups','portal_operations_events')").fetchone()[0]==0;assert c.execute('SELECT COUNT(*) FROM timetable_plans').fetchone()[0]==2;assert c.execute('SELECT COUNT(*) FROM timetable_rooms').fetchone()[0]==18;assert c.execute('SELECT COUNT(*) FROM timetable_staff_windows').fetchone()[0]==4
        assert artisan('migrate','--force').returncode==0
        passed('Disposable latest down/up drops only six Pack43 operations tables while retaining Pack38 rooms/windows/plans and real earlier schemas')
        configure('production');start_backend();assert req(admin,root)[0]==503
        passed('Existing production-access gate remains enforced')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual local client-verified HTTPS with disposable SQLite/encrypted database sessions/cache','actualCloudAccess':False,'actualNativeEngine':False,'actualBrowserOrPhoneTested':False,'realDataUsed':False,'externalMessagesSent':False,'productionApproved':False,'registerAccessGrantedByCover':False,'newGovernanceChecks':5,'newPack41Checks':6};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps(output))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
