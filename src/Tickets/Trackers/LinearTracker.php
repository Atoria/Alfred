<?php

namespace Atoria\Alfred\Tickets\Trackers;

use Atoria\Alfred\Tickets\Ticket;
use Atoria\Alfred\Tickets\TrackerException;
use Illuminate\Support\Facades\Http;

/**
 * Linear (GraphQL). The team can be given by key ("ENG") or id. Screenshots
 * are uploaded to Linear's storage and shown in the description.
 */
class LinearTracker extends BaseTracker
{
    protected const API = 'https://api.linear.app/graphql';

    protected array $required = ['api_key', 'team'];

    public function name(): string
    {
        return 'linear';
    }

    public function label(): string
    {
        return 'Linear';
    }

    public function target(): ?string
    {
        return $this->config('team');
    }

    public function create(Ticket $ticket): array
    {
        $teamId = $this->teamId();

        $images = [];
        $warnings = $this->uploadAll($ticket->files, function (array $file) use (&$images) {
            $data = $this->graphql('the upload', 'mutation($contentType: String!, $filename: String!, $size: Int!) { fileUpload(contentType: $contentType, filename: $filename, size: $size) { success uploadFile { uploadUrl assetUrl headers { key value } } } }', [
                'contentType' => $file['mime'], 'filename' => $file['filename'], 'size' => strlen($file['contents']),
            ]);
            $upload = $data['fileUpload']['uploadFile'] ?? null;
            if (! $upload) {
                throw new TrackerException("Linear did not accept the upload {$file['filename']}.");
            }

            $headers = ['Content-Type' => $file['mime'], 'Cache-Control' => 'public, max-age=31536000'];
            foreach ((array) ($upload['headers'] ?? []) as $h) {
                $headers[$h['key']] = $h['value'];
            }
            $this->call("the upload {$file['filename']}", fn () => Http::withHeaders($headers)->timeout(60)
                ->withBody($file['contents'], $file['mime'])->put($upload['uploadUrl']));

            $images[] = str_starts_with($file['mime'], 'image/')
                ? '!['.$file['kind'].']('.$upload['assetUrl'].')'
                : '['.$file['filename'].']('.$upload['assetUrl'].')';
        });

        $input = [
            'teamId' => $teamId,
            'title' => mb_substr($ticket->title, 0, 250),
            'description' => $ticket->body($images ? ["### Screenshots and files\n".implode("\n\n", $images)] : []),
        ];
        $data = $this->graphql('the issue', 'mutation($input: IssueCreateInput!) { issueCreate(input: $input) { success issue { identifier url } } }', ['input' => $input]);
        $issue = $data['issueCreate']['issue'] ?? null;
        if (! $issue) {
            throw new TrackerException('Linear did not create the issue.');
        }

        return ['key' => (string) $issue['identifier'], 'url' => (string) $issue['url'], 'warnings' => $warnings];
    }

    /** The team's id, looking it up by key ("ENG") when a key was configured. */
    protected function teamId(): string
    {
        $team = trim((string) $this->config('team'));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $team)) {
            return $team;
        }

        $data = $this->graphql('the team lookup', 'query($key: String!) { teams(filter: { key: { eq: $key } }) { nodes { id } } }', ['key' => strtoupper($team)]);
        $id = $data['teams']['nodes'][0]['id'] ?? null;
        if (! $id) {
            throw new TrackerException("Linear has no team with the key \"{$team}\". Set ALFRED_LINEAR_TEAM to a team key or id.");
        }

        return $id;
    }

    protected function graphql(string $what, string $query, array $variables): array
    {
        $response = $this->call($what, fn () => Http::withHeaders(['Authorization' => (string) $this->config('api_key')])
            ->acceptJson()->timeout(30)
            ->post(self::API, ['query' => $query, 'variables' => $variables]));

        if ($errors = $response->json('errors')) {
            throw new TrackerException("Linear refused {$what}: ".($errors[0]['message'] ?? 'unknown error'));
        }

        return (array) $response->json('data', []);
    }
}
