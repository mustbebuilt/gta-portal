<?php
namespace Tests\Feature;

use App\Http\Middleware\LocalTestOnly;
use App\Support\CloudReadiness;
use App\Support\StagingAccess;
use Illuminate\Http\Request;
use Tests\Concerns\ConfiguresStaging;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class StagingAccessTest extends TestCase
{
    use ConfiguresStaging;

    public function test_explicit_staging_gate_keeps_private_headers_and_requires_secure_same_origin_request(): void
    {
        $this->configureStaging();$this->assertSame([],StagingAccess::errors());
        $request=Request::create('https://staging.example.test/login');
        $response=(new LocalTestOnly)->handle($request,fn()=>response('Fictional staging response'));
        $this->assertSame(200,$response->getStatusCode());$this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));$this->assertSame('DENY',$response->headers->get('X-Frame-Options'));$this->assertSame('nosniff',$response->headers->get('X-Content-Type-Options'));$this->assertSame('same-origin',$response->headers->get('Referrer-Policy'));
        foreach(['http://staging.example.test/login','https://other.example.test/login','https://sub.staging.example.test/login','https://staging.example.test:8443/login'] as $url){$this->assertFalse(StagingAccess::requestAllowed(Request::create($url)));}
        $checks=collect(CloudReadiness::inspect()['checks'])->keyBy('check');$this->assertSame('pass',$checks['hosted_web_gate']['status']);$this->assertSame('pass',$checks['s3_adapter']['status']);
    }
    public static function invalidProfiles(): array
    {
        return [
            'disabled'=>['deployment.staging_enabled',false],
            'debug'=>['app.debug',true],
            'wrong key'=>['app.key','base64:not-valid-application-key'],
            'cipher mismatch'=>['app.cipher','AES-128-CBC'],
            'http origin'=>['app.url','http://staging.example.test'],
            'credentials in origin'=>['app.url','https://name:secret@staging.example.test'],
            'query origin'=>['app.url','https://staging.example.test/?token=secret'],
            'path origin'=>['app.url','https://staging.example.test/other'],
            'local sessions'=>['session.driver','file'],
            'unencrypted sessions'=>['session.encrypt',false],
            'insecure cookies'=>['session.secure',false],
            'javascript cookies'=>['session.http_only',false],
            'local cache'=>['cache.default','file'],
        ];
    }
    #[DataProvider('invalidProfiles')]
    public function test_incomplete_profile_does_not_open_hosted_access(string $key,mixed $value): void
    {
        $this->configureStaging();config([$key=>$value]);$this->assertNotEmpty(StagingAccess::errors());$this->assertFalse(StagingAccess::requestAllowed(Request::create('https://staging.example.test/login')));
    }
    public function test_production_and_arbitrary_environments_remain_closed_even_with_staging_flags(): void
    {
        $this->configureStaging();foreach(['production','preview','development'] as $env){$this->app['env']=$env;$this->assertFalse(StagingAccess::requestAllowed(Request::create('https://staging.example.test/login')));}
    }
    public function test_forwarded_proto_from_an_untrusted_request_does_not_bypass_https(): void
    {
        $this->configureStaging();Request::setTrustedProxies([],Request::HEADER_X_FORWARDED_PROTO);
        $r=Request::create('http://staging.example.test/login','GET',[],[],[],['HTTP_X_FORWARDED_PROTO'=>'https']);$this->assertFalse(StagingAccess::requestAllowed($r));
    }
    public function test_local_fictional_development_retains_its_previous_access(): void
    {
        $this->app['env']='local';config(['deployment.staging_enabled'=>false]);$this->assertTrue(StagingAccess::requestAllowed(Request::create('http://localhost/login')));
    }
}
