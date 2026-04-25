<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Firestore;
use Symfony\Component\HttpFoundation\Response;

class FirestoreRetryMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Always start with a fresh Firestore client so we never reuse a stale
        // gRPC channel that WSL2's NAT silently dropped during idle periods.
        $this->flushFirestore();

        $attempts = 3;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                return $next($request);
            } catch (\Exception $e) {
                if ($i === $attempts - 1 || !str_contains($e->getMessage(), 'recvmsg')) {
                    throw $e;
                }
                $this->flushFirestore();
                usleep(200_000 * ($i + 1));
            }
        }
    }

    private function flushFirestore(): void
    {
        app()->forgetInstance(Firestore::class);
        app()->forgetInstance('firebase.firestore');
    }
}
