<?php

namespace Atoria\Alfred\Tickets\Trackers;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Tickets\Ticket;
use Illuminate\Support\Facades\Http;

/**
 * GitHub Issues (also GitHub Enterprise via api_url). GitHub's API cannot
 * upload images, so the issue links to the screenshots stored in Alfred.
 */
class GitHubTracker extends BaseTracker
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

        $repo = trim((string) $this->config('repo'), '/');
        $response = $this->call('the issue', fn () => Http::withToken((string) $this->config('token'))
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(30)
            ->post(rtrim((string) ($this->config('api_url') ?: 'https://api.github.com'), '/')."/repos/{$repo}/issues", $payload));

        $warnings = [];
        if ($ticket->images()) {
            $warnings[] = 'GitHub cannot take image uploads through its API, so the issue links to the screenshots in '.Alfred::name().' instead.';
        }

        return ['key' => '#'.$response->json('number'), 'url' => (string) $response->json('html_url'), 'warnings' => $warnings];
    }
}
