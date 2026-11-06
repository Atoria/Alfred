<?php

namespace Atoria\Alfred\Tickets;

use Atoria\Alfred\Tenancy\Tenancy;
use Atoria\Alfred\Tickets\Trackers\Plannable;
use Atoria\Alfred\Tickets\Trackers\Tracker;
use Illuminate\Support\Facades\Cache;

/**
 * "Assign to" and "Sprint / list / milestone" choices for the ticket form,
 * looked up from the tracker and cached for a few minutes (per tenant), and
 * the check that a picked id really is one the tracker offered.
 */
class Planning
{
    public function __construct(protected Tenancy $tenancy) {}

    public function enabled(): bool
    {
        return (bool) config('alfred.tickets.planning', true);
    }

    public function supports(Tracker $tracker): bool
    {
        return $this->enabled() && $tracker instanceof Plannable;
    }

    /**
     * @return array{assignees: array, destinations: array, destination_label: ?string, supported: bool}
     *
     * @throws TrackerException
     */
    public function for(Tracker $tracker, bool $fresh = false): array
    {
        if (! $this->supports($tracker)) {
            return ['supported' => false, 'assignees' => [], 'destinations' => [], 'destination_label' => null];
        }
        /** @var Tracker&Plannable $tracker */
        $key = 'alfred:planning:'.sha1((string) $this->tenancy->current().'|'.$tracker->name().'|'.(string) $tracker->target());
        if ($fresh) {
            Cache::forget($key);
        }

        $options = Cache::remember($key, max(0, (int) config('alfred.tickets.planning_cache_seconds', 300)), fn () => $this->clean($tracker->planning()));

        return ['supported' => true, 'destination_label' => $tracker->destinationLabel()] + $options;
    }

    /**
     * Is $assignee / $destination one of the offered choices? Null and "" always are.
     *
     * @throws TrackerException
     */
    public function allows(Tracker $tracker, ?string $assignee, ?string $destination): bool
    {
        if (($assignee ?? '') === '' && ($destination ?? '') === '') {
            return true;
        }
        if (! $this->supports($tracker)) {
            return false;
        }

        $options = $this->for($tracker);
        $ok = fn (?string $id, array $list) => ($id ?? '') === '' || in_array($id, array_column($list, 'id'), true);

        return $ok($assignee, $options['assignees']) && $ok($destination, $options['destinations']);
    }

    protected function clean(array $options): array
    {
        $list = fn (array $items) => array_values(array_filter(array_map(fn ($i) => array_filter([
            'id' => (string) ($i['id'] ?? ''),
            'name' => mb_substr(trim((string) ($i['name'] ?? '')), 0, 120),
            'hint' => isset($i['hint']) && $i['hint'] !== '' ? mb_substr((string) $i['hint'], 0, 60) : null,
            'default' => ! empty($i['default']) ?: null,
        ], fn ($v) => $v !== null), (array) $items), fn ($i) => $i['name'] !== ''));

        $assignees = $list($options['assignees'] ?? []);
        usort($assignees, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return ['assignees' => array_slice($assignees, 0, 500), 'destinations' => array_slice($list($options['destinations'] ?? []), 0, 200)];
    }
}
