<?php
namespace App\Http\Controllers;
use App\Support\MobileVerification;
use Illuminate\Http\Request;
class MobileVerificationController extends Controller {
 public function issue(Request $r) {
  $state=MobileVerification::issue($r);
  return to_route('alerts.index')->with('status',match($state){'Accepted'=>'Code request accepted by the SMS provider. Enter the code from your phone; provider acceptance does not confirm handset delivery.','Rejected'=>'The SMS provider rejected this request. Contact GTA if it continues.','Superseded'=>'Your details changed before the code could be sent. Reopen your preferences.',default=>'The sending outcome is uncertain. Do not repeatedly request codes; wait and contact GTA if needed.'});
 }
 public function confirm(Request $r) {
  abort_unless($r->user()->role==='apprentice',403);
  $data=$r->validate(['challenge_id'=>['required','uuid'],'code'=>['required','string','regex:/^[0-9]{8}$/']]);
  MobileVerification::confirm($r,$data['challenge_id'],$data['code']);
  return to_route('alerts.index')->with('status','Your saved mobile number is verified. This does not enable automatic task/review reminders or change your alert choice.');
 }
}
