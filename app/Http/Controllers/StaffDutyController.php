<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\StaffDuties;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StaffDutyController extends Controller
{
    public function save(Request $request, User $user)
    {
        Gate::authorize('manage-directories');
        $data=$request->validate(['record_version'=>['required','integer','min:1'],'duty_version'=>['required','integer','min:0'],
            'duties'=>['sometimes','array:careers_adviser,mentor'],'duties.careers_adviser'=>['sometimes','boolean'],'duties.mentor'=>['sometimes','boolean']]);
        $changed=StaffDuties::save($user,$request->user(),$request->session()->get('auth_version'),$data['record_version'],$data['duty_version'],$data['duties']??[]);
        return to_route('directories.show',['staff',$user->id])->with('status',$changed?'Additional duties saved. Older sessions and pending account links were invalidated. Apprentice assignments and saved work were preserved.':'No additional-duty changes were needed.');
    }
}
