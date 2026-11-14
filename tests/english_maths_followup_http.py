"""Real loopback HTTP Maths and English follow-up batches with fictional disposable SQLite.
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
with tempfile.TemporaryDirectory(prefix='gta-em-followup-') as temporary:
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
        code=r"require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$u=App\Models\User::where('email','admin@example.test')->firstOrFail();$a=App\Models\Apprentice::factory()->create(['first_name'=>'Fictional','last_name'=>'EM followup','reference'=>'EM-FOLLOWUP-TEST']);$q=App\Models\Qualification::factory()->create(['apprentice_id'=>$a->id,'start_date'=>today()->subYear()->subDay(),'notes'=>'PRIVATE EM NOTES']);$t=App\Models\TrainingTopic::factory()->create(['apprentice_id'=>$a->id,'topic'=>'EM waiting topic']);$t->evidence_stage='Awaiting review';$t->draft='PRIVATE EM DRAFT';$t->save();echo json_encode(['apprentice'=>$a->id,'topic'=>$t->id]);"
        r=subprocess.run([args.php,'-r',code],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert r.returncode==0,'Fictional EM setup failed: '+r.stderr;ids=json.loads(r.stdout);before={t:rows(t) for t in ['qualifications','training_topics','learning_events']}
        server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{port}','-t','public'],cwd=project,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        for _ in range(100):
            try:
                if urlopen(origin+'/up',timeout=1).status==200:break
            except Exception:time.sleep(.05)
        _,text,_=req('/login');req('/login',{'_token':token(text),'email':'admin@example.test','password':password});passed('Real session and CSRF sign-in')
        _,text,_=req('/maths-english?search=EM-FOLLOWUP-TEST&subject=maths&view=overdue');assert 'EM-FOLLOWUP-TEST' in text and 'Qualification overdue (1)' in text and 'Topic evidence awaiting review (1)' in text;Markup(text);passed('Search and subject filters show overlapping learner counts')
        assert 'PRIVATE EM NOTES' not in text and 'PRIVATE EM DRAFT' not in text;passed('Caseload excludes private notes and topic bodies')
        from html import unescape
        source='/apprentices/'+str(ids['apprentice'])+'/maths-english'
        assert source+'?subject=maths&show=overdue' in unescape(text);_,text,_=req(source+'?subject=maths&show=overdue');assert 'Overdue' in text;passed('Qualification follow-up link opens the existing subject source')
        _,text,_=req(source+'?subject=maths&topics=awaiting');assert 'EM waiting topic' in text and '/topics/'+str(ids['topic']) in text;Markup(text);passed('Awaiting evidence filter retains an authorised topic link')
        _,text,_=req('/topics/'+str(ids['topic']));assert 'EM waiting topic' in text;passed('Topic source opens through actual HTTP')
        _,text,_=req('/maths-english?search=NO-MATCH-EM-XYZ');assert 'No matching apprentices' in text;passed('Empty search does not imply completed training')
        assert {t:rows(t) for t in before}==before;passed('Read workflows preserve qualification, topic and audit rows')
        result={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual localhost HTTP/session/CSRF with disposable fictional SQLite','browserVerified':False,'actualCloudAccess':False};Path(args.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
    finally:
        if server:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
