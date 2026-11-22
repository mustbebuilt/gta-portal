<?php
use App\Models\{User,Programme,ProgrammeSetupTemplate68 as Template};
use App\Support\Rpl68;
use Illuminate\Support\Facades\{Artisan,DB};
Artisan::command('gta:setup68-import-template {file} {--actor=} {--approved-source=}',function(){
 $u=User::find((int)$this->option('actor'));
 if(!$u?->can('create',\App\Models\Apprentice::class)||!filled($this->option('approved-source'))){$this->error('An active authorised administrator/CEO and the controlled source approval reference are required.');return 1;}
 $data=json_decode(file_get_contents($this->argument('file')),true,512,JSON_THROW_ON_ERROR);Rpl68::validateTemplate($data);
 $p=Programme::where('standard_code',$data['standard_code'])->where('version',$data['standard_version'])->where('active',true)->firstOrFail();
 DB::transaction(function()use($p,$data,$u){$row=Template::where('programme_id',$p->id)->lockForUpdate()->first()??new Template(['programme_id'=>$p->id]);$value=$data;$value['source_approval_ref']=$this->option('approved-source');$row->template=$value;$row->source_hash=Rpl68::templateHash($value);$row->version=$row->exists?$row->version+1:1;$row->active=true;$row->save();DB::table('setup_template_events68')->insert(['actor_id'=>$u->id,'template_id'=>$row->id,'version'=>$row->version,'source_hash'=>$row->source_hash,'created_at'=>now()]);});
 $this->info('Controlled template imported. Changed source hashes invalidate affected old approvals; reconcile those plans before release.');return 0;
});
