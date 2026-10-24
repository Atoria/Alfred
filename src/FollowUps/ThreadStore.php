<?php

namespace Atoria\Alfred\FollowUps;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ThreadStore
{
    public function find(?string $id, string $owner, int $explanationId): ?Thread
    {
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9]{32}$/', $id)) {
            return null;
        }

        $data = $this->cache()->get($this->key($id));
        if (! is_array($data)) {
            return null;
        }

        $thread = Thread::fromArray($data);

        return $thread->owner === $owner && $thread->explanationId === $explanationId ? $thread : null;
    }

    public function start(string $owner, int $explanationId): Thread
    {
        return new Thread(Str::random(32), $owner, $explanationId);
    }

    public function save(Thread $thread): void
    {
        $this->cache()->put($this->key($thread->id), $thread->toArray(), max(600, (int) config('alfred.recording.ttl', 3600)));
    }

    protected function key(string $id): string
    {
        return 'alfred:thread:'.$id;
    }

    protected function cache(): Repository
    {
        return Cache::store(config('alfred.recording.store'));
    }
}
