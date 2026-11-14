"""Loopback HTTP persistence proof with a disposable SQLite database. No browser."""
from pathlib import Path
import argparse, datetime, base64, http.cookiejar, json, os, re, secrets, signal, socket, sqlite3, subprocess, tempfile, time
from urllib.request import build_opener, HTTPCookieProcessor, Request
from urllib.parse import urlencode
from urllib.error import HTTPError, URLError

parser = argparse.ArgumentParser()
parser.add_argument('--php', default='php')
parser.add_argument('--output', required=True)
parser.add_argument('--mysql-fixture',action='store_true')
args = parser.parse_args()
if args.mysql_fixture:
    from mysql_dbapi import Module as sqlite3
project = Path(__file__).resolve().parent.parent
results = []
server = None

def passed(name):
    results.append({'name': name, 'passed': True})

with tempfile.TemporaryDirectory(prefix='gta-laravel-http-') as temp:
    temp = Path(temp)
    database = temp/'fictional.sqlite'
    database.touch()
    test_storage = temp/'storage'
    for part in ['app/private','framework/sessions','framework/cache/data','framework/cache/locks','framework/views','logs']:
        (test_storage/part).mkdir(parents=True, exist_ok=True)
    env = os.environ.copy()
    env.update(APP_ENV='local', APP_DEBUG='false', APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(), DB_CONNECTION='sqlite', DB_DATABASE=str(database), CACHE_STORE='file', SESSION_DRIVER='file', MAIL_MAILER='array', QUEUE_CONNECTION='sync')
    if args.mysql_fixture:
        # The independent DB bridge validates the private marker before opening any connection.
        with sqlite3.connect(database):pass
        env.update(DB_CONNECTION='mysql',DB_HOST=os.environ['GTA_HTTP_MYSQL_HOST'],DB_PORT=os.environ['GTA_HTTP_MYSQL_PORT'],DB_DATABASE=os.environ['GTA_HTTP_MYSQL_DATABASE'],DB_USERNAME=os.environ['GTA_HTTP_MYSQL_USER'],DB_PASSWORD=os.environ['GTA_HTTP_MYSQL_PASSWORD'],DB_SOCKET='',DB_URL='')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    env['APP_URL'] = base
    env['LARAVEL_STORAGE_PATH'] = str(test_storage)
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
    def login(opener, email='admin@example.test', password_value=None):
        status, text, _ = request(opener, '/login')
        assert status == 200
        status, text, url = request(opener, '/login', {'_token':token(text), 'email':email, 'password':password_value or password})
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
        # Fictional Panel allocation and one deliberately reconstructed legacy placeholder.
        status, form, _ = request(c, '/directories/programmes/create')
        status, text, target = request(c, '/directories/programmes', {'_token':token(form),'standard_code':'ST0403','version':'1.3','name':'Fictional Panel source proof','level':'3','active':'1'})
        assert status == 200 and re.search(r'/directories/programmes/\d+$',target)
        panel_programme = target.rsplit('/',1)[1]
        with sqlite3.connect(database) as db:
            calendar_adviser=db.execute("SELECT id FROM users WHERE email='careers@example.test'").fetchone()[0]
            review_tutor=db.execute("SELECT id FROM users WHERE email='tutor@example.test'").fetchone()[0]
            review_mentor,review_mentor_name=db.execute("SELECT id,name FROM users WHERE email='mentor@example.test'").fetchone()
        status, form, _ = request(c, '/apprentices/create')
        status, text, target = request(c, '/apprentices', {**data,'_token':token(form),'reference':'HTTP-PANEL','programme_id':panel_programme,'start_date':(datetime.date.today()-datetime.timedelta(days=200)).isoformat(),'careers_adviser_id':str(calendar_adviser),'tutor_id':str(review_tutor),'mentor_id':str(review_mentor)})
        assert status == 200 and re.search(r'/apprentices/\d+$',target)
        panel_id = int(target.rsplit('/',1)[1]); panel_path=f'/apprentices/{panel_id}/learning'
        status, text, _ = request(c,panel_path)
        status, text, _ = request(c,panel_path,{'_token':token(text),'confirmed':'1'})
        with sqlite3.connect(database) as db:
            rows=db.execute("SELECT id,reference,template FROM learning_tasks WHERE apprentice_id=? AND reference LIKE '%-t5' ORDER BY block",(panel_id,)).fetchall()
            assert len(rows)==10 and all(json.loads(row[2])['kind']=='impact' for row in rows)
            reflection_id=rows[0][0]; old_hash=json.loads((project/'resources/data/panel-placeholder-hashes.json').read_text())[rows[0][1]]
            placeholder={'filename':'panel-block-1-reflection-source-required','kind':'source-pending','catalogueId':'panel','sourceBlock':1,'sourceTask':5,'fields':[],'instructions':'Source required','version':'pending','sha256':old_hash}
            db.execute("UPDATE learning_tasks SET template=?,status='Source required' WHERE id=?",(json.dumps(placeholder),reflection_id))
        passed('Actual HTTP Panel allocation assigns ten real source reflections')
        status,text,_=request(c,panel_path)
        assert 'Load reflection sources' in text
        assert request(c,panel_path+'/reflection-sources',{'confirmed':'1'})[0]==419
        passed('Actual HTTP reflection source loading rejects a missing CSRF token')
        status,text,_=request(c,panel_path+'/reflection-sources',{'_token':token(text),'confirmed':'1'})
        assert status==200 and '1 untouched reflection placeholders loaded' in text
        with sqlite3.connect(database) as db:
            upgraded=db.execute('SELECT template,status,version FROM learning_tasks WHERE id=?',(reflection_id,)).fetchone()
            assert json.loads(upgraded[0])['kind']=='impact' and upgraded[1:] == ('Draft',2)
            assert db.execute("SELECT COUNT(*) FROM learning_events WHERE record_id=? AND event='reflection_source_loaded'",(reflection_id,)).fetchone()[0]==1
        passed('Actual HTTP guarded source loading creates a versioned draft and audit event')
        status,text,_=request(c,panel_path+'/reflection-sources',{'_token':token(text),'confirmed':'1'})
        assert status==200 and '0 untouched reflection placeholders loaded' in text
        passed('Actual HTTP repeated reflection loading leaves the saved draft unchanged')
        delivery_path=f'/apprentices/{panel_id}/delivery-plan'
        status,text,_=request(c,delivery_path)
        assert status==200 and 'Save draft calendar' in text
        calendar={'version':'0'}
        first=datetime.date.today()-datetime.timedelta(days=200)
        for n in range(12):
            calendar[f'blocks[{n}][start]']=(first+datetime.timedelta(days=20*n)).isoformat()
            calendar[f'blocks[{n}][end]']=(first+datetime.timedelta(days=20*n+19)).isoformat()
            calendar[f'blocks[{n}][hours]']='0' if n==11 else '10'
        assert request(c,delivery_path+'/calendar',calendar)[0]==419
        passed('Actual HTTP calendar mutation rejects a missing CSRF token')
        status,text,_=request(c,delivery_path+'/calendar',{**calendar,'_token':token(text)})
        assert status==200 and 'Draft block calendar saved' in text
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT COUNT(*) FROM learning_tasks WHERE apprentice_id=? AND due_date IS NOT NULL',(panel_id,)).fetchone()[0]==0
        passed('Actual HTTP twelve-block calendar saves without assigning task deadlines')
        details={'version':'1','source_ref':'Fictional controlled HTTP delivery plan','ksb_ref':'Fictional checked primary mapping','qualifications_ref':'Fictional individual qualification requirements','release_ref':'Fictional employer release reference','target_hours':'110','basis':'Full training retained'}
        status,text,_=request(c,delivery_path+'/details',{**details,'_token':token(text)})
        assert status==200 and 'details saved for review' in text
        checks={'version':'2','source_checked':'1','hours_checked':'1','curriculum_checked':'1','release_checked':'1'}
        status,text,_=request(c,delivery_path+'/approve',{**checks,'_token':token(text)})
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT active FROM individual_delivery_plans WHERE apprentice_id=?',(panel_id,)).fetchone()[0]==0
        passed('Actual HTTP plan preparer cannot approve their own draft')
        independent=client();login(independent,'ceo@example.test')
        status,text,_=request(independent,delivery_path)
        status,text,_=request(independent,delivery_path+'/approve',{**checks,'_token':token(text)})
        assert status==200 and 'Provider delivery plan approved' in text
        with sqlite3.connect(database) as db:
            approved=json.loads(db.execute('SELECT approved FROM individual_delivery_plans WHERE apprentice_id=?',(panel_id,)).fetchone()[0]);assert approved['details']['target_hours']=='110'
        passed('Actual HTTP independent reviewer approves the exact controlled plan revision')
        status,text,_=request(c,delivery_path)
        apply={'version':'3','approval_hash':re.search(r'name="approval_hash" value="([^"]+)"',text)[1],'task_binding':re.search(r'name="task_binding" value="([^"]+)"',text)[1],'confirmed':'1','_token':token(text)}
        status,text,_=request(c,delivery_path+'/apply',apply)
        assert status==200 and 'deadlines filled' in text
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT COUNT(*) FROM learning_tasks WHERE apprentice_id=? AND due_date IS NOT NULL',(panel_id,)).fetchone()[0]==50
        passed('Actual HTTP confirmed plan preview applies fifty unset deadlines with saved versions')
        with c.open(base+delivery_path+'/approved.pdf',timeout=30) as response:
            assert response.status==200 and response.read().startswith(b'%PDF-')
        passed('Actual HTTP current approved delivery plan exports a protected PDF')
        status,text,_=request(c,f'/apprentices/{panel_id}/reviews/new')
        fields={'reviewDate':(datetime.date.today()-datetime.timedelta(days=80)).isoformat(),'trainingFrom':first.isoformat(),'mentor':review_mentor_name,'tutor':'Fictional tutor','employerRep':'Fictional employer','method':'Face to face','attendance':'Present','judgement':'On track','previousCheck':'First review — no previous review actions','planChange':'No','summary':'Fictional recorded review discussion','learning':'Actual training discussed','progressDiscussion':'Evidence checked','previousNotes':'N/A','planChangeNotes':'N/A','sharedSupport':'N/A','absence':'N/A','newNeeds':'No','trainingChecked':'1','targetsAgreed':'1','providerPrepared':'1'}
        status,text,target=request(c,f'/apprentices/{panel_id}/reviews',{'_token':token(text),**{f'fields[{k}]':v for k,v in fields.items()}})
        assert status==200
        panel_review_path=target.removeprefix(base)
        with sqlite3.connect(database) as db:
            snap=json.loads(db.execute('SELECT source_snapshot FROM progress_review_drafts WHERE apprentice_id=?',(panel_id,)).fetchone()[0]);assert snap['delivery_plan']['hash']==approved['hash']
        passed('Actual HTTP review saves the exact approved plan and next-period block snapshot')
        assert request(c,panel_review_path+'/ready',{'version':'1','confirmed':'1'})[0]==419
        status,text,_=request(c,panel_review_path+'/ready',{'_token':token(text),'version':'1','confirmed':'1'})
        assert status==200 and 'frozen and ready' in text
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT state,version FROM progress_review_drafts WHERE apprentice_id=?',(panel_id,)).fetchone()==('Ready for signatures',2)
            assert db.execute("SELECT COUNT(*) FROM learning_events WHERE apprentice_id=? AND event='review_completed'",(panel_id,)).fetchone()[0]==0
        passed('Actual HTTP review readiness freezes its revision without claiming completion or resetting the cycle')
        status,text,_=request(c,f'/apprentices/{panel_id}/otj-ledger')
        assert status==200 and '110.00' in text and 'Historical completeness is not verified' in text
        passed('Actual HTTP OTJ ledger links the approved target without claiming a complete historical record')
        with c.open(base+f'/apprentices/{panel_id}/otj-ledger/report.pdf',timeout=30) as response:
            assert response.status==200 and response.read().startswith(b'%PDF-')
        passed('Actual HTTP scoped OTJ ledger exports a real source summary PDF')
        status,text,_=request(c,'/careers')
        assert 'HTTP-PANEL' in text and 'Approved-calendar Careers checks' in text
        assert request(c,'/careers/prompts',{})[0]==419
        status,text,_=request(c,'/careers/prompts',{'_token':token(text)})
        assert status==200 and '2 new in-portal Careers prompts recorded' in text
        with sqlite3.connect(database) as db:
            prompt_id=db.execute('SELECT id FROM careers_prompts WHERE apprentice_id=? ORDER BY block',(panel_id,)).fetchone()[0]
            assert db.execute('SELECT COUNT(*) FROM career_discussions').fetchone()[0]==0
        passed('Actual HTTP administrator records due Block 10/11 Careers prompts with CSRF protection')
        adviser=client();login(adviser,'careers@example.test')
        status,text,_=request(adviser,'/careers')
        status,text,_=request(adviser,f'/careers/prompts/{prompt_id}/seen',{'_token':token(text)})
        assert status==200
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT seen_at FROM careers_prompts WHERE id=?',(prompt_id,)).fetchone()[0]
            assert db.execute('SELECT COUNT(*) FROM career_discussions').fetchone()[0]==0
        passed('Actual HTTP assigned adviser acknowledges a current prompt without recording delivered advice')
        # Fictional signed-copy fixture; workflow checks do not verify real signature authenticity.
        panel_review_id=int(panel_review_path.rsplit('/',1)[1])
        with c.open(base+panel_review_path+'/draft.pdf',timeout=30) as response:
            signed_fixture=response.read();assert signed_fixture.startswith(b'%PDF-')
        signed_path=f'/review-signed-copies/{panel_review_id}'
        status,text,_=request(c,signed_path)
        assert status==200 and 'Record the signed source' in text
        assert request(c,signed_path,{'version':'2'})[0]==419
        with sqlite3.connect(database) as db:
            panel_name=' '.join(db.execute('SELECT first_name,last_name FROM apprentices WHERE id=?',(panel_id,)).fetchone())
            review_fields=json.loads(db.execute('SELECT fields FROM progress_review_drafts WHERE id=?',(panel_review_id,)).fetchone()[0])
        signed_fields={'_token':token(text),'version':'2','source_reference':'Fictional externally signed copy source','identity_reference':'Fictional authorised participant references','copy_checked':'1','apprentice_name':panel_name,'apprentice_date':datetime.date.today().isoformat(),'employer_name':review_fields['employerRep'],'employer_date':datetime.date.today().isoformat(),'mentor_name':review_fields['mentor'],'mentor_date':datetime.date.today().isoformat()}
        status,text,_=upload(c,signed_path,signed_fields,'fictional-review-copy.pdf',signed_fixture)
        assert status==200 and 'Awaiting independent check' in text
        with sqlite3.connect(database) as db:
            signed_record_id=db.execute('SELECT id FROM signed_review_records WHERE progress_review_draft_id=?',(panel_review_id,)).fetchone()[0]
            assert db.execute('SELECT checked_at FROM signed_review_records WHERE id=?',(signed_record_id,)).fetchone()[0] is None
        passed('Actual multipart signed-copy upload enforces CSRF and remains pending without moving a review date')
        confirmations={'version':'1','content_checked':'1','identity_checked':'1','signatures_checked':'1'}
        status,text,_=request(c,f'/signed-review-records/{signed_record_id}/confirm',{**confirmations,'_token':token(text)})
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT checked_at FROM signed_review_records WHERE id=?',(signed_record_id,)).fetchone()[0] is None
        passed('Actual signed-copy confirmation rejects the uploading administrator')
        status,text,_=request(independent,signed_path)
        status,text,_=request(independent,f'/signed-review-records/{signed_record_id}/confirm',{**confirmations,'_token':token(text)})
        assert status==200 and 'Signed copy independently checked' in text
        status,text,_=request(c,'/reviews')
        assert status==200 and (datetime.date.today()-datetime.timedelta(days=10)).isoformat() in text
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT version FROM progress_review_drafts WHERE id=?',(panel_review_id,)).fetchone()[0]==2
        with c.open(base+f'/signed-review-records/{signed_record_id}/source.pdf',timeout=30) as response:
            assert response.read()==signed_fixture
        passed('Actual independent signed-copy check tracks meeting plus seventy days and retains the exact private PDF')
        status,text,_=request(c,f'/apprentices/{panel_id}/outcome')
        status,text,_=request(c,f'/apprentices/{panel_id}/outcome',{'_token':token(text),'version':'0','context[status]':'Active','context[sourceRef]':'Fictional HTTP active enrolment source'})
        assert status==200
        status,text,_=request(c,'/reviews')
        # Review reminder sources must be checked and due; upload time cannot extend them.
        assert 'In-portal review reminders' in text and 'Record current due reminders' in text
        assert request(c,'/reviews/reminders',{})[0]==419
        status,text,_=request(c,'/reviews/reminders',{'_token':token(text)})
        assert status==200 and '2 new in-portal review reminders recorded' in text
        status,text,_=request(c,'/reviews/reminders',{'_token':token(text)})
        assert status==200 and '0 new in-portal review reminders recorded' in text
        with sqlite3.connect(database) as db:
            tutor_reminder=db.execute('SELECT id FROM review_reminders WHERE apprentice_id=? AND recipient_id=?',(panel_id,review_tutor)).fetchone()[0]
            mentor_reminder=db.execute('SELECT id FROM review_reminders WHERE apprentice_id=? AND recipient_id=?',(panel_id,review_mentor)).fetchone()[0]
            assert db.execute('SELECT COUNT(*) FROM review_reminders').fetchone()[0]==2
            frozen_review_before_reminders=db.execute('SELECT fields,source_snapshot,version FROM progress_review_drafts WHERE id=?',(panel_review_id,)).fetchone()
        passed('Actual HTTP review reminders enforce CSRF and record one source per current tutor and mentor without duplicates')
        review_staff=client();login(review_staff,'tutor@example.test')
        status,text,_=request(review_staff,'/reviews')
        assert 'HTTP-PANEL' in text and 'Mark review reminder seen' in text and 'Record current due reminders' not in text
        assert request(review_staff,'/reviews/reminders',{'_token':token(text)})[0]==403
        assert request(review_staff,f'/reviews/reminders/{mentor_reminder}/seen',{'_token':token(text)})[0]==403
        assert request(review_staff,f'/reviews/reminders/{tutor_reminder}/seen',{})[0]==419
        status,text,_=request(review_staff,f'/reviews/reminders/{tutor_reminder}/seen',{'_token':token(text)})
        assert status==200 and 'marked seen' in text and 'review remains due' in text
        status,text,_=request(review_staff,f'/reviews/reminders/{tutor_reminder}/seen',{'_token':token(text)})
        assert status==200
        mentor_client=client();login(mentor_client,'mentor@example.test')
        status,text,_=request(mentor_client,'/reviews')
        status,text,_=request(mentor_client,f'/reviews/reminders/{mentor_reminder}/seen',{'_token':token(text)})
        assert status==200 and 'marked seen' in text
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT COUNT(*) FROM review_reminder_events').fetchone()[0]==4
            assert db.execute('SELECT fields,source_snapshot,version FROM progress_review_drafts WHERE id=?',(panel_review_id,)).fetchone()==frozen_review_before_reminders
        passed('Actual tutor and mentor acknowledgement is scoped, idempotent and leaves frozen review content and overdue date unchanged')
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
            support_tutor_id=db.execute("SELECT id FROM users WHERE email='tutor@example.test'").fetchone()[0]
        status,text,_=request(c,f'/staff-support-permissions?person={support_tutor_id}')
        assert status==200 and 'Inclusion Team duty' in text
        status,text,_=request(c,f'/staff-support-permissions/{support_tutor_id}',{'_token':token(text),'version':'0','duty':'member','scope':'all','reason':'Fictional HTTP temporary support cover'})
        assert status==200 and 'Support duty and scope saved' in text
        cover=client();login(cover,'tutor@example.test')
        assert request(cover,'/support')[0]==200
        passed('Actual HTTP administrator grant enables a separate scoped support duty for a tutor')
        assert request(cover,'/staff-support-permissions')[0]==403
        assert request(cover,f'/apprentices/{demo_id}/careers')[0]==403
        passed('Support duty does not grant permission administration or private Careers access')
        status,text,_=request(c,f'/staff-support-permissions/{support_tutor_id}',{'_token':token(text),'version':'1','duty':'staff','scope':'assigned','reason':'Fictional HTTP cover finished'})
        assert status==200 and request(cover,'/support')[0]==403
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT duty,version FROM support_capabilities WHERE user_id=?',(support_tutor_id,)).fetchone()==('staff',2)
            assert db.execute('SELECT COUNT(*) FROM support_permission_events WHERE user_id=?',(support_tutor_id,)).fetchone()[0]==2
        passed('Actual HTTP revocation removes private team access and retains both reasoned audit events')
        status,text,_=request(c,session_path)
        status,text,_=request(c,session_path,{'_token':token(text),'version':'2','delivered_learning':'Corrected fictional actual learning','correction_reason':'Fictional register departure correction',f'marks[{demo_id}][code]':'O',f'marks[{demo_id}][arrival]':'09:00',f'marks[{demo_id}][departure]':'15:00',f'marks[{demo_id}][otj_confirmed]':'1'})
        assert status==200 and '5.00 hours' in text and 'Fictional register departure correction' in text
        passed('Actual HTTP register correction retains a reason and replaces the confirmed subtotal')
        status,text,_=request(c,'/sessions')
        status,text,_=request(c,'/sessions',{'_token':token(text),'tutor_group_id':str(group_id),'name':'HTTP repeated future plan','session_date':'2026-12-01','start_time':'09:00','end_time':'16:00','planned_learning':'Fictional future subject plan','breaks_checked':'1','repeat_weeks':'2'})
        assert status==200
        with sqlite3.connect(database) as db:
            assert db.execute("SELECT COUNT(*) FROM training_sessions WHERE name='HTTP repeated future plan'").fetchone()[0]==2
            assert db.execute("SELECT COUNT(*) FROM attendance_marks WHERE training_session_id IN (SELECT id FROM training_sessions WHERE name='HTTP repeated future plan')").fetchone()[0]==0
        passed('Actual HTTP repeated plans create separate registers without actual attendance or hours')
        status,text,_=request(c,f'/apprentices/{demo_id}/learning')
        status,text,_=request(c,f'/apprentices/{demo_id}/block-controls',{'_token':token(text),'version':'1','enabled':'1','operation':'release','block':'2','reason':'Fictional HTTP sequence exception'})
        assert status==200 and 'Fictional HTTP sequence exception' in text
        status,text,_=request(c,f'/apprentices/{demo_id}/support')
        assert 'Marker change history' in text
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT COUNT(*) FROM marker_change_events WHERE apprentice_id=?',(demo_id,)).fetchone()[0]>=1
            assert db.execute('SELECT COUNT(*) FROM assignment_change_events').fetchone()[0]>=1
        passed('Block exceptions, marker history and minimal assignment events are persisted through actual HTTP workflows')
        for pdf_path in [f'/tasks/{task_id}/recorded-work.pdf','/reports/operational.pdf']:
            with c.open(base+pdf_path,timeout=30) as response:
                assert response.headers.get_content_type()=='application/pdf'
                assert response.read().startswith(b'%PDF-')
        with sqlite3.connect(database) as db:
            review_id=db.execute('SELECT id FROM progress_review_drafts WHERE apprentice_id=?',(demo_id,)).fetchone()[0]
        with c.open(base+f'/apprentices/{demo_id}/reviews/{review_id}/draft.pdf',timeout=30) as response:
            assert response.headers.get_content_type()=='application/pdf'
            assert response.read().startswith(b'%PDF-')
        passed('Actual HTTP recorded-work, scoped operational and protected review-draft downloads return real PDF bytes')
        # A dated fictional source fixture is needed to exercise the seven-day threshold.
        # It is inserted only into this disposable test database, not through a backdating feature.
        old_date = datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0) - datetime.timedelta(days=8)
        with sqlite3.connect(database) as db:
            template = db.execute('SELECT template FROM learning_tasks WHERE id=?', (task_id,)).fetchone()[0]
            submission = [{'version':1, 'submitted_at':old_date.isoformat(), 'template_hash':json.loads(template)['sha256'], 'answers':{}, 'decision':None}]
            cursor = db.execute('INSERT INTO learning_tasks (apprentice_id,reference,block,title,theme,template,answers,submissions,status,submitted_at,version,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', (demo_id,'http-reminder-proof',1,'Fictional HTTP marking reminder','Fictional dated source',template,'{}',json.dumps(submission),'Awaiting marking',old_date.strftime('%Y-%m-%d %H:%M:%S'),1,old_date.strftime('%Y-%m-%d %H:%M:%S'),old_date.strftime('%Y-%m-%d %H:%M:%S')))
            reminder_task_id = cursor.lastrowid
        status, text, _ = request(c, '/marking')
        assert status == 200 and 'Fictional HTTP marking reminder' in text
        passed('Actual HTTP marking worklist shows a dated fictional overdue source')
        assert request(c, '/marking/reminders', {})[0] == 419
        passed('Actual HTTP reminder creation rejects a missing CSRF token')
        status, text, _ = request(c, '/marking')
        status, text, _ = request(c, '/marking/reminders', {'_token':token(text)})
        assert status == 200 and '1 new in-portal reminders recorded' in text
        status, text, _ = request(c, '/marking/reminders', {'_token':token(text)})
        assert status == 200 and '0 new in-portal reminders recorded' in text
        with sqlite3.connect(database) as db:
            reminder_id = db.execute('SELECT id FROM marking_reminders WHERE learning_task_id=?',(reminder_task_id,)).fetchone()[0]
            assert db.execute('SELECT COUNT(*) FROM marking_reminders').fetchone()[0] == 1
        passed('Actual HTTP administrator creates one deduplicated in-portal reminder')
        with sqlite3.connect(database) as db:
            correction_id,raw,correction_version=db.execute("SELECT id,template,version FROM learning_tasks WHERE apprentice_id=? AND reference='b1-t2'",(demo_id,)).fetchone()
            correction_name=next(f['name'] for f in json.loads(raw)['fields'] if f['role']=='apprentice' and f['key'] in ['apprentice_name','sign_apprentice_name','apprentice_signoff'])
        status,text,_=request(c,f'/tasks/{correction_id}')
        status,text,_=request(c,f'/tasks/{correction_id}/deadline',{'_token':token(text),'version':str(correction_version),'due_date':(datetime.date.today()+datetime.timedelta(days=14)).isoformat()})
        status,text,_=request(learner,f'/tasks/{correction_id}')
        status,text,_=request(learner,f'/tasks/{correction_id}',{'_token':token(text),'version':str(correction_version+1),'intent':'submit','own_work':'1',f'answers[{correction_name}]':'Fictional correction apprentice'})
        assert status==200 and 'Submitted to the tutor' in text
        status,text,_=request(c,f'/tasks/{correction_id}')
        status,text,_=request(c,f'/tasks/{correction_id}/decision',{'_token':token(text),'version':str(correction_version+2),'decision':'Referred','decision_date':datetime.date.today().isoformat(),'confirmed':'1','feedback':'Fictional correction: record the actual measured value.'})
        assert status==200
        with sqlite3.connect(database) as db:
            action_before=db.execute('SELECT COUNT(*) FROM smart_actions').fetchone()[0]
        status,text,_=request(c,f'/apprentices/{demo_id}/actions/proposals')
        assert status==200 and 'Prepare correction action' in text
        status,text,_=request(c,f'/apprentices/{demo_id}/actions/create?source_task_id={correction_id}')
        binding=re.search(r'name="source_binding" value="([^"]+)"',text)[1]
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT COUNT(*) FROM smart_actions').fetchone()[0]==action_before
        passed('Actual HTTP correction proposal opens from tutor feedback without creating an action')
        proposal={'_token':token(text),'source_task_id':str(correction_id),'source_binding':binding,'title':'Fictional agreed correction action','owner':'Apprentice','method':'linked-task','learning_task_id':str(correction_id),'evidence_method':'portal','relevance':'correction','learning_reference':'b1-t2','support':'Tutor support available','achievable':'1','agreed':'1'}
        status,text,_=request(c,f'/apprentices/{demo_id}/actions',proposal)
        assert status==200 and 'SMART action saved' in text
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT COUNT(*) FROM smart_actions WHERE learning_task_id=?',(correction_id,)).fetchone()[0]==1
            assert db.execute('SELECT status FROM learning_tasks WHERE id=?',(correction_id,)).fetchone()[0]=='Referred'
        passed('Actual HTTP agreed source-bound proposal creates one linked action without approving the task')
        marking_tutor=client();login(marking_tutor,'tutor@example.test')
        status, text, _ = request(marking_tutor, '/marking')
        assert status == 200 and 'Mark reminder seen' in text
        status, text, _ = request(marking_tutor, f'/marking/reminders/{reminder_id}/seen', {'_token':token(text)})
        assert status == 200 and 'Reminder marked seen' in text
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT seen_at FROM marking_reminders WHERE id=?',(reminder_id,)).fetchone()[0]
            assert db.execute('SELECT status FROM learning_tasks WHERE id=?',(reminder_task_id,)).fetchone()[0] == 'Awaiting marking'
        passed('Actual HTTP tutor acknowledgement retains the outstanding task without approving it')
        assert request(learner, '/marking')[0] == 403
        passed('Actual HTTP apprentice cannot access the staff marking worklist')
        recovery_client=client()
        status, text, _=request(recovery_client, '/forgot-password')
        assert status==200 and 'not enabled' in text
        for address in ['admin@example.test','absent@example.test']:
            status, text, _=request(recovery_client, '/login')
            status, text, _=request(recovery_client, '/forgot-password', {'_token':token(text), 'email':address})
            assert status==200 and 'If the account is eligible and recovery is enabled' in text
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT COUNT(*) FROM password_reset_tokens').fetchone()[0]==0
        passed('Actual HTTP disabled recovery returns generic responses without creating a token')
        older_tutor=client();login(older_tutor,'tutor@example.test')
        new_tutor_password='HTTP-Fictional-New-Passphrase-13!'
        status, text, _=request(marking_tutor, '/account/password')
        assert status==200 and 'Change your password' in text
        change={'current_password':password,'password':new_tutor_password,'password_confirmation':new_tutor_password}
        assert request(marking_tutor,'/account/password',change)[0]==419
        passed('Actual HTTP password change rejects a missing CSRF token')
        status, text, _=request(marking_tutor, '/account/password')
        status, text, changed_url=request(marking_tutor, '/account/password', {'_token':token(text), **change})
        assert status==200 and changed_url.endswith('/login') and 'Password changed' in text
        passed('Actual HTTP current-password change signs out the changing session')
        assert request(older_tutor,'/marking')[2].endswith('/login')
        passed('Actual HTTP password change invalidates an already signed-in second browser session')
        new_tutor=client();login(new_tutor,'tutor@example.test',new_tutor_password)
        assert request(new_tutor,'/account/password')[0]==200
        with sqlite3.connect(database) as db:
            tutor_security_state=db.execute("SELECT auth_version,password,remember_token FROM users WHERE email='tutor@example.test'").fetchone()
            assert tutor_security_state[0]==2
        passed('Actual HTTP new password signs in with the current account security version')
        with sqlite3.connect(database) as db:
            prompt_count=db.execute('SELECT COUNT(*) FROM careers_prompts').fetchone()[0]
        preview=json.loads(artisan('gta:portal-reminders').strip())
        assert preview['mode']=='preview' and preview['external_messages_sent'] is False
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT COUNT(*) FROM portal_reminder_runs').fetchone()[0]==0
        recorded=json.loads(artisan('gta:portal-reminders','--record').strip())
        assert recorded['mode']=='record' and recorded['external_messages_sent'] is False
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT COUNT(*) FROM careers_prompts').fetchone()[0]==prompt_count
            assert db.execute('SELECT COUNT(*) FROM portal_reminder_runs').fetchone()[0]==1
        passed('Actual command preview is read-only and explicit combined recording deduplicates existing in-portal prompts')
        # Reviewed qualification/OTJ imports and immutable operational packs through real forms.
        import csv, io
        def csv_bytes(headers,rows):
            stream=io.StringIO();writer=csv.writer(stream);writer.writerow(headers);writer.writerows(rows);return stream.getvalue().encode()
        with sqlite3.connect(database) as db:
            qualification_version=db.execute('SELECT version FROM qualifications WHERE id=?',(q_id,)).fetchone()[0]
            qualification_start_before_import=db.execute('SELECT start_date FROM qualifications WHERE id=?',(q_id,)).fetchone()[0]
            topics_before_import=db.execute('SELECT id,status,completion_date FROM training_topics ORDER BY id').fetchall()
        status,text,_=request(c,'/data-imports/qualification-results/create')
        result_csv=csv_bytes(['qualification_id','version','status','result','achievement_date','evidence_reference'],[[q_id,qualification_version,'Achieved','Achieved',(datetime.date.today()-datetime.timedelta(days=1)).isoformat(),'Fictional awarding result source']])
        status,text,target=upload(c,'/data-imports/qualification-results',{'_token':token(text),'source_reference':'Fictional controlled result statement'},'results.csv',result_csv)
        assert status==200 and '/data-imports/batches/' in target
        result_import_path=target.removeprefix(base)
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT status FROM qualifications WHERE id=?',(q_id,)).fetchone()[0]=='Working towards'
        passed('Actual multipart result import saves a read-only exact-row preview')
        binding=re.search(r'name="preview_hash" value="([^"]+)"',text)[1]
        apply={'version':'1','preview_hash':binding,'source_checked':'1','results_checked':'1'}
        assert request(c,result_import_path+'/apply',apply)[0]==419
        status,text,_=request(c,result_import_path+'/apply',{**apply,'_token':token(text)})
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT status FROM qualifications WHERE id=?',(q_id,)).fetchone()[0]=='Working towards'
        passed('Actual result application enforces CSRF and rejects the batch preparer')
        checker=client();login(checker,'ceo@example.test')
        status,text,_=request(checker,result_import_path)
        status,text,_=request(checker,result_import_path+'/apply',{**apply,'_token':token(text)})
        assert status==200
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT status,result,start_date FROM qualifications WHERE id=?',(q_id,)).fetchone()==('Achieved','Achieved',qualification_start_before_import)
            assert db.execute('SELECT id,status,completion_date FROM training_topics ORDER BY id').fetchall()==topics_before_import
        passed('Actual independent result check applies the result while preserving topic status and custom start')
        status,text,_=request(c,'/data-imports/historical-otj/create')
        historical_csv=csv_bytes(['apprentice_id','training_date','start_time','end_time','excluded_minutes','topic','evidence_reference'],[[panel_id,(datetime.date.today()-datetime.timedelta(days=40)).isoformat(),'17:00','19:00','30','Fictional historic occupational learning','Fictional checked historic evidence']])
        status,text,target=upload(c,'/data-imports/historical-otj',{'_token':token(text),'source_reference':'Fictional historic source ledger'},'historical.csv',historical_csv)
        assert status==200 and '/data-imports/batches/' in target
        historical_import_path=target.removeprefix(base)
        binding=re.search(r'name="preview_hash" value="([^"]+)"',text)[1]
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT COUNT(*) FROM historical_otj_records').fetchone()[0]==0
        passed('Actual historical upload previews net minutes without adding hours or attendance')
        status,text,_=request(checker,historical_import_path)
        status,text,_=request(checker,historical_import_path+'/apply',{'_token':token(text),'version':'1','preview_hash':binding,'source_checked':'1','occupational_checked':'1','duplicate_checked':'1'})
        assert status==200
        with sqlite3.connect(database) as db:
            historical_id=db.execute('SELECT id FROM historical_otj_records WHERE apprentice_id=?',(panel_id,)).fetchone()[0]
            assert db.execute('SELECT minutes FROM historical_otj_records WHERE id=?',(historical_id,)).fetchone()[0]==90
            assert db.execute('SELECT COUNT(*) FROM attendance_marks WHERE apprentice_id=?',(panel_id,)).fetchone()[0]==0
        status,text,_=request(c,f'/apprentices/{panel_id}/otj-ledger')
        assert status==200 and '1.50' in text and 'Independently reviewed import' in text
        passed('Actual independent historic check adds ninety source minutes to the ledger without inventing attendance')
        status,text,_=request(c,'/reports/management-packs')
        assert status==200 and 'Checks to follow up' in text
        assert request(c,'/reports/management-packs',{'title':'Fictional fixed pack','confirmed':'1'})[0]==419
        status,text,target=request(c,'/reports/management-packs',{'_token':token(text),'title':'Fictional fixed operational pack','confirmed':'1'})
        assert status==200 and re.search(r'/reports/management-packs/\d+$',target)
        pack_path=target.removeprefix(base);pack_id=int(target.rsplit('/',1)[1])
        with sqlite3.connect(database) as db:
            captured_pack=db.execute('SELECT snapshot FROM management_packs WHERE id=?',(pack_id,)).fetchone()[0]
            people=json.loads(captured_pack)['apprentices'];panel_capture=next(x for x in people if x['id']==panel_id)
            assert panel_capture['otj']['historical_minutes']==90
        with c.open(base+pack_path+'/report.pdf',timeout=30) as response:
            assert response.status==200 and response.read().startswith(b'%PDF-')
        passed('Actual management capture saves a scoped fixed position and exports its protected PDF')
        status,text,_=request(c,f'/apprentices/{panel_id}/otj-ledger')
        status,text,_=request(c,f'/historical-otj/{historical_id}/withdraw',{'_token':token(text),'version':'1','reason':'Fictional source correction discovered'})
        assert status==200
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT withdrawn_at FROM historical_otj_records WHERE id=?',(historical_id,)).fetchone()[0]
            assert db.execute('SELECT snapshot FROM management_packs WHERE id=?',(pack_id,)).fetchone()[0]==captured_pack
        passed('Actual historical withdrawal preserves source history and the earlier captured management pack')
        status,text,_=request(c,signed_path)
        status,text,_=request(c,f'/signed-review-records/{signed_record_id}/withdraw',{'_token':token(text),'version':'2','reason':'Fictional source withdrawal for audit proof'})
        assert status==200 and 'Withdrawn' in text
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT withdrawn_at FROM signed_review_records WHERE id=?',(signed_record_id,)).fetchone()[0]
            assert db.execute('SELECT snapshot FROM management_packs WHERE id=?',(pack_id,)).fetchone()[0]==captured_pack
        with c.open(base+f'/signed-review-records/{signed_record_id}/source.pdf',timeout=30) as response:
            assert response.read()==signed_fixture
        passed('Actual signed-copy withdrawal retains the private PDF, history and earlier management snapshot')
        status,text,_=request(mentor_client,'/reviews')
        assert 'Mark review reminder seen' not in text
        status,text,_=request(mentor_client,f'/reviews/reminders/{mentor_reminder}/seen',{'_token':token(text)})
        assert status==200 and 'no longer current' in text
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT COUNT(*) FROM review_reminders').fetchone()[0]==2
            assert db.execute('SELECT COUNT(*) FROM review_reminder_events').fetchone()[0]==4
        passed('Actual source withdrawal hides obsolete review prompts and rejects stale acknowledgement while retaining reminders and audits')
        with sqlite3.connect(database) as db:
            db.row_factory=sqlite3.Row
            saved_modules={}
            for table in ['learning_tasks','training_topics','qualifications','smart_actions','career_discussions','training_sessions','attendance_marks','support_concerns','apprentice_markers','progress_review_drafts','programme_outcomes','monthly_scorecards','case_studies','survey_receipts','staff_development_profiles','staff_development_events','support_plans','training_plan_workspaces','executive_access_sessions','executive_access_events','extra_trainings','support_capabilities','support_permission_events','marker_change_events','assignment_change_events','learning_block_controls','marking_reminders','marking_reminder_events','account_access_events','individual_delivery_plans','careers_prompts','careers_prompt_events','portal_reminder_runs','data_import_batches','data_import_events','historical_otj_records','management_packs','signed_review_records','signed_review_events','review_reminders','review_reminder_events']:
                saved_modules[table]=[dict(row) for row in db.execute('SELECT * FROM '+table+' ORDER BY id')]

        import hashlib
        saved_files={}
        with sqlite3.connect(database) as db:
            for table in ['qualifications','support_plans']:
                for (raw,) in db.execute('SELECT files FROM '+table+' WHERE files IS NOT NULL'):
                    for file in json.loads(raw):
                        path=test_storage/'app/private'/file['path']
                        assert hashlib.sha256(path.read_bytes()).hexdigest()==file['sha256']
                        saved_files[str(path)]=file['sha256']
        with sqlite3.connect(database) as db:
            for (raw,) in db.execute('SELECT file FROM signed_review_records'):
                entry=json.loads(raw);path=test_storage/'app/private'/entry['path']
                assert hashlib.sha256(path.read_bytes()).hexdigest()==entry['sha256']
                saved_files[str(path)]=entry['sha256']
        inventory=json.loads(artisan('gta:evidence-inventory'))
        assert inventory['result']=='references_scanned' and inventory['totals']['references']>0 and inventory['totals']['objects']>0 and not inventory['writes_performed']
        assert 'reference_details' not in inventory
        passed('Actual CLI inventory reports registered upload counts without document contents or source mutation')
        verified_inventory=json.loads(artisan('gta:evidence-inventory','--verify-files'))
        assert verified_inventory['result']=='verified_referenced_objects' and not verified_inventory['reference_change_detected']
        assert verified_inventory['inventory_binding']==inventory['inventory_binding']
        assert verified_inventory['totals']['verified_objects']==verified_inventory['totals']['objects']
        passed('Explicit CLI inventory hash-verifies all registered fixture objects while saved rows and file bytes remain unchanged')
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
        with sqlite3.connect(database) as db:
            assert db.execute("SELECT auth_version,password,remember_token FROM users WHERE email='tutor@example.test'").fetchone()==tutor_security_state
        restarted_tutor=client();login(restarted_tutor,'tutor@example.test',new_tutor_password)
        assert request(restarted_tutor,'/account/password')[0]==200
        passed('Actual HTTP updated credentials and security version persist after PHP restart')
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
        passed('Complete saved records for forty-one connected module tables survive PHP restart unchanged')
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
        result = {'passed':len(results),'failed':0,'checks':results,'database':'Isolated MariaDB via PDO MySQL and independent Python connector' if args.mysql_fixture else 'SQLite file-backed database','http':'Actual PHP/Laravel loopback requests, not browser automation','recordProof':{'reference':before['reference'],'employer':before['employer'],'version':before['version'],'allFieldsUnchangedAfterRestart':True},'limits':['No browser or mobile layout acceptance','Not a production deployment or full-portal acceptance']}
        Path(args.output).write_text(json.dumps(result,indent=2)+'\n')
        print(json.dumps({'passed':len(results),'failed':0,'restartProof':True}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(results),'failed':1,'checks':results,'database':'Isolated MariaDB fixture' if args.mysql_fixture else 'SQLite fixture'},indent=2)+'\n')
        raise
    finally:
        stop()
        with sqlite3.connect(database) as db:
            for table in ['qualifications','support_plans']:
                try:
                    for (raw,) in db.execute('SELECT files FROM '+table+' WHERE files IS NOT NULL'):
                        for file in json.loads(raw):
                            (test_storage/'app/private'/file['path']).unlink(missing_ok=True)
                except sqlite3.OperationalError:
                    pass
