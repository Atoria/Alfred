<?php

namespace Atoria\Alfred\Context;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Server-side storage for what a page did (route, views, components, queries
 * WITH bindings). Lives only in your cache — the agent sees a redacted view.
 */
class ContextStore
{
    public function cache(): Repository
    {
        return Cache::store(config('alfred.recording.store'));
    }

    public function get(string $token): ?PageContext
    {
        if (! self::validToken($token)) {
            return null;
        }

        $data = $this->cache()->get($this->key($token));

        return is_array($data) ? PageContext::fromArray($data) : null;
    }

    public function put(PageContext $context): void
    {
        $this->cache()->put($this->key($context->token), $context->toArray(), (int) config('alfred.recording.ttl', 3600));
    }

    public static function validToken(?string $token): bool
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9]{32,64}$/', $token) === 1;
    }

    protected function key(string $token): string
    {
        return 'alfred:ctx:'.$token;
    }
}
