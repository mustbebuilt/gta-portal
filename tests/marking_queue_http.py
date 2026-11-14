"""Real loopback HTTP improved marking queue with fictional disposable SQLite.
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
with tempfile.TemporaryDirectory(prefix='gta-marking-queue-') as temporary:
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
        code=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$u=App\Models\User::factory()->create(['role'=>'tutor']);$v=App\Models\User::factory()->create(['role'=>'tutor']);$bad=App\Models\User::factory()->create(['role'=>'tutor','active'=>false]);$ids=[];foreach([['late',$u,now()->subDays(8)],['today',$u,today()->endOfDay()->startOfSecond()->subDays(7)],['missing',$u,null],['future',$u,now()->addDay()],['other',$v,now()->subDays(8)],['inactive',$bad,now()->subDays(8)],['unassigned',null,now()->subDays(8)]] as [$kind,$owner,$at]){$a=App\Models\Apprentice::factory()->create(['tutor_id'=>$owner?->id]);$t=App\Models\LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>'queue-http-'.$kind,'title'=>'Fictional queue '.$kind]);$t->status='Awaiting marking';$t->submitted_at=$at;$t->submissions=$at?[['version'=>1,'submitted_at'=>$at->toIso8601String(),'template_hash'=>$t->template['sha256'],'answers'=>['secret'=>'PRIVATE ANSWER MUST NOT BE IN QUEUE'],'decision'=>null]]:[];$t->save();$ids[$kind]=$t->id;}$ids['tutor']=$u->id;$ids['inactive_tutor']=$bad->id;echo json_encode($ids);"
        r=subprocess.run([args.php,'-r',code],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert r.returncode==0,'Fictional queue source setup failed';ids=json.loads(r.stdout);before=rows('learning_tasks');reminders=rows('marking_reminders')
        server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{port}','-t','public'],cwd=project,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        for _ in range(100):
            try:
                if urlopen(origin+'/up',timeout=1).status==200:break
            except OSError:time.sleep(.05)
        else:raise AssertionError('Local PHP fixture did not start')
        _,form,_=req('/login');_,_,url=req('/login',{'_token':token(form),'email':'admin@example.test','password':password});assert not url.endswith('/login');passed('Actual administrator sign-in uses a private fictional session')
        own='&tutor='+str(ids['tutor']);_,text,_=req('/marking?view=all'+own);assert 'data-marking-task="'+str(ids['late'])+'"' in text and 'data-marking-task="'+str(ids['other'])+'"' not in text and 'PRIVATE ANSWER MUST NOT BE IN QUEUE' not in text;passed('Manager tutor filter returns assigned queue rows without other work or private answers')
        assert '<strong>4</strong> awaiting marking' in text and '<strong>1</strong> overdue' in text and '<strong>1</strong> due today' in text and '<strong>2</strong> date checks' in text;passed('Summary counts match the chosen tutor across all views')
        _,text,_=req('/marking?view=today'+own);assert 'data-marking-task="'+str(ids['today'])+'"' in text and 'data-marking-task="'+str(ids['late'])+'"' not in text and 'Marking deadline:' in text;passed('Due-today view shows the deadline and excludes already-overdue work')
        status,_,_=req('/tasks/'+str(ids['today']));assert status==200;passed('The submitted task opens through its real authorised source route')
        _,text,_=req('/marking?view=needs-owner&tutor='+str(ids['inactive_tutor']));assert 'Inactive account' in text and 'data-marking-task="'+str(ids['inactive'])+'"' in text;_,text,_=req('/marking?view=all&tutor=unassigned');assert 'data-marking-task="'+str(ids['unassigned'])+'"' in text and 'Not assigned' in text;passed('Inactive and unassigned tutor filters show clear assignment checks')
        assert 'regardless of the filters above' in text;passed('Global reminder control explains that it processes all eligible overdue sources')
        assert rows('learning_tasks')==before and rows('marking_reminders')==reminders;passed('Queue reads and filters preserve tasks and create no reminders')
        result={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual loopback HTTP/session/CSRF against disposable fictional SQLite','browserVerified':False,'actualCloudAccess':False,'limits':['Not browser/phone/screen-reader/layout acceptance','Due-today fixture uses current portal calendar; run away from the midnight rollover']};Path(args.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
    finally:
        if server:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
