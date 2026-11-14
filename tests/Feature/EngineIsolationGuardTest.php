<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedMysql;
use Tests\TestCase;

class EngineIsolationGuardTest extends TestCase
{
    public function test_reset_guard_refuses_remote_or_ordinary_database_before_any_connection(): void
    {
        DB::shouldReceive('connection')->never();
        config(['database.default'=>'mysql','database.connections.mysql.host'=>'production.example.test','database.connections.mysql.database'=>'real_portal','database.connections.mysql.unix_socket'=>'']);
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('refuse reset');IsolatedMysql::assertDisposable();
    }
    public function test_even_a_locally_named_database_requires_matching_private_run_token(): void
    {
        DB::shouldReceive('connection')->never();$dir=sys_get_temp_dir().'/gta-mariadb-guard-'.bin2hex(random_bytes(6));mkdir($dir,0700);
        $file=$dir.'/isolated-run.json';$name='gta_test_0123456789abcdef';file_put_contents($file,json_encode(['mode'=>'local-disposable','database'=>$name,'username'=>$name,'port'=>3307,'token'=>'expected-private-fixture-token']));chmod($file,0600);
        $oldMarker=getenv('GTA_ENGINE_MARKER');$oldToken=getenv('GTA_ENGINE_TOKEN');
        try{
            putenv('GTA_ENGINE_MARKER='.$file);putenv('GTA_ENGINE_TOKEN=wrong-fixture-token');
            config(['database.default'=>'mysql','database.connections.mysql.host'=>'127.0.0.1','database.connections.mysql.port'=>3307,'database.connections.mysql.database'=>$name,'database.connections.mysql.username'=>$name,'database.connections.mysql.unix_socket'=>'']);
            try{IsolatedMysql::assertDisposable();$this->fail('Matching token required');}catch(\RuntimeException $e){$this->assertStringContainsString('refuse reset',$e->getMessage());}
        }finally{putenv($oldMarker===false?'GTA_ENGINE_MARKER':'GTA_ENGINE_MARKER='.$oldMarker);putenv($oldToken===false?'GTA_ENGINE_TOKEN':'GTA_ENGINE_TOKEN='.$oldToken);unlink($file);rmdir($dir);}
    }    public function test_default_suite_cannot_reset_a_file_or_native_database_without_engine_isolation(): void
    {
        DB::shouldReceive('connection')->never();$old=getenv('GTA_ENGINE_TESTS');putenv('GTA_ENGINE_TESTS');
        try{
            foreach([['sqlite','/not-a-test/live.sqlite'],['mysql',':memory:']] as [$driver,$database]){
                config(['database.default'=>$driver,'database.connections.sqlite.database'=>$database]);
                try{$this->setUpTraits();$this->fail('Default reset must be refused');}catch(\RuntimeException $e){$this->assertStringContainsString('only permits testing with in-memory SQLite',$e->getMessage());}
            }
        }finally{putenv($old===false?'GTA_ENGINE_TESTS':'GTA_ENGINE_TESTS='.$old);}
    }

}
