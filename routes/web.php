<?php

use App\Http\Controllers\ActionPlanController;
use App\Http\Controllers\ApprenticeJourneyController;
use App\Http\Controllers\ApprenticeManagementController;
use App\Http\Controllers\ApprenticeTaskController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttentionController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CareerController;
use App\Http\Controllers\CaseStudyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnglishMathsController;
use App\Http\Controllers\GroupManagementController;
use App\Http\Controllers\HelpGuideController;
use App\Http\Controllers\InspectionOverviewController;
use App\Http\Controllers\OtjController;
use App\Http\Controllers\ProgressReviewController;
use App\Http\Controllers\ReportingController;
use App\Http\Controllers\StaffDevelopmentController;
use App\Http\Controllers\SupportPlanController;
use App\Http\Controllers\TaskMarkingController;
use Illuminate\Support\Facades\Route;

// Public Welcome Page (Vehicle showcase)
Route::get('/', function () {
    return view('welcome');
})->name('welcome');

// Guest Authentication Routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

// Authenticated Protected Routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // 10-Block Curriculum & Task Workspace
    Route::get('/tasks', [ApprenticeTaskController::class, 'index'])->name('tasks.index');
    Route::get('/apprentice/{apprentice}/tasks', [ApprenticeTaskController::class, 'index'])->name('apprentice.tasks');
    Route::post('/apprentice/{apprentice}/block-request', [ApprenticeTaskController::class, 'storeBlockRequest'])->name('tasks.block-request');
    Route::post('/block-request/{id}/decide', [ApprenticeTaskController::class, 'decideBlockRequest'])->name('tasks.block-decide')->middleware('role:admin');
    Route::get('/tasks/{task}', [ApprenticeTaskController::class, 'show'])->name('tasks.show');
    Route::post('/tasks/{task}/upload', [ApprenticeTaskController::class, 'uploadEvidence'])->name('tasks.upload');
    Route::post('/tasks/{task}/submit', [ApprenticeTaskController::class, 'submitForMarking'])->name('tasks.submit');
    Route::get('/tasks/{task}/uploads/{upload}/download', [ApprenticeTaskController::class, 'downloadEvidence'])->name('tasks.download');

    // Attendance Registers & RAG Metrics
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('/attendance/apprentice/{apprentice}', [AttendanceController::class, 'apprentice'])->name('attendance.apprentice');
    Route::post('/attendance/store', [AttendanceController::class, 'store'])->name('attendance.store')->middleware('role:admin,trainer,moderator');

    // Off-The-Job (OTJ) Hours Tracking
    Route::get('/otj', [OtjController::class, 'index'])->name('otj.index');
    Route::get('/apprentice/{apprentice}/otj', [OtjController::class, 'index'])->name('apprentice.otj');
    Route::post('/apprentice/{apprentice}/otj', [OtjController::class, 'store'])->name('otj.store');
    Route::post('/otj/{otj}/verify', [OtjController::class, 'verify'])->name('otj.verify')->middleware('role:admin,trainer');

    // Progress Reviews & Tripartite Signatures
    Route::get('/reviews', [ProgressReviewController::class, 'index'])->name('reviews.index');
    Route::get('/apprentice/{apprentice}/reviews', [ProgressReviewController::class, 'index'])->name('apprentice.reviews');
    Route::get('/reviews/{review}', [ProgressReviewController::class, 'show'])->name('reviews.show');
    Route::post('/apprentice/{apprentice}/reviews/schedule', [ProgressReviewController::class, 'schedule'])->name('reviews.schedule');
    Route::post('/reviews/{review}/conduct', [ProgressReviewController::class, 'conduct'])->name('reviews.conduct');
    Route::post('/reviews/{review}/sign', [ProgressReviewController::class, 'sign'])->name('reviews.sign');

    // Visual Apprentice Journey Roadmap
    Route::get('/journey', [ApprenticeJourneyController::class, 'show'])->name('journey.show');
    Route::get('/apprentice/{apprentice}/journey', [ApprenticeJourneyController::class, 'show'])->name('apprentice.journey');

    // Needs Attention Watchlist (Staff Only)
    Route::get('/attention', [AttentionController::class, 'index'])->name('attention.index')->middleware('role:admin,trainer,moderator,mentor');

    // Action Plan (Agreed SMART targets)
    Route::get('/action-plan', [ActionPlanController::class, 'index'])->name('action-plan.index');
    Route::post('/apprentice/{apprentice}/action-plan', [ActionPlanController::class, 'store'])->name('action-plan.store');
    Route::post('/action-plan/item/{item}/complete', [ActionPlanController::class, 'complete'])->name('action-plan.complete');

    // English & Maths Summary (Assessment & Topics)
    Route::get('/english-maths', [EnglishMathsController::class, 'index'])->name('english-maths.index');

    // Careers & Guidance (Strictly firewalled: Employers 403 barred)
    Route::get('/careers', [CareerController::class, 'index'])->name('careers.index');
    Route::get('/apprentice/{apprentice}/careers', [CareerController::class, 'index'])->name('apprentice.careers');
    Route::post('/apprentice/{apprentice}/careers', [CareerController::class, 'store'])->name('careers.store')->middleware('role:admin,trainer,moderator,mentor');

    // Staff Development & CPD
    Route::get('/staff-development', [StaffDevelopmentController::class, 'index'])->name('staff-development.index')->middleware('role:admin,trainer,moderator,mentor');
    Route::post('/staff-development/{user}/record', [StaffDevelopmentController::class, 'storeRecord'])->name('staff-development.record')->middleware('role:admin,moderator');
    Route::post('/staff-development/{user}/target', [StaffDevelopmentController::class, 'storeTarget'])->name('staff-development.target')->middleware('role:admin,moderator');

    // General Reporting Home & Report Directory
    Route::get('/reports', [ReportingController::class, 'index'])->name('reports.index')->middleware('role:admin,trainer,moderator,mentor');

    // Help & How-To Guides (Role-scoped)
    Route::get('/help', [HelpGuideController::class, 'index'])->name('help.index');

    // Marking SLA Queue (GTA Tutors & Quality Staff)
    Route::middleware('role:admin,trainer,moderator')->group(function () {
        Route::get('/marking', [TaskMarkingController::class, 'index'])->name('marking.index');
        Route::post('/marking/{task}/approve', [TaskMarkingController::class, 'approve'])->name('marking.approve');
        Route::post('/marking/{task}/refer', [TaskMarkingController::class, 'refer'])->name('marking.refer');
    });

    // Case Studies Bank (Staff Only)
    Route::middleware('role:admin,trainer,moderator,mentor')->group(function () {
        Route::get('/case-studies', [CaseStudyController::class, 'index'])->name('case-studies.index');
        Route::get('/case-studies/create', [CaseStudyController::class, 'create'])->name('case-studies.create');
        Route::post('/case-studies', [CaseStudyController::class, 'store'])->name('case-studies.store');
        Route::get('/case-studies/{caseStudy}', [CaseStudyController::class, 'show'])->name('case-studies.show');
        Route::post('/case-studies/{caseStudy}/approve', [CaseStudyController::class, 'approve'])->name('case-studies.approve');

        // Additional Learning Support (ALS) Plans
        Route::get('/support', [SupportPlanController::class, 'index'])->name('support.index');
        Route::get('/support/{supportPlan}', [SupportPlanController::class, 'show'])->name('support.show');
    });

    // Administrator Only Area
    Route::middleware('role:admin')->group(function () {
        // Admin Command Hub
        Route::get('/admin', [ApprenticeManagementController::class, 'hub'])->name('admin.index');

        // Apprentices list & Add apprentice (Programme & Staff Setup)
        Route::get('/admin/apprentices', [ApprenticeManagementController::class, 'index'])->name('admin.apprentices.index');
        Route::get('/admin/apprentices/create', [ApprenticeManagementController::class, 'create'])->name('admin.apprentices.create');
        Route::post('/admin/apprentices', [ApprenticeManagementController::class, 'store'])->name('admin.apprentices.store');

        // Group creation and tutor assignments
        Route::get('/admin/groups', [GroupManagementController::class, 'index'])->name('admin.groups.index');
        Route::post('/admin/groups', [GroupManagementController::class, 'store'])->name('admin.groups.store');
        Route::post('/admin/groups/{group}/assign', [GroupManagementController::class, 'assign'])->name('admin.groups.assign');
        Route::delete('/admin/groups/{group}/apprentices/{apprentice}', [GroupManagementController::class, 'remove'])->name('admin.groups.remove');

        // Inspection Overview & Printable Dossier (Admins / CEO Only)
        Route::get('/inspection-overview', [InspectionOverviewController::class, 'index'])->name('inspection.overview');
        Route::get('/inspection-overview/print', [InspectionOverviewController::class, 'printDossier'])->name('inspection.print');
    });
});
