<?php

namespace Atoria\Alfred\PullRequests\Providers;

use Atoria\Alfred\PullRequests\PullRequestDraft;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Bitbucket Cloud: one commit on a new branch (src API), then a pull request.
 * Auth: a repository/workspace access token, or your email plus an API token.
 */
class BitbucketProvider extends BaseGitProvider
{
    protected const API = 'https://api.bitbucket.org/2.0/repositories/';

    protected array $required = ['token', 'repo'];

    public function name(): string
    {
        return 'bitbucket';
    }

    public function label(): string
    {
        return 'Bitbucket';
    }

    public function target(): ?string
    {
        return $this->config('repo');
    }

    public function open(PullRequestDraft $draft): array
    {
        $repo = self::API.trim((string) $this->config('repo'), '/');
        $base = $draft->base ?: (string) $this->call('the repository lookup', fn () => $this->http()->get($repo))->json('mainbranch.name');
        $baseHash = (string) $this->call("the branch {$base}", fn () => $this->http()->get("{$repo}/refs/branches/".rawurlencode($base)))->json('target.hash');

        $request = $this->http()->asMultipart();
        foreach ($draft->paths() as $path) {
            $res = $this->call("the file {$path}", fn () => $this->http()->get("{$repo}/src/{$baseHash}/".implode('/', array_map('rawurlencode', explode('/', $path)))), [404]);
            if ($res->status() === 404) {
                throw $this->missingFile($path);
            }
            $request = $request->attach($path, $draft->apply($path, $res->body()), basename($path));
        }

        $this->call('the commit', fn () => $request->post("{$repo}/src", [
            'message' => $draft->commitMessage, 'branch' => $draft->branch, 'parents' => $baseHash,
        ]));

        $pr = $this->call('the pull request', fn () => $this->http()->post("{$repo}/pullrequests", [
            'title' => mb_substr($draft->title, 0, 250),
            'description' => $draft->body,
            'source' => ['branch' => ['name' => $draft->branch]],
            'destination' => ['branch' => ['name' => $base]],
            'close_source_branch' => true,
            'draft' => $draft->draft,
        ]));

        return ['key' => '#'.$pr->json('id'), 'url' => (string) $pr->json('links.html.href'), 'branch' => $draft->branch, 'warnings' => []];
    }

    protected function http(): PendingRequest
    {
        $http = Http::acceptJson()->timeout(30);

        return $this->config('email')
            ? $http->withBasicAuth((string) $this->config('email'), (string) $this->config('token'))
            : $http->withToken((string) $this->config('token'));
    }
}
