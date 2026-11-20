<?php
namespace App\Support;

class RegisterPayload
{
    private static function normal(array $r): array
    {
        return ['code'=>$r['code']??null,'arrival'=>filled($r['arrival']??null)?substr($r['arrival'],0,5):null,
            'departure'=>filled($r['departure']??null)?substr($r['departure'],0,5):null,
            'parts'=>$r['parts']??[],'otj_minutes'=>isset($r['otj_minutes'])?(int)$r['otj_minutes']:null,
            'otj_confirmed'=>(bool)($r['otj_confirmed']??false),'comment'=>filled($r['comment']??null)?$r['comment']:null,
            'adjustment'=>filled($r['adjustment']??null)?$r['adjustment']:null];
    }
    public static function same(array $old, array $new, ?string $oldLearning, ?string $newLearning): bool
    {
        if (($oldLearning??'')!==($newLearning??'')) {return false;}
        $before=[];foreach($old as $r){$before[(int)$r['apprentice_id']]=self::normal($r);}
        $after=[];foreach($new as $id=>$r){$after[(int)$id]=self::normal($r);}
        ksort($before);ksort($after);return $before===$after;
    }
}
