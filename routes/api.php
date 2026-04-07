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

    // Employer
    Route::post('/employer/signup', [EmployerController::class, 'signUp']);
    Route::post('/employer/setupProfile', [EmployerController::class, 'setupProfile']);

    // Admin
    Route::get('/admin/create', [AdminController::class, 'create']);

    // Jobs
    Route::post('/jobs', [JobController::class, 'store']);
    Route::get('/jobs', [JobController::class, 'index']);
    Route::delete('/jobs/{id}', [JobController::class, 'destroy']);
});