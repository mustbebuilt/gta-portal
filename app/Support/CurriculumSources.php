<?php
namespace App\Support;
use App\Models\{Apprentice,LearningTask};

class CurriculumSources
{
    public const LABELS=['current'=>'Matches current task source','retained'=>'Recognised retained PDF; saved schema differs','different'=>'Saved schema differs from current source','unrecognised'=>'Saved source not recognised in retained receipts','invalid'=>'Saved task metadata needs checking','unexpected'=>'Task reference outside the linked catalogue'];
    private static function data(string $name): array
    {
        $text=file_get_contents(resource_path('data/'.$name.'.json'),false,null,0,5*1024*1024+1);
        if($text===false || strlen($text)>5*1024*1024){throw new \RuntimeException('Source data unavailable or oversized.');}
        $value=json_decode($text,true,512,JSON_THROW_ON_ERROR);if(!is_array($value)){throw new \RuntimeException('Invalid source data shape.');}return $value;
    }
    public static function schemaHash(array $schema): string {return ReviewedImports::hash($schema);}
    private static function pdf(string $filename): array
    {
        $base=realpath(resource_path('task-pdfs'));$path=resource_path('task-pdfs/'.$filename);
        if($filename==='' || basename($filename)!==$filename || !preg_match('/^[A-Za-z0-9_.-]+\.pdf$/',$filename) || !$base || is_link($path)){return ['status'=>'invalid_pdf_location'];}
        $real=realpath($path);if(!$real || !str_starts_with($real,$base.DIRECTORY_SEPARATOR) || !is_file($real)){return ['status'=>'missing_pdf'];}
        $bytes=filesize($real);if($bytes===false || $bytes<1 || $bytes>20*1024*1024){return ['status'=>'pdf_size_needs_checking'];}
        $sha=hash_file('sha256',$real);return is_string($sha)?['status'=>'read','sha256'=>$sha,'bytes'=>$bytes]:['status'=>'unreadable_pdf'];
    }
    public static function release(string $key): array
    {
        if(!isset(TaskCatalogue::PROGRAMMES[$key])){throw new \InvalidArgumentException('Unknown loaded programme.');}
        $issues=[];$checks=[];$tasks=[];$templates=[];$notices=[];$inputs=[];$receipt=[];
        try{
            foreach([$key.'-tasks',$key.'-catalogue','final-task-pack-receipt','final-task-pack-v1-receipt','task-pack-qa2-audit',($key==='autocare'?'original-pdf-hashes':$key.'-source-receipt')]as$name){$data[$name]=self::data($name);$inputs[$name]=ReviewedImports::hash($data[$name]);}
            $tasks=$data[$key.'-tasks'];$catalogue=$data[$key.'-catalogue'];$templates=$catalogue['templates']??[];$map=$catalogue['tasks']??[];
            if(!array_is_list($tasks) || count($tasks)!==50 || !is_array($templates) || !is_array($map) || count($map)!==50){$issues[]='catalogue_shape';}
            foreach(($data['final-task-pack-receipt']['pdfs']??[])as$item){if(($item['catalogue']??null)===$key){$receipt[$item['reference']??''][]=$item;}}
            if(count($receipt)!==50){$issues[]='release_receipt_count';}
            $expected=[];for($b=1;$b<=10;$b++){for($t=1;$t<=5;$t++){$expected[]=($key==='autocare'?'':$key.'-').'b'.$b.'-t'.$t;}}
            $ids=[];
            foreach($tasks as$task){
                if(!is_array($task)){$issues[]='task_metadata_shape';continue;}$ref=$task['id']??'';$ids[]=$ref;$itemIssues=[];
                if(!is_string($ref) || !in_array($ref,$expected,true) || !is_int($task['block']??null) || !is_int($task['task']??null) || $ref!==($key==='autocare'?'':$key.'-').'b'.($task['block']??'').'-t'.($task['task']??'')){$itemIssues[]='task_identity';}
                $filename=is_string($ref)?($map[$ref]??null):null;$schema=is_string($filename)?($templates[$filename]??null):null;
                $source=is_string($ref)?($receipt[$ref]??[]):[];
                if(!is_array($schema) || !is_string($filename) || ($task['template']??null)!==$filename || ($schema['filename']??null)!==$filename || ($schema['sourceBlock']??null)!==($task['block']??null) || ($schema['sourceTask']??null)!==($task['task']??null) || ($schema['catalogueId']??null)!==$key){$itemIssues[]='catalogue_mapping';}
                if(count($source)!==1 || !is_array($schema) || ($schema['sourcePdfFilename']??null)!==($source[0]['filename']??null) || ($schema['sourceTemplateHash']??null)!==($source[0]['sha256']??null) || !preg_match('/^[a-f0-9]{64}$/',$source[0]['sha256']??'')){$itemIssues[]='receipt_binding';}
                $pdf=['status'=>'not_checked'];
                if(is_array($schema) && is_string($schema['sourcePdfFilename']??null)){$pdf=self::pdf($schema['sourcePdfFilename']);if(($pdf['sha256']??null)!==($source[0]['sha256']??null) || $pdf['status']!=='read'){$itemIssues[]='pdf_hash_or_availability';}}
                else{$itemIssues[]='pdf_metadata';}
                $checks[]=['reference'=>is_string($ref)?$ref:'invalid','issues'=>$itemIssues,'pdf'=>$pdf,'schema_hash'=>is_array($schema)?self::schemaHash($schema):null];
                foreach($itemIssues as$issue){$issues[]=(is_string($ref)?$ref:'invalid').':'.$issue;}
            }
            if(count(array_unique($ids,SORT_REGULAR))!==50 || array_diff($expected,$ids) || array_diff($ids,$expected)){$issues[]='task_set_or_duplicates';}
            foreach(($data['task-pack-qa2-audit']['open_items']??[])as$notice){if(($key==='paint' && ($notice['id']??null)==='C1') || ($key==='autocare' && ($notice['id']??null)==='C2')){$notices[]=$notice;}}
            if(in_array($key,['paint','autocare'],true) && (count($notices)!==1 || !filled($notices[0]['topic']??null) || !filled($notices[0]['detail']??null))){$issues[]='retained_curriculum_notice_missing';}
            $retained=[];$retainedRefs=[];foreach([$data['final-task-pack-receipt'],$data['final-task-pack-v1-receipt']]as$sourceReceipt){foreach(($sourceReceipt['pdfs']??[])as$item){if(($item['catalogue']??null)===$key && is_string($item['filename']??null) && is_string($item['sha256']??null)){$retained[$item['filename']]=$item['sha256'];$retainedRefs[$item['filename']][]=$item['reference']??'';}}}
            if($key==='autocare'){foreach($data['original-pdf-hashes']as$name=>$digest){if(is_string($name) && is_string($digest)){$retained[$name]=$digest;}}}
            else{foreach(($data[$key.'-source-receipt']['pdfs']??[])as$item){if(is_string($item['storedFilename']??null) && is_string($item['sourcePdfHash']??null)){$retained[$item['storedFilename']]=$item['sourcePdfHash'];}}}
        }catch(\Throwable){$issues[]='source_data_unreadable_or_incompatible';$retained=[];$retainedRefs=[];}
        $binding=ReviewedImports::hash(['key'=>$key,'programme'=>TaskCatalogue::PROGRAMMES[$key],'inputs'=>$inputs,'checks'=>$checks,'issues'=>$issues,'notices'=>$notices]);
        return ['key'=>$key,'programme'=>TaskCatalogue::PROGRAMMES[$key],'binding'=>$binding,'technical_issues'=>array_values(array_unique($issues)),'technical_ok'=>$issues===[],'task_count'=>count($tasks),'pdfs_checked'=>count(array_filter($checks,fn($check)=>$check['pdf']['status']==='read')),'notices'=>$notices,'checks'=>$checks,'tasks'=>$tasks,'templates'=>$templates,'retained'=>$retained,'retained_references'=>$retainedRefs,'file_reads'=>true,'writes_performed'=>false,'curriculum_approval'=>false];
    }
    public static function allowed(\App\Models\User $user,Apprentice $a): bool {return LearningAccess::manager($user)||LearningAccess::tutor($user,$a);}
    public static function apprentice(Apprentice $a): array
    {
        $a=$a->fresh();$programme=$a->programmeRecord()->first();$group=$a->groupRecord()->first();$key=TaskCatalogue::keyFor($a);$issues=[];
        if(!$a->start_date){$issues[]='First learning date is not recorded; confirm cohort/version scope.';}
        if(!$a->programme_id){$issues[]='Explicit programme/version link is missing; legacy Autocare fallback is not cohort confirmation.';}
        if(!$key){$issues[]='Linked programme/version is inactive, unavailable or outside the four loaded catalogues.';}
        $release=$key?self::release($key):null;$definitions=collect($release['tasks']??[])->keyBy('id');$expected=$definitions->keys()->all();
        $total=LearningTask::where('apprentice_id',$a->id)->count();
        $rows=LearningTask::where('apprentice_id',$a->id)->orderBy('id')->limit(201)->get(['id','reference','block','title','theme','template']);$items=[];$refs=[];
        if($total>200){$issues[]='More than 200 task records; reconcile the source set before reviewing.';}
        foreach($rows->take(200)as$task){
            $schema=$task->template;$ref=$task->reference;$refs[]=$ref;$definition=$definitions->get($ref);$savedHash=is_array($schema)?self::schemaHash($schema):null;
            $category='unexpected';$currentHash=null;
            if($definition){
                $current=$release['templates'][$definition['template']]??null;$currentHash=is_array($current)?self::schemaHash($current):null;
                if(!is_array($schema) || !is_int($schema['sourceBlock']??null) || ($schema['sourceBlock']??null)!==$task->block || ($schema['sourceTask']??null)!==$definition['task'] || ($schema['catalogueId']??'autocare')!==$key){$category='invalid';}
                elseif($currentHash!==null && hash_equals($currentHash,$savedHash) && $task->block===$definition['block'] && $task->title===$definition['title'] && $task->theme===$definition['theme']){$category='current';}
                elseif(is_string($schema['sourcePdfFilename']??null) && ($release['retained'][$schema['sourcePdfFilename']]??null)===($schema['sourceTemplateHash']??null)){$category=($schema['sourcePdfFilename']??null)!==($current['sourcePdfFilename']??null)?'retained':'different';}
                else{$category='unrecognised';}
            }
            $savedPdf=is_array($schema) && is_string($schema['sourcePdfFilename']??null)?self::pdf($schema['sourcePdfFilename']):['status'=>'no_saved_pdf_metadata'];
            $knownRefs=is_array($schema)?($release['retained_references'][$schema['sourcePdfFilename']??'']??[]):[];
            if($knownRefs && !in_array($ref,$knownRefs,true)){$category='invalid';}
            if($category!=='unexpected' && ($savedPdf['status']!=='read' || ($savedPdf['sha256']??null)!==($schema['sourceTemplateHash']??null))){$category='invalid';}
            $items[]=['saved_pdf'=>$savedPdf,'id'=>$task->id,'reference'=>$ref,'block'=>$task->block,'category'=>$category,'saved_schema_hash'=>$savedHash,'current_schema_hash'=>$currentHash,'saved_record_source_hash'=>ReviewedImports::hash([$task->title,$task->theme,$task->block,$schema])];
        }
        $missing=array_values(array_diff($expected,$refs));$duplicates=array_keys(array_filter(array_count_values($refs),fn($count)=>$count>1));
        if($duplicates){$issues[]='Duplicate task references require investigation.';}
        $context=['apprentice_id'=>$a->id,'programme_id'=>$a->programme_id,'programme'=>$programme?collect($programme->getAttributes())->only('standard_code','version','active')->all():null,'tutor_group_id'=>$a->tutor_group_id,'group'=>$group?collect($group->getAttributes())->only('programme_id','active','reference')->all():null,'tutor_id'=>$a->tutor_id,'start_date'=>$a->start_date?->toDateString()];
        $snapshot=['total_records'=>$total,'context'=>$context,'catalogue_key'=>$key,'release_binding'=>$release['binding']??null,'technical_issues'=>$release['technical_issues']??['no_loaded_release'],'source_notices'=>$release['notices']??[],'items'=>$items,'missing'=>$missing,'duplicates'=>$duplicates,'context_issues'=>$issues];
        return ['binding'=>ReviewedImports::hash($snapshot),'snapshot'=>$snapshot,'release'=>$release,'counts'=>array_count_values(array_column($items,'category')),'scope_ok'=>$issues===[] && $release && $release['technical_ok'],'missing_count'=>count($missing),'recorded_count'=>$total,'automatic_changes'=>false];
    }
    public static function safeRelease(array $r): array
    {
        return collect($r)->only('key','programme','binding','technical_issues','technical_ok','task_count','pdfs_checked','notices','file_reads','writes_performed','curriculum_approval')->all();
    }
}
