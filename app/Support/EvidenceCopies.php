<?php
namespace App\Support;

use App\Models\EvidenceCopyPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EvidenceCopies
{
    private static function profile(string $disk): array
    {
        PrivateEvidence::diskName(['disk'=>$disk]);
        return collect(config('filesystems.disks.'.$disk))->only('driver','root','prefix','bucket','endpoint','region','url','visibility')->all();
    }
    public static function proposal(string $source,string $target): array
    {
        if($source===$target){throw new \RuntimeException('Choose distinct configured disks.');}
        $snapshot=EvidenceInventory::copyManifest($source);$max=(int)config('evidence_copy.max_object_bytes');
        if(!$snapshot['objects'] || $max<1){throw new \RuntimeException('No eligible bounded source objects.');}
        foreach($snapshot['objects'] as $object){if($object['bytes']>$max){throw new \RuntimeException('Source exceeds this copy workflow limit.');}}
        $data=['source_disk'=>$source,'target_disk'=>$target,'source_profile'=>self::profile($source),'target_profile'=>self::profile($target),'inventory_binding'=>$snapshot['inventory_binding'],'max_object_bytes'=>$max,'objects'=>$snapshot['objects']];
        return ['private_manifest'=>$data,'proposal_hash'=>ReviewedImports::hash($data),'objects'=>count($snapshot['objects']),'bytes'=>array_sum(array_column($snapshot['objects'],'bytes'))];
    }
    public static function prepare(string $source,string $target,string $expected,string $reference): EvidenceCopyPlan
    {
        if(!app()->environment('staging') || !filled($reference) || mb_strlen($reference)>1000){throw new \RuntimeException('Record plans only in staging with a controlled reference.');}
        $proposal=self::proposal($source,$target);if(!hash_equals($proposal['proposal_hash'],$expected)){throw new \RuntimeException('Proposal changed. Preview again.');}
        return DB::transaction(function()use($source,$target,$proposal,$reference){
            $id=(string)Str::uuid();$manifest=$proposal['private_manifest'];$items=[];
            foreach($manifest['objects'] as $token=>$object){$items[]=['token'=>$token,'source'=>$object,'destination'=>['disk'=>$target,'path'=>'gta-staged-copies/'.$id.'/'.$token.'.bin','sha256'=>$object['sha256'],'bytes'=>$object['bytes']]];}
            unset($manifest['objects']);$manifest['items']=$items;$manifest['proposal_hash']=$proposal['proposal_hash'];
            $plan=EvidenceCopyPlan::create(['id'=>$id,'source_disk'=>$source,'target_disk'=>$target,'reference'=>$reference,'manifest'=>$manifest,'manifest_hash'=>ReviewedImports::hash($manifest)]);
            self::event($plan,'plan_recorded',['objects'=>count($items),'bytes'=>$proposal['bytes']]);return $plan;
        });
    }
    private static function intact(EvidenceCopyPlan $plan): bool
    {
        $manifest=$plan->manifest;
        if(!is_array($manifest) || ReviewedImports::hash($manifest)!==$plan->manifest_hash || ($manifest['source_disk']??null)!==$plan->source_disk || ($manifest['target_disk']??null)!==$plan->target_disk || $plan->source_disk===$plan->target_disk || !is_int($manifest['max_object_bytes']??null) || $manifest['max_object_bytes']<1 || !is_array($manifest['items']??null) || !$manifest['items'] || !array_is_list($manifest['items'])){return false;}
        $objects=[];
        foreach($manifest['items'] as $item){
            $token=$item['token']??null;$source=$item['source']??null;$target=$item['destination']??null;
            if(!is_string($token) || !preg_match('/^[a-f0-9]{64}$/',$token) || isset($objects[$token]) || !is_array($source) || !is_array($target)){return false;}
            try{$location=PrivateEvidence::location($source);}catch(\Throwable){return false;}
            if($source['disk']!==$plan->source_disk || ReviewedImports::hash($location)!==$token || !is_int($source['bytes']??null) || $source['bytes']<1 || $source['bytes']>$manifest['max_object_bytes'] || !is_string($source['sha256']??null) || !preg_match('/^[a-f0-9]{64}$/',$source['sha256'])){return false;}
            if(ReviewedImports::hash($target)!==ReviewedImports::hash(['disk'=>$plan->target_disk,'path'=>'gta-staged-copies/'.$plan->id.'/'.$token.'.bin','sha256'=>$source['sha256'],'bytes'=>$source['bytes']])){return false;}
            $objects[$token]=$source;
        }
        $proposal=$manifest;unset($proposal['items'],$proposal['proposal_hash']);$proposal['objects']=$objects;
        return ReviewedImports::hash($proposal)===($manifest['proposal_hash']??null);
    }

    private static function current(EvidenceCopyPlan $plan): bool
    {
        if(!self::intact($plan)){return false;}
        return self::proposal($plan->source_disk,$plan->target_disk)['proposal_hash']===$plan->manifest['proposal_hash'];
    }
    // Internal recovery API: pinned copies may be rechecked even if live originals/references change.
    // Never expose returned locators in a response, CLI output or notification.
    public static function verifiedManifest(EvidenceCopyPlan $plan): array
    {
        if(!self::intact($plan) || $plan->state!=='Verified staging copies' || !is_array($plan->result) || ($plan->result['source_stable_at_finish']??false)!==true){throw new \RuntimeException('A verified intact retained copy plan is required.');}
        $items=$plan->manifest['items'];$results=$plan->result['items']??[];
        if(!is_array($results) || !array_is_list($results) || count($results)!==count($items) || ($plan->result['verified_copies']??null)!==count($items)){throw new \RuntimeException('Copy result is incomplete.');}
        foreach($items as $index=>$item){if(($results[$index]['token']??null)!==$item['token'] || ($results[$index]['status']??null)!=='verified_copy'){throw new \RuntimeException('Copy result binding disagrees.');}}
        foreach(['source','target'] as $side){if(ReviewedImports::hash(self::profile($plan->{$side.'_disk'}))!==ReviewedImports::hash($plan->manifest[$side.'_profile']??[])){throw new \RuntimeException('Copy storage profile changed.');}}
        return $plan->manifest;
    }
    public static function summary(EvidenceCopyPlan $plan): array
    {
        $intact=self::intact($plan);
        return ['plan_id'=>$plan->id,'version'=>$plan->version,'state'=>$plan->state,'manifest_hash'=>$plan->manifest_hash,'integrity'=>$intact?'intact':'needs_checks','objects'=>$intact?count($plan->manifest['items']):null,'result'=>self::safeResult($plan->result),'application_links_changed'=>false,'sources_deleted'=>false,'cutover_approved'=>false];
    }
    private static function safeResult(?array $result): ?array
    {
        if($result===null){return null;}
        $items=[];foreach(($result['items']??[]) as $item){if(is_string($item['token']??null) && preg_match('/^[a-f0-9]{64}$/',$item['token']) && in_array($item['status']??null,['verified_copy','copy_failed'],true)){$items[]=['token'=>$item['token'],'status'=>$item['status']];}}
        return ['items'=>$items,'verified_copies'=>count(array_filter($items,fn($item)=>$item['status']==='verified_copy')),'source_stable_at_finish'=>($result['source_stable_at_finish']??false)===true,'cutover_approved'=>false];
    }
    public static function execute(EvidenceCopyPlan $plan,int $version,string $expected): EvidenceCopyPlan
    {
        if(!app()->environment('staging') || config('evidence_copy.enabled')!==true){throw new \RuntimeException('Staging copy execution is disabled.');}
        foreach([$plan->source_disk,$plan->target_disk] as $name){$settings=self::profile($name);if(($settings['driver']??null)==='s3' && !empty($settings['endpoint']) && parse_url($settings['endpoint'],PHP_URL_SCHEME)!=='https'){throw new \RuntimeException('Staging S3 requires HTTPS.');}}
        // Claim once. No database transaction is held while transferring file bytes.
        $claimed=DB::transaction(function()use($plan,$version,$expected){
            $row=EvidenceCopyPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if($row->state!=='Planned' || $row->version!==$version || !hash_equals($row->manifest_hash,$expected) || !self::current($row)){throw new \RuntimeException('Plan is stale, altered or already claimed.');}
            $row->state='Running';$row->version++;$row->started_at=now();$row->save();self::event($row,'copy_started',['objects'=>count($row->manifest['items'])]);return $row;
        });
        $items=[];$ok=true;
        foreach($claimed->manifest['items'] as $item){
            $status='copy_failed';
            try{
                // A pre-write intent survives interruption; destination keys remain in the protected manifest.
                self::event($claimed,'object_intended',['token'=>$item['token']]);
                PrivateEvidence::copyExclusive($item['source'],$item['destination'],$claimed->manifest['max_object_bytes']);
                $actual=PrivateEvidence::fingerprint($item['destination']);
                if(!$actual['exists'] || $actual['sha256']!==$item['source']['sha256'] || $actual['bytes']!==$item['source']['bytes']){throw new \RuntimeException('Destination readback differs.');}
                $status='verified_copy';self::event($claimed,'object_verified',['token'=>$item['token']]);
            }catch(\Throwable){$ok=false;}
            $items[]=['token'=>$item['token'],'status'=>$status];if(!$ok){break;}
        }
        $stable=false;
        if($ok){
            try{
                $stable=true;
                foreach($claimed->manifest['items'] as $item){
                    foreach(['source','destination'] as $side){$actual=PrivateEvidence::fingerprint($item[$side]);if(!$actual['exists'] || $actual['sha256']!==$item['source']['sha256'] || $actual['bytes']!==$item['source']['bytes']){$stable=false;break 2;}}
                }
                $stable=self::current($claimed) && $stable;
            }catch(\Throwable){$stable=false;}
        }
        // Object writes cannot be rolled back with SQL. Never delete evidence on audit/finish failure.
        return DB::transaction(function()use($claimed,$items,$ok,$stable){
            $row=EvidenceCopyPlan::whereKey($claimed->id)->lockForUpdate()->firstOrFail();if($row->state!=='Running' || $row->version!==$claimed->version){throw new \RuntimeException('Running state changed; reconcile retained staging keys.');}
            $row->state=$ok && $stable?'Verified staging copies':'Needs checks';$row->version++;$row->finished_at=now();$row->result=['items'=>$items,'verified_copies'=>count(array_filter($items,fn($i)=>$i['status']==='verified_copy')),'source_stable_at_finish'=>$stable,'cutover_approved'=>false];$row->save();self::event($row,'copy_finished',['state'=>$row->state,'verified_copies'=>$row->result['verified_copies']]);return $row;
        });
    }
    private static function event(EvidenceCopyPlan $plan,string $event,array $summary): void
    {
        DB::table('evidence_copy_events')->insert(['plan_id'=>$plan->id,'event'=>$event,'origin'=>'console','summary'=>json_encode($summary,JSON_THROW_ON_ERROR),'created_at'=>now()]);
    }
}
