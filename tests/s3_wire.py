"""Actual locked PHP SDK HTTP requests against a disposable signed S3 fixture.
This is an API-contract fixture, not Laravel Cloud, R2 or AWS acceptance.
"""
from pathlib import Path
import argparse, base64, hashlib, hmac, http.server, json, os, re, socket, subprocess, tempfile, threading
from urllib.parse import urlsplit, parse_qsl, quote, unquote

parser=argparse.ArgumentParser();parser.add_argument('--php',default='php');parser.add_argument('--output',required=True);args=parser.parse_args()
project=Path(__file__).resolve().parent.parent;checks=[];requests=[];mode='normal'
access='GTAFICTIONALACCESS';secret='Fictional-s3-wire-secret-only'
def passed(name):checks.append({'name':name,'passed':True})
def digest(value):return hashlib.sha256(value).hexdigest()

with tempfile.TemporaryDirectory(prefix='gta-s3-wire-') as temporary:
    temp=Path(temporary);objects=temp/'objects';objects.mkdir();storage=temp/'app-storage'
    for part in ['app/private','framework/cache/data','framework/cache/locks','framework/views','framework/sessions','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
    class S3(http.server.BaseHTTPRequestHandler):
        protocol_version='HTTP/1.1'
        def log_message(self,*args):pass
        def reply(self,status,body=b'',content_type='application/xml',size=None):
            self.send_response(status);self.send_header('Content-Type',content_type);self.send_header('Content-Length',str(len(body) if size is None else size));self.send_header('ETag','"fictional"');self.end_headers()
            if self.command!='HEAD':self.wfile.write(body)
        def body(self):
            if self.headers.get('Transfer-Encoding','').lower()=='chunked':
                chunks=[]
                while True:
                    length=int(self.rfile.readline().split(b';')[0].strip(),16)
                    if not length:
                        while self.rfile.readline().strip():pass
                        break
                    chunks.append(self.rfile.read(length));self.rfile.read(2)
                return b''.join(chunks)
            return self.rfile.read(int(self.headers.get('Content-Length','0')))
        def authorised(self,body):
            auth=self.headers.get('Authorization','');m=re.match(r'AWS4-HMAC-SHA256 Credential=([^/]+)/([^,]+),\s*SignedHeaders=([^,]+),\s*Signature=([a-f0-9]+)',auth)
            if not m or m[1]!=access:return False
            scope=m[2];signed=m[3];date,region,service,term=scope.split('/')
            if service!='s3' or term!='aws4_request':return False
            headers=''.join(k+':'+re.sub(r'\s+',' ',self.headers.get(k,'').strip())+'\n' for k in signed.split(';'))
            parts=urlsplit(self.path);query='&'.join(quote(k,safe='-_.~')+'='+quote(v,safe='-_.~') for k,v in sorted(parse_qsl(parts.query,keep_blank_values=True)))
            canonical='\n'.join([self.command,parts.path,query,headers,signed,self.headers.get('x-amz-content-sha256',digest(body))])
            string='\n'.join(['AWS4-HMAC-SHA256',self.headers.get('x-amz-date',''),scope,digest(canonical.encode())])
            key=('AWS4'+secret).encode()
            for part in [date,region,service,term]:key=hmac.new(key,part.encode(),hashlib.sha256).digest()
            return hmac.compare_digest(hmac.new(key,string.encode(),hashlib.sha256).hexdigest(),m[4])
        def handle_object(self):
            global mode
            raw=self.body();signed=self.authorised(raw);acl=any(k.lower().startswith(('x-amz-acl','x-amz-grant-')) for k in self.headers)
            parts=urlsplit(self.path);path=unquote(parts.path).lstrip('/');target=objects/path
            if '..' in Path(path).parts:self.reply(400);return
            requests.append({'method':self.command,'signed':signed,'acl':acl,'anonymous':not bool(self.headers.get('Authorization')),'conditional_create':self.headers.get('If-None-Match')=='*'})
            if acl:self.reply(501,b'<Error><Code>NotImplemented</Code></Error>');return
            if not signed:
                if mode=='public' and self.command=='GET' and target.is_file():self.reply(200,target.read_bytes(),'text/plain')
                elif mode=='redirect' and self.command=='GET':
                    self.send_response(302);self.send_header('Location','http://127.0.0.1:'+str(self.server.server_port)+'/must-not-follow');self.send_header('Content-Length','0');self.end_headers()
                else:self.reply(403,b'<Error><Code>AccessDenied</Code></Error>')
                return
            if self.command=='PUT':
                if self.headers.get('If-None-Match')=='*' and target.exists():self.reply(412,b'<Error><Code>PreconditionFailed</Code></Error>');return
                data=raw
                if self.headers.get('Content-Encoding','').lower()=='aws-chunked':
                    decoded=[];offset=0
                    while True:
                        end=data.index(b'\r\n',offset);size=int(data[offset:end].split(b';')[0],16);offset=end+2
                        if not size:break
                        decoded.append(data[offset:offset+size]);offset+=size+2
                    data=b''.join(decoded)
                target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(data);self.reply(200)
            elif self.command=='DELETE':
                if mode=='deny-delete':self.reply(403,b'<Error><Code>AccessDenied</Code></Error>')
                else:target.unlink(missing_ok=True);self.reply(204)
            elif not target.is_file():self.reply(404,b'<Error><Code>NoSuchKey</Code></Error>')
            elif self.command=='HEAD':self.reply(200,b'',content_type='text/plain',size=target.stat().st_size)
            elif self.command=='GET':self.reply(200,b'Changed fictional bytes' if mode=='corrupt' else target.read_bytes(),'text/plain')
            else:self.reply(400)
        do_GET=handle_object;do_PUT=handle_object;do_HEAD=handle_object;do_DELETE=handle_object
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),S3);thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    env=os.environ.copy();env.update(APP_ENV='local',APP_DEBUG='false',APP_KEY='base64:'+base64.b64encode(bytes(range(32))).decode(),APP_CONFIG_CACHE=str(temp/'config.php'),LARAVEL_STORAGE_PATH=str(storage),GTA_FIXTURE_ENDPOINT=f'http://127.0.0.1:{server.server_port}')
    bootstrap="require 'vendor/autoload.php';$a=require 'bootstrap/app.php';$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
    setup=bootstrap+"$c=$a['config']->all();$disk=['driver'=>'s3','key'=>'GTAFICTIONALACCESS','secret'=>'Fictional-s3-wire-secret-only','region'=>'us-east-1','bucket'=>'fictional-private','endpoint'=>getenv('GTA_FIXTURE_ENDPOINT'),'use_path_style_endpoint'=>true,'throw'=>true,'root'=>'evidence-prefix'];$c['filesystems']['disks']['fixture_s3']=$disk;$c['filesystems']['disks']['second_s3']=$disk;$c['filesystems']['disks']['second_s3']['bucket']='different-private';$c['evidence']=['disk'=>'fixture_s3','legacy_disk'=>'local','private_disks'=>['local','fixture_s3','second_s3']];file_put_contents(getenv('APP_CONFIG_CACHE'),'<?php return '.var_export($c,true).';');"
    def php(code):
        result=subprocess.run([args.php,'-r',bootstrap+code],cwd=project,env=env,capture_output=True,text=True,timeout=60)
        assert result.returncode==0,'PHP wire harness failed; inspect locally without exposing credentials'
        return result.stdout
    def probe():
        result=subprocess.run([args.php,'artisan','gta:evidence-storage','--probe','--no-interaction'],cwd=project,env=env,capture_output=True,text=True,timeout=60)
        assert secret not in result.stdout+result.stderr
        return result.returncode,json.loads(result.stdout)
    try:
        setup_result=subprocess.run([args.php,'-r',setup],cwd=project,env=env,capture_output=True,text=True,timeout=30);assert setup_result.returncode==0
        inspected=json.loads(php('echo json_encode(App\\Support\\EvidenceStorage::inspect());'));assert inspected['result']=='configuration_present' and not requests
        passed('Configuration-only check creates no SDK network request')
        code,r=probe();assert code==0 and r['result']=='passed' and not r['bucket_visibility_verified'] and not list(objects.rglob('*.txt'))
        passed('Actual signed SDK upload, hash-read, unsigned denial and verified delete pass through HTTP fixture')
        assert any(x['method']=='PUT' and x['signed'] for x in requests) and all(not x['acl'] for x in requests)
        passed('Serialised requests contain valid fixture SigV4 signatures and no object ACL/grant headers')
        assert any(x['anonymous'] and x['method']=='GET' for x in requests)
        passed('Anonymous probe uses an unsigned request to the configured API endpoint only')
        mode='public';code,r=probe();assert code==1 and r['failure']=='anonymous_api_check' and r['checks']['cleanup']
        passed('An anonymously readable fixture object fails the probe and is removed')
        mode='corrupt';code,r=probe();assert code==1 and r['failure']=='hash_read' and r['checks']['cleanup']
        passed('Changed wire bytes fail SHA-256 readback and still clean up')
        mode='redirect';before=len(requests);code,r=probe();assert code==1 and r['failure']=='anonymous_api_check' and r['checks']['cleanup'];assert len([q for q in requests[before:] if q['anonymous']])==1
        passed('Anonymous redirect is inconclusive and not followed')
        mode='deny-delete';code,r=probe();assert code==1 and not r['checks']['cleanup'] and r.get('cleanup_reference');assert secret not in json.dumps(r)
        passed('Denied cleanup reports the synthetic key and nonzero status without credential details')
        # Fixture-only recovery of the known synthetic object; no real source is touched.
        for p in objects.rglob('*'):
            if p.is_file():p.unlink()
        mode='normal';source=temp/'fictional-source.txt';source.write_text('Fictional persistent S3 evidence');metadata=temp/'entry.json'
        env['GTA_FIXTURE_SOURCE']=str(source);env['GTA_FIXTURE_METADATA']=str(metadata)
        php("$f=new Illuminate\\Http\\UploadedFile(getenv('GTA_FIXTURE_SOURCE'),'proof.txt','text/plain',null,true);$m=App\\Support\\PrivateEvidence::store($f,'task-evidence',1);file_put_contents(getenv('GTA_FIXTURE_METADATA'),json_encode($m));")
        m=json.loads(metadata.read_text());assert m['disk']=='fixture_s3';assert (objects/'fictional-private/evidence-prefix'/m['path']).read_bytes()==source.read_bytes()
        passed('Actual SDK evidence upload pins its disk and matches independently read persisted bytes')
        output=php("$m=json_decode(file_get_contents(getenv('GTA_FIXTURE_METADATA')),true);config(['evidence.disk'=>'second_s3']);$finger=App\\Support\\PrivateEvidence::fingerprint($m);if(!$finger['exists'] || $finger['sha256']!==$m['sha256'] || $finger['bytes']!==$m['bytes'] || !App\\Support\\PrivateEvidence::matchesHash($m)){exit(2);}$r=App\\Support\\PrivateEvidence::download($m);if(!str_contains($r->headers->get('Cache-Control'),'no-store')){exit(3);}$r->sendContent();")
        assert output==source.read_text()
        passed('A fresh PHP process with a changed default disk fingerprints, verifies and privately downloads the original pinned object')
        before=len(requests)
        php("$m=json_decode(file_get_contents(getenv('GTA_FIXTURE_METADATA')),true);$d=[...$m,'disk'=>'second_s3','path'=>'gta-staged-copies/fixture/verified.bin'];App\\Support\\PrivateEvidence::copyExclusive($m,$d,20*1024*1024);$f=App\\Support\\PrivateEvidence::fingerprint($d);if($f['sha256']!==$m['sha256'] || $f['bytes']!==$m['bytes']){exit(2);}")
        destination=objects/'different-private/evidence-prefix/gta-staged-copies/fixture/verified.bin';assert destination.read_bytes()==source.read_bytes()
        assert any(q['method']=='PUT' and q['signed'] and q['conditional_create'] for q in requests[before:])
        passed('Exclusive copy sends signed PutObject If-None-Match star without ACLs and verifies destination bytes')
        output=php("$m=json_decode(file_get_contents(getenv('GTA_FIXTURE_METADATA')),true);$d=[...$m,'disk'=>'second_s3','path'=>'gta-staged-copies/fixture/verified.bin'];try{App\\Support\\PrivateEvidence::copyExclusive($m,$d,20*1024*1024);echo 'unsafe';}catch(Throwable){echo 'refused';}")
        assert output=='refused' and destination.read_bytes()==source.read_bytes()
        passed('A second actual SDK conditional copy receives collision refusal and preserves the existing destination')
        stored=objects/'fictional-private/evidence-prefix'/m['path'];stored.write_text('Tampered fixture evidence')
        assert php("$m=json_decode(file_get_contents(getenv('GTA_FIXTURE_METADATA')),true);echo App\\Support\\PrivateEvidence::matchesHash($m)?'match':'changed';")=='changed'
        passed('Fresh-process SHA-256 check detects changed persisted bytes')
        stored.unlink();assert php("$m=json_decode(file_get_contents(getenv('GTA_FIXTURE_METADATA')),true);echo App\\Support\\PrivateEvidence::exists($m)?'exists':'missing';")=='missing'
        passed('Missing object uses a file-only HEAD check without bucket listing')
        assert all(not q['acl'] for q in requests)
        result={'passed':len(checks),'failed':0,'checks':checks,'transport':'Actual locked PHP AWS SDK HTTP requests to a local filesystem-backed SigV4-checking fixture','requests':len(requests),'actualCloudAccess':False,'realCredentialsOrLearnerData':False,'limits':['Fixture is not full S3/R2 implementation or actual Cloud acceptance','No actual bucket/public aliases, private Cloud credentials, TLS endpoint, target database or deployment verified','Normal and failure fixtures prove application request/cleanup logic, not external infrastructure guarantees']}
        Path(args.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0,'requests':len(requests),'actualCloudAccess':False}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks,'requests':len(requests),'actualCloudAccess':False},indent=2)+'\n');raise
    finally:server.shutdown();server.server_close()
