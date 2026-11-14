"""Real loopback HTTP register follow-up batches with fictional disposable SQLite.
Checks scoped counts/rows, deadlines and assignment flags; not visual/browser acceptance.
"""
from pathlib import Path
import argparse,base64,http.cookiejar,json,os,re,secrets,signal,socket,sqlite3,subprocess,tempfile,time
from urllib.request import build_opener,HTTPCookieProcessor,Request,urlopen
from urllib.parse import urlencode
from html.parser import HTMLParser
p=argparse.ArgumentParser();p.add_argument('--php',default='php');p.add_argument('--output',required=True);args=p.parse_args()
project=Path(__file__).resolve().parent.parent;checks=[];server=None
class Markup(HTMLParser):
    def __init__(self,text):
        super().__init__();self.ids={};self.links=[];self.summary=False;self.summary_depth=0;self.summary_links=[];self.feed(text)
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if 'id' in a:
            assert a['id'] not in self.ids,'Duplicate form/page ID';self.ids[a['id']]=(tag,a)
        if tag=='section' and 'data-error-summary' in a:self.summary=True;self.summary_depth=1
        elif self.summary_depth and tag=='section':self.summary_depth+=1
        if tag=='a' and self.summary_depth:self.summary_links.append(a.get('href'))
    def handle_endtag(self,tag):
        if tag=='section' and self.summary_depth:self.summary_depth-=1

def passed(name):checks.append({'name':name,'passed':True})
def check_fields(html,fields):
    m=Markup(html);assert m.summary
    for field in fields:
        assert '#'+field in m.summary_links
        tag,a=m.ids[field];assert tag in ['input','select','textarea'] and a.get('type')!='hidden';assert a.get('aria-invalid')=='true' and a.get('aria-describedby')==field+'-error' and field+'-error' in m.ids
    return m
