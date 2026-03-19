<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Database;

class SeekerController extends Controller
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
            ->getReference('seekers/'.$uid)
            ->getValue();

        if ($existing) {
            return response()->json([
                'error' => 'User already exists'
            ]);
        }

        // TODO: - Validate fields
        // $request->validate([
        //     'seeker_' => 'required|string|max:255'
        // ]);

        $newSeeker = [
            'seeker_address' => $request->seeker_address,
            'seeker_birthdate' => $request->seeker_birthdate,
            'seeker_email_address' => $request->seeker_email_address,
            'seeker_firstname' => $request->seeker_firstname,
            'seeker_lastname' => $request->seeker_lastname,
            'seeker_location' => $request->seeker_location,
            'seeker_mobile_number' => $request->seeker_mobile_number,
            'seeker_salary' => $request->seeker_salary,
            'seeker_status' => 0,
            'seeker_verified' => 0,
            'created_at' => Database::SERVER_TIMESTAMP,
            'updated_at' => Database::SERVER_TIMESTAMP
        ];

        $this->database
            ->getReference('seekers/'.$uid)
            ->set($newSeeker);

        return response()->json([
            'message' => 'Seeker registered successfully',
            'data' => json_encode($newSeeker)
        ]);
    }
}