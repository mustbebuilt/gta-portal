<?php
namespace Tests\Feature;
use App\Models\{Apprentice,User};
use App\Support\{ApplicationSchema,CloudReadiness};
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Schema\{Blueprint,Builder};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\{DB,Schema};
use Mockery;
use Tests\TestCase;

class ApplicationSchemaTest extends TestCase
{
 use LazilyRefreshDatabase;
 private $originalDatabase;
 protected function setUp():void {parent::setUp();$this->originalDatabase=DB::getFacadeRoot();}
 protected function tearDown():void {
  DB::swap($this->originalDatabase);config(['database.default'=>'sqlite']);$this->app['env']='testing';parent::tearDown();
 }
 private function ready():void {User::factory()->create(['role'=>'admin']);}
 private function mockNative(Builder $schema,bool $ran=true):void {
  $this->app['env']='staging';config(['database.default'=>'mysql']);
  $connection=Mockery::mock(Connection::class);$connection->shouldReceive('getDriverName')->andReturn('mysql');$connection->shouldReceive('select')->with('select 1')->andReturn([['1'=>1]]);$connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
  DB::shouldReceive('connection')->andReturn($connection);
  $repo=Mockery::mock(MigrationRepositoryInterface::class);$repo->shouldReceive('repositoryExists')->andReturn(true);
  $names=array_keys(app('migrator')->getMigrationFiles([database_path('migrations')]));$repo->shouldReceive('getRan')->andReturn($ran?$names:[]);$this->app->instance('migration.repository',$repo);
 }
 public function test_reference_migrations_match_all_required_names_and_recent_columns():void {
  $this->ready();$r=ApplicationSchema::inspectSchema(Schema::getFacadeRoot());
  $this->assertSame('pass',$r['status']);$this->assertSame(73,$r['required_tables']);$this->assertSame(705,$r['required_columns']);$this->assertSame(73,$r['tables_checked']);$this->assertSame([],$r['missing_tables']);$this->assertSame([],$r['missing_columns']);
  $this->assertContains('maths_tutor',ApplicationSchema::TABLES['staff_duties']);$this->assertContains('english_tutor',ApplicationSchema::TABLES['staff_duties']);$this->assertArrayHasKey('account_second_factors',ApplicationSchema::TABLES);
 }
 public function test_missing_subject_columns_block_even_when_all_migration_names_are_recorded():void {
  $this->ready();Schema::table('staff_duties',fn(Blueprint $t)=>$t->dropColumn(['maths_tutor','english_tutor']));$schema=Schema::getFacadeRoot();$this->mockNative($schema);
  $r=CloudReadiness::inspect(true);$checks=collect($r['checks'])->keyBy('check');
  $this->assertSame('pass',$checks['database_connection_and_migrations']['status']);$this->assertSame('blocked',$checks['required_application_schema']['status']);$this->assertSame(['english_tutor','maths_tutor'],$r['schema_presence']['missing_columns']['staff_duties']);$this->assertFalse($r['writes_performed']);
 }
 public function test_missing_table_is_reported_without_values_and_not_recreated():void {
  $this->ready();Schema::drop('staff_duties');$r=ApplicationSchema::inspectSchema(Schema::getFacadeRoot());
  $this->assertSame('blocked',$r['status']);$this->assertContains('staff_duties',$r['missing_tables']);$this->assertFalse(Schema::hasTable('staff_duties'));$this->assertFalse($r['writes_performed']);
 }
 public function test_extra_target_tables_and_columns_are_allowed():void {
  $this->ready();Schema::create('fictional_target_extra',fn(Blueprint $t)=>$t->id());Schema::table('users',fn(Blueprint $t)=>$t->string('fictional_extra')->nullable());
  $r=ApplicationSchema::inspectSchema(Schema::getFacadeRoot());$this->assertSame('pass',$r['status']);$this->assertSame([],$r['missing_columns']);$this->assertSame(73,$r['required_tables']);
 }
 public function test_default_diagnostic_never_opens_database_and_marks_schema_not_checked():void {
  DB::shouldReceive('connection')->never();$r=CloudReadiness::inspect();$this->assertSame('not_checked',$r['schema_presence']['status']);$this->assertSame('not_requested',$r['schema_presence']['reason']);$this->assertSame(0,$r['schema_presence']['tables_checked']);
 }
 public function test_probe_refuses_non_staging_without_opening_database():void {
  $this->app['env']='production';config(['database.default'=>'mysql']);DB::shouldReceive('connection')->never();$r=CloudReadiness::inspect(true);
  $this->assertSame('blocked',$r['schema_presence']['status']);$this->assertSame('staging_required',$r['schema_presence']['reason']);$this->assertSame(0,$r['schema_presence']['tables_checked']);
 }
 public function test_actual_sqlite_driver_is_not_accepted_as_native_even_with_mysql_configuration():void {
  $this->ready();$actual=DB::connection();$this->app['env']='staging';config(['database.default'=>'mysql']);DB::shouldReceive('connection')->andReturn($actual);
  $r=CloudReadiness::inspect(true);$this->assertSame('actual_driver_not_supported',$r['schema_presence']['reason']);$this->assertSame(0,$r['schema_presence']['tables_checked']);$this->assertSame('blocked',ApplicationSchema::inspect($actual)['status']);
 }
 public function test_full_names_pass_is_separate_from_pending_migrations_and_not_deployment_approval():void {
  $this->ready();$schema=Schema::getFacadeRoot();$this->mockNative($schema,false);$r=CloudReadiness::inspect(true);$checks=collect($r['checks'])->keyBy('check');
  $this->assertSame('blocked',$checks['database_connection_and_migrations']['status']);$this->assertSame('pass',$checks['required_application_schema']['status']);$this->assertFalse($r['deployment_performed']);$this->assertSame('blocked',$r['result']);
 }
 public function test_connection_errors_are_redacted_and_never_treated_as_a_pass():void {
  $this->app['env']='staging';config(['database.default'=>'mysql','database.connections.mysql.password'=>'FICTIONAL-DB-PASSWORD']);DB::shouldReceive('connection')->andThrow(new \RuntimeException('FICTIONAL-DB-PASSWORD sensitive-host query-value'));
  $r=CloudReadiness::inspect(true);$text=json_encode($r);$this->assertSame('database_probe_failed',$r['schema_presence']['reason']);$this->assertStringNotContainsString('FICTIONAL-DB-PASSWORD',$text);$this->assertStringNotContainsString('sensitive-host',$text);$this->assertStringNotContainsString('query-value',$text);
 }
 public function test_metadata_exception_does_not_expose_exception_or_return_partial_pass():void {
  $schema=Mockery::mock(Builder::class);$schema->shouldReceive('hasTable')->andThrow(new \RuntimeException('FICTIONAL-METADATA-SECRET'));
  $r=ApplicationSchema::inspectSchema($schema);$this->assertSame('blocked',$r['status']);$this->assertSame('schema_probe_failed',$r['reason']);$this->assertSame(0,$r['tables_checked']);$this->assertStringNotContainsString('FICTIONAL-METADATA-SECRET',json_encode($r));
 }
 public function test_schema_read_does_not_change_business_data_or_query_record_values():void {
  $this->ready();Apprentice::factory()->create(['first_name'=>'FICTIONAL-PRIVATE-LEARNER']);$before=DB::table('users')->get()->toArray();$apprentices=DB::table('apprentices')->get()->toArray();
  DB::enableQueryLog();$r=ApplicationSchema::inspectSchema(Schema::getFacadeRoot());$queries=DB::getQueryLog();DB::disableQueryLog();
  $this->assertSame('pass',$r['status']);$this->assertStringNotContainsString('FICTIONAL-PRIVATE-LEARNER',json_encode($r));
  $this->assertEquals($before,DB::table('users')->get()->toArray());$this->assertEquals($apprentices,DB::table('apprentices')->get()->toArray());
  foreach($queries as $q) {$this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|create|alter|drop|replace|truncate)\b/i',$q['query']);}
 }
 public function test_command_outputs_structured_schema_status_and_nonzero_when_blocked():void {
  $this->app['env']='staging';config(['database.default'=>'sqlite']);DB::shouldReceive('connection')->never();
  $this->artisan('gta:cloud-check',['--database'=>true])->expectsOutputToContain('"configured_driver_not_supported"')->assertExitCode(1);
 }
}
