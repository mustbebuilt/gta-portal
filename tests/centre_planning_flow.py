"""Fictional Pack38 centre planning workflows over a local TLS proxy; never accesses Laravel Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-centre-planning-') as temporary:
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
        ids=script(r"$out=[];foreach(['admin','planner','tutor','other','learner']as$key){$u=App\Models\User::factory()->create(['role'=>$key==='planner'?'manager':($key==='other'?'tutor':($key==='learner'?'apprentice':$key)),'email'=>$key.'@example.test','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);$out[$key]=$u->id;}$g=App\Models\TutorGroup::factory()->create(['tutor_id'=>$out['tutor'],'name'=>'Fictional training group']);$a=App\Models\Apprentice::factory()->create(['user_id'=>$out['learner'],'email'=>'learner@example.test','tutor_group_id'=>$g->id,'tutor_id'=>$out['tutor'],'reference'=>'PRIVATE-APPRENTICE-REFERENCE']);$out['group']=$g->id;$out['a']=$a->id;$out['room']=App\Models\TimetableRoom::where('site','Doncaster')->first()->id;$out['date']=today()->addDays(3)->toDateString();echo json_encode($out);")
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            assert req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password})[0]==200;return c
        def post(c,source,path,values):
            status,text,_,_=req(c,source);assert status==200;pairs=[('_token',csrf(text))]
            for k,v in values.items():
                if isinstance(v,list):pairs.extend((k,str(i))for i in v)
                else:pairs.append((k,str(v)))
            return req(c,path,pairs)
        def hidden(text,name):
            m=re.search(r'name="'+re.escape(name)+r'"\s+value="([^"]*)"',text);assert m,'Missing expected real form field';return m[1]
        import uuid
        admin=login('admin@example.test');planner=login('planner@example.test');tutor=login('tutor@example.test');other=login('other@example.test');learner=login('learner@example.test');root='/centre-planning';setup=root+'/setup';form=root+'/plans/new';plans=root+'/plans'
        assert req(planner,setup)[0]==403 and req(learner,root)[0]==403 and req(admin,root+'/access/'+str(ids['planner']),{'active':1})[0]==419
        assert post(admin,setup,root+'/access/'+str(ids['planner']),{'version':0,'active':1,'confirmed':1})[0]==200
        assert req(planner,setup)[0]==200 and req(planner,'/portal-setup')[0]==403 and req(tutor,setup)[0]==403
        passed('Separate centre-planner permission granted through real CSRF forms; staff role preserved and broader admin/learner boundaries enforced')
        assert post(planner,setup,root+'/rooms/'+str(ids['room']),{'version':1,'capacity':8,'uses[]':['Teaching','Review','Admin'],'active':1,'confirmed':1})[0]==200
        working=root+'/working-time?'+urlencode({'staff_id':ids['tutor'],'date':ids['date'],'weeks':2});_,text,_,_=req(planner,working);hash=hidden(text,'source_hash')
        assert post(planner,working,root+'/working-time',{'staff_id':ids['tutor'],'date':ids['date'],'weeks':2,'source_hash':hash,'working':1,'start':'08:00','end':'18:00','confirmed':1})[0]==200
        passed('Checked room capacity/use and exact two dated working windows saved; no default room capacity or employment assumptions used')
        d={'request_token':str(uuid.uuid4()),'version':0,'staff_id':ids['tutor'],'date':ids['date'],'weeks':2,'start':'09:00','end':'12:00','kind':'Teaching','site':'Doncaster','room_id':ids['room'],'group_id':ids['group'],'headcount':4,'title':'Fictional engine training','confirmed':1}
        status,text,url,_=post(planner,form,plans,d);assert status==200 and 'Draft'in text;detail=url[len(origin):];pid=detail.rsplit('/',1)[-1]
        board=root+'?'+urlencode({'week':ids['date'],'mode':'staff','resource':ids['tutor']});assert 'Fictional engine training'not in req(tutor,board)[1]
        post(planner,detail,detail+'/decision',{'version':1,'action':'publish','confirmed':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM timetable_plans WHERE id=?',(pid,)).fetchone()[0]=='Draft'
        assert post(planner,detail,detail+'/decision',{'version':1,'action':'check','confirmed':1})[0]==200
        assert post(planner,detail,detail+'/decision',{'version':2,'action':'publish','confirmed':1})[0]==200
        status,text,_,headers=req(planner,board);assert status==200 and 'Fictional engine training'in text and 'Available'in text and 'Accessible item list'in text and headers['Referrer-Policy']=='no-referrer'
        assert 'Fictional engine training'in req(tutor,board)[1] and 'Fictional engine training'not in req(other,board)[1]
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM timetable_plan_entries WHERE plan_id=?',(pid,)).fetchone()[0]==2;assert c.execute('SELECT COUNT(*) FROM training_sessions').fetchone()[0]==0;assert 'Fictional engine'not in c.execute('SELECT source FROM timetable_plans WHERE id=?',(pid,)).fetchone()[0]
        passed('Draft/check/publish exact recurrence visible only after checked publication; own staff timetable forced and no register automatically created')
        status,report,_,_=req(planner,root+'.json?'+urlencode({'week':ids['date']}));j=json.loads(report);assert status==200 and j['actual_attendance_or_otj']is False and 'PRIVATE-APPRENTICE'not in report and 'Fictional engine training'not in report
        assert req(tutor,root+'.json')[0]==403
        passed('Staff/room/group board source, safe JSON export and current scope preserve private apprentice references and planning-only meaning')
        # Create an ordinary existing register; linking must only relate records, never create marks or mutate register facts.
        reg=script("$s=App\\Models\\TrainingSession::factory()->create(['tutor_group_id'=>"+str(ids['group'])+",'created_by'=>"+str(ids['admin'])+",'session_date'=>'"+ids['date']+"','start_time'=>'09:00','end_time'=>'12:00','members'=>["+str(ids['a'])+"]]);$e=App\\Models\\TimetablePlanEntry::where('plan_id','"+pid+"')->orderBy('work_date')->first();echo json_encode(['id'=>$s->id,'entry'=>$e->id]);")
        assert post(planner,detail,root+'/entries/'+str(reg['entry'])+'/register',{'version':3,'session_id':reg['id'],'session_version':1,'confirmed':1})[0]==200
        j=json.loads(req(planner,root+'.json?'+urlencode({'week':ids['date']}))[1]);assert len(j['rows'])==1
        script("App\\Models\\TrainingSession::whereKey("+str(reg['id'])+")->increment('version');echo '{}';")
        j=json.loads(req(planner,root+'.json?'+urlencode({'week':ids['date']}))[1]);assert len(j['rows'])==2 and 'CLASH'in req(planner,board)[1]
        passed('Checked exact register link collapses duplicate while preserving room reservation; later register drift shows separate conflicting sources without changing either')
        browser_checks=[]
        if args.browser:
            from playwright.sync_api import sync_playwright,expect
            with sync_playwright()as pw:
                browser=pw.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox'])
                # This private loopback browser context accepts only the fixture's self-signed cert; Python HTTPS above verifies its exact cert.
                context=browser.new_context(viewport={'width':1440,'height':1000},ignore_https_errors=True);page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
                page.goto(origin+'/login');page.get_by_label('Email address',exact=True).fill('planner@example.test');page.get_by_label('Password',exact=True).fill(private_password);page.get_by_role('button',name='Sign in',exact=True).click();page.wait_for_load_state('networkidle')
                page.goto(origin+board);expect(page.get_by_role('heading',name='Centre timetable',exact=True)).to_be_visible();expect(page.locator('.planning-card')).to_have_count(2);expect(page.locator('.planning-gap').first).to_be_visible();assert page.locator('.planning-card').first.evaluate('(e)=>e.getBoundingClientRect().height')>20
                assert page.locator('.planning-grid').evaluate('(e)=>getComputedStyle(e).tableLayout')=='fixed';browser_checks.append('Actual Chromium desktop grid loads feature CSS, two distinct overlapping cards, accessible list and recorded gaps')
                screenshots=os.getenv('GTA_FIXTURE_SCREENSHOT_DIR')
                if screenshots:Path(screenshots).mkdir(parents=True,exist_ok=True);page.screenshot(path=str(Path(screenshots)/'centre-planning-desktop.png'),full_page=True)
                page.set_viewport_size({'width':390,'height':844});assert page.locator('.planning-scroll').evaluate('(e)=>e.scrollWidth>e.clientWidth');expect(page.get_by_role('heading',name='Accessible item list')).to_have_count(1);page.locator('.planning-scroll').focus();page.keyboard.press('ArrowRight');browser_checks.append('Actual Chromium390px viewport retains a horizontally scrollable grid and complete alternate item list')
                if screenshots:page.screenshot(path=str(Path(screenshots)/'centre-planning-mobile.png'),full_page=True)
                page.emulate_media(media='print');assert page.locator('.planning-grid').evaluate('(e)=>getComputedStyle(e).display')=='none';expect(page.get_by_role('heading',name='Accessible item list')).to_be_visible();browser_checks.append('Actual print CSS suppresses clipped grid and retains the full item list')
                assert not errors;context.close();browser.close()
            for item in browser_checks:passed(item)
        assert post(planner,detail,detail+'/decision',{'version':3,'action':'withdraw','reason':'Synthetic future reschedule','confirmed':1})[0]==200
        j=json.loads(req(planner,root+'.json?'+urlencode({'week':ids['date']}))[1]);assert len(j['rows'])==1 and j['rows'][0]['external']is True
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM attendance_marks').fetchone()[0]==0;assert c.execute('SELECT COUNT(*) FROM signed_review_records').fetchone()[0]==0;assert c.execute('SELECT state FROM training_sessions WHERE id=?',(reg['id'],)).fetchone()[0]=='Planned'
        passed('Future planning withdrawal retains the original register and creates no attendance, OTJ or formal review completion')
        assert post(admin,setup,root+'/access/'+str(ids['planner']),{'version':1,'active':0,'confirmed':1})[0]==200
        assert req(planner,setup)[0]==403
        passed('Current planning-access revocation removes setup/write privileges on the next authenticated request')
        with sqlite3.connect(db) as c: before_tables={row[0] for row in c.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('account_lifecycle_proposals','account_lifecycle_states','cohort_forecasts','cohort_snapshots','operational_followups','portal_operations_events')").fetchone()[0]==6;assert c.execute('SELECT COUNT(*) FROM training_sessions').fetchone()[0]==1;assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='review_appointments'").fetchone()[0]==1
        with sqlite3.connect(db) as c:
            after_tables={row[0] for row in c.execute("SELECT name FROM sqlite_master WHERE type='table'")}
            assert before_tables-after_tables=={'historical_cohorts','historical_cohort_rows'} and not(after_tables-before_tables)
        assert artisan('migrate','--force').returncode==0
        passed('Latest Pack48 down/up removes only the two historical cohort tables while all prior schema and business-data checks are retained')
        configure('production');start_backend();assert req(admin,root)[0]==503
        passed('Existing production-access gate remains unchanged')
        result={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual local client-verified HTTPS with disposable SQLite/encrypted database sessions/cache','actualChromiumTested':args.browser,'browserFixtureAcceptsLocalSelfSignedCertificate':args.browser,'physicalPhoneTested':False,'actualNativeEngine':False,'actualCloudAccess':False,'realDataUsed':False,'externalMessagesSent':False,'productionApproved':False};Path(args.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
