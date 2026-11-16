<?php
namespace App\Support;
class PortalCharts
{
 public const COLOURS=['#12833d','#b67b00','#de3945','#777b81','#8e5ec4','#55595f'];
 public static function svg(array $chart):string
 {
  $rows=$chart['rows'];if(count($rows)>1000){throw new \InvalidArgumentException('Narrow the chart to1000 source rows.');}
  foreach($rows as$row){if(!is_string($row['label'])){throw new \InvalidArgumentException('Chart label needed.');}foreach(['value','second']as$key){$v=$row[$key]??null;if($v!==null&&(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||(float)$v>1e12)){throw new \InvalidArgumentException('Use known non-negative finite chart values or null.');}}}
  $title=e($chart['title']);$type=$chart['type']??'bars';
  if($type==='donut'){
   if(count($rows)>6||collect($rows)->contains(fn($r)=>$r['value']===null)){throw new \InvalidArgumentException('A doughnut needs up to six known counts.');}
   $total=array_sum(array_column($rows,'value'));$parts='<circle class="chart-track" cx="120" cy="112" r="72" fill="none" stroke="#a3a3a3" stroke-width="22"/>';$offset=0;$length=2*M_PI*72;
   if($total>0){foreach($rows as$i=>$row){$n=$length*$row['value']/$total;$parts.='<circle cx="120" cy="112" r="72" fill="none" stroke="'.self::COLOURS[$i].'" stroke-width="22" stroke-dasharray="'.self::n($n).' '.self::n($length-$n).'" stroke-dashoffset="'.self::n(-$offset).'" transform="rotate(-90 120 112)"><title>'.e($row['label']).': '.e((string)$row['value']).'</title></circle>';$offset+=$n;}}
   $parts.='<text x="120" y="116" text-anchor="middle" font-size="28" font-weight="700">'.e($total>0?self::n($total):'No records').'</text><text x="120" y="140" text-anchor="middle" font-size="12">'.($total>0?'Allocated tasks':'Check scope').'</text>';
   foreach($rows as$i=>$row){$y=35+$i*34;$parts.='<rect x="240" y="'.($y-11).'" width="10" height="10" rx="2" fill="'.self::COLOURS[$i].'"/><text x="263" y="'.$y.'">'.e(mb_strimwidth($row['label'],0,38,'…')).'</text><text x="700" y="'.$y.'" text-anchor="end" font-weight="700">'.e((string)$row['value']).'</text>';}
   return self::wrap($title,720,240,$parts);
  }
  if($type==='line'){
   $knownMax=max([0,...array_map(fn($r)=>(float)($r['value']??0),$rows)]);$max=$chart['max']??max(1,$knownMax);
   if(!is_numeric($max)||!is_finite((float)$max)||(float)$max<=0||(float)$max>1e12||(float)$max<$knownMax){throw new \InvalidArgumentException('Chart maximum must be positive, finite and include every known value.');}
   $parts=self::grid(55,695,20,190,(float)$max,true);$segments=[];$points=[];$marks='';
   foreach($rows as$i=>$row){$x=55+(count($rows)>1?640*$i/(count($rows)-1):320);if($row['value']===null){if($points){$segments[]=$points;$points=[];}$marks.='<text x="'.self::n($x).'" y="183" text-anchor="middle" font-size="10">?</text>';}else{$y=190-170*$row['value']/$max;$points[]=self::n($x).','.self::n($y);$marks.='<circle cx="'.self::n($x).'" cy="'.self::n($y).'" r="4" fill="#12833d"><title>'.e($row['label']).': '.e((string)$row['value']).'</title></circle>';}if($i%max(1,(int)ceil(count($rows)/8))===0||$i===count($rows)-1){$parts.='<text x="'.self::n($x).'" y="213" text-anchor="middle" font-size="11">'.e(mb_strimwidth($row['label'],0,12,'…')).'</text>';}}
   if($points){$segments[]=$points;}foreach($segments as$p){$parts.='<polyline points="'.implode(' ',$p).'" fill="none" stroke="#12833d" stroke-width="3"/>';}
   return self::wrap($title,720,240,$parts.$marks);
  }
  if(!in_array($type,['bars','grouped'],true)){throw new \InvalidArgumentException('Unknown chart type.');}
  if(count($rows)>14){$tail=array_slice($rows,13);$more=['label'=>'Further entries (see table)','value'=>collect($tail)->contains(fn($r)=>($r['value']??null)===null)?null:array_sum(array_column($tail,'value'))];if($type==='grouped'){$more['second']=collect($tail)->contains(fn($r)=>($r['second']??null)===null)?null:array_sum(array_column($tail,'second'));}$rows=[...array_slice($rows,0,13),$more];}
  $max=max([1,...array_map(fn($r)=>max((float)($r['value']??0),(float)($r['second']??0)),$rows)]);$step=$type==='grouped'?58:44;$height=max(115,count($rows)*$step+50);$parts=self::grid(245,645,15,$height-30,$max,false);
  foreach($rows as$i=>$row){$y=34+$i*$step;$parts.='<text x="4" y="'.$y.'">'.e(mb_strimwidth($row['label'],0,31,'…')).'</text>';
   foreach($type==='grouped'?['value','second']:['value']as$j=>$key){$v=$row[$key]??null;$at=$y-14+$j*19;$colour=$type==='grouped'?['#777b81','#12833d'][$j]:self::COLOURS[0];if($v!==null){$width=400*$v/$max;$parts.='<rect x="245" y="'.$at.'" width="'.self::n($width).'" height="14" rx="3" fill="'.$colour.'"><title>'.e($row['label']).': '.e((string)$v).'</title></rect><text x="710" y="'.($at+12).'" text-anchor="end" font-size="12">'.e(self::n((float)$v)).'</text>';}else{$parts.='<text x="710" y="'.($at+12).'" text-anchor="end" font-size="11">Unknown</text>';}}
  }
  return self::wrap($title,720,$height,$parts);
 }
 private static function grid(float $x,float $right,float $top,float $bottom,float $max,bool $vertical):string
 {
  $s='';for($i=0;$i<=4;$i++){$v=$max*$i/4;$pos=$vertical?$bottom-($bottom-$top)*$i/4:$x+($right-$x)*$i/4;
   $s.=$vertical?'<path class="chart-grid" d="M'.$x.' '.self::n($pos).'H'.$right.'" stroke="#b0b0b0" fill="none"/><text x="'.($x-10).'" y="'.self::n($pos+4).'" text-anchor="end" font-size="11">'.self::n($v).'</text>':'<path class="chart-grid" d="M'.self::n($pos).' '.$top.'V'.$bottom.'" stroke="#b0b0b0" fill="none"/><text x="'.self::n($pos).'" y="'.($bottom+20).'" text-anchor="middle" font-size="11">'.self::n($v).'</text>';
  }return $s.'<path class="chart-axis" d="M'.$x.' '.$top.'V'.$bottom.'H'.$right.'" fill="none" stroke="#73777c"/>';
 }
 private static function n(float $n):string{return rtrim(rtrim(number_format($n,3,'.',''),'0'),'.')?:'0';}
 private static function wrap(string $title,int $w,int $h,string $body):string{return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$w.' '.$h.'" role="img" aria-label="'.$title.'" style="font-family:DejaVu Sans,sans-serif;font-size:14px;fill:#191a1b"><title>'.$title.'</title>'.$body.'</svg>';}
}
