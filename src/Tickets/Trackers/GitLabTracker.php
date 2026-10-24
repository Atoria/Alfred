<?php

namespace Atoria\Alfred\Tickets\Trackers;

use Atoria\Alfred\Tickets\Ticket;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** GitLab issues (gitlab.com or self-hosted). Screenshots are uploaded and shown in the description. */
class GitLabTracker extends BaseTracker
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

        $response = $this->call('the issue', fn () => $this->http()->post($this->project().'/issues', $payload));

        return ['key' => '#'.$response->json('iid'), 'url' => (string) $response->json('web_url'), 'warnings' => $warnings];
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
