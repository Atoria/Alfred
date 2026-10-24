<?php

namespace Atoria\Alfred\Tasks;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Tenancy\Tenancy;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Long AI work (a new explanation, a proposed change) takes 5–15 model calls,
 * often longer than a load balancer or proxy lets a request live (Laravel
 * Cloud, nginx, Cloudflare: 30–100 s → "504"). So the request answers at
 * once with a task id, the work runs right after the response has been sent
 * (same PHP process, so the signed-in user, gates and tenant are as on the
 * request), and the overlay polls /_alfred/tasks/{id} for the result.
 *
 * Servers that can't finish a response early (`php artisan serve`) simply
 * deliver the task id once the work is done; the overlay then gets the
 * result on its first check. ALFRED_ASYNC=false runs everything inline.
 */
class Background
{
    public function __construct(protected Alfred $alfred, protected Tenancy $tenancy) {}

    public function enabled(): bool
    {
        return (bool) config('alfred.async.enabled', true);
    }

    /** @param  Closure(): JsonResponse  $work */
    public function run(Request $request, Closure $work): JsonResponse
    {
        if (! $this->enabled()) {
            return $work();
        }

        $id = Str::random(40);
        $owner = $this->alfred->userKey($request);

        // Each task holds a PHP worker for up to minutes: one person can't tie them all up.
        if (! $this->claimSlot($owner, $id)) {
            return response()->json(['message' => Alfred::name().' is still working on your earlier requests. Wait for one to finish, then try again.'], 429)
                ->header('Retry-After', '15');
        }

        $this->put($id, ['status' => 'running', 'owner' => $owner, 'started' => time()]);

        $tenant = $this->tenancy->current();

        $ran = false; // long-lived apps (tests, some workers) keep terminating callbacks around
        app()->terminating(function () use ($id, $owner, $work, $tenant, &$ran) {
            if ($ran) {
                return;
            }
            $ran = true;
            @set_time_limit(0);
            @ignore_user_abort(true);

            // Alfred's tenant middleware has already left the tenant by now.
            $entered = false;
            try {
                if ($tenant !== null && $this->tenancy->current() !== $tenant) {
                    $this->tenancy->initialize($tenant);
                    $entered = true;
                }

                $response = $work();
                $this->put($id, ['status' => 'done', 'owner' => $owner, 'code' => $response->getStatusCode(), 'body' => $response->getData(true), 'retry_after' => $response->headers->get('Retry-After')]);
            } catch (Throwable $e) {
                report($e);
                $this->put($id, ['status' => 'done', 'owner' => $owner, 'code' => 500, 'body' => ['message' => Alfred::name().' ran into a problem. Please try again.']]);
            } finally {
                $this->releaseSlot($owner, $id);
                if ($entered) {
                    $this->tenancy->end();
                }
            }
        });

        return response()->json(['task' => $id, 'status' => 'running'], 202);
    }

    /** What the overlay gets when it checks on a task. */
    public function result(Request $request, string $id): JsonResponse
    {
        $task = preg_match('/^[A-Za-z0-9]{40}$/', $id) ? $this->cache()->get($this->key($id)) : null;

        if (! is_array($task) || ($task['owner'] ?? null) !== $this->alfred->userKey($request)) {
            return response()->json(['message' => 'This request has expired. Please try again.'], 410);
        }

        if ($task['status'] === 'running') {
            $elapsed = time() - (int) ($task['started'] ?? time());
            if ($elapsed > max(60, (int) config('alfred.async.timeout', 600))) {
                return response()->json(['message' => Alfred::name().' took too long and stopped. Please try again, or describe a smaller change.'], 504);
            }

            return response()->json(['task' => $id, 'status' => 'running', 'elapsed' => $elapsed], 202);
        }

        $response = response()->json($task['body'] ?? [], (int) ($task['code'] ?? 200));

        return isset($task['retry_after']) ? $response->header('Retry-After', (string) $task['retry_after']) : $response;
    }

    protected function claimSlot(string $owner, string $id): bool
    {
        $max = (int) config('alfred.async.max_running', 3);
        if ($max <= 0) {
            return true;
        }

        $key = $this->slotsKey($owner);
        $now = time();
        $stale = max(60, (int) config('alfred.async.timeout', 600));
        $running = array_filter((array) $this->cache()->get($key, []), fn ($started) => $now - (int) $started < $stale);
        if (count($running) >= $max) {
            return false;
        }
        $running[$id] = $now;
        $this->cache()->put($key, $running, $stale + 60);

        return true;
    }

    protected function releaseSlot(string $owner, string $id): void
    {
        try {
            $key = $this->slotsKey($owner);
            $running = (array) $this->cache()->get($key, []);
            unset($running[$id]);
            $this->cache()->put($key, $running, max(60, (int) config('alfred.async.timeout', 600)) + 60);
        } catch (Throwable) {
            // stale slots expire on their own
        }
    }

    protected function slotsKey(string $owner): string
    {
        return 'alfred:running:'.sha1($owner);
    }

    protected function put(string $id, array $task): void
    {
        $this->cache()->put($this->key($id), $task, max(600, (int) config('alfred.async.timeout', 600) + 300));
    }

    protected function key(string $id): string
    {
        return 'alfred:task:'.$id;
    }

    protected function cache(): Repository
    {
        return Cache::store(config('alfred.recording.store'));
    }
}
