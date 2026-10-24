<?php

namespace Atoria\Alfred\Http\Middleware;

use Atoria\Alfred\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs first on Alfred's own routes (before the session starts): puts the
 * request in the tenant of the page it came from, using the signed reference
 * the overlay sends. Users, gates, the cache and files then all resolve in
 * that tenant exactly as they did on the page.
 */
class ResolveTenant
{
    public function __construct(protected Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tenancy->adapter()) {
            return $next($request);
        }

        if (! $this->tenancy->requested($request)) {
            // On a tenant's domain Alfred never runs without knowing the page's
            // tenant (plain links like the access link and "stop preview" excepted).
            if ($this->tenancy->isTenantRequest($request) && ! $this->exempt($request)) {
                return $this->deny($request, 'This request is missing its workspace. Reload the page and try again.');
            }

            return $next($request);
        }

        $key = $this->tenancy->keyFromRequest($request);
        if ($key === null) {
            return $this->deny($request, 'This page has expired. Reload the page and try again.');
        }

        $current = $this->tenancy->current();
        if ($current !== null && $current !== $key) {
            // The app already identified a different tenant for this request.
            return $this->deny($request, 'This request belongs to a different workspace.');
        }

        if ($current === null) {
            try {
                $this->tenancy->initialize($key);
            } catch (Throwable $e) {
                report($e);

                return $this->deny($request, 'This workspace is no longer available.', 404);
            }
        }

        return $next($request);
    }

    /** Plain navigations that never carry the header and touch no tenant data. */
    protected function exempt(Request $request): bool
    {
        $prefix = trim((string) config('alfred.path', '_alfred'), '/');

        return $request->is($prefix.'/access', $prefix.'/access/forget', $prefix.'/preview/stop');
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($this->tenancy->requested($request)) {
            $this->tenancy->end(); // long-running workers (Octane) start the next request clean
        }
    }

    protected function deny(Request $request, string $message, int $status = 403): Response
    {
        return $request->expectsJson() || $request->isMethod('POST')
            ? response()->json(['message' => $message], $status)
            : response($message, $status);
    }
}
