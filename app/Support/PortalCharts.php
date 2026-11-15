<?php
namespace App\Support;
class PortalCharts
{
 public const COLOURS=['#007c91','#3769c8','#a34a17','#b12246','#6552a5','#4d6970'];
 public static function svg(array $chart):string
 {
  $rows=$chart['rows'];if(count($rows)>1000){throw new \InvalidArgumentException('Narrow the chart to1000 source rows.');}
  foreach($rows as$row){if(!is_string($row['label'])){throw new \InvalidArgumentException('Chart label needed.');}foreach(['value','second']as$key){$v=$row[$key]??null;if($v!==null&&(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||(float)$v>1e12)){throw new \InvalidArgumentException('Use known non-negative finite chart values or null.');}}}
  $title=e($chart['title']);$type=$chart['type']??'bars';
  if($type==='donut'){
   if(count($rows)>6||collect($rows)->contains(fn($r)=>$r['value']===null)){throw new \InvalidArgumentException('A doughnut needs up to six known counts.');}
   $total=array_sum(array_column($rows,'value'));$parts='';$offset=0;$length=2*M_PI*72;
   if($total>0){foreach($rows as$i=>$row){$n=$length*$row['value']/$total;$parts.='<circle cx="120" cy="112" r="72" fill="none" stroke="'.self::COLOURS[$i].'" stroke-width="28" stroke-dasharray="'.self::n($n).' '.self::n($length-$n).'" stroke-dashoffset="'.self::n(-$offset).'" transform="rotate(-90 120 112)"><title>'.e($row['label']).': '.e((string)$row['value']).'</title></circle>';$offset+=$n;}}
   $parts.='<text x="120" y="119" text-anchor="middle" font-size="22">'.e($total>0?(string)$total:'No records').'</text>';
   foreach($rows as$i=>$row){$y=35+$i*32;$parts.='<rect x="240" y="'.($y-12).'" width="12" height="12" fill="'.self::COLOURS[$i].'"/><text x="263" y="'.$y.'">'.e(mb_strimwidth($row['label'],0,45,'…')).': '.e((string)$row['value']).'</text>';}
   return self::wrap($title,720,240,$parts);
  }
  if($type==='line'){
   $max=$chart['max']??max(1,...array_map(fn($r)=>(float)($r['value']??0),$rows));$parts='<path d="M55 20V190H695" fill="none" stroke="#758399"/>';$segments=[];$points=[];
   foreach($rows as$i=>$row){$x=55+(count($rows)>1?640*$i/(count($rows)-1):320);if($row['value']===null){if($points){$segments[]=$points;$points=[];}}else{$y=190-170*$row['value']/$max;$points[]=self::n($x).','.self::n($y);$parts.='<circle cx="'.self::n($x).'" cy="'.self::n($y).'" r="4" fill="#007c91"><title>'.e($row['label']).': '.e((string)$row['value']).'</title></circle>';}if($i%max(1,(int)ceil(count($rows)/8))===0||$i===count($rows)-1){$parts.='<text x="'.self::n($x).'" y="213" text-anchor="middle" font-size="11">'.e(mb_strimwidth($row['label'],0,12,'…')).'</text>';}}
   if($points){$segments[]=$points;}foreach($segments as$p){$parts.='<polyline points="'.implode(' ',$p).'" fill="none" stroke="#007c91" stroke-width="3"/>';}
   $parts.='<text x="8" y="24">'.self::n((float)$max).'</text><text x="28" y="193">0</text>';
   return self::wrap($title,720,235,$parts);
  }
  if(count($rows)>14){$tail=array_slice($rows,13);$more=['label'=>'Further entries (see table)','value'=>collect($tail)->contains(fn($r)=>($r['value']??null)===null)?null:array_sum(array_column($tail,'value'))];if($type==='grouped'){$more['second']=collect($tail)->contains(fn($r)=>($r['second']??null)===null)?null:array_sum(array_column($tail,'second'));}$rows=[...array_slice($rows,0,13),$more];}
  if(!in_array($type,['bars','grouped'],true)){throw new \InvalidArgumentException('Unknown chart type.');}
  $max=max(1,...array_map(fn($r)=>max((float)($r['value']??0),(float)($r['second']??0)),$rows));$height=max(95,count($rows)*44+55);$parts='<path d="M245 15V'.($height-30).'H700" fill="none" stroke="#758399"/>';
  foreach($rows as$i=>$row){$y=32+$i*44;$parts.='<text x="4" y="'.$y.'">'.e(mb_strimwidth($row['label'],0,31,'…')).'</text>';
   foreach($type==='grouped'?['value','second']:['value']as$j=>$key){$v=$row[$key]??null;$at=$y-13+$j*16;$colour=self::COLOURS[$j];if($v!==null){$width=425*$v/$max;$parts.='<rect x="245" y="'.$at.'" width="'.self::n($width).'" height="12" fill="'.$colour.'"><title>'.e($row['label']).': '.e((string)$v).'</title></rect>';}else{$parts.='<text x="252" y="'.($at+10).'" font-size="11">Unknown</text>';}}
  }
  $parts.='<text x="245" y="'.($height-9).'">0</text><text x="695" y="'.($height-9).'" text-anchor="end">'.self::n($max).'</text>';
  return self::wrap($title,720,$height,$parts);
 }
 private static function n(float $n):string{return rtrim(rtrim(number_format($n,3,'.',''),'0'),'.')?:'0';}
 private static function wrap(string $title,int $w,int $h,string $body):string{return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$w.' '.$h.'" role="img" aria-label="'.$title.'" style="font-family:DejaVu Sans,sans-serif;font-size:14px;fill:#243b53"><title>'.$title.'</title>'.$body.'</svg>';}
}
