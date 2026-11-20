<?php
use App\Http\Controllers\RoutineSupportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth',\App\Http\Middleware\ActiveAccount::class,\App\Http\Middleware\RequireSecondFactor::class,\App\Http\Middleware\RequirePrivacyAcknowledgement::class])->group(function () {
    Route::get('/apprentices/{apprentice}/routine-support/{plan?}',[RoutineSupportController::class,'show'])->name('routine-support.show');
    Route::post('/apprentices/{apprentice}/routine-support/{plan?}',[RoutineSupportController::class,'save'])->name('routine-support.save');
    Route::get('/apprentices/{apprentice}/training-journey',[\App\Http\Controllers\TrainingJourney72Controller::class,'show'])->name('training-journey72.show');
    Route::get('/reports/training-gaps',[\App\Http\Controllers\TrainingJourney72Controller::class,'gaps'])->name('training-gaps72.index');
    Route::get('/support-register',[\App\Http\Controllers\SupportRegister71Controller::class,'index'])->name('support-register71.index');
    Route::get('/support-register/reports',[\App\Http\Controllers\SupportRegister71Controller::class,'reports'])->name('support-register71.reports');
    Route::post('/support-register/markers',[\App\Http\Controllers\SupportRegister71Controller::class,'addMarker'])->name('support-register71.add-marker');
    Route::post('/support-register/schedules/{schedule}/review',[\App\Http\Controllers\SupportRegister71Controller::class,'review'])->name('support-register71.review');
    Route::post('/support-register/actions',[\App\Http\Controllers\SupportRegister71Controller::class,'addAction'])->name('support-register71.add-action');
    Route::post('/support-register/actions/{action}/complete',[\App\Http\Controllers\SupportRegister71Controller::class,'completeAction'])->name('support-register71.complete-action');
    Route::get('/apprentices/{apprentice}/progress-review74',[\App\Http\Controllers\ProgressReview74Controller::class,'show'])->name('progress-review74.show');
});
