<?php
namespace Tests\Support;

class IsolatedMysql
{
    public static function assertDisposable(): void
    {
        $c=config('database.connections.mysql');$markerFile=getenv('GTA_ENGINE_MARKER');
        $dir=is_string($markerFile)?dirname($markerFile):'';$base=rtrim(sys_get_temp_dir(),'/').'/gta-mariadb-';
        $m=is_string($markerFile) && is_file($markerFile)?json_decode(file_get_contents($markerFile),true):null;
        if(!app()->environment('testing') || config('database.default')!=='mysql' || !str_starts_with($dir,$base) || !is_array($m) || ($m['mode']??null)!=='local-disposable' || ($c['host']??null)!=='127.0.0.1' || !empty($c['unix_socket']) || !preg_match('/^gta_test_[a-f0-9]{16}$/',$c['database']??'') || ($m['database']??null)!==$c['database'] || ($m['username']??null)!==($c['username']??null) || (int)($m['port']??0)!==(int)($c['port']??0) || !filled(getenv('GTA_ENGINE_TOKEN')) || !hash_equals($m['token']??'',getenv('GTA_ENGINE_TOKEN'))){
            throw new \RuntimeException('Engine tests refuse reset without a matching private local disposable-database marker.');
        }
    }
}
