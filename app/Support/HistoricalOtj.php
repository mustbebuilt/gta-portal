<?php
namespace App\Support;

use App\Models\Apprentice;
use App\Models\ExtraTraining;
use App\Models\HistoricalOtjRecord;
use App\Models\TrainingSession;

class HistoricalOtj
{
    public static function identity(Apprentice $a): array
    {
        $p=$a->programmeRecord()->first();
        return ['programme_id'=>$a->programme_id,'catalogue'=>TaskCatalogue::keyFor($a),'code'=>$p?->standard_code,'version'=>$p?->version,'start'=>$a->start_date?->toDateString()];
    }

    public static function overlaps(array $row, ?int $except=null): bool
    {
        $a=(int)$row['apprentice_id'];$date=$row['training_date'];$start=AttendanceMath::time($row['start_time']);$end=AttendanceMath::time($row['end_time']);
        $overlap=fn($x)=>max($start,AttendanceMath::time($x->start_time))<min($end,AttendanceMath::time($x->end_time));
        if(TrainingSession::whereJsonContains('members',$a)->whereDate('session_date',$date)->where('state','!=','Cancelled')->get()->contains($overlap)){return true;}
        if(ExtraTraining::where('apprentice_id',$a)->whereDate('training_date',$date)->whereNull('withdrawn_at')->get()->contains($overlap)){return true;}
        return HistoricalOtjRecord::where('apprentice_id',$a)->whereDate('training_date',$date)->whereNull('withdrawn_at')->when($except,fn($q)=>$q->where('id','!=',$except))->get()->contains($overlap);
    }

    public static function ledger(Apprentice $a): array
    {
        $identity=self::identity($a);$out=[];
        foreach(HistoricalOtjRecord::where('apprentice_id',$a->id)->orderBy('training_date')->orderBy('id')->get() as $row){
            $reason=null;
            if($row->withdrawn_at){$reason='Imported record withdrawn';}
            elseif(ReviewedImports::hash($row->programme_identity)!==ReviewedImports::hash($identity)){$reason='Imported programme/start source needs reconciliation';}
            elseif(\Carbon\Carbon::parse($row->training_date->toDateString().' '.$row->end_time,config('app.timezone'))->gt(now())){$reason='Imported date needs checking';}
            elseif(self::overlaps(['apprentice_id'=>$a->id,'training_date'=>$row->training_date->toDateString(),'start_time'=>$row->start_time,'end_time'=>$row->end_time],$row->id)){$reason='Imported interval overlaps another source - reconcile';}
            $out[]=['kind'=>'historical','id'=>$row->id,'date'=>$row->training_date->toDateString(),'title'=>$row->topic,'minutes'=>$reason?0:$row->minutes,'state'=>$reason??'Independently reviewed import','code'=>null,'source_version'=>$row->version,'data_check'=>$reason && !$row->withdrawn_at];
        }
        return $out;
    }
}
