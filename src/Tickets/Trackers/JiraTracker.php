<?php

namespace Atoria\Alfred\Tickets\Trackers;

use Atoria\Alfred\Tickets\JiraException;
use Atoria\Alfred\Tickets\Ticket;
use Atoria\Alfred\Tickets\TrackerException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Jira Cloud (REST v3): rich description (ADF) and file attachments. Can
 * assign the issue and put it in an active or future sprint of the
 * project's Scrum board (Agile REST); "Backlog" leaves it where Jira puts it.
 */
class JiraTracker extends BaseTracker implements Plannable
{
    protected array $required = ['url', 'email', 'token', 'project'];

    public function name(): string
    {
        return 'jira';
    }

    public function label(): string
    {
        return 'Jira';
    }

    public function target(): ?string
    {
        return $this->config('project');
    }

    /** Also reads an older published config, where these lived under alfred.jira. */
    protected function config(string $key, mixed $default = null): mixed
    {
        return config("alfred.trackers.jira.{$key}") ?? config("alfred.jira.{$key}", $default);
    }

    public function create(Ticket $ticket): array
    {
        $fields = [
            'project' => ['key' => $this->config('project')],
            'summary' => mb_substr($ticket->title, 0, 250),
            'issuetype' => ['name' => $this->config('issue_type') ?: 'Task'],
            'description' => $ticket->adf,
        ];
        if ($labels = $this->labels()) {
            $fields['labels'] = array_map(fn ($l) => preg_replace('/\s+/', '-', $l), $labels);
        }
        if ($ticket->assignee) {
            $fields['assignee'] = ['accountId' => $ticket->assignee];
        }

        try {
            $response = $this->http()->post($this->base().'/rest/api/3/issue', ['fields' => $fields]);
        } catch (ConnectionException $e) {
            throw new JiraException('Could not reach Jira: '.$e->getMessage(), 0, $e);
        }
        if ($response->failed()) {
            $errors = array_merge((array) $response->json('errorMessages', []), array_map(
                fn ($k, $v) => "{$k}: {$v}",
                array_keys((array) $response->json('errors', [])),
                (array) $response->json('errors', []),
            ));
            throw new JiraException('Jira refused the ticket ('.$response->status().'): '.(implode('; ', $errors) ?: mb_substr($response->body(), 0, 300)));
        }

        $key = (string) $response->json('key');

        $warnings = $this->uploadAll($ticket->files, function (array $file) use ($key) {
            $this->call("the attachment {$file['filename']}", fn () => $this->http()
                ->withHeaders(['X-Atlassian-Token' => 'no-check'])
                ->attach('file', $file['contents'], $file['filename'], ['Content-Type' => $file['mime']])
                ->post($this->base().'/rest/api/3/issue/'.rawurlencode($key).'/attachments'));
        });

        if ($ticket->destination) {
            try {
                $this->call('moving the issue to the sprint', fn () => $this->http()
                    ->post($this->base().'/rest/agile/1.0/sprint/'.rawurlencode($ticket->destination).'/issue', ['issues' => [$key]]));
            } catch (TrackerException $e) {
                report($e);
                $warnings[] = "{$key} was created in the backlog: ".$e->getMessage();
            }
        }

        return ['key' => $key, 'url' => $this->base().'/browse/'.$key, 'warnings' => $warnings];
    }

    public function destinationLabel(): string
    {
        return 'Sprint';
    }

    public function planning(): array
    {
        $project = (string) $this->config('project');

        $people = $this->call('the list of assignable people', fn () => $this->http()
            ->get($this->base().'/rest/api/3/user/assignable/search', ['project' => $project, 'maxResults' => 200]))->json();
        $assignees = [];
        foreach ((array) $people as $p) {
            if (($p['accountType'] ?? 'atlassian') === 'atlassian' && ($p['active'] ?? true)) {
                $assignees[] = ['id' => (string) ($p['accountId'] ?? ''), 'name' => (string) ($p['displayName'] ?? '')];
            }
        }

        $destinations = [['id' => '', 'name' => 'Backlog', 'default' => true]];
        try {
            $sprints = $this->sprints();
        } catch (TrackerException $e) {
            report($e); // no Jira Software / board access: people can still be assigned
            $sprints = [];
        }
        foreach ($sprints as $sprint) {
            $destinations[] = [
                'id' => (string) $sprint['id'],
                'name' => (string) $sprint['name'],
                'hint' => ($sprint['state'] ?? '') === 'active' ? 'active' : 'future',
            ];
        }

        return ['assignees' => $assignees, 'destinations' => $destinations];
    }

    /** Active and future sprints of the configured board, or the project's first Scrum board. */
    protected function sprints(): array
    {
        $board = trim((string) $this->config('board'));
        if ($board === '') {
            $boards = $this->call('the list of boards', fn () => $this->http()
                ->get($this->base().'/rest/agile/1.0/board', ['projectKeyOrId' => $this->config('project'), 'type' => 'scrum', 'maxResults' => 1]));
            $board = (string) ($boards->json('values.0.id') ?? '');
            if ($board === '') {
                return []; // Kanban only: no sprints, everything goes to the backlog
            }
        }

        $sprints = $this->call('the list of sprints', fn () => $this->http()
            ->get($this->base().'/rest/agile/1.0/board/'.rawurlencode($board).'/sprint', ['state' => 'active,future', 'maxResults' => 50]));

        $list = (array) $sprints->json('values', []);
        usort($list, fn ($a, $b) => [($a['state'] ?? '') !== 'active', $a['startDate'] ?? '9', $a['id'] ?? 0] <=> [($b['state'] ?? '') !== 'active', $b['startDate'] ?? '9', $b['id'] ?? 0]);

        return $list;
    }

    protected function http(): PendingRequest
    {
        return Http::withBasicAuth((string) $this->config('email'), (string) $this->config('token'))->acceptJson()->timeout(30);
    }

    protected function base(): string
    {
        return rtrim((string) $this->config('url'), '/');
    }
}
