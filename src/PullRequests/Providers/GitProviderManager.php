<?php

namespace Atoria\Alfred\PullRequests\Providers;

use InvalidArgumentException;

/** Knows every git provider and which ones this app offers (ALFRED_PULL_REQUESTS). */
class GitProviderManager
{
    public const BUILT_IN = [
        'github' => GitHubProvider::class,
        'gitlab' => GitLabProvider::class,
        'bitbucket' => BitbucketProvider::class,
    ];

    /** @var array<string, GitProvider> */
    protected array $resolved = [];

    /** @return array<string, class-string<GitProvider>> */
    public function all(): array
    {
        return array_merge(self::BUILT_IN, (array) config('alfred.pull_requests.custom', []));
    }

    public function get(string $name): GitProvider
    {
        $class = $this->all()[$name] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException("Unknown git provider [{$name}].");
        }

        return $this->resolved[$name] ??= app($class);
    }

    /** @return GitProvider[] listed in ALFRED_PULL_REQUESTS and fully configured, in that order */
    public function available(): array
    {
        $names = array_values(array_unique(array_filter(array_map(fn ($n) => strtolower(trim((string) $n)), (array) config('alfred.pull_requests.providers', [])))));

        return array_values(array_filter(
            array_map(fn ($n) => isset($this->all()[$n]) ? $this->get($n) : null, $names),
            fn ($p) => $p instanceof GitProvider && $p->configured(),
        ));
    }

    public function find(?string $name): ?GitProvider
    {
        foreach ($this->available() as $provider) {
            if ($name === null || $provider->name() === $name) {
                return $provider;
            }
        }

        return null;
    }
}
