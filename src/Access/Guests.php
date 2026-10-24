<?php

namespace Atoria\Alfred\Access;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Alfred for visitors who are not signed in (login pages, public pages, guest
 * checkout…). Off by default, and limited to non-production environments
 * unless you explicitly list production.
 *
 * A browser is let in by opening the private access link once
 * (/_alfred/access?key=…), which leaves a signed cookie. On local no key is
 * needed. Guests can ask "How is this calculated?" and "Report or request",
 * never edit, see code or drill into records.
 */
class Guests
{
    public const COOKIE = 'alfred_access';

    public function enabled(): bool
    {
        return (bool) config('alfred.guests.enabled', false);
    }

    /** Is guest access allowed in this environment at all? */
    public function environmentAllowed(): bool
    {
        $environments = array_filter(array_map('trim', (array) config('alfred.guests.environments', [])));

        if (in_array('*', $environments, true) || app()->environment($environments)) {
            if (app()->isProduction()) {
                $this->warnProduction();
            }

            return true;
        }

        return false;
    }

    public function key(): string
    {
        return trim((string) config('alfred.guests.access_key', ''));
    }

    /** No key needed: switched on, on a local machine, with no key set. */
    public function open(): bool
    {
        return $this->key() === '' && app()->isLocal();
    }

    /** May this (signed-out) request use Alfred? */
    public function allowed(?Request $request): bool
    {
        if (! $this->enabled() || ! $this->environmentAllowed()) {
            return false;
        }
        if ($this->open()) {
            return true;
        }
        if ($this->key() === '' || ! $request) {
            return false;
        }

        $cookie = $request->cookies->get(self::COOKIE);

        return is_string($cookie) && hash_equals($this->cookieValue(), $cookie);
    }

    public function check(string $key): bool
    {
        return $this->key() !== '' && hash_equals($this->key(), $key);
    }

    /** Changing ALFRED_ACCESS_KEY (or APP_KEY) signs every browser out. */
    public function cookieValue(): string
    {
        return hash_hmac('sha256', 'alfred-access|'.$this->key(), (string) config('app.key'));
    }

    public function cookie(Request $request): Cookie
    {
        $days = max(1, (int) config('alfred.guests.remember_days', 30));

        return new Cookie(self::COOKIE, $this->cookieValue(), now()->addDays($days), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }

    public function forgetCookie(Request $request): Cookie
    {
        return new Cookie(self::COOKIE, '', 1, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }

    /** At most once an hour, so it shows in logs without flooding them. */
    protected function warnProduction(): void
    {
        try {
            if (Cache::store()->add('alfred:guests:production-warning', 1, 3600)) {
                Log::warning('Alfred: guest access is switched on in production. Anyone with the access link can spend AI credits and read explanations of your code. Remove "production" from ALFRED_GUESTS_ENVIRONMENTS unless you really mean it.');
            }
        } catch (\Throwable) {
            // logging must never break the page
        }
    }
}
