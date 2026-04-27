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
use App\Http\Controllers\ChatController;
use App\Http\Controllers\RatingController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\ServiceTagController;
use App\Http\Controllers\SeekerPreferencesController;

Route::get('/test-firebase', function (Database $database) {
    $database->getReference('test_connection')->set([
        'status'    => 'Success!',
        'timestamp' => now()->toDateTimeString()
    ]);

    return response()->json(['message' => 'Connected to Firebase! Check your console.']);
});

Route::middleware([FirebaseAuthMiddleware::class])->group(function () {
    // User
    Route::get('/user/profile', [UserController::class, 'profile']);

    // Seeker
    Route::post('/seeker/signup',       [SeekerController::class, 'signUp']);
    Route::post('/seeker/setupProfile', [SeekerController::class, 'setupProfile']);
    Route::post('/seeker/preferences', [SeekerPreferencesController::class, 'upsert']);

    // Employer
    Route::post('/employer/signup',       [EmployerController::class, 'signUp']);
    Route::post('/employer/setupProfile', [EmployerController::class, 'setupProfile']);

    // Jobs
    Route::post('/jobs',                  [JobController::class, 'store']);
    Route::post('/jobs/list',             [JobController::class, 'index']);
    Route::post('/jobs/archive',          [JobController::class, 'destroy']);
    Route::post('/seeker/jobs',           [JobController::class, 'seekerJobs']);
    Route::post('/employer/archivedJobs', [JobController::class, 'employerArchivedJobs']);

    // Applications
    Route::post('/jobs/apply',              [ApplicationController::class, 'apply']);
    Route::post('/jobs/withdraw',           [ApplicationController::class, 'withdraw']);
    Route::post('/jobs/applicants',         [ApplicationController::class, 'jobApplicants']);
    Route::post('/seeker/appliedJobs',      [ApplicationController::class, 'seekerApplications']);
    Route::post('/seeker/completedJobs',   [ApplicationController::class, 'seekerCompletedJobs']);
    Route::post('/applications/updateStatus',  [ApplicationController::class, 'updateStatus']);
    Route::post('/applications/markComplete',  [ApplicationController::class, 'markComplete']);

    // Chat
    Route::post('/chats/list',   [ChatController::class, 'list']);
    Route::post('/chats/notify', [ChatController::class, 'notify']);
    Route::post('/chats/hide',   [ChatController::class, 'hide']);

    // Ratings
    Route::post('/ratings',      [RatingController::class, 'submit']);
    Route::post('/ratings/list', [RatingController::class, 'list']);

    // Devices (FCM token management)
    Route::post('/devices/register',   [DeviceController::class, 'register']);
    Route::post('/devices/unregister', [DeviceController::class, 'unregister']);

    // Admin
    Route::post('/admin/verifyUser',            [AdminController::class, 'verifyUser']);
    Route::get('/admin/analytics/overview',     [AdminController::class, 'analyticsOverview']);
    Route::get('/admin/analytics/tags',         [AdminController::class, 'analyticsTags']);
    Route::get('/admin/analytics/funnel',       [AdminController::class, 'analyticsFunnel']);
    Route::get('/admin/analytics/timeseries',   [AdminController::class, 'analyticsTimeseries']);
    Route::get('/admin/analytics/users',        [AdminController::class, 'analyticsUsers']);
    Route::get('/admin/analytics/ratings',      [AdminController::class, 'analyticsRatings']);
    
    // Service Tags
    Route::get('/service-tags', [ServiceTagController::class, 'index']);
});
