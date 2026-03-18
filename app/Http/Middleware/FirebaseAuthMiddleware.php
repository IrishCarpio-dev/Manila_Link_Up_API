<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kreait\Firebase\Auth;
use Kreait\Firebase\Database;

class FirebaseAuthMiddleware
{
    protected $auth;
    protected $database;

    public function __construct(Auth $auth, Database $database)
    {
        $this->auth = $auth;
        $this->database = $database;
    }

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $verifiedToken = $this->auth->verifyIdToken($token);
            $uid = $verifiedToken->claims()->get('email');

            if (!$email) {
                return response()->json(['error' => 'No email in token'], 400);
            }

            $userTypes = ['admins', 'seekers', 'employers'];
            $userData = null;
            $userType = null;

            foreach ($userTypes as $type) {
                $data = $this->database->getReference($type.'/'.$email)->getValue();
                if ($data) {
                    $userData = $data;
                    $userType = $type;
                    break;
                }
            }

            if (!$userData) {
                return response()->json(['error' => 'User not found'], 404);
            }

            // Map type-specific fields into consistent keys
            $mappedData = match ($userType) {
                'admins' => [
                    'name' => $userData['admin_name'] ?? null,
                    'email' => $userData['admin_email_address'] ?? null
                ],
                'seekers' => [
                    'name' => $userData['seeker_name'] ?? null,
                    'email' => $userData['seeker_email_address'] ?? null
                ],
                'employers' => [
                    'name' => $userData['employer_company_name'] ?? null,
                    'email' => $userData['employer_email_address'] ?? null
                ],
                default => $userData
            };

            $request->merge([
                'firebase_user_type' => $userType,
                'firebase_data' => $mappedData
            ]);

            // // ✅ Get user from Realtime DB
            // $user = $this->database
            //     ->getReference('users/' . $uid)
            //     ->getValue();

            // // Optional: if user record doesn't exist yet
            // if (!$user) {
            //     $this->database
            //         ->getReference('users/' . $uid)
            //         ->set([
            //             'email' => $verifiedToken->claims()->get('email'),
            //         ]);

            //     $user = [
            //         'email' => $verifiedToken->claims()->get('email'),
            //     ];
            // }

            // // Attach to request
            // $request->merge([
            //     'firebase_uid' => $uid,
            //     'firebase_email' => $user['email'] ?? null,
            // ]);

        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Invalid token',
            ], 401);
        }

        return $next($request);
    }
}