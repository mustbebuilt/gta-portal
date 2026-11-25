<?php

namespace App\Support;

final class ReviewFacts83
{
    /** Fill only blank factual notes on explicit request. Never infer discussion, ratings or agreement. */
    public static function fill(array $fields, array $snapshot): array
    {
        if (!filled($fields['learning']??null) && !empty($snapshot['delivered'])) {
            $fields['learning']=mb_substr(implode("\n",array_map(fn($row)=>$row['date'].' · '.$row['name'].' · '.($row['subjects']??'Subjects not recorded'),array_slice($snapshot['delivered'],0,20))),0,20000);
        }
        if (!filled($fields['progressDiscussion']??null)) {
            $lines=[];
            if (!empty($snapshot['assigned_tasks'])) $lines[]=$snapshot['approved_tasks'].' of '.$snapshot['assigned_tasks'].' loaded tasks approved. Task approval only; check progress against the agreed plan.';
            $otj=$snapshot['otj_position']??null;
            if ($otj) $lines[]='Recorded OTJ subtotal: '.number_format($otj['recorded_minutes']/60,2).' hours. Loaded records only; historical completeness not verified.';
            if($lines)$fields['progressDiscussion']=implode("\n",$lines);
        }
        return $fields;
    }
}
