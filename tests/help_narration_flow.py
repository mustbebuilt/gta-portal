"""Fictional Pack27 general help and narration workflows over a local TLS proxy; never accesses Laravel Cloud.
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

with tempfile.TemporaryDirectory(prefix='gta-help-audio-') as temporary:
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
                self.send_header('Content-Length',response.getheader('Content-Length') if self.command=='HEAD' and response.getheader('Content-Length') is not None else str(len(payload)));self.end_headers();self.wfile.write(payload)
            finally:connection.close()
        do_GET=forward;do_POST=forward;do_HEAD=forward
    def req(client,path,data=None):
        try:
            with client.open(Request(origin+path,data=None if data is None else urlencode(data).encode()),timeout=30) as response:return response.status,response.read().decode(),response.url,response.headers
        except HTTPError as error:return error.code,error.read().decode(),error.url,error.headers
    def csrf(text):
        match=re.search(r'name="_token"\s+value="([^"]+)"',text);assert match,'No CSRF token on real form';return match[1]


    import io,wave,hashlib
    output_audio=io.BytesIO()
    with wave.open(output_audio,'wb')as silent:silent.setnchannels(1);silent.setsampwidth(2);silent.setframerate(8000);silent.writeframes(bytes(1600))
    audio_bytes=output_audio.getvalue()
    try:
        configure();assert artisan('migrate','--force').returncode==0
        seed=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$users=[];foreach(['admin@example.test'=>'admin','checker@example.test'=>'ceo','learner@example.test'=>'apprentice','employer@example.test'=>'employer','tutor@example.test'=>'tutor']as$email=>$role){$users[$email]=App\Models\User::factory()->create(['email'=>$email,'role'=>$role,'password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);}$a=App\Models\Apprentice::factory()->create(['reference'=>'HELP-FICTIONAL','first_name'=>'PRIVATE-LEARNER-HELP-SENTINEL','user_id'=>$users['learner@example.test']->id]);echo json_encode(['apprentice'=>$a->id]);"
        result=subprocess.run([args.php,'-r',seed],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert result.returncode==0,'Fictional help setup failed'
        cert=temp/'cert.pem';key=temp/'key.pem';assert subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],capture_output=True,timeout=30).returncode==0
        proxy=http.server.ThreadingHTTPServer(('127.0.0.1',tls_port),Proxy);tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);proxy.socket=tls.wrap_socket(proxy.socket,server_side=True);threading.Thread(target=proxy.serve_forever,daemon=True).start();start_backend()
        def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))))
        def login(address):
            c=client();status,text,_,_=req(c,'/login');assert status==200;assert req(c,'/login',{'_token':csrf(text),'email':address,'password':private_password})[0]==200;return c
        def post(c,source,path,values):
            status,text,_,_=req(c,source);assert status==200;return req(c,path,{'_token':csrf(text),**values})
        admin=login('admin@example.test');checker=login('checker@example.test');learner=login('learner@example.test');employer=login('employer@example.test');tutor=login('tutor@example.test')
        status,text,_,headers=req(learner,'/help?q=review');assert status==200 and 'Read and sign your review'in text and 'Prepare a review draft'not in text and 'PRIVATE-LEARNER-HELP-SENTINEL'not in text and headers['Referrer-Policy']=='no-referrer'and 'no-store'in headers['Cache-Control']
        assert req(learner,'/help?guide=setup')[0]==404 and req(tutor,'/help-recordings')[0]==403
        passed('Actual role-scoped help search finds current signing guidance, hides staff setup and never loads apprentice record text')
        text=req(learner,'/help?guide=start')[1];assert 'A GTA Yorkshire recording is not yet available'in text and 'data-recording-url'not in text
        passed('Text-first help and device option remain available while no approved regional recording exists')
        assert req(admin,'/help-recordings',{'guide_id':'start'})[0]==419
        source='/help-recordings?guide=start';status,text,_,_=req(admin,source);assert status==200;guide_hash=re.search(r'name="guide_hash" value="([a-f0-9]{64})"',text).group(1)
        boundary='GTAHelpFixture'+secrets.token_hex(10);parts=[]
        values={'_token':csrf(text),'guide_id':'start','guide_hash':guide_hash,'source_reference':'PRIVATE-HELP-RECORDING-REFERENCE','generic_content_checked':'1'}
        for name,value in values.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="fictional-silent.wav"\r\nContent-Type: audio/wav\r\n\r\n'.encode()+audio_bytes+b'\r\n');parts.append(f'--{boundary}--\r\n'.encode())
        with admin.open(Request(origin+'/help-recordings',data=b''.join(parts),headers={'Content-Type':'multipart/form-data; boundary='+boundary}),timeout=30)as response:assert response.status==200
        with sqlite3.connect(db)as c:record=c.execute('SELECT id,state,file,source_reference,version FROM help_narration_records').fetchone();assert record and record[1]=='Uploaded'and 'PRIVATE-HELP-RECORDING-REFERENCE'not in record[3]and 'help-narrations/'not in record[2]
        media='/help/guides/start/recordings/'+record[0];decision='/help-recordings/'+record[0]+'/decision'
        assert req(learner,media)[0]==409 and req(learner,'/help?guide=start')[1].count('data-recording-url')==0
        passed('Real CSRF/multipart audio upload stores a bounded private encrypted descriptor; upload alone does not expose recording')
        values={'version':1,'action':'approve','note':'FICTIONAL-MECHANICAL-CHECK-NOT-ACCENT-PROOF','yorkshire_listened':1,'transcript_checked':1,'permission_checked':1}
        assert post(admin,source,decision,values)[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT state FROM help_narration_records').fetchone()[0]=='Uploaded'
        assert post(checker,source,decision,values)[0]==200
        with sqlite3.connect(db)as c:assert c.execute('SELECT state,version,published_guide_key FROM help_narration_records').fetchone()==('Approved',2,'start')
        passed('Self-publication is rejected and a different authorised manager can record the mechanical approval checks; silent fixture is not accent proof')
        status,text,_,headers=req(learner,'/help?guide=start');assert status==200 and media in text and 'preload="none"'in text and 'PRIVATE-HELP-RECORDING-REFERENCE'not in text and 'FICTIONAL-MECHANICAL-CHECK'not in text and 'autoplay'not in text
        assert not re.search(r'<audio[^>]*\ssrc=',text)
        passed('Published guide offers only explicit same-origin playback, retains transcript and exposes no private permission/review notes or eager media source')
        with learner.open(origin+media,timeout=30)as response:body=response.read();assert response.status==200 and body==audio_bytes and response.headers['Accept-Ranges']=='bytes'and response.headers['Content-Type']=='audio/wav'and 'no-store'in response.headers['Cache-Control']
        with learner.open(Request(origin+media,headers={'Range':'bytes=0-11'}),timeout=30)as response:assert response.status==206 and response.read()==audio_bytes[:12]and response.headers['Content-Range']==f'bytes 0-11/{len(audio_bytes)}'
        with learner.open(Request(origin+media,headers={'Range':'bytes=-8'}),timeout=30)as response:assert response.status==206 and response.read()==audio_bytes[-8:]
        with learner.open(Request(origin+media,method='HEAD',headers={'Range':'bytes=2-7'}),timeout=30)as response:assert response.status==206 and response.read()==b''and response.headers['Content-Length']=='6'
        passed('Actual authenticated media returns exact silent-fixture bytes, correct single/suffix ranges and HEAD content length over verified TLS')
        try:
            learner.open(Request(origin+media,headers={'Range':'bytes=0-1,4-5'}),timeout=30);raise AssertionError('Multiple ranges accepted')
        except HTTPError as error:assert error.code==416 and error.read()==b''
        assert req(learner,'/help-recordings/'+record[0]+'/preview')[0]==403 and req(learner,'/help/guides/submit-work/recordings/'+record[0])[0]==404
        passed('Multiple ranges, forged guide/record association and non-manager preview access are refused')
        report=artisan('gta:help-check','--records','--verify-files');assert report.returncode==0;data=json.loads(report.stdout);assert data['counts']['files_verified']==1 and data['counts']['approved_current']==1 and data['accent_automatically_verified']is False and 'PRIVATE-HELP-RECORDING-REFERENCE'not in report.stdout and data['guides_without_current_recording']==32
        passed('Actual read-only staging readiness/file check reports one current clip and remaining gaps without claiming automatic accent verification')
        stop_backend();start_backend()
        with learner.open(origin+media,timeout=30)as response:assert hashlib.sha256(response.read()).digest()==hashlib.sha256(audio_bytes).digest()
        passed('Encrypted narration state, session and exact private media persist across backend restart')
        assert post(admin,source,decision,{'version':2,'action':'withdraw','note':'PRIVATE-HELP-WITHDRAWAL'})[0]==200
        assert req(learner,media)[0]==409 and 'data-recording-url'not in req(learner,'/help?guide=start')[1]
        with admin.open(origin+'/help-recordings/'+record[0]+'/preview',timeout=30)as response:assert response.read()==audio_bytes
        passed('Explicit withdrawal removes user playback while preserving private archived clip and history')
        stop_backend();assert artisan('migrate:rollback','--step=1','--force').returncode==0
        with sqlite3.connect(db)as c:assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('timetable_delivery_records')").fetchone()[0]==0;assert c.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='electronic_review_packets'").fetchone()[0]==1
        assert artisan('migrate','--force').returncode==0
        passed('Disposable latest down/up removes only Pack41 delivery attribution table and retains Pack26 signing schema; real audio needs backup')
        configure('production');start_backend();assert req(learner,'/help')[0]==503
        passed('Existing production access gate remains enforced')
        output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual client-verified local HTTPS, encrypted database sessions/cache and private silent WAV byte/range/HEAD transport','realDataUsed':False,'externalMessagesSent':False,'speechProviderCalled':False,'actualYorkshireRecordingProvidedOrHeard':False,'actualBrowserOrPhoneTested':False,'actualCloudAccess':False,'actualNativeEngine':False};Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps(output))
    finally:
        stop_backend()
        if proxy:proxy.shutdown();proxy.server_close()
