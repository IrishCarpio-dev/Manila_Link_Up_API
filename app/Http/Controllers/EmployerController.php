<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Core\Timestamp;
use Carbon\Carbon;
use Kreait\Laravel\Firebase\Facades\Firebase;
use App\Services\FileUploader;

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

        if ($existing) {
            return response()->json([
                'error' => 'User already exists'
            ]);
        }

        validator($request->all(), [
            'email'        => ['required', 'email', 'max:255'],
            'fullName'     => ['required', 'string', 'max:255'],
            'mobileNumber' => ['required', 'string', 'max:20'],
        ])->validate();

        $newEmployer = [
            'email' => $request->email,
            'fullName' => $request->fullName,
            'mobileNumber' => $request->mobileNumber,
            'isProfileSet' => FALSE,
            'isVerified' => FALSE,
            'createdAt' => FieldValue::serverTimestamp(),
            'updatedAt' => FieldValue::serverTimestamp()
        ];

        $this->database
            ->collection('employers')
            ->document($uid)
            ->set($newEmployer);

        return response()->json([
            'message' => 'Employer registered successfully',
            'data' => json_encode($newEmployer)
        ]);
    }

    public function setupProfile(Request $request)
    {
        // VALIDATIONS
        $uid = $request->authUid;

        $reference = $this->database
            ->collection('employers')
            ->document($uid);
        $snapshot = $reference->snapshot();

        if (!$snapshot->exists()) {
            return response()->json([
                'error' => 'User not found.',
                404
            ]);
        }

        validator($request->all(), [
            'birthDate'    => ['required', 'date'],
            'location'     => ['required', 'string', 'max:255'],
            'profilePhoto' => [
                'file',
                'image',
                'mimes:jpeg,png,jpg',
                'max:2048'
            ],
            'validId' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,png,jpg',
                'max:5120'
            ],
            'clearance' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,png,jpg',
                'max:5120'
            ]
        ])->validate();

        // SAVE FILES
        $profilePhotoUrl = "";
        if ($request->hasFile('profilePhoto')) {
            $profilePhotoUrl = FileUploader::upload($request->file('profilePhoto'), 'profilePhotos');
        }

        $clearanceUrl = FileUploader::upload($request->file('clearance'), 'clearances');
        $validIdUrl = FileUploader::upload($request->file('validId'), 'validIds');

        // STORE DATA
        $dateString = $request->birthDate;
        $immutableDate = Carbon::parse($dateString)->toDateTimeImmutable();
        $birthDateTimestamp = new Timestamp($immutableDate);

        $newElement = [
            ['path' => 'location', 'value' => $request->location],
            ['path' => 'birthDate', 'value' => $birthDateTimestamp],
            ['path' => 'clearanceUrl', 'value' => $clearanceUrl],
            ['path' => 'validIdUrl', 'value' => $validIdUrl],
            ['path' => 'isProfileSet', 'value' => TRUE],
            ['path' => 'updatedAt', 'value' => FieldValue::serverTimestamp()]
        ];

        if (!empty($profilePhotoUrl)) {
            $newElement[] = ['path' => 'profilePhotoUrl', 'value' => $profilePhotoUrl];
        }

        $reference->update($newElement);

        return response()->json([
            'message' => 'Profile set up successfully.',
            'data' => json_encode($snapshot->data())
        ]);
    }
}