<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Database;

use App\Http\Middleware\FirebaseAuthMiddleware;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SeekerController;
use App\Http\Controllers\EmployerController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\ServiceTagController;
use App\Http\Controllers\SeekerPreferencesController;

// This is your test route
Route::get('/test-firebase', function (Database $database) {
    $database->getReference('test_connection')->set([
        'status' => 'Success!',
        'timestamp' => now()->toDateTimeString()
    ]);

    return response()->json(['message' => 'Connected to Firebase! Check your console.']);
});

Route::post('/register-seeker', [AuthController::class, 'registerSeeker']);

Route::middleware([FirebaseAuthMiddleware::class])->group(function () {
    // User
    Route::get('/user/profile', [UserController::class, 'profile']);

    // Seeker
    Route::post('/seeker/signup', [SeekerController::class, 'signUp']);
    Route::post('/seeker/setupProfile', [SeekerController::class, 'setupProfile']);
    Route::post('/seeker/preferences', [SeekerPreferencesController::class, 'upsert']);

    // Employer
    Route::post('/employer/signup', [EmployerController::class, 'signUp']);
    Route::post('/employer/setupProfile', [EmployerController::class, 'setupProfile']);

    // Admin
    Route::get('/admin/create', [AdminController::class, 'create']);

    // Jobs
    Route::post('/jobs', [JobController::class, 'store']);
    Route::post('/jobs/list', [JobController::class, 'index']);
    Route::post('/jobs/archive', [JobController::class, 'destroy']);
    Route::post('/jobs/apply', [ApplicationController::class, 'apply']);
    Route::post('/jobs/withdraw', [ApplicationController::class, 'withdraw']);
    Route::post('/jobs/applicants', [ApplicationController::class, 'jobApplicants']);

    // Applications
    Route::post('/seeker/appliedJobs', [ApplicationController::class, 'seekerApplications']);
    Route::post('/applications/updateStatus', [ApplicationController::class, 'updateStatus']);

    // Service Tags
    Route::get('/service-tags', [ServiceTagController::class, 'index']);
});