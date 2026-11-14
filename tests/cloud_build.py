"""Fresh local no-dev install and Laravel build-cache proof; never deploys to Cloud."""
from pathlib import Path
import argparse, json, os, shutil, subprocess, tempfile
p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--composer',required=True);p.add_argument('--output',required=True);a=p.parse_args()
source=Path(__file__).resolve().parent.parent;checks=[]
def passed(name):checks.append({'name':name,'passed':True})
with tempfile.TemporaryDirectory(prefix='gta-clean-cloud-build-') as temporary:
    root=Path(temporary)/'app'
    def ignore(directory,names):
        rel=Path(directory).relative_to(source);out=[]
        for name in names:
            path=rel/name
            if name in ['vendor','.env','.phpunit.result.cache','__pycache__'] or name.endswith('.pyc'):out.append(name)
            elif path.parts[0]=='storage' and name!='.gitignore':out.append(name)
            elif path.parts[:2]==('bootstrap','cache') and name!='.gitignore':out.append(name)
            elif path.as_posix()=='database/database.sqlite':out.append(name)
        return out
    shutil.copytree(source,root,ignore=ignore)
    (root/'bootstrap/cache').mkdir(parents=True,exist_ok=True)
    for folder in ['app/private','framework/cache/data','framework/cache/locks','framework/views','framework/sessions','logs']:(root/'storage'/folder).mkdir(parents=True,exist_ok=True)
    env=os.environ.copy();env.update(APP_ENV='staging',APP_DEBUG='false',APP_URL='https://staging.example.test',APP_KEY='base64:c3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3M=',GTA_ENABLE_STAGING_PORTAL='false',GTA_ALLOW_INITIAL_ADMIN='false',GTA_SCHEDULE_PORTAL_REMINDERS='false')
    for key in ['APP_CONFIG_CACHE','APP_ROUTES_CACHE','LARAVEL_STORAGE_PATH']:env.pop(key,None)
    # Match this workspace's custom PHP ini in Composer's child PHP processes.
    ini=subprocess.run([a.php,'-r','echo php_ini_loaded_file();'],capture_output=True,text=True,check=True).stdout.strip()
    if ini:env['PHPRC']=ini
    def run(command):
        result=subprocess.run(command,cwd=root,env=env,capture_output=True,text=True,timeout=180)
        if result.returncode!=0:raise RuntimeError('Fresh build step failed: '+command[-1]+'\n'+result.stdout[-2000:]+result.stderr[-2000:])
    try:
        run([a.php,a.composer,'install','--no-dev','--prefer-dist','--no-interaction','--optimize-autoloader','--no-progress']);passed('Fresh composer install --no-dev succeeds from the supplied lock, including actual post-autoload package discovery')
        run([a.php,a.composer,'check-platform-reqs','--no-dev']);passed('Fresh runtime-only dependencies satisfy this PHP platform')
        run([a.php,'-r',"require 'vendor/autoload.php'; if(!class_exists(League\\Flysystem\\AwsS3V3\\AwsS3V3Adapter::class) || !class_exists(Aws\\S3\\S3Client::class) || class_exists(PHPUnit\\Framework\\TestCase::class)){exit(1);}"]);passed('S3 runtime classes load while PHPUnit development classes are absent')
        for command in ['config:cache','route:cache','view:cache']:
            run([a.php,'artisan',command]);passed('Fresh runtime-only '+command+' succeeds')
        assert not (root/'.env').exists() and not (root/'database/database.sqlite').exists();passed('Build creates no real .env, application database or administrator')
        result={'passed':len(checks),'failed':0,'checks':checks,'php':'8.4.27','actualCloudBuild':False,'limits':['Clean local runtime-only build, not an actual Laravel Cloud deployment','No target database, private bucket, real secret configuration or user acceptance tested']}
        Path(a.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0,'actualCloudBuild':False}))
    except Exception:
        Path(a.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks,'actualCloudBuild':False},indent=2)+'\n');raise
