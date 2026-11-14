"""Run the application suite on a freshly initialised private local MariaDB daemon.
Never connects to an existing database; creates and removes its own socket/data/schema/user.
"""
from pathlib import Path
import argparse,json,os,re,secrets,signal,socket as sockets,subprocess,sys,tempfile,time,xml.etree.ElementTree as ET
p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--server-root',required=True);p.add_argument('--output',required=True);p.add_argument('--filter');p.add_argument('--http-only',action='store_true');p.add_argument('--python-deps');a=p.parse_args()
project=Path(__file__).resolve().parent.parent;server_root=Path(a.server_root).resolve();server=None
with tempfile.TemporaryDirectory(prefix='gta-mariadb-') as temporary:
    temp=Path(temporary);data=temp/'data';data.mkdir();listener=sockets.socket();listener.bind(('127.0.0.1',0));listener.listen(64);port=listener.getsockname()[1];nonce=secrets.token_hex(8);database='gta_test_'+nonce;username=database;password=secrets.token_hex(24);token=secrets.token_hex(24)
    env=os.environ.copy();env['LD_LIBRARY_PATH']=str(server_root/'lib/x86_64-linux-gnu')+':'+str(server_root.parent/'lib/x86_64-linux-gnu')+':'+env.get('LD_LIBRARY_PATH','')
    server_binary=server_root/'sbin/mariadbd';client=server_root/'bin/mariadb';install=server_root/'bin/mariadb-install-db'
    def sql(text):
        r=subprocess.run([str(client),'--no-defaults','--protocol=tcp','--host=127.0.0.1','--port='+str(port),'--user=root','--batch','--skip-column-names'],env=env,input=text,capture_output=True,text=True,timeout=30)
        if r.returncode:raise RuntimeError('Private local database setup/query failed')
        return r.stdout.strip()
    try:
        init=subprocess.run([str(install),'--no-defaults','--basedir='+str(server_root),'--datadir='+str(data),'--auth-root-authentication-method=normal','--skip-test-db','--skip-name-resolve'],env=env,capture_output=True,text=True,timeout=60)
        if init.returncode:raise RuntimeError('Private MariaDB initialisation failed: '+init.stderr[-1500:]+init.stdout[-1500:])
        logfile=open(temp/'server.log','w')
        command=[str(server_binary),'--no-defaults','--basedir='+str(server_root),'--datadir='+str(data),'--pid-file='+str(temp/'server.pid'),'--bind-address=127.0.0.1','--port='+str(port),'--skip-name-resolve','--innodb-buffer-pool-size=64M','--innodb-flush-log-at-trx-commit=2','--performance-schema=OFF','--log-error='+str(temp/'mariadb.log')]
        # Official socket activation accepts only the already-bound loopback TCP descriptor.
        launch="import os,sys;fd=int(sys.argv[1]);os.dup2(fd,3);os.set_inheritable(3,True);e=os.environ.copy();e['LISTEN_FDS']='1';e['LISTEN_PID']=str(os.getpid());os.execve(sys.argv[2],sys.argv[2:],e)"
        server=subprocess.Popen([sys.executable,'-c',launch,str(listener.fileno()),*command],env=env,pass_fds=(listener.fileno(),),stdout=logfile,stderr=logfile,start_new_session=True)
        listener.close()
        for _ in range(200):
            if server.poll() is not None:raise RuntimeError('Private daemon failed: '+(temp/'mariadb.log').read_text()[-1500:])
            try:
                if sql('SELECT 1;')=='1':break
            except RuntimeError:pass
            time.sleep(.05)
        else:raise RuntimeError('Private MariaDB startup timed out')
        version=sql('SELECT VERSION();')
        sql(f"CREATE DATABASE `{database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER '{username}'@'127.0.0.1' IDENTIFIED BY '{password}'; GRANT ALL PRIVILEGES ON `{database}`.* TO '{username}'@'127.0.0.1';")
        marker={'mode':'local-disposable','database':database,'username':username,'port':port,'token':token};marker_file=temp/'isolated-run.json';marker_file.write_text(json.dumps(marker));marker_file.chmod(0o600)
        tree=ET.parse(project/'phpunit.xml');xml=tree.getroot();xml.set('bootstrap',str(project/'vendor/autoload.php'))
        for node in xml.findall('.//directory'):
            if node.text:node.text=str(project/node.text)
        values={'APP_ENV':'testing','DB_CONNECTION':'mysql','DB_HOST':'127.0.0.1','DB_DATABASE':database,'DB_USERNAME':username,'DB_PASSWORD':password,'DB_SOCKET':'','DB_PORT':str(port),'GTA_ENGINE_MARKER':str(marker_file),'DB_URL':'','GTA_ENGINE_TESTS':'1','GTA_ENGINE_TOKEN':token}
        section=xml.find('php')
        for key,value in values.items():
            node=next((n for n in section.findall('env') if n.get('name')==key),None)
            if node is None:node=ET.SubElement(section,'env')
            node.set('name',key);node.set('value',value);node.set('force','true')
        config=temp/'phpunit.xml';tree.write(config,encoding='unicode');config.chmod(0o600)
        storage=temp/'storage'
        for folder in ['app/private','framework/cache/data','framework/cache/locks','framework/views','framework/sessions','logs']:(storage/folder).mkdir(parents=True,exist_ok=True)
        run_env=os.environ.copy();run_env.update(values,APP_KEY='base64:c3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3M=',LARAVEL_STORAGE_PATH=str(storage));run_env.pop('APP_CONFIG_CACHE',None);run_env.pop('APP_ROUTES_CACHE',None)
        command=[a.php,str(project/'vendor/bin/phpunit'),'-c',str(config),'--colors=never']
        if a.filter:command+=['--filter',a.filter]
        started=time.monotonic();http_result=None
        if a.http_only:
            http_db='gta_test_'+secrets.token_hex(8);sql(f"CREATE DATABASE `{http_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON `{http_db}`.* TO '{username}'@'127.0.0.1';")
            marker['http_database']=http_db;marker_file.write_text(json.dumps(marker));marker_file.chmod(0o600)
            http_env=run_env.copy();http_env.update(GTA_HTTP_MYSQL_HOST='127.0.0.1',GTA_HTTP_MYSQL_PORT=str(port),GTA_HTTP_MYSQL_DATABASE=http_db,GTA_HTTP_MYSQL_USER=username,GTA_HTTP_MYSQL_PASSWORD=password)
            if a.python_deps:http_env['PYTHONPATH']=str(Path(a.python_deps).resolve())+os.pathsep+http_env.get('PYTHONPATH','')
            result=subprocess.run([sys.executable,str(project/'tests/http_persistence.py'),'--php',a.php,'--output',str(temp/'http.json'),'--mysql-fixture'],cwd=project,env=http_env,capture_output=True,text=True,timeout=600)
            if result.returncode:raise RuntimeError('Native HTTP fixture failed: '+result.stderr[-2500:]+result.stdout[-1000:])
            http_result=json.loads((temp/'http.json').read_text());suite={'result':'not_run','tests':0,'passed':0,'assertions':0}
        else:
            result=subprocess.run(command,cwd=project,env=run_env,capture_output=True,text=True,timeout=1800)
            try:suite=json.loads(result.stdout)
            except json.JSONDecodeError:raise RuntimeError('Native suite did not return structured results: '+result.stderr[-1800:]+result.stdout[-1800:])
        if password in result.stdout+result.stderr or token in result.stdout+result.stderr:raise RuntimeError('Fixture secret must not appear in test output')
        # A second private schema proves the once-only bootstrap across two PHP processes.
        race_db='gta_test_'+secrets.token_hex(8)
        sql(f"CREATE DATABASE `{race_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON `{race_db}`.* TO '{username}'@'127.0.0.1';")
        marker['race_database']=race_db;marker_file.write_text(json.dumps(marker));marker_file.chmod(0o600)
        race_env=run_env.copy();race_env.update(DB_DATABASE=race_db,APP_ENV='staging',APP_DEBUG='false',APP_URL='https://staging.example.test',SESSION_DRIVER='database',SESSION_ENCRYPT='true',SESSION_SECURE_COOKIE='true',CACHE_STORE='database',GTA_ENABLE_STAGING_PORTAL='true',GTA_ALLOW_INITIAL_ADMIN='true',GTA_RACE_DIRECTORY=str(temp),BCRYPT_ROUNDS='10')
        migrate=subprocess.run([a.php,'artisan','migrate','--force','--no-interaction'],cwd=project,env=race_env,capture_output=True,text=True,timeout=60)
        if migrate.returncode:raise RuntimeError('Disposable race schema migration failed')
        credentials=json.dumps({'password':'Fictional-private-race-passphrase-30!','password_confirmation':'Fictional-private-race-passphrase-30!'})
        workers=[]
        try:
            for _ in range(2):
                worker=subprocess.Popen([a.php,str(project/'tests/initial_admin_race.php')],cwd=project,env=race_env,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
                worker.stdin.write(credentials);worker.stdin.close();worker.stdin=None;workers.append(worker)
            for _ in range(200):
                if len(list(temp.glob('ready-*')))==2:break
                if any(w.poll() is not None for w in workers):raise RuntimeError('Private race worker stopped before barrier')
                time.sleep(.025)
            else:raise RuntimeError('Private race barrier timed out')
            (temp/'start-race').write_text('start')
            outcomes=[]
            for worker in workers:
                stdout,stderr=worker.communicate(timeout=30)
                if worker.returncode:raise RuntimeError('Native race worker failed without exposing credentials')
                outcomes.append(json.loads(stdout)['result'])
            if sorted(outcomes)!=['created','rejected']:raise RuntimeError('Expected exactly one native bootstrap winner')
            counts=sql(f"SELECT (SELECT COUNT(*) FROM `{race_db}`.users),(SELECT COUNT(*) FROM `{race_db}`.account_access_events WHERE event='initial_admin_created'),(SELECT COUNT(*) FROM `{race_db}`.portal_bootstrap_states WHERE completed_by IS NOT NULL AND completed_at IS NOT NULL);")
            if counts!='1\t1\t1':raise RuntimeError('Native bootstrap race did not retain exactly one account/audit/marker')
            concurrency={'result':'passed','attempts':2,'created':1,'rejected':1,'accounts':1,'creationAudits':1,'consumedMarkers':1,'scope':'Real concurrent PHP workers released from a common barrier on a separate native InnoDB schema; other concurrency paths not certified'}
        finally:
            for worker in workers:
                if worker.poll() is None:worker.terminate();worker.wait(timeout=10)
        report={'engine':version,'driver':'pdo_mysql / Laravel mysql connection','suite':suite,'http':http_result,'seconds':round(time.monotonic()-started,2),'isolated':True,'network':'Private disposable 127.0.0.1 TCP listener only, passed through socket activation; no external listener','schemaReset':'Fresh private schema per database-backed test; no false enclosing transaction around DDL','nativeAuditErrors':True,'initialAdminConcurrency':concurrency,'actualCloudAccess':False,'actualOracleMySqlAccess':False,'limits':['MariaDB is not the exact Cloud MySQL version; actual Cloud engine/schema acceptance remains','No existing/live database or real data accessed','This suite does not establish all target concurrency, performance, backup or migration mappings']}
        Path(a.output).write_text(json.dumps(report,indent=2)+'\n');print(json.dumps({'engine':version,'mode':'http' if a.http_only else 'suite','result':'passed' if a.http_only and http_result['failed']==0 else suite.get('result'),'tests':suite.get('tests'),'passed':http_result['passed'] if a.http_only else suite.get('passed'),'assertions':suite.get('assertions'),'concurrency':concurrency['result'],'seconds':report['seconds']}))
        if result.returncode:raise SystemExit(result.returncode)
    finally:
        if server and server.poll() is None:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=30)
