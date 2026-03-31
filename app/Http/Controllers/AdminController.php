<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Cloud\Firestore\FieldValue;
use Kreait\Laravel\Firebase\Facades\Firebase;

class AdminController extends Controller
{
    protected $database;

    public function __construct()
    {
        $this->database = Firebase::firestore()->database();
    }

    public function verifyUser(Request $request)
    {
        $uid = $request->authUid;

        if (!$uid) {
            return response()->json(['error' => 'UID not found'], 400);
        }

        $isAdmin = $this->database
            ->collection('admins')
            ->document($uid)
            ->snapshot()
            ->exists();

        if (!$isAdmin) {
            return response()->json([
                'error' => 'Unauthorized access',
                401
            ]);
        }

        $request->validate([
            'type'  => 'required|in:seeker,employer'
        ]);

        $isSeeker = $request->type === 'seeker';
        $collection = ($isSeeker) ? 'seekers' : 'employers';
        $userUid = $request->userUid;

        $isExisting = $this->database
            ->collection($collection)
            ->document($userUid)
            ->snapshot()
            ->exists();

        if (!isExisting) {
            return response()->json([
                'error' => 'User not found',
                404
            ]);
        }

        $this->database
            ->collection($collection)
            ->document($userUid)
            ->update([
                ['path' => 'isVerified', 'value' => TRUE],
                ['path' => 'updatedAt', 'value' => FieldValue::serverTiemstamp()]
            ]);

        return response()->json([
            'message' => "User " . $userUid . " verified."
        ]);
    }
}