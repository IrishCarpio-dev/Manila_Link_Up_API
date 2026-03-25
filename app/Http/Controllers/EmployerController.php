<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Database;

class EmployerController extends Controller
{
    protected $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function signUp(Request $request)
    {
        $uid = $request->firebase_uid;

        if (!$uid) {
            return response()->json(['error' => 'UID not found'], 400);
        }

        $existing = $this->database
            ->getReference('employers/'.$uid)
            ->getValue();

        // TODO: - Validate fields

        if ($existing) {
            return response()->json([
                'error' => 'User already exists'
            ]);
        }

        $newEmployer = [
            'employer_address' => $request->employer_address,
            'employer_birthdate' => $request->employer_birthdate,
            'employer_email_address' => $request->employer_email_address,
            'employer_fullname' => $request->employer_fullname,
            'employer_location' => $request->employer_location,
            'employer_mobile_number' => $request->employer_mobile_number,
            'employer_status' => 0,
            'employer_valid_id' => 0,
            'employer_verified' => 0,
            'created_at' => Database::SERVER_TIMESTAMP,
            'updated_at' => Database::SERVER_TIMESTAMP
        ];

        $this->database
            ->getReference('employers/'.$uid)
            ->set($newEmployer);

        return response()->json([
            'message' => 'Employer registered successfully',
            'data' => json_encode($newEmployer)
        ]);
    }
}