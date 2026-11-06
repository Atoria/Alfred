<?php

namespace Atoria\Alfred\Tickets\Trackers;

use Atoria\Alfred\Tickets\TrackerException;

/**
 * A tracker that can assign the ticket to someone and put it in a sprint,
 * list, cycle, milestone or section. The ticket form then shows "Assign to"
 * and "Sprint" (or whatever destinationLabel() says) dropdowns.
 *
 * Ticket::$assignee and Ticket::$destination carry the picked ids into
 * create(); both are checked against planning() before they get there.
 */
interface Plannable
{
    /** What the second dropdown is called, e.g. "Sprint", "List", "Milestone". */
    public function destinationLabel(): string;

    /**
     * People who can be assigned and places the ticket can go. Ids are the
     * tracker's own. One destination may be marked default (otherwise the
     * first one is); an id of "" means "the tracker's default place"
     * (e.g. the Jira backlog).
     *
     * @return array{
     *     assignees: array<int, array{id: string, name: string}>,
     *     destinations: array<int, array{id: string, name: string, hint?: string, default?: bool}>
     * }
     *
     * @throws TrackerException
     */
    public function planning(): array;
}
