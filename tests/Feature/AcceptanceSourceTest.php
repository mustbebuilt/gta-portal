<?php
namespace Tests\Feature;
use App\Support\AcceptanceSource;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class AcceptanceSourceTest extends TestCase {
 private function tree():string {
  $root=sys_get_temp_dir().'/gta-acceptance-source-'.bin2hex(random_bytes(8));mkdir($root,0700,true);
  foreach(AcceptanceSource::ROOTS as$path){mkdir($root.'/'.$path,0700,true);file_put_contents($root.'/'.$path.'/fictional.txt','Fictional source');}
  foreach([...AcceptanceSource::FILES,...AcceptanceSource::REQUIRED]as$path){if(!is_dir(dirname($root.'/'.$path))){mkdir(dirname($root.'/'.$path),0700,true);}file_put_contents($root.'/'.$path,'Fictional required source');}return $root;
 }
 private function remove(string $root):void {if(is_link($root)||is_file($root)){unlink($root);return;}foreach(scandir($root)as$p){if(!in_array($p,['.','..'],true)){$this->remove($root.'/'.$p);}}rmdir($root);}
 public function test_capture_hashes_real_fixture_source_without_db_writes_file_contents_or_secret_values():void {
  $root=$this->tree();try{file_put_contents($root.'/.env','PRIVATE-ENV-CONTENT');file_put_contents($root.'/config/fictional.txt','PRIVATE-CONFIG-SOURCE-CONTENT');config(['mail.mailers.smtp.password'=>'PRIVATE-REAL-CONFIG-SECRET']);DB::shouldReceive('connection')->never();$before=hash_file('sha256',$root.'/config/fictional.txt');$s=(new AcceptanceSource)->capture($root);$this->assertTrue(AcceptanceSource::valid($s));$this->assertFalse($s['database_accessed']);$this->assertFalse($s['writes_performed']);$this->assertSame($before,hash_file('sha256',$root.'/config/fictional.txt'));foreach(['PRIVATE-ENV','PRIVATE-CONFIG','PRIVATE-REAL']as$value){$this->assertStringNotContainsString($value,json_encode($s));}$this->assertArrayNotHasKey('.env',$s['files']);$this->assertArrayNotHasKey('files',AcceptanceSource::summary($s));}finally{$this->remove($root);}
 }
 public function test_source_byte_change_and_sensitive_config_change_change_fingerprint_without_exporting_secret():void {
  $root=$this->tree();try{$reader=new AcceptanceSource;$a=$reader->capture($root);file_put_contents($root.'/app/fictional.txt','Changed source bytes');$b=$reader->capture($root);$this->assertNotSame($a['fingerprint'],$b['fingerprint']);config(['mail.mailers.smtp.password'=>'PRIVATE-CHANGED-PASSWORD']);$c=$reader->capture($root);$this->assertSame($b['files'],$c['files']);$this->assertNotSame($b['fingerprint'],$c['fingerprint']);$this->assertStringNotContainsString('PRIVATE-CHANGED',json_encode($c));}finally{$this->remove($root);}
 }
 public function test_symlink_source_private_artifacts_and_missing_required_source_block_capture():void {
  $root=$this->tree();try{file_put_contents($root.'/.env','PRIVATE-ENV');symlink($root.'/.env',$root.'/app/linked.txt');$s=(new AcceptanceSource)->capture($root);$this->assertSame('blocked',$s['status']);$this->assertContains('symlink_source',$s['blockers']);unlink($root.'/app/linked.txt');file_put_contents($root.'/app/private.key','PRIVATE-KEY');$s=(new AcceptanceSource)->capture($root);$this->assertContains('private_artifact_in_source',$s['blockers']);unlink($root.'/app/private.key');unlink($root.'/app/Support/StagingAcceptance.php');$s=(new AcceptanceSource)->capture($root);$this->assertContains('required_source_missing',$s['blockers']);$this->assertFalse(AcceptanceSource::valid($s));}finally{$this->remove($root);}
 }
 public function test_read_only_cli_exports_summary_not_file_inventory_and_performs_no_db_access():void {
  $root=$this->tree();try{$capture=(new AcceptanceSource)->capture($root);$reader=\Mockery::mock(AcceptanceSource::class);$reader->shouldReceive('capture')->once()->andReturn($capture);$this->app->instance(AcceptanceSource::class,$reader);DB::shouldReceive('connection')->never();$this->artisan('gta:acceptance-source-check')->assertExitCode(0);$this->assertFalse($capture['deployment_approved']);}finally{$this->remove($root);}
 }
}
