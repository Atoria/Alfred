<?php

namespace Atoria\Alfred\Tickets\Trackers;

use Atoria\Alfred\Tickets\Ticket;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Asana: a task in one project, with the screenshots attached. */
class AsanaTracker extends BaseTracker
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
        $response = $this->call('the task', fn () => $this->http()->post(self::API.'/tasks', ['data' => [
            'name' => mb_substr($ticket->title, 0, 250),
            'notes' => $ticket->body(),
            'projects' => [(string) $this->config('project')],
        ]]));
        $gid = (string) $response->json('data.gid');

        $warnings = $this->uploadAll($ticket->files, function (array $file) use ($gid) {
            $this->call("the attachment {$file['filename']}", fn () => $this->http()
                ->attach('file', $file['contents'], $file['filename'], ['Content-Type' => $file['mime']])
                ->post(self::API.'/attachments', ['parent' => $gid]));
        });

        return ['key' => '#'.$gid, 'url' => (string) $response->json('data.permalink_url'), 'warnings' => $warnings];
    }

    protected function http(): PendingRequest
    {
        return Http::withToken((string) $this->config('token'))->acceptJson()->timeout(30);
    }
}
