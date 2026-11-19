<?php
// Include INSIDE the existing authenticated, active-account, MFA and privacy middleware group.
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GuidedSetup68Controller as Setup68Controller;
\Illuminate\Support\Facades\Route::prefix('apprentice-setup')->name('setup68.')->group(function(){
 Route::get('/',[Setup68Controller::class,'index'])->name('index');
 Route::post('/',[Setup68Controller::class,'create'])->middleware('throttle:20,1')->name('create');
 Route::get('/{draft}/{step?}',[Setup68Controller::class,'show'])->whereUuid('draft')->where('step','[1-6]')->name('show');
 Route::post('/{draft}/steps/{step}',[Setup68Controller::class,'save'])->whereUuid('draft')->where('step','[1-5]')->name('save');
 Route::post('/{draft}/submit',[Setup68Controller::class,'submit'])->whereUuid('draft')->name('submit');
 Route::post('/{draft}/confirm',[Setup68Controller::class,'confirm'])->whereUuid('draft')->name('confirm');
 Route::post('/{draft}/reopen',[Setup68Controller::class,'reopen'])->whereUuid('draft')->name('reopen');
});

Route::get('/employer-setup',[\App\Http\Controllers\EmployerSetup68Controller::class,'show'])->name('employer68.show');
Route::post('/employer-setup',[\App\Http\Controllers\EmployerSetup68Controller::class,'store'])->middleware('throttle:20,1')->name('employer68.store');
