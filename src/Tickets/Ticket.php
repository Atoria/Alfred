<?php

namespace Atoria\Alfred\Tickets;

/**
 * Everything a tracker needs to create one ticket, already rendered.
 *
 * Trackers that speak Markdown use body(); Jira uses $adf. Files are the
 * ticked screenshots (plus the full patch when the diff is too long to
 * inline). Trackers that cannot upload files link to $screenshotUrls instead.
 */
final class Ticket
{
    /**
     * @param  string[]  $labels
     * @param  array<int, array{kind: string, filename: string, contents: string, mime: string}>  $files
     * @param  array<string, string>  $screenshotUrls  kind => URL of the screenshot in Alfred (needs sign-in)
     */
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $markdown,
        public readonly string $footer,
        public readonly array $adf,
        public readonly array $files,
        public readonly array $screenshotUrls,
        public readonly array $labels,
        public readonly int $changeId,
    ) {}

    /** Markdown body; $sections (already Markdown) go before the footer, e.g. embedded images. */
    public function body(array $sections = []): string
    {
        $parts = array_filter([trim($this->markdown), ...array_map('trim', $sections), trim($this->footer)], fn ($p) => $p !== '');

        return implode("\n\n", $parts);
    }

    /** @return array<int, array{kind: string, filename: string, contents: string, mime: string}> */
    public function images(): array
    {
        return array_values(array_filter($this->files, fn ($f) => str_starts_with($f['mime'], 'image/')));
    }

    /** Markdown list of links to the screenshots, for trackers that cannot take uploads. */
    public function screenshotLinks(string $appName): string
    {
        if (! $this->screenshotUrls) {
            return '';
        }
        $lines = ['### Screenshots', "_Stored in {$appName} (sign in to view):_"];
        foreach ($this->screenshotUrls as $kind => $url) {
            $lines[] = '- ['.($kind === 'before' ? 'Before (when requested)' : 'Current view').']('.$url.')';
        }

        return implode("\n", $lines);
    }
}
