<?php

namespace Atoria\Alfred\Tickets\Trackers;

use Atoria\Alfred\Tickets\Ticket;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * ClickUp: a task in a list (Markdown description, tags, attachments). The
 * list is ALFRED_CLICKUP_LIST_ID by default, or another list in the same
 * folder (sprint folders keep one list per sprint); a list member can be assigned.
 */
class ClickUpTracker extends BaseTracker implements Plannable
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
        if ($ticket->assignee) {
            $task['assignees'] = [(int) $ticket->assignee];
        }
        $list = $ticket->destination ?: (string) $this->config('list_id');

        $response = $this->call('the task', fn () => $this->http()->post(self::API.'/list/'.rawurlencode($list).'/task', $task));
        $id = (string) $response->json('id');

        $warnings = $this->uploadAll($ticket->files, function (array $file) use ($id) {
            $this->call("the attachment {$file['filename']}", fn () => $this->http()
                ->attach('attachment', $file['contents'], $file['filename'], ['Content-Type' => $file['mime']])
                ->post(self::API.'/task/'.rawurlencode($id).'/attachment'));
        });

        return ['key' => (string) ($response->json('custom_id') ?: '#'.$id), 'url' => (string) $response->json('url'), 'warnings' => $warnings];
    }

    public function destinationLabel(): string
    {
        return 'List / sprint';
    }

    public function planning(): array
    {
        $listId = (string) $this->config('list_id');
        $members = $this->call('the list of members', fn () => $this->http()->get(self::API.'/list/'.rawurlencode($listId).'/member'))->json('members', []);

        $lists = [];
        $home = $this->call('the list lookup', fn () => $this->http()->get(self::API.'/list/'.rawurlencode($listId)));
        $folder = $home->json('folder.id');
        if ($folder && ! $home->json('folder.hidden')) {
            $lists = (array) $this->call('the lists in the folder', fn () => $this->http()->get(self::API.'/folder/'.rawurlencode((string) $folder).'/list', ['archived' => 'false']))->json('lists', []);
        }
        if (! in_array($listId, array_map(fn ($l) => (string) $l['id'], $lists), true)) {
            array_unshift($lists, ['id' => $listId, 'name' => (string) ($home->json('name') ?: 'Default list')]);
        }

        return [
            'assignees' => array_map(fn ($m) => ['id' => (string) $m['id'], 'name' => (string) (($m['username'] ?? '') ?: ($m['email'] ?? ''))], (array) $members),
            'destinations' => array_map(fn ($l) => ['id' => (string) $l['id'], 'name' => (string) $l['name'], 'default' => (string) $l['id'] === $listId], $lists),
        ];
    }

    protected function http(): PendingRequest
    {
        return Http::withHeaders(['Authorization' => (string) $this->config('token')])->acceptJson()->timeout(30);
    }
}
