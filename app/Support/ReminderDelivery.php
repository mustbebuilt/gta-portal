<?php
namespace App\Support;
use App\Models\{Apprentice, LearningTask, MarkingReminder, ReviewReminder, ReminderMail, SupportPlanReminder, User};
use App\Notifications\PortalWorkPrompt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReminderDelivery
{
    public const LABELS = ['prepared'=>'Prepared — review required','reviewed'=>'Reviewed — explicit dispatch required','dispatching'=>'Attempt reserved — outcome may be unknown; do not retry','accepted'=>'SMTP accepted — inbox delivery unconfirmed','needs_check'=>'Delivery outcome needs checking — do not retry','cancelled'=>'Cancelled before dispatch','stale'=>'Source or account changed — not sent'];
    public static function recipients(): array
    {
        $list=config('reminder_delivery.recipients',[]);
        return is_array($list) ? array_values(array_unique(array_map(fn($v)=>strtolower(trim($v)),array_filter($list,fn($v)=>is_string($v)&&trim($v)!=='')))) : [];
    }
    public static function errors(): array
    {
        return RecoveryDelivery::configurationErrors(config('reminder_delivery.enabled')===true,self::recipients(),'staging_reminder_mail_disabled');
    }
    public static function available(): bool { return app()->environment('staging') && self::errors()===[]; }
    public static function administrator(User $user): bool
    {
        return $user->active && $user->email_verified_at && in_array($user->role,['admin','ceo'],true);
    }
    public static function eligible(User $user): bool
    {
        $inclusion = SupportAccess::team($user) && in_array(SupportAccess::settings($user)['duty'], ['member','lead'], true);
        return self::available() && $user->active && $user->email_verified_at && (in_array($user->role,['tutor','mentor'],true) || $inclusion)
            && in_array(strtolower(trim($user->email)),self::recipients(),true)
            && User::whereRaw('LOWER(email) = ?',[strtolower(trim($user->email))])->count()===1;
    }
    public static function hash(array $snapshot): string { return ReviewedImports::hash($snapshot); }
    // Reconcile existing in-portal records against current sources; never creates prompts or marks them seen.
    public static function candidate(User $user): array
    {
        if(!self::eligible($user)){return ['snapshot'=>null,'checks'=>1,'check_message'=>'Account must be active, email-confirmed, unique and an allowed tutor, mentor or Inclusion Team recipient.'];}
        $marking=MarkingReminder::where('recipient_id',$user->id)->whereNull('seen_at')->orderBy('id')->limit(101)->get();
        $reviews=ReviewReminder::where('recipient_id',$user->id)->whereNull('seen_at')->orderBy('id')->limit(101)->get();
        $supportPlans=\App\Models\SupportPlan::whereIn('apprentice_id',SupportAccess::ids($user))->select('id');
        $support=SupportPlanReminder::where('recipient_id',$user->id)->whereIn('support_plan_id',$supportPlans)->whereNull('seen_at')->whereNull('dismissed_at')->orderBy('id')->limit(101)->get();
        if($marking->count()+$reviews->count()+$support->count()>100){return ['snapshot'=>null,'checks'=>1,'check_message'=>'More than 100 unseen source records. Reconcile these in the portal first.'];}
        $items=[];$checks=0;
        foreach($marking as $reminder){
            $task=LearningTask::find($reminder->learning_task_id);
            $source=$task && $user->role==='tutor' ? MarkingReminders::source($task) : null;
            if(!$source || $source['recipient_id']!==$user->id || $source['source_key']!==$reminder->source_key || $source['submission_version']!==$reminder->submission_version || !$source['submitted_at']->equalTo($reminder->submitted_at)){$checks++;continue;}
            $items[]=['kind'=>'marking','id'=>$reminder->id,'key'=>$reminder->source_key];
        }
        foreach($reviews as $reminder){
            $apprentice=Apprentice::find($reminder->apprentice_id);
            $position=$apprentice ? ReviewReminders::position($apprentice) : ['items'=>[]];
            $item=collect($position['items'])->first(fn($item)=>$item['owner']->id===$user->id && $item['key']===$reminder->source_key);
            if(!$item || !$item['intact'] || !is_array($reminder->source) || self::hash($reminder->source)!==$item['key'] || $reminder->signed_review_record_id!==$item['source']['record_id']){$checks++;continue;}
            $items[]=['kind'=>'review','id'=>$reminder->id,'key'=>$reminder->source_key];
        }
        foreach($support as $reminder){
            if(!SupportPlanReminders::current($reminder,$user)){$checks++;continue;}
            $items[]=['kind'=>'support_plan','id'=>$reminder->id,'key'=>$reminder->source_key];
        }
        $snapshot=$items ? ['recipient_id'=>$user->id,'auth_version'=>$user->auth_version,'role'=>$user->role,'email_hash'=>hash('sha256',$user->email),'origin'=>config('app.url'),'items'=>$items] : null;
        return ['snapshot'=>$snapshot,'checks'=>$checks,'check_message'=>$checks ? $checks.' recorded source item(s) no longer match the current position and were excluded.' : ($snapshot ? 'Current source checks passed.' : 'No eligible current source items.')];
    }
    public static function preview(): array
    {
        $rows=[];
        if(!self::available()){return [];}
        // A valid configured pilot has at most ten addresses; never scan the full user table here.
        foreach(self::recipients() as $email){
            $users=User::whereRaw('LOWER(email) = ?',[$email])->limit(2)->get();
            if($users->count()!==1){continue;}
            $user=$users->first();$candidate=self::candidate($user);$snapshot=$candidate['snapshot'];
            $rows[]=['user'=>$user,'checks'=>$candidate['checks'],'check_message'=>$candidate['check_message'],'snapshot'=>$snapshot,'fingerprint'=>$snapshot?self::hash($snapshot):null,'existing'=>$snapshot?ReminderMail::where('fingerprint',self::hash($snapshot))->first():null];
        }
        return $rows;
    }
    private static function actor(User $actor, int $version): void
    {
        abort_unless(self::administrator($actor) && $actor->auth_version===$version,403);
    }
    private static function event(ReminderMail $row, ?int $actor, string $event): void
    {
        DB::table('reminder_mail_events')->insert(['outbox_id'=>$row->id,'actor_id'=>$actor,'event'=>$event,'created_at'=>now()]);
    }
    public static function prepare(User $actor,int $version,int $recipient,string $fingerprint): ReminderMail
    {
        return DB::transaction(function()use($actor,$version,$recipient,$fingerprint){
            $users=User::whereIn('id',[$actor->id,$recipient])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            self::actor($users[$actor->id],$version);$user=$users->get($recipient);
            $snapshot=$user?self::candidate($user)['snapshot']:null;
            if(!$snapshot || !hash_equals(self::hash($snapshot),$fingerprint)){throw ValidationException::withMessages(['delivery'=>'The account or source changed. Reload the preview.']);}
            $existing=ReminderMail::where('fingerprint',$fingerprint)->first();
            if($existing){return $existing;}
            $row=ReminderMail::create(['recipient_id'=>$recipient,'fingerprint'=>$fingerprint,'snapshot'=>$snapshot,'state'=>'prepared','version'=>1,'prepared_by'=>$actor->id]);self::event($row,$actor->id,'prepared');return $row;
        });
    }
    private static function current(ReminderMail $row,User $recipient): bool
    {
        $snapshot=self::candidate($recipient)['snapshot'];
        return $snapshot && is_array($row->snapshot) && $row->recipient_id===$recipient->id && hash_equals(self::hash($row->snapshot),$row->fingerprint) && hash_equals(self::hash($snapshot),$row->fingerprint);
    }
    public static function transition(ReminderMail $row,User $actor,int $authVersion,int $version,string $action): void
    {
        DB::transaction(function()use($row,$actor,$authVersion,$version,$action){
            $row=ReminderMail::whereKey($row->id)->lockForUpdate()->firstOrFail();
            $users=User::whereIn('id',[$actor->id,$row->recipient_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');self::actor($users[$actor->id],$authVersion);
            if($row->version!==$version || !in_array($row->state,['prepared','reviewed'],true)){throw ValidationException::withMessages(['delivery'=>'This delivery changed or has already been reserved. Reload its history.']);}
            if($action==='cancel'){$row->state='cancelled';}
            else{
                abort_unless($action==='review' && $row->state==='prepared',403);
                if(!self::current($row,$users[$row->recipient_id])){throw ValidationException::withMessages(['delivery'=>'This source or account is no longer current. Cancel the record and reload.']);}
                $row->state='reviewed';$row->approved_by=$actor->id;$row->approver_auth_version=$authVersion;$row->approved_at=now();
            }
            $row->version++;$row->save();self::event($row,$actor->id,$row->state);
        });
    }
    // A committed reservation precedes SMTP. No retries or scheduler call this method.
    public static function dispatch(int $id): array
    {
        if(!self::available()){return ['result'=>'blocked','mail_attempted'=>false];}
        if(DB::transactionLevel()!==0){return ['result'=>'outer_transaction_refused','mail_attempted'=>false];}
        $reservation=DB::transaction(function()use($id){
            $row=ReminderMail::whereKey($id)->lockForUpdate()->first();
            if(!$row || $row->state!=='reviewed'){return null;}
            $users=User::whereIn('id',[$row->approved_by,$row->recipient_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor=$users->get($row->approved_by);$recipient=$users->get($row->recipient_id);
            if(!$actor || !self::administrator($actor) || $actor->auth_version!==$row->approver_auth_version || !$row->approved_at || now()->greaterThanOrEqualTo($row->approved_at->copy()->addMinutes(15)) || !$recipient || !self::current($row,$recipient)){
                $row->state='stale';$row->version++;$row->save();self::event($row,null,'stale');return null;
            }
            $row->state='dispatching';$row->attempted_at=now();$row->version++;$row->save();self::event($row,null,'attempt_reserved');return $row;
        });
        if(!$reservation){return ['result'=>'not_dispatchable','mail_attempted'=>false];}
        // Changes after this final read cannot retract mail; the payload contains no personal/source details.
        $recipient=User::find($reservation->recipient_id);$actor=User::find($reservation->approved_by);
        if(!self::available() || !$recipient || !self::current($reservation,$recipient) || !$actor || !self::administrator($actor) || $actor->auth_version!==$reservation->approver_auth_version){return self::finish($reservation,'stale',false);}
        try{$recipient->notify(new PortalWorkPrompt($reservation->snapshot['origin']));}
        catch(\Throwable){return self::finish($reservation,'needs_check',true);}
        return self::finish($reservation,'accepted',true);
    }
    private static function finish(ReminderMail $reservation,string $state,bool $attempted): array
    {
        try{
            DB::transaction(function()use($reservation,$state){
                $row=ReminderMail::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
                if($row->state!=='dispatching'){throw new \RuntimeException('Reservation changed');}
                $row->state=$state;$row->finished_at=now();$row->version++;$row->save();self::event($row,null,$state);
            });
        }catch(\Throwable){return ['result'=>'outcome_unknown','mail_attempted'=>$attempted];}
        return ['result'=>$state,'mail_attempted'=>$attempted];
    }
    public static function inspect(): array
    {
        $errors=self::errors();return ['result'=>$errors?'blocked':'configuration_checks_only','blockers'=>$errors,'pilot_recipient_count'=>count(self::recipients()),'sends_performed'=>false,'writes_performed'=>false];
    }
}
