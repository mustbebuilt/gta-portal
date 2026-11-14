<?php
namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\Programme;
use App\Models\TutorGroup;
use App\Models\User;
use App\Support\InitialAdmin;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ConfiguresStaging;
use Tests\TestCase;

class InitialAdminTest extends TestCase
{
    use LazilyRefreshDatabase,ConfiguresStaging;
    private const PASSWORD='Fictional-first-admin-passphrase-28!';
    public function test_preview_creates_nothing_then_one_staging_admin_and_atomic_audit_allow_real_sign_in(): void
    {
        $this->configureStaging();Mail::fake();$before=InitialAdmin::preview();$this->assertTrue($before['available']);$this->assertFalse($before['writes_performed']);$this->assertDatabaseCount('users',0);
        $user=InitialAdmin::create('Fictional Administrator',' First-Admin@Example.Test ',self::PASSWORD,self::PASSWORD);
        $this->assertSame('first-admin@example.test',$user->email);$this->assertTrue($user->active);$this->assertSame('admin',$user->role);$this->assertNull($user->email_verified_at);$this->assertTrue(Hash::check(self::PASSWORD,$user->password));
        $this->assertFalse(InitialAdmin::preview()['available']);$this->assertDatabaseHas('portal_bootstrap_states',['id'=>1,'completed_by'=>$user->id]);$this->assertDatabaseHas('account_access_events',['user_id'=>$user->id,'event'=>'initial_admin_created','origin'=>'staging_bootstrap']);
        $audit=json_encode(DB::table('account_access_events')->get());$this->assertStringNotContainsString(self::PASSWORD,$audit);$this->assertStringNotContainsString($user->password,$audit);
        $form=$this->get('https://staging.example.test/login')->assertOk()->assertSee('Staging portal');
        preg_match('/name="_token" value="([^"]+)"/',$form->getContent(),$match);$this->assertNotEmpty($match[1]??null);
        $session=$form->getCookie(config('session.cookie'));$this->assertNotNull($session);
        $login=$this->withCookie(config('session.cookie'),$session->getValue())->post('https://staging.example.test/login',['_token'=>$match[1],'email'=>$user->email,'password'=>self::PASSWORD])->assertRedirect('https://staging.example.test/apprentices');
        $this->assertAuthenticatedAs($user);$currentSession=$login->getCookie(config('session.cookie'));$this->assertNotNull($currentSession);
        $this->withCookie(config('session.cookie'),$currentSession->getValue())->get('https://staging.example.test/apprentices')->assertOk()->assertSee('Staging portal');Mail::assertNothingSent();Mail::assertNothingQueued();
    }
    public function test_second_creation_does_not_change_existing_password_role_or_audit(): void
    {
        $this->configureStaging();$first=InitialAdmin::create('Fictional First','first@example.test',self::PASSWORD,self::PASSWORD);$original=$first->fresh()->getAttributes();
        try{InitialAdmin::create('Fictional Second','second@example.test',self::PASSWORD,self::PASSWORD);$this->fail('Only one initial account');}catch(ValidationException){$this->assertSame($original,$first->fresh()->getAttributes());}
        $this->assertDatabaseCount('users',1);$this->assertDatabaseCount('account_access_events',1);
    }
    public function test_existing_accounts_and_domain_records_block_initialisation(): void
    {
        $this->configureStaging();
        foreach([User::class,Apprentice::class,Employer::class,Programme::class,TutorGroup::class] as $model){
            $row=$model::factory()->create();$this->assertFalse(InitialAdmin::preview()['available']);
            try{InitialAdmin::create('Fictional Admin','first@example.test',self::PASSWORD,self::PASSWORD);$this->fail('Populated application blocked');}catch(ValidationException){$this->assertNull(DB::table('portal_bootstrap_states')->value('completed_at'));}$row->delete();
        }
        $this->assertDatabaseCount('users',0);$this->assertDatabaseCount('account_access_events',0);
    }
    public function test_flags_environment_and_password_validation_cannot_be_bypassed(): void
    {
        $this->configureStaging();
        foreach(['production','local','testing'] as $env){$this->app['env']=$env;try{InitialAdmin::create('Fictional Admin','first@example.test',self::PASSWORD,self::PASSWORD);$this->fail('Staging only');}catch(ValidationException){$this->assertDatabaseCount('users',0);}}
        $this->app['env']='staging';config(['deployment.initial_admin_enabled'=>false]);$this->assertFalse(InitialAdmin::preview()['available']);config(['deployment.initial_admin_enabled'=>true]);
        foreach([['Bad email','wrong',self::PASSWORD,self::PASSWORD],['Fictional Admin','first@example.test','short','short'],['Fictional Admin','first@example.test',self::PASSWORD,'different']] as $args){try{InitialAdmin::create(...$args);$this->fail('Invalid bootstrap input');}catch(ValidationException){$this->assertDatabaseCount('users',0);}}
    }
    public function test_audit_failure_rolls_back_account_and_consumption_marker(): void
    {
        $this->configureStaging();\Tests\Support\AuditFault::install('reject_initial_admin','account_access_events','Fictional initial audit failure');
        try{InitialAdmin::create('Fictional Admin','first@example.test',self::PASSWORD,self::PASSWORD);$this->fail('Audit required');}catch(\Illuminate\Database\QueryException $e){$this->assertStringContainsString('Fictional initial audit failure',$e->getMessage());}
        $this->assertDatabaseCount('users',0);$this->assertNull(DB::table('portal_bootstrap_states')->value('completed_at'));DB::statement('DROP TRIGGER reject_initial_admin');$this->assertTrue(InitialAdmin::preview()['available']);
    }
    public function test_private_json_credentials_reject_malformed_extra_nested_and_oversized_input_without_echo(): void
    {
        $data=['password'=>self::PASSWORD,'password_confirmation'=>self::PASSWORD];$this->assertSame($data,InitialAdmin::credentialsFromJson(json_encode($data)));
        foreach(['',self::PASSWORD,json_encode([...$data,'role'=>'ceo']),json_encode(['password'=>['nested'],'password_confirmation'=>self::PASSWORD]),str_repeat(' ',2049)] as $raw){try{InitialAdmin::credentialsFromJson($raw);$this->fail('Invalid JSON rejected');}catch(ValidationException $e){$this->assertStringNotContainsString(self::PASSWORD,$e->getMessage());}}
    }
    public function test_command_defaults_to_read_only_preview_and_never_accepts_password_argument(): void
    {
        $this->configureStaging();$command=\Illuminate\Support\Facades\Artisan::all()['gta:initial-admin'];$this->assertFalse($command->getDefinition()->hasOption('password'));$this->assertFalse($command->getDefinition()->hasArgument('password'));$this->artisan('gta:initial-admin')->assertSuccessful();$this->assertDatabaseCount('users',0);
        $this->artisan('gta:initial-admin',['--create'=>true,'--name'=>'Fictional Admin','--email'=>'first@example.test','--no-interaction'=>true])->assertExitCode(1);$this->assertDatabaseCount('users',0);
        $this->app['env']='production';$this->artisan('gta:initial-admin')->assertExitCode(1);$this->assertDatabaseCount('users',0);
    }
    public function test_interactive_command_uses_hidden_questions_and_retains_once_only_state(): void
    {
        $this->configureStaging();$this->artisan('gta:initial-admin',['--create'=>true,'--name'=>'Fictional Admin','--email'=>'first@example.test'])->expectsQuestion('Administrator password',self::PASSWORD)->expectsQuestion('Confirm administrator password',self::PASSWORD)->expectsOutputToContain('Initial staging administrator created')->assertSuccessful();
        $this->assertDatabaseCount('users',1);$this->artisan('gta:initial-admin',['--create'=>true,'--name'=>'Second','--email'=>'second@example.test'])->assertExitCode(1);$this->assertDatabaseCount('users',1);
    }
}
