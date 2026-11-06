<?php

namespace Atoria\Alfred\Tickets\Trackers;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Tickets\Ticket;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * GitHub Issues (also GitHub Enterprise via api_url). GitHub's API cannot
 * upload images, so the issue links to the screenshots stored in Alfred.
 * Can assign a collaborator and set an open milestone.
 */
class GitHubTracker extends BaseTracker implements Plannable
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

    public function create(Ticket $ticket): array
    {
        $payload = ['title' => mb_substr($ticket->title, 0, 250), 'body' => $ticket->body([$ticket->screenshotLinks(Alfred::name())])];
        if ($labels = $this->labels()) {
            $payload['labels'] = $labels;
        }
        if ($ticket->assignee) {
            $payload['assignees'] = [$ticket->assignee];
        }
        if ($ticket->destination) {
            $payload['milestone'] = (int) $ticket->destination;
        }

        $response = $this->call('the issue', fn () => $this->http()->post($this->repoUrl().'/issues', $payload));

        $warnings = [];
        if ($ticket->images()) {
            $warnings[] = 'GitHub cannot take image uploads through its API, so the issue links to the screenshots in '.Alfred::name().' instead.';
        }

        return ['key' => '#'.$response->json('number'), 'url' => (string) $response->json('html_url'), 'warnings' => $warnings];
    }

    public function destinationLabel(): string
    {
        return 'Milestone';
    }

    public function planning(): array
    {
        $people = $this->call('the list of assignees', fn () => $this->http()->get($this->repoUrl().'/assignees', ['per_page' => 100]))->json();
        $milestones = $this->call('the list of milestones', fn () => $this->http()->get($this->repoUrl().'/milestones', ['state' => 'open', 'sort' => 'due_on', 'per_page' => 100]))->json();

        return [
            'assignees' => array_map(fn ($u) => ['id' => (string) $u['login'], 'name' => (string) $u['login']], (array) $people),
            'destinations' => array_merge(
                [['id' => '', 'name' => 'No milestone', 'default' => true]],
                array_map(fn ($m) => ['id' => (string) $m['number'], 'name' => (string) $m['title'], 'hint' => ! empty($m['due_on']) ? 'due '.substr((string) $m['due_on'], 0, 10) : null], (array) $milestones),
            ),
        ];
    }

    protected function http(): PendingRequest
    {
        return Http::withToken((string) $this->config('token'))
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(30);
    }

    protected function repoUrl(): string
    {
        return rtrim((string) ($this->config('api_url') ?: 'https://api.github.com'), '/').'/repos/'.trim((string) $this->config('repo'), '/');
    }
}
