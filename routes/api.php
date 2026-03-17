<?php

use Illuminate\Support\Facades\Route;
use Kreait\Firebase\Contract\Database;
use App\Http\Controllers\Api\AuthController;

// This is your test route
Route::get('/test-firebase', function (Database $database) {
    $database->getReference('test_connection')->set([
        'status' => 'Success!',
        'timestamp' => now()->toDateTimeString()
    ]);

    return response()->json(['message' => 'Connected to Firebase! Check your console.']);
});

Route::post('/register-seeker', [AuthController::class, 'registerSeeker']);