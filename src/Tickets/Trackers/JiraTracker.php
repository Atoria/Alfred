<?php

namespace Atoria\Alfred\Tickets\Trackers;

use Atoria\Alfred\Tickets\JiraException;
use Atoria\Alfred\Tickets\Ticket;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Jira Cloud (REST v3): rich description (ADF) and file attachments. */
class JiraTracker extends BaseTracker
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

        return ['key' => $key, 'url' => $this->base().'/browse/'.$key, 'warnings' => $warnings];
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
