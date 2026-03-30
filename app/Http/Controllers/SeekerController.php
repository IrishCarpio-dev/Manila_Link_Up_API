<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Core\Timestamp;
use Carbon\Carbon;
use Kreait\Laravel\Firebase\Facades\Firebase;
use App\Services\FileUploader;


class SeekerController extends Controller
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
            ->collection('seekers')
            ->document($uid)
            ->snapshot()
            ->exists();

        if ($existing) {
            return response()->json([
                'error' => 'User already exists'
            ]);
        }

        // TODO: - Validate fields
        // $request->validate([
        //     'seeker_' => 'required|string|max:255'
        // ]);

        $dateString = $request->birth_date;

        $immutableDate = Carbon::parse($dateString)->toDateTimeImmutable();
        $firestoreTimestamp = new Timestamp($immutableDate);

        $newElement = [
            'email' => $request->seeker_email_address,
            'first_name' => $request->seeker_firstname,
            'last_name' => $request->seeker_lastname,
            'mobile_number' => $request->seeker_mobile_number,
            'is_open_for_work' => FALSE,
            'is_verified' => FALSE,
            'is_profile_set' => FALSE,
            'created_at' => FieldValue::serverTimestamp(),
            'updated_at' => FieldValue::serverTimestamp()
        ];

        $this->database
            ->collection('seekers')
            ->document($uid)
            ->set($newElement);

        return response()->json([
            'message' => 'Seeker registered successfully',
            'data' => json_encode($newElement)
        ]);
    }

    public function setupProfile(Request $request)
    {
        // VALIDATIONS
        $uid = $request->auth_uid;

        $seekerReference = $this->database
            ->collection('seekers')
            ->document($uid);
        $seekerSnapshot = $seekerReference->snapshot();

        if (!$seekerSnapshot->exists()) {
            return response()->json([
                'error' => 'User not found.',
                404
            ]);
        }

        validator($request->all(), [
            'profile_photo' => [
                'file', 
                'image|mimes:jpeg,png,jpg', 
                'max:2048'
            ],
            'valid_id' => [
                'required',
                'file',
                'image|mimes:jpeg,png,jpg',
                'max:5120'
            ],
            'clearance' => [
                'required',
                'file',
                'image|mimes:jpeg,png,jpg',
                'max:5120'
            ]
        ])->validate(); 

        // SAVE FILES
        $profile_photo_url = "";
        if ($request->hasFile('profile_photo')) {
            $profile_photo_url = FileUploader::upload($request->file('profile_photo'), 'profiles');
        }

        $clearance_url = FileUploader::upload($request->file('clearance'), 'clearances');
        $valid_id_url = FileUploader::upload($request->file('valid_id'), 'valid_ids');

        // STORE DATA
        $dateString = $request->birth_date;
        $immutableDate = Carbon::parse($dateString)->toDateTimeImmutable();
        $birthDateTimestamp = new Timestamp($immutableDate);

        $newElement = [
            ['path' => 'address', 'value' => $request->address],
            ['path' => 'birth_date', 'value' => $birthDateTimestamp],
            ['path' => 'location', 'value' => $request->location],
            ['path' => 'clearance_url', 'value' => $clearance_url],
            ['path' => 'valid_id_url', 'value' => $valid_id_url],
            ['path' => 'is_profile_set', 'value' => TRUE],
            ['path' => 'updated_at', 'value' => FieldValue::serverTimestamp()]
        ];

        if (!empty($profile_photo_url)) {
            $newElement[] = ['path' => 'profile_photo_url', 'value' => $profile_photo_url];
        }

        $seekerReference->update($newElement);

        return response()->json([
            'message' => 'Profile set up successfully.',
            'data' => json_encode($seekerSnapshot->data())
        ]);
    }
}