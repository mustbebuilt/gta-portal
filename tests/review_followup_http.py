"""Real loopback HTTP review follow-up batches with fictional disposable SQLite.
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
with tempfile.TemporaryDirectory(prefix='gta-review-followup-') as temporary:
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
        code=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$u=App\Models\User::factory()->create(['role'=>'tutor']);$m=App\Models\User::factory()->create(['role'=>'mentor']);$admin=App\Models\User::where('role','admin')->firstOrFail();$checker=App\Models\User::factory()->create(['role'=>'ceo']);$g=App\Models\TutorGroup::factory()->create();$ids=['group'=>$g->id];foreach([['late',80,'Active'],['today',70,'Active'],['paused',80,'Completed'],['history',null,'Active']] as [$kind,$days,$status]){$a=App\Models\Apprentice::factory()->create(['tutor_id'=>$u->id,'mentor_id'=>$m->id,'tutor_group_id'=>$g->id,'start_date'=>today()->subYear()]);App\Models\ProgrammeOutcome::create(['apprentice_id'=>$a->id,'context'=>['status'=>$status,'sourceRef'=>'Fictional enrolment source']]);$date=$days===null?null:today()->subDays($days)->toDateString();$r=App\Models\ProgressReviewDraft::factory()->create(['apprentice_id'=>$a->id,'author_id'=>$u->id,'fields'=>['reviewDate'=>$date,'summary'=>'PRIVATE REVIEW SUMMARY DO NOT PRINT'],'source_snapshot'=>['whole_plan'=>'Fictional fixture snapshot','captured_at'=>now()->toIso8601String(),'assigned_tasks'=>0]]);if($date){$r->state='Ready for signatures';$r->save();$hash=str_repeat('a',64);$snap=['apprentice_id'=>$a->id,'review_id'=>$r->id,'review_version'=>1,'file_sha256'=>$hash,'fields'=>['reviewDate'=>$date,'private'=>'PRIVATE SIGNER DETAILS']];App\Models\SignedReviewRecord::create(['apprentice_id'=>$a->id,'progress_review_draft_id'=>$r->id,'review_version'=>1,'meeting_date'=>$date,'snapshot'=>$snap,'snapshot_hash'=>App\Support\ReviewedImports::hash($snap),'file'=>['sha256'=>$hash],'prepared_by'=>$admin->id,'checked_by'=>$checker->id,'checked_at'=>now(),'history'=>[]]);}$ids[$kind]=$a->id;$ids[$kind.'_draft']=$r->id;}echo json_encode($ids);"
        r=subprocess.run([args.php,'-r',code],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert r.returncode==0,'Synthetic review metadata setup failed';ids=json.loads(r.stdout);before={t:rows(t) for t in ['progress_review_drafts','signed_review_records','review_reminders']}
        server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{port}','-t','public'],cwd=project,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        for _ in range(100):
            try:
                if urlopen(origin+'/up',timeout=1).status==200:break
            except OSError:time.sleep(.05)
        else:raise AssertionError('Local PHP fixture did not start')
        _,form,_=req('/login');_,_,url=req('/login',{'_token':token(form),'email':'admin@example.test','password':password});assert not url.endswith('/login');passed('Actual administrator sign-in uses a private fictional session')
        group='group='+str(ids['group']);_,text,_=req('/reviews?'+group+'&view=due');assert 'data-review-apprentice="'+str(ids['late'])+'"' in text and 'data-review-apprentice="'+str(ids['today'])+'"' in text and 'data-review-apprentice="'+str(ids['paused'])+'"' not in text and 'data-review-apprentice="'+str(ids['history'])+'"' not in text;passed('Due view includes checked active due references and excludes paused/unknown-history rows')
        assert '<strong>1</strong> overdue checked references' in text and '<strong>1</strong> due today' in text and '<strong>1</strong> status/history checks' in text and '<strong>1</strong> paused/non-active' in text;passed('Search/scope summary distinguishes overdue, today, missing history and paused status')
        assert 'PRIVATE REVIEW SUMMARY DO NOT PRINT' not in text and 'PRIVATE SIGNER DETAILS' not in text and 'regardless of the filters above' in text;passed('Queue omits private draft/signer text and makes global reminder scope explicit')
        _,text,_=req('/dashboard?'+group);assert 'id="dashboard-reviews-title"' in text and 'group='+str(ids['group'])+'&amp;view=overdue' in text;passed('Dashboard review links retain the selected group scope')
        _,text,_=req('/reviews?'+group+'&view=checks');assert 'data-review-apprentice="'+str(ids['history'])+'"' in text and 'data-review-apprentice="'+str(ids['late'])+'"' not in text;passed('History-check filter does not turn a provisional date into a confirmed due date')
        path='/apprentices/'+str(ids['late'])+'/reviews/'+str(ids['late_draft']);_,text,_=req('/reviews?apprentice='+str(ids['late']));assert origin+path in text and 'Continue latest draft' in text;status,_,_=req(path);assert status==200;passed('Most recent draft shortcut opens its actual authorised source route')
        assert {t:rows(t) for t in before}==before;passed('Worklist/dashboard/source reads preserve draft and signed-copy records and create no reminders')
        result={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual localhost HTTP/session/CSRF with disposable fictional SQLite','fixture':'Synthetic hash-bound checked-copy metadata; actual upload/independent-check workflows covered separately by existing suite','browserVerified':False,'actualCloudAccess':False,'limits':['No real signature/provenance claim from synthetic fixture','Not browser/device/screen-reader/layout acceptance']};Path(args.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
    finally:
        if server:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
