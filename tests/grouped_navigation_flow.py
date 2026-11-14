"""Fictional management assignment checks and retained staff additional-duty workflow over a local TLS proxy; never accesses Laravel Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-staff-duties-') as temporary:
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
        seed=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$users=[];foreach(['admin@example.test'=>'admin','tutor@example.test'=>'tutor','other@example.test'=>'tutor','learner@example.test'=>'apprentice']as$email=>$role){$users[$email]=App\Models\User::factory()->create(['name'=>$email==='tutor@example.test'?'Fictional Dual Duty Staff':'Fictional Test Account','email'=>$email,'role'=>$role,'password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);}$own=App\Models\Apprentice::factory()->create(['reference'=>'DUTY-MAIN','first_name'=>'Fictional Own Tutor Learner','tutor_id'=>$users['tutor@example.test']->id]);$target=App\Models\Apprentice::factory()->create(['reference'=>'DUTY-TARGET','first_name'=>'Fictional Duty Learner','tutor_id'=>$users['other@example.test']->id]);$target->user_id=$users['learner@example.test']->id;$target->save();$outside=App\Models\Apprentice::factory()->create(['reference'=>'DUTY-OUTSIDE','first_name'=>'Fictional Unrelated Learner','tutor_id'=>$users['other@example.test']->id]);$task=App\Models\LearningTask::factory()->create(['apprentice_id'=>$own->id]);echo json_encode(['task'=>$task->id,'users'=>array_map(fn($u)=>$u->id,$users),'own'=>$own->id,'target'=>$target->id,'outside'=>$outside->id]);"
        seeded=subprocess.run([args.php,'-r',seed],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert seeded.returncode==0,'Fictional staff setup failed';ids=json.loads(seeded.stdout);target=ids['target'];staff=ids['users']['tutor@example.test'];staff_path=f'/directories/staff/{staff}';duty_path=f'/staff/{staff}/duties'
        with sqlite3.connect(db)as c:password_before=c.execute('SELECT password FROM users WHERE id=?',(staff,)).fetchone()[0];original_users=c.execute('SELECT COUNT(*) FROM users').fetchone()[0]
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
        admin=login('admin@example.test');tutor=login('tutor@example.test');learner=login('learner@example.test')
        def menu(text):
            found=re.search(r'<nav id="portal-navigation".*?</nav>',text,re.S);assert found,'Grouped menu missing';return found[0]
        def open_group(text,key):
            return re.search(r'<details[^>]*data-navigation-group="'+key+r'"[^>]*\bopen(?:\s|>)',menu(text))is not None
        status,text,_,_=req(admin,'/help');assert status==200
        nav=menu(text);assert len(re.findall(r'data-navigation-group=',nav))==7 and not re.search(r'<details[^>]*\bopen(?:\s|>)',nav)
        assert 'My responsibilities'in nav and 'Dashboard'in nav and 'Help and how to'in nav and '<summary>Administration</summary>'in nav
        passed('Actual management Help response groups the full menu into seven native closed sections with common shortcuts retained')
        status,text,_,_=req(tutor,'/help');assert status==200
        nav=menu(text);assert 'data-navigation-group="administration"'not in nav and 'data-navigation-group="system"'not in nav and 'Marking worklist'in nav
        status,text,_,_=req(learner,'/help');assert status==200 and 'data-navigation-group="staff-quality"'not in menu(text)
        passed('Role-specific rendered menus omit empty administration/system/staff groups while retaining permitted staff work links')
        status,text,_,_=req(admin,'/account/password');assert status==200 and open_group(text,'account') and not open_group(text,'training')
        status,text,_,_=req(tutor,f'/tasks/{ids["task"]}');assert status==200 and open_group(text,'training')
        passed('Actual account and task responses automatically open the matching navigation section without expanding unrelated sections')
        task_path=f'/tasks/{ids["task"]}';deadline_path=task_path+'/deadline'
        status,text,_,_=post(tutor,task_path,deadline_path,{'version':'1','due_date':'2026-12-01','reason':'Fictional deadline agreement'});assert status==200
        with sqlite3.connect(db)as c:
            date,version,history=c.execute('SELECT due_date,version,deadline_history FROM learning_tasks WHERE id=?',(ids['task'],)).fetchone()
            assert date.startswith('2026-12-01') and version==2
            rows=json.loads(history);assert len(rows)==1 and rows[0]['before']is None and rows[0]['after']=='2026-12-01' and rows[0]['origin']=='manual' and rows[0]['by']==staff
            before=c.execute('SELECT * FROM learning_tasks WHERE id=?',(ids['task'],)).fetchone()
        status,text,_,_=req(tutor,task_path);assert status==200 and 'Deadline change history'in text and 'Fictional deadline agreement'in text
        status,text,_,_=post(tutor,task_path,deadline_path,{'version':'2','due_date':'2026-12-01','reason':'No change'});assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT * FROM learning_tasks WHERE id=?',(ids['task'],)).fetchone()==before
        passed('Actual task deadline POST stores dated actor/before/after history and staff display; identical-date save preserves the exact task row')
        status,text,_,_=post(tutor,task_path,deadline_path,{'version':'1','due_date':'2026-12-10'});assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT * FROM learning_tasks WHERE id=?',(ids['task'],)).fetchone()==before
        status,text,_,_=post(tutor,task_path,deadline_path,{'version':'2','due_date':'','reason':'Fictional date cleared'});assert status==200
        with sqlite3.connect(db)as c:
            date,version,history=c.execute('SELECT due_date,version,deadline_history FROM learning_tasks WHERE id=?',(ids['task'],)).fetchone();assert date is None and version==3 and len(json.loads(history))==2
        assert req(learner,deadline_path,{'_token':csrf(req(learner,'/help')[1]),'version':'3','due_date':'2026-12-10'})[0]==403
        passed('Stale deadline save cannot replace current data; clearing appends a second history entry and an unrelated learner cannot edit it')
        assert req(tutor,'/careers')[0]==403 and req(tutor,f'/apprentices/{target}/reviews/new')[0]==403
        status,text,_,_=req(admin,staff_path);assert status==200 and 'Additional duties on this account'in text
        assert req(admin,duty_path,{'record_version':'1','duty_version':'0','duties[mentor]':'1'})[0]==419
        token=csrf(req(tutor,'/help')[1]);assert req(tutor,duty_path,{'_token':token,'record_version':'1','duty_version':'0','duties[mentor]':'1'})[0]==403
        passed('Additional access is absent by default; grant endpoint requires management authority and actual CSRF')
        status,text,_,_=post(admin,staff_path,duty_path,{'record_version':'1','duty_version':'0','duties[admin]':'1'});assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM staff_duties').fetchone()[0]==0
        status,text,_,_=post(admin,staff_path,duty_path,{'record_version':'1','duty_version':'0','duties[careers_adviser]':'1','duties[mentor]':'1'});assert status==200
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT role,auth_version,record_version,password FROM users WHERE id=?',(staff,)).fetchone()==('tutor',2,2,password_before)
            assert c.execute('SELECT COUNT(*) FROM users').fetchone()[0]==original_users
            row=c.execute('SELECT careers_adviser,mentor,version,history FROM staff_duties WHERE user_id=?',(staff,)).fetchone();assert row[:3]==(1,1,1)and len(json.loads(row[3]))==1
        assert req(tutor,'/help')[2].endswith('/login');tutor=login('tutor@example.test')
        passed('Two explicit grants retain one account, its tutor primary role/password and audited history while invalidating the old session')
        status,text,_,_=req(tutor,'/careers');assert status==200 and 'Fictional Duty Learner'not in text and 'Fictional Own Tutor Learner'not in text
        edit=f'/apprentices/{target}/edit';status,text,_,_=req(admin,edit);assert status==200
        for field in ['careers_adviser_id','mentor_id']:
            select=re.search(r'<select[^>]*name="'+field+r'"[^>]*>(.*?)</select>',text,re.S)[1];assert f'value="{staff}"'in select
        values={'_method':'put','version':'1','reference':'DUTY-TARGET','first_name':'Fictional Duty Learner','last_name':'Apprentice','tutor_id':str(ids['users']['other@example.test']),'careers_adviser_id':str(staff),'mentor_id':str(staff)}
        status,text,_,_=req(admin,f'/apprentices/{target}',{'_token':csrf(text),**values});assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT careers_adviser_id,mentor_id,tutor_id FROM apprentices WHERE id=?',(target,)).fetchone()==(staff,staff,ids['users']['other@example.test'])
        passed('Granted staff become selectable for both duties; explicit apprentice update records those assignments without changing the main tutor')
        status,text,_,_=req(tutor,'/careers');assert status==200 and 'Fictional Duty Learner'in text and 'Fictional Own Tutor Learner'not in text and 'Fictional Unrelated Learner'not in text
        status,text,_,_=req(tutor,'/reviews');assert status==200 and 'DUTY-TARGET'in text and 'DUTY-MAIN'in text and 'DUTY-OUTSIDE'not in text
        status,text,_,_=req(tutor,f'/apprentices/{target}/reviews/new');assert status==200 and 'Open original Action Plan'not in text and 'Open individual delivery plan'not in text
        assert req(tutor,f'/apprentices/{ids["outside"]}/careers')[0]==403 and req(tutor,f'/apprentices/{ids["outside"]}/reviews/new')[0]==403
        assert req(tutor,f'/apprentices/{target}/learning')[0]==403 and req(tutor,'/directories/staff')[0]==403 and req(tutor,f'/apprentices/{target}/support-plans/new')[0]==403
        passed('Careers/review lists use the assigned scope union only; additional mentor duty does not grant unrelated tasks, administration or private Inclusion plans')
        career_path=f'/apprentices/{target}/careers';today=time.strftime('%Y-%m-%d',time.gmtime())
        status,text,_,_=post(tutor,career_path,career_path,{'type':'progression','discussion_date':today,'goals':'Fictional goal','options':'Fictional options','advice':'PRIVATE-DUAL-DUTY-ADVICE','outcome':'Next step agreed','next_step':'Fictional next step'});assert status==200
        review_new=f'/apprentices/{target}/reviews/new'
        status,text,_,_=post(tutor,review_new,f'/apprentices/{target}/reviews',{'fields[reviewDate]':today,'fields[summary]':'PRIVATE-DUAL-DUTY-REVIEW'});assert status==200
        with sqlite3.connect(db)as c:
            discussion=c.execute('SELECT * FROM career_discussions WHERE apprentice_id=?',(target,)).fetchall();review=c.execute('SELECT * FROM progress_review_drafts WHERE apprentice_id=?',(target,)).fetchall();assert len(discussion)==1 and len(review)==1
        passed('One login records an assigned private Careers discussion and an assigned mentor review draft with separate source attribution')
        status,text,_,_=post(admin,staff_path,duty_path,{'record_version':'1','duty_version':'0'});assert status==200 and 'duties changed'in text
        with sqlite3.connect(db)as c:assert c.execute('SELECT version FROM staff_duties WHERE user_id=?',(staff,)).fetchone()[0]==1
        passed('Stale account/duty form cannot overwrite a newer grant')
        status,text,_,_=post(admin,staff_path,duty_path,{'record_version':'2','duty_version':'1','duties[mentor]':'1'});assert status==200
        assert req(tutor,'/careers')[2].endswith('/login');tutor=login('tutor@example.test')
        assert req(tutor,'/careers')[0]==403 and req(tutor,career_path)[0]==403
        status,text,_,_=req(tutor,'/reviews');assert status==200 and 'DUTY-TARGET'in text and 'DUTY-MAIN'in text
        passed('Revoking Careers signs out the old session and removes Careers access while retaining assigned mentor and primary tutor access')
        status,text,_,_=post(admin,staff_path,duty_path,{'record_version':'3','duty_version':'2'});assert status==200
        assert req(tutor,'/help')[2].endswith('/login');tutor=login('tutor@example.test')
        status,text,_,_=req(tutor,'/reviews');assert status==200 and 'DUTY-MAIN'in text and 'DUTY-TARGET'not in text
        assert req(tutor,f'/apprentices/{target}/reviews/new')[0]==403
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT * FROM career_discussions WHERE apprentice_id=?',(target,)).fetchall()==discussion
            assert c.execute('SELECT * FROM progress_review_drafts WHERE apprentice_id=?',(target,)).fetchall()==review
            assert c.execute('SELECT careers_adviser_id,mentor_id FROM apprentices WHERE id=?',(target,)).fetchone()==(staff,staff)
        passed('Revoking mentor removes its scope while retaining tutor access, previous assignments and exact saved Careers/review histories')
        status,text,_,_=req(tutor,'/help');assert 'Read-aloud voice'in text and 'Nothing is read automatically'in text
        stop_backend();start_backend();assert req(tutor,'/reviews')[0]==200
        passed('Pack11 optional voice interface is retained and correct database-session access survives PHP restart')
        status,text,_,_=post(admin,staff_path,duty_path,{'record_version':'4','duty_version':'3','duties[maths_tutor]':'1'});assert status==200
        assert req(tutor,'/help')[2].endswith('/login');tutor=login('tutor@example.test')
        status,text,_,_=req(admin,edit);assert status==200
        select=re.search(r'<select[^>]*name="maths_tutor_id"[^>]*>(.*?)</select>',text,re.S)[1];assert f'value="{staff}"'in select
        select=re.search(r'<select[^>]*name="english_tutor_id"[^>]*>(.*?)</select>',text,re.S)[1];assert f'value="{staff}"'not in select
        status,text,_,_=req(tutor,'/maths-english');assert status==200 and 'DUTY-TARGET'not in text
        values.update(version='2',maths_tutor_id=str(staff))
        status,text,_,_=req(admin,f'/apprentices/{target}',{'_token':csrf(req(admin,edit)[1]),**values});assert status==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT maths_tutor_id,english_tutor_id FROM apprentices WHERE id=?',(target,)).fetchone()==(staff,None)
        passed('Maths-only duty appears only in the Maths selector and grants no caseload until an explicit apprentice assignment is saved')
        status,text,_,_=req(tutor,'/maths-english');assert status==200 and 'DUTY-TARGET'in text and 'DUTY-OUTSIDE'not in text
        path=f'/apprentices/{target}/maths-english'
        assert req(tutor,path+'?subject=maths')[0]==200 and req(tutor,path+'?subject=english')[0]==403
        data={'subject':'maths','type':'Functional Skills','title':'Fictional TLS Maths','level':'Level 2','status':'Working towards','start_date':today}
        status,text,_,_=post(tutor,path,f'/apprentices/{target}/qualifications',data);assert status==200
        status,text,_,_=post(tutor,path,f'/apprentices/{target}/qualifications',{**data,'subject':'english'});assert status==403
        with sqlite3.connect(db)as c:
            qualifications=c.execute('SELECT * FROM qualifications WHERE apprentice_id=?',(target,)).fetchall();assert len(qualifications)==1
        passed('Actual HTTPS subject pages and qualification writes allow assigned Maths only, refusing English and unrelated scope')
        status,text,_,_=post(admin,staff_path,duty_path,{'record_version':'5','duty_version':'4'});assert status==200
        assert req(tutor,path)[2].endswith('/login');tutor=login('tutor@example.test');assert req(tutor,path)[0]==403
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT * FROM qualifications WHERE apprentice_id=?',(target,)).fetchall()==qualifications
            assert c.execute('SELECT maths_tutor_id FROM apprentices WHERE id=?',(target,)).fetchone()[0]==staff
        passed('Subject revocation invalidates the old database session and removes access while preserving qualification and assignment history')
        with sqlite3.connect(db)as c:
            before_people=c.execute('SELECT * FROM apprentices ORDER BY id').fetchall()
            before_duties=c.execute('SELECT * FROM staff_duties ORDER BY user_id').fetchall()
            before_accounts=c.execute('SELECT * FROM users ORDER BY id').fetchall()
        status,text,_,headers=req(admin,'/assignment-checks');assert status==200 and 'no-store'in headers.get('Cache-Control','')
        assert 'Role or subject duty needs checking'in text and 'DUTY-TARGET'in text
        assert req(tutor,'/assignment-checks')[0]==403 and req(learner,'/assignment-checks')[0]==403
        passed('Management-only assignment report flags retained revoked duties and refuses both tutor and apprentice; response is not cached')
        status,text,_,_=req(admin,f'/assignment-checks?view=all&role=maths_tutor&staff={staff}');assert status==200 and 'DUTY-TARGET'in text and 'DUTY-OUTSIDE'not in text
        assert 'Edit assignments'in text and 'Open staff record'in text
        assert req(admin,'/assignment-checks?view=unassigned&role=english_tutor')[0]==200
        assert '/assignment-checks'in req(admin,'/portal-setup')[1]
        assert '/assignment-checks'in req(admin,staff_path)[1]
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT * FROM apprentices ORDER BY id').fetchall()==before_people
            assert c.execute('SELECT * FROM staff_duties ORDER BY user_id').fetchall()==before_duties
            assert c.execute('SELECT * FROM users ORDER BY id').fetchall()==before_accounts
        passed('Live report filters responsibility/person, links exact existing edit screens, separates unassigned responsibilities and preserves all business rows/grants/accounts')
        status,text,_,_=post(admin,staff_path,duty_path,{'record_version':'6','duty_version':'5','duties[careers_adviser]':'1','duties[mentor]':'1','duties[maths_tutor]':'1'});assert status==200
        tutor=login('tutor@example.test')
        with sqlite3.connect(db)as c:
            before_people=c.execute('SELECT * FROM apprentices ORDER BY id').fetchall()
            before_duties=c.execute('SELECT * FROM staff_duties ORDER BY user_id').fetchall()
            before_accounts=c.execute('SELECT * FROM users ORDER BY id').fetchall()
        status,text,_,headers=req(tutor,'/my-responsibilities');assert status==200 and 'no-store'in headers.get('Cache-Control','')
        assert 'DUTY-MAIN'in text and 'DUTY-TARGET'in text and 'DUTY-OUTSIDE'not in text
        assert req(learner,'/my-responsibilities')[0]==403
        assert 'DUTY-TARGET'not in req(admin,'/my-responsibilities')[1]
        status,text,_,_=req(tutor,'/my-responsibilities?role=careers_adviser');assert status==200 and 'DUTY-TARGET'in text and 'DUTY-MAIN'not in text
        status,text,_,_=req(tutor,'/my-responsibilities?search=DUTY-MAIN');assert status==200 and 'DUTY-MAIN'in text and 'DUTY-TARGET'not in text
        assert req(tutor,'/my-responsibilities?role=english_tutor')[0]==403
        assert req(tutor,f'/apprentices/{target}/maths-english?subject=maths')[0]==200
        assert req(tutor,f'/apprentices/{target}/maths-english?subject=english')[0]==403
        with sqlite3.connect(db)as c:
            assert c.execute('SELECT * FROM apprentices ORDER BY id').fetchall()==before_people
            assert c.execute('SELECT * FROM staff_duties ORDER BY user_id').fetchall()==before_duties
            assert c.execute('SELECT * FROM users ORDER BY id').fetchall()==before_accounts
        passed('Live staff hub shows only eligible own assignments; filters/subject links respect scope, management sees no global personal names, learner is refused and business rows are unchanged')
        status,text,_,_=post(admin,staff_path,duty_path,{'record_version':'7','duty_version':'6'});assert status==200
        assert req(tutor,'/my-responsibilities')[2].endswith('/login');tutor=login('tutor@example.test')
        status,text,_,_=req(tutor,'/my-responsibilities');assert status==200 and 'DUTY-MAIN'in text and 'DUTY-TARGET'not in text
        assert req(tutor,'/my-responsibilities?role=careers_adviser')[0]==403
        passed('Extra-duty removal invalidates the old hub session and removes additional scopes while retaining the main tutor assignment')
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:
            assert 'deadline_history' not in [r[1] for r in c.execute('PRAGMA table_info(learning_tasks)')]
            assert c.execute('SELECT COUNT(*) FROM staff_duties WHERE user_id=?',(staff,)).fetchone()[0]==1
            assert c.execute('SELECT * FROM career_discussions WHERE apprentice_id=?',(target,)).fetchall()==discussion and c.execute('SELECT * FROM progress_review_drafts WHERE apprentice_id=?',(target,)).fetchall()==review
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('account_second_factors','support_plan_reminders')").fetchone()[0]==2
        assert artisan('migrate','--force').returncode==0
        passed('New migration rolls back/reapplies only the latest task-history column, preserving the duty table on disposable SQLite; previous business records, MFA and support-reminder schema remain')
        configure('production');start_backend();assert req(admin,staff_path)[0]==503 and req(admin,'/assignment-checks')[0]==503 and req(admin,'/my-responsibilities')[0]==503
        passed('Existing production gate remains enforced')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Local client-verified HTTPS, cached configuration and disposable SQLite database sessions/cache','realDataUsed':False,'externalMessagesSent':False,'actualCloudAccess':False,'actualNativeEngine':False,'actualYorkshireVoiceTested':False,'limits':['No actual Martin repository/native locks/Cloud/browser/mobile acceptance','Additional Careers, mentor, Maths and English duties are explicit; primary role, Inclusion and admin policies remain separate','Pack11 voice choice retained; no regional voice audio/service generated']};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