with tempfile.TemporaryDirectory(prefix='gta-register-followup-') as temporary:
    temp=Path(temporary);db=temp/'fictional.sqlite';db.touch();storage=temp/'storage'
    for part in ['app/private','framework/cache/data','framework/cache/locks','framework/views','framework/sessions','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    origin=f'http://127.0.0.1:{port}';env=os.environ.copy();env.update(APP_ENV='local',APP_DEBUG='false',APP_URL=origin,APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),DB_CONNECTION='sqlite',DB_DATABASE=str(db),DB_URL='',SESSION_DRIVER='file',CACHE_STORE='file',MAIL_MAILER='array',QUEUE_CONNECTION='sync',LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(temp/'config.php'),APP_ROUTES_CACHE=str(temp/'routes.php'))
    def artisan(*command):
        r=subprocess.run([args.php,'artisan',*command,'--no-interaction'],cwd=project,env=env,capture_output=True,text=True,timeout=60);assert r.returncode==0,'Disposable fixture setup failed';return r.stdout
    c=build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def req(path,data=None,referer=None):
        with c.open(Request(origin+path,data=None if data is None else urlencode(data).encode(),headers={} if referer is None else {'Referer':origin+referer}),timeout=30) as r:return r.status,r.read().decode(),r.url
    def token(text):return re.search(r'name="_token"\s+value="([^"]+)"',text)[1]
    def rows(table):
        with sqlite3.connect(db) as conn:return conn.execute('SELECT * FROM '+table+' ORDER BY id').fetchall()
    try:
        artisan('migrate');seed=artisan('gta:demo');password=seed.split('Local test password for these accounts: ',1)[1].splitlines()[0].strip()
        code=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$u=App\Models\User::factory()->create(['role'=>'tutor']);$g=App\Models\TutorGroup::factory()->create(['tutor_id'=>$u->id]);$ids=['group'=>$g->id];foreach(['missing','recorded','future','cancelled','unknown','malformed'] as $kind){$a=App\Models\Apprentice::factory()->create(['tutor_id'=>$u->id,'tutor_group_id'=>$g->id]);$s=App\Models\TrainingSession::factory()->create(['tutor_group_id'=>$g->id,'session_date'=>$kind==='future'?today()->addDay():today()->subDay(),'name'=>'Fictional register '.$kind,'members'=>[$a->id]]);if(in_array($kind,['recorded','unknown'],true)){App\Models\AttendanceMark::create(['training_session_id'=>$s->id,'apprentice_id'=>$a->id,'code'=>$kind==='recorded'?'O':'Z','recorded_by'=>$s->created_by,'comment'=>'PRIVATE REGISTER COMMENT NOT FOR LIST','otj_confirmed'=>false]);}if($kind==='cancelled'){$s->state='Cancelled';$s->save();}if($kind==='malformed'){$s->members=[['invalid']];$s->save();}$ids[$kind]=$s->id;$ids[$kind.'_apprentice']=$a->id;}echo json_encode($ids);"
        r=subprocess.run([args.php,'-r',code],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert r.returncode==0,'Fictional register setup failed';ids=json.loads(r.stdout);before={t:rows(t) for t in ['training_sessions','attendance_marks','learning_events']}
        server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{port}','-t','public'],cwd=project,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        for _ in range(100):
            try:
                if urlopen(origin+'/up',timeout=1).status==200:break
            except OSError:time.sleep(.05)
        else:raise AssertionError('Local PHP fixture did not start')
        _,form,_=req('/login');_,_,url=req('/login',{'_token':token(form),'email':'admin@example.test','password':password});assert not url.endswith('/login');passed('Actual administrator sign-in uses a private fictional session')
        group='group='+str(ids['group']);_,text,_=req('/sessions?'+group);assert '<strong>1</strong> ended with codes missing' in text and '<strong>1</strong> codes recorded' in text and '<strong>1</strong> planned/in progress' in text and '<strong>1</strong> cancelled' in text and '<strong>2</strong> source checks' in text;passed('Scoped register counts distinguish missing codes, recorded codes, future, cancelled and source problems')
        assert 'PRIVATE REGISTER COMMENT NOT FOR LIST' not in text;passed('Register list omits private attendance comments')
        _,text,_=req('/sessions?'+group+'&view=needs-register');assert 'data-register-session="'+str(ids['missing'])+'"' in text and 'data-register-session="'+str(ids['cancelled'])+'"' not in text and 'data-register-session="'+str(ids['recorded'])+'"' not in text;passed('Ended-code filter excludes cancelled and already coded sessions')
        _,text,_=req('/dashboard?'+group);assert 'id="dashboard-registers-title"' in text and 'group='+str(ids['group'])+'&amp;view=needs-register' in text;passed('Dashboard register links retain selected group scope')
        _,text,_=req('/sessions/'+str(ids['recorded']));assert 'id="register-readiness-title"' in text and 'All saved roster entries have recognised codes' in text and 'not proof of attendance or eligible OTJ' in text;passed('Actual source register shows code position without confirming attendance or hours')
        from urllib.error import HTTPError
        try:req('/sessions/'+str(ids['malformed']));raise AssertionError('Malformed roster source should refuse')
        except HTTPError as e:assert e.code==422
        passed('Malformed source roster produces an explicit 422 instead of an unusable register')
        try:req('/reports/operational');raise AssertionError('Totals with malformed source should refuse')
        except HTTPError as e:assert e.code==422
        passed('Attendance totals fail closed with source-check status instead of a partial attendance percentage')
        assert {t:rows(t) for t in before}==before;passed('List/dashboard/source reads preserve sessions, marks and audit records')
        result={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual localhost HTTP/session/CSRF with disposable fictional SQLite','fixture':'Code-position checks only; synthetic codes do not confirm attendance/OTJ','browserVerified':False,'actualCloudAccess':False,'limits':['Not attendance/OTJ eligibility or historical completeness assurance','Not browser/device/screen-reader/layout acceptance']};Path(args.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
    finally:
        if server:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
