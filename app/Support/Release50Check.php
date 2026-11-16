<?php
namespace App\Support;
class Release50Check
{
 public static function contrast(string $a,string $b):float
 {
  $luminance=static function(string $hex):float{if(!preg_match('/^#[a-f0-9]{6}$/iD',$hex)){throw new \InvalidArgumentException('Six-digit theme colour needed.');}$v=[];foreach([1,3,5]as$i){$n=hexdec(substr($hex,$i,2))/255;$v[]=$n<=.04045?$n/12.92:(($n+.055)/1.055)**2.4;}return .2126*$v[0]+.7152*$v[1]+.0722*$v[2];};$x=$luminance($a);$y=$luminance($b);return(max($x,$y)+.05)/(min($x,$y)+.05);
 }
 public static function report(bool $environment=false,bool $database=false):array
 {
  $r=Release49Check::report($environment,$database);$r['pack']='50';$r['theme_contrast']=[];
  try{$tokens=json_decode(file_get_contents(resource_path('data/visual-theme50.json')),true,512,JSON_THROW_ON_ERROR);$css=file_get_contents(public_path('assets/portal.css'));if(is_file(public_path('assets/theme53.css')))$css.=file_get_contents(public_path('assets/theme53.css'));foreach(['dark','light']as$theme){$t=$tokens[$theme];foreach(['background','surface']as$surface){foreach(['text','muted','link','danger','approved']as$ink){$key=$theme.':'.$ink.':'.$surface;$ratio=self::contrast($t[$ink],$t[$surface]);$r['theme_contrast'][$key]=round($ratio,3);$r['checks']['contrast:'.$key]=$ratio>=4.5;}}$r['checks']['contrast:'.$theme.':button']=self::contrast($t['button'],$t['button_text'])>=4.5;foreach($t as$value){$r['checks']['palette:'.$theme.':'.$value]=str_contains($css,$value);}}}catch(\Throwable){$r['checks']['theme_tokens']=false;}
  if(in_array(false,$r['checks'],true)){$r['status']='needs_attention';}
  $r['contrast_scope']='Calculated solid-colour token pairs only; not rendered-page, browser, phone, keyboard or screen-reader acceptance';$r['new_migrations']=0;$r['new_dependencies']=0;$r['actual_target_acceptance']=false;$r['writes_performed']=false;return $r;
 }
}
