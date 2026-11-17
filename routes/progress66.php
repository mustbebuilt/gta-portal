<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProgressDashboard66Controller as Dash;
// Loaded once inside web.php's normal web middleware; every action rechecks current server scope.
Route::middleware(['auth',\App\Http\Middleware\ActiveAccount::class,\App\Http\Middleware\RequireSecondFactor::class,\App\Http\Middleware\RequirePrivacyAcknowledgement::class])->group(function () {
 Route::get('/progress-dashboard',[Dash::class,'index'])->name('progress66.index');
 Route::get('/progress-dashboard/export/{format}',[Dash::class,'export'])->whereIn('format',['json','csv'])->name('progress66.export');
 Route::get('/progress-dashboard/demo/{role}',[Dash::class,'demo'])->whereIn('role',['admin','manager','tutor','apprentice','employer','mentor'])->name('progress66.demo');
 Route::get('/progress-dashboard/demo/{role}/export/{format}',[Dash::class,'demoExport'])->whereIn('role',['admin','manager','tutor','apprentice','employer','mentor'])->whereIn('format',['json','csv'])->name('progress66.demo-export');
});
