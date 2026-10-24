<?php

namespace Atoria\Alfred\Tickets\Trackers;

use InvalidArgumentException;

/** Knows every tracker and which ones this app offers (ALFRED_TICKETS). */
class TrackerManager
{
    public const BUILT_IN = [
        'jira' => JiraTracker::class,
        'github' => GitHubTracker::class,
        'gitlab' => GitLabTracker::class,
        'linear' => LinearTracker::class,
        'trello' => TrelloTracker::class,
        'clickup' => ClickUpTracker::class,
        'asana' => AsanaTracker::class,
    ];

    /** @var array<string, Tracker> */
    protected array $resolved = [];

    /** @return array<string, class-string<Tracker>> */
    public function all(): array
    {
        return array_merge(self::BUILT_IN, (array) config('alfred.tickets.custom', []));
    }

    public function get(string $name): Tracker
    {
        $class = $this->all()[$name] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException("Unknown ticket tracker [{$name}].");
        }

        return $this->resolved[$name] ??= app($class);
    }

    /** Names listed in ALFRED_TICKETS (or "jira" when only the old ALFRED_JIRA=true is set). @return string[] */
    public function listed(): array
    {
        $names = array_values(array_filter(array_map(fn ($n) => strtolower(trim((string) $n)), (array) config('alfred.tickets.trackers', [])), fn ($n) => $n !== ''));
        if ($names === [] && (config('alfred.trackers.jira.enabled') || config('alfred.jira.enabled'))) {
            $names = ['jira'];
        }

        return array_values(array_unique(array_filter($names, fn ($n) => isset($this->all()[$n]))));
    }

    /** Listed and fully configured, in the listed order; the first is the default. @return Tracker[] */
    public function available(): array
    {
        return array_values(array_filter(array_map(fn ($n) => $this->get($n), $this->listed()), fn (Tracker $t) => $t->configured()));
    }

    public function find(?string $name): ?Tracker
    {
        foreach ($this->available() as $tracker) {
            if ($name === null || $tracker->name() === $name) {
                return $tracker;
            }
        }

        return null;
    }
}
