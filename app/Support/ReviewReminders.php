<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\ReviewReminder;
use App\Models\ProgrammeOutcome;
use App\Models\SignedReviewRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewReminders
{
    public static function allowed(User $u): bool
    {
        return $u->active && (in_array($u->role,['admin','ceo','tutor'],true) || StaffDuties::has($u,'mentor'));
    }

    // Only checked actual meeting records create due prompts. First-review references stay provisional.
    public static function position(Apprentice $a, bool $lock=false): array
    {
        if($lock){SignedReviewRecord::where('apprentice_id',$a->id)->orderBy('id')->lockForUpdate()->get();}
        $cycle=SignedReviews::cycle($a);
        $outcome=ProgrammeOutcome::where('apprentice_id',$a->id)->when($lock,fn($q)=>$q->lockForUpdate())->first();
        $status=$outcome?->context['status']??'Unknown';
        $statusCheck=!in_array($status,['Active','Break in learning','Completed','Withdrawn','Transferred'],true) || !filled($outcome?->context['sourceRef']??null);
        $paused=!$statusCheck && $status!=='Active';
        $due=!$statusCheck && !$paused && $cycle['next_due'] && $cycle['next_due']<=today()->toDateString();
        $items=[];$ownerChecks=0;
        if($due){
            $record=SignedReviewRecord::findOrFail($cycle['latest_record_id']);
            foreach(['tutor','mentor'] as $role){
                $owner=$a->{$role.'_id'} ? User::whereKey($a->{$role.'_id'})->when($lock,fn($q)=>$q->lockForUpdate())->first() : null;
                if(!$owner || !$owner->active || ($role==='mentor' ? !StaffDuties::has($owner,'mentor') : $owner->role!=='tutor')){$ownerChecks++;continue;}
                $source=['outcome_id'=>$outcome->id,'status_binding'=>ReviewedImports::hash(collect($outcome->context)->only('status','sourceRef','endDate')->all()),'apprentice_id'=>$a->id,'record_id'=>$record->id,'record_version'=>$record->version,'snapshot_hash'=>$record->snapshot_hash,'checked_at'=>$record->checked_at->toIso8601String(),'meeting_date'=>$cycle['last_meeting'],'due_date'=>$cycle['next_due'],'recipient_id'=>$owner->id,'recipient_role'=>$role];
                if($role==='mentor' && ($binding=StaffDuties::binding($owner,'mentor'))!==null){$source['duty_binding']=$binding;}
                $key=ReviewedImports::hash($source);
                $reminder=ReviewReminder::where('source_key',$key)->first();
                $intact=!$reminder || (ReviewedImports::hash($reminder->source)===$key && $reminder->apprentice_id===$a->id && $reminder->signed_review_record_id===$record->id && $reminder->recipient_id===$owner->id);
                $items[]=['owner'=>$owner,'source'=>$source,'key'=>$key,'reminder'=>$reminder,'intact'=>$intact];
            }
        }
        return ['programme_status'=>$status,'programme_check'=>$statusCheck,'paused'=>$paused,'cycle'=>$cycle,'due'=>(bool)$due,'owner_checks'=>$ownerChecks,'items'=>$items];
    }

    public static function generate(bool $record=false, ?User $actor=null): array
    {
        if($actor){abort_unless(LearningAccess::manager($actor),403);}
        $run=function() use($record,$actor): array {
            if($actor && $record){$actor=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();abort_unless(LearningAccess::manager($actor),403);}
            $counts=['apprentices'=>0,'due'=>0,'programme_checks'=>0,'paused'=>0,'history_checks'=>0,'owner_checks'=>0,'source_checks'=>0,'eligible'=>0,'already_recorded'=>0,'awaiting_seen'=>0,'created'=>0];
            Apprentice::orderBy('id')->chunkById(50,function($people) use(&$counts,$record,$actor): void {
                foreach($people as $a){
                    if($record){$a=Apprentice::whereKey($a->id)->lockForUpdate()->firstOrFail();}
                    $p=self::position($a,$record);$counts['apprentices']++;
                    if($p['programme_check']){$counts['programme_checks']++;continue;}
                    if($p['paused']){$counts['paused']++;continue;}
                    if(!$p['cycle']['next_due']){$counts['history_checks']++;continue;}
                    if(!$p['due']){continue;}$counts['due']++;$counts['owner_checks']+=$p['owner_checks'];
                    foreach($p['items'] as $item){
                        if(!$item['intact']){$counts['source_checks']++;continue;}
                        $counts['eligible']++;
                        if($item['reminder']){$counts['already_recorded']++;if(!$item['reminder']->seen_at){$counts['awaiting_seen']++;}continue;}
                        if($record){
                            $source=$item['source'];
                            $reminder=ReviewReminder::create(['apprentice_id'=>$a->id,'signed_review_record_id'=>$source['record_id'],'recipient_id'=>$item['owner']->id,'source'=>$source,'source_key'=>$item['key']]);
                            self::event($reminder,$actor?->id,'recorded',$actor?'administrator':'command');$counts['created']++;$counts['awaiting_seen']++;
                        }
                    }
                }
            });
            return $counts;
        };
        return $record ? DB::transaction($run) : $run();
    }

    public static function seen(ReviewReminder $reminder, User $user): void
    {
        abort_unless(self::allowed($user) && $reminder->recipient_id===$user->id,403);
        DB::transaction(function() use($reminder,$user): void {
            $a=Apprentice::whereKey($reminder->apprentice_id)->lockForUpdate()->firstOrFail();
            $fresh=User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless(LearningAccess::tutor($fresh,$a) || StaffDuties::assigned($fresh,$a,'mentor'),403);
            $position=self::position($a,true);
            $item=collect($position['items'])->first(fn($item)=>$item['owner']->id===$fresh->id && $item['key']===$reminder->source_key);
            $row=ReviewReminder::whereKey($reminder->id)->lockForUpdate()->firstOrFail();
            if(!$item || !$item['intact'] || ReviewedImports::hash($row->source)!==$item['key'] || $row->apprentice_id!==$a->id || $row->signed_review_record_id!==$item['source']['record_id'] || $row->recipient_id!==$fresh->id){
                throw ValidationException::withMessages(['reminder'=>'This reminder is no longer current or its source needs checking. Reopen Progress reviews.']);
            }
            if(!$row->seen_at){$row->seen_at=now();$row->save();self::event($row,$fresh->id,'seen','recipient');}
        });
    }

    private static function event(ReviewReminder $r, ?int $actor, string $event, string $origin): void
    {
        DB::table('review_reminder_events')->insert(['review_reminder_id'=>$r->id,'actor_id'=>$actor,'event'=>$event,'origin'=>$origin,'created_at'=>now()]);
    }
}
