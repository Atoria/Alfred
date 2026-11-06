<?php

namespace Atoria\Alfred\Tickets\Trackers;

use Atoria\Alfred\Tickets\Ticket;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * GitLab issues (gitlab.com or self-hosted). Screenshots are uploaded and
 * shown in the description. Can assign a project member and set an active milestone.
 */
class GitLabTracker extends BaseTracker implements Plannable
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

    public function create(Ticket $ticket): array
    {
        $images = [];
        $warnings = $this->uploadAll($ticket->files, function (array $file) use (&$images) {
            $res = $this->call("the upload {$file['filename']}", fn () => $this->http()
                ->attach('file', $file['contents'], $file['filename'], ['Content-Type' => $file['mime']])
                ->post($this->project().'/uploads'));
            $images[] = (string) $res->json('markdown');
        });

        $payload = [
            'title' => mb_substr($ticket->title, 0, 250),
            'description' => $ticket->body($images ? ["### Screenshots and files\n".implode("\n\n", $images)] : []),
        ];
        if ($labels = $this->labels()) {
            $payload['labels'] = implode(',', $labels);
        }
        if ($ticket->assignee) {
            $payload['assignee_ids'] = [(int) $ticket->assignee];
        }
        if ($ticket->destination) {
            $payload['milestone_id'] = (int) $ticket->destination;
        }

        $response = $this->call('the issue', fn () => $this->http()->post($this->project().'/issues', $payload));

        return ['key' => '#'.$response->json('iid'), 'url' => (string) $response->json('web_url'), 'warnings' => $warnings];
    }

    public function destinationLabel(): string
    {
        return 'Milestone';
    }

    public function planning(): array
    {
        $members = $this->call('the list of members', fn () => $this->http()->get($this->project().'/members/all', ['per_page' => 100]))->json();
        $milestones = $this->call('the list of milestones', fn () => $this->http()->get($this->project().'/milestones', ['state' => 'active', 'per_page' => 100]))->json();

        $assignees = [];
        foreach ((array) $members as $m) {
            if (($m['state'] ?? 'active') === 'active' && ! str_ends_with((string) ($m['username'] ?? ''), '_bot') && ($m['access_level'] ?? 30) >= 20) {
                $assignees[(string) $m['id']] = ['id' => (string) $m['id'], 'name' => (string) (($m['name'] ?? '') ?: $m['username'])];
            }
        }

        return [
            'assignees' => array_values($assignees),
            'destinations' => array_merge(
                [['id' => '', 'name' => 'No milestone', 'default' => true]],
                array_map(fn ($m) => ['id' => (string) $m['id'], 'name' => (string) $m['title'], 'hint' => ! empty($m['due_date']) ? 'due '.$m['due_date'] : null], (array) $milestones),
            ),
        ];
    }

    protected function http(): PendingRequest
    {
        return Http::withHeaders(['PRIVATE-TOKEN' => (string) $this->config('token')])->acceptJson()->timeout(30);
    }

    protected function project(): string
    {
        $base = rtrim((string) ($this->config('url') ?: 'https://gitlab.com'), '/');

        return $base.'/api/v4/projects/'.rawurlencode(trim((string) $this->config('project'), '/'));
    }
}
