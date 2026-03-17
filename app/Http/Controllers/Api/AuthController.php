<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
// IMPORT
use Kreait\Firebase\Contract\Database;

class AuthController extends Controller
{
    // DEFINE THE PROPERTY
    protected $database;

    //ADD THE CONSTRUCTOR TO LINK FIREBASE
    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function registerSeeker(Request $request)
    {
        // logic remains the same, but now $this->database is actually defined!
        $data = [
           'seeker_firstname'       => $request->firstname,
        'seeker_lastname'        => $request->lastname,
        'seeker_email_address'   => $request->email,
        'seeker_address'         => $request->address,
        'seeker_birthdate'       => $request->birthdate,
        'seeker_location'        => $request->location,
        'seeker_phone_number'    => $request->phone,
        'seeker_salary'          => $request->salary,
        'seeker_profile_picture' => "default_profile.png",
        'seeker_clearance'       => "default_clearance.png",
        'seeker_status'          => 1,
        'seeker_verified'        => 1
        ];

        $uid = $request->uid; 
        
        // line only works if the Constructor above exists
        $this->database->getReference('seekers/' . $uid)->set($data);

        return response()->json(['message' => 'Seeker profile saved to Firebase!'], 201);
    }


        public function getProfile($uid)
    {
        $seeker = $this->database->getReference('seekers/' . $uid)->getValue();

        if ($seeker) {
            return response()->json($seeker);
        }

        return response()->json(['error' => 'User not found'], 404);
    }
}