"""Real loopback HTTP Extra-training follow-up batches with fictional disposable SQLite.
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
with tempfile.TemporaryDirectory(prefix='gta-extra-followup-') as temporary:
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
        code=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$a=App\Models\Apprentice::factory()->create(['reference'=>'EXTRA-FOLLOWUP-TEST']);$approved=App\Models\ExtraTraining::factory()->create(['apprentice_id'=>$a->id,'working'=>'normal','topic'=>'Fictional battery approved']);$approved->approval=['by'=>1,'at'=>now()->toIso8601String(),'eligible'=>true];$approved->save();$pending=App\Models\ExtraTraining::factory()->create(['apprentice_id'=>$a->id,'topic'=>'Fictional battery pending']);App\Models\ExtraTraining::factory()->count(22)->create(['apprentice_id'=>$a->id,'working'=>'normal','topic'=>'Other diagnostic training']);$foreign=App\Models\ExtraTraining::factory()->create();echo json_encode(['apprentice'=>$a->id,'approved'=>$approved->id,'pending'=>$pending->id,'foreign'=>$foreign->id]);"
        r=subprocess.run([args.php,'-r',code],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert r.returncode==0,'Fictional extra-training setup failed';ids=json.loads(r.stdout);before={t:rows(t) for t in ['extra_trainings','learning_events','attendance_marks']}
        server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{port}','-t','public'],cwd=project,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        for _ in range(100):
            try:
                if urlopen(origin+'/up',timeout=1).status==200:break
            except Exception:time.sleep(.05)
        _,text,_=req('/login');_,text,url=req('/login',{'_token':token(text),'email':'admin@example.test','password':password});assert not url.endswith('/login');passed('Real administrator session and CSRF sign-in')
        source='/apprentices/'+str(ids['apprentice'])+'/extra-training'
        _,text,_=req(source+'?search=battery&show=pending');assert 'Fictional battery pending' in text and 'Fictional battery approved' not in text and 'All records (2)' in text and 'All pending checks (1)' in text;Markup(text);passed('Topic search and workflow filters show scoped record counts')
        assert '2.00 hrs' in text and 'independent of search, status and page' in text;passed('Filtered pending list retains whole-apprentice approved subtotal')
        _,text,_=req(source+'?show=pending&page=99');assert 'page=1' in text and 'show=pending' in text;Markup(text);passed('Out-of-range pagination clamps and retains status filter')
        _,text,_=req(source+'?record='+str(ids['approved'])+'&search=NO-MATCH&show=pending&page=99');assert 'Showing source record '+str(ids['approved']) in text and 'Fictional battery approved' in text and 'Fictional battery pending' not in text;passed('Direct source link opens older record regardless of list filters')
        from urllib.error import HTTPError
        try:req(source+'?record='+str(ids['foreign']));raise AssertionError('Foreign record should refuse')
        except HTTPError as e:assert e.code==404
        passed('Foreign apprentice record ID cannot be opened in this scope')
        _,text,_=req(source+'?search=NO-MATCH');assert 'No matching extra training' in text and '2.00 hrs' in text;passed('Empty search makes no completion claim and retains subtotal')
        assert {t:rows(t) for t in before}==before;passed('Read navigation preserves extra training, attendance and audit rows')
        result={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual localhost HTTP/session/CSRF with disposable fictional SQLite','fixture':'Synthetic read states; existing feature tests exercise approval/confirmation writes','browserVerified':False,'actualCloudAccess':False};Path(args.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
    finally:
        if server:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
