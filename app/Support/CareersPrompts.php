<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\CareersPrompt;
use App\Models\IndividualDeliveryPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CareersPrompts
{
    public static function source(Apprentice $a, array $plan, array $block): array
    {
        return ['apprentice_id'=>$a->id,'recipient_id'=>$a->careers_adviser_id,'block'=>$block['number'],'start_date'=>$block['start'],'plan_hash'=>$plan['hash'],'source_key'=>hash('sha256',json_encode([$a->id,$a->careers_adviser_id,$block['number'],$block['start'],$plan['hash']],JSON_THROW_ON_ERROR))];
    }

    public static function due(Apprentice $a): array
    {
        $plan=DeliveryPlan::approved($a);
        if (! $plan) { return []; }
        return array_values(array_filter($plan['blocks'],fn($b)=>in_array($b['number'],[10,11],true) && $b['start']<=today()->toDateString()));
    }

    public static function worklist($apprentices): array
    {
        $items=[];
        foreach($apprentices as $a) {
            $plan=DeliveryPlan::approved($a);if(! $plan){continue;}
            $adviser=$a->careersAdviser;
            foreach(self::due($a) as $block) {
                $valid=$adviser && $adviser->active && $adviser->role==='careers_adviser';
                $source=$valid ? self::source($a,$plan,$block) : null;
                $items[]=['apprentice'=>$a,'block'=>$block,'source'=>$source,'prompt'=>$source ? CareersPrompt::where('source_key',$source['source_key'])->first() : null,'owner_ok'=>$valid];
            }
        }
        return $items;
    }

    public static function generate(bool $record=false, ?User $actor=null): array
    {
        if($actor){abort_unless(LearningAccess::manager($actor),403);}
        $run=function() use($record,$actor): array {
            if($record && $actor){$actor=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();abort_unless(LearningAccess::manager($actor),403);}
            $counts=['due'=>0,'eligible'=>0,'owner_checks'=>0,'already_recorded'=>0,'created'=>0];
            Apprentice::orderBy('id')->chunkById(50,function($people) use(&$counts,$record,$actor): void {
                foreach($people as $a) {
                    if($record){$a=Apprentice::whereKey($a->id)->lockForUpdate()->firstOrFail();if($a->programme_id){$a->programmeRecord()->lockForUpdate()->first();}IndividualDeliveryPlan::where('apprentice_id',$a->id)->lockForUpdate()->first();}
                    $plan=DeliveryPlan::approved($a);if(! $plan){continue;}
                    $owner=$a->careers_adviser_id ? User::whereKey($a->careers_adviser_id)->when($record,fn($q)=>$q->lockForUpdate())->first() : null;
                    foreach(self::due($a) as $b) {
                        $counts['due']++;
                        if(! $owner || ! $owner->active || $owner->role!=='careers_adviser'){$counts['owner_checks']++;continue;}
                        $counts['eligible']++;$source=self::source($a,$plan,$b);
                        if(CareersPrompt::where('source_key',$source['source_key'])->exists()){$counts['already_recorded']++;continue;}
                        if($record){$p=CareersPrompt::create($source);self::event($p,$actor?->id,'recorded',$actor?'administrator':'command');$counts['created']++;}
                    }
                }
            });
            return $counts;
        };
        return $record ? DB::transaction($run) : $run();
    }

    public static function seen(CareersPrompt $prompt, User $user): void
    {
        abort_unless($user->active && $user->role==='careers_adviser' && $prompt->recipient_id===$user->id,403);
        DB::transaction(function() use($prompt,$user): void {
            $a=Apprentice::whereKey($prompt->apprentice_id)->lockForUpdate()->firstOrFail();
            if($a->programme_id){$a->programmeRecord()->lockForUpdate()->first();}
            IndividualDeliveryPlan::where('apprentice_id',$a->id)->lockForUpdate()->first();
            $fresh=User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->active && $fresh->role==='careers_adviser' && $a->careers_adviser_id===$fresh->id,403);
            $p=CareersPrompt::whereKey($prompt->id)->lockForUpdate()->firstOrFail();$plan=DeliveryPlan::approved($a);
            $b=collect(self::due($a))->firstWhere('number',$p->block);
            abort_unless($plan && $b && self::source($a,$plan,$b)['source_key']===$p->source_key,403);
            if(! $p->seen_at){$p->seen_at=now();$p->save();self::event($p,$fresh->id,'seen','adviser');}
        });
    }

    private static function event(CareersPrompt $p, ?int $actor, string $event, string $origin): void
    {
        DB::table('careers_prompt_events')->insert(['careers_prompt_id'=>$p->id,'actor_id'=>$actor,'event'=>$event,'origin'=>$origin,'created_at'=>now()]);
    }
}
