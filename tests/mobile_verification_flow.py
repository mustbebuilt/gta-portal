"""Separate-process local verification proof. Uses HTTP fakes, never real SMS or provider TLS."""
from pathlib import Path
import argparse,base64,json,os,secrets,subprocess,tempfile
parser=argparse.ArgumentParser();parser.add_argument('--php',default='php');parser.add_argument('--output',required=True);args=parser.parse_args()
root=Path(__file__).resolve().parent.parent;checks=[]
def passed(name):checks.append({'name':name,'passed':True})
with tempfile.TemporaryDirectory(prefix='gta-mobile-proof-')as temporary:
 temp=Path(temporary);temp.chmod(0o700);db=temp/'fictional.sqlite';db.touch();storage=temp/'storage'
 for part in ['app/private','framework/sessions','framework/cache/data','framework/views','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
 env=os.environ.copy();env.update(APP_ENV='staging',APP_DEBUG='false',APP_URL='https://fictional.example.test',APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),DB_CONNECTION='sqlite',DB_DATABASE=str(db),SESSION_DRIVER='database',SESSION_ENCRYPT='true',SESSION_SECURE_COOKIE='true',CACHE_STORE='database',MAIL_MAILER='array',QUEUE_CONNECTION='sync',GTA_ENABLE_STAGING_PORTAL='true',GTA_SMS_MOBILE_VERIFICATION_ENABLED='true',GTA_SMS_MOBILE_VERIFICATION_RECIPIENTS='+447700900123',VONAGE_KEY='FAKEKEY1',VONAGE_SECRET='FICTIONAL-SECRET',VONAGE_SMS_FROM='GTA',LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(temp/'config.php'),APP_ROUTES_CACHE=str(temp/'routes.php'),GTA_MOBILE_FIXTURE='1',GTA_MOBILE_FIXTURE_STATE=str(temp/'private-state.json'))
 def run(*command):
  r=subprocess.run([args.php,*command],cwd=root,env=env,capture_output=True,text=True,timeout=60);assert r.returncode==0,'Private verification fixture failed';return r
 def phase(name):return json.loads(run('tests/mobile_verification_fixture.php',name).stdout)
 try:
  run('artisan','migrate','--force','--no-interaction');assert phase('seed')['seeded'];passed('Disposable fictional apprentice/profile/encrypted database session created in isolated resources')
  issued=phase('issue');assert issued['state']=='Accepted' and issued['committed_reservation_seen_by_independent_connection'];passed('Independent SQLite connection sees committed Reserved row before the simulated provider accepts the request')
  assert not issued['code_plaintext_stored'] and not issued['phone_plaintext_stored'];passed('Challenge persists neither raw OTP nor raw phone; provider/code capture remains private temporary test state')
  r=phase('wrong');assert r['rejected'] and r['attempts']==1 and r['state']=='Accepted' and not r['verified'];passed('New PHP process loads the same session/challenge and commits a wrong-code attempt without verification')
  r=phase('verify');assert not r['rejected'] and r['verified'] and r['state']=='Consumed' and r['code_hash_cleared'] and r['channel']=='both' and r['enabled'];passed('Later PHP process verifies the code once, clears its hash and preserves Both/On preference')
  r=phase('replay');assert r['rejected'] and r['state']=='Consumed';passed('Consumed code cannot be reused after application restart')
  diag=json.loads(run('artisan','gta:mobile-verification-check','--history','--no-interaction').stdout);assert diag['result']=='configuration_checks_only' and diag['history']['states']['Consumed']==1 and '+447700900123'not in json.dumps(diag) and 'FICTIONAL-SECRET'not in json.dumps(diag);passed('Actual CLI diagnostic/history reports aggregate state and configuration only, with no secrets or contact values')
  assert phase('phone_change')['verification_cleared'];passed('A later saved phone change clears verification and preserves the preference workflow')
  output={'passed':len(checks),'failed':0,'checks':checks,'transport':'Separate PHP processes, disposable SQLite/database sessions/cache and Laravel HTTP fake','realDataUsed':False,'externalMessagesSent':False,'actualCloudAccess':False,'actualNativeEngine':False,'actualProviderTLSOrDelivery':False,'limits':['HTTP fake proves application protocol handling, not actual provider/network/handset behaviour','Native locks/concurrency and browser/phone acceptance remain','Automatic task/review reminder pipeline is not implemented by this verification workstream']}
  Path(args.output).write_text(json.dumps(output,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
 except Exception:
  Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
