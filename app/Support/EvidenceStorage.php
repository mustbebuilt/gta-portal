<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EvidenceStorage
{
    public static function inspect(): array
    {
        try{
            $disk=PrivateEvidence::diskName();$driver=config('filesystems.disks.'.$disk.'.driver');
            $adapter=class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class);
            return ['mode'=>'configuration','result'=>$driver==='s3' && $adapter?'configuration_present':'cloud_storage_not_configured','disk'=>$disk,'driver'=>$driver,'s3_adapter_installed'=>$adapter,'network_performed'=>false,'writes_performed'=>false,'bucket_visibility_verified'=>false];
        }catch(\Throwable){return ['mode'=>'configuration','result'=>'invalid_configuration','network_performed'=>false,'writes_performed'=>false,'bucket_visibility_verified'=>false];}
    }

    public static function probe(): array
    {
        $config=self::inspect();
        $result=['mode'=>'synthetic_probe','result'=>'failed','checks'=>['write'=>false,'hash_read'=>false,'anonymous_api_denied'=>false,'cleanup'=>false],'database_records_created'=>false,'bucket_visibility_verified'=>false,'anonymous_scope'=>'Configured API endpoint only; other public aliases and actual bucket visibility still need operator verification.'];
        if(!app()->environment(['local','testing','staging']) || $config['result']!=='configuration_present'){
            return [...$result,'failure'=>'requires_explicit_nonproduction_s3_configuration','network_performed'=>false,'writes_performed'=>false];
        }
        $disk=$config['disk'];$directory='gta-storage-probes/'.Str::uuid();$temp=null;$entry=null;$phase='prepare';$attempted=false;
        try{
            $temp=tempnam(sys_get_temp_dir(),'gta-storage-');if($temp===false){throw new \RuntimeException;}
            chmod($temp,0600);$content="GTA fictional storage probe\n".bin2hex(random_bytes(32));file_put_contents($temp,$content);
            $file=new UploadedFile($temp,'fictional-storage-probe.txt','text/plain',null,true);
            // Known random key supports cleanup even when a write fails after reaching the service.
            $entry=['disk'=>$disk,'path'=>$directory.'/'.$file->hashName()];
            $client=Storage::disk($disk)->getClient();$url=$client->getObjectUrl(config('filesystems.disks.'.$disk.'.bucket'),Storage::disk($disk)->path($entry['path']));$parts=parse_url($url);
            $local=app()->environment(['local','testing']) && in_array($parts['host']??null,['127.0.0.1','localhost','[::1]'],true);
            if(!is_array($parts) || !in_array($parts['scheme']??'',($local?['https','http']:['https']),true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])){throw new \RuntimeException;}
            $phase='write';$attempted=true;$stored=PrivateEvidence::store($file,$directory,0);
            if($stored['path']!==$entry['path']){throw new \RuntimeException;}$result['checks']['write']=true;
            $phase='hash_read';if(!PrivateEvidence::matchesHash($stored)){throw new \RuntimeException;}$result['checks']['hash_read']=true;
            $phase='anonymous_api_check';
            $anonymous=Http::timeout(10)->withOptions(['allow_redirects'=>false])->get($url);
            if(!in_array($anonymous->status(),[403,404],true)){throw new \RuntimeException;}$result['checks']['anonymous_api_denied']=true;
        }catch(\Throwable){$result['failure']=$phase;}
        finally{
            if($entry && $attempted){try{$deleted=PrivateEvidence::delete($entry);$result['checks']['cleanup']=$deleted && !PrivateEvidence::exists($entry);}catch(\Throwable){$result['checks']['cleanup']=false;}}
            if($temp && is_file($temp)){unlink($temp);}
        }
        if($attempted && !$result['checks']['cleanup']){$result['cleanup_reference']=$entry['path'];}
        $result['network_performed']=$attempted;$result['write_attempted']=$attempted;$result['writes_performed']=$attempted?($result['checks']['write']?true:null):false;
        if(!in_array(false,$result['checks'],true)){$result['result']='passed';unset($result['failure']);}
        return $result;
    }
}
