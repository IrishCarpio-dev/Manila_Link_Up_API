<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Laravel\Firebase\Facades\Firebase;

class EmployerController extends Controller
{
    protected $database;

    public function __construct()
    {
        $this->database = Firebase::firestore()->database();
    }

    public function signUp(Request $request)
    {
        $uid = $request->auth_uid;

        if (!$uid) {
            return response()->json(['error' => 'UID not found'], 400);
        }

        $existing = $this->database
            ->collection('employers')
            ->document($uid)
            ->snapshot()
            ->exists();

        // TODO: - Validate fields

        if ($existing) {
            return response()->json([
                'error' => 'User already exists'
            ]);
        }

        $newEmployer = [
            'address' => $request->address,
            'birth_date' => $request->birth_date,
            'email' => $request->email,
            'full_name' => $request->full_name,
            'location' => $request->location,
            'mobile_number' => $request->mobile_number,
            'status' => 0,
            'is_verified' => 0,
            'created_at' => Database::SERVER_TIMESTAMP,
            'updated_at' => Database::SERVER_TIMESTAMP
        ];

        // $this->database
        //     ->getReference('employers/'.$uid)
        //     ->set($newEmployer);

        $this->database
            ->collection('employers')
            ->document($uid)
            ->set(newEmployer);

        return response()->json([
            'message' => 'Employer registered successfully',
            'data' => json_encode($newEmployer)
        ]);
    }

    public function updateMedia(Request $request)
    {
        $result = UserMediaUploader::updateMedia($request, 'employer', $uid);

        return response()->$result;
    }
}