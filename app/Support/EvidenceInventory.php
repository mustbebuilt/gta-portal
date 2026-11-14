<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EvidenceInventory
{
    private const SOURCES=[
        'task_evidence'=>['learning_task_id','disk','path','sha256','bytes'],
        'learning_tasks'=>['version','submissions'],
        'training_topics'=>['version','files','submissions','history'],
        'qualifications'=>['version','files','history'],
        'support_plans'=>['version','files','active_plan','reviews','history'],
        'staff_development_profiles'=>['version','payload'],
        'extra_trainings'=>['version','file','history','approval','agreement','compensation'],
        'signed_review_records'=>['version','file','snapshot','history'],
    ];
    private array $references=[];
    private array $objects=[];
    private array $modules=[];
    private array $rows=[];
    private array $taskFiles=[];
    private array $issues=[];
    private array $profiles=[];

    private function issue(string $table,int $id,string $column,string $code,int $ordinal=0): void
    {
        $this->issues[]=['module'=>$table,'record_id'=>$id,'column'=>$column,'reference_ordinal'=>$ordinal,'status'=>$code];
        $this->modules[$table]['issues']++;
    }

    private function entry(string $table,int $id,string $column,string $slot,array $entry): void
    {
        $legacy=!isset($entry['disk']);$codes=[];$location=null;
        try{$location=PrivateEvidence::location($entry);}catch(\Throwable){$codes[]='location_or_disk_invalid';}
        $sha=$entry['sha256']??null;$bytes=$entry['bytes']??null;
        if(!is_string($sha) || !preg_match('/^[a-f0-9]{64}$/',$sha)){$codes[]='hash_missing_or_invalid';}
        if(!is_int($bytes) || $bytes<1){$codes[]='size_missing_or_invalid';}
        $ref=['module'=>$table,'record_id'=>$id,'column'=>$column,'reference_ordinal'=>++$this->modules[$table]['references'],'legacy'=>$legacy,'status'=>$codes?'metadata_needs_checking':'referenced_only','issues'=>$codes];
        // Internal binding deliberately excludes names, purpose, notes and document contents.
        $binding=['module'=>$table,'id'=>$id,'column'=>$column,'slot'=>hash('sha256',$slot),'disk'=>$entry['disk']??null,'path'=>$entry['path']??null,'sha256'=>$sha,'bytes'=>$bytes,'parent_task'=>$entry['learning_task_id']??null];
        $ref['binding']=ReviewedImports::hash($binding);
        if($location){
            $this->profiles[$location['disk']]=collect(config('filesystems.disks.'.$location['disk']))->only('driver','root','prefix','bucket','endpoint','region','url','visibility')->all();
            $key=ReviewedImports::hash($location);$ref['object_token']=$key;
            if(!isset($this->objects[$key])){$this->objects[$key]=['entry'=>[...$location,'sha256'=>$sha,'bytes'=>$bytes],'declarations'=>[],'status'=>'not_checked'];}
            $this->objects[$key]['declarations'][ReviewedImports::hash([$sha,$bytes])]=true;
        }
        $this->references[]=$ref;
        if($codes){$this->modules[$table]['issues']++;}
    }

    private function walk(mixed $node,string $table,int $id,string $column,string $slot='',int $depth=0,bool $force=false): void
    {
        if($node===null){return;}
        if($depth>64){$this->issue($table,$id,$column,'metadata_depth_exceeded');return;}
        if(!is_array($node)){if($force){$this->issue($table,$id,$column,'file_metadata_not_object');}return;}
        if($force || array_key_exists('path',$node) || (isset($node['sha256']) && (isset($node['bytes']) || isset($node['name'])))){
            $this->entry($table,$id,$column,$slot,$node);return;
        }
        if(!array_is_list($node)){ksort($node);}
        foreach($node as $key=>$value){
            $path=$slot.'/'.$key;
            if($key==='files' && $value!==null){
                if(!is_array($value)){$this->issue($table,$id,$column,'file_container_not_array');continue;}
                foreach($value as $index=>$entry){$this->walk($entry,$table,$id,$column,$path.'/'.$index,$depth+1,true);}
            }else{$this->walk($value,$table,$id,$column,$path,$depth+1);}
        }
    }

    private function json(mixed $value,string $table,int $id,string $column): mixed
    {
        if($value===null){return null;}
        try{$decoded=is_string($value)?json_decode($value,true,128,JSON_THROW_ON_ERROR):$value;}
        catch(\Throwable){$this->issue($table,$id,$column,'invalid_json');return null;}
        if($decoded!==null && !is_array($decoded)){$this->issue($table,$id,$column,'json_not_array_or_object');return null;}
        return $decoded;
    }

    private function taskLinks(array $submissions,int $task): void
    {
        foreach($submissions as $index=>$submission){
            if(!is_array($submission)){ $this->issue('learning_tasks',$task,'submissions','invalid_submission');continue;}
            if(!array_key_exists('evidence',$submission)){continue;}
            if(!is_array($submission['evidence'])){$this->issue('learning_tasks',$task,'submissions','invalid_evidence_links');continue;}
            foreach($submission['evidence'] as $slot=>$link){
                if(!is_array($link) || !(is_int($link['id']??null) || (is_string($link['id']??null) && ctype_digit($link['id'])))){$this->issue('learning_tasks',$task,'submissions','invalid_task_file_link');continue;}
                $file=$this->taskFiles[$link['id']]??null;
                if(!$file){$this->issue('learning_tasks',$task,'submissions','missing_task_file_record');continue;}
                if($file['learning_task_id']!==$task){$this->issue('learning_tasks',$task,'submissions','task_file_owner_mismatch');continue;}
                if(($link['sha256']??null)!==$file['sha256']){$this->issue('learning_tasks',$task,'submissions','task_file_hash_disagrees');continue;}
                $this->entry('learning_tasks',$task,'submissions',$index.'/evidence/'.$slot,$file);
            }
        }
    }

    private function scan(): void
    {
        foreach(self::SOURCES as $table=>$columns){
            $this->modules[$table]=['rows'=>0,'references'=>0,'issues'=>0,'schema'=>'available'];
            if(!Schema::hasTable($table) || !Schema::hasColumns($table,['id',...$columns])){
                $this->modules[$table]['schema']='missing_or_incompatible';$this->modules[$table]['issues']++;continue;
            }
            DB::table($table)->select(['id',...$columns])->orderBy('id')->chunkById(100,function($records) use($table,$columns): void {
                foreach($records as $row){
                    $id=(int)$row->id;$this->modules[$table]['rows']++;
                    $this->rows[]=[$table,$id,$row->version??null];
                    if($table==='task_evidence'){
                        $entry=(array)$row;$entry['learning_task_id']=(int)$entry['learning_task_id'];$size=$entry['bytes'];if(is_string($size) && preg_match('/^[1-9][0-9]*$/',$size) && (string)(int)$size===$size){$entry['bytes']=(int)$size;}
                        $this->taskFiles[$id]=$entry;$this->entry($table,$id,'file','',$entry);continue;
                    }
                    foreach($columns as $column){
                        if($column==='version'){continue;}$value=$this->json($row->$column,$table,$id,$column);
                        if($value===null){continue;}
                        if($table==='learning_tasks'){$this->taskLinks($value,$id);}
                        elseif($column==='files'){
                            foreach($value as $slot=>$entry){$this->walk($entry,$table,$id,$column,(string)$slot,0,true);}
                        }else{$this->walk($value,$table,$id,$column,'',0,$column==='file');}
                    }
                }
            });
        }
        foreach($this->objects as &$object){
            if(count($object['declarations'])>1){$object['status']='conflicting_declarations';}
            elseif(!is_string($object['entry']['sha256']) || !preg_match('/^[a-f0-9]{64}$/',$object['entry']['sha256']) || !is_int($object['entry']['bytes']) || $object['entry']['bytes']<1){$object['status']='metadata_needs_checking';}
        }unset($object);
    }

    private function binding(): string
    {
        $refs=array_column($this->references,'binding');sort($refs);
        return ReviewedImports::hash(['rows'=>$this->rows,'references'=>$refs,'issues'=>$this->issues,'schemas'=>array_map(fn($m)=>$m['schema'],$this->modules),'legacy_disk'=>config('evidence.legacy_disk'),'storage_profiles'=>$this->profiles]);
    }

    // Internal migration planner API. Full locators remain server-side, never CLI inventory output.
    public static function copyManifest(string $sourceDisk): array
    {
        PrivateEvidence::diskName(['disk'=>$sourceDisk]);$scan=new self;$scan->scan();
        foreach($scan->modules as $module){if($module['issues'] || $module['schema']!=='available'){throw new \RuntimeException('Reconcile the inventory before planning copies.');}}
        $objects=[];
        foreach($scan->objects as $key=>$object){
            if($object['status']!=='not_checked'){throw new \RuntimeException('Reconcile conflicting/incomplete source metadata.');}
            if($object['entry']['disk']===$sourceDisk){$objects[$key]=$object['entry'];}
        }
        ksort($objects);
        return ['inventory_binding'=>$scan->binding(),'objects'=>$objects];
    }

    public static function run(bool $verify=false,bool $details=false): array
    {
        $inventory=new self;$inventory->scan();$binding=$inventory->binding();$changed=false;
        if($verify){
            foreach($inventory->objects as &$object){
                if($object['status']!=='not_checked'){continue;}
                try{
                    $actual=PrivateEvidence::fingerprint($object['entry']);
                    $object['status']=!$actual['exists']?'missing':(($actual['bytes']===$object['entry']['bytes'] && hash_equals($object['entry']['sha256'],$actual['sha256']))?'verified':'hash_or_size_disagrees');
                }catch(\Throwable){$object['status']='unreadable';}
            }unset($object);
            // File/network work holds no database transaction. A second scan detects reference drift.
            $after=new self;$after->scan();$changed=$after->binding()!==$binding;
        }
        $totals=['rows'=>array_sum(array_column($inventory->modules,'rows')),'references'=>count($inventory->references),'objects'=>count($inventory->objects),'legacy_references'=>0,'pinned_references'=>0,'metadata_issues'=>count($inventory->issues),'schema_issues'=>count(array_filter($inventory->modules,fn($m)=>$m['schema']!=='available')),'conflicting_objects'=>0,'declared_unique_bytes'=>0,'objects_with_unknown_size'=>0,'verified_objects'=>0,'missing_objects'=>0,'changed_objects'=>0,'unreadable_objects'=>0,'unverified_objects'=>0];
        foreach($inventory->references as &$reference){
            $totals[$reference['legacy']?'legacy_references':'pinned_references']++;
            if($reference['issues']){$totals['metadata_issues']++;}
            if(isset($reference['object_token']) && $reference['status']==='referenced_only'){$reference['status']=$inventory->objects[$reference['object_token']]['status'];}
            unset($reference['binding']);
        }unset($reference);
        foreach($inventory->objects as $object){
            $status=$object['status'];
            if($status==='conflicting_declarations'){$totals['conflicting_objects']++;}
            if(is_int($object['entry']['bytes']) && $object['entry']['bytes']>0 && $status!=='conflicting_declarations'){$totals['declared_unique_bytes']+=$object['entry']['bytes'];}else{$totals['objects_with_unknown_size']++;}
            $key=match($status){'verified'=>'verified_objects','missing'=>'missing_objects','hash_or_size_disagrees'=>'changed_objects','unreadable'=>'unreadable_objects',default=>'unverified_objects'};$totals[$key]++;
        }
        $needs=$changed || $totals['schema_issues'] || $totals['metadata_issues'] || $totals['conflicting_objects'] || $totals['missing_objects'] || $totals['changed_objects'] || $totals['unreadable_objects'];
        $result=['format_version'=>1,'captured_at'=>now()->toIso8601String(),'mode'=>$verify?'verify_referenced_files':'references_only','result'=>$needs?'needs_checks':($verify?'verified_referenced_objects':'references_scanned'),'totals'=>$totals,'modules'=>$inventory->modules,'inventory_binding'=>$binding,'reference_change_detected'=>$changed,'writes_performed'=>false,'files_read'=>$verify,'migration_authorised'=>false,'object_identity'=>'Resolved configured disk name plus relative path; aliases of the same physical location are not merged.', 'coverage'=>'Known portal upload records and retained metadata/links only; no bucket/directory listing, unregistered-file discovery or complete-backup claim.','verification_scope'=>'File identity/bytes only; no approval, signature authenticity, funding or backup certification.', 'consistency'=>'Read-only live scan, not a frozen backup. Optional verification re-scans metadata after file reads; matching bindings do not certify ongoing consistency.'];
        if($details){$result['reference_details']=$inventory->references;$result['issue_details']=$inventory->issues;}
        return $result;
    }
}
