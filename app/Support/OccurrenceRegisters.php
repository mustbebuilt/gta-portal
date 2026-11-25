<?php
namespace App\Support;
use App\Models\{User,TimetablePlan as Plan,TimetablePlanEntry as Entry,TrainingSession,TutorGroup,TimetableDeliveryRecord as Delivery};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
class OccurrenceRegisters {
 public static function row(Entry $e,Plan $p):?array {return collect($p->source['data']['rows'])->firstWhere('work_date',$e->work_date->toDateString());}
 public static function match(Entry $e,Plan $p,TrainingSession $s):bool {try{$row=self::row($e,$p);$a=RegisterFollowup::roster($s);$ids=$a['ids'];$saved=$row['members']??[];sort($ids);sort($saved);return $row&&$a['shape']&&!$a['duplicates']&&$e->kind==='Teaching'&&$s->state==='Planned'&&$s->tutor_group_id===$e->group_id&&$s->session_date->toDateString()===$e->work_date->toDateString()&&$s->start_time===$e->start_time&&$s->end_time===$e->end_time&&$ids===$saved;}catch(\Throwable){return false;}}

    public static function nearbyRegisters(Collection $entries): Collection
    {
        return TrainingSession::with('group')->withExists('marks')
            ->whereIn('tutor_group_id', $entries->pluck('group_id')->filter()->unique())
            ->whereIn(DB::raw('DATE(session_date)'), $entries->map(fn (Entry $entry) => $entry->work_date->toDateString())->unique())
            ->where('state', '!=', 'Cancelled')
            ->get()->groupBy(fn (TrainingSession $session) => $session->tutor_group_id.':'.$session->session_date->toDateString());
    }

    public static function overlaps(Entry $entry, Collection $nearby): Collection
    {
        return $nearby->get($entry->group_id.':'.$entry->work_date->toDateString(), collect())
            ->filter(fn (TrainingSession $session) => $session->start_time < $entry->end_time && $session->end_time > $entry->start_time);
    }

    public static function matchingRegister(User $user, Entry $entry, Plan $plan, Collection $overlaps): ?TrainingSession
    {
        $session = $overlaps->count() === 1 ? $overlaps->first() : null;
        if (!$session || !$session->members || ($entry->training_session_id && $entry->training_session_id !== $session->id)
            || $session->group?->tutor_id !== $entry->staff_id || !self::match($entry, $plan, $session)
            || !RegisterFollowup::canOpen($user, $session)
            || Entry::where('training_session_id', $session->id)->whereKeyNot($entry->id)->exists()) {
            return null;
        }

        return $session;
    }

