<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Auth;
use Symfony\Component\HttpFoundation\Response;

class FirebaseAuthMiddleware
{
    protected Auth $auth;

    public function __construct(Auth $auth)
    {
        $this->auth = $auth;
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'No token provided'], 401);
        }

        try {
            $verifiedIdToken = $this->auth->verifyIdToken($token, false, 60);
            $uid = $verifiedIdToken->claims()->get('sub');
            $request->merge(['authUid' => $uid]);
        } catch (\Throwable $e) {
            \Log::error('FirebaseAuthMiddleware: ' . $e->getMessage(), [
                'exception' => get_class($e),
            ]);
            return response()->json(['error' => 'Invalid token'], 401);
        }

        return $next($request);
    }
}