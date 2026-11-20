<?php
use App\Http\Controllers\RoutineSupportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth',\App\Http\Middleware\ActiveAccount::class,\App\Http\Middleware\RequireSecondFactor::class,\App\Http\Middleware\RequirePrivacyAcknowledgement::class])->group(function () {
    Route::get('/apprentices/{apprentice}/routine-support/{plan?}',[RoutineSupportController::class,'show'])->name('routine-support.show');
    Route::post('/apprentices/{apprentice}/routine-support/{plan?}',[RoutineSupportController::class,'save'])->name('routine-support.save');
});
