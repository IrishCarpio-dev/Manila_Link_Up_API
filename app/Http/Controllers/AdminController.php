<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Database;

class AdminController extends Controller
{
    protected $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function create(Reqeust $request)
    {
        // TODO: - Validate fields

        // Validate if current user is an admin
        $currentUid = $request->firebase_uid;

        if (!$currentUid) {
            return response()->json(['error' => 'UID not found', 400]);
        }

        $isCurrentUserAdmin = $this->database
            ->getReference('admins/'.$currentUid)
            ->getValue();

        if (!$isCurrentUserAdmin) {
            return response()->json(['error'=>'Unauthorized Access', 401]);
        }

        // Validate if new admin is already an admin

        $uid = $requeust->admin_uid;

        if (!$adminUid) {
            return response()->json(['error' => 'Admin UID not found', 400]);
        }

        $existing = $this->database
            ->getReference('admins/'.$adminUid)
            ->getValue();
        
        if ($existing) {
            return response()->json(['error' => 'Admin already exists', 400]);
        }
        
        $newElement = [
            'admin_fullname' => $request->admin_fullname,
            'admin_email_address' => $request->admin_email_address
        ];

        $this->database
            ->getReference('admins/'.$uid)
            ->set($newElement);

        return response()->json([
            'message' => 'Admin created successfully',
            'data' => json_encode($newElement)
        ]);
    }
}