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

    public function signup(Request $request)
    {
        $uid = $request->uid;//$request->firebase_email;

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

        // TODO validate fields
        // $request->validate([
        //     'seeker_' => 'required|string|max:255'
        // ]);

        // Store in Firebase Realtime DB
        $this->database
            ->getReference('seekers/'.$uid)
            ->set([
                'seeker_address' => $request->seeker_address,
                'seeker_birthdate' => $request->seeker_birthdate,
                'seeker_email_address' => $request->seeker_email_address,
                'seeker_firstname' => $request->seeker_firstname,
                'seeker_lastname' => $request->seeker_lastname,
                'seeker_location' => $request->seeker_location,
                'seeker_phone_number' => $request->seeker_phone_number,
                'seeker_salary' => $request->seeker_salary,
                'seeker_status' => 0,
                'seeker_verified' => 0,
                'created_at' => Database::SERVER_TIMESTAMP,
                'updated_at' => Database::SERVER_TIMESTAMP
            ]);

        return response()->json([
            'message' => 'Seeker registered successfully',
            'data' => [
                'seeker_address' => $request->seeker_address,
                'seeker_birthdate' => $request->seeker_birthdate,
                'seeker_email_address' => $request->seeker_email_address,
                'seeker_firstname' => $request->seeker_firstname,
                'seeker_lastname' => $request->seeker_lastname,
                'seeker_location' => $request->seeker_location,
                'seeker_phone_number' => $request->seeker_phone_number,
                'seeker_salary' => $request->seeker_salary,
                'seeker_status' => 0,
                'seeker_verified' => 0
            ]
        ]);
    }

    public function getProfile(Request $request)
    {
        $uid = $request->uid;

        if (!$uid) {
            return response()->json(['error' => 'UID not found'], 400);
        }

        $seeker = $this->database->getReference('seekers/' . $request->uid)->getValue();

        if ($seeker) {
            return response()->json($seeker);
        }

        return response()->json(['error' => 'User not found'], 404);
    }
}