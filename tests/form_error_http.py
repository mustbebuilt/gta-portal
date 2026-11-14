"""Real loopback HTTP rejected-form recovery with fictional disposable SQLite.
Checks returned markup/data preservation, not browser focus or assistive technology.
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
with tempfile.TemporaryDirectory(prefix='gta-form-errors-') as temporary:
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
        server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{port}','-t','public'],cwd=project,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        for _ in range(100):
            try:
                if urlopen(origin+'/up',timeout=1).status==200:break
            except OSError:time.sleep(.05)
        else:raise AssertionError('Local PHP fixture did not start')
        _,form,_=req('/login');_,_,url=req('/login',{'_token':token(form),'email':'admin@example.test','password':password});assert not url.endswith('/login');passed('Fictional administrator signs in through actual HTTP and a real session')
        before=rows('apprentices');_,form,_=req('/apprentices/create');assert not Markup(form).summary;passed('A clean apprentice form has no error summary')
        name='Fictional <script>notExecutable()</script>'
        _,text,url=req('/apprentices',{'_token':token(form),'reference':'BAD REFERENCE','first_name':name,'last_name':'Learner','start_date':'2026-11-13','planned_end_date':'2026-11-12','mentor_id':'999999'},'/apprentices/create');assert url.endswith('/apprentices/create');m=check_fields(text,['reference','planned_end_date','mentor_id']);passed('Actual rejected POST redirects to a summary with working IDs and descriptions for text/date/linked-select errors')
        assert m.ids['first_name'][1]['value']==name and '<script>notExecutable()</script>' not in text and m.ids['planned_end_date'][1]['value']=='2026-11-12';passed('Valid entered text/date values survive the redirect and HTML-like text stays escaped')
        assert rows('apprentices')==before;passed('Rejected apprentice submission leaves all original apprentice rows unchanged')
        users_before=rows('users');events_before=rows('directory_events');_,form,_=req('/directories/staff/create');_,text,url=req('/directories/staff',{'_token':token(form),'name':'Fictional Staff','email':'invalid','active':'1','role':'functional_skills_tutor','subject_scope':''},'/directories/staff/create');m=check_fields(text,['email','subject_scope']);assert m.ids['name'][1]['value']=='Fictional Staff';assert 'maths/English subject scope' in text;passed('Staff form rejection provides input/select error links and readable subject-scope guidance')
        assert rows('users')==users_before and rows('directory_events')==events_before;passed('Rejected staff submission creates no account or directory audit')
        with sqlite3.connect(db) as conn:record=conn.execute('SELECT id,reference,first_name,last_name,version FROM apprentices ORDER BY id LIMIT 1').fetchone()
        ident,reference,first,last,version=record;path=f'/apprentices/{ident}/edit';_,form,_=req(path)
        with sqlite3.connect(db) as conn:conn.execute('UPDATE apprentices SET version=version+1 WHERE id=?',(ident,))
        current=rows('apprentices');_,text,url=req(f'/apprentices/{ident}',{'_token':token(form),'_method':'PUT','reference':reference,'first_name':'Unsaved change','last_name':last,'version':str(version)},path);m=Markup(text);assert m.summary and not m.summary_links and 'This record changed while you were editing' in text and rows('apprentices')==current;passed('Stale HTTP edit shows an unlinked conflict message and cannot overwrite the current record')
        for kind in ['employers','staff','groups','programmes']:
            _,text,_=req('/directories/'+kind+'/create');assert not Markup(text).summary
        passed('All four clean directory forms render unique IDs without a stale summary')
        result={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual loopback HTTP, session cookies and CSRF against disposable fictional SQLite','browserVerified':False,'actualCloudAccess':False,'limits':['Validates returned markup and database rows, not native anchor focus, layout, screen reader or touch interaction']};Path(args.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
    finally:
        if server:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
