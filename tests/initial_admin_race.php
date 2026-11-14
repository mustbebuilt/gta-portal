<?php
// Private subprocess worker used only by mysql_engine.py's disposable local race fixture.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$directory=getenv('GTA_RACE_DIRECTORY');
if(!is_string($directory) || !str_starts_with($directory,rtrim(sys_get_temp_dir(),'/').'/gta-mariadb-') || !is_file($directory.'/isolated-run.json')){exit(2);}
$marker=json_decode(file_get_contents($directory.'/isolated-run.json'),true);$connection=config('database.connections.mysql');
if(config('database.default')!=='mysql' || ($connection['host']??null)!=='127.0.0.1' || !empty($connection['unix_socket']) || ($marker['race_database']??null)!==($connection['database']??null) || ($marker['username']??null)!==($connection['username']??null) || (int)($marker['port']??0)!==(int)($connection['port']??0) || !filled(getenv('GTA_ENGINE_TOKEN')) || !hash_equals($marker['token']??'',getenv('GTA_ENGINE_TOKEN'))){exit(2);}
$credentials=App\Support\InitialAdmin::credentialsFromJson(stream_get_contents(STDIN,2049));
file_put_contents($directory.'/ready-'.getmypid(),'ready');
$limit=microtime(true)+15;
while(!is_file($directory.'/start-race')){if(microtime(true)>$limit){exit(3);}usleep(10000);}
try{
    $u=App\Support\InitialAdmin::create('Fictional concurrent administrator','race-admin@example.test',$credentials['password'],$credentials['password_confirmation']);
    echo json_encode(['result'=>'created','id'=>$u->id]);
}catch(Illuminate\Validation\ValidationException){echo json_encode(['result'=>'rejected']);}
