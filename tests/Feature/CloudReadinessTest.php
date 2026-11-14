<?php
namespace Tests\Feature;

use App\Support\CloudReadiness;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CloudReadinessTest extends TestCase
{
    public function test_default_read_only_probe_reports_real_hosted_block_without_connecting_or_exposing_secrets(): void
    {
        DB::shouldReceive('connection')->never();
        config(['database.connections.mysql.password'=>'Fictional-secret-never-print','app.key'=>'base64:Fictional-key-never-print000000000000000000']);
        $r=CloudReadiness::inspect();$text=json_encode($r,JSON_THROW_ON_ERROR);
        $this->assertSame('blocked',$r['result']);$this->assertFalse($r['writes_performed']);$this->assertFalse($r['deployment_performed']);$this->assertStringNotContainsString('Fictional-secret',$text);$this->assertStringNotContainsString('Fictional-key',$text);
        $checks=collect($r['checks'])->keyBy('check');$this->assertSame('blocked',$checks['hosted_web_gate']['status']);$this->assertSame('not_checked',$checks['database_connection_and_migrations']['status']);
    }
    public function test_explicit_probe_refuses_sqlite_fallback_and_command_fails_honestly(): void
    {
        config(['database.default'=>'sqlite']);DB::shouldReceive('connection')->never();
        $r=CloudReadiness::inspect(true);$checks=collect($r['checks'])->keyBy('check');$this->assertSame('blocked',$checks['database_connection_and_migrations']['status']);$this->assertTrue($r['database_probe_requested']);
        $this->artisan('gta:cloud-check',['--database'=>true])->assertExitCode(1);
    }
}
