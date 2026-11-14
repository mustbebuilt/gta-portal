"""Real loopback HTTP Support follow-up batches with fictional disposable SQLite.
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
with tempfile.TemporaryDirectory(prefix='gta-support-followup-') as temporary:
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
        code=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$u=App\Models\User::where('email','admin@example.test')->firstOrFail();$a=App\Models\Apprentice::factory()->create(['reference'=>'SUPPORT-FOLLOWUP-TEST']);$ids=['apprentice'=>$a->id];foreach(['late','today','missing','closed','new'] as $kind){$c=App\Models\SupportConcern::factory()->create(['apprentice_id'=>$a->id,'initial_note'=>'PRIVATE SUPPORT INITIAL NOTE']);$c->forceFill(['owner_id'=>$kind==='missing'?null:$u->id,'code'=>'IM36','status'=>$kind==='closed'?'Closed':'Monitoring','next_action'=>'PRIVATE SUPPORT ACTION TEXT','acknowledged_at'=>in_array($kind,['closed','new'])?null:now(),'action_due'=>$kind==='missing'?null:($kind==='today'?today():today()->subDay()),'next_review'=>in_array($kind,['new','missing'])?null:today()->addDay()])->save();$ids[$kind]=$c->id;}$fields=['type'=>'full','referral'=>today()->subDay()->toDateString(),'barriers'=>'PRIVATE PLAN BARRIERS','strengths'=>'Practical engagement','view'=>'Apprentice view','support'=>'PRIVATE ACTIVE PLAN TEXT','review'=>today()->subDay()->toDateString(),'funding'=>'not_funded'];foreach(['Active','Draft','Closed','Missing'] as $kind){$p=App\Models\SupportPlan::create(['apprentice_id'=>$a->id,'concern_ids'=>[$ids['late']],'draft'=>['fields'=>$fields,'codes'=>[$ids['late']=>'IM36']]]);$p->status=$kind==='Missing'?'Active':$kind;if(in_array($kind,['Active','Closed'])){$p->active_plan=['approved_at'=>today()->subDays(2)->toIso8601String(),'fields'=>$fields,'codes'=>[$ids['late']=>'IM36'],'concern_ids'=>[$ids['late']],'reason'=>'Checked fictional plan','draft_hash'=>'fixture'];}$p->save();$ids[$kind]=$p->id;}echo json_encode($ids);"
        r=subprocess.run([args.php,'-r',code],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert r.returncode==0,'Fictional support setup failed';ids=json.loads(r.stdout);before={t:rows(t) for t in ['support_concerns','support_plans','learning_events','marker_change_events']}
        server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{port}','-t','public'],cwd=project,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        for _ in range(100):
            try:
                if urlopen(origin+'/up',timeout=1).status==200:break
            except Exception:time.sleep(.05)
        _,text,_=req('/login');_,text,url=req('/login',{'_token':token(text),'email':'admin@example.test','password':password});assert not url.endswith('/login');passed('Real administrator session and CSRF sign-in')
        query='search=SUPPORT-FOLLOWUP-TEST'
        _,text,_=req('/support?'+query);assert 'All open concerns (4)' in text and 'Awaiting acknowledgement (1)' in text and 'Action due today (1)' in text and 'Missing action / review dates (2)' in text;Markup(text);passed('Search and overlapping concern counts exclude closed records')
        assert 'PRIVATE SUPPORT INITIAL NOTE' not in text and 'PRIVATE SUPPORT ACTION TEXT' not in text and 'PRIVATE PLAN BARRIERS' not in text and 'PRIVATE ACTIVE PLAN TEXT' not in text;passed('Concern and plan summaries omit sensitive case and plan text')
        assert 'Reviews due (1)' in text and 'Review source / date checks (1)' in text and 'Draft only (1)' in text and 'Closed plans (1)' in text;passed('Active due, draft, source-check and closed plans remain distinct')
        _,text,_=req('/support?'+query+'&state=owners&plan_state=overdue&page=99&plan_page=99');assert 'Owner needs checking' in text and 'Concern follow-up (1)' in text and 'Support-plan review follow-up (1)' in text;Markup(text);passed('Independent filters and clamped pages keep correct scope')
        _,text,_=req('/apprentices/'+str(ids['apprentice'])+'/support#concern-'+str(ids['late']));assert 'PRIVATE SUPPORT INITIAL NOTE' in text;passed('Concern source opens only through the authorised existing record route')
        _,text,_=req('/apprentices/'+str(ids['apprentice'])+'/support-plans/'+str(ids['Active']));assert 'PRIVATE ACTIVE PLAN TEXT' in text;passed('Plan source opens exact existing private record')
        _,text,_=req('/support?'+query+'&state=today&plan_state=checks');assert 'Concern follow-up (1)' in text and 'Support-plan review follow-up (1)' in text;passed('Due-today concern and plan source-check views stay independent')
        _,text,_=req('/support?search=NO-MATCH-SUPPORT');assert 'No concerns match this view' in text and 'No saved plans match this view' in text;passed('Empty lists do not establish complete support coverage')
        assert {t:rows(t) for t in before}==before;passed('Read navigation preserves concerns, plans, versions, markers and audits')
        result={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual localhost HTTP/session/CSRF with disposable fictional SQLite','fixture':'Synthetic source states for read classification; existing tests separately exercise approval/review writes','browserVerified':False,'actualCloudAccess':False};Path(args.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
    finally:
        if server:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
