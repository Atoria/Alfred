<?php

namespace Atoria\Alfred\Tickets;

use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Context\CodeLocator;
use Illuminate\Support\Str;

/**
 * Turns a change into a ticket: an editable title + description (plain
 * text) and fixed details (page, environment, status, files, diff,
 * screenshots) rendered as Jira ADF or as Markdown for copying.
 */
class TicketBuilder
{
    public const MAX_DIFF = 20000;

    public function __construct(protected Screenshots $screenshots) {}

    /** @return array{title: string, description: string} */
    public function draft(Change $change): array
    {
        $who = $change->requested_by ?: 'Someone';
        $where = $this->pagePath($change);

        if ($change->status === Change::REQUESTED) {
            $element = $this->elementLabel($change);

            return [
                'title' => Str::limit(Str::ucfirst(trim(Str::before($change->instruction, "\n"))), 240, '…'),
                'description' => "{$who} reported on {$where}".($element ? " ({$element})" : '').":\n“".trim($change->instruction).'”',
            ];
        }

        $description = "{$who} asked for a UI change on {$where}:\n“".trim($change->instruction).'”';
        if ($change->summary) {
            $description .= "\n\nProposed change: ".$change->summary.'.';
        }
        if ($change->notes) {
            $description .= "\n\n".trim($change->notes);
        }
        $description .= "\n\n".$this->statusSentence($change);

        return [
            'title' => Str::limit($change->summary ?: Str::ucfirst(trim($change->instruction)), 240, '…'),
            'description' => $description,
        ];
    }

    /** @param  string[]  $attached screenshot kinds that will be attached */
    public function adf(Change $change, string $description, bool $includeDiff, array $attached): array
    {
        $blocks = Adf::paragraphs($description);
        $blocks[] = Adf::heading('Details');
        $blocks[] = Adf::bullets($this->detailItems($change, true));

        if ($includeDiff && ($where = CodeLocator::lines($change->location))) {
            $blocks[] = Adf::heading('Where in the code');
            $blocks[] = Adf::bullets(array_map(fn ($l) => [$l], $where));
        }

        if ($includeDiff && $change->diff) {
            $blocks[] = Adf::heading('Code change');
            $blocks[] = Adf::code($this->diff($change));
        }

        if ($attached) {
            $blocks[] = Adf::paragraph(['Screenshots attached: '.implode(', ', array_map(fn ($k) => "{$k}", $attached)).'.']);
        }

        $blocks[] = Adf::rule();
        $blocks[] = Adf::paragraph(['Created with '.\Atoria\Alfred\Alfred::name().' from change #'.$change->id.'.']);

        return Adf::doc($blocks);
    }

    /**
     * Everything a tracker needs, rendered once.
     *
     * @param  string[]  $kinds  screenshot kinds to attach
     */
    public function ticket(Change $change, string $title, string $description, bool $includeDiff, array $kinds): Ticket
    {
        $files = [];
        $urls = [];
        foreach ($kinds as $kind) {
            if ($shot = $this->screenshots->get($change, $kind)) {
                [$contents, $mime, $filename] = $shot;
                $files[] = ['kind' => $kind, 'filename' => $filename, 'contents' => $contents, 'mime' => $mime];
                $urls[$kind] = route('alfred.changes.screenshots.show', ['change' => $change->id, 'kind' => $kind]);
            }
        }
        if ($includeDiff && $change->diff && strlen((string) $change->diff) > self::MAX_DIFF) {
            $files[] = ['kind' => 'patch', 'filename' => "alfred-change-{$change->id}.patch", 'contents' => (string) $change->diff, 'mime' => 'text/x-diff'];
        }

        return new Ticket(
            title: trim($title),
            description: trim($description),
            markdown: trim($description)."\n\n".$this->markdownDetails($change, $includeDiff),
            footer: "---\n_Created with ".\Atoria\Alfred\Alfred::name()." from change #{$change->id}._",
            adf: $this->adf($change, $description, $includeDiff, array_keys($urls)),
            files: $files,
            screenshotUrls: $urls,
            labels: (array) config('alfred.tickets.labels', []),
            changeId: (int) $change->id,
        );
    }

    /** Fixed details as Markdown (for trackers that use Markdown, and for "Copy ticket text"). */
    public function markdownDetails(Change $change, bool $includeDiff): string
    {
        $md = "### Details\n";
        foreach ($this->detailItems($change, false) as $item) {
            $md .= '- '.implode('', array_map(fn ($n) => is_string($n) ? $n : ($n['text'] ?? ''), array_filter($item)))."\n";
        }
        if ($includeDiff && ($where = CodeLocator::lines($change->location))) {
            $md .= "\n### Where in the code\n".implode('', array_map(fn ($l) => "- {$l}\n", $where));
        }
        if ($includeDiff && $change->diff) {
            $md .= "\n### Code change\n```diff\n".rtrim($this->diff($change))."\n```\n";
        }

        return $md;
    }

    protected function detailItems(Change $change, bool $links): array
    {
        $items = [];
        if ($change->url) {
            $items[] = ['Page: ', $links ? Adf::text($change->url, $change->url) : $change->url];
        }
        $items[] = ['Environment: '.$change->environment];
        $items[] = ['Status: '.($change->status === Change::REQUESTED ? 'Request (no change proposed)' : $this->statusLabel($change))];
        if ($change->requested_by) {
            $items[] = ['Requested by: '.$change->requested_by];
        }
        if ($change->paths()) {
            $items[] = ['Files: '.implode(', ', $change->paths())];
        }
        if ($label = $this->elementLabel($change)) {
            $items[] = ['Element: '.$label];
        }

        return $items;
    }

    protected function elementLabel(Change $change): ?string
    {
        $element = (array) $change->element;
        $label = trim(($element['column'] ?? '').' '.implode(' / ', array_slice((array) ($element['labels'] ?? []), 0, 2)));

        return $label !== '' ? Str::limit($label, 140, '…') : null;
    }

    protected function statusSentence(Change $change): string
    {
        return match ($change->status) {
            Change::REQUESTED => 'No code change has been proposed yet.',
            Change::APPLIED => "It has already been applied on {$change->environment}".($change->applied_by ? " by {$change->applied_by}" : '').' — this ticket is to review it and bring it into the codebase.',
            Change::REVERTED => "It was tried on {$change->environment} and then undone.",
            Change::DISCARDED => 'The proposal was discarded; this ticket records the request.',
            default => 'It has not been applied yet.',
        };
    }

    protected function statusLabel(Change $change): string
    {
        $label = ucfirst($change->status);
        if ($change->status === Change::APPLIED && $change->applied_at) {
            $label .= ' '.$change->applied_at->toDayDateTimeString();
        }

        return $label;
    }

    protected function diff(Change $change): string
    {
        $diff = (string) $change->diff;

        return strlen($diff) > self::MAX_DIFF ? substr($diff, 0, self::MAX_DIFF)."\n… (truncated — see the attached patch)" : $diff;
    }

    protected function pagePath(Change $change): string
    {
        $path = $change->url ? parse_url($change->url, PHP_URL_PATH) : null;

        return $path ?: ($change->route ?: 'a page');
    }
}
