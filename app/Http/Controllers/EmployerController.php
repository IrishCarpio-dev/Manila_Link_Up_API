<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Core\Timestamp;
use Carbon\Carbon;
use Kreait\Laravel\Firebase\Facades\Firebase;
use App\Services\FileUploader;
use App\Services\NotificationService;

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

        $docRef = $this->database->collection('employers')->document($uid);
        $docRef->set($newEmployer);

        Firebase::auth()->setCustomUserClaims($uid, ['role' => 'employer']);

        $snap = $docRef->snapshot();

        return response()->json([
            'message' => 'Employer registered successfully',
            'data'    => $this->formatDoc($snap->data()),
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
            ['path' => 'location', 'value' => $request->location],
            ['path' => 'birthDate', 'value' => $birthDateTimestamp],
            ['path' => 'clearanceUrl', 'value' => $clearanceUrl],
            ['path' => 'validIdUrl', 'value' => $validIdUrl],
            ['path' => 'isProfileSet', 'value' => TRUE],
            ['path' => 'rejectedAt',  'value' => null],
            ['path' => 'updatedAt',   'value' => FieldValue::serverTimestamp()]
        ];

        $reference->update($newElement);

        $firstName  = $snapshot->data()['firstName'] ?? '';
        $lastName   = $snapshot->data()['lastName'] ?? '';
        $adminDocs  = $this->database->collection('admins')->documents();
        foreach ($adminDocs as $adminDoc) {
            if (!$adminDoc->exists()) continue;
            NotificationService::notify(
                $adminDoc->id(),
                'verification_submitted',
                'New Verification Request',
                "{$firstName} {$lastName} submitted documents for review.",
                ['userUid' => $uid, 'userType' => 'employer']
            );
        }

        $updated = $reference->snapshot();

        return response()->json([
            'message' => 'Profile set up successfully.',
            'data'    => $this->formatDoc($updated->data()),
        ]);
    }
}