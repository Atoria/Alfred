<?php

namespace Atoria\Alfred\PullRequests\Providers;

use Atoria\Alfred\PullRequests\PullRequestDraft;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** GitLab (gitlab.com or self-hosted): one commit on a new branch, then a merge request. */
class GitLabProvider extends BaseGitProvider
{
    protected array $required = ['token', 'project'];

    public function name(): string
    {
        return 'gitlab';
    }

    public function label(): string
    {
        return 'GitLab';
    }

    public function target(): ?string
    {
        return $this->config('project');
    }

    public function noun(): string
    {
        return 'merge request';
    }

    public function open(PullRequestDraft $draft): array
    {
        $project = $this->project();
        $base = $draft->base ?: (string) $this->call('the project lookup', fn () => $this->http()->get($project))->json('default_branch');

        $actions = [];
        foreach ($draft->paths() as $path) {
            $res = $this->call("the file {$path}", fn () => $this->http()->get("{$project}/repository/files/".rawurlencode($path).'/raw', ['ref' => $base]), [404]);
            if ($res->status() === 404) {
                throw $this->missingFile($path);
            }
            $actions[] = ['action' => 'update', 'file_path' => $path, 'content' => $draft->apply($path, $res->body())];
        }

        // force: a branch left over from an earlier failed attempt is ours, start it again from the base.
        $this->call('the commit', fn () => $this->http()->post("{$project}/repository/commits", [
            'branch' => $draft->branch, 'start_branch' => $base, 'commit_message' => $draft->commitMessage, 'actions' => $actions, 'force' => true,
        ]));

        $payload = [
            'source_branch' => $draft->branch,
            'target_branch' => $base,
            'title' => ($draft->draft ? 'Draft: ' : '').mb_substr($draft->title, 0, 240),
            'description' => $draft->body,
            'remove_source_branch' => true,
        ];
        if ($draft->labels) {
            $payload['labels'] = implode(',', $draft->labels);
        }
        $mr = $this->call('the merge request', fn () => $this->http()->post("{$project}/merge_requests", $payload));

        return ['key' => '!'.$mr->json('iid'), 'url' => (string) $mr->json('web_url'), 'branch' => $draft->branch, 'warnings' => []];
    }

    protected function http(): PendingRequest
    {
        return Http::withHeaders(['PRIVATE-TOKEN' => (string) $this->config('token')])->acceptJson()->timeout(30);
    }

    protected function project(): string
    {
        return rtrim((string) ($this->config('url') ?: 'https://gitlab.com'), '/').'/api/v4/projects/'.rawurlencode(trim((string) $this->config('project'), '/'));
    }
}
