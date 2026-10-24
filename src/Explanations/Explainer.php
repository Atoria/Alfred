<?php

namespace Atoria\Alfred\Explanations;

use Atoria\Alfred\Agent\Agent;
use Atoria\Alfred\Context\ElementDescriptor;
use Atoria\Alfred\Context\PageContext;
use Atoria\Alfred\Security\PathGuard;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Cache-first explanations: one per "thing on a page", regenerated only when
 * the code it was built from changes (verified ones are kept and flagged).
 */
class Explainer
{
    public function __construct(
        protected Agent $agent,
        protected PathGuard $guard,
    ) {}

    /**
     * The stored explanation, if it can be served without generating: the
     * shared one, or (for someone who may not create shared ones) their own.
     *
     * @return array{explanation: Explanation, cached: true, stale: bool}|null
     */
    public function cached(PageContext $page, ElementDescriptor $element, ?string $owner = null): ?array
    {
        $shared = ExplanationKey::for($page, $element);

        foreach (array_filter([$shared, $owner !== null ? ExplanationKey::private($shared, $owner) : null]) as $key) {
            $existing = Explanation::query()->where('key', $key)->first();
            if (! $existing) {
                continue;
            }

            $stale = $existing->isStale($this->guard);

            // A verified explanation is never silently replaced — a human approved it.
            // Unverified ones in the old single-audience format are regenerated.
            if ($existing->isVerified() || (! $stale && ! $existing->isOutdatedFormat())) {
                return ['explanation' => $existing, 'cached' => true, 'stale' => $stale];
            }
        }

        return null;
    }

    /**
     * @param  string|null  $owner  set when this person may not create shared explanations: theirs stays private
     * @return array{explanation: Explanation, cached: bool, stale: bool}
     */
    public function explain(PageContext $page, ElementDescriptor $element, string $userKey, bool $force = false, ?string $owner = null, ?string $by = null): array
    {
        $key = ExplanationKey::for($page, $element);
        if ($owner !== null) {
            $key = ExplanationKey::private($key, $owner);
        }

        if (! $force && ($hit = $this->cached($page, $element, $owner))) {
            return $hit;
        }

        $this->throttle($userKey);

        $generate = function () use ($key, $page, $element, $force, $owner, $by) {
            // Someone else may have generated it while we waited for the lock.
            if (! $force && ($fresh = Explanation::query()->where('key', $key)->first()) && ! $fresh->isStale($this->guard) && ! $fresh->isOutdatedFormat()) {
                return $fresh;
            }

            return $this->generate($key, $page, $element, $owner, $by);
        };

        // One generation per key at a time, so two users opening the same
        // explanation don't pay for it twice.
        // Cache::store(), not the Cache facade directly: tenancy packages
        // (stancl's CacheTenancyBootstrapper) tag facade calls, which stores
        // like "database" or "file" don't support.
        $cache = Cache::store();
        $explanation = $cache->getStore() instanceof LockProvider
            ? $cache->getStore()->lock('alfred:gen:'.$key, 180)->block(150, $generate)
            : $generate();

        return ['explanation' => $explanation, 'cached' => false, 'stale' => false];
    }

    protected function generate(string $key, PageContext $page, ElementDescriptor $element, ?string $owner = null, ?string $by = null): Explanation
    {
        $result = $this->agent->run($page, $element->forExplanation());
        $e = $result->explanation;

        $query = $e['query_index'] !== null ? $page->query($e['query_index']) : null;
        $existed = Explanation::query()->where('key', $key)->exists();

        $explanation = Explanation::query()->updateOrCreate(['key' => $key], [
            'owner' => $owner,
            'route' => mb_substr($page->routeKey(), 0, 255),
            'component' => $element->innermostComponent(),
            'label' => mb_substr((string) ($element->explainKey ?? $element->column ?? ($element->labels[0] ?? '')), 0, 255) ?: null,
            'title' => $e['title'],
            'summary' => $e['summary'],
            'formula' => $e['formula'],
            'steps' => $e['steps'],
            'data_sources' => $e['data_sources'],
            'caveats' => $e['caveats'],
            'example' => $e['example'],
            'glossary' => $e['glossary'],
            'developer' => $e['developer'],
            'technical_notes' => $e['developer']['flow'],
            'format' => Explanation::FORMAT,
            'confidence' => $e['confidence'],
            'query_sql' => $query['sql'] ?? null,
            'query_connection' => $query['connection'] ?? null,
            'query_source' => $query['source'] ?? null,
            'files' => $result->files,
            'model' => $result->model,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'status' => Explanation::GENERATED,
            'edited' => false,
            'verified_by' => null,
            'verified_at' => null,
        ]);

        ExplanationVersion::record($explanation, $existed ? 'regenerated' : 'generated', $by);
        \Atoria\Alfred\Events\ExplanationGenerated::dispatch($explanation, $by);

        return $explanation;
    }

    /** New AI work for this user (and, for guests, this IP) this minute. Shared by explanations and follow-ups. */
    public function throttle(string $userKey): void
    {
        \Atoria\Alfred\Usage\Usage::ensureBudget($userKey);

        $limit = (int) config('alfred.rate_limit', 6);
        if ($limit <= 0) {
            return;
        }

        $keys = ['alfred:generate:'.$userKey => $limit];

        // Guests can start a fresh session at will, so they are also limited per IP.
        if (! str_starts_with($userKey, 'u:') && app()->bound('request')) {
            $keys['alfred:generate:guest-ip:'.sha1(app(\Atoria\Alfred\Tenancy\Tenancy::class)->current().'|'.(string) request()->ip())] = max(1, (int) config('alfred.guests.rate_limit', $limit));
        }

        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new TooManyGenerations(RateLimiter::availableIn($key));
            }
        }
        foreach (array_keys($keys) as $key) {
            RateLimiter::hit($key, 60);
        }
    }
}
