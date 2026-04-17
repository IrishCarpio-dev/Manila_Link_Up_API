<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Cloud\Firestore\FieldValue;
use Kreait\Laravel\Firebase\Facades\Firebase;

class DeviceController extends Controller
{
    protected $database;

    public function __construct()
    {
        $this->database = Firebase::firestore()->database();
    }

    public function register(Request $request)
    {
        $uid = $request->authUid;

        validator($request->all(), [
            'fcmToken' => ['required', 'string'],
            'platform' => ['required', 'string', 'in:android,ios'],
        ])->validate();

        $docId = $uid . '_' . md5($request->fcmToken);

        $this->database->collection('devices')->document($docId)->set([
            'uid'       => $uid,
            'fcmToken'  => $request->fcmToken,
            'platform'  => $request->platform,
            'updatedAt' => FieldValue::serverTimestamp(),
        ]);

        return response()->json(['message' => 'Device registered successfully'], 200);
    }

    public function unregister(Request $request)
    {
        $uid = $request->authUid;

        validator($request->all(), [
            'fcmToken' => ['required', 'string'],
        ])->validate();

        $docId = $uid . '_' . md5($request->fcmToken);

        $this->database->collection('devices')->document($docId)->delete();

        return response()->json(['message' => 'Device unregistered successfully'], 200);
    }
}
