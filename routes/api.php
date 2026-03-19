<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Database;

use App\Http\Middleware\FirebaseAuthMiddleware;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\SeekerController;

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
    Route::post('/seeker/signup', [SeekerController::class, 'signup']);
    Route::get('/seeker/profile', [SeekerController::class, 'getProfile']);
});