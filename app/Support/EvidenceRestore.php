<?php
namespace App\Support;
use App\Models\{EvidenceCopyPlan,EvidenceRestoreRun};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EvidenceRestore
{
    private static function staging(): void
    {
        if(!app()->environment('staging') || StagingAccess::errors()!==[]){throw new \RuntimeException('Controlled staging configuration required.');}
    }
    private static function profile(string $disk,bool $requireRoot = true): array
    {
        PrivateEvidence::diskName(['disk'=>$disk]);
        $profile=collect(config('filesystems.disks.'.$disk))->only('driver','root','prefix','bucket','endpoint','region','url','visibility')->all();
        if($profile['driver']==='local'){
            $configured=(string)($profile['root']??'');$root=realpath($configured);
            if(!$root){
                if($requireRoot || !str_starts_with($configured,'/')){throw new \RuntimeException('Create the dedicated private root before previewing.');}
                // The original disk may be unavailable during recovery. Resolve its nearest existing
                // ancestor for overlap checks without reading any original evidence or requiring its mount.
                $missing=[];$cursor=rtrim($configured,'/');
                while(!($base=realpath($cursor))){$missing[]=basename($cursor);$parent=dirname($cursor);if($parent===$cursor){throw new \RuntimeException('Private root cannot be resolved.');}$cursor=$parent;}
                $root=rtrim($base,'/').'/'.implode('/',array_reverse($missing));
            }
            if($requireRoot && !is_dir($root)){throw new \RuntimeException('Restore root must be an existing private directory.');}
            $profile['resolved_root']=rtrim(str_replace('\\','/',$root),'/').'/';
        }elseif(!is_string($profile['bucket']??null) || trim($profile['bucket'])===''){throw new \RuntimeException('A configured private bucket is required.');}
        elseif(!empty($profile['endpoint']) && parse_url($profile['endpoint'],PHP_URL_SCHEME)!=='https'){throw new \RuntimeException('Private remote storage requires HTTPS.');}
        return $profile;
    }
    private static function isolatedTarget(string $target): array
    {
        if(!in_array($target,config('evidence_restore.targets',[]),true) || in_array($target,[config('evidence.disk'),config('evidence.legacy_disk')],true)){throw new \RuntimeException('Choose an explicitly allowed separate restore disk.');}
        $profile=self::profile($target);
        foreach(config('evidence.private_disks',[]) as $disk){
            if($disk===$target){continue;}$other=self::profile($disk,false);
            if($profile['driver']==='local' && $other['driver']==='local'){
                $a=$profile['resolved_root'];$b=$other['resolved_root'];
                if(str_starts_with($a,$b) || str_starts_with($b,$a)){throw new \RuntimeException('Restore root overlaps another approved private disk.');}
            }
            if($profile['driver']==='s3' && $other['driver']==='s3' && (empty($profile['bucket']) || ($profile['bucket']??null)===($other['bucket']??null))){throw new \RuntimeException('A separate restore bucket is required.');}
        }
        return $profile;
    }
    private static function boundedManifest(EvidenceCopyPlan $plan): array
    {
        $manifest=EvidenceCopies::verifiedManifest($plan);$limit=config('evidence_restore.max_objects');$max=config('evidence_restore.max_object_bytes');
        if(!is_int($limit) || $limit<1 || $limit>1000 || count($manifest['items'])>$limit || !is_int($max) || $max<1 || $max>20*1024*1024){throw new \RuntimeException('Restore rehearsal exceeds configured bounded scope.');}
        foreach($manifest['items'] as $item){if($item['source']['bytes']>$max){throw new \RuntimeException('Object exceeds restore limit.');}}
        return $manifest;
    }
    public static function backupSummary(EvidenceCopyPlan $plan): array
    {
        $manifest=self::boundedManifest($plan);
        return ['plan_id'=>$plan->id,'version'=>$plan->version,'objects'=>count($manifest['items']),'declared_bytes'=>array_sum(array_column(array_column($manifest['items'],'source'),'bytes')),'file_reads'=>false,'writes_performed'=>false,'originals_read'=>false,'cutover_approved'=>false];
    }
    public static function verifyBackup(EvidenceCopyPlan $plan): array
    {
        self::staging();$plan=$plan->fresh();$manifest=self::boundedManifest($plan);$verified=0;$issues=0;
        foreach($manifest['items'] as $item){
            try{$actual=PrivateEvidence::fingerprint($item['destination'],config('evidence_restore.max_object_bytes'));if($actual['exists'] && $actual['bytes']===$item['source']['bytes'] && hash_equals($item['source']['sha256'],$actual['sha256'])){$verified++;}else{$issues++;}}
            catch(\Throwable){$issues++;}
        }
        $stable=false;
        try{$after=$plan->fresh();$stable=$after->version===$plan->version && $after->manifest_hash===$plan->manifest_hash && ReviewedImports::hash(self::boundedManifest($after))===ReviewedImports::hash($manifest);}catch(\Throwable){}
        return ['result'=>$issues===0 && $stable?'verified_retained_copies':'needs_checks','objects'=>count($manifest['items']),'verified_objects'=>$verified,'issues'=>$issues,'binding_stable'=>$stable,'file_reads'=>true,'writes_performed'=>false,'originals_read'=>false,'cutover_approved'=>false];
    }
    public static function proposal(EvidenceCopyPlan $plan,string $target): array
    {
        $manifest=self::boundedManifest($plan);
        if(in_array($target,[$plan->source_disk,$plan->target_disk],true)){throw new \RuntimeException('Restore target must be a third disk.');}
        $targetProfile=self::isolatedTarget($target);
        $data=['copy_plan_id'=>$plan->id,'copy_version'=>$plan->version,'copy_manifest_hash'=>$plan->manifest_hash,'target_disk'=>$target,'target_profile'=>$targetProfile,'max_object_bytes'=>config('evidence_restore.max_object_bytes'),'max_objects'=>config('evidence_restore.max_objects'),'objects'=>array_map(fn($item)=>['token'=>$item['token'],'source'=>$item['destination']],$manifest['items'])];
        return ['private_manifest'=>$data,'proposal_hash'=>ReviewedImports::hash($data),'objects'=>count($data['objects']),'bytes'=>array_sum(array_column(array_column($data['objects'],'source'),'bytes'))];
    }
    public static function prepare(EvidenceCopyPlan $plan,string $target,string $expected,string $reference): EvidenceRestoreRun
    {
        self::staging();if(!filled($reference) || mb_strlen($reference)>1000){throw new \RuntimeException('A controlled rehearsal reference is required.');}
        $proposal=self::proposal($plan->fresh(),$target);if(!hash_equals($proposal['proposal_hash'],$expected)){throw new \RuntimeException('Restore proposal changed.');}
        return DB::transaction(function()use($plan,$target,$proposal,$reference){
            $parent=EvidenceCopyPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if(self::proposal($parent,$target)['proposal_hash']!==$proposal['proposal_hash']){throw new \RuntimeException('Copy plan changed before recording.');}
            $id=(string)Str::uuid();$manifest=$proposal['private_manifest'];$items=[];
            foreach($manifest['objects'] as $object){$items[]=[...$object,'destination'=>['disk'=>$target,'path'=>'gta-restore-rehearsals/'.$id.'/'.$object['token'].'.bin','sha256'=>$object['source']['sha256'],'bytes'=>$object['source']['bytes']]];}
            unset($manifest['objects']);$manifest['items']=$items;$manifest['proposal_hash']=$proposal['proposal_hash'];
            $run=EvidenceRestoreRun::create(['id'=>$id,'copy_plan_id'=>$parent->id,'reference'=>$reference,'manifest'=>$manifest,'manifest_hash'=>ReviewedImports::hash($manifest)]);self::event($run,'prepared',['objects'=>count($items)]);return $run;
        });
    }
    private static function intact(EvidenceRestoreRun $run): bool
    {
        try{
            $m=$run->manifest;if(!is_array($m) || ReviewedImports::hash($m)!==$run->manifest_hash || ($m['copy_plan_id']??null)!==$run->copy_plan_id || !is_array($m['items']??null) || !array_is_list($m['items']) || !$m['items']){return false;}
            $plan=EvidenceCopyPlan::findOrFail($run->copy_plan_id);$proposal=self::proposal($plan,$m['target_disk']);if($proposal['proposal_hash']!==($m['proposal_hash']??null)){return false;}
            $expected=$proposal['private_manifest'];$items=[];
            foreach($expected['objects'] as $object){$items[]=[...$object,'destination'=>['disk'=>$m['target_disk'],'path'=>'gta-restore-rehearsals/'.$run->id.'/'.$object['token'].'.bin','sha256'=>$object['source']['sha256'],'bytes'=>$object['source']['bytes']]];}
            unset($expected['objects']);$expected['items']=$items;$expected['proposal_hash']=$proposal['proposal_hash'];return ReviewedImports::hash($expected)===$run->manifest_hash;
        }catch(\Throwable){return false;}
    }
    public static function summary(EvidenceRestoreRun $run): array
    {
        $intact=self::intact($run);$result=$run->result;
        return ['run_id'=>$run->id,'copy_plan_id'=>$run->copy_plan_id,'version'=>$run->version,'state'=>in_array($run->state,['Prepared','Running','Verified restore rehearsal','Needs checks'],true)?$run->state:'Needs checks','manifest_hash'=>$run->manifest_hash,'integrity'=>$intact?'intact':'needs_checks','objects'=>$intact?count($run->manifest['items']):null,'verified_objects'=>is_int($result['verified_objects']??null)?$result['verified_objects']:null,'recorded_finished_at'=>$run->finished_at?->toIso8601String(),'application_links_changed'=>false,'originals_read'=>false,'sources_deleted'=>false,'cutover_approved'=>false];
    }
    public static function verify(EvidenceRestoreRun $run): array
    {
        self::staging();$run=$run->fresh();if($run->state!=='Verified restore rehearsal' || !self::intact($run)){throw new \RuntimeException('An intact completed rehearsal is required.');}
        $ok=0;$issues=0;
        foreach($run->manifest['items'] as $item){
            foreach(['source','destination'] as $side){
                try{$actual=PrivateEvidence::fingerprint($item[$side],$run->manifest['max_object_bytes']);if(!$actual['exists'] || $actual['bytes']!==$item['source']['bytes'] || $actual['sha256']!==$item['source']['sha256']){$issues++;continue 2;}}
                catch(\Throwable){$issues++;continue 2;}
            }$ok++;
        }
        $after=$run->fresh();$stable=$after->version===$run->version && $after->manifest_hash===$run->manifest_hash && self::intact($after);
        return ['result'=>$issues===0 && $stable?'verified_rehearsal_objects':'needs_checks','objects'=>count($run->manifest['items']),'verified_objects'=>$ok,'issues'=>$issues,'binding_stable'=>$stable,'file_reads'=>true,'writes_performed'=>false,'originals_read'=>false,'cutover_approved'=>false];
    }
    public static function execute(EvidenceRestoreRun $run,int $version,string $expected): EvidenceRestoreRun
    {
        self::staging();if(config('evidence_restore.enabled')!==true || DB::transactionLevel()!==0){throw new \RuntimeException('Explicit restore switch and a committed standalone operation are required.');}
        $claimed=DB::transaction(function()use($run,$version,$expected){
            $row=EvidenceRestoreRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if($row->state!=='Prepared' || $row->version!==$version || !hash_equals($row->manifest_hash,$expected) || !self::intact($row)){throw new \RuntimeException('Restore record is stale, altered or already claimed.');}
            $row->state='Running';$row->version++;$row->started_at=now();$row->save();self::event($row,'started',['objects'=>count($row->manifest['items'])]);return $row;
        });
        $verified=0;$ok=true;
        foreach($claimed->manifest['items'] as $item){
            try{
                if(config('evidence_restore.enabled')!==true || !self::intact($claimed)){throw new \RuntimeException('Restore configuration changed.');}
                self::event($claimed,'object_intended',['token'=>$item['token']]);PrivateEvidence::copyExclusive($item['source'],$item['destination'],$claimed->manifest['max_object_bytes']);
                $actual=PrivateEvidence::fingerprint($item['destination'],$claimed->manifest['max_object_bytes']);if(!$actual['exists'] || $actual['sha256']!==$item['source']['sha256'] || $actual['bytes']!==$item['source']['bytes']){throw new \RuntimeException('Restore readback differs.');}
                $verified++;self::event($claimed,'object_verified',['token'=>$item['token']]);
            }catch(\Throwable){$ok=false;break;}
        }
        $stable=false;
        if($ok){
            try{
                $stable=true;foreach($claimed->manifest['items'] as $item){foreach(['source','destination'] as $side){$actual=PrivateEvidence::fingerprint($item[$side],$claimed->manifest['max_object_bytes']);if(!$actual['exists'] || $actual['sha256']!==$item['source']['sha256'] || $actual['bytes']!==$item['source']['bytes']){$stable=false;break 2;}}}
                $stable=$stable && config('evidence_restore.enabled')===true && self::intact($claimed->fresh());
            }catch(\Throwable){$stable=false;}
        }
        return DB::transaction(function()use($claimed,$verified,$ok,$stable){
            $row=EvidenceRestoreRun::whereKey($claimed->id)->lockForUpdate()->firstOrFail();if($row->state!=='Running' || $row->version!==$claimed->version){throw new \RuntimeException('Running restore changed; retain all rehearsal keys.');}
            $row->state=$ok && $stable?'Verified restore rehearsal':'Needs checks';$row->version++;$row->finished_at=now();$row->result=['verified_objects'=>$verified,'binding_stable_at_finish'=>$stable,'cutover_approved'=>false];$row->save();self::event($row,'finished',['verified_objects'=>$verified,'binding_stable_at_finish'=>$stable]);return $row;
        });
    }
    private static function event(EvidenceRestoreRun $run,string $event,array $summary): void
    {
        DB::table('evidence_restore_events')->insert(['run_id'=>$run->id,'event'=>$event,'summary'=>json_encode($summary,JSON_THROW_ON_ERROR),'created_at'=>now()]);
    }
}
