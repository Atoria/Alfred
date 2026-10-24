<?php

namespace Atoria\Alfred\PullRequests;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Context\CodeLocator;
use Illuminate\Support\Str;

/** Turns a change (and its ticket, if any) into a pull request draft. */
class PullRequestBuilder
{
    public function draft(Change $change, ?string $title = null): PullRequestDraft
    {
        $ticketKey = $change->ticket_key;
        $base = trim((string) config('alfred.pull_requests.base', ''));
        $title = trim((string) ($title ?: $this->defaultTitle($change)));

        $prefix = trim((string) config('alfred.pull_requests.path_prefix', ''), '/');
        $edits = [];
        foreach ((array) $change->edits as $edit) {
            $path = ($prefix !== '' ? $prefix.'/' : '').ltrim((string) $edit['path'], '/');
            $edits[$path][] = ['search' => (string) $edit['search'], 'replace' => (string) $edit['replace']];
        }

        return new PullRequestDraft(
            title: $title,
            body: $this->body($change),
            branch: $this->branch($change),
            commitMessage: $title."\n\n".'Requested by '.($change->requested_by ?: 'someone').' with '.Alfred::name()." (change #{$change->id}).".($ticketKey ? "\nTicket: {$ticketKey}" : ''),
            edits: $edits,
            base: $base !== '' ? $base : null,
            draft: (bool) config('alfred.pull_requests.draft', true),
            labels: array_values(array_filter(array_map('trim', (array) config('alfred.pull_requests.labels', [])))),
            changeId: (int) $change->id,
        );
    }

    /** "PROJ-42: Bold the orders total" — the ticket key first, so Jira and Linear link them. */
    public function defaultTitle(Change $change): string
    {
        $title = Str::limit($change->summary ?: Str::ucfirst(trim((string) $change->instruction)), 200, '…');
        $key = $change->ticket_key;

        return $key && ! str_starts_with($key, '#') && ! str_starts_with($key, '!') ? "{$key}: {$title}" : $title;
    }

    public function branch(Change $change): string
    {
        $prefix = (string) config('alfred.pull_requests.branch_prefix', 'alfred/');
        $key = $change->ticket_key && ! str_starts_with($change->ticket_key, '#') ? Str::slug($change->ticket_key).'-' : '';
        $slug = Str::limit(Str::slug($change->summary ?: (string) $change->instruction), 40, '');

        return $prefix.$key.$change->id.($slug !== '' ? '-'.rtrim($slug, '-') : '');
    }

    public function body(Change $change): string
    {
        $who = $change->requested_by ?: 'Someone';
        $page = $change->url ?: ($change->route ?: 'a page');
        $lines = [];

        $lines[] = "**{$who}** asked for this on [{$page}]({$page})".($change->environment ? " ({$change->environment})" : '').':';
        $lines[] = '> '.str_replace("\n", "\n> ", trim((string) $change->instruction));
        if ($change->summary) {
            $lines[] = '**Proposed change:** '.$change->summary.'.';
        }
        if ($change->notes) {
            $lines[] = trim((string) $change->notes);
        }

        if ($change->ticket_key) {
            $ticket = $change->ticket_url ? "[{$change->ticket_key}]({$change->ticket_url})" : $change->ticket_key;
            $lines[] = '**Ticket:** '.$ticket;
            // Same platform: let it close the issue when merged.
            $provider = (array) config('alfred.pull_requests.providers', []);
            if (in_array($change->ticket_provider, ['github', 'gitlab'], true) && str_starts_with($change->ticket_key, '#') && in_array($change->ticket_provider, $provider, true)) {
                $lines[] = "Closes {$change->ticket_key}";
            }
        }

        $files = array_map(fn ($p) => "- `{$p}`", $change->paths());
        if ($files) {
            $lines[] = "### Files\n".implode("\n", $files);
        }
        if ($where = CodeLocator::lines($change->location)) {
            $lines[] = "### Where on the page\n".implode("\n", array_map(fn ($l) => "- {$l}", $where));
        }

        $shots = [];
        foreach (array_keys((array) $change->screenshots) as $kind) {
            $shots[] = '- ['.($kind === 'before' ? 'Before (when requested)' : 'Current view').']('.route('alfred.changes.screenshots.show', ['change' => $change->id, 'kind' => $kind]).')';
        }
        if ($shots) {
            $lines[] = "### Screenshots\n_Stored in ".Alfred::name()." (sign in to view):_\n".implode("\n", $shots);
        }

        $lines[] = "---\n_Opened by ".Alfred::name()." from change #{$change->id}. The code was written by AI: review it before merging._";

        return implode("\n\n", $lines);
    }
}
