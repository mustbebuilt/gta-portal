"""Loopback-only disposable before/after theme fixtures; no real records/providers or production routes.
Run against the supplied reference checkout(s), never a live application. Stop with Ctrl-C.
"""
from pathlib import Path
import argparse,base64,json,os,secrets,signal,socket,subprocess,tempfile,time
p=argparse.ArgumentParser();p.add_argument('--before-root',required=True);p.add_argument('--after-root',required=True);p.add_argument('--output',required=True);p.add_argument('--before-port',type=int,default=8852);p.add_argument('--after-port',type=int,default=8853);p.add_argument('--php',default='php');a=p.parse_args()
roots=[Path(a.before_root).resolve(),Path(a.after_root).resolve()];processes=[]
with tempfile.TemporaryDirectory(prefix='gta53-local-theme-')as d:
 t=Path(d);db=t/'fictional.sqlite';db.touch();secret='Fictional-only-53-'+secrets.token_urlsafe(14);key='base64:'+base64.b64encode(secrets.token_bytes(32)).decode();base=os.environ.copy();base.update(APP_ENV='local',APP_DEBUG='false',APP_KEY=key,DB_CONNECTION='sqlite',DB_DATABASE=str(db),SESSION_DRIVER='database',SESSION_ENCRYPT='true',SESSION_SECURE_COOKIE='false',CACHE_STORE='database',MAIL_MAILER='array',QUEUE_CONNECTION='sync',GTA_MFA_ENROLMENT_ENABLED='false',GTA_MFA_PILOT_EMAILS='',GTA_SCHEDULE_PORTAL_REMINDERS='false',GTA_SOURCE_TEST_PASSWORD=secret)
 envs=[]
 for i,(root,port)in enumerate(zip(roots,[a.before_port,a.after_port])):
  storage=t/str(i)/'storage'
  for n in ['app/private','framework/cache/data','framework/cache/locks','framework/views','framework/sessions','logs']:(storage/n).mkdir(parents=True,exist_ok=True)
  env=base.copy();env.update(APP_URL='http://127.0.0.1:'+str(port),SESSION_COOKIE='gta53_theme_'+str(i),LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(t/str(i)/'config.php'),APP_ROUTES_CACHE=str(t/str(i)/'routes.php'));envs.append(env)
 result=subprocess.run([a.php,'artisan','migrate','--force','--no-interaction'],cwd=roots[1],env=envs[1],capture_output=True,text=True);assert result.returncode==0,result.stderr
 bootstrap=r"require 'vendor/autoload.php';$a=require 'bootstrap/app.php';$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();"
 seed=r"""$users=[];foreach(['admin','ceo','tutor','apprentice','employer','manager','mentor','careers_adviser','functional_skills_tutor','inclusion_lead','inclusion_member','staff']as$role){$u=App\Models\User::factory()->create(['role'=>$role,'name'=>'Fictional '.str_replace('_',' ',$role).' theme check','email'=>'theme53-'.$role.'@example.test','password'=>getenv('GTA_SOURCE_TEST_PASSWORD')]);$users[$role]=$u;} $group=App\Models\TutorGroup::factory()->create(['name'=>'Fictional theme group','tutor_id'=>$users['tutor']->id]);$apprentice=App\Models\Apprentice::factory()->create(['user_id'=>$users['apprentice']->id,'first_name'=>'Fictional','last_name'=>'Theme apprentice','tutor_id'=>$users['tutor']->id,'tutor_group_id'=>$group->id,'mentor_id'=>$users['mentor']->id,'careers_adviser_id'=>$users['careers_adviser']->id]);foreach(['Approved','Awaiting marking','Referred','Draft']as$i=>$status){App\Models\LearningTask::factory()->create(['apprentice_id'=>$apprentice->id,'reference'=>'theme53-'.$i,'title'=>'Fictional theme task '.($i+1),'status'=>$status,'template'=>App\Support\TaskCatalogue::template('b1-t3')]);}echo json_encode(['apprentice'=>$apprentice->id]);"""
 seeded=subprocess.run([a.php,'-r',bootstrap+seed],cwd=roots[1],env=envs[1],capture_output=True,text=True);assert seeded.returncode==0,seeded.stderr;ids=json.loads(seeded.stdout)
 view=t/'theme53_fixture.blade.php';view.write_text('''@extends('layout')
@section('title','Fictional theme checks')
@section('content')
<h1>Shared theme checks</h1><p id="theme-intro">Fictional interface fixtures only. These are not operational records.</p>
<section class="panel"><h2>Labels, links and supporting text</h2><p>Primary text and values: 123.45. <a href="#theme-fields">Underlined action link</a></p><p class="muted">Secondary text should remain readable in either theme.</p><nav class="section-nav" aria-label="Fixture navigation"><a href="#theme-fields" aria-current="page">Selected fixture</a><a href="#theme-table">Other fixture</a></nav><div class="notice">Notice with <a href="#theme-fields">a notice action</a>.</div><div class="errors" role="alert"><h2>Check the highlighted fields</h2><p>Fictional validation message for contrast review.</p></div></section>
<section class="panel" id="theme-fields"><h2>Native controls and focus</h2><form method="get" action="/__theme53-preview"><label for="fixture-name">Entered value</label><input id="fixture-name" value="Fictional entered text"><label for="fixture-placeholder">Placeholder</label><input id="fixture-placeholder" placeholder="Example placeholder text"><label for="fixture-readonly">Read-only field</label><input id="fixture-readonly" value="Read-only example" readonly><label for="fixture-disabled">Disabled field</label><input id="fixture-disabled" value="Disabled example" disabled><label for="fixture-choice">Drop-down</label><select id="fixture-choice"><option>First fictional choice</option><option>Second fictional choice</option></select><label for="fixture-date">Date</label><input id="fixture-date" type="date" value="2026-11-16"><label for="fixture-email">Required email</label><input id="fixture-email" type="email" required placeholder="name@example.test"><label class="check"><input type="checkbox">Fictional checkbox label</label><button type="submit" class="primary">Check native validation</button><button type="button" disabled>Disabled action</button></form></section>
<section class="panel" id="theme-table"><h2>Table and status labels</h2><div class="table-scroll"><table><thead><tr><th>Measure</th><th>Recorded value</th><th>Status</th></tr></thead><tbody><tr><td>Fictional completed work</td><td>12</td><td><span class="status green">Recorded</span></td></tr><tr><td>Fictional action</td><td>2</td><td><span class="status red">Overdue</span></td></tr><tr><td>Source check</td><td>Unknown</td><td><span class="status neutral">Needs checking</span></td></tr></tbody></table></div></section>
@include('visuals.controls')
@include('visuals.panel',['chart'=>['key'=>'theme-check','title'=>'Fictional chart','note'=>'Rendered fixture values only','type'=>'bars','unit'=>'records','rows'=>[['label'=>'Recorded','value'=>12],['label'=>'Unknown','value'=>null],['label'=>'Zero','value'=>0]]]])
@endsection''')
 for i,(root,port,env)in enumerate(zip(roots,[a.before_port,a.after_port],envs)):
  router=t/str(i)/'router.php';router.write_text("<?php $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$root="+json.dumps(str(root))+";if(str_starts_with($path,'/assets/')){return false;}require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$app['view']->addLocation("+json.dumps(str(t))+ ");Illuminate\\Support\\Facades\\Route::middleware(['web','auth'])->get('/__theme53-preview',fn()=>response()->view('theme53_fixture'));$app->handleRequest(Illuminate\\Http\\Request::capture());")
  processes.append(subprocess.Popen([a.php,'-S','127.0.0.1:'+str(port),'-t',str(root/'public'),str(router)],cwd=root,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True))
 for port in [a.before_port,a.after_port]:
  for _ in range(100):
   try:
    with socket.create_connection(('127.0.0.1',port),timeout=.2):break
   except OSError:time.sleep(.05)
 report={'before':'http://127.0.0.1:'+str(a.before_port),'after':'http://127.0.0.1:'+str(a.after_port),'password':secret,'roles':['admin','ceo','tutor','apprentice','employer','manager','mentor','careers_adviser','functional_skills_tutor','inclusion_lead','inclusion_member','staff'],'ids':ids,'real_data_used':False,'production_deployed':False};Path(a.output).write_text(json.dumps(report));print('Loopback-only fictional theme fixtures ready',flush=True)
 try:
  while True:time.sleep(1)
 except KeyboardInterrupt:pass
 finally:
  for process in processes:
   try:os.killpg(process.pid,signal.SIGTERM);process.wait(timeout=5)
   except ProcessLookupError:pass
