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
        $uid = $request->authUid;

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
            'birthDate' => $request->birthDate,
            'email' => $request->email,
            'fullName' => $request->fullName,
            'location' => $request->location,
            'mobileNumber' => $request->mobileNumber,
            'status' => 0,
            'isVerified' => 0,
            'createdAt' => Database::SERVER_TIMESTAMP,
            'updatedAt' => Database::SERVER_TIMESTAMP
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