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
        $uid = $request->auth_uid;

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
        $user_uid = $request->user_uid;

        $isExisting = $this->database
            ->collection($collection)
            ->document($user_uid)
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
            ->document($user_uid)
            ->update([
                ['path' => 'is_verified', 'value' => TRUE],
                ['path' => 'updated_at', 'value' => FieldValue::serverTiemstamp()]
            ]);

        return response()->json([
            'message' => "User " . $user_uid . " verified."
        ]);
    }
}