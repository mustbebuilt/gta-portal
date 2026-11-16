"""Fictional Pack30 leaver destination workflows over a local TLS proxy; never accesses Laravel Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-leaver-destination-') as temporary:
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
        bootstrap=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();"
        def script(code):
            r=subprocess.run([args.php,'-r',bootstrap+code],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert r.returncode==0,'Private fixture setup failed';return json.loads(r.stdout or '{}')
        ids=script(r"$users=[];foreach(['admin@example.test'=>'admin','adviser@example.test'=>'careers_adviser','other@example.test'=>'careers_adviser','tutor@example.test'=>'tutor','learner@example.test'=>'apprentice']as$email=>$role){$users[$email]=App\Models\User::factory()->create(['email'=>$email,'role'=>$role,'password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);}$a=App\Models\Apprentice::factory()->create(['reference'=>'LEAVER-FICTIONAL','first_name'=>'=HYPERLINK(\"fictional\")','careers_adviser_id'=>$users['adviser@example.test']->id,'tutor_id'=>$users['tutor@example.test']->id,'user_id'=>$users['learner@example.test']->id,'start_date'=>today()->subYears(3)]);$o=App\Models\ProgrammeOutcome::factory()->create(['apprentice_id'=>$a->id,'context'=>['status'=>'Completed','endDate'=>today()->subDays(14)->toDateString(),'sourceRef'=>'PRIVATE-STATUS-SOURCE','achievement'=>'Unknown','additional'=>'Yes','contextRef'=>'PRIVATE-SUPPORT-CONTEXT','destination'=>'Unknown','destinationBasis'=>'Unknown']]);echo json_encode(['apprentice'=>$a->id,'outcome'=>$o->id,'today'=>today()->toDateString(),'next'=>today()->addDays(7)->toDateString()]);")
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200
            assert req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password})[0]==200;return c
        def post(c,source,path,values):
            status,text,_,_=req(c,source);assert status==200
            return req(c,path,{'_token':csrf(text),**values})
        manager=login('admin@example.test');adviser=login('adviser@example.test');other=login('other@example.test');tutor=login('tutor@example.test');learner=login('learner@example.test')
        detail=f'/apprentices/{ids["apprentice"]}/leaver-followup';contacts=f'/apprentices/{ids["apprentice"]}/destination-contacts';proposals=f'/apprentices/{ids["apprentice"]}/destination-proposals'
        status,text,_,headers=req(adviser,detail);assert status==200 and 'LEAVER-FICTIONAL'in text and headers['Referrer-Policy']=='no-referrer'
        assert req(other,detail)[0]==403 and req(tutor,detail)[0]==403 and req(learner,detail)[0]==403 and req(client(),detail)[2].endswith('/login')
        passed('Actual verified HTTPS requires manager or currently assigned Careers duty; other staff/apprentice/guest refused')
        contact={'request_token':'12345678-1234-4234-8234-123456789abc','outcome_version':1,'contacted_on':ids['today'],'method':'Phone','result':'No reply','next_followup_on':ids['next'],'reference':'PRIVATE-CONTACT-SOURCE','note':'PRIVATE-CONTACT-NOTE','confirmed':1}
        assert req(adviser,contacts,contact)[0]==419
        passed('Actual missing-CSRF contact POST refused before writes')
        original=script(f"echo json_encode(App\\Models\\ProgrammeOutcome::findOrFail({ids['outcome']})->getAttributes());")
        status,text,_,_=post(adviser,detail,contacts,contact);assert status==200 and 'PRIVATE-CONTACT-NOTE'in text
        with sqlite3.connect(db)as c:row=c.execute('SELECT id,detail FROM destination_contacts').fetchone();contact_id=row[0];assert 'PRIVATE-CONTACT'not in row[1]
        assert script(f"echo json_encode(App\\Models\\ProgrammeOutcome::findOrFail({ids['outcome']})->getAttributes());")==original
        passed('Real dated contact stores encrypted history/explicit next date without destination, voice, status or achievement change')
        post(adviser,detail,contacts,contact)
        with sqlite3.connect(db)as c:assert c.execute('SELECT COUNT(*) FROM destination_contacts').fetchone()[0]==1
        passed('Repeated identical contact token is idempotent')
        status,text,_,_=req(adviser,'/leaver-followup?show=unknown');assert status==200 and 'LEAVER-FICTIONAL'in text and 'PRIVATE-CONTACT'not in text
        assert 'LEAVER-FICTIONAL'not in req(other,'/leaver-followup')[1]
        passed('Scoped unknown/next-follow-up queue exposes metadata without private contact notes')
        draft={'request_token':'22345678-1234-4234-8234-123456789abc','outcome_version':1,'destination':'New employer','basis':'Reported','observed_on':ids['today'],'reference':'PRIVATE-DESTINATION-SOURCE','confirmed':1}
        status,text,url,_=post(adviser,detail,proposals,draft);assert status==200;proposal=url[len(origin):];proposal_id=proposal.rsplit('/',1)[1]
        assert script(f"echo json_encode(App\\Models\\ProgrammeOutcome::findOrFail({ids['outcome']})->getAttributes());")==original
        assert req(other,proposal)[0]==403
        passed('Encrypted dated destination proposal leaves reporting unchanged and stays assignment-scoped')
        assert post(adviser,proposal,proposal+'/review',{'version':1,'decision':'apply','note':'Fictional self check','confirmed':1})[0]==403
        status,text,_,_=post(manager,proposal,proposal+'/review',{'version':1,'decision':'apply','note':'PRIVATE-CHECK-SOURCE','confirmed':1});assert status==200
        outcome=script(f"echo json_encode(App\\Models\\ProgrammeOutcome::findOrFail({ids['outcome']})->context);")
        assert outcome['destination']=='New employer' and outcome['destinationBasis']=='Reported' and outcome['achievement']=='Unknown' and outcome['additional']=='Yes'
        with sqlite3.connect(db)as c:assert c.execute('SELECT state,version FROM destination_proposals WHERE id=?',(proposal_id,)).fetchone()==('Applied',2)
        passed('Different manager applies exactly four destination fields; Reported is preserved and private/achievement context unchanged')
        status,csv_text,_,headers=req(adviser,'/leaver-followup.csv?search=LEAVER-FICTIONAL');assert status==200 and 'text/csv'in headers['Content-Type'] and "'=HYPERLINK"in csv_text and 'PRIVATE-'not in csv_text and 'Current independently checked proposal'in csv_text
        assert req(tutor,'/leaver-followup.csv')[0]==403
        passed('Actual scoped filtered CSV protects formula-leading names and excludes private notes/source/support references')
        stop_backend();start_backend();assert req(manager,proposal)[0]==200 and '1 current independently checked proposals'in req(manager,'/leaver-followup')[1]
        passed('Database-backed sessions, applied destination and current independent-check provenance survive restart')
        post(manager,proposal,proposal+'/withdraw',{'version':2,'reason':'Fictional source correction','confirmed':1})
        restored=script(f"echo json_encode(App\\Models\\ProgrammeOutcome::findOrFail({ids['outcome']})->context);")
        assert restored==json.loads(original['context'])
        passed('Independent applied correction restores prior destination fields including absent keys, retaining non-destination context/history')
        draft['request_token']='32345678-1234-4234-8234-123456789abc';draft['outcome_version']=3
        status,text,url,_=post(adviser,detail,proposals,draft);assert status==200;proposal=url[len(origin):]
        post(manager,proposal,proposal+'/review',{'version':1,'decision':'apply','note':'Fictional check','confirmed':1})
        script(f"$o=App\\Models\\ProgrammeOutcome::findOrFail({ids['outcome']});$c=$o->context;$c['voice']='Recorded';$o->context=$c;$o->version++;$o->save();echo '{{}}';")
        post(manager,proposal,proposal+'/withdraw',{'version':2,'reason':'Fictional later correction','confirmed':1})
        final=script(f"echo json_encode(App\\Models\\ProgrammeOutcome::findOrFail({ids['outcome']})->context);");assert final['voice']=='Recorded' and final['destination']=='New employer'
        passed('Later original-context changes block automatic reversal; newer facts are not overwritten')
        post(adviser,detail,f'/destination-contacts/{contact_id}/void',{'version':1,'reason':'Fictional contact correction','confirmed':1})
        with sqlite3.connect(db)as c:assert c.execute('SELECT voided_at FROM destination_contacts WHERE id=?',(contact_id,)).fetchone()[0]is not None and c.execute('SELECT COUNT(*) FROM destination_events').fetchone()[0]>=7
        passed('Incorrect contact is voided with audit retained; no contact or proposal history deletion')
        with sqlite3.connect(db) as c: before_tables={row[0] for row in c.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('account_lifecycle_proposals','account_lifecycle_states','cohort_forecasts','cohort_snapshots','operational_followups','portal_operations_events')").fetchone()[0]==6
            assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='workplace_support_shares'").fetchone()[0]==1
            assert c.execute('SELECT COUNT(*) FROM programme_outcomes').fetchone()[0]==1
        with sqlite3.connect(db) as c:
            after_tables={row[0] for row in c.execute("SELECT name FROM sqlite_master WHERE type='table'")}
            assert before_tables-after_tables=={'centre_manager_grants','centre_manager_access_requests','centre_manager_followups'} and not(after_tables-before_tables)
        assert artisan('migrate','--force').returncode==0
        passed('Latest Pack49 down/up removes only the three Centre Manager access/follow-up tables while all prior schema and business-data checks are retained')
        configure('production');start_backend();assert req(manager,'/leaver-followup')[0]==503 and req(adviser,detail)[0]==503
        passed('Existing production gate still blocks new follow-up/report screens')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual local client-verified HTTPS; disposable SQLite and encrypted database sessions/cache','realDataUsed':False,'externalMessagesSent':False,'actualCloudAccess':False,'actualNativeEngine':False,'actualBrowserOrPhoneTested':False,'actualMicrosoftExcelTested':False};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps(output))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
