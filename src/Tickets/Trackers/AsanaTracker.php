<?php

namespace Atoria\Alfred\Tickets\Trackers;

use Atoria\Alfred\Tickets\Ticket;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Asana: a task in one project (optionally in a section, assigned to a project member), with the screenshots attached. */
class AsanaTracker extends BaseTracker implements Plannable
{
    protected const API = 'https://app.asana.com/api/1.0';

    protected array $required = ['token', 'project'];

    public function name(): string
    {
        return 'asana';
    }

    public function label(): string
    {
        return 'Asana';
    }

    public function target(): ?string
    {
        return $this->config('project_name') ?: null;
    }

    public function create(Ticket $ticket): array
    {
        // Asana's "notes" are plain text; the Markdown stays readable as-is.
        $data = [
            'name' => mb_substr($ticket->title, 0, 250),
            'notes' => $ticket->body(),
            'projects' => [(string) $this->config('project')],
        ];
        if ($ticket->assignee) {
            $data['assignee'] = $ticket->assignee;
        }
        if ($ticket->destination) {
            unset($data['projects']);
            $data['memberships'] = [['project' => (string) $this->config('project'), 'section' => $ticket->destination]];
        }
        $response = $this->call('the task', fn () => $this->http()->post(self::API.'/tasks', ['data' => $data]));
        $gid = (string) $response->json('data.gid');

        $warnings = $this->uploadAll($ticket->files, function (array $file) use ($gid) {
            $this->call("the attachment {$file['filename']}", fn () => $this->http()
                ->attach('file', $file['contents'], $file['filename'], ['Content-Type' => $file['mime']])
                ->post(self::API.'/attachments', ['parent' => $gid]));
        });

        return ['key' => '#'.$gid, 'url' => (string) $response->json('data.permalink_url'), 'warnings' => $warnings];
    }

    public function destinationLabel(): string
    {
        return 'Section';
    }

    public function planning(): array
    {
        $project = rawurlencode((string) $this->config('project'));
        $members = $this->call('the list of project members', fn () => $this->http()->get(self::API.'/projects/'.$project, ['opt_fields' => 'members.name']))->json('data.members', []);
        $sections = $this->call('the list of sections', fn () => $this->http()->get(self::API.'/projects/'.$project.'/sections', ['opt_fields' => 'name']))->json('data', []);

        return [
            'assignees' => array_map(fn ($m) => ['id' => (string) $m['gid'], 'name' => (string) ($m['name'] ?? '')], (array) $members),
            'destinations' => array_merge(
                [['id' => '', 'name' => 'Project default', 'default' => true]],
                array_map(fn ($s) => ['id' => (string) $s['gid'], 'name' => (string) $s['name']], (array) $sections),
            ),
        ];
    }

    protected function http(): PendingRequest
    {
        return Http::withToken((string) $this->config('token'))->acceptJson()->timeout(30);
    }
}
