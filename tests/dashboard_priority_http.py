"""Real loopback HTTP improved dashboard with fictional disposable SQLite.
Checks the live list, filters and source link; not visual/browser acceptance.
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
with tempfile.TemporaryDirectory(prefix='gta-dashboard-priority-') as temporary:
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
        code="require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$a=App\\Models\\Apprentice::firstOrFail();$t=App\\Models\\LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>'preview-http-priority','title'=>'Fictional priority proof','due_date'=>today()->subDays(1000)]);echo json_encode(['task'=>$t->id]);"
        r=subprocess.run([args.php,'-r',code],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert r.returncode==0,'Fictional source setup failed';ident=json.loads(r.stdout)['task'];before=rows('learning_tasks')
        server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{port}','-t','public'],cwd=project,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        for _ in range(100):
            try:
                if urlopen(origin+'/up',timeout=1).status==200:break
            except OSError:time.sleep(.05)
        else:raise AssertionError('Local PHP fixture did not start')
        _,form,_=req('/login');_,_,url=req('/login',{'_token':token(form),'email':'admin@example.test','password':password});assert not url.endswith('/login');passed('Actual administrator sign-in uses a private fictional session')
        _,text,_=req('/dashboard?priority=overdue');assert 'data-priority-row="task-'+str(ident)+'"' in text and 'Fictional priority proof' in text and 'Training work to follow up' in text;passed('Actual dashboard GET renders the scoped overdue source row')
        source=origin+'/tasks/'+str(ident);assert 'href="'+source+'"' in text;status,_,_=req('/tasks/'+str(ident));assert status==200;passed('The rendered task link opens the actual authorised source route')
        _,text,_=req('/dashboard?priority=checks');assert 'data-priority-row="task-'+str(ident)+'"' not in text;passed('Changing the filter excludes an overdue row from date checks')
        _,text,_=req('/reports/operational');assert 'data-priority-row' not in text;passed('Existing operational report stays separate from the live work list')
        from urllib.error import HTTPError
        try:req('/dashboard?priority=invented');raise AssertionError('Invalid filter was accepted')
        except HTTPError as e:assert e.code==422
        passed('An unknown live-list filter returns 422 through actual HTTP')
        assert rows('learning_tasks')==before;passed('Dashboard/filter/source/report reads preserve all task records')
        result={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual loopback HTTP and session cookies with disposable fictional SQLite','browserVerified':False,'actualCloudAccess':False,'limits':['Not browser/phone/screen-reader/layout acceptance']};Path(args.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
    finally:
        if server:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
