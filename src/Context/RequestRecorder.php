<?php

namespace Atoria\Alfred\Context;

use Atoria\Alfred\Support\SafeUrl;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Records what the current request did.
 *
 * Recording starts before we know who the user is (Alfred runs as global
 * middleware, before sessions start, so it works with any middleware stack
 * — the web group, Filament panels, Inertia, Livewire). Whether anything is
 * kept is decided after the response, once the user is known.
 *
 * Bound as a scoped singleton: reset per request (Octane-safe).
 */
class RequestRecorder
{
    protected ?PageContext $context = null;

    /** True when this request continues a page we injected into (a Livewire round-trip). */
    protected bool $continuation = false;

    protected ?string $currentComponent = null;

    protected string $basePath;

    protected string $packagePath;

    public function __construct(protected ContextStore $store)
    {
        $this->basePath = rtrim(str_replace('\\', '/', (string) (config('alfred.base_path') ?: base_path())), '/');
        $this->packagePath = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/'); // .../src
    }

    public function active(): bool
    {
        return $this->context !== null;
    }

    public function context(): ?PageContext
    {
        return $this->context;
    }

    public function isContinuation(): bool
    {
        return $this->continuation;
    }

    /** Start recording a new page view. */
    public function start(Request $request): PageContext
    {
        $this->continuation = false;

        return $this->context = new PageContext(Str::random(40), null, SafeUrl::clean($request->fullUrl()));
    }

    /** Start recording a follow-up request from an already-recorded page. */
    public function continue(string $token, Request $request): PageContext
    {
        $this->continuation = true;

        return $this->context = new PageContext($token, null, SafeUrl::clean($request->fullUrl()));
    }

    public function captureRoute(Request $request): void
    {
        if (! $this->context || $this->continuation || ! ($route = $request->route())) {
            return;
        }

        $this->context->routeName = $route->getName();
        $this->context->routeUri = $route->uri();
        $this->context->action = $route->getActionName();
    }

    public function recordQuery(QueryExecuted $event): void
    {
        $max = (int) config('alfred.recording.max_queries', 300);
        if (! $this->context || $this->context->queryCount() >= $max) {
            return; // full: don't pay for a backtrace per query on query-heavy pages
        }

        [$source, $caller] = $this->locate();

        $this->context->addQuery(
            $event->sql,
            $event->bindings,
            $event->connectionName,
            (float) $event->time,
            $source,
            $caller,
            $this->currentComponent,
            $max,
        );
    }

    public function recordView(string $name, ?string $path): void
    {
        if (! $this->context || str_starts_with($name, 'alfred::')) {
            return;
        }

        $this->context->addView($name, $path ? $this->relative($path) : null);
    }

    public function recordComponent(object $component): void
    {
        if (! $this->context) {
            return;
        }

        $name = method_exists($component, 'getName') ? (string) $component->getName() : get_class($component);
        $this->context->addComponent($name, get_class($component));
        $this->currentComponent = $name;
    }

    /**
     * Keep what was recorded, now that we know the user.
     * Returns the page token, or null if nothing was kept.
     */
    public function persist(string $userKey): ?string
    {
        if (! $this->context) {
            return null;
        }

        if ($this->continuation) {
            $existing = $this->store->get($this->context->token);

            // Only the user who loaded the page may add to its context.
            if (! $existing || $existing->userId !== $userKey) {
                return null;
            }

            $existing->merge($this->context);
            $this->store->put($existing);

            return $existing->token;
        }

        $this->context->userId = $userKey;
        $this->store->put($this->context);

        return $this->context->token;
    }

    public function stop(): void
    {
        $this->context = null;
        $this->continuation = false;
        $this->currentComponent = null;
    }

    /**
     * The first stack frame inside the application (not vendor, not Alfred,
     * not compiled views) — i.e. "which line of YOUR code ran this query".
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function locate(): array
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60);

        foreach ($frames as $i => $frame) {
            $file = isset($frame['file']) ? str_replace('\\', '/', $frame['file']) : null;

            if (! $file || ! str_starts_with($file, $this->basePath.'/')) {
                continue;
            }
            if (str_contains($file, '/vendor/') || str_starts_with($file, $this->packagePath.'/') || str_contains($file, '/storage/framework/')) {
                continue;
            }

            $next = $frames[$i + 1] ?? [];
            $caller = isset($next['class']) ? $next['class'].'::'.$next['function'] : ($next['function'] ?? null);

            return [$this->relative($file).':'.($frame['line'] ?? 0), $caller];
        }

        return [null, null];
    }

    protected function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $this->basePath.'/') ? substr($path, strlen($this->basePath) + 1) : $path;
    }
}
