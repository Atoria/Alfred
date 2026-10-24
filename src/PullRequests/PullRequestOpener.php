<?php

namespace Atoria\Alfred\PullRequests;

use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Events\PullRequestOpened;
use Atoria\Alfred\PullRequests\Providers\GitProvider;
use Atoria\Alfred\PullRequests\Providers\GitProviderManager;

/** Opens a pull request for a change, once, and records it on the change. */
class PullRequestOpener
{
    public function __construct(
        protected GitProviderManager $providers,
        protected PullRequestBuilder $builder,
    ) {}

    /** Can this change get a pull request at all (ignoring who asks)? */
    public function eligible(Change $change): bool
    {
        return $change->hasEdits()
            && ! $change->pr_key
            && ! in_array($change->status, [Change::DISCARDED, Change::FAILED, Change::REQUESTED], true)
            && $this->providers->available() !== [];
    }

    /**
     * @return array{provider: string, label: string, noun: string, key: string, url: string, branch: string, warnings: string[]}
     *
     * @throws PullRequestException
     */
    public function open(Change $change, ?string $providerName, ?string $title, ?string $by): array
    {
        if ($change->pr_key) {
            throw new PullRequestException("A pull request already exists: {$change->pr_key}.");
        }
        if (! $change->hasEdits()) {
            throw new PullRequestException('This change has no code edits to put in a pull request.');
        }
        $provider = $this->providers->find($providerName);
        if (! $provider instanceof GitProvider) {
            throw new PullRequestException($providerName
                ? "The git provider \"{$providerName}\" is not set up here."
                : 'No git provider is set up. Set ALFRED_PULL_REQUESTS and its settings.');
        }

        $result = $provider->open($this->builder->draft($change, $title));

        $change->forceFill([
            'pr_provider' => $provider->name(),
            'pr_key' => $result['key'],
            'pr_url' => $result['url'],
            'pr_branch' => $result['branch'],
            'pr_opened_by' => $by,
            'pr_opened_at' => now(),
        ])->save();
        PullRequestOpened::dispatch($change, $by);

        return ['provider' => $provider->name(), 'label' => $provider->label(), 'noun' => $provider->noun()] + $result;
    }

    /** @return array{provider: ?string, label: ?string, key: ?string, url: ?string, branch: ?string} */
    public function info(Change $change): array
    {
        $label = null;
        if ($change->pr_provider) {
            try {
                $label = $this->providers->get($change->pr_provider)->label();
            } catch (\InvalidArgumentException) {
                $label = ucfirst($change->pr_provider);
            }
        }

        return ['provider' => $change->pr_provider, 'label' => $label, 'key' => $change->pr_key, 'url' => $change->pr_url, 'branch' => $change->pr_branch];
    }

    /** @return array<int, array{name: string, label: string, target: ?string, noun: string}> */
    public function choices(): array
    {
        return array_map(fn (GitProvider $p) => ['name' => $p->name(), 'label' => $p->label(), 'target' => $p->target(), 'noun' => $p->noun()], $this->providers->available());
    }
}
