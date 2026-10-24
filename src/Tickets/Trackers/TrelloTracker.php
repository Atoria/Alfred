<?php

namespace Atoria\Alfred\Tickets\Trackers;

use Atoria\Alfred\Tickets\Ticket;
use Illuminate\Support\Facades\Http;

/** Trello: a card on one list, with the screenshots attached. */
class TrelloTracker extends BaseTracker
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
            'idList' => $this->config('list_id'),
            'name' => mb_substr($ticket->title, 0, 250),
            'desc' => mb_substr($ticket->body(), 0, 16000),
            'pos' => 'top',
        ];
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

    protected function auth(): string
    {
        return http_build_query(['key' => $this->config('key'), 'token' => $this->config('token')]);
    }
}
