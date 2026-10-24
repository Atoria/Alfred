<?php

namespace Atoria\Alfred\Context;

/**
 * Everything Alfred knows about one page view: the initial request plus the
 * Livewire requests it made afterwards. Stored server-side only.
 */
class PageContext
{
    /** @var array<string, string|null> view name => relative path */
    public array $views = [];

    /** @var array<string, string> livewire name => class */
    public array $components = [];

    /** @var array<string, array<string, mixed>> keyed by query fingerprint */
    public array $queries = [];

    public function __construct(
        public string $token,
        public ?string $userId,
        public string $url,
        public ?string $routeName = null,
        public ?string $routeUri = null,
        public ?string $action = null,
        public int $createdAt = 0,
    ) {
        $this->createdAt = $createdAt ?: time();
    }

    /** The route as a stable identifier: its name, else its URI pattern, else the path. */
    public function routeKey(): string
    {
        return $this->routeName ?: ($this->routeUri ?: (parse_url($this->url, PHP_URL_PATH) ?: '/'));
    }

    public function addView(string $name, ?string $path): void
    {
        if (count($this->views) < 300 && ! array_key_exists($name, $this->views)) {
            $this->views[$name] = $path;
        }
    }

    public function addComponent(string $name, string $class): void
    {
        if (count($this->components) < 200) {
            $this->components[$name] = $class;
        }
    }

    public function queryCount(): int
    {
        return count($this->queries);
    }

    /** @param  array<int, mixed>  $bindings */
    public function addQuery(string $sql, array $bindings, string $connection, float $time, ?string $source, ?string $caller, ?string $component, int $max = 300): void
    {
        $id = substr(sha1($connection.'|'.$sql.'|'.$source), 0, 16);

        if (isset($this->queries[$id])) {
            $this->queries[$id]['count']++;
            $this->queries[$id]['bindings'] = $bindings; // latest wins
            $this->queries[$id]['time'] += $time;

            return;
        }

        if (count($this->queries) >= $max) {
            return;
        }

        $this->queries[$id] = [
            'id' => $id,
            'sql' => $sql,
            'bindings' => $bindings,
            'connection' => $connection,
            'time' => $time,
            'source' => $source,
            'caller' => $caller,
            'component' => $component,
            'count' => 1,
        ];
    }

    /** Queries as a numbered list (the agent refers to them by index). @return array<int, array<string, mixed>> */
    public function queryList(): array
    {
        return array_values($this->queries);
    }

    public function query(int $index): ?array
    {
        return $this->queryList()[$index] ?? null;
    }

    /** Find the recorded query with this SQL (and preferably this source). */
    public function findQuery(string $sql, ?string $connection = null, ?string $source = null): ?array
    {
        $fallback = null;
        foreach ($this->queries as $q) {
            if ($q['sql'] !== $sql || ($connection !== null && $q['connection'] !== $connection)) {
                continue;
            }
            if ($source === null || $q['source'] === $source) {
                return $q;
            }
            $fallback ??= $q;
        }

        return $fallback;
    }

    public function merge(self $other): void
    {
        foreach ($other->views as $name => $path) {
            $this->addView($name, $path);
        }
        foreach ($other->components as $name => $class) {
            $this->addComponent($name, $class);
        }
        foreach ($other->queries as $id => $q) {
            if (isset($this->queries[$id])) {
                $this->queries[$id]['count'] += $q['count'];
                $this->queries[$id]['bindings'] = $q['bindings'];
            } else {
                $this->queries[$id] = $q;
            }
        }
    }

    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'user_id' => $this->userId,
            'url' => $this->url,
            'route_name' => $this->routeName,
            'route_uri' => $this->routeUri,
            'action' => $this->action,
            'created_at' => $this->createdAt,
            'views' => $this->views,
            'components' => $this->components,
            'queries' => $this->queries,
        ];
    }

    public static function fromArray(array $data): self
    {
        $ctx = new self(
            (string) $data['token'],
            isset($data['user_id']) ? (string) $data['user_id'] : null,
            (string) ($data['url'] ?? ''),
            $data['route_name'] ?? null,
            $data['route_uri'] ?? null,
            $data['action'] ?? null,
            (int) ($data['created_at'] ?? 0),
        );
        $ctx->views = (array) ($data['views'] ?? []);
        $ctx->components = (array) ($data['components'] ?? []);
        $ctx->queries = (array) ($data['queries'] ?? []);

        return $ctx;
    }
}
