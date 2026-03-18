<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Database;
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

// Route::middleware(['firebase.auth'])->group(function () {
//     Route::get('/dashboard', function () {
//         $type = $request->firebase_user_type;
//         $data = $request->firebase_data;

//         return response()->json([
//             'user_type' => $type,
//             'dashboard' => match ($type) {
//                 'admins' => 'Admin Dashboard',
//                 'users' => 'User Dashboard',
//                 'enterprises' => 'Enterprise Dashboard',
//                 default => 'Unknown Dashboard'
//             },
//             'user' => $data
//         ]);
//     });

//     Route::post('/signup/seeker', [SeekerController::class, 'signup']);
// });


    Route::post('/signup/seeker', [SeekerController::class, 'signup']);