"""Actual local CLI/metadata diagnostics on disposable SQLite; NOT native MySQL/Cloud acceptance."""
from pathlib import Path
import argparse,base64,hashlib,json,os,secrets,subprocess,tempfile
parser=argparse.ArgumentParser();parser.add_argument('--php',default='php');parser.add_argument('--output',required=True);args=parser.parse_args()
root=Path(__file__).resolve().parent.parent;checks=[]
def passed(name):checks.append({'name':name,'passed':True})
with tempfile.TemporaryDirectory(prefix='gta-schema-diagnostic-')as temporary:
 temp=Path(temporary);db=temp/'fictional.sqlite';db.touch();storage=temp/'storage'
 for part in ['app/private','framework/sessions','framework/cache/data','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
 secret='Fictional-secret-'+secrets.token_urlsafe(18);private_name='Fictional-private-learner-'+secrets.token_hex(12)
 env=os.environ.copy();env.update(APP_ENV='staging',APP_DEBUG='false',APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),APP_URL='https://fictional.example.test',DB_CONNECTION='sqlite',DB_DATABASE=str(db),DB_PASSWORD=secret,SESSION_DRIVER='array',CACHE_STORE='array',MAIL_MAILER='array',QUEUE_CONNECTION='sync',LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(temp/'config.php'),APP_ROUTES_CACHE=str(temp/'routes.php'),GTA_FIXTURE_NAME=private_name)
 bootstrap=r"require 'vendor/autoload.php';$a=require 'bootstrap/app.php';$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();"
 def run(command,custom=None):
  result=subprocess.run([args.php,*command],cwd=root,env=custom or env,capture_output=True,text=True,timeout=60)
  assert secret not in result.stdout and private_name not in result.stdout,'Diagnostic exposed fixture values';return result
 def script(body):
  r=run(['-r',bootstrap+body]);assert r.returncode==0,'Fixture PHP failed';return json.loads(r.stdout or '{}')
 def digest():return hashlib.sha256(db.read_bytes()).hexdigest()
 def cli(custom,*flags):
  r=run(['artisan','gta:cloud-check',*flags,'--no-interaction'],custom);assert r.returncode==1,'Incomplete local configuration must not be declared ready';return json.loads(r.stdout)
 def metadata():
  before=digest();r=script(r"echo json_encode(App\Support\ApplicationSchema::inspectSchema(Illuminate\Support\Facades\Schema::getFacadeRoot()));")
  assert digest()==before,'Metadata probe changed SQLite bytes';return r
 try:
  r=run(['artisan','migrate','--force','--no-interaction']);assert r.returncode==0,'Disposable migration failed'
  script(r"App\Models\User::factory()->create(['role'=>'admin']);App\Models\Apprentice::factory()->create(['first_name'=>getenv('GTA_FIXTURE_NAME')]);echo '{}';")
  mysql=env.copy();mysql.update(DB_CONNECTION='mysql',DB_DATABASE='fictional_no_connection',DB_HOST='fictional-unreachable.invalid')
  before=digest();r=cli(mysql);assert r['schema_presence']['status']=='not_checked' and r['schema_presence']['tables_checked']==0 and digest()==before
  passed('Actual default CLI marks database/schema not checked and does not require a working connection')
  r=cli(env,'--database');assert r['schema_presence']['reason']=='configured_driver_not_supported' and r['schema_presence']['tables_checked']==0 and digest()==before
  passed('Explicit staging CLI refuses SQLite fallback and preserves the complete disposable database bytes')
  production=mysql.copy();production['APP_ENV']='production';r=cli(production,'--database');assert r['schema_presence']['reason']=='staging_required' and digest()==before
  passed('Explicit probe in production refuses before database metadata access; no values or credentials emitted')
  r=cli(mysql,'--database');assert r['schema_presence']['status']=='blocked' and r['schema_presence']['reason']=='database_probe_failed' and digest()==before
  passed('Actual unavailable native connection fails closed with sanitised structured output, not a native success claim')
  r=metadata();assert r['status']=='pass' and r['required_tables']==73 and r['required_columns']==704
  passed('All 73 reference tables and 704 required column names are present on actual freshly migrated disposable SQLite')
  script(r"Illuminate\Support\Facades\Schema::table('staff_duties',fn($t)=>$t->dropColumn(['maths_tutor','english_tutor']));echo '{}';")
  r=metadata();assert r['status']=='blocked' and r['missing_columns']['staff_duties']==['english_tutor','maths_tutor']
  names=script(r"echo json_encode(['pending'=>array_values(array_diff(array_keys(app('migrator')->getMigrationFiles([database_path('migrations')])),app('migration.repository')->getRan()))]);")
  assert names['pending']==[]
  passed('Missing subject columns are caught although every reference migration name is recorded; no repair is performed')
  script(r"Illuminate\Support\Facades\Schema::table('staff_duties',function($t){$t->boolean('maths_tutor')->default(false);$t->boolean('english_tutor')->default(false);});Illuminate\Support\Facades\Schema::create('fictional_extra',fn($t)=>$t->id());echo '{}';")
  assert metadata()['status']=='pass'
  passed('Explicit fixture restoration and an extra target table are accepted without treating extra schema as a failure')
  script(r"Illuminate\Support\Facades\Schema::drop('staff_duties');echo '{}';")
  r=metadata();assert r['status']=='blocked' and 'staff_duties'in r['missing_tables']
  passed('Missing table is identified by static contract name and remains absent after the read-only probe')
  output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual local PHP CLI and disposable SQLite metadata','realDataUsed':False,'externalMessagesSent':False,'actualCloudAccess':False,'actualNativeEngine':False,'nativeDriverFailureOnly':True,'limits':['Positive schema checks use SQLite metadata, not native MySQL/MariaDB','Configured native CLI connection is intentionally unavailable; its failure is tested, not native success','No types/indexes/constraints/data or staging release acceptance certified']}
  Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
 except Exception:
  Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
