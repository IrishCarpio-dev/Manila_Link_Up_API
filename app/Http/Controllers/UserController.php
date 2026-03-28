<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Laravel\Firebase\Facades\Firebase;

class UserController extends Controller
{
    protected $firestore;

    public function __construct()
    {
        $this->firestore = Firebase::firestore()->database();
    }

    public function profile(Request $request)
    {
        $uid = $request->auth_uid;

        if (!$uid) {
            return response()->json(['error' => 'UID not found'], 400);
        }

        $userTypes = ['admins', 'employers', 'seekers'];
        $profiles = [];

        foreach ($userTypes as $type)
            {
                $data = $this->firestore
                    ->collection($type)
                    ->document($uid)
                    ->snapshot()
                    ->data();

                if ($data) {
                    $profiles[$type] = $data;
                }
            }

        if (!empty($profiles)) {
            return response()->json($profiles);
        }

        return response()->json(['error' => 'User not found'], 404);
    }

}