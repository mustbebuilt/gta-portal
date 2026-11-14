<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\Programme;
use App\Models\TutorGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InitialAdmin
{
    public static function available(): bool
    {
        return app()->environment('staging') && config('deployment.initial_admin_enabled')===true && StagingAccess::errors()===[];
    }

    public static function preview(): array
    {
        $state=DB::table('portal_bootstrap_states')->where('id',1)->first();
        $empty=!User::exists() && !Apprentice::exists() && !Employer::exists() && !Programme::exists() && !TutorGroup::exists();
        return ['available'=>self::available() && $empty && $state && !$state->completed_at,'empty_application'=>$empty,'completed'=>(bool)$state?->completed_at,'writes_performed'=>false];
    }

    public static function credentialsFromJson(string $input): array
    {
        try{$data=json_decode($input,true,4,JSON_THROW_ON_ERROR);}catch(\Throwable){$data=null;}
        if(!is_array($data) || strlen($input)>2048 || array_diff(array_keys($data),['password','password_confirmation']) || !isset($data['password'],$data['password_confirmation']) || !is_string($data['password']) || !is_string($data['password_confirmation'])){
            throw ValidationException::withMessages(['password'=>'Supply bounded JSON password and password_confirmation through private standard input.']);
        }
        return $data;
    }

    public static function create(string $name,string $email,string $password,string $confirmation): User
    {
        if(!self::available()){throw ValidationException::withMessages(['bootstrap'=>'Enable a correctly configured staging environment and the explicit initial-administrator setting.']);}
        $data=Validator::make(['name'=>trim($name),'email'=>strtolower(trim($email)),'password'=>$password,'password_confirmation'=>$confirmation],['name'=>['required','string','max:200'],'email'=>['required','email','max:254'],'password'=>AccountAccess::passwordRules()])->validate();
        return DB::transaction(function() use($data): User {
            // A singleton migration row serialises competing first-account attempts across instances.
            $state=DB::table('portal_bootstrap_states')->where('id',1)->lockForUpdate()->first();
            if(!self::available() || !$state || $state->completed_at || User::exists() || Apprentice::exists() || Employer::exists() || Programme::exists() || TutorGroup::exists()){
                throw ValidationException::withMessages(['bootstrap'=>'Initial administrator creation is only available once on an empty staging application.']);
            }
            $user=new User(['name'=>$data['name'],'email'=>$data['email'],'password'=>$data['password']]);
            $user->role='admin';$user->active=true;$user->remember_token=Str::random(60);$user->save();
            DB::table('account_access_events')->insert(['user_id'=>$user->id,'actor_id'=>null,'event'=>'initial_admin_created','origin'=>'staging_bootstrap','created_at'=>now()]);
            DB::table('portal_bootstrap_states')->where('id',1)->update(['completed_by'=>$user->id,'completed_at'=>now()]);
            return $user;
        });
    }
}
