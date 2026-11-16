<?php
namespace App\Support;
/** Read-only source and declared-pair checks. Never claims rendered or live acceptance. */
class Release53Check
{
 public static function theme():array
 {
  $checks=[];$pairs=[];
  try{
   $tokens=json_decode(file_get_contents(resource_path('data/theme53.json')),true,512,JSON_THROW_ON_ERROR);
   $receipts=json_decode(file_get_contents(resource_path('data/theme53-receipts.json')),true,512,JSON_THROW_ON_ERROR);
   foreach(['public/assets/theme53.css','public/assets/theme-boot53.js','resources/data/theme53.json','public/assets/gta-logo.jpg']as$path){$file=base_path($path);$checks['receipt:'.$path]=isset($receipts[$path])&&is_file($file)&&!is_link($file)&&hash_file('sha256',$file)===$receipts[$path]['sha256']&&filesize($file)===$receipts[$path]['bytes'];}
   $checks['original_logo']=hash_file('sha256',public_path('assets/gta-logo.jpg'))==='e24fbed4f2b1797d918b6ce390aff70685daa74317ba40095a0702e2e485c0b8';
   $declared=[['text-primary','background',4.5],['text-primary','surface',4.5],['text-primary','sidebar',4.5],['text-primary','header',4.5],['text-primary','menu-surface',4.5],['text-secondary','background',4.5],['text-secondary','notice',4.5],['link','background',4.5],['link','surface',4.5],['link-hover','surface',4.5],['link','notice',4.5],['text-primary','selected',4.5],['link','selected',3],['text-placeholder','surface',4.5],['control-border','background',3],['control-border','surface',3],['focus','surface',3],['focus','background',3],['focus','selected',3],['button-text','button-background',4.5],['button-text','button-hover',4.5],['button-background','surface',3],['error-text','error-background',4.5],['text-secondary','menu-surface',4.5]];
   foreach(['dark','light']as$mode){foreach($declared as[$fg,$bg,$target]){$value=Release50Check::contrast($tokens[$mode][$fg],$tokens[$mode][$bg]);$key=$mode.':'.$fg.':'.$bg;$pairs[$key]=['ratio'=>round($value,3),'target'=>$target,'passed'=>$value>=$target];$checks['pair:'.$key]=$value>=$target;}}
   foreach(['dark'=>['#79d99b','#ff9299','#efbf62','#6cb8f0'],'light'=>['#146735','#a51324','#815100','#005da8']]as$mode=>$inks){foreach($inks as$i=>$ink){$value=Release50Check::contrast($ink,$tokens[$mode]['notice']);$key=$mode.':labelled-status:'.$i;$pairs[$key]=['ratio'=>round($value,3),'target'=>4.5,'passed'=>$value>=4.5];$checks['pair:'.$key]=$value>=4.5;}}
   $layout=file_get_contents(resource_path('views/layout.blade.php'));
   $boot=strpos($layout,'theme-boot53.js');$base=strpos($layout,'assets/portal.css');$shared=strpos($layout,'assets/theme53.css');$scripts=strpos($layout,'assets/portal.js');
   $checks['early_boot_last_shared_css']=$boot!==false&&$base!==false&&$shared!==false&&$scripts!==false&&$boot<$base&&$base<$shared&&$shared<$scripts&&substr_count($layout,'theme53.css')===1;
  }catch(\Throwable){$checks['theme_source']=false;}
  return ['checks'=>$checks,'declared_solid_pairs'=>$pairs,'rendered_computed_colours_measured'=>false,'browser_screenshots_verified'=>false,'scope'=>'Opaque declared token pairs and file receipts only; CSS inheritance and actual controls require rendered checks.'];
 }
 public static function report(bool $environment=false,bool $database=false):array
 {
  $r=Release52Check::report($environment,$database);$theme=self::theme();$r['pack']='53';$r['checks']=array_merge($r['checks'],$theme['checks']);$r['theme53']=$theme;$r['theme_only']=true;$r['actual_target_acceptance']=false;$r['writes_performed']=false;if(in_array(false,$r['checks'],true))$r['status']='needs_attention';return $r;
 }
}
