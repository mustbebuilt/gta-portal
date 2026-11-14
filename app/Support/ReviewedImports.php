<?php
namespace App\Support;

use App\Models\Apprentice;
use App\Models\DataImportBatch;
use App\Models\HistoricalOtjRecord;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReviewedImports
{
    public const HEADERS=[
        'qualification-results'=>['qualification_id','version','status','result','achievement_date','evidence_reference'],
        'historical-otj'=>['apprentice_id','training_date','start_time','end_time','excluded_minutes','topic','evidence_reference'],
    ];

    public static function fail(string $message): never {throw ValidationException::withMessages(['file'=>$message]);}
    public static function hash(array $value): string
    {
        // JSON database engines may reorder object keys. Preserve list order, canonicalise object keys.
        $normalise=function($v) use (&$normalise) {
            if($v instanceof \JsonSerializable){return $normalise($v->jsonSerialize());}
            if(!is_array($v)){return $v;}
            if(!array_is_list($v)){ksort($v);}
            return array_map($normalise,$v);
        };
        return hash('sha256',json_encode($normalise($value),JSON_THROW_ON_ERROR));
    }

    public static function prepare(string $kind, UploadedFile $file, string $reference, User $actor): DataImportBatch
    {
        abort_unless(LearningAccess::manager($actor),403);
        $raw=file_get_contents($file->getRealPath());
        if(!mb_check_encoding($raw,'UTF-8') || str_contains($raw,"\0")){self::fail('Use a UTF-8 CSV without binary content.');}
        $stream=fopen('php://temp','r+');fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$raw));rewind($stream);
        $header=fgetcsv($stream,0,',','"','');if($header!==self::HEADERS[$kind]){self::fail('Column headers/order must match the downloaded template exactly.');}
        $rows=[];$keys=[];
        while(($cells=fgetcsv($stream,0,',','"',''))!==false){
            if($cells===[null] || collect($cells)->every(fn($v)=>trim((string)$v)==='')){continue;}
            if(count($rows)>=200 || count($cells)!==count($header)){self::fail('Use at most 200 rows, with the exact template columns. No changes were saved.');}
            $v=array_combine($header,array_map(fn($x)=>trim((string)$x),$cells));
            $line=count($rows)+2;
            try{$row=$kind==='qualification-results'?self::qualification($v):self::otj($v);}
            catch(ValidationException $e){self::fail('CSV row '.$line.': '.implode(' ', $e->validator->errors()->all()));}
            $key=$kind==='qualification-results' ? (string)$row['qualification_id'] : $row['fingerprint'];
            if(isset($keys[$key])){self::fail('CSV row '.$line.' repeats a source in this batch.');}$keys[$key]=true;
            if($kind==='historical-otj'){
                foreach($rows as $prior){if($prior['apprentice_id']===$row['apprentice_id'] && $prior['training_date']===$row['training_date'] && max(AttendanceMath::time($prior['start_time']),AttendanceMath::time($row['start_time']))<min(AttendanceMath::time($prior['end_time']),AttendanceMath::time($row['end_time']))){self::fail('CSV row '.$line.' overlaps another row in this batch.');}}
            }
            $rows[]=$row;
        }
        fclose($stream);if(!$rows){self::fail('The CSV has no data rows.');}
        return DB::transaction(function() use($kind,$file,$reference,$raw,$rows,$actor){
            $fresh=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();abort_unless(LearningAccess::manager($fresh),403);
            $batch=DataImportBatch::create(['kind'=>$kind,'prepared_by'=>$fresh->id,'source_name'=>mb_substr(basename($file->getClientOriginalName()),0,255),'source_reference'=>$reference,'source_hash'=>hash('sha256',$raw),'preview_hash'=>self::hash($rows),'rows'=>$rows]);
            self::event($batch,$fresh,'prepared');return $batch;
        });
    }

    private static function qualification(array $v): array
    {
        Validator::make($v,['qualification_id'=>['required','integer','min:1'],'version'=>['required','integer','min:1'],'status'=>['required',Rule::in(['Achieved','Awaiting confirmation'])],'result'=>['nullable','string','max:100'],'achievement_date'=>['nullable','date_format:Y-m-d','before_or_equal:today'],'evidence_reference'=>['required','string','max:2000']])->validate();
        $q=Qualification::find($v['qualification_id']);
        if(!$q || $q->version!==(int)$v['version'] || !in_array($q->status,['Working towards','Awaiting confirmation'],true)){self::fail('Choose an unchanged working/awaiting qualification ID and current version. Existing achievements/exemptions need individual amendment.');}
        $achieved=$v['status']==='Achieved';
        if($achieved && (!filled($v['result']) || !filled($v['achievement_date']) || ($q->start_date && $v['achievement_date']<$q->start_date->toDateString()))){self::fail('An achieved result needs its actual result and valid date on/after the known qualification start.');}
        if(!$achieved && (filled($v['result']) || filled($v['achievement_date']))){self::fail('Awaiting confirmation must have blank result and achievement date.');}
        if($q->type==='Functional Skills' && $achieved && $v['result']!=='Achieved'){self::fail('Functional Skills records use Achieved without grade bands.');}
        return ['qualification_id'=>$q->id,'version'=>$q->version,'apprentice_id'=>$q->apprentice_id,'reference'=>$q->apprentice->reference,'title'=>$q->title,'subject'=>$q->subject,'level'=>$q->level,'type'=>$q->type,'baseline_hash'=>self::hash($q->getAttributes()),'before'=>$q->only('status','result','achievement_date'),'status'=>$v['status'],'result'=>$v['result']!==''?$v['result']:null,'achievement_date'=>$v['achievement_date']?:null,'evidence_reference'=>$v['evidence_reference']];
    }

    private static function otj(array $v): array
    {
        Validator::make($v,['apprentice_id'=>['required','integer','min:1'],'training_date'=>['required','date_format:Y-m-d','before_or_equal:today'],'start_time'=>['required','date_format:H:i'],'end_time'=>['required','date_format:H:i'],'excluded_minutes'=>['required','integer','min:0'],'topic'=>['required','string','max:1000'],'evidence_reference'=>['required','string','max:2000']])->validate();
        $a=Apprentice::find($v['apprentice_id']);
        if(!$a || !$a->programme_id || !TaskCatalogue::keyFor($a) || !$a->start_date || $v['training_date']<$a->start_date->toDateString()){self::fail('Historical OTJ needs a known apprentice, explicit loaded programme/version and training on/after recorded first learning.');}
        if(\Carbon\Carbon::parse($v['training_date'].' '.$v['end_time'],config('app.timezone'))->gt(now())){self::fail('Historical training must have ended before it can be imported.');}
        $minutes=AttendanceMath::time($v['end_time'])-AttendanceMath::time($v['start_time'])-(int)$v['excluded_minutes'];
        if($minutes<=0){self::fail('Use a same-day positive interval after excluded minutes.');}
        $identity=HistoricalOtj::identity($a);
        $fingerprint=self::hash([$a->id,$identity,$v['training_date'],$v['start_time'],$v['end_time'],$minutes]);
        if(HistoricalOtjRecord::where('fingerprint',$fingerprint)->exists() || HistoricalOtj::overlaps([...$v,'apprentice_id'=>$a->id])){self::fail('This interval duplicates/overlaps an existing register, extra-training or imported source. Reconcile it separately.');}
        return [...$v,'apprentice_id'=>$a->id,'reference'=>$a->reference,'apprentice_version'=>$a->version,'programme_identity'=>$identity,'excluded_minutes'=>(int)$v['excluded_minutes'],'minutes'=>$minutes,'fingerprint'=>$fingerprint];
    }

    public static function apply(DataImportBatch $batch, User $actor, int $version, string $hash): void
    {
        abort_unless(LearningAccess::manager($actor),403);
        DB::transaction(function() use($batch,$actor,$version,$hash): void {
            $actor=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();abort_unless(LearningAccess::manager($actor),403);
            $b=DataImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if($b->prepared_by===$actor->id || $b->state!=='Preview' || $b->version!==$version || $b->preview_hash!==$hash || self::hash($b->rows)!==$hash || $b->created_at->lt(now()->subDay())){self::fail('Use a current unchanged preview, checked by a different administrator/CEO within 24 hours.');}
            $people=Apprentice::whereIn('id',array_unique(array_column($b->rows,'apprentice_id')))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach($b->rows as $row){
                $a=$people->get($row['apprentice_id']);if(!$a){self::fail('An apprentice source no longer exists.');}
                if($b->kind==='qualification-results'){
                    $q=Qualification::whereKey($row['qualification_id'])->lockForUpdate()->firstOrFail();
                    if($q->apprentice_id!==$a->id || $q->version!==$row['version'] || self::hash($q->getAttributes())!==$row['baseline_hash']){self::fail('A qualification changed after preview. Prepare the current result again.');}
                    // Revalidate dates/status rules at the point of application as well as preview.
                    self::qualification(['qualification_id'=>$q->id,'version'=>$q->version,'status'=>$row['status'],'result'=>$row['result']??'','achievement_date'=>$row['achievement_date']??'','evidence_reference'=>$row['evidence_reference']]);
                    $q->history=[...($q->history??[]),['before'=>$q->only('status','result','achievement_date'),'reason'=>'Reviewed result import','import_batch_id'=>$b->id,'source_hash'=>$b->source_hash,'evidence_reference'=>$row['evidence_reference'],'by'=>$actor->id,'at'=>now()->toIso8601String()]];
                    $q->status=$row['status'];$q->result=$row['result'];$q->achievement_date=$row['achievement_date'];$q->version++;$q->save();
                    self::learning($actor,$a->id,'qualification',$q->id,'result_imported');
                }else{
                    if($a->programme_id){$a->programmeRecord()->lockForUpdate()->first();}
                    if($a->version!==$row['apprentice_version'] || self::hash(HistoricalOtj::identity($a))!==self::hash($row['programme_identity'])){self::fail('The apprentice/programme/start source changed. Prepare a current preview.');}
                    $checked=self::otj(collect($row)->only(self::HEADERS['historical-otj'])->all());
                    if($checked['fingerprint']!==$row['fingerprint']){self::fail('The imported interval changed.');}
                    $record=HistoricalOtjRecord::create([...collect($row)->only('apprentice_id','programme_identity','training_date','start_time','end_time','excluded_minutes','minutes','topic','evidence_reference','fingerprint')->all(),'data_import_batch_id'=>$b->id,'history'=>[['event'=>'reviewed_import','by'=>$actor->id,'at'=>now()->toIso8601String()]],'version'=>1]);
                    self::learning($actor,$a->id,'historical_otj',$record->id,'historical_otj_imported');
                }
            }
            $b->state='Applied';$b->applied_by=$actor->id;$b->applied_at=now();$b->version++;$b->save();self::event($b,$actor,'applied');
        });
    }

    public static function withdraw(HistoricalOtjRecord $record, User $actor, int $version, string $reason): void
    {
        abort_unless(LearningAccess::manager($actor),403);
        DB::transaction(function() use($record,$actor,$version,$reason): void {
            $actor=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();abort_unless(LearningAccess::manager($actor),403);
            Apprentice::whereKey($record->apprentice_id)->lockForUpdate()->firstOrFail();$row=HistoricalOtjRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if($row->version!==$version || $row->withdrawn_at){self::fail('This imported record changed or is already withdrawn.');}
            $row->history=[...$row->history,['event'=>'withdrawn','by'=>$actor->id,'reason'=>$reason,'at'=>now()->toIso8601String()]];$row->withdrawn_at=now();$row->version++;$row->save();self::learning($actor,$row->apprentice_id,'historical_otj',$row->id,'historical_otj_withdrawn');
        });
    }

    private static function event(DataImportBatch $b,User $actor,string $event): void
    {
        DB::table('data_import_events')->insert(['data_import_batch_id'=>$b->id,'actor_id'=>$actor->id,'event'=>$event,'created_at'=>now()]);
    }
    private static function learning(User $actor,int $a,string $kind,int $id,string $event): void
    {
        DB::table('learning_events')->insert(['user_id'=>$actor->id,'apprentice_id'=>$a,'kind'=>$kind,'record_id'=>$id,'event'=>$event,'created_at'=>now()]);
    }
}
