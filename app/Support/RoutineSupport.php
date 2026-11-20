<?php
namespace App\Support;

use App\Models\{Apprentice,SupportConcern,SupportPlan,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Routine, non-funded help. No marker, funding claim or external sharing is inferred. */
class RoutineSupport
{
    public static function is(?SupportPlan $p): bool
    {
        return $p && (($p->active_plan['basis'] ?? $p->draft['basis'] ?? null) === 'routine_nonfunded_v1');
    }

    public static function ownerAllowed(User $u, Apprentice $a): bool
    {
        return SupportAccess::team($u,$a);
    }

    public static function save(User $actor, int $auth, Apprentice $profile, ?SupportPlan $plan, array $d): SupportPlan
    {
        return DB::transaction(function () use ($actor,$auth,$profile,$plan,$d) {
            $a=Apprentice::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $u=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($u->active && $u->auth_version===$auth && SupportAccess::team($u,$a),403);
            $p=$plan ? SupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail() : SupportPlan::where('apprentice_id',$a->id)->where('status','!=','Closed')->lockForUpdate()->first();
            if ($p) { abort_unless($p->apprentice_id===$a->id,404); }
            $hash=ReviewedImports::hash(collect($d)->except(['version','profile_version','request_id'])->all());
            foreach ($p?->history??[] as $event) {
                if (($event['request_id']??null)===$d['request_id']) {
                    if (($event['by']??null)!==$u->id || !hash_equals($event['request_hash']??'',$hash)) {
                        throw ValidationException::withMessages(['request_id'=>'This save reference belongs to a different request. Reopen the plan.']);
                    }
                    return $p;
                }
            }
            if ($p && (!$plan || !self::is($p) || $p->status==='Closed')) {
                throw ValidationException::withMessages(['need'=>'A current plan already exists, or this is retained specialist/closed history. Open its existing support record.']);
            }
            if ($a->version!==(int)$d['profile_version'] || ($p?->version??0)!==(int)$d['version']) {
                throw ValidationException::withMessages(['version'=>'The apprentice or support plan changed. Reopen before saving.']);
            }
            $owner=User::whereKey($d['owner_id'])->lockForUpdate()->firstOrFail();
            abort_unless(self::ownerAllowed($owner,$a),403);
            $p??=new SupportPlan(['apprentice_id'=>$a->id]);
            $before=$p->active_plan;
            if ($before && $d['intent']==='activate' && !filled($d['change_reason']??null)) {
                throw ValidationException::withMessages(['change_reason'=>'Briefly explain the change to the active support.']);
            }
            $fields=['type'=>'light_touch','funding'=>'not_funded','referral'=>$p->draft['fields']['referral']??today()->toDateString(),
                'review'=>$d['review'],'barriers'=>$d['need'],'support'=>$d['action'],'view'=>$d['apprentice_view']??null,
                'strengths'=>null,'rationale'=>null,'routine_measure'=>$d['measure']??null,'routine_owner_id'=>$owner->id];
            $p->draft=['basis'=>'routine_nonfunded_v1','fields'=>$fields,'codes'=>[]];
            if (!$p->exists) {
                $c=SupportConcern::create(['apprentice_id'=>$a->id,'raised_by'=>$u->id,'initial_note'=>$d['need']]);
                $c->owner_id=$owner->id; $c->status='Monitoring'; $c->acknowledged_at=now(); $c->acknowledged_by=$u->id;
                $c->next_action=$d['action'];$c->action_due=$d['review'];$c->next_review=$d['review'];
                $c->history=[['event'=>'routine_support_need','by'=>$u->id,'at'=>now()->toIso8601String()]];$c->save();
                $p->concern_ids=[$c->id];
            }
            if ($d['intent']==='activate') {
                // Codes remain empty. Do not fabricate a classification or change apprentice markers.
                $p->active_plan=['basis'=>'routine_nonfunded_v1','fields'=>$fields,'concern_ids'=>$p->concern_ids,'codes'=>[],
                    'draft_hash'=>hash('sha256',json_encode($p->draft)),'approved_at'=>now()->format('Y-m-d\TH:i:s.uP'),
                    'approved_by'=>$u->id,'reason'=>$d['change_reason']??'Routine support recorded and activated'];
                $p->status='Active';
                if ($p->review_owner_id!==$owner->id) {
                    $p->review_owner_id=$owner->id;$p->review_owner_revision=($p->review_owner_revision??0)+1;
                }
                // Update only this routine route's original uncoded need; independently classified cases stay untouched.
                foreach (SupportConcern::where('apprentice_id',$a->id)->whereIn('id',$p->concern_ids)->lockForUpdate()->get() as $c) {
                    if ($c->code===null && collect($c->history??[])->contains('event','routine_support_need') && $c->status!=='Closed') {
                        $c->history=[...($c->history??[]),['event'=>'routine_action_updated','by'=>$u->id,'at'=>now()->toIso8601String(),
                            'before'=>$c->only('owner_id','next_action','action_due','next_review')]];
                        $c->owner_id=$owner->id;$c->next_action=$d['action'];$c->action_due=$d['review'];$c->next_review=$d['review'];$c->version++;$c->save();
                    }
                }
            }
            $p->history=[...($p->history??[]),['event'=>$d['intent']==='activate'?'approved':'routine_draft_saved',
                'basis'=>'routine_nonfunded_v1','by'=>$u->id,'at'=>now()->toIso8601String(),'before_active'=>$before,
                'release'=>$d['intent']==='activate'?$p->active_plan:null,'draft'=>$p->draft,
                'request_id'=>$d['request_id'],'request_hash'=>$hash]];
            if ($p->exists) {$p->version++;}$p->save();
            DB::table('learning_events')->insert(['user_id'=>$u->id,'apprentice_id'=>$a->id,'kind'=>'support','record_id'=>$p->id,
                'event'=>$d['intent']==='activate'?'routine_activated':'routine_draft_saved','created_at'=>now()]);
            return $p;
        },3);
    }
}
