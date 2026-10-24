<?php

namespace Atoria\Alfred\PullRequests;

use Atoria\Alfred\Changes\EditApplier;
use Atoria\Alfred\Changes\EditMismatch;

/**
 * One pull request to open: title, body, branch, commit message and the
 * change's edits grouped by repository path. Providers fetch each file from
 * the base branch and call apply() to get the new contents.
 */
final class PullRequestDraft
{
    /**
     * @param  array<string, array<int, array{search: string, replace: string}>>  $edits  repo path => edits
     * @param  string[]  $labels
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $branch,
        public readonly string $commitMessage,
        public readonly array $edits,
        public readonly ?string $base,
        public readonly bool $draft,
        public readonly array $labels,
        public readonly int $changeId,
    ) {}

    /** @return string[] repository paths that change */
    public function paths(): array
    {
        return array_keys($this->edits);
    }

    /**
     * The file's new contents: the edits re-applied to the base branch's copy.
     * Exact matches first; if the base branch moved, the forgiving matcher.
     */
    public function apply(string $path, string $content): string
    {
        try {
            return EditApplier::apply($content, $this->edits[$path] ?? [], forgiving: true);
        } catch (EditMismatch $e) {
            throw new PullRequestException("{$path} on the base branch no longer matches edit ".($e->index + 1).' of this change ('.preg_replace('/^Edit \d+: /', '', $e->getMessage()).') Ask again for a fresh change, or apply the patch by hand.');
        }
    }
}
