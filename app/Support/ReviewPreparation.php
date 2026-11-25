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
        if (($fields['format83']??null)==='simple83') return self::simple($fields,$snapshot,$plan);
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
        if ($snapshot['requires_safety_answer'] ?? false) {
            $safety = match ($fields['safetyAnswer'] ?? null) { 'yes'=>true, 'no'=>false, default=>null };
            try {
                ApprenticeReviewData::safety($safety, $fields['safetyFollowup'] ?? null);
                $safetyChecked = true;
            } catch (\InvalidArgumentException) {
                $safetyChecked = false;
            }
            $items[]=['key'=>'safety', 'label'=>'Accidents or near-misses answer and follow-up if needed', 'section'=>'review-4', 'checked'=>$safetyChecked];
        }
        $pending=array_values(array_filter($items,fn($item)=>!$item['checked']));
        return ['items'=>$items,'pending'=>$pending,'ready'=>$pending===[]];
    }

    public const SIMPLE_SECTIONS=['review-1'=>'1. Progress','review-2'=>'2. Discussion','review-3'=>'3. Targets','review-4'=>'4. Finish'];

    private static function simple(array $fields,array $snapshot,?array $plan):array
    {
        $required=['reviewDate'=>['Actual meeting date','review-1'],'trainingFrom'=>['Training period start','review-1'],
            'mentor'=>['Learning & Progress Mentor','review-1'],'tutor'=>['Tutor','review-1'],'employerRep'=>['Employer representative','review-1'],
            'method'=>['Meeting method','review-1'],'attendance'=>['Employer participation','review-2'],
            'judgement'=>['Progress judgement','review-2'],'summary'=>['Discussion summary','review-2'],
            'planChange'=>['Training-plan change decision','review-2'],'newNeeds'=>['New support needs decision','review-2']];
        if (($fields['attendance']??null)==='Absent — opportunity to contribute recorded') $required['absence']=['Opportunity for employer contribution','review-2'];
        if (($fields['planChange']??null)==='Yes') $required['planChangeNotes']=['Agreed training-plan change','review-2'];
        $items=[];
        foreach($required as $key=>[$label,$section])$items[]=['key'=>$key,'label'=>$label,'section'=>$section,'checked'=>filled($fields[$key]??null)];
        $items[]=['key'=>'checked83','label'=>'Progress, agreed targets, review content and current plan checked','section'=>'review-4','checked'=>($fields['checked83']??false)===true || ($fields['checked83']??null)===1 || ($fields['checked83']??null)==='1'];
        $items[]=['key'=>'currentPlan','label'=>'Saved draft linked to the current approved delivery plan','section'=>'review-1',
            'checked'=>$plan!==null && isset($plan['hash']) && ($snapshot['delivery_plan']['hash']??null)===$plan['hash']];
        if ($snapshot['requires_safety_answer']??false) {
            try {ApprenticeReviewData::safety(match($fields['safetyAnswer']??null){'yes'=>true,'no'=>false,default=>null},$fields['safetyFollowup']??null);$ok=true;}
            catch(\InvalidArgumentException){$ok=false;}
            $items[]=['key'=>'safety','label'=>'Accidents or near-misses answer and follow-up if needed','section'=>'review-2','checked'=>$ok];
        }
        $periodOk=false;
        try {
            $start=$snapshot['programme_dates']['start']??null;
            if ($start && filled($fields['reviewDate']??null) && filled($fields['trainingFrom']??null)) {
                $from=ApprenticeReviewData::date($fields['trainingFrom']);$meeting=ApprenticeReviewData::date($fields['reviewDate']);
                $periodOk=$from>=ApprenticeReviewData::date($start) && $from<=$meeting && $meeting<=ApprenticeReviewData::date(today()->toDateString());
            }
        } catch(\InvalidArgumentException) {}
        $items[]=['key'=>'period83','label'=>'Review dates within the recorded programme','section'=>'review-1','checked'=>$periodOk];
        $pending=array_values(array_filter($items,fn($item)=>!$item['checked']));
        return ['items'=>$items,'pending'=>$pending,'ready'=>$pending===[],'sections'=>self::SIMPLE_SECTIONS];
    }

    public static function requireReady(array $fields,array $snapshot,?array $plan):void
    {
        $result=self::inspect($fields,$snapshot,$plan);
        if(!$result['ready']) {throw ValidationException::withMessages(['version'=>'Complete the saved review preparation items: '.implode('; ',array_column($result['pending'],'label')).'. Save the draft after making changes.']);}
    }
}