    public static function weeklyDefaults(Entry $entry, TutorGroup $group): ?array
    {
        $next = SessionPlanning::weekly($group, $entry->work_date->toDateString());
        if (!$next || $next['settings']['start'] !== $entry->start_time || $next['settings']['end'] !== $entry->end_time) {
            return null;
        }

        return $next;
    }
 public static function identity(Entry $e,Plan $p,array $v):bool {return ($v['plan_id']??null)===$p->id&&($v['source_hash']??null)===$p->source_hash&&($v['entry_id']??null)===$e->id&&($v['session_id']??null)===$e->training_session_id;}
 public static function create(User $actor,int $auth,Entry $record,int $version,string $learning,array $breaks):TrainingSession {return DB::transaction(function()use($actor,$auth,$record,$version,$learning,$breaks):TrainingSession {$u=CentrePlanning::actor($actor,$auth);$p=Plan::whereKey($record->plan_id)->lockForUpdate()->firstOrFail();TimetableGovernance::guardPlan($u,$p);$e=Entry::whereKey($record->id)->lockForUpdate()->firstOrFail();$g=TutorGroup::whereKey($e->group_id)->lockForUpdate()->firstOrFail();abort_unless(RegisterCreation::allowed($u,$g),403);
  $request=ReviewedImports::hash(['learning'=>$learning,'breaks'=>$breaks]);if($e->training_session_id||$e->link_proof){if($e->training_session_id&&CentrePlanning::linkCurrent($e,$p)&&($e->link_proof['creation_hash']??null)===$request){return TrainingSession::findOrFail($e->training_session_id);}CentrePlanning::fail('An existing or changed register link needs review; no second register is created.');}
  if($p->version!==$version||$p->state!=='Published'||!CentrePlanning::published($p)||!CentrePlanning::fresh($p,true)||$e->kind!=='Teaching'||!\Carbon\Carbon::parse($e->work_date->toDateString().' '.$e->start_time,config('app.timezone'))->gt(now())){CentrePlanning::fail('Use the exact current future published teaching occurrence.');}
  $weekly = self::weeklyDefaults($e, $g);
  if ($weekly && TrainingSession::where('tutor_group_id', $g->id)->where('regular_week', $weekly['week'])->exists()) {
      CentrePlanning::fail('This group already has a weekly register for that week. Review it before creating another.');
  }
  $s=RegisterCreation::store($u,$auth,$g,['name'=>$p->source['data']['args']['title'],'planned_learning'=>$learning,'session_date'=>$e->work_date->toDateString(),'start_time'=>$e->start_time,'end_time'=>$e->end_time],$breaks);
  if ($weekly) {
      $s->regular_week = $weekly['week'];
      $s->save();
  }
  CentrePlanning::link($u,$auth,$e,$p->version,$s,$s->version);$e->refresh();$proof=$e->link_proof;$proof['creation_hash']=$request;$e->link_proof=$proof;$e->save();CentrePlanning::event($u,'register_created_from_plan',$p->version,['entry'=>$e->id,'session'=>$s->id,'creation_hash'=>$request,'marks_created'=>false],$p);return $s;
 },3);}
 public static function current(Entry $e,Plan $p):bool {try{$v=$e->link_proof;$s=TrainingSession::find($e->training_session_id);$receipt=Delivery::find($e->training_session_id);if(!CentrePlanning::intact($p)||!CentrePlanning::published($p)||!$v||!$s||!$receipt||!self::identity($e,$p,$v)||!self::match($e,$p,$s)){return false;}$source=TimetableInsights::source($s);return TimetableInsights::record($receipt,$source)!==null&&$v['delivery_version']===$receipt->version&&$v['delivery_hash']===$receipt->details_hash;}catch(\Throwable){return false;}}
 public static function reconcile(User $actor,int $auth,Entry $record,int $version,string $oldHash,int $deliveryVersion,string $sourceHash,string $reason):void {DB::transaction(function()use($actor,$auth,$record,$version,$oldHash,$deliveryVersion,$sourceHash,$reason):void {$u=CentrePlanning::actor($actor,$auth);$p=Plan::whereKey($record->plan_id)->lockForUpdate()->firstOrFail();TimetableGovernance::guardPlan($u,$p);$e=Entry::whereKey($record->id)->lockForUpdate()->firstOrFail();$s=TrainingSession::whereKey($e->training_session_id)->lockForUpdate()->firstOrFail();$receipt=Delivery::whereKey($s->id)->lockForUpdate()->firstOrFail();$before=$e->link_proof;
  if(!$before||!self::identity($e,$p,$before)||!CentrePlanning::intact($p)||!CentrePlanning::published($p)||$p->version!==$version||ReviewedImports::hash($before)!==$oldHash||!self::match($e,$p,$s)||!collect(CentrePlanning::liveRows($p))->contains('work_date',$e->work_date->toDateString())||!filled($reason)||mb_strlen($reason)>2000){CentrePlanning::fail('Review the exact existing live occurrence/link and record a safe reconciliation reason.');}
  $source=TimetableInsights::source($s);if($source['hash']!==$sourceHash||$receipt->version!==$deliveryVersion||!TimetableInsights::record($receipt,$source)){CentrePlanning::fail('Confirm current actual delivery before reconciling its planning link.');}
  $e->link_proof=['mode'=>'delivery','plan_id'=>$p->id,'source_hash'=>$p->source_hash,'entry_id'=>$e->id,'session_id'=>$s->id,'delivery_version'=>$receipt->version,'delivery_hash'=>$receipt->details_hash,'by'=>$u->id,'at'=>now()->toIso8601String(),'creation_hash'=>$before['creation_hash']??null];$e->save();CentrePlanning::event($u,'delivered_link_reconciled',$p->version,['before'=>$before,'after'=>$e->link_proof,'reason'=>$reason,'register_unchanged'=>true],$p);
 },3);}
}
