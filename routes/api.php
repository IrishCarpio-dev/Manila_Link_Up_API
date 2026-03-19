<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Database;

use App\Http\Middleware\FirebaseAuthMiddleware;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SeekerController;
use App\Http\Controllers\EmployerController;

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

    // Employer
    Route::post('/employer/signup', [EmployerController::class, 'signUp']);
});