"""Real loopback HTTP Careers follow-up batches with fictional disposable SQLite.
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
with tempfile.TemporaryDirectory(prefix='gta-careers-followup-') as temporary:
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
        code=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$u=App\Models\User::factory()->create(['role'=>'careers_adviser']);$a=App\Models\Apprentice::factory()->create(['first_name'=>'Fictional','last_name'=>'Careers followup','reference'=>'CAREERS-FOLLOWUP-TEST','careers_adviser_id'=>$u->id]);$d=App\Models\CareerDiscussion::factory()->create(['apprentice_id'=>$a->id,'adviser_id'=>$u->id,'discussion_date'=>today()->subMonth(),'advice'=>'PRIVATE CAREERS ADVICE FOR SOURCE ONLY']);$actions=[];foreach(['late','today','missing'] as $i=>$kind){$actions[]=['id'=>$kind,'title'=>'Fictional Careers '.$kind,'status'=>$kind==='late'?'Ready for adviser check':'To do','owner'=>'Apprentice','due_date'=>$kind==='late'?today()->subDay()->toDateString():($kind==='today'?today()->toDateString():null),'criteria'=>'PRIVATE CAREERS CRITERIA','support'=>'Adviser appointment','agreed_at'=>today()->subMonth()->toDateString(),'created_by'=>$u->id];}$d->actions=$actions;$d->save();for($i=0;$i<12;$i++){App\Models\CareerDiscussion::factory()->create(['apprentice_id'=>$a->id,'adviser_id'=>$u->id,'type'=>'employment','discussion_date'=>today()->subDay()]);}$bad=App\Models\CareerDiscussion::factory()->create(['apprentice_id'=>$a->id,'adviser_id'=>$u->id]);$bad->actions=['invalid'];$bad->save();echo json_encode(['apprentice'=>$a->id,'discussion'=>$d->id,'bad'=>$bad->id]);"
        r=subprocess.run([args.php,'-r',code],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert r.returncode==0,'Fictional Careers setup failed';ids=json.loads(r.stdout);before={t:rows(t) for t in ['career_discussions','learning_events','careers_prompts','careers_prompt_events']}
        server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{port}','-t','public'],cwd=project,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        for _ in range(100):
            try:
                if urlopen(origin+'/up',timeout=1).status==200:break
            except Exception:time.sleep(.05)
        _,text,_=req('/login');_,text,url=req('/login',{'_token':token(text),'email':'admin@example.test','password':password});assert not url.endswith('/login');passed('Actual administrator session and CSRF sign-in')
        _,text,_=req('/careers?search=CAREERS-FOLLOWUP-TEST&type=progression');assert 'Overdue (1)' in text and 'Due today (1)' in text and 'Date checks (1)' in text and 'With adviser (1)' in text;Markup(text);passed('Search and type filters show overlapping action counts')
        assert 'PRIVATE CAREERS ADVICE FOR SOURCE ONLY' not in text and 'PRIVATE CAREERS CRITERIA' not in text;passed('Private advice and action criteria remain at source')
        assert 'Unreadable action sources (1)' in text;passed('Malformed action source is explicitly counted outside actionable rows')
        source='/apprentices/'+str(ids['apprentice'])+'/careers';assert 'discussion='+str(ids['discussion']) in text
        _,text,_=req(source+'?discussion='+str(ids['discussion']));assert 'id="discussion-'+str(ids['discussion'])+'"' in text and 'PRIVATE CAREERS ADVICE FOR SOURCE ONLY' in text;Markup(text);passed('Older worklist source opens exact discussion beyond first history page')
        _,text,_=req('/careers?search=CAREERS-FOLLOWUP-TEST&show=dates');assert 'Fictional Careers missing' in text and 'Fictional Careers late' not in text and 'no overdue date inferred' in text;passed('Missing deadline remains a date check without inferred lateness')
        _,text,_=req(source+'?history_type=employment&history_page=99');assert 'Discussion history (12)' in text and 'history_page=1' in text and 'Print / Save PDF covers only the discussion page currently shown' in text;passed('History filtering and clamped pagination retain honest print scope')
        from urllib.error import HTTPError
        try:req(source+'?discussion='+str(ids['bad']));raise AssertionError('Unreadable action source should refuse')
        except HTTPError as e:assert e.code==422 and 'Careers action source needs administrator reconciliation' in e.read().decode()
        passed('Malformed source discussion returns a source-check 422')
        _,text,_=req('/careers?search=NO-MATCH-CAREERS');assert 'No apprentices match the search' in text and 'all eligible sources regardless of the search and filters above' in text;passed('Empty search and global prompt control scope stay explicit')
        assert {t:rows(t) for t in before}==before;passed('Reads preserve discussions, action states, prompts and audits')
        result={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual localhost HTTP/session/CSRF with disposable fictional SQLite','browserVerified':False,'actualCloudAccess':False};Path(args.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
    finally:
        if server:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
