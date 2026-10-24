<?php

namespace Atoria\Alfred\PullRequests\Providers;

use Atoria\Alfred\PullRequests\PullRequestDraft;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * GitHub (and GitHub Enterprise via api_url). One commit with all files,
 * built with the Git Data API, on a new branch; then a (draft) pull request.
 */
class GitHubProvider extends BaseGitProvider
{
    protected array $required = ['token', 'repo'];

    public function name(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return 'GitHub';
    }

    public function target(): ?string
    {
        return $this->config('repo');
    }

    public function open(PullRequestDraft $draft): array
    {
        $repo = $this->repo();
        $base = $draft->base ?: (string) $this->call('the repository lookup', fn () => $this->http()->get($repo))->json('default_branch');

        $baseSha = (string) $this->call("the branch {$base}", fn () => $this->http()->get("{$repo}/git/ref/heads/".rawurlencode($base)))->json('object.sha');
        $baseTree = (string) $this->call('the base commit', fn () => $this->http()->get("{$repo}/git/commits/{$baseSha}"))->json('tree.sha');

        $tree = [];
        foreach ($draft->paths() as $path) {
            $res = $this->call("the file {$path}", fn () => $this->http()->withHeaders(['Accept' => 'application/vnd.github.raw+json'])
                ->get("{$repo}/contents/".$this->encodePath($path), ['ref' => $base]), [404]);
            if ($res->status() === 404) {
                throw $this->missingFile($path);
            }
            $tree[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'content' => $draft->apply($path, $res->body())];
        }

        $treeSha = (string) $this->call('the new tree', fn () => $this->http()->post("{$repo}/git/trees", ['base_tree' => $baseTree, 'tree' => $tree]))->json('sha');
        $commit = (string) $this->call('the commit', fn () => $this->http()->post("{$repo}/git/commits", [
            'message' => $draft->commitMessage, 'tree' => $treeSha, 'parents' => [$baseSha],
        ]))->json('sha');

        // A branch left over from an earlier failed attempt is ours: move it.
        $ref = $this->call('the branch', fn () => $this->http()->post("{$repo}/git/refs", ['ref' => 'refs/heads/'.$draft->branch, 'sha' => $commit]), [422]);
        if ($ref->status() === 422) {
            $this->call('the branch', fn () => $this->http()->patch("{$repo}/git/refs/heads/".$this->encodePath($draft->branch), ['sha' => $commit, 'force' => true]));
        }

        $payload = ['title' => mb_substr($draft->title, 0, 250), 'head' => $draft->branch, 'base' => $base, 'body' => $draft->body, 'draft' => $draft->draft];
        $pr = $this->call('the pull request', fn () => $this->http()->post("{$repo}/pulls", $payload), [422]);
        $warnings = [];
        if ($pr->status() === 422 && $draft->draft && str_contains(strtolower($pr->body()), 'draft')) {
            // Draft pull requests need a paid plan on private repositories.
            $pr = $this->call('the pull request', fn () => $this->http()->post("{$repo}/pulls", ['draft' => false] + $payload));
            $warnings[] = 'This repository does not allow draft pull requests, so it was opened as a normal one.';
        } elseif ($pr->failed()) {
            $this->call('the pull request', fn () => $pr);
        }

        $number = (int) $pr->json('number');
        if ($draft->labels) {
            try {
                $this->call('the labels', fn () => $this->http()->post("{$repo}/issues/{$number}/labels", ['labels' => $draft->labels]));
            } catch (\Atoria\Alfred\PullRequests\PullRequestException $e) {
                $warnings[] = $e->getMessage();
            }
        }

        return ['key' => '#'.$number, 'url' => (string) $pr->json('html_url'), 'branch' => $draft->branch, 'warnings' => $warnings];
    }

    protected function http(): PendingRequest
    {
        return Http::withToken((string) $this->config('token'))
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(30);
    }

    protected function repo(): string
    {
        return rtrim((string) ($this->config('api_url') ?: 'https://api.github.com'), '/').'/repos/'.trim((string) $this->config('repo'), '/');
    }

    protected function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
