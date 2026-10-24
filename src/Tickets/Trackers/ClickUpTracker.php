<?php

namespace Atoria\Alfred\Tickets\Trackers;

use Atoria\Alfred\Tickets\Ticket;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** ClickUp: a task in one list (Markdown description, tags, attachments). */
class ClickUpTracker extends BaseTracker
{
    protected const API = 'https://api.clickup.com/api/v2';

    protected array $required = ['token', 'list_id'];

    public function name(): string
    {
        return 'clickup';
    }

    public function label(): string
    {
        return 'ClickUp';
    }

    public function target(): ?string
    {
        return $this->config('list_name') ?: null;
    }

    public function create(Ticket $ticket): array
    {
        $task = ['name' => mb_substr($ticket->title, 0, 250), 'markdown_description' => $ticket->body()];
        if ($labels = $this->labels()) {
            $task['tags'] = $labels;
        }

        $response = $this->call('the task', fn () => $this->http()->post(self::API.'/list/'.rawurlencode((string) $this->config('list_id')).'/task', $task));
        $id = (string) $response->json('id');

        $warnings = $this->uploadAll($ticket->files, function (array $file) use ($id) {
            $this->call("the attachment {$file['filename']}", fn () => $this->http()
                ->attach('attachment', $file['contents'], $file['filename'], ['Content-Type' => $file['mime']])
                ->post(self::API.'/task/'.rawurlencode($id).'/attachment'));
        });

        return ['key' => (string) ($response->json('custom_id') ?: '#'.$id), 'url' => (string) $response->json('url'), 'warnings' => $warnings];
    }

    protected function http(): PendingRequest
    {
        return Http::withHeaders(['Authorization' => (string) $this->config('token')])->acceptJson()->timeout(30);
    }
}
