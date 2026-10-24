<?php

namespace Atoria\Alfred\Tickets\Trackers;

use Atoria\Alfred\Tickets\Ticket;

/**
 * A place tickets go: Jira, GitHub Issues, Trello, … Implement this to add
 * your own and register it in config('alfred.tickets.custom').
 */
interface Tracker
{
    /** Short key used in config and the API, e.g. "jira". */
    public function name(): string;

    /** What people see, e.g. "Jira". */
    public function label(): string;

    /** Are all required settings present? */
    public function configured(): bool;

    /** Where tickets land, shown on the button, e.g. "PROJ" or "acme/app". */
    public function target(): ?string;

    /**
     * Create the ticket (and upload its files where the tracker supports it).
     *
     * @return array{key: string, url: string, warnings?: string[]}
     *
     * @throws \Atoria\Alfred\Tickets\TrackerException
     */
    public function create(Ticket $ticket): array;
}
