<?php
namespace Tests\Feature;
use App\Support\MobileVerification;
use Illuminate\Support\Facades\{DB,Http};
use Tests\TestCase;
class MobileVerificationDiagnosticTest extends TestCase {
 public function test_default_diagnostic_never_opens_database_or_http_and_is_explicitly_configuration_only():void {
  DB::shouldReceive('connection')->never();Http::preventStrayRequests();$r=MobileVerification::inspect();$this->assertSame('blocked',$r['result']);$this->assertFalse($r['sends_performed']);$this->assertFalse($r['writes_performed']);$this->artisan('gta:mobile-verification-check')->assertExitCode(1);Http::assertNothingSent();
 }
}
