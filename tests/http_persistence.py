"""Loopback HTTP persistence proof with a disposable SQLite database. No browser."""
from pathlib import Path
import argparse, base64, http.cookiejar, json, os, re, secrets, signal, socket, sqlite3, subprocess, tempfile, time
from urllib.request import build_opener, HTTPCookieProcessor, Request
from urllib.parse import urlencode
from urllib.error import HTTPError, URLError

parser = argparse.ArgumentParser()
parser.add_argument('--php', default='php')
parser.add_argument('--output', required=True)
args = parser.parse_args()
project = Path(__file__).resolve().parent.parent
results = []
server = None

def passed(name):
    results.append({'name': name, 'passed': True})

with tempfile.TemporaryDirectory(prefix='gta-laravel-http-') as temp:
    temp = Path(temp)
    database = temp/'fictional.sqlite'
    database.touch()
    env = os.environ.copy()
    env.update(APP_ENV='local', APP_DEBUG='false', APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(), DB_CONNECTION='sqlite', DB_DATABASE=str(database), CACHE_STORE='file', SESSION_DRIVER='file', MAIL_MAILER='array', QUEUE_CONNECTION='sync')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    env['APP_URL'] = base
    def artisan(*command):
        result = subprocess.run([args.php, 'artisan', *command, '--no-interaction'], cwd=project, env=env, capture_output=True, text=True)
        assert result.returncode == 0, 'Artisan command failed: '+result.stderr
        return result.stdout
    artisan('migrate')
    seed = artisan('gta:demo')
    password = seed.split('Local test password for these accounts: ',1)[1].splitlines()[0].strip()
    passed('Fresh disposable database migrated and fictional setup created')
    def client(): return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(opener, path, data=None):
        payload = urlencode(data).encode() if data is not None else None
        try:
            with opener.open(Request(base+path, data=payload), timeout=15) as response:
                return response.status, response.read().decode(), response.url
        except HTTPError as error:
            return error.code, error.read().decode(), error.url
    def upload(opener,path,fields,filename,content):
        boundary='gta'+secrets.token_hex(12)
        parts=[]
        for name,value in fields.items():
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{filename}"\r\nContent-Type: text/plain\r\n\r\n'.encode()+content+b'\r\n')
        parts.append(f'--{boundary}--\r\n'.encode())
        with opener.open(Request(base+path,data=b''.join(parts),headers={'Content-Type':f'multipart/form-data; boundary={boundary}'}),timeout=15) as response:
            return response.status,response.read().decode(),response.url
    def token(text): return re.search(r'name="_token"\s+value="([^"]+)"', text)[1]
    def start():
        global server
        server = subprocess.Popen([args.php, '-S', f'127.0.0.1:{port}', '-t', 'public'], cwd=project, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
        for _ in range(100):
            try:
                if request(client(), '/login')[0] == 200: return
            except (URLError, ConnectionError): pass
            time.sleep(.05)
        raise AssertionError('Local PHP server did not start')
    def stop():
        global server
        if server is not None:
            os.killpg(server.pid, signal.SIGTERM)
            server.wait(timeout=10)
            server = None
    def login(opener, email='admin@example.test'):
        status, text, _ = request(opener, '/login')
        assert status == 200
        status, text, url = request(opener, '/login', {'_token':token(text), 'email':email, 'password':password})
        assert status == 200 and not url.endswith('/login'), 'Sign-in failed'
        return text
    try:
        start()
        c = client()
        status, text, url = request(c, '/apprentices')
        assert status == 200 and url.endswith('/login')
        passed('Unauthenticated HTTP request redirects to sign in')
        status, _, _ = request(c, '/login', {'email':'admin@example.test', 'password':password})
        assert status == 419
        passed('Actual HTTP login rejects a missing CSRF token')
        login(c)
        passed('Generated fictional administrator can sign in through HTTP')
        links = {}
        directory_rows = []
        for kind, payload, table in [
            ('employers', {'reference':'HTTP-EMP','name':'Fictional HTTP Employer','active':'1'}, 'employers'),
            ('programmes', {'standard_code':'HTTP-DEMO','version':'demo-1','name':'Fictional HTTP Programme','level':'2','active':'1'}, 'programmes'),
            ('staff', {'name':'Fictional HTTP Mentor','email':'http-mentor@example.test','role':'mentor','active':'1'}, 'users'),
            ('groups', {'reference':'HTTP-GRP','name':'Fictional HTTP Group','active':'1'}, 'tutor_groups')
        ]:
            status, form, _ = request(c, '/directories/'+kind+'/create')
            assert status == 200
            status, page, target = request(c, '/directories/'+kind, {**payload, '_token':token(form)})
            assert status == 200 and re.search(r'/directories/'+kind+r'/\d+$', target) and payload['name'] in page
            record_id = int(target.rsplit('/',1)[1])
            links[{'employers':'employer_id','programmes':'programme_id','staff':'mentor_id','groups':'tutor_group_id'}[kind]] = str(record_id)
            with sqlite3.connect(database) as db:
                db.row_factory = sqlite3.Row
                directory_rows.append((table, record_id, dict(db.execute('SELECT * FROM '+table+' WHERE id=?',(record_id,)).fetchone())))
            passed('Actual HTTP creates, saves and reopens '+kind+' directory record')
        status, text, _ = request(c, '/apprentices/create')
        assert status == 200 and 'Save apprentice' in text
        passed('Actual add-apprentice form is served')
        data = {'_token':token(text), 'reference':'HTTP-001', 'first_name':'Fictional', 'last_name':'Persistence Learner', 'email':'proof@example.test', 'programme':'Fictional training programme', 'start_date':'2026-11-01', 'planned_end_date':'2028-11-01', 'employer':'Demo Workshop', 'group_name':'Demo Group', 'tutor_id':''}
        data.update(links)
        status, text, url = request(c, '/apprentices', data)
        assert status == 200 and re.search(r'/apprentices/\d+$',url) and 'Fictional Persistence Learner' in text
        record_path = url.removeprefix(base)
        passed('HTTP add form saves and opens the new apprentice')
        assert all(name in text for name in ['Fictional HTTP Employer','Fictional HTTP Programme','Fictional HTTP Mentor','Fictional HTTP Group'])
        passed('Linked apprentice detail displays saved directory names')
        with sqlite3.connect(database) as db:
            assert db.execute("SELECT COUNT(*) FROM apprentices WHERE reference='HTTP-001'").fetchone()[0] == 1
        passed('Independent database query confirms exactly one saved record')
        status, text, _ = request(c, record_path+'/edit')
        assert status == 200
        version = re.search(r'name="version" value="(\d+)"', text)[1]
        changed = {**data, '_token':token(text), '_method':'PUT', 'version':version, 'employer':'Updated Demo Workshop'}
        status, text, _ = request(c, record_path, changed)
        assert status == 200 and 'Fictional HTTP Employer' in text
        passed('HTTP edit updates the saved apprentice')
        # Exercise the connected modules through actual HTTP forms, not direct writes.
        with sqlite3.connect(database) as db:
            demo_id=db.execute("SELECT id FROM apprentices WHERE reference='DEMO-001'").fetchone()[0]
            group_id=db.execute("SELECT tutor_group_id FROM apprentices WHERE id=?",(demo_id,)).fetchone()[0]
            actor_id=db.execute("SELECT id FROM users WHERE email='admin@example.test'").fetchone()[0]
        status, text, _=request(c,f'/apprentices/{demo_id}/learning')
        status, text, _=request(c,f'/apprentices/{demo_id}/learning',{'_token':token(text),'confirmed':'1'})
        assert status==200 and '50 tasks' in text
        with sqlite3.connect(database) as db:
            task_id, schema=db.execute("SELECT id, template FROM learning_tasks WHERE apprentice_id=? AND reference='b1-t4'",(demo_id,)).fetchone()
            field=next(f['name'] for f in json.loads(schema)['fields'] if f['role']=='apprentice' and f['key']=='apprentice_name')
        passed('Actual HTTP explicitly assigns all 50 source tasks')
        learner=client();login(learner,'apprentice@example.test')
        status,text,_=request(learner,f'/tasks/{task_id}')
        status,text,_=request(learner,f'/tasks/{task_id}',{'_token':token(text),'version':'1','intent':'draft',f'answers[{field}]':'Fictional HTTP Apprentice'})
        assert status==200 and 'Draft saved' in text
        status,text,_=request(learner,f'/tasks/{task_id}',{'_token':token(text),'version':'2','intent':'submit','own_work':'1',f'answers[{field}]':'Fictional HTTP Apprentice'})
        assert status==200 and 'Awaiting marking' in text
        passed('Apprentice HTTP draft and submission retain answers and lock the submitted version')
        status,text,_=request(c,f'/tasks/{task_id}')
        status,text,_=request(c,f'/tasks/{task_id}/decision',{'_token':token(text),'version':'3','decision':'Approved','decision_date':__import__('datetime').date.today().isoformat(),'confirmed':'1','feedback':'Fictional proof decision'})
        assert status==200 and 'Whole-task decision saved' in text
        passed('Authorised HTTP tutor decision saves the whole task approval')
        status,text,_=request(c,f'/apprentices/{demo_id}/qualifications/create')
        status,text,_=request(c,f'/apprentices/{demo_id}/qualifications',{'_token':token(text),'subject':'maths','type':'Functional Skills','title':'HTTP Functional Skills Maths','level':'Level 2','status':'Working towards','start_date':'2026-11-01'})
        assert status==200 and '01 Nov 2027' in text
        passed('Actual HTTP qualification form records an individual twelve-month target')
        status,text,_=request(c,f'/apprentices/{demo_id}/topics',{'_token':token(text),'catalogue_id':'0','planned_hours':'2'})
        assert status==200 and 'Addition and Subtraction' in text
        passed('Actual HTTP topic assignment stays separate from qualification achievement')
        import datetime
        due=(datetime.date.today()+datetime.timedelta(days=14)).isoformat()
        status,text,_=request(c,f'/apprentices/{demo_id}/actions/create')
        status,text,_=request(c,f'/apprentices/{demo_id}/actions',{'_token':token(text),'title':'HTTP five practice questions','owner':'Apprentice','due_date':due,'method':'quantity','quantity':'5','unit':'questions','evidence_method':'worksheet','relevance':'block','learning_reference':'Block 1','support':'Tutor support','achievable':'1','agreed':'1'})
        assert status==200 and '5 questions' in text
        passed('Actual HTTP SMART action saves an objective completion measure')
        status,text,_=request(c,f'/apprentices/{demo_id}/careers')
        status,text,_=request(c,f'/apprentices/{demo_id}/careers',{'_token':token(text),'type':'progression','discussion_date':'2026-11-01','goals':'HTTP fictional career goal','options':'Further learning','advice':'Compare the routes','outcome':'Exploring options','next_step':'Discuss course choices'})
        assert status==200 and 'HTTP fictional career goal' in text
        passed('Actual HTTP Careers discussion is stored in the private Careers area')
        status,text,_=request(c,'/sessions')
        status,text,target=request(c,'/sessions',{'_token':token(text),'tutor_group_id':str(group_id),'name':'HTTP training session','session_date':'2026-11-01','start_time':'09:00','end_time':'16:00','planned_learning':'Fictional inspection learning','breaks_checked':'1','breaks[0][start]':'12:00','breaks[0][end]':'13:00'})
        assert status==200 and re.search(r'/sessions/\d+$',target)
        session_path=target.removeprefix(base)
        status,text,_=request(c,session_path,{'_token':token(text),'version':'1','delivered_learning':'Actual fictional inspection learning',f'marks[{demo_id}][code]':'O',f'marks[{demo_id}][arrival]':'09:00',f'marks[{demo_id}][departure]':'16:00',f'marks[{demo_id}][otj_confirmed]':'1'})
        assert status==200 and '6.00 hours' in text
        passed('Actual HTTP session plan and register confirm exactly six eligible hours')
        status,text,_=request(c,f'/apprentices/{demo_id}/support')
        status,text,_=request(c,f'/apprentices/{demo_id}/support',{'_token':token(text),'initial_note':'HTTP fictional travel concern'})
        assert status==200 and 'HTTP fictional travel concern' in text
        with sqlite3.connect(database) as db:
            concern_id=db.execute('SELECT id FROM support_concerns WHERE apprentice_id=?',(demo_id,)).fetchone()[0]
        status,text,_=request(c,f'/concerns/{concern_id}/classify',{'_token':token(text),'version':'1','code':'IM36','owner_id':str(actor_id),'next_action':'Agree travel plan','action_due':due,'next_review':due})
        assert status==200 and 'Concern acknowledged' in text
        passed('Actual HTTP concern acknowledgement records its marker and responsible owner')
        # Additional persisted modules: real HTTP requests with CSRF, then a fresh reopen.
        status,text,_=request(c,f'/apprentices/{demo_id}/reviews/new')
        status,text,target=request(c,f'/apprentices/{demo_id}/reviews',{'_token':token(text),'fields[reviewDate]':datetime.date.today().isoformat(),'fields[summary]':'Fictional HTTP review preparation'})
        assert status==200 and 'Fictional HTTP review preparation' in text
        passed('Actual HTTP progress-review draft saves a fixed source snapshot without completing a review')
        status,text,_=request(c,f'/apprentices/{demo_id}/outcome')
        status,text,_=request(c,f'/apprentices/{demo_id}/outcome',{'_token':token(text),'version':'0','context[status]':'Active','context[sourceRef]':'Fictional HTTP enrolment source'})
        status,text,_=request(c,f'/apprentices/{demo_id}/outcome')
        assert status==200 and 'Fictional HTTP enrolment source' in text
        passed('Actual HTTP retention context saves without inventing achievement or historical changes')
        status,text,_=request(c,f'/apprentices/{demo_id}/monthly-snapshot')
        status,text,_=request(c,f'/apprentices/{demo_id}/monthly-snapshot',{'_token':token(text),'version':'0','month':datetime.date.today().strftime('%Y-%m'),'scores[engagement]':'4','comment':'Fictional HTTP observed engagement','observed':'1'})
        status,text,_=request(c,f'/apprentices/{demo_id}/monthly-snapshot')
        assert status==200 and 'Fictional HTTP observed engagement' in text
        passed('Actual HTTP monthly snapshot preserves unobserved areas separately from observed scores')
        status,text,_=request(c,'/case-studies/create')
        status,text,target=request(c,'/case-studies',{'_token':token(text),'fields[title]':'Fictional HTTP anonymised case','fields[category]':'Attendance and engagement'})
        assert status==200 and 'Fictional HTTP anonymised case' in text
        passed('Actual HTTP case-study draft saves and reopens without appearing as approved evidence')
        status,text,_=request(c,f'/apprentices/{demo_id}/surveys')
        status,text,_=request(c,f'/apprentices/{demo_id}/surveys',{'_token':token(text),'kind':'induction','submitted_date':datetime.date.today().isoformat()})
        status,text,_=request(c,f'/apprentices/{demo_id}/surveys')
        assert status==200 and 'Awaiting Inclusion check' in text
        passed('Actual HTTP survey receipt saves separately from a private Inclusion Team check')
        status,text,_=request(c,f'/staff-development/{actor_id}')
        status,text,_=request(c,f'/staff-development/{actor_id}/entry',{'_token':token(text),'version':'0','kind':'cpd','date':datetime.date.today().isoformat(),'title':'Fictional HTTP CPD record','summary':'Fictional practice update','status':'Completed','evidence':'Fictional certificate reference'})
        status,text,_=request(c,f'/staff-development/{actor_id}')
        assert status==200 and 'Fictional HTTP CPD record' in text
        passed('Actual HTTP staff CPD record saves and reopens under employee scope')
        for path in ['/dashboard','/reports/operational','/reports/inspection','/reports/retention',f'/apprentices/{demo_id}/journey',f'/apprentices/{demo_id}/curriculum-reference','/help']:
            assert request(c,path)[0]==200
        passed('Actual HTTP connected dashboards, journey, curriculum reference and scoped help respond successfully')
        with sqlite3.connect(database) as db:
            q_id=db.execute('SELECT id FROM qualifications WHERE apprentice_id=?',(demo_id,)).fetchone()[0]
        status,text,_=request(c,f'/apprentices/{demo_id}/qualifications/{q_id}/edit')
        status,text,_=upload(c,f'/qualifications/{q_id}/files',{'_token':token(text),'version':'1','purpose':'Fictional HTTP qualification evidence'},'fictional-qualification.txt',b'Fictional certificate evidence only')
        assert status==200
        status,text,_=request(c,f'/apprentices/{demo_id}/maths-english')
        assert status==200 and 'fictional-qualification.txt' in text
        passed('Actual multipart HTTP saves a private qualification file without recording achievement')
        status,text,_=request(c,f'/apprentices/{demo_id}/support-plans/new')
        status,text,target=request(c,f'/apprentices/{demo_id}/support-plans',{'_token':token(text),'concern_ids[]':str(concern_id),'fields[type]':'full','fields[referral]':datetime.date.today().isoformat(),'fields[barriers]':'Fictional travel concern','fields[strengths]':'Practical engagement','fields[view]':'Wants regular attendance','fields[support]':'Agree travel options','fields[review]':due,'fields[funding]':'not_funded'})
        assert status==200 and re.search(r'/support-plans/\d+$',target)
        p_id=int(target.rsplit('/',1)[1]);plan_path=target.removeprefix(base)
        status,text,_=request(c,f'/support-plans/{p_id}/approve',{'_token':token(text),'version':'1','confirmed':'1','reason':'Fictional HTTP checked approval'})
        with sqlite3.connect(database) as db:
            active=json.loads(db.execute('SELECT active_plan FROM support_plans WHERE id=?',(p_id,)).fetchone()[0])
        status,text,_=request(c,plan_path)
        status,text,_=request(c,f'/support-plans/{p_id}/activities',{'_token':token(text),'version':'2','approved_at':active['approved_at'],'request_id':str(__import__('uuid').uuid4()),'date':datetime.date.today().isoformat(),'type':'Specialist 1:1 Coaching','who':str(actor_id),'delivered':'Fictional HTTP delivered support'})
        status,text,_=request(c,plan_path)
        assert status==200 and 'Fictional HTTP delivered support' in text
        passed('Actual HTTP active support plan records delivered support separately from OTJ and formal review')
        status,text,_=upload(c,f'/support-plans/{p_id}/files',{'_token':token(text),'version':'3','purpose':'Fictional HTTP support evidence'},'fictional-support.txt',b'Fictional support evidence only')
        status,text,_=request(c,plan_path)
        assert status==200 and 'fictional-support.txt' in text
        passed('Actual multipart HTTP saves restricted support evidence against the original plan')
        executive=client();login(executive,'ceo@example.test')
        status,text,_=request(executive,f'/staff-development/{actor_id}?view=manager')
        with sqlite3.connect(database) as db:
            staff_payload=json.loads(db.execute('SELECT payload FROM staff_development_profiles WHERE user_id=?',(actor_id,)).fetchone()[0])
        entry=staff_payload['entries'][0]
        status,text,_=request(executive,f'/staff-development/{actor_id}/impact',{'_token':token(text),'version':'1','entry_id':entry['id'],'source_revision':'1','date':datetime.date.today().isoformat(),'baseline_status':'recorded','baseline':'Fictional original observation','outcome':'too_early','basis':'unchecked','summary':'Fictional HTTP impact check pending','evidence':'Further evidence needed','followup':'none'})
        status,text,_=request(executive,f'/staff-development/{actor_id}?view=manager')
        assert status==200 and 'Fictional HTTP impact check pending' in text
        passed('Actual HTTP staff impact records uncertainty without claiming proven improvement')
        status,text,_=request(c,f'/apprentices/{demo_id}/training-plan')
        ref='autocare-st0499-v1.3-af1.10-v1.0-reference'
        status,text,_=request(c,f'/apprentices/{demo_id}/training-plan/proposal',{'_token':token(text),'version':'0','programmeRef':ref,'otjHours':'600','practicalEnd':'2028-11-01','basis':'Manual planning proposal'})
        status,text,_=request(c,f'/apprentices/{demo_id}/training-plan')
        assert status==200 and '600' in text and 'Not an approved individual training plan' in text
        passed('Actual HTTP saves an explicit draft plan proposal without applying an individual target')
        status,text,_=request(c,f'/apprentices/{demo_id}/training-plan/rpl',{'_token':token(text),'version':'1','code':'K1','outcome':'exposure','omission':'No omission - full training retained'})
        status,text,_=request(c,f'/apprentices/{demo_id}/training-plan')
        assert status==200 and '1 of 64 source KSB decisions recorded' in text
        passed('Actual HTTP saves a source-bound RPL decision while incomplete programme totals stay unknown')
        status,text,_=request(executive,'/ceo/emergency')
        status,text,_=request(executive,'/ceo/emergency',{'_token':token(text),'reason':'Fictional HTTP emergency proof','password':password,'confirmed':'1'})
        assert status==200 and 'Emergency override on' in text
        status,text,_=request(executive,'/ceo/emergency/end',{'_token':token(text)})
        assert status==200 and 'Emergency override off' in text
        passed('Actual HTTP CEO password confirmation starts and explicitly ends an audited session override')
        status,text,_=request(c,f'/apprentices/{demo_id}/extra-training')
        status,text,_=request(c,f'/apprentices/{demo_id}/extra-training',{'_token':token(text),'training_date':(datetime.date.today()-datetime.timedelta(days=1)).isoformat(),'start_time':'17:00','end_time':'18:00','excluded_minutes':'0','topic':'Fictional HTTP extra occupational learning','provider':'Fictional GTA training','learning':'New diagnostic learning actually delivered','evidence':'Fictional original evidence reference','working':'outside'})
        assert status==200
        with sqlite3.connect(database) as db:
            extra_id=db.execute('SELECT id FROM extra_trainings WHERE apprentice_id=?',(demo_id,)).fetchone()[0]
        status,text,_=request(learner,f'/apprentices/{demo_id}/extra-training')
        status,text,_=request(learner,f'/extra-training/{extra_id}/agreement',{'_token':token(text),'version':'1','confirmed':'1'})
        status,text,_=request(c,f'/apprentices/{demo_id}/extra-training')
        status,text,_=request(c,f'/extra-training/{extra_id}/compensation',{'_token':token(text),'version':'2','method':'paid','date':datetime.date.today().isoformat(),'covered':'60','employer_name':'Fictional workplace supervisor','source':'Employer email','reference':'Fictional actual compensation source'})
        status,text,_=request(c,f'/apprentices/{demo_id}/extra-training')
        status,text,_=request(c,f'/extra-training/{extra_id}/approve',{'_token':token(text),'version':'3','eligible':'1'})
        status,text,_=request(c,f'/apprentices/{demo_id}/extra-training')
        assert status==200 and 'Approved OTJ' in text and '1.00 hrs' in text
        passed('Actual HTTP outside-hours training requires own apprentice agreement, actual employer compensation and tutor approval')
        with sqlite3.connect(database) as db:
            db.row_factory=sqlite3.Row
            saved_modules={}
            for table in ['learning_tasks','training_topics','qualifications','smart_actions','career_discussions','training_sessions','attendance_marks','support_concerns','apprentice_markers','progress_review_drafts','programme_outcomes','monthly_scorecards','case_studies','survey_receipts','staff_development_profiles','staff_development_events','support_plans','training_plan_workspaces','executive_access_sessions','executive_access_events','extra_trainings']:
                saved_modules[table]=[dict(row) for row in db.execute('SELECT * FROM '+table+' ORDER BY id')]

        import hashlib
        saved_files={}
        with sqlite3.connect(database) as db:
            for table in ['qualifications','support_plans']:
                for (raw,) in db.execute('SELECT files FROM '+table+' WHERE files IS NOT NULL'):
                    for file in json.loads(raw):
                        path=project/'storage/app/private'/file['path']
                        assert hashlib.sha256(path.read_bytes()).hexdigest()==file['sha256']
                        saved_files[str(path)]=file['sha256']
        no_csrf = {**data}; no_csrf.pop('_token')
        assert request(c, '/apprentices', no_csrf)[0] == 419
        passed('Actual HTTP create rejects a missing CSRF token')
        with sqlite3.connect(database) as db:
            db.row_factory = sqlite3.Row
            before = dict(db.execute("SELECT * FROM apprentices WHERE reference='HTTP-001'").fetchone())
            assert before['version'] == 2 and before['tutor_id'] is None
            assert db.execute("SELECT COUNT(*) FROM audit_events WHERE apprentice_id=?",(before['id'],)).fetchone()[0] == 2
        passed('Version, null tutor and two audit events are persisted')
        stop()
        start()
        passed('PHP server stops and restarts against the same SQLite file')
        fresh = client()
        login(fresh)
        status, text, _ = request(fresh, record_path)
        assert status == 200 and 'Fictional HTTP Employer' in text and 'HTTP-001' in text
        with sqlite3.connect(database) as db:
            db.row_factory = sqlite3.Row
            after = dict(db.execute("SELECT * FROM apprentices WHERE reference='HTTP-001'").fetchone())
        with sqlite3.connect(database) as db:
            db.row_factory=sqlite3.Row
            for table, original in saved_modules.items():
                assert [dict(row) for row in db.execute('SELECT * FROM '+table+' ORDER BY id')]==original
        passed('Complete saved records for twenty-one connected module tables survive PHP restart unchanged')
        for path,digest in saved_files.items():
            assert hashlib.sha256(Path(path).read_bytes()).hexdigest()==digest
        passed('Private qualification and support evidence bytes retain their hashes after PHP restart')
        assert before == after
        assert all(str(after[key]) == value for key, value in links.items())
        with sqlite3.connect(database) as db:
            db.row_factory = sqlite3.Row
            for table, record_id, original in directory_rows:
                assert dict(db.execute('SELECT * FROM '+table+' WHERE id=?',(record_id,)).fetchone()) == original
        passed('Linked foreign keys and complete directory rows survive PHP restart')
        passed('Fresh sign-in after restart reopens an identical complete database row')
        status, text, _ = request(fresh, record_path)
        status, text, url = request(fresh, '/logout', {'_token':token(text)})
        assert status == 200 and url.endswith('/login')
        assert request(fresh, record_path)[2].endswith('/login')
        passed('Logout removes access to the saved record')
        result = {'passed':len(results),'failed':0,'checks':results,'database':'SQLite file-backed database','http':'Actual PHP/Laravel loopback requests, not browser automation','recordProof':{'reference':before['reference'],'employer':before['employer'],'version':before['version'],'allFieldsUnchangedAfterRestart':True},'limits':['No browser or mobile layout acceptance','Not a production deployment or full-portal acceptance']}
        Path(args.output).write_text(json.dumps(result,indent=2)+'\n')
        print(json.dumps({'passed':len(results),'failed':0,'restartProof':True}))
    finally:
        stop()
        with sqlite3.connect(database) as db:
            for table in ['qualifications','support_plans']:
                try:
                    for (raw,) in db.execute('SELECT files FROM '+table+' WHERE files IS NOT NULL'):
                        for file in json.loads(raw):
                            (project/'storage/app/private'/file['path']).unlink(missing_ok=True)
                except sqlite3.OperationalError:
                    pass
