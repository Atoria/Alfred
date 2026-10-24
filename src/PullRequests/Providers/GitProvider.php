<?php

namespace Atoria\Alfred\PullRequests\Providers;

use Atoria\Alfred\PullRequests\PullRequestDraft;
use Atoria\Alfred\PullRequests\PullRequestException;

/**
 * Where pull requests go: GitHub, GitLab, Bitbucket, … Implement this to add
 * your own and register it in config('alfred.pull_requests.custom').
 */
interface GitProvider
{
    /** Short key used in config and the API, e.g. "github". */
    public function name(): string;

    /** What people see, e.g. "GitHub". */
    public function label(): string;

    public function configured(): bool;

    /** The repository, shown on the button, e.g. "acme/app". */
    public function target(): ?string;

    /** What review requests are called here: "pull request" or "merge request". */
    public function noun(): string;

    /**
     * Create the branch, commit the edits and open the pull request.
     *
     * @return array{key: string, url: string, branch: string, warnings: string[]}
     *
     * @throws PullRequestException
     */
    public function open(PullRequestDraft $draft): array;
}
