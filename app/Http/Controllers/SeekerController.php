<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        $uid = $request->authUid;

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

        validator($request->all(), [
            'email'        => ['required', 'email', 'max:255'],
            'firstName'    => ['required', 'string', 'max:255'],
            'middleName'   => ['nullable', 'string', 'max:255'],
            'lastName'     => ['required', 'string', 'max:255'],
            'suffix'       => ['nullable', 'string', 'max:50'],
            'mobileNumber' => ['required', 'string', 'max:20'],
        ])->validate();

        $newElement = [
            'email' => $request->email,
            'firstName' => $request->firstName,
            'middleName' => $request->middleName ?? null,
            'lastName' => $request->lastName,
            'suffix' => $request->suffix ?? null,
            'mobileNumber' => $request->mobileNumber,
            'isOpenForWork' => true,
            'isVerified' => FALSE,
            'isProfileSet' => FALSE,
            'createdAt' => FieldValue::serverTimestamp(),
            'updatedAt' => FieldValue::serverTimestamp()
        ];

        $docRef = $this->database->collection('seekers')->document($uid);
        $docRef->set($newElement);

        Firebase::auth()->setCustomUserClaims($uid, ['role' => 'seeker']);

        $snap = $docRef->snapshot();

        return response()->json([
            'message' => 'Seeker registered successfully',
            'data'    => $this->formatDoc($snap->data()),
        ]);
    }

    public function setupProfile(Request $request)
    {
        // VALIDATIONS
        $uid = $request->authUid;

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
            'birthDate'    => ['required', 'date'],
            'address'      => ['required', 'string', 'max:255'],
            'location'     => ['required', 'string', Rule::in(config('manila.districts'))],
            'profilePhoto' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) {
                    if (!$value) return;
                    $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $value);
                    $decoded = base64_decode($base64, true);
                    if ($decoded === false || strlen($decoded) > 1048576) {
                        $fail('Profile photo must be a valid base64 image under 1MB.');
                    }
                },
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
        $clearanceUrl = FileUploader::upload($request->file('clearance'), 'clearances');
        $validIdUrl   = FileUploader::upload($request->file('validId'), 'validIds');

        // STORE PROFILE PHOTO IN SEPARATE COLLECTION (avoids 1MB doc limit)
        if ($request->filled('profilePhoto')) {
            $this->database->collection('profilePhotos')->document($uid)->set([
                'base64'    => $request->profilePhoto,
                'updatedAt' => FieldValue::serverTimestamp(),
            ]);
        }

        // STORE DATA
        $dateString = $request->birthDate;
        $immutableDate = Carbon::parse($dateString)->toDateTimeImmutable();
        $birthDateTimestamp = new Timestamp($immutableDate);

        $newElement = [
            ['path' => 'address', 'value' => $request->address],
            ['path' => 'birthDate', 'value' => $birthDateTimestamp],
            ['path' => 'location', 'value' => $request->location],
            ['path' => 'clearanceUrl', 'value' => $clearanceUrl],
            ['path' => 'validIdUrl', 'value' => $validIdUrl],
            ['path' => 'isProfileSet', 'value' => TRUE],
            ['path' => 'updatedAt', 'value' => FieldValue::serverTimestamp()]
        ];

        $seekerReference->update($newElement);

        $updated = $seekerReference->snapshot();

        return response()->json([
            'message' => 'Profile set up successfully.',
            'data'    => $this->formatDoc($updated->data()),
        ]);
    }
}