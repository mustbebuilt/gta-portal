"""Real loopback HTTP Staff follow-up batches with fictional disposable SQLite.
Checks scoped caseload filters and source links; not visual/browser acceptance.
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
with tempfile.TemporaryDirectory(prefix='gta-staff-followup-') as temporary:
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
        code=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$manager=App\Models\User::where('email','admin@example.test')->firstOrFail();$u=App\Models\User::factory()->create(['role'=>'tutor','name'=>'Followup Colleague']);$outside=App\Models\User::factory()->create(['role'=>'staff','name'=>'Hidden Outsider Staff']);$actions=[];$schedules=[];foreach(['late','today','missing','done'] as $kind){$due=$kind==='missing'?null:($kind==='today'?today()->toDateString():today()->subDay()->toDateString());$actions[]=['id'=>$kind,'title'=>'PRIVATE STAFF ACTION TEXT','measure'=>'PRIVATE STAFF MEASURE','reason'=>'PRIVATE STAFF REASON','support'=>'PRIVATE STAFF SUPPORT','due'=>$due,'status'=>$kind==='done'?'Completed':($kind==='late'?'Ready for manager check':'In progress'),'completed'=>null,'updates'=>[]];$schedules[]=['id'=>$kind,'kind'=>'appraisal','due'=>$due,'status'=>$kind==='done'?'Cancelled':'Planned','completed_date'=>null,'entry_id'=>null,'history'=>[]];}$p=App\Models\StaffDevelopmentProfile::create(['user_id'=>$u->id,'payload'=>['entries'=>[],'actions'=>$actions,'schedules'=>$schedules,'months'=>[],'impacts'=>[]]]);$p->manager_id=$manager->id;$p->save();echo json_encode(['person'=>$u->id,'action_anchor'=>'staff-action-'.hash('sha256','late'),'review_anchor'=>'staff-review-'.hash('sha256','today')]);"
        r=subprocess.run([args.php,'-r',code],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert r.returncode==0,'Fictional staff setup failed';ids=json.loads(r.stdout);before={t:rows(t) for t in ['staff_development_profiles','staff_development_events']}
        server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{port}','-t','public'],cwd=project,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        for _ in range(100):
            try:
                if urlopen(origin+'/up',timeout=1).status==200:break
            except Exception:time.sleep(.05)
        _,text,_=req('/login');_,text,url=req('/login',{'_token':token(text),'email':'admin@example.test','password':password});assert not url.endswith('/login');passed('Real sign-in with a manager-assigned administrator, without global HR access')
        query='search=Followup'
        _,text,_=req('/staff-development?'+query);assert 'All outstanding actions (3)' in text and 'With manager (1)' in text and 'Date checks (1)' in text and 'All outstanding reviews (3)' in text;Markup(text);passed('Scoped search and queue counts exclude completed actions and cancelled bookings')
        assert 'Hidden Outsider Staff' not in text and 'PRIVATE STAFF ACTION TEXT' not in text and 'PRIVATE STAFF MEASURE' not in text and 'PRIVATE STAFF SUPPORT' not in text;passed('Other staff and private action bodies stay outside summary HTML')
        _,text,_=req('/staff-development?'+query+'&action_state=checking&review_state=today&action_page=99&review_page=99&staff_page=99');assert 'Outstanding development actions (1)' in text and 'Planned review follow-up (1)' in text;passed('Independent queue filters and clamped pages retain scope')
        assert '#'+ids['action_anchor'] in text and '#'+ids['review_anchor'] in text;passed('Worklists link to exact hashed source anchors')
        _,text,_=req('/staff-development/'+str(ids['person'])+'?view=manager');assert 'id="'+ids['action_anchor']+'"' in text and 'id="'+ids['review_anchor']+'"' in text and 'PRIVATE STAFF ACTION TEXT' in text;Markup(text);passed('Existing manager source page opens exact actions and bookings')
        assert 'Check source date' in text;passed('Source page labels missing dates without inferring lateness')
        _,text,_=req('/staff-development?'+query+'&staff_state=inactive');assert 'Staff in your scope (0)' in text and 'Outstanding development actions (0)' in text and 'Planned review follow-up (0)' in text;passed('Staff-state selection consistently applies to all three lists')
        _,text,_=req('/staff-development?search=NO-MATCH-STAFF');assert 'No staff records match the search' in text and 'does not prove every required review took place' in text;passed('Empty lists retain explicit completeness limits')
        assert {t:rows(t) for t in before}==before;passed('Read navigation preserves staff profiles, versions and audits')
        result={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual localhost HTTP/session/CSRF with disposable fictional SQLite','fixture':'Synthetic read states; existing workflow tests separately exercise actual actions and bookings','browserVerified':False,'actualCloudAccess':False};Path(args.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
    finally:
        if server:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
