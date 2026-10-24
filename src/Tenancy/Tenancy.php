<?php

namespace Atoria\Alfred\Tenancy;

use Illuminate\Http\Request;
use Throwable;

/**
 * Multi-tenancy, done once for all of Alfred:
 *
 *  - which adapter to use (auto-detected, or ALFRED_TENANCY / a custom class);
 *  - a tamper-proof reference to the page's tenant that the overlay sends back
 *    with every Alfred request, so those requests run in the same tenant as
 *    the page. It is signed with the app key, bound to the host and expires,
 *    so it cannot be edited into another tenant or replayed on another domain.
 *    Access itself is still decided by the tenant's own users and gates;
 *  - the central connection Alfred keeps its own tables on.
 */
class Tenancy
{
    public const HEADER = 'X-Alfred-Tenant';

    public const PARAM = 'alfred_tenant';

    protected ?TenancyAdapter $adapter = null;

    protected bool $resolved = false;

    public function adapter(): ?TenancyAdapter
    {
        if ($this->resolved) {
            return $this->adapter;
        }
        $this->resolved = true;

        $driver = strtolower(trim((string) config('alfred.tenancy.driver', 'auto')));
        $candidates = match ($driver) {
            '', 'none', 'off', 'false' => [],
            'auto' => [StanclTenancy::class, SpatieTenancy::class],
            'stancl' => [StanclTenancy::class],
            'spatie' => [SpatieTenancy::class],
            default => [config('alfred.tenancy.driver')],
        };

        foreach ($candidates as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }
            $adapter = app($class);
            if ($adapter instanceof TenancyAdapter && $adapter->available()) {
                return $this->adapter = $adapter;
            }
        }

        return null;
    }

    public function current(): ?string
    {
        try {
            return $this->adapter()?->current();
        } catch (Throwable) {
            return null;
        }
    }

    /** Must this request carry a tenant reference? (On a tenant domain: yes.) */
    public function isTenantRequest(Request $request): bool
    {
        try {
            return (bool) $this->adapter()?->isTenantRequest($request);
        } catch (Throwable) {
            return false;
        }
    }

    public function centralConnection(): ?string
    {
        try {
            return $this->adapter()?->centralConnection();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The signed reference to the current tenant for the overlay, or null on
     * central pages. Format: base64url(key).expires.signature
     */
    public function clientToken(Request $request): ?string
    {
        $key = $this->current();
        if ($key === null || $key === '') {
            return null;
        }

        $expires = time() + max(300, (int) config('alfred.tenancy.token_ttl', 43200));
        $encoded = rtrim(strtr(base64_encode($key), '+/', '-_'), '=');

        return $encoded.'.'.$expires.'.'.$this->signature($key, $expires, $request);
    }

    /** The tenant key from a reference the overlay sent back, or null if it is missing, expired or tampered with. */
    public function keyFromRequest(Request $request): ?string
    {
        $token = $request->headers->get(self::HEADER) ?: $request->query(self::PARAM);
        if (! is_string($token) || substr_count($token, '.') !== 2) {
            return null;
        }

        [$encoded, $expires, $signature] = explode('.', $token, 3);
        if (! ctype_digit($expires) || (int) $expires < time()) {
            return null;
        }
        $key = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (! is_string($key) || $key === '') {
            return null;
        }

        return hash_equals($this->signature($key, (int) $expires, $request), $signature) ? $key : null;
    }

    /** Did the request carry a tenant reference at all (valid or not)? */
    public function requested(Request $request): bool
    {
        return $request->headers->has(self::HEADER) || $request->query->has(self::PARAM);
    }

    public function initialize(string $key): void
    {
        $this->adapter()?->initialize($key);
    }

    public function end(): void
    {
        try {
            $this->adapter()?->end();
        } catch (Throwable) {
            // already ended
        }
    }

    /**
     * Bound to the host, so a reference from one tenant's domain is useless
     * on another. Not bound to the session: tenancy has to start before the
     * session does, for apps that keep sessions in the tenant database.
     */
    protected function signature(string $key, int $expires, Request $request): string
    {
        return hash_hmac('sha256', 'alfred-tenant|'.$key.'|'.strtolower($request->getHost()).'|'.$expires, (string) config('app.key'));
    }
}
