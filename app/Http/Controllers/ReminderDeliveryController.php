<?php
namespace App\Http\Controllers;
use App\Models\{ReminderMail,User};
use App\Support\ReminderDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
class ReminderDeliveryController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('manage-directories');
        $data=$request->validate(['state'=>['nullable','in:all,'.implode(',',array_keys(ReminderDelivery::LABELS))]]);$state=$data['state']??'all';
        $rows=ReminderMail::when($state!=='all',fn($q)=>$q->where('state',$state))->orderByDesc('id')->paginate(20)->withQueryString();
        $users=User::whereIn('id',$rows->pluck('recipient_id'))->get()->keyBy('id');
        return view('reminder-mail.index',['preview'=>ReminderDelivery::preview(),'rows'=>$rows,'users'=>$users,'state'=>$state]);
    }
    public function prepare(Request $request)
    {
        Gate::authorize('manage-directories');$data=$request->validate(['recipient_id'=>['required','integer'],'fingerprint'=>['required','regex:/^[a-f0-9]{64}$/']]);
        $row=ReminderDelivery::prepare($request->user(),$request->session()->get('auth_version'),$data['recipient_id'],$data['fingerprint']);
        return to_route('reminder-mail.show',$row)->with('status','Prepared source snapshot. No email was sent.');
    }
    public function show(ReminderMail $delivery)
    {
        Gate::authorize('manage-directories');
        return view('reminder-mail.show',['delivery'=>$delivery,'recipient'=>User::find($delivery->recipient_id),'events'=>DB::table('reminder_mail_events')->where('outbox_id',$delivery->id)->orderBy('id')->get()]);
    }
    public function transition(Request $request,ReminderMail $delivery)
    {
        Gate::authorize('manage-directories');$data=$request->validate(['version'=>['required','integer'],'action'=>['required','in:review,cancel'],'confirmed'=>['required','accepted']]);
        ReminderDelivery::transition($delivery,$request->user(),$request->session()->get('auth_version'),$data['version'],$data['action']);
        return to_route('reminder-mail.show',$delivery)->with('status','Delivery record updated. No email was sent.');
    }
}
