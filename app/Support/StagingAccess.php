<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;

class StagingAccess
{
    // Configuration checks only; connectivity, private storage and schema need separate acceptance.
    public static function errors(): array
    {
        $errors=[];
        if(!app()->environment('staging')){$errors[]='staging_environment_required';}
        if(config('deployment.staging_enabled')!==true){$errors[]='staging_access_disabled';}
        if(config('app.debug')!==false){$errors[]='debug_must_be_disabled';}
        $key=(string)config('app.key');
        $raw=str_starts_with($key,'base64:') ? base64_decode(substr($key,7),true) : $key;
        if(!is_string($raw) || !Encrypter::supported($raw,(string)config('app.cipher'))){$errors[]='valid_application_key_required';}
        $url=parse_url((string)config('app.url'));
        if(!is_array($url) || ($url['scheme']??null)!=='https' || empty($url['host']) || !filter_var(config('app.url'),FILTER_VALIDATE_URL) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || !in_array($url['path']??'',['','/'],true)){$errors[]='https_application_origin_required';}
        if(config('session.secure')!==true || config('session.encrypt')!==true || config('session.http_only')!==true){$errors[]='secure_encrypted_http_only_sessions_required';}
        if(!in_array(config('session.driver'),['database','redis'],true)){$errors[]='shared_sessions_required';}
        if(!in_array(config('cache.default'),['database','redis'],true)){$errors[]='shared_cache_required';}
        return $errors;
    }

    public static function requestAllowed(Request $request): bool
    {
        if(app()->environment(['local','testing'])){return true;}
        if(self::errors() || !$request->isSecure()){return false;}
        $origin=parse_url(config('app.url'));
        return strtolower($request->getHost())===strtolower($origin['host']) && $request->getPort()===($origin['port']??443);
    }
}
