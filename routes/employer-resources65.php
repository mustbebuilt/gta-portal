<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EmployerResources65Controller;
// Load ONCE from routes/web.php; web.php already applies session and CSRF middleware.
Route::middleware(['auth', 'can:view-employer-resources'])->prefix('employer-resources')->name('employer-resources.')->group(function () {
    Route::get('/', [EmployerResources65Controller::class, 'index'])->name('index');
    Route::get('/state', [EmployerResources65Controller::class, 'state'])->name('state');
    Route::post('/progress', [EmployerResources65Controller::class, 'save'])->middleware('throttle:60,1')->name('save');
});
