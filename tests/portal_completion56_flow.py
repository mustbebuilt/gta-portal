"""Fictional Pack53 shared v2 theme plus Pack52 common planned-duration analysis plus Pack51 reviewed nearby-date planning plus Pack50 visual/login refinements and Pack49 reviewed Centre Manager grants and scoped coordination plus retained portal journeys over a local TLS proxy; never accesses Laravel Cloud.
Uses disposable SQLite with database sessions/cache. Does not certify MySQL or S3.
"""
from pathlib import Path
import argparse, base64, http.client, http.cookiejar, http.server, json, os, re, secrets, signal, socket, sqlite3, ssl, subprocess, tempfile, threading, time
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
        annual54=script(r"""$u=App\Models\User::find(ADMIN54);$a=App\Models\User::factory()->create(['role'=>'staff','name'=>'Fictional annual A54']);$b=App\Models\User::factory()->create(['role'=>'staff','name'=>'Fictional annual B54']);$first=today()->addDays(30)->toDateString();$late=today()->addDays(150)->toDateString();$end=today()->addDays(300)->toDateString();foreach([$a,$b]as$who)App\Support\CentrePlanning::windows($u,1,$who,[$first,$late],App\Support\ReviewedImports::hash(App\Support\CentrePlanning::windowSource($who,[$first,$late])),true,'09:00','17:00');$base=['staff_id'=>$b->id,'date'=>$first,'weeks'=>1,'start'=>'09:00','end'=>'10:00','kind'=>'Admin','site'=>'Remote','room_id'=>null,'group_id'=>null,'headcount'=>null,'title'=>'Fictional fixed annual54'];$plans=[];foreach([$base,[...$base,'staff_id'=>$a->id,'date'=>$late,'start'=>'15:00','end'=>'16:00','title'=>'Fictional transferable annual54']]as$args){$p=App\Support\CentrePlanning::save($u,1,null,0,(string)Illuminate\Support\Str::uuid(),$args);App\Support\CentrePlanning::check($u,1,$p,1,true);App\Support\CentrePlanning::publish($u,1,$p->refresh(),2);$plans[]=$p->id;}echo json_encode(['first'=>$first,'late'=>$late,'end'=>$end,'one'=>$a->id,'two'=>$b->id,'plan'=>$plans[1]]);""".replace('ADMIN54',str(ids['admin'])))
        year54=root+'/year';query54=urlencode({'from':annual54['first'],'to':annual54['end']})
        status54,html54,_,head54=req(admin,year54+'?'+query54);assert status54==200 and 'Fictional transferable annual54'in html54 and head54['Referrer-Policy']=='no-referrer'
        with sqlite3.connect(db)as c:count54=[c.execute('SELECT COUNT(*) FROM '+t).fetchone()[0]for t in ['timetable_plans','timetable_optimisation_runs','training_sessions','attendance_marks']]
        values54={'from':annual54['first'],'to':annual54['end'],'plans[]':annual54['plan'],'date_mode':'year','staff_options['+annual54['plan']+'][]':annual54['two']}
        status54,preview54,_,_=post(admin,year54+'?'+query54,year54+'/preview',values54);assert status54==200 and 'Checked full-year proposal available'in preview54 and 'Fictional annual B54'in preview54
        with sqlite3.connect(db)as c:assert count54==[c.execute('SELECT COUNT(*) FROM '+t).fetchone()[0]for t in ['timetable_plans','timetable_optimisation_runs','training_sessions','attendance_marks']]
        passed('The private full-year page and real CSRF preview propose a named staff/date change150 days ahead without changing any reservation or actual delivery record')
        prepare54={**values54,'source_hash':hidden(preview54,'source_hash'),'request_token':hidden(preview54,'request_token'),'reason':'Fictional agreed suitability and arrangements54','confirmed':1,'staff_suitability':1}
        status54,saved54,url54,_=post(admin,year54+'?'+query54,year54+'/prepare',prepare54);assert status54==200 and 'Prepared'in saved54;run54=url54[len(origin):]
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT state FROM timetable_plans WHERE id=?',(annual54['plan'],)).fetchone()[0]=='Published'
            assert 'Fictional agreed suitability'not in c.execute('SELECT details FROM timetable_optimisation_runs WHERE id=?',(run54.rsplit('/',1)[-1],)).fetchone()[0]
        passed('Real prepare records an encrypted exact annual proposal and staff-suitability confirmation while the original stays Published')
        apply54={'version':1,'details_hash':hidden(saved54,'details_hash'),'confirmed':1,'arrangements_checked':1}
        assert post(admin,run54,run54+'/apply',apply54)[0]==200
        observed54=script("$r=App\\Models\\TimetableOptimisationRun::find('"+run54.rsplit('/',1)[-1]+"');$p=App\\Models\\TimetablePlan::find($r->receipt['replacements']['"+annual54['plan']+"']);echo json_encode(['id'=>$p->id,'date'=>$p->source['data']['args']['date'],'staff'=>$p->source['data']['args']['staff_id'],'state'=>$r->state]);")
        assert observed54['date']==annual54['first']and observed54['staff']==annual54['two']and observed54['state']=='Applied'
        with sqlite3.connect(db)as c:
            assert count54[2:]==[c.execute('SELECT COUNT(*) FROM '+t).fetchone()[0]for t in ['training_sessions','attendance_marks']]
            replay54=[c.execute('SELECT COUNT(*) FROM '+t).fetchone()[0]for t in ['timetable_plans','timetable_planning_events']]
        assert post(admin,run54,run54+'/apply',apply54)[0]==200
        with sqlite3.connect(db)as c:assert replay54==[c.execute('SELECT COUNT(*) FROM '+t).fetchone()[0]for t in ['timetable_plans','timetable_planning_events']]
        passed('Explicit HTTP application changes the reviewed annual date/staff together, leaves registers/attendance unchanged and replays without duplicate plans/history')
        status54,move54,_,_=req(admin,year54+'/move/'+observed54['id']+'?'+urlencode({'date':annual54['late'],'staff_id':annual54['two']}));assert status54==200 and 'Check this exact move'in move54 and 'timetable has not changed'in move54
        assert 'value="'+annual54['late']+'"'in req(admin,root+'/plans/new?'+urlencode({'date':annual54['late'],'staff_id':annual54['two'],'kind':'Preparation'}))[1]
        passed('Drag destinations and day-cell Add workload links open prefilled checked forms through read-only HTTP requests rather than silently moving work')
        board54=req(admin,root+'?'+urlencode({'week':annual54['first']}))[1];assert all(v in board54 for v in ['Add workload','data-workload-tools','data-move-workload','Enable optional drag-and-drop shortcuts','timetable-workloads54.js'])
        asset54=req(admin,'/assets/timetable-workloads54.js')[1];assert 'application/x-gta-workload'in asset54 and 'location.assign'in asset54 and 'fetch('not in asset54
        passed('Actual published board and served optional drag script expose both buttons and drag shortcuts; the script navigates to forms and performs no fetch/write')
        help54=req(admin,'/help?category=Timetable')[1];assert all(v in help54 for v in ['Adding a workload to the timetable','Planning the full year','53 weekly dates','drag-and-drop'])
        assert 'Help with this page: Planning the full year'in req(admin,year54)[1]
        passed('Three real role-scoped timetable How-To transcripts are served with contextual links and updated annual/holiday/drag instructions')
        assert req(tutor,year54)[0]==403 and req(learner,year54)[0]==403
        passed('Unallocated Tutor and Apprentice still cannot open the annual planning workspace through direct URLs')
        release54=artisan('gta:release-check54');assert release54.returncode==0;report54=json.loads(release54.stdout);assert report54['pack']=='54'and not report54['actual_target_acceptance']and not report54['writes_performed']
        passed('The cumulative read-only release54 check verifies annual/help capability without certifying live Cloud or browser dragging')

        usability55=script(r"""$t=App\Models\User::factory()->create(['role'=>'tutor','name'=>'Fictional Tutor55']);$u=App\Models\User::factory()->create(['role'=>'apprentice','name'=>'Fictional Learner55','email'=>'learner55@example.test','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);$a=App\Models\Apprentice::factory()->create(['user_id'=>$u->id,'tutor_id'=>$t->id,'first_name'=>'Fictional learner55','last_name'=>'Usability']);$task=App\Models\LearningTask::factory()->create(['apprentice_id'=>$a->id,'status'=>'Draft','title'=>'Fictional draft55']);echo json_encode(['apprentice'=>$a->id,'task'=>$task->id]);""")
        learner55=login('learner55@example.test');learning55='/apprentices/'+str(usability55['apprentice'])+'/learning';task55='/tasks/'+str(usability55['task'])
        with sqlite3.connect(db)as c:counts55=[c.execute('SELECT COUNT(*) FROM '+table).fetchone()[0]for table in ['learning_tasks','learning_events','directory_events','account_onboarding_tokens','employer_apprentice_grants']]
        for client55 in [admin,learner55]:
            status55,html55,_,_=req(client55,learning55+'?status=Approved');assert status55==200 and 'No matching tasks'in html55 and '1 task(s) are already assigned'in html55 and 'Show all assigned tasks'in html55 and 'No tasks assigned'not in html55
        passed('Real Admin and own Apprentice HTTP task filters distinguish no matches from no allocation, retain the existing task and expose the clear-filter action')
        profile55='/apprentices/'+str(usability55['apprentice']);admin_profile55=req(admin,profile55)[1];learner_profile55=req(learner55,profile55)[1]
        assert 'Set up apprentice sign-in'in admin_profile55 and 'My training tasks'in learner_profile55 and 'Set up apprentice sign-in'not in learner_profile55
        assert admin_profile55.index('<h1>')<admin_profile55.index('id="apprentice-next-step"')
        passed('Actual profile HTML puts identity before next steps and restricts sign-in/assignment shortcuts to management while the Apprentice sees personal actions')
        script("$t=App\\Models\\LearningTask::find("+str(usability55['task'])+");$t->status='Referred';$t->submissions=[['version'=>1,'submitted_at'=>now()->toIso8601String(),'answers'=>[],'decision'=>['status'=>'Referred','feedback'=>'Correct the measurements <script>HTTP55</script>','date'=>today()->toDateString(),'tutor_name'=>'Fictional Tutor55','source_fields'=>[]]]];$t->save();echo '{}';")
        page55=req(learner55,task55)[1];assert 'Most recent referral feedback'in page55 and '&lt;script&gt;HTTP55&lt;/script&gt;'in page55 and '<script>HTTP55</script>'not in page55 and 'Submit to tutor'in page55
        passed('An actual referred-task response surfaces the recorded feedback before editing and escapes embedded script text without changing the referred work')
        directory55=req(admin,'/directories/employers?search=UNMATCHED55ZZ')[1];assert 'Clear filters and check existing records'in directory55 and 'Add employer'in directory55 and 'Add your first employer'not in directory55
        assert 'Save employer'in req(admin,'/directories/employers/create')[1]and 'Save staff member'in req(admin,'/directories/staff/create')[1]
        passed('Real filtered directories explain no matches and use specific Add/Save labels rather than encouraging duplicate organisation/staff records')
        employer55=req(admin,'/employer-accounts')[1];assert 'Employer organisation or individual contact?'in employer55 and 'Help with this page: Set up an employer organisation and named contact'in employer55
        assert 'saving a profile' in req(admin,'/help?guide=add-apprentice')[1].lower()
        passed('The employer contact page and real contextual How-To transcripts explain the separate organisation, account, invitation and named authority steps')
        with sqlite3.connect(db)as c:assert counts55==[c.execute('SELECT COUNT(*) FROM '+table).fetchone()[0]for table in ['learning_tasks','learning_events','directory_events','account_onboarding_tokens','employer_apprentice_grants']]
        passed('These interface GETs leave task/allocation/audit/invitation/grant counts unchanged; only explicit fixture setup changed the fictional referral')
        script("$p=App\\Models\\Programme::factory()->create(['standard_code'=>'ST0068','active'=>true]);$a=App\\Models\\Apprentice::find("+str(usability55['apprentice'])+");$a->programme_id=$p->id;$a->start_date=today()->subDays(30);$a->save();echo '{}';")
        journey55=profile55+'/journey';status55,road55,_,_=req(learner55,journey55)
        assert status55==200 and 'journey-lorry55.svg'in road55 and 'Latest recorded point'in road55 and 'data-journey-current-stop'in road55 and 'Follow the apprenticeship road map'in req(learner55,'/help?guide=journey-progress')[1]
        passed('Actual own Apprentice HTTPS roadmap automatically selects the linked Heavy Vehicle lorry and serves its current-point label and journey guide')
        script("$a=App\\Models\\Apprentice::find("+str(usability55['apprentice'])+");$a->programmeRecord->update(['standard_code'=>'ST0499']);echo '{}';")
        road55=req(learner55,journey55)[1];assert 'journey-car55.svg'in road55 and 'journey-lorry55.svg'not in road55
        for asset55 in ['journey-car55.svg','journey-lorry55.svg','journey-marker55.css','journey-marker55.js']:assert req(learner55,'/assets/'+asset55)[0]==200
        with sqlite3.connect(db)as c:assert counts55==[c.execute('SELECT COUNT(*) FROM '+table).fetchone()[0]for table in ['learning_tasks','learning_events','directory_events','account_onboarding_tokens','employer_apprentice_grants']]
        passed('Changing only the fictional linked programme selects the car automatically; four local assets serve successfully and roadmap reads leave task/audit/invitation/grant counts intact')
        status56,journey56,_,headers56=req(learner55,journey55)
        assert status56==200 and 'What happens next?'in journey56 and 'Go to latest recorded point'in journey56 and 'id="journey-current-point"'in journey56 and 'no-store'in headers56['Cache-Control']and headers56['Referrer-Policy']=='no-referrer'
        passed('Actual own Apprentice journey serves the next-action/training/review panel and latest-point anchor with private no-store/no-referrer headers')
        script("$a=App\\Models\\Apprentice::find("+str(usability55['apprentice'])+");App\\Models\\TrainingSession::factory()->create(['members'=>[(string)$a->id],'session_date'=>today()->addDays(2)->toDateString(),'name'=>'Fictional string roster56']);echo '{}';")
        journey56=req(learner55,journey55)[1];assert 'Fictional string roster56'in journey56 and 'They do not move the vehicle along the road'in journey56 and 'Booked review appointment'not in journey56
        assert 'Go to latest recorded point'in req(learner55,'/help?guide=journey-progress')[1]
        passed('A saved numeric-text roster displays the correct next training session while future plans remain separate from milestones; the actual updated How-To is served')
        script("App\\Models\\TrainingSession::where('name','Fictional string roster56')->delete();echo '{}';")
        command56=artisan('gta:release-check56');assert command56.returncode==0;release56=json.loads(command56.stdout);assert release56['pack']=='56'and release56['checks']['journey_overview_present']and not release56['actual_target_acceptance']and not release56['writes_performed']
        passed('The read-only cumulative release56 diagnostic recognises the journey integration without granting production or rendered acceptance')
        script("$a=App\\Models\\Apprentice::find("+str(usability55['apprentice'])+");$a->start_date=null;$a->save();echo '{}';")
        result55=artisan('gta:release-check55');assert result55.returncode==0;release55=json.loads(result55.stdout);assert release55['pack']=='55'and release55['theme_only']is False and release55['actual_target_acceptance']is False and release55['writes_performed']is False
        passed('The read-only cumulative release check identifies usability work correctly without claiming actual Cloud/browser acceptance')

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
        with sqlite3.connect(db)as c:pid=c.execute('SELECT plan_id FROM timetable_plan_entries WHERE id=?',(ids['entry'],)).fetchone()[0]
        planpage=root+'/plans/'+pid;create_url=root+'/entries/'+str(ids['entry'])+'/create-register'
        create_data={'version':3,'planned_learning':'Fictional explicitly planned learning','breaks[0][start]':'12:00','breaks[0][end]':'13:00','breaks_checked':1,'confirmed':1}
        assert post(admin,planpage,create_url,create_data)[0]==200
        with sqlite3.connect(db)as c:
            linked=c.execute('SELECT training_session_id FROM timetable_plan_entries WHERE id=?',(ids['entry'],)).fetchone()[0]
            assert linked and c.execute('SELECT COUNT(*) FROM attendance_marks WHERE training_session_id=?',(linked,)).fetchone()[0]==0
            count_before=c.execute('SELECT COUNT(*) FROM training_sessions').fetchone()[0]
        assert post(admin,planpage,create_url,create_data)[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM training_sessions').fetchone()[0]==count_before
        passed('Explicit future occurrence creation uses the canonical register, links it and safely replays without duplicate sessions or invented attendance')
        privacy_admin='/privacy-administration'
        keys=['controller_contact','data_categories','purposes','lawful_bases','special_category','staff_monitoring','database_engine_provider','database_region','files_region','backup_region_retention','supplier_dpa_subprocessors_transfers','legacy_ionos_copies','retention_holds','rights_alternatives_review']
        def privacy_draft(label):
            values=dict(label=label,privacy_body=('Fictional approved privacy wording for workflow testing only. '*8),contact='privacy@example.test',confirmed=1)
            values.update({'verification['+k+']':'Fictional verified evidence REF-42'for k in keys})
            assert post(admin,privacy_admin,privacy_admin+'/draft',values)[0]==200
            with sqlite3.connect(db)as c:return c.execute('SELECT id,body_hash FROM portal_privacy_notices WHERE label=?',(label,)).fetchone()
        def privacy_revision():
            with sqlite3.connect(db)as c:return c.execute('SELECT revision FROM portal_privacy_state').fetchone()[0]
        notice,notice_hash=privacy_draft('fixture-v1')
        assert 'awaiting verified approval'in req(learner,'/privacy-information')[1]
        assert post(ceo,privacy_admin,privacy_admin+'/'+notice+'/publish',dict(revision=privacy_revision(),confirmed=1))[0]==200
        assert 'Fictional approved privacy wording'in req(learner,'/privacy-information')[1] and 'Fictional verified evidence'not in req(learner,'/privacy-information')[1]
        with sqlite3.connect(db)as c:assert c.execute('SELECT enabled FROM portal_privacy_state').fetchone()[0]==0
        passed('Private drafts remain unpublished until CEO approval with every verification reference; the approved notice is public before login and rollout stays separate')
        assert post(ceo,privacy_admin,privacy_admin+'/rollout',dict(revision=privacy_revision(),enabled=1,privacy_reason='Fictional verified staging rollout',confirmed=1))[0]==200
        assert req(learner,'/account/password')[2].endswith('/privacy')
        ack=dict(notice_id=notice,body_hash=notice_hash,acknowledged=1)
        assert post(learner,'/privacy','/privacy/acknowledge',ack)[0]==200
        assert req(learner,'/account/password')[2].endswith('/account/password')
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM portal_privacy_acknowledgements').fetchone()[0]==1
        passed('Enabling the verified current notice gates protected use until the actual user records this version acknowledgement, without storing blanket consent')
        text=req(learner,'/privacy')[1];token=hidden(text,'request_token')
        assert post(learner,'/privacy','/privacy/concern',dict(notice_id=notice,body_hash=notice_hash,request_token=token,privacy_concern='PRIVATE-PRIVACY-CONCERN',confirmed=1))[0]==200
        assert req(learner,'/account/password')[2].endswith('/privacy') and req(learner,'/privacy-information')[0]==200
        with sqlite3.connect(db)as c:
            case=c.execute('SELECT id FROM portal_privacy_cases WHERE user_id=?',(ids['learner'],)).fetchone()[0]
            assert 'PRIVATE-PRIVACY-CONCERN'not in c.execute('SELECT details FROM portal_privacy_cases WHERE id=?',(case,)).fetchone()[0]
        import datetime
        review_on=(datetime.date.fromisoformat(ids['date'])+datetime.timedelta(days=7)).isoformat()
        assert post(admin,privacy_admin,privacy_admin+'/review/'+case,dict(version=1,action='Alternative',review_on=review_on,privacy_reason='Fictional reviewed alternative',confirmed=1))[0]==200
        assert req(learner,'/account/password')[2].endswith('/account/password')
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM portal_privacy_acknowledgements').fetchone()[0]==1;assert c.execute('SELECT active FROM users WHERE id=?',(ids['learner'],)).fetchone()[0]==1
        passed('A private concern pauses protected use while rights/contact remain; a reviewed dated alternative restores access without a fabricated acknowledgement or deleted account')
        new_notice,new_notice_hash=privacy_draft('fixture-v2')
        assert post(ceo,privacy_admin,privacy_admin+'/'+new_notice+'/publish',dict(revision=privacy_revision(),confirmed=1))[0]==200
        assert req(learner,'/account/password')[2].endswith('/privacy')
        assert post(learner,'/privacy','/privacy/acknowledge',ack)[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM portal_privacy_acknowledgements').fetchone()[0]==1
        assert post(admin,privacy_admin,privacy_admin+'/review/'+case,dict(version=2,action='Resolved',privacy_reason='Fictional concern addressed',confirmed=1))[0]==200
        assert post(learner,'/privacy','/privacy/acknowledge',dict(notice_id=new_notice,body_hash=new_notice_hash,acknowledged=1))[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM portal_privacy_acknowledgements').fetchone()[0]==2
        passed('A newly published notice invalidates the old-version alternative and rejects old-screen acknowledgement; resolution permits the user to acknowledge the new version themselves')
        text=req(learner,'/privacy')[1]
        assert post(learner,'/privacy','/privacy/concern',dict(notice_id=new_notice,body_hash=new_notice_hash,request_token=hidden(text,'request_token'),privacy_concern='Review account retention request',confirmed=1))[0]==200
        with sqlite3.connect(db)as c:case2=c.execute('SELECT id FROM portal_privacy_cases WHERE open_user_id=?',(ids['learner'],)).fetchone()[0]
        assert post(admin,privacy_admin,privacy_admin+'/review/'+case2,dict(version=1,action='Deletion-review',privacy_reason='Human retention/holds review required',confirmed=1))[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT active FROM users WHERE id=?',(ids['learner'],)).fetchone()[0]==1;assert c.execute('SELECT COUNT(*) FROM training_sessions').fetchone()[0]==count_before
        assert req(learner,'/account/password')[2].endswith('/privacy')
        passed('Deletion-review is a human follow-up queue and keeps credentials active-but-paused and retained training evidence intact; no purge is invented')
        text=req(ceo,'/privacy')[1]
        assert post(ceo,'/privacy','/privacy/concern',dict(notice_id=new_notice,body_hash=new_notice_hash,request_token=hidden(text,'request_token'),privacy_concern='Fictional privileged recovery concern',confirmed=1))[0]==200
        assert req(ceo,privacy_admin)[0]==200
        with sqlite3.connect(db)as c:ceocase=c.execute('SELECT id FROM portal_privacy_cases WHERE open_user_id=?',(ids['ceo'],)).fetchone()[0]
        assert post(ceo,privacy_admin,privacy_admin+'/review/'+ceocase,dict(version=1,action='Alternative',review_on=review_on,privacy_reason='Explicit fictional CEO recovery review',recovery=1,confirmed=1))[0]==200
        assert req(ceo,'/account/password')[2].endswith('/account/password')
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM portal_privacy_acknowledgements WHERE user_id=?',(ids['ceo'],)).fetchone()[0]==0
        passed('Privileged privacy recovery remains accessible and explicitly audited, with dated alternative access rather than a forged user acknowledgement')
        # Admin acknowledges the current notice; new operations retain the normal privacy gate.
        assert post(admin,'/privacy','/privacy/acknowledge',dict(notice_id=new_notice,body_hash=new_notice_hash,acknowledged=1))[0]==200
        ops='/account-operations';target_page=ops+'?'+urlencode({'user_id':ids['learner'],'case':case2})
        def account_prepare(action):
            text=req(admin,target_page)[1]
            values=dict(request_token=hidden(text,'request_token'),action=action,source_hash=hidden(text,'source_hash'),review_on=review_on,retention='Fictional approved retention reference',handover='Fictional checked training access alternative',reason='Fictional reviewed access request',case=case2,confirmed=1)
            status,_,url,_=post(admin,target_page,ops+'/user/'+str(ids['learner'])+'/prepare',values);assert status==200;return url[len(origin):]
        archive=account_prepare('Archive')
        assert post(admin,archive,archive+'/review',dict(version=1,accept=1,reference='Self review blocked',confirmed=1))[0]==403
        assert post(ceo,archive,archive+'/review',dict(version=1,accept=1,reference='Independent fictional retention and continuity check',confirmed=1))[0]==200
        assert post(admin,archive,archive+'/apply',dict(version=2,confirmed=1))[0]==200
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT active,auth_version FROM users WHERE id=?',(ids['learner'],)).fetchone()==(0,2)
            assert c.execute('SELECT COUNT(*) FROM sessions WHERE user_id=?',(ids['learner'],)).fetchone()[0]==0
            assert c.execute('SELECT COUNT(*) FROM training_sessions').fetchone()[0]==count_before
            assert c.execute('SELECT state FROM portal_privacy_cases WHERE id=?',(case2,)).fetchone()[0]=='Deletion-review'
        assert req(learner,'/privacy-information')[0]==200
        passed('A privacy-linked archive requires independent review, disables login and revokes sessions while keeping training evidence and the unresolved privacy review')
        status,sealed,_,_=req(admin,ops+'/restrictions.gta');assert status==200 and 'learner@example.test'not in sealed
        # Inspect through the same service with the retained key; transport upload is tested separately in Feature tests.
        inspected=script("$u=App\\Models\\User::find("+str(ids['admin'])+");echo json_encode(App\\Support\\AccountLifecycle::inspectLedger($u,"+json.dumps(sealed)+"));")
        assert inspected['writes_performed']is False and inspected['rows'][0]['login_still_blocked']is True
        passed('Encrypted restriction evidence is exported and authenticated for a read-only post-restore check without revealing account email or reactivating users')
        restore=account_prepare('Restore')
        assert post(ceo,restore,restore+'/review',dict(version=1,accept=1,reference='Independent fictional identity recovery check',confirmed=1))[0]==200
        assert post(admin,restore,restore+'/apply',dict(version=2,confirmed=1))[0]==200
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT active,auth_version FROM users WHERE id=?',(ids['learner'],)).fetchone()==(1,3)
            assert c.execute('SELECT status FROM account_lifecycle_states WHERE user_id=?',(ids['learner'],)).fetchone()[0]=='Active'
            assert c.execute('SELECT state FROM portal_privacy_cases WHERE id=?',(case2,)).fetchone()[0]=='Deletion-review'
        passed('Reviewed restore retains identity with a fresh login version while leaving old credentials, acknowledgement and privacy concern decisions separate')
        # Existing outcome workflow supplies current programme context; no achievement is inferred from tasks.
        outcome='/apprentices/'+str(ids['a'])+'/outcome'
        with sqlite3.connect(db)as c:o=c.execute('SELECT version FROM programme_outcomes WHERE apprentice_id=?',(ids['a'],)).fetchone()
        assert post(admin,outcome,outcome,{'version':o[0]if o else 0,'context[status]':'Active','context[sourceRef]':'Fictional checked programme status'})[0]==200
        task=script("$t=App\\Models\\LearningTask::factory()->create(['apprentice_id'=>"+str(ids['a'])+",'due_date'=>today()->subDay()->toDateString()]);echo json_encode(['id'=>$t->id,'reference'=>$t->reference]);")
        today=(datetime.date.fromisoformat(actual['date'])+datetime.timedelta(days=1)).isoformat()
        filters={'from':today[:4]+'-01-01','to':today};report_page='/reports/cohort-context?'+urlencode(filters)
        status,text,_,headers=req(admin,report_page);assert status==200 and 'What the figures include'in text and headers['Referrer-Policy']=='no-referrer'
        source_hash=hidden(text,'source_hash')
        status,_,url,_=post(admin,report_page,'/reports/cohort-context/snapshots',dict(**filters,source_hash=source_hash,confirmed=1));assert status==200;first=url[len(origin):];first_id=first.rsplit('/',1)[-1]
        raw=req(admin,first+'/aggregate.json')[1];data=json.loads(raw)
        assert data['report']['counts']['cohort']==1 and data['report']['counts']['active']==1 and data['report']['official_qar']is False
        assert 'PRIVATE-'not in raw and 'first_name'not in raw and 'apprentice_id'not in raw
        passed('The current start-date group report shows known and missing records with explicit counts, and saves an aggregate-only report without private learner details or official-QAR claims')
        forecast='/apprentices/'+str(ids['a'])+'/checked-estimate';text=req(admin,forecast)[1];estimate_hash=hidden(text,'source_hash')
        target=(datetime.date.fromisoformat(today)+datetime.timedelta(days=60)).isoformat()
        review=(datetime.date.fromisoformat(today)+datetime.timedelta(days=14)).isoformat()
        assert post(admin,forecast,forecast,dict(version=0,source_hash=estimate_hash,target=target,review=review,basis='Staff estimate',reference='Fictional checked training estimate',reason='Reviewed current records',confirmed=1))[0]==200
        assert post(admin,report_page,'/reports/cohort-context/snapshots',dict(**filters,source_hash=source_hash,confirmed=1))[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM cohort_snapshots').fetchone()[0]==1
        text=req(admin,report_page)[1];new_source=hidden(text,'source_hash');assert new_source!=source_hash
        status,_,url,_=post(admin,report_page,'/reports/cohort-context/snapshots',dict(**filters,source_hash=new_source,confirmed=1));assert status==200;second_id=url.rsplit('/',1)[-1]
        assert req(admin,'/reports/cohort-context/compare?'+urlencode({'earlier':first_id,'later':second_id}))[0]==200
        old=json.loads(req(admin,first+'/aggregate.json')[1]);assert old['report']['counts']['current_forecasts']==0
        passed('A checked staff estimate changes current report coverage, rejects an old preview and supports saved-report comparison while preserving the earlier figures')
        desk='/staff-follow-up';text=req(admin,desk)[1]
        blocks=re.findall(r'<section class="panel">(.*?)</section>',text,re.S)
        taskblock=next(b for b in blocks if task['reference']in b and 'name="source_key"'in b)
        key=hidden(taskblock,'source_key');token=hidden(taskblock,'request_token')
        assert post(admin,desk,desk+'/apprentice/'+str(ids['a']),dict(source_key=key,owner_id=ids['tutor'],request_token=token,due=review,reference='Fictional checked follow-up note',confirmed=1))[0]==200
        with sqlite3.connect(db)as c:fup=c.execute('SELECT id,version,source_kind FROM operational_followups').fetchone();assert fup[2]=='task'
        assert post(admin,desk,desk+'/'+fup[0],dict(version=fup[1],status='Resolved',owner_id=ids['tutor'],due=review,reference='Contact made; original task still requires its normal decision',confirmed=1))[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT status FROM learning_tasks WHERE id=?',(task['id'],)).fetchone()[0]=='Draft';assert c.execute('SELECT status FROM operational_followups').fetchone()[0]=='Resolved'
        assert 'The original action is still outstanding'in req(admin,desk)[1]
        passed('A source-linked staff follow-up can be assigned and closed without marking the actual task complete or hiding the outstanding original action')
        help_page=req(admin,'/help')[1]
        assert 'No Yorkshire-labelled voice'not in help_page and 'Choose a voice, then select Read aloud'in help_page
        assert 'Chief Executive (CEO)'in req(ceo,ops)[1]
        passed('Help uses the simplified read-aloud wording, UK role names are displayed and the removed regional availability statement is absent')
        # Pack44 uses the same disposable fixture and authenticated HTTP session.
        from html import unescape
        before=script("echo json_encode(['concerns'=>App\\Models\\SupportConcern::count(),'plans'=>App\\Models\\SupportPlan::count(),'reviews'=>App\\Models\\ProgressReviewDraft::count(),'tasks'=>App\\Models\\LearningTask::count()]);")
        status,text,_,_=req(admin,'/quick-actions');assert status==200 and 'What would you like to do?'in text and 'Continue saved work'in text
        ref=script("echo json_encode(['ref'=>App\\Models\\Apprentice::find("+str(ids['a'])+")->reference]);")['ref']
        status,text,_,_=req(admin,'/quick-actions?'+urlencode({'task':'support','search':ref}));assert status==200 and ref in text and '#support-plan-list'in text
        support='/apprentices/'+str(ids['a'])+'/support';status,text,_,_=req(admin,support);assert status==200 and 'Start here'in text and 'Record the support need first'in text
        passed('Quick actions finds a permitted apprentice without a support plan and opens the guided support record without creating one')
        status,text,_,_=req(admin,'/support?'+urlencode({'search':ref,'state':'overdue','plan_state':'overdue'}));assert status==200 and ref in text and 'Start / manage support'in text
        passed('Support apprentice finder remains available when concern and plan follow-up filters have no matches')
        status,text,_,_=req(admin,'/apprentices/'+str(ids['a'])+'/work-area');assert status==200 and 'Other actions'not in text and 'Check an apprentice record'in text and 'Add or change inclusion markers'in text
        status,text,_,_=req(admin,'/apprentices/'+str(ids['a'])+'/record-checks');assert status==200 and 'Needs checking'in text and 'do not infer an exemption'in text and 'PRIVATE-CONCERN-TEXT'not in text
        passed('Apprentice action area and record checks link to authorised original sources without exposing private record bodies')
        saved=script("$p=App\\Models\\ProgressReviewDraft::factory()->create(['apprentice_id'=>"+str(ids['a'])+",'author_id'=>"+str(ids['admin'])+",'fields'=>['note'=>'PRIVATE-SAVED-BODY-44']]);echo json_encode(['id'=>$p->id]);")
        status,text,_,_=req(admin,'/saved-work?'+urlencode({'kind':'review','search':ref}));assert status==200 and '/reviews/'+str(saved['id'])in text and 'PRIVATE-SAVED-BODY-44'not in text
        status,text,_,_=req(admin,'/quick-actions?'+urlencode({'task':'reviews','search':ref}));assert status==200 and '/reviews/'+str(saved['id'])in text
        passed('Saved work and review quick action continue the actual existing draft rather than creating a second review')
        after=script("echo json_encode(['concerns'=>App\\Models\\SupportConcern::count(),'plans'=>App\\Models\\SupportPlan::count(),'reviews'=>App\\Models\\ProgressReviewDraft::count(),'tasks'=>App\\Models\\LearningTask::count()]);")
        assert after==dict(before,reviews=before['reviews']+1)
        passed('Opening all new navigation and checking pages preserves source counts; only the explicit fictional draft fixture added a record')
        browser_result={'performed':False,'actualTarget':False,'physicalPhone':False,'screenReader':False}
        if args.browser:
            from playwright.sync_api import sync_playwright
            import hashlib
            pub=subprocess.run(['openssl','x509','-in',str(cert),'-pubkey','-noout'],capture_output=True,check=True).stdout
            der=subprocess.run(['openssl','pkey','-pubin','-outform','DER'],input=pub,capture_output=True,check=True).stdout
            pin=base64.b64encode(hashlib.sha256(der).digest()).decode()
            jar=next(h.cookiejar for h in admin.handlers if isinstance(h,HTTPCookieProcessor))
            rendered=[];browser_errors=[]
            with sync_playwright()as pw:
                browser=pw.chromium.launch(executable_path=args.browser_executable,headless=True,args=['--no-sandbox','--ignore-certificate-errors-spki-list='+pin])
                context=browser.new_context(viewport={'width':1280,'height':900},locale='en-GB',timezone_id='Europe/London')
                context.add_cookies([{'name':c.name,'value':c.value,'url':origin,'secure':c.secure,'httpOnly':c.has_nonstandard_attr('HttpOnly'),'sameSite':'Lax'}for c in jar])
                page=context.new_page();page.on('pageerror',lambda e:browser_errors.append(str(e)))
                pages=[('/quick-actions','What would you like to do?'),('/quick-actions?'+urlencode({'task':'support','search':ref}),'What would you like to do?'),('/apprentices/'+str(ids['a'])+'/work-area',None),('/apprentices/'+str(ids['a'])+'/record-checks',None),('/saved-work','Continue saved work'),(support,'Apprentice support record')]
                for width in [1280,390,320]:
                    page.set_viewport_size({'width':width,'height':900})
                    for url,title in pages:
                        response=page.goto(origin+url);assert response.status==200,(url,response.status)
                        page.locator('main h1').wait_for();assert not title or page.locator('main h1').inner_text()==title
                        assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),('Horizontal overflow',width,url)
                        rendered.append({'path':url.split('?')[0],'width':width,'horizontalOverflow':False})
                page.set_viewport_size({'width':1280,'height':900});page.goto(origin+'/quick-actions')
                page.locator('#action-search').fill('support plan');page.get_by_role('button',name='Find actions',exact=True).click();page.get_by_role('link',name='Start or continue a support plan',exact=True).click()
                page.locator('#action-apprentice').fill(ref);page.get_by_role('button',name='Find apprentice',exact=True).click();page.get_by_role('link',name='Open task',exact=True).click();assert page.url.endswith('#support-plan-list')
                page.goto(origin+'/quick-actions');page.keyboard.press('Tab');assert page.evaluate("document.activeElement.classList.contains('skip-link')")
                page.keyboard.press('Enter');assert page.evaluate("document.activeElement.id==='main-content'")
                page.locator('#theme-toggle').click();page.set_viewport_size({'width':320,'height':900});assert page.evaluate('document.documentElement.scrollWidth <= innerWidth+1')
                # Inspection images stay temporary/scratch; they are not part of the deliverable.
                screenshot=Path(args.output).parent/'pack44_quick_actions_320.png';page.screenshot(path=str(screenshot),full_page=True)
                page.emulate_media(media='print');assert page.locator('main').is_visible()
                assert not browser_errors,browser_errors
                browser.close()
            browser_result={'performed':True,'engine':'Local headless Chromium','renderedPages':rendered,'actualTarget':False,'physicalPhone':False,'screenReader':False,'keyboard':'Skip link and form navigation exercised','print':'Main content visibility only; no PDF layout certification','TLS':'Local fixture certificate public-key pin; no global HTTPS-ignore setting','pageErrors':browser_errors}
            passed('Local Chromium renders six current pages at 1280, 390 and 320 pixels without horizontal overflow and completes the support search journey')
            passed('Local browser checks keyboard skip link, optional theme control and print-content visibility without script errors; physical-device and screen-reader acceptance remain separate')
        # Pack45: source-backed visual reporting and atomic future-work rescheduling.
        guest=client();status,login_page,_,_=req(guest,'/login');assert status==200 and 'gta-login-scene.webp'in login_page and 'name="email"'in login_page and 'name="password"'in login_page and 'Visual concept'not in login_page
        assert req(guest,'/accessibility')[0]==200
        with guest.open(Request(origin+'/assets/gta-login-scene.webp'),timeout=30) as response:assert response.status==200 and response.read().startswith(b'RIFF')
        passed('Real sign-in HTML uses separate optimised artwork and labelled controls, with a functional public accessibility destination')
        report_path='/visual-reports?'+urlencode({'apprentice':ids['a'],'from':'2026-11-01','to':script('echo json_encode(["date"=>today()->toDateString()]);')['date']})
        status,report_html,_,_=req(admin,report_path);assert status==200 and 'Full portal report'in report_html and 'Weekly apprentice-session attendance'in report_html and 'exact values'in report_html and 'PRIVATE-SAVED-BODY-44'not in report_html
        passed('Visual report uses the current authorised apprentice scope, exact chart tables and unknown attendance without copying private draft bodies')
        with admin.open(Request(origin+report_path.replace('/visual-reports?','/visual-reports.pdf?')),timeout=30) as response:pdfbytes=response.read();assert response.status==200 and pdfbytes.startswith(b'%PDF')
        (Path(args.output).parent/'https_visual_report_fixture.pdf').write_bytes(pdfbytes)
        passed('The same authorised report exports a real server-rendered PDF containing chart and table material')
        release=artisan('gta:release-check45');assert release.returncode==0;release_result=json.loads(release.stdout);assert release_result['actual_target_acceptance']is False and release_result['writes_performed']is False and release_result['checks']['visual_assets']is True
        passed('Read-only release check verifies source routes, asset receipts and PDF support without claiming target acceptance')
        moving=script("$u=App\\Models\\User::find("+str(ids['admin'])+");$d=['staff_id'=>"+str(ids['tutor'])+",'date'=>'"+ids['date']+"','weeks'=>1,'start'=>'16:30','end'=>'17:30','kind'=>'Marking','site'=>'Remote','room_id'=>null,'group_id'=>null,'headcount'=>null,'title'=>'Fictional checked marking move'];$p=App\\Support\\CentrePlanning::save($u,1,null,0,(string)Illuminate\\Support\\Str::uuid(),$d);App\\Support\\CentrePlanning::check($u,1,$p,1,true);App\\Support\\CentrePlanning::publish($u,1,$p->refresh(),2);echo json_encode(['id'=>$p->id]);")
        move_path='/centre-planning/work-moves/'+moving['id'];status,move_page,_,_=req(admin,move_path);assert status==200
        choice=re.search(r'<option value="(\d{4}-\d{2}-\d{2} \d{2}:\d{2})"',move_page);assert choice
        move_data={'_token':csrf(move_page),'version':hidden(move_page,'version'),'source_hash':hidden(move_page,'source_hash'),'request_token':hidden(move_page,'request_token'),'choice':choice[1],'reason':'Fictional reviewed continuity request45','warnings_accepted':1,'confirmed':1}
        with sqlite3.connect(db)as c:preserved=(c.execute('SELECT COUNT(*) FROM training_sessions').fetchone()[0],c.execute('SELECT status FROM learning_tasks WHERE id=?',(task['id'],)).fetchone()[0])
        assert req(admin,move_path,move_data)[0]==200
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT state FROM timetable_plans WHERE id=?',(moving['id'],)).fetchone()[0]=='Withdrawn'
            replacement=c.execute('SELECT id,state FROM timetable_plans WHERE request_token=?',(move_data['request_token'],)).fetchone();assert replacement and replacement[1]=='Published'
            assert preserved==(c.execute('SELECT COUNT(*) FROM training_sessions').fetchone()[0],c.execute('SELECT status FROM learning_tasks WHERE id=?',(task['id'],)).fetchone()[0])
        passed('Confirmed work move withdraws only the original future reservation and publishes an equal-duration checked replacement without changing task or register completion')
        assert req(admin,move_path,move_data)[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM timetable_plans WHERE request_token=?',(move_data['request_token'],)).fetchone()[0]==1
        passed('Exact HTTP replay returns the same work replacement without duplicating the activity')
        # Pack46: retained-key identity matching, independent recovery and own calendar/status.
        recovery=script("$u=App\\Models\\User::find("+str(ids['admin'])+");$t=App\\Models\\User::factory()->create(['role'=>'tutor','active'=>false,'name'=>'Fictional recovery target46']);$s=new App\\Models\\AccountLifecycleState(['user_id'=>$t->id,'version'=>1,'status'=>'Restricted','review_on'=>today()->addDays(30)->toDateString()]);$s->proof=['fields'=>App\\Support\\AccountLifecycle::stateFields($s),'history'=>[]];$s->save();$sealed=App\\Support\\AccountLifecycle::ledger($u);$s->delete();$t->active=true;$t->save();echo json_encode(['id'=>$t->id,'sealed'=>$sealed,'review_on'=>today()->addDays(14)->toDateString()]);")
        path='/restriction-recovery';page=req(admin,path)[1];boundary='gta46-'+secrets.token_hex(12)
        body=('--'+boundary+'\r\nContent-Disposition: form-data; name="_token"\r\n\r\n'+csrf(page)+'\r\n--'+boundary+'\r\nContent-Disposition: form-data; name="ledger"; filename="restrictions.gta"\r\nContent-Type: application/octet-stream\r\n\r\n'+recovery['sealed']+'\r\n--'+boundary+'--\r\n').encode()
        with admin.open(Request(origin+path+'/preview',data=body,headers={'Content-Type':'multipart/form-data; boundary='+boundary}),timeout=30)as response:assert response.status==200;preview=response.read().decode()
        assert 'Missing restriction'in preview and 'Fictional recovery target46'in preview
        passed('Authenticated multipart restriction-file preview matches retained identity and current missing access without disabling the account')
        values={'_token':csrf(preview),'sealed':hidden(preview,'sealed'),'source_hash':hidden(preview,'source_hash'),'request_token':hidden(preview,'request_token'),'selected[]':recovery['id'],'review_on':recovery['review_on'],'retention':'Fictional reviewed retention46','handover':'Fictional checked continuity46','reason':'Fictional restore reconciliation46','confirmed':1}
        status,text,url,_=req(admin,path,values);assert status==200;run=url[len(origin):]
        assert post(admin,run,run+'/review',{'version':1,'accept':1,'reference':'Self review must fail','confirmed':1})[0]==403
        with sqlite3.connect(db)as c:assert c.execute('SELECT active FROM users WHERE id=?',(recovery['id'],)).fetchone()[0]==1
        assert post(ceo,run,run+'/review',{'version':1,'accept':1,'reference':'Fictional independent recovery check46','confirmed':1})[0]==200
        assert post(admin,run,run+'/apply',{'version':2,'confirmed':1})[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT active,auth_version FROM users WHERE id=?',(recovery['id'],)).fetchone()==(0,2)
        passed('Recovery preparation does not block access; self-review is refused and independently approved application reuses normal credential/session restriction checks')
        current=req(admin,run)[1];assert req(admin,run+'/apply',{'_token':csrf(current),'version':2,'confirmed':1})[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT auth_version FROM users WHERE id=?',(recovery['id'],)).fetchone()[0]==2
        passed('Exact applied-request replay leaves the same restriction and does not invalidate credentials again')
        personal=script("$u=App\\Models\\User::factory()->create(['role'=>'apprentice','name'=>'Fictional calendar apprentice46','email'=>'calendar46@example.test','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);$a=App\\Models\\Apprentice::factory()->create(['user_id'=>$u->id,'email'=>$u->email,'tutor_id'=>"+str(ids['tutor'])+",'start_date'=>today()->subDays(10)->toDateString()]);$a->alert_mobile='+447700900046';$a->alert_channel='both';$a->alerts_enabled=true;$a->save();App\\Models\\TrainingSession::factory()->create(['created_by'=>"+str(ids['admin'])+",'members'=>[$a->id],'name'=>'Fictional own training day46','session_date'=>today()->addDays(3)->toDateString(),'start_time'=>'09:00','end_time'=>'10:00']);App\\Models\\LearningTask::factory()->create(['apprentice_id'=>$a->id,'due_date'=>today()->addDays(4)->toDateString(),'title'=>'PRIVATE-CALENDAR-TITLE46','template'=>['kind'=>'task']]);App\\Models\\SupportConcern::factory()->create(['apprentice_id'=>$a->id,'initial_note'=>'PRIVATE-WORKSHEET-SUPPORT46']);echo json_encode(['id'=>$a->id,'ref'=>$a->reference]);")
        personal_client=login('calendar46@example.test');privacy_page=req(personal_client,'/privacy')[1]
        assert post(personal_client,'/privacy','/privacy/acknowledge',{'notice_id':hidden(privacy_page,'notice_id'),'body_hash':hidden(privacy_page,'body_hash'),'acknowledged':1})[0]==200
        status,calendar_html,_,_=req(personal_client,'/my-calendar');assert status==200 and 'Fictional own training day46'in calendar_html and 'PRIVATE-WORKSHEET-SUPPORT46'not in calendar_html
        status,ics,_,_=req(personal_client,'/my-calendar.ics');assert status==200 and 'BEGIN:VCALENDAR'in ics and 'SUMMARY:Task deadline'in ics and 'PRIVATE-CALENDAR-TITLE46'not in ics and 'VALARM'not in ics
        passed('Own apprentice calendar and ICS use saved roster/current deadlines with generic export titles, no private support or extra reminders')
        worksheet='/apprentices/'+str(personal['id'])+'/review-worksheet';status,worksheet_html,_,_=req(admin,worksheet);assert status==200 and 'Factual preparation only'in worksheet_html and 'PRIVATE-WORKSHEET-SUPPORT46'not in worksheet_html
        with admin.open(Request(origin+worksheet+'.pdf'),timeout=30)as response:pdf=response.read();assert response.status==200 and pdf.startswith(b'%PDF')
        (Path(args.output).parent/'https_review_worksheet_fixture.pdf').write_bytes(pdf)
        passed('Authorised current-source worksheet and real PDF contain factual preparation only and do not expose private support case notes')
        status,comms,_,_=req(admin,'/communication-checks?'+urlencode({'search':personal['ref']}));assert status==200 and 'Configuration checks'in comms and '+447700900046'not in comms and 'calendar46@example.test'not in comms
        status,own_status,_,_=req(personal_client,'/my-reminder-status');assert status==200 and 'My reminder status'in own_status and '+447700900046'not in own_status
        release=artisan('gta:release-check46');assert release.returncode==0 and json.loads(release.stdout)['actual_target_acceptance']is False
        passed('Manager and own communication status hide contact values, remain read-only and keep release checks distinct from target acceptance')

        # Pack47: isolated distant future repeated plans; no real data or provider operations.
        optimise=script("$u=App\\Models\\User::find("+str(ids['admin'])+");$t=App\\Models\\User::factory()->create(['role'=>'tutor','name'=>'Fictional optimiser tutor47']);$g=App\\Models\\TutorGroup::factory()->create(['tutor_id'=>$t->id,'active'=>true,'name'=>'Fictional optimiser group47']);$room=App\\Models\\TimetableRoom::create(['site'=>'Doncaster','name'=>'Fictional optimiser room47','created_by'=>$u->id,'capacity'=>null,'uses'=>[],'active'=>false,'version'=>0]);App\\Support\\CentrePlanning::room($u,1,$room,0,['capacity'=>20,'uses'=>['Teaching','Admin'],'active'=>true]);$date=today()->addDays(70)->toDateString();$dates=App\\Support\\CentrePlanning::dates($date,2);App\\Support\\CentrePlanning::windows($u,1,$t,$dates,App\\Support\\ReviewedImports::hash(App\\Support\\CentrePlanning::windowSource($t,$dates)),true,'09:00','17:00');$plans=[];foreach([['09:00','10:00','Marking'],['13:00','14:00','Teaching'],['15:00','16:00','Preparation']]as$i=>$slot){$teach=$slot[2]==='Teaching';$d=['staff_id'=>$t->id,'date'=>$date,'weeks'=>2,'start'=>$slot[0],'end'=>$slot[1],'kind'=>$slot[2],'site'=>$teach?'Doncaster':'Remote','room_id'=>$teach?$room->id:null,'group_id'=>$teach?$g->id:null,'headcount'=>$teach?20:null,'title'=>'Fictional optimiser activity47 '.$i];$p=App\\Support\\CentrePlanning::save($u,1,null,0,(string)Illuminate\\Support\\Str::uuid(),$d);App\\Support\\CentrePlanning::check($u,1,$p,1,true);App\\Support\\CentrePlanning::publish($u,1,$p->refresh(),2);if($i){$plans[]=$p->id;}}echo json_encode(['plans'=>$plans,'from'=>$date,'to'=>end($dates)]);")
        opt='/centre-planning/optimiser';listing=opt+'?'+urlencode({'from':optimise['from'],'to':optimise['to']})
        assert req(personal_client,opt)[0]==403 and req(admin,opt+'/preview',{'from':optimise['from']})[0]==419
        passed('Optimiser preserves role and missing-CSRF boundaries over actual HTTPS')
        page=req(admin,listing)[1]
        with sqlite3.connect(db)as c:before_opt=(c.execute('SELECT COUNT(*) FROM timetable_plans').fetchone()[0],c.execute('SELECT COUNT(*) FROM timetable_planning_events').fetchone()[0],c.execute('SELECT COUNT(*) FROM training_sessions').fetchone()[0])
        values=[('_token',csrf(page)),('from',optimise['from']),('to',optimise['to']),*[('plans[]',x)for x in optimise['plans']]]
        status,preview,_,headers=req(admin,opt+'/preview',values);assert status==200 and '480 minutes before'in preview and '0 minutes proposed'in preview and 'Fictional optimiser group47'in preview
        with sqlite3.connect(db)as c:assert before_opt==(c.execute('SELECT COUNT(*) FROM timetable_plans').fetchone()[0],c.execute('SELECT COUNT(*) FROM timetable_planning_events').fetchone()[0],c.execute('SELECT COUNT(*) FROM training_sessions').fetchone()[0])
        passed('Read-only multi-plan preview checks repeated Teaching and shows an exact480-to-zero minute gap proposal with real staff/group/date context')
        saved_values=[('_token',csrf(preview)),('from',optimise['from']),('to',optimise['to']),*[('plans[]',x)for x in optimise['plans']],('source_hash',hidden(preview,'source_hash')),('request_token',hidden(preview,'request_token')),('reason','Fictional agreed people and times47'),('confirmed',1)]
        status,prepared,url,_=req(admin,opt,saved_values);assert status==200 and 'Apply reviewed timetable changes'in prepared;run47=url[len(origin):]
        with sqlite3.connect(db)as c:
            assert all(c.execute('SELECT state FROM timetable_plans WHERE id=?',(x,)).fetchone()[0]=='Published'for x in optimise['plans'])
            assert c.execute('SELECT state FROM timetable_optimisation_runs WHERE id=?',(run47.rsplit('/',1)[-1],)).fetchone()[0]=='Prepared'
        passed('Saving an encrypted reviewed proposal creates no reservation changes and retains the explicit final arrangements confirmation')
        assert post(admin,run47,run47+'/cancel',{'version':1,'details_hash':hidden(prepared,'details_hash'),'confirmed':1})[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM timetable_optimisation_runs WHERE id=?',(run47.rsplit('/',1)[-1],)).fetchone()[0]=='Cancelled'
        passed('Actual HTTP cancellation closes the proposal while every original remains Published')
        saved_values=[(k,str(uuid.uuid4())if k=='request_token'else v)for k,v in saved_values]
        status,prepared,url,_=req(admin,opt,saved_values);assert status==200;run47=url[len(origin):];proof47=hidden(prepared,'details_hash')
        payload47={'version':1,'details_hash':proof47,'confirmed':1,'arrangements_checked':1,'warnings_accepted':1}
        assert post(admin,run47,run47+'/apply',payload47)[0]==200
        verified=script("$r=App\\Models\\TimetableOptimisationRun::find('"+run47.rsplit('/',1)[-1]+"');$ok=App\\Support\\TimetableOptimiser::intact($r);$counts=[];foreach($r->receipt['replacements']as$old=>$new){$a=App\\Models\\TimetablePlan::find($old);$b=App\\Models\\TimetablePlan::find($new);$ok=$ok&&$a->state==='Withdrawn'&&App\\Support\\CentrePlanning::published($b)&&App\\Support\\CentrePlanning::inspect($b)['errors']===[];$x=$a->source['data']['args'];$y=$b->source['data']['args'];foreach(['staff_id','group_id','room_id','kind','date','weeks','site','headcount']as$key){$ok=$ok&&$x[$key]===$y[$key];}$counts[]=count($b->source['data']['rows']);}echo json_encode(['ok'=>$ok,'state'=>$r->state,'occurrences'=>$counts]);")
        assert verified['ok'] and verified['state']=='Applied' and verified['occurrences']==[2,2]
        with sqlite3.connect(db)as c:
            after_opt=(c.execute('SELECT COUNT(*) FROM timetable_plans').fetchone()[0],c.execute('SELECT COUNT(*) FROM timetable_planning_events').fetchone()[0],c.execute('SELECT COUNT(*) FROM training_sessions').fetchone()[0]);assert after_opt[0]==before_opt[0]+2 and after_opt[2]==before_opt[2]
        passed('Reviewed HTTP application withdraws both originals and publishes two checked repeated replacements together without changing registers or occurrence identity')
        assert post(admin,run47,run47+'/apply',payload47)[0]==200
        with sqlite3.connect(db)as c:assert after_opt==(c.execute('SELECT COUNT(*) FROM timetable_plans').fetchone()[0],c.execute('SELECT COUNT(*) FROM timetable_planning_events').fetchone()[0],c.execute('SELECT COUNT(*) FROM training_sessions').fetchone()[0])
        passed('Exact applied HTTP replay returns the recorded outcome without another withdrawal, replacement or audit')
        command=artisan('gta:release-check47');assert command.returncode==0
        release=json.loads(command.stdout);assert release['pack']=='47' and not release['actual_target_acceptance'] and not release['writes_performed']
        passed('Pack47 read-only release command recognises optimiser routes without claiming actual deployment acceptance')


        # Pack48: separate reviewed historical cohorts; no current assignment is inferred.
        hist=script(r"""$u=App\Models\User::find(ADMIN_ID48);$p=App\Models\Programme::create(['standard_code'=>'HTTPS48','version'=>'1.0','name'=>'Fictional historical programme48','level'=>3]);$old=App\Models\User::factory()->create(['role'=>'tutor','active'=>false,'name'=>'Fictional former tutor48']);$current=App\Models\User::factory()->create(['role'=>'tutor','name'=>'PRIVATE-CURRENT-TUTOR48']);$out=['programme'=>$p->id,'historical'=>$old->id,'current'=>$current->id,'first'=>null];foreach(['2023','2024']as$year){for($i=0;$i<5;$i++){$a=App\Models\Apprentice::factory()->create(['programme_id'=>$p->id,'tutor_id'=>$current->id,'start_date'=>((int)$year-2).'-09-01','planned_end_date'=>$year.'-06-30','first_name'=>'PRIVATE-APPRENTICE-HTTPS48']);$context=['status'=>'Completed','endDate'=>$year.'-06-20','achievement'=>'Achieved','achievementDate'=>$year.'-07-01','sourceRef'=>'PRIVATE-OUTCOME-HTTPS48','reason'=>'PRIVATE-CONTEXT-HTTPS48'];if($year==='2024'&&$i===0){$context=['status'=>'Withdrawn','endDate'=>$year.'-06-20','sourceRef'=>'PRIVATE-OUTCOME-HTTPS48'];}App\Models\ProgrammeOutcome::factory()->create(['apprentice_id'=>$a->id,'context'=>$context]);if($out['first']===null){$out['first']=$a->id;}}}echo json_encode($out);""".replace('ADMIN_ID48',str(ids['admin'])))
        hroot='/reports/historical-cohorts'
        assert req(personal_client,hroot)[0]==403 and req(admin,hroot+'/preview',{'programme':hist['programme']})[0]==419
        passed('Historical cohort routes preserve actual HTTPS role and missing-CSRF boundaries')
        def historical_json_post(c,path,values):
            page=req(c,path.rsplit('/',1)[0])[1]
            body=urlencode({'_token':csrf(page),**values}).encode()
            try:
                with c.open(Request(origin+path,data=body,headers={'Accept':'application/json'}),timeout=30)as response:return response.status,response.read().decode()
            except HTTPError as error:return error.code,error.read().decode()
        def historical_capture(year,verbose=False):
            page=req(admin,hroot)[1]
            with sqlite3.connect(db)as c:before_h=(c.execute('SELECT COUNT(*) FROM historical_cohorts').fetchone()[0],c.execute('SELECT COUNT(*) FROM historical_cohort_rows').fetchone()[0],c.execute('SELECT COUNT(*) FROM portal_operations_events').fetchone()[0])
            filters={'programme':hist['programme'],'from':year+'-01-01','to':year+'-12-31','grace':90}
            status,preview,_,_=req(admin,hroot+'/preview',{'_token':csrf(page),**filters});assert status==200 and '5 apprentice(s)'in preview and 'PRIVATE-CURRENT-TUTOR48'not in preview
            with sqlite3.connect(db)as c:assert before_h==(c.execute('SELECT COUNT(*) FROM historical_cohorts').fetchone()[0],c.execute('SELECT COUNT(*) FROM historical_cohort_rows').fetchone()[0],c.execute('SELECT COUNT(*) FROM portal_operations_events').fetchone()[0])
            if verbose:passed('Real historical preview is read-only, captures the exact planned-end cohort and never infers the current tutor')
            status,text,url,_=req(admin,hroot,{'_token':csrf(preview),**filters,'request_token':hidden(preview,'request_token'),'source_hash':hidden(preview,'source_hash'),'reference':'PRIVATE-COHORT-EVIDENCE-HTTPS48','confirmed':1});assert status==200 and 'Not yet checked'in text
            cohort_path=url[len(origin):];cohort_id=cohort_path.rsplit('/',1)[-1]
            with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM historical_cohort_rows WHERE cohort_id=? AND historical_tutor_id IS NULL',(cohort_id,)).fetchone()[0]==5
            if verbose:passed('Actual capture freezes five rows with unknown unreviewed attribution and leaves live tutor assignments unchanged')
            selected=re.findall(r'name="rows\[([a-f0-9-]+)\]"\s+value="(\d+)"',text);assert len(selected)==5
            values=[('_token',csrf(text)),('version',hidden(text,'version')),('basis','Documented tutor at recorded planned end'),('historical_tutor_id',hist['historical']),('reference','PRIVATE-ATTRIBUTION-EVIDENCE-HTTPS48'),('confirmed',1),*[(f'rows[{id}]',version)for id,version in selected]]
            status,text,_,_=req(admin,cohort_path+'/batch',values);assert status==200 and '5 of 5'in text
            version=int(hidden(text,'version'));assert historical_json_post(admin,cohort_path+'/decision',{'version':version,'action':'approve','reference':'Fictional refused self approval48','confirmed':1})[0]==422
            assert post(ceo,cohort_path,cohort_path+'/decision',{'version':version,'action':'approve','reference':'PRIVATE-INDEPENDENT-REVIEW-HTTPS48','confirmed':1})[0]==200
            if verbose:passed('Checked batch attribution to an inactive former tutor requires independent review and creates no live staff reassignment')
            return cohort_path,cohort_id
        h1,h1id=historical_capture('2023',True)
        status,export,_,_=req(admin,h1+'/aggregate.json');assert status==200;aggregate=json.loads(export)
        assert aggregate['counts']['mature']==5 and aggregate['counts']['achieved']==5 and aggregate['achievement_percent']==100 and not aggregate['official_qar']
        for secret in ['PRIVATE-APPRENTICE-HTTPS48','PRIVATE-CURRENT-TUTOR48','PRIVATE-OUTCOME-HTTPS48','PRIVATE-COHORT-EVIDENCE','PRIVATE-ATTRIBUTION-EVIDENCE','PRIVATE-INDEPENDENT-REVIEW','PRIVATE-CONTEXT-HTTPS48']:assert secret not in export
        with admin.open(Request(origin+h1+'/report.pdf'),timeout=30)as response:pdf48=response.read();assert response.status==200 and pdf48.startswith(b'%PDF')
        (Path(args.output).parent/'https_historical_cohort_fixture.pdf').write_bytes(pdf48)
        passed('Approved aggregate JSON and real PDF contain exact mature-cohort counts without apprentice identities or private evidence bodies')
        h2,h2id=historical_capture('2024')
        status,comparison,_,_=req(admin,hroot+'/compare?'+urlencode({'earlier':h1id,'later':h2id}));assert status==200 and '-20.0'in comparison and 'PRIVATE-APPRENTICE-HTTPS48'not in comparison
        passed('Two separately approved compatible mature cohorts compare five-of-five versus four-of-five as minus20 percentage points without a tutor ranking')
        script("$a=App\\Models\\Apprentice::find("+str(hist['first'])+");$a->tutor_id="+str(ids['tutor'])+";$a->save();App\\Models\\ProgrammeOutcome::where('apprentice_id',$a->id)->first()->update(['context'=>['status'=>'Active','sourceRef'=>'Fictional subsequent live correction48']]);echo '{}';")
        assert json.loads(req(admin,h1+'/aggregate.json')[1])==aggregate
        passed('Later live outcome and current tutor changes cannot rewrite a historical approved capture')
        page=req(ceo,h1)[1];assert post(ceo,h1,h1+'/decision',{'version':hidden(page,'version'),'action':'withdraw','reference':'Fictional later withdrawal of historical report48','confirmed':1})[0]==200
        try:
            with admin.open(Request(origin+h1+'/aggregate.json',headers={'Accept':'application/json'}),timeout=30)as response:withdraw_status=response.status
        except HTTPError as error:withdraw_status=error.code
        assert withdraw_status==422
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM historical_cohort_rows WHERE cohort_id=?',(h1id,)).fetchone()[0]==5 and c.execute('SELECT state FROM historical_cohorts WHERE id=?',(h1id,)).fetchone()[0]=='Withdrawn'
        release48=artisan('gta:release-check48');assert release48.returncode==0 and not json.loads(release48.stdout)['actual_target_acceptance']
        passed('Withdrawal retains cohort evidence while blocking fresh exports; release-check48 remains read-only and does not approve deployment')


        # Pack49: no automatic authority from a manager/timetable label.
        cm=script(r"""$u=App\Models\User::find(ADMIN_ID49);$m=App\Models\User::factory()->create(['role'=>'manager','name'=>'Fictional scoped manager49','email'=>'centre49@example.test','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);$t=App\Models\User::factory()->create(['role'=>'tutor','name'=>'Fictional follow-up owner49','email'=>'centreowner49@example.test','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);$g=App\Models\TutorGroup::factory()->create(['tutor_id'=>$t->id,'active'=>true,'name'=>'Fictional permitted group49']);$other=App\Models\TutorGroup::factory()->create(['tutor_id'=>$t->id,'active'=>true,'name'=>'Fictional outside group49']);$a=App\Models\Apprentice::factory()->create(['tutor_id'=>$t->id,'tutor_group_id'=>$g->id,'first_name'=>'Fictional Centre apprentice49']);$b=App\Models\Apprentice::factory()->create(['tutor_id'=>$t->id,'tutor_group_id'=>$other->id,'first_name'=>'PRIVATE-OUTSIDE-SCOPE49']);App\Models\ProgrammeOutcome::factory()->create(['apprentice_id'=>$a->id,'context'=>['status'=>'Active','sourceRef'=>'PRIVATE-OUTCOME-SOURCE49','reason'=>'PRIVATE-SUPPORT-BODY49','ehcp'=>'Yes']]);$task=App\Models\LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>'CENTRE-TASK49','template'=>['kind'=>'task'],'due_date'=>today()->subDay(),'answers'=>['answer'=>'PRIVATE-TASK-ANSWER49']]);echo json_encode(['manager'=>$m->id,'owner'=>$t->id,'group'=>$g->id,'a'=>$a->id,'outside'=>$b->id,'task'=>$task->id,'expires'=>today()->addDays(90)->toDateString(),'from'=>today()->subDays(70)->toDateString(),'to'=>today()->toDateString()]);""".replace('ADMIN_ID49',str(ids['admin'])))
        cmclient=login('centre49@example.test');ownerclient=login('centreowner49@example.test')
        for c in [cmclient,ownerclient]:
            pp=req(c,'/privacy')[1]
            assert post(c,'/privacy','/privacy/acknowledge',{'notice_id':hidden(pp,'notice_id'),'body_hash':hidden(pp,'body_hash'),'acknowledged':1})[0]==200
        assert req(cmclient,'/centre-oversight')[0]==403 and req(cmclient,'/centre-access')[0]==403 and req(admin,'/centre-access/preview',{'target':cm['manager']})[0]==419
        passed('Centre Manager routes retain current-role and missing-CSRF boundaries before any scoped grant exists')
        access='/centre-access';page=req(admin,access)[1]
        values=[('_token',csrf(page)),('target',cm['manager']),('groups[]',cm['group']),('areas[]','training'),('areas[]','coordination'),('expires',cm['expires'])]
        with sqlite3.connect(db)as c:before_c=(c.execute('SELECT COUNT(*) FROM centre_manager_access_requests').fetchone()[0],c.execute('SELECT COUNT(*) FROM centre_manager_grants').fetchone()[0])
        status,preview,_,_=req(admin,access+'/preview',values);assert status==200 and 'Fictional scoped manager49'in preview and 'Fictional permitted group49'in preview
        with sqlite3.connect(db)as c:assert before_c==(c.execute('SELECT COUNT(*) FROM centre_manager_access_requests').fetchone()[0],c.execute('SELECT COUNT(*) FROM centre_manager_grants').fetchone()[0])
        values=[('_token',csrf(preview)),*[(k,v)for k,v in values if k!='_token'],('ceo_locked',0),('source_hash',hidden(preview,'source_hash')),('request_token',hidden(preview,'request_token')),('reference','PRIVATE-CHECKED-ACCESS-REFERENCE49'),('confirmed',1)]
        status,prepared,url,_=req(admin,access+'/requests',values);assert status==200;access_run=url[len(origin):]
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM centre_manager_grants').fetchone()[0]==before_c[1]
        passed('Actual access preview is read-only and preparation records an encrypted scoped proposal without granting rights')
        def centre_json_post(client,source,path,values):
            page=req(client,source)[1]
            try:
                with client.open(Request(origin+path,data=urlencode({'_token':csrf(page),**values}).encode(),headers={'Accept':'application/json'}),timeout=30)as response:return response.status,response.read().decode()
            except HTTPError as error:return error.code,error.read().decode()
        assert centre_json_post(admin,access_run,access_run+'/review',{'version':1,'accept':1,'reference':'Fictional refused self review49','confirmed':1})[0]==422
        assert post(ceo,access_run,access_run+'/review',{'version':1,'accept':1,'reference':'Fictional independent access check49','confirmed':1})[0]==200
        assert req(cmclient,'/centre-oversight')[0]==403
        passed('Independent approval is required and approval alone still grants no Centre Manager access')
        assert post(admin,access_run,access_run+'/apply',{'version':2,'confirmed':1})[0]==200
        overview=req(cmclient,'/centre-oversight?area=training')[1];assert 'Fictional Centre apprentice49'in overview and 'PRIVATE-OUTSIDE-SCOPE49'not in overview
        assert req(cmclient,'/centre-oversight/apprentices/'+str(cm['outside'])+'/training')[0]==403
        assert req(cmclient,'/reports/historical-cohorts')[0]==403 and req(cmclient,'/account-operations')[0]==403
        with sqlite3.connect(db)as c:assert c.execute('SELECT role FROM users WHERE id=?',(cm['manager'],)).fetchone()[0]=='manager'
        passed('Activation exposes only selected current group members and areas while canonical role, private reports and account administration stay unchanged')
        record='/centre-oversight/apprentices/'+str(cm['a'])+'/training';status,source,_,_=req(cmclient,record);assert status==200 and 'Known-source work is past its recorded deadline'in source
        assert all(secret not in source for secret in ['PRIVATE-TASK-ANSWER49','PRIVATE-SUPPORT-BODY49','PRIVATE-OUTCOME-SOURCE49'])
        assert req(cmclient,'/centre-oversight/apprentices/'+str(cm['a'])+'/functional_skills')[0]==403
        passed('Operational projection withholds task answers, private support/outcome references and ungranted maths/English detail')
        values={'flag':'task_deadline','from':hidden(source,'from'),'to':hidden(source,'to'),'source_hash':hidden(source,'source_hash'),'owner_choice':str(cm['owner'])+':tutor','request_token':hidden(source,'request_token'),'due':cm['to'],'reference':'PRIVATE-CENTRE-FOLLOWUP-REF49','confirmed':1}
        status,text,url,_=req(cmclient,record+'/follow-up',{'_token':csrf(source),**values});assert status==200;work49=url[len(origin):]
        assert post(ownerclient,work49,work49,{'version':1,'status':'In progress','source_hash':hidden(req(ownerclient,work49)[1],'source_hash'),'reference':'Fictional source work acknowledged49','confirmed':1})[0]==200
        assert centre_json_post(cmclient,work49,work49,{'version':2,'status':'Resolved','source_hash':values['source_hash'],'reference':'Fictional manager cannot resolve owner work49','confirmed':1})[0]==403
        with sqlite3.connect(db)as c:assert c.execute('SELECT status FROM learning_tasks WHERE id=?',(cm['task'],)).fetchone()[0]=='Draft'
        passed('Source-linked follow-up reaches the current Tutor, is explicitly acknowledged, and cannot be resolved by a non-owner manager or mark the task complete')
        script("$a=App\\Models\\Apprentice::find("+str(cm['a'])+");$a->tutor_id="+str(ids['tutor'])+";$a->save();echo '{}';")
        assert req(ownerclient,work49)[0]==404
        assert post(cmclient,work49,work49,{'version':2,'status':'Cancelled','source_hash':values['source_hash'],'reference':'Fictional old assignment request cancelled49','confirmed':1})[0]==200
        passed('Current assignment transfer removes the previous owner from direct access while the still-scoped manager can cancel obsolete coordination work')
        assert post(admin,access,access+'/staff/'+str(cm['manager'])+'/revoke',{'version':1,'reference':'Fictional immediate scoped access revocation49','confirmed':1})[0]==200
        assert req(cmclient,'/centre-oversight')[0]==403 and req(cmclient,work49)[0]==404
        assert centre_json_post(admin,access_run,access_run+'/apply',{'version':2,'confirmed':1})[0]==422
        with sqlite3.connect(db)as c:assert c.execute('SELECT active FROM centre_manager_grants WHERE user_id=?',(cm['manager'],)).fetchone()[0]==0
        passed('Immediate revocation blocks current/direct access and an old activation replay cannot reactivate the retained grant')
        release49=artisan('gta:release-check49');assert release49.returncode==0 and not json.loads(release49.stdout)['actual_target_acceptance']
        passed('Pack49 source/configuration/schema-name release command remains read-only and distinct from actual target acceptance')

        status,login50,_,_=req(client(),'/login')
        assert status==200 and 'data-design-release="50"'in login50 and 'data-signin-theme'in login50 and 'class="eyebrow">GTA Portal'not in login50
        assert all(x in login50 for x in ['name="email"','name="password"','name="_token"','Forgotten your password?','Privacy notice'])
        passed('Pack50 public sign-in retains actual CSRF/credential/recovery/privacy fields and exposes its own theme control without the duplicate scene label')
        status,theme50,_,_=req(admin,'/assets/signin-theme50.js');assert status==200 and 'gta-local-theme'in theme50 and 'password'not in theme50
        status,css50,_,_=req(admin,'/assets/portal.css');assert status==200 and '--bg:#101112'in css50 and '--bg:#f4f4f3'in css50 and '.visual-chart svg text{fill:var(--text)}'in css50
        passed('Versioned real theme assets are served over HTTPS with neutral dark/light tokens and inherited chart text colours')
        status,logo50,_,_=req(admin,'/assets/gta-mark.svg');assert status==200 and 'fill="#ffffff"'in logo50 and '<g fill="#111111">'in logo50
        passed('The served vector GTA mark uses white infill and dark lettering in both themes')
        status,dashboard50,_,_=req(admin,'/dashboard');assert status==200 and 'data-task-summary'in dashboard50 and 'Tasks needing attention'in dashboard50 and 'data-visual-choice="both"'in dashboard50
        assert 'PRIVATE-TASK-ANSWER49'not in dashboard50 and 'PRIVATE-SUPPORT-BODY49'not in dashboard50
        passed('Real dashboard delivers current-scope cards, attention links and chart/table controls without private answer/support bodies')
        status,report50,_,_=req(admin,'/visual-reports?apprentice='+str(cm['a']));assert status==200 and 'data-task-summary'in report50 and 'exact values'in report50 and 'data-visual-table'in report50
        passed('Selected-apprentice visual report retains exact tables beside matching-source summary cards')
        result50=artisan('gta:release-check50');assert result50.returncode==0;receipt50=json.loads(result50.stdout)
        assert receipt50['pack']=='50'and receipt50['actual_target_acceptance']is False and receipt50['new_migrations']==0 and len(receipt50['theme_contrast'])==20
        assert all(value>=4.5 for value in receipt50['theme_contrast'].values())
        passed('Read-only cumulative Pack50 command verifies solid-colour contrast pairs while refusing to claim browser or target acceptance')

        near=script(r"""$admin=App\Models\User::find(ADMIN51);$staff=App\Models\User::factory()->create(['role'=>'tutor','name'=>'Fictional nearby-date tutor51']);$other=App\Models\User::factory()->create(['role'=>'tutor']);$from=today()->addDays(70)->toDateString();$to=today()->addDays(71)->toDateString();$dates=[$from,$to];foreach([$staff,$other]as$u){App\Support\CentrePlanning::windows($admin,1,$u,$dates,App\Support\ReviewedImports::hash(App\Support\CentrePlanning::windowSource($u,$dates)),true,'09:00','12:00');}$room=App\Models\TimetableRoom::create(['site'=>'Doncaster','name'=>'Fictional nearby-date room51','capacity'=>20,'uses'=>[],'active'=>false,'version'=>1,'history'=>[]]);App\Support\CentrePlanning::room($admin,1,$room,1,['capacity'=>20,'uses'=>['Admin'],'active'=>true]);$base=['staff_id'=>$staff->id,'date'=>$from,'weeks'=>1,'start'=>'09:00','end'=>'10:00','kind'=>'Admin','site'=>'Remote','room_id'=>null,'group_id'=>null,'headcount'=>null,'title'=>'Fictional original anchor51'];$publish=function($args)use($admin){$p=App\Support\CentrePlanning::save($admin,1,null,0,(string)Illuminate\Support\Str::uuid(),$args);App\Support\CentrePlanning::check($admin,1,$p,1,true);App\Support\CentrePlanning::publish($admin,1,$p->refresh(),2);return $p->refresh();};$publish($base);$move=$publish([...$base,'start'=>'11:00','end'=>'12:00','kind'=>'Preparation','site'=>'Doncaster','room_id'=>$room->id,'title'=>'Fictional nearby-date preparation51']);$publish([...$base,'staff_id'=>$other->id,'start'=>'10:00','end'=>'11:00','site'=>'Doncaster','room_id'=>$room->id,'title'=>'Fictional shared room reservation51']);echo json_encode(['plan'=>$move->id,'from'=>$from,'to'=>$to,'staff'=>$staff->id]);""".replace('ADMIN51',str(ids['admin'])))
        nearroot='/centre-planning/optimiser';nearquery=nearroot+'?'+urlencode({'from':near['from'],'to':near['to']})
        status,index51,_,_=req(admin,nearquery);assert status==200 and 'name="date_flex"'in index51 and 'Allow one day either side'in index51
        values51=[('_token',csrf(index51)),('from',near['from']),('to',near['to']),('plans[]',near['plan']),('date_flex',1)]
        with sqlite3.connect(db)as c:before51=[c.execute('SELECT COUNT(*) FROM '+t).fetchone()[0]for t in ['timetable_optimisation_runs','timetable_plans','training_sessions','learning_tasks']]
        status,preview51,_,_=req(admin,nearroot+'/preview',values51);assert status==200 and 'Nearby-date mode'in preview51 and 'Date and/or time change'in preview51
        with sqlite3.connect(db)as c:assert before51==[c.execute('SELECT COUNT(*) FROM '+t).fetchone()[0]for t in ['timetable_optimisation_runs','timetable_plans','training_sessions','learning_tasks']]
        passed('Nearby-date selection and actual source-bound HTTPS preview expose the original/proposed dates without changing reservations, registers or tasks')
        request51={'from':near['from'],'to':near['to'],'date_flex':1,'source_hash':hidden(preview51,'source_hash'),'request_token':hidden(preview51,'request_token'),'reason':'Fictional agreed date/time arrangements51','confirmed':1}
        status,saved51,url51,_=req(admin,nearroot,[('_token',csrf(preview51)),('plans[]',near['plan']),*request51.items()]);assert status==200 and 'Apply reviewed timetable changes'in saved51 and 'agreed the proposed dates and times'in saved51;run51=url51[len(origin):]
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM timetable_plans WHERE id=?',(near['plan'],)).fetchone()[0]=='Published'
        passed('Preparation preserves the chosen nearby-date radius and requires a separate explicit arrangements/final decision while the original remains Published')
        tutor51=login('tutor@example.test');privacy51=req(tutor51,'/privacy')[1]
        assert post(tutor51,'/privacy','/privacy/acknowledge',{'notice_id':hidden(privacy51,'notice_id'),'body_hash':hidden(privacy51,'body_hash'),'acknowledged':1})[0]==200
        for denied51 in [personal_client,tutor51]:
            assert req(denied51,run51)[0]==403
            assert post(denied51,'/privacy',run51+'/apply',{'version':1,'details_hash':hidden(saved51,'details_hash'),'arrangements_checked':1,'confirmed':1,'warnings_accepted':1})[0]==403
        passed('Ordinary Tutor and Apprentice direct URLs cannot read or apply a planning proposal')
        assert post(admin,run51,run51+'/apply',{'version':1,'details_hash':hidden(saved51,'details_hash'),'arrangements_checked':1,'confirmed':1,'warnings_accepted':1})[0]==200
        observed51=script("$r=App\\Models\\TimetableOptimisationRun::find('"+run51.rsplit('/',1)[-1]+"');$new=App\\Models\\TimetablePlan::find($r->receipt['replacements']['"+near['plan']+"']);echo json_encode(['state'=>$r->state,'date'=>$new->source['data']['args']['date'],'staff'=>$new->source['data']['args']['staff_id'],'start'=>$new->source['data']['args']['start'],'end'=>$new->source['data']['args']['end'],'old'=>App\\Models\\TimetablePlan::find('"+near['plan']+"')->state]);")
        assert observed51=={'state':'Applied','date':near['to'],'staff':near['staff'],'start':'11:00','end':'12:00','old':'Withdrawn'}
        with sqlite3.connect(db)as c:assert before51[2:]==[c.execute('SELECT COUNT(*) FROM '+t).fetchone()[0]for t in ['training_sessions','learning_tasks']];replay51=[c.execute('SELECT COUNT(*) FROM '+t).fetchone()[0]for t in ['timetable_plans','timetable_planning_events']]
        passed('Explicit HTTPS application moves the preparation to the next checked day, retains staff/duration/history and leaves actual registers/tasks untouched')
        assert post(admin,run51,run51+'/apply',{'version':1,'details_hash':hidden(saved51,'details_hash'),'arrangements_checked':1,'confirmed':1,'warnings_accepted':1})[0]==200
        with sqlite3.connect(db)as c:assert replay51==[c.execute('SELECT COUNT(*) FROM '+t).fetchone()[0]for t in ['timetable_plans','timetable_planning_events']]
        passed('Exact applied nearby-date replay returns its retained outcome without duplicate reservations or audit events')
        result51=artisan('gta:release-check51');assert result51.returncode==0;read51=json.loads(result51.stdout);assert read51['pack']=='51'and read51['actual_target_acceptance']is False and read51['new_migrations']==0
        style51=req(admin,'/assets/portal.css')[1];assert '--accent:#a9d3da'in style51 and '.signin-scene img{filter:none}'in style51
        passed('The current read-only release check and served calmer-cyan/navy styling remain distinct from real Cloud/browser acceptance')

        bench52=script(r"""$admin=App\Models\User::find(ADMIN52);$checker=App\Models\User::factory()->create(['role'=>'admin']);$historic=App\Models\User::factory()->create(['role'=>'tutor','active'=>false,'name'=>'Fictional historical Tutor52']);$current=App\Models\User::factory()->create(['role'=>'tutor','name'=>'PRIVATE-CURRENT52']);$programme=App\Models\Programme::create(['standard_code'=>'HTTPS52','version'=>'1.0','name'=>'Fictional duration comparison52','level'=>3]);$ids=[];foreach([2023,2024]as$year){foreach(($year===2023?[[18,5,5],[30,10,0]]:[[18,10,10],[30,5,0]])as[$months,$n,$achieved]){for($i=0;$i<$n;$i++){$a=App\Models\Apprentice::factory()->create(['programme_id'=>$programme->id,'tutor_id'=>$current->id,'first_name'=>'PRIVATE-APPRENTICE52','start_date'=>Carbon\Carbon::parse($year.'-06-30')->subMonthsNoOverflow($months)->toDateString(),'planned_end_date'=>$year.'-06-30']);App\Models\ProgrammeOutcome::factory()->create(['apprentice_id'=>$a->id,'context'=>['status'=>'Completed','achievement'=>$i<$achieved?'Achieved':'Not achieved','endDate'=>$year.'-06-20','achievementDate'=>$i<$achieved?$year.'-07-01':null,'sourceRef'=>'PRIVATE-OUTCOME52']]);}}$v=App\Support\HistoricalCohorts::capture($admin,$programme->id,$year.'-01-01',$year.'-12-31',90);$c=App\Support\HistoricalCohorts::create($admin,1,$programme->id,$year.'-01-01',$year.'-12-31',90,$v['source_hash'],(string)Illuminate\Support\Str::uuid(),'PRIVATE-COHORT52');$selected=[];foreach(App\Support\HistoricalCohorts::rows($c)as$row)$selected[$row->id]=$row->version;App\Support\HistoricalCohorts::editBatch($admin,1,$c,$c->version,$selected,$historic->id,App\Support\HistoricalCohorts::BASES[0],'PRIVATE-ATTRIBUTION52');$c->refresh();App\Support\HistoricalCohorts::decide($checker,1,$c,$c->version,'approve','PRIVATE-CHECK52');$ids[]=$c->id;}echo json_encode(['earlier'=>$ids[0],'later'=>$ids[1]]);""".replace('ADMIN52',str(ids['admin'])))
        base52='/reports/historical-cohorts/same-mix';query52=urlencode(bench52)
        status,context52,_,headers52=req(admin,base52+'?'+query52);assert status==200 and 'Historical comparison in context'in context52 and '33.3%'in context52 and '66.7%'in context52 and '50.0%'in context52 and headers52['Referrer-Policy']=='no-referrer'
        assert 'Compare the same mix of planned programme lengths'in req(admin,'/reports/historical-cohorts/compare?'+query52)[1]
        passed('Current approved historical comparison links to a real same-duration-mix report retaining33.3/66.7 recorded results beside50/50 common-mix results')
        match52=re.search(r'analysis=([a-f0-9]{64})',context52);assert match52;analysis52=match52[1];pinned52=query52+'&analysis='+analysis52
        with sqlite3.connect(db)as c:readcounts52=[c.execute('SELECT COUNT(*) FROM '+t).fetchone()[0]for t in ['historical_cohorts','historical_cohort_rows','portal_operations_events','learning_tasks','centre_manager_grants']]
        status,aggregate52,_,head52=req(admin,base52+'.json?'+pinned52);assert status==200;data52=json.loads(aggregate52)
        assert data52['analysis_hash']==analysis52 and [x['weight']for x in data52['weights']]==[.5,.5,0]
        assert data52['overall']['earlier']['same_mix_percentage']==50 and data52['overall']['later']['same_mix_percentage']==50 and not data52['official_qar']and not data52['causal_staff_effectiveness']
        assert all(v not in aggregate52 for v in ['PRIVATE-APPRENTICE52','PRIVATE-CURRENT52','PRIVATE-OUTCOME52','PRIVATE-COHORT52','PRIVATE-ATTRIBUTION52','PRIVATE-CHECK52','"apprentice_id":'])
        passed('Pinned exact JSON uses common weights and frozen historical attribution while excluding apprentice identities/private references and any causal or official-return claim')
        with admin.open(origin+base52+'.pdf?'+pinned52,timeout=30)as pdf52:
            body52=pdf52.read();assert body52.startswith(b'%PDF')and pdf52.headers['Content-Type']=='application/pdf'and pdf52.headers['Referrer-Policy']=='no-referrer'and'no-store'in pdf52.headers['Cache-Control']
        passed('The exact pinned source produces a real private PDF with no-referrer headers over verified HTTPS')
        for denied52 in [personal_client,tutor51]:
            for suffix52 in ['', '.json','.pdf']:assert req(denied52,base52+suffix52+'?'+(query52 if suffix52==''else pinned52))[0]==403
        passed('Fully authenticated privacy-acknowledged ordinary Tutor and Apprentice cannot read the context page, exact JSON or PDF through direct URLs')
        def json52(path):
            try:
                with admin.open(Request(origin+path,headers={'Accept':'application/json'}),timeout=30)as response:return response.status,response.read()
            except HTTPError as error:return error.code,error.read()
        assert json52(base52+'.json?'+query52+'&analysis='+'0'*64)[0]==409
        assert json52(base52+'?'+query52+'&minimum=1')[0]==422
        with sqlite3.connect(db)as c:assert readcounts52==[c.execute('SELECT COUNT(*) FROM '+t).fetchone()[0]for t in ['historical_cohorts','historical_cohort_rows','portal_operations_events','learning_tasks','centre_manager_grants']]
        passed('Wrong source pin and attempted threshold override fail while page/data/PDF reads leave cohort, audit, task and access data unchanged')
        script("$c=App\\Models\\HistoricalCohort::find('"+bench52['later']+"');App\\Support\\HistoricalCohorts::decide(App\\Models\\User::find("+str(ids['ceo'])+"),1,$c,$c->version,'withdraw','Fictional later source withdrawal52');echo '{}';")
        assert json52(base52+'.json?'+pinned52)[0]==422
        passed('Withdrawal of a previously approved source immediately blocks a fresh pinned export rather than serving a cached comparison')
        check52=artisan('gta:release-check52');assert check52.returncode==0;release52=json.loads(check52.stdout);assert release52['pack']=='52'and release52['actual_target_acceptance']is False and release52['new_migrations']==0
        passed('Pack52 read-only cumulative release check exposes the bounded descriptive method without claiming Cloud/native/browser acceptance')

        for client53,page53 in [(admin,'/dashboard'),(personal_client,'/my-next-step'),(tutor51,'/dashboard')]:
            status53,html53,_,_=req(client53,page53);assert status53==200
            assert html53.index('theme-boot53.js')<html53.index('portal.css')<html53.index('theme53.css')<html53.index('portal.js')
            assert html53.count('theme53.css')==1 and 'gta-logo.jpg'in html53 and 'gta-mark.svg'not in html53
        passed('Authenticated Admin, Tutor and Apprentice real pages serve one shared theme after the base CSS and boot the existing preference before CSS')
        css53=req(admin,'/assets/theme53.css')[1];boot53=req(admin,'/assets/theme-boot53.js')[1]
        assert all(value53 in css53 for value53 in ['#DFE9F2','#EAF0F6','#CDDFE5','#9BC6CC','#18212B','input::placeholder','input:disabled','input[readonly]','input:-webkit-autofill',':focus-visible','@media print'])
        assert 'gta-local-theme'in boot53 and 'setItem'not in boot53
        passed('Actual HTTP assets contain the authoritative v2 colours and declared control states; early boot reads without overwriting the saved preference')
        result53=artisan('gta:release-check53');assert result53.returncode==0;read53=json.loads(result53.stdout)
        assert read53['pack']=='53'and read53['theme_only']and not read53['actual_target_acceptance']and not read53['theme53']['rendered_computed_colours_measured']and len(read53['theme53']['declared_solid_pairs'])==56
        passed('Read-only Pack53 checks validate56 declared pairs and source receipts while explicitly withholding rendered and live acceptance')

        with sqlite3.connect(db) as c: preserved47=[c.execute('SELECT COUNT(*) FROM '+t).fetchone()[0]for t in ['timetable_plans','timetable_rooms','timetable_staff_windows']];before_tables={row[0] for row in c.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('account_lifecycle_proposals','account_lifecycle_states','cohort_forecasts','cohort_snapshots','operational_followups','portal_operations_events')").fetchone()[0]==6;assert preserved47==[c.execute('SELECT COUNT(*) FROM '+t).fetchone()[0]for t in ['timetable_plans','timetable_rooms','timetable_staff_windows']]
        with sqlite3.connect(db) as c:
            after_tables={row[0] for row in c.execute("SELECT name FROM sqlite_master WHERE type='table'")}
            assert before_tables-after_tables=={'centre_manager_grants','centre_manager_access_requests','centre_manager_followups'} and not(after_tables-before_tables)
        assert artisan('migrate','--force').returncode==0
        passed('Latest Pack49 down/up removes only the three Centre Manager access/follow-up tables while all prior schema and business-data checks are retained')
        configure('production');start_backend();assert req(admin,root)[0]==503
        passed('Existing production-access gate remains enforced')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual local client-verified HTTPS with disposable SQLite/encrypted database sessions/cache','actualCloudAccess':False,'actualNativeEngine':False,'actualBrowserOrPhoneTested':False,'realDataUsed':False,'externalMessagesSent':False,'productionApproved':False,'registerAccessGrantedByCover':False,'newGovernanceChecks':5,'newPack41Checks':6,'newPack42Checks':7,'newPack43Checks':7,'newPack44Checks':7 if args.browser else 5,'newPack45Checks':6,'newPack46Checks':6,'newPack47Checks':7,'newPack48Checks':8,'newPack49Checks':9,'newPack50Checks':6,'newPack51Checks':6,'newPack52Checks':7,'newPack53Checks':3,'newPack54Checks':8,'newPack55Checks':9,'newPack56Checks':3,'browser':browser_result};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps(output))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
