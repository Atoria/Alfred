<?php

namespace Atoria\Alfred\Support;

use Atoria\Alfred\Alfred;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-person limits, and for signed-out visitors also per IP (a fresh
 * session must not mean a fresh budget). Returns a 429 response when over.
 */
class Limits
{
    public static function hit(Request $request, string $name, int $perMinute, int $guestPerIpPerMinute): ?JsonResponse
    {
        $alfred = app(Alfred::class);
        $userKey = $alfred->userKey($request);
        $keys = ['alfred:'.$name.':'.$userKey => max(1, $perMinute)];
        if (! str_starts_with($userKey, 'u:')) {
            $keys['alfred:'.$name.':ip:'.sha1(app(\Atoria\Alfred\Tenancy\Tenancy::class)->current().'|'.(string) $request->ip())] = max(1, $guestPerIpPerMinute);
        }

        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return response()->json(['message' => 'Too many requests. Try again in a minute.'], 429)
                    ->header('Retry-After', (string) RateLimiter::availableIn($key));
            }
        }
        foreach (array_keys($keys) as $key) {
            RateLimiter::hit($key, 60);
        }

        return null;
    }
}
