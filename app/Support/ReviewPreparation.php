<?php
namespace App\Support;
use Illuminate\Validation\ValidationException;

class ReviewPreparation
{
    public const FIELDS=[
        'reviewDate'=>['Actual meeting date','review-1'],'trainingFrom'=>['Training period start','review-1'],
        'mentor'=>['Learning & Progress Mentor','review-1'],'tutor'=>['Tutor','review-1'],'employerRep'=>['Employer representative','review-1'],
        'method'=>['Meeting method','review-1'],'attendance'=>['Employer participation','review-2'],'absence'=>['Participation / absence details','review-2'],
        'judgement'=>['Progress judgement','review-3'],'learning'=>['Learning covered','review-3'],'progressDiscussion'=>['Progress discussion','review-3'],
        'planChange'=>['Training-plan change decision','review-4'],'planChangeNotes'=>['Plan change notes','review-4'],
        'newNeeds'=>['New support needs decision','review-4'],'sharedSupport'=>['Shareable support summary','review-4'],
        'previousCheck'=>['Previous action plan check','review-5'],'previousNotes'=>['Previous actions notes','review-5'],
        'summary'=>['Discussion summary','review-6'],
    ];
    public const SECTIONS=['review-1'=>'1. Review details','review-2'=>'2. Attendance and engagement','review-3'=>'3. Training progress','review-4'=>'4. Changes and support','review-5'=>'5. Previous actions and next targets','review-6'=>'6. Discussion and preparation'];

    public static function inspect(array $fields,array $snapshot,?array $plan):array
    {
        $items=[];
        foreach(self::FIELDS as $key=>[$label,$section]) {
            $checked=filled($fields[$key]??null)&&($key!=='previousCheck'||$fields[$key]!=='Unable to check');
            $items[]=['key'=>$key,'label'=>$label,'section'=>$section,'checked'=>$checked];
        }
        foreach(['trainingChecked'=>['Training checked','review-3'],'targetsAgreed'=>['Targets agreed','review-5'],'providerPrepared'=>['Draft content checked','review-6']]as$key=>[$label,$section]) {
            $items[]=['key'=>$key,'label'=>$label,'section'=>$section,'checked'=>(bool)($fields[$key]??false)];
        }
        $items[]=['key'=>'currentPlan','label'=>'Saved draft linked to the current approved delivery plan','section'=>'review-3',
            'checked'=>$plan!==null&&isset($plan['hash'])&&($snapshot['delivery_plan']['hash']??null)===$plan['hash']];
        $pending=array_values(array_filter($items,fn($item)=>!$item['checked']));
        return ['items'=>$items,'pending'=>$pending,'ready'=>$pending===[]];
    }

    public static function requireReady(array $fields,array $snapshot,?array $plan):void
    {
        $result=self::inspect($fields,$snapshot,$plan);
        if(!$result['ready']) {throw ValidationException::withMessages(['version'=>'Complete the saved review preparation items: '.implode('; ',array_column($result['pending'],'label')).'. Save the draft after making changes.']);}
    }
}
