<?php
namespace App\Support;
use App\Models\LearningTask;
/** Explains existing controls; never authorises a write or changes a deadline. */
class TaskAvailability {
 public static function for(LearningTask $task,?array $release=null):array {
  $state='ready';$label='Available apprentice work';$owner='Apprentice';$detail='Use the current task form to save work or submit to the Tutor.';
  if(($task->template['kind']??'source-pending')==='source-pending'){$state='source';$label='Task source needs checking';$owner='Administrator';$detail='The programme source must be checked before work can be recorded. Ask the Tutor or an administrator to check the source.';}
  elseif(!$task->editable()){$state='read-only';$label='Recorded task state';$owner=$task->status==='Awaiting marking'?'Main tutor':'Tutor';$detail='Check the recorded task status. This is not editable apprentice work.';}
  elseif(!($release??BlockRelease::state($task))['open']){$state='locked';$label='Training block not released';$owner='Tutor / administrator';$detail='The block is not open for apprentice work. Ask the Tutor to check the sequence and plan; an administrator controls any recorded release exception.';}
  elseif(($task->template['kind']??null)==='knowledge'){$state='paper';$label='Paper assessment needs Tutor follow-up';$owner='Main tutor';$detail='This is a paper assessment. The Tutor arranges/checks the assessment and records the result; the apprentice cannot submit it as an online task.';}
  return ['state'=>$state,'label'=>$label,'owner'=>$owner,'detail'=>$detail,'available'=>$state==='ready'];
 }
}
