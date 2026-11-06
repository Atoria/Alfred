<?php

namespace Atoria\Alfred\Tickets\Trackers;

use Atoria\Alfred\Tickets\Ticket;
use Illuminate\Support\Facades\Http;

/**
 * Trello: a card on a list (ALFRED_TRELLO_LIST_ID by default, or another
 * open list on the same board), with the screenshots attached and,
 * optionally, a board member assigned.
 */
class TrelloTracker extends BaseTracker implements Plannable
{
    protected const API = 'https://api.trello.com/1';

    protected array $required = ['key', 'token', 'list_id'];

    public function name(): string
    {
        return 'trello';
    }

    public function label(): string
    {
        return 'Trello';
    }

    public function target(): ?string
    {
        return $this->config('list_name') ?: null;
    }

    public function create(Ticket $ticket): array
    {
        $card = [
            'idList' => $ticket->destination ?: $this->config('list_id'),
            'name' => mb_substr($ticket->title, 0, 250),
            'desc' => mb_substr($ticket->body(), 0, 16000),
            'pos' => 'top',
        ];
        if ($ticket->assignee) {
            $card['idMembers'] = $ticket->assignee;
        }
        if ($labels = array_filter((array) $this->config('label_ids', []))) {
            $card['idLabels'] = implode(',', $labels);
        }

        $response = $this->call('the card', fn () => Http::acceptJson()->timeout(30)->asForm()
            ->post(self::API.'/cards?'.$this->auth(), $card));
        $id = (string) $response->json('id');

        $warnings = $this->uploadAll($ticket->files, function (array $file) use ($id) {
            $this->call("the attachment {$file['filename']}", fn () => Http::acceptJson()->timeout(60)
                ->attach('file', $file['contents'], $file['filename'], ['Content-Type' => $file['mime']])
                ->post(self::API.'/cards/'.$id.'/attachments?'.$this->auth(), ['name' => $file['filename']]));
        });

        return ['key' => '#'.($response->json('idShort') ?? $id), 'url' => (string) ($response->json('shortUrl') ?: $response->json('url')), 'warnings' => $warnings];
    }

    public function destinationLabel(): string
    {
        return 'List';
    }

    public function planning(): array
    {
        $board = (string) $this->call('the board lookup', fn () => Http::acceptJson()->timeout(30)
            ->get(self::API.'/lists/'.rawurlencode((string) $this->config('list_id')).'/board?fields=id&'.$this->auth()))->json('id');

        $members = $this->call('the list of board members', fn () => Http::acceptJson()->timeout(30)
            ->get(self::API.'/boards/'.rawurlencode($board).'/members?fields=fullName,username&'.$this->auth()))->json();
        $lists = $this->call('the lists of the board', fn () => Http::acceptJson()->timeout(30)
            ->get(self::API.'/boards/'.rawurlencode($board).'/lists?filter=open&fields=name&'.$this->auth()))->json();

        return [
            'assignees' => array_map(fn ($m) => ['id' => (string) $m['id'], 'name' => (string) (($m['fullName'] ?? '') ?: ($m['username'] ?? ''))], (array) $members),
            'destinations' => array_map(fn ($l) => ['id' => (string) $l['id'], 'name' => (string) $l['name'], 'default' => $l['id'] === $this->config('list_id')], (array) $lists),
        ];
    }

    protected function auth(): string
    {
        return http_build_query(['key' => $this->config('key'), 'token' => $this->config('token')]);
    }
}
