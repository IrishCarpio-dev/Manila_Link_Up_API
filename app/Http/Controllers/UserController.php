<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Database;

class UserController extends Controller
{
    protected $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function profile(Request $request)
    {
        $uid = $request->uid;

        if (!$uid) {
            return response()->json(['error' => 'UID not found'], 400);
        }

        $userTypes = ['admins', 'employers', 'seekers'];
        $profiles = [];

        foreach ($userTypes as $type)
            {
                $data = $this->database->getReference($type.'/'.$uid)->getValue();

                if ($data) {
                    $profiles[$type] = $data;
                }
            }

        if (!empty($profiles)) {
            return response()->json(json_encode($profiles));
        }

        return response()->json(['error' => 'User not found'], 404);
    }

}