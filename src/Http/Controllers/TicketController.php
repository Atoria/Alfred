<?php

namespace Atoria\Alfred\Http\Controllers;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\PullRequests\PullRequestException;
use Atoria\Alfred\PullRequests\PullRequestOpener;
use Atoria\Alfred\Tickets\Screenshots;
use Atoria\Alfred\Tickets\TicketBuilder;
use Atoria\Alfred\Tickets\TrackerException;
use Atoria\Alfred\Tickets\Trackers\Tracker;
use Atoria\Alfred\Tickets\Trackers\TrackerManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/** Screenshots on a change, and turning a change into a ticket in Jira, GitHub, Trello, … */
class TicketController extends Controller
{
    public function __construct(
        protected Alfred $alfred,
        protected Screenshots $screenshots,
        protected TrackerManager $trackers,
    ) {}

    public function storeScreenshot(Request $request, Change $change): JsonResponse
    {
        $this->authorizeChange($request, $change);
        abort_unless($this->screenshots->enabled(), 404);

        $data = $request->validate([
            'kind' => ['required', Rule::in(Screenshots::KINDS)],
            'image' => ['required', 'string'],
        ]);

        try {
            $this->screenshots->store($change, $data['kind'], $data['image']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['screenshots' => $this->screenshots->kinds($change)]);
    }

    public function showScreenshot(Request $request, Change $change, string $kind): Response
    {
        $this->authorizeChange($request, $change);

        $shot = $this->screenshots->get($change, $kind);
        abort_if($shot === null, 404);
        [$contents, $mime, $filename] = $shot;

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function draft(Request $request, Change $change, TicketBuilder $builder): JsonResponse
    {
        $this->authorizeChange($request, $change);
        $includeDiff = (bool) config('alfred.tickets.include_diff', true);

        return response()->json($builder->draft($change) + [
            'details_markdown' => $builder->markdownDetails($change, $includeDiff),
            'screenshots' => $this->screenshots->kinds($change),
            'screenshots_enabled' => $this->screenshots->enabled(),
            'include_diff' => $includeDiff,
            'trackers' => array_map(fn (Tracker $t) => ['name' => $t->name(), 'label' => $t->label(), 'target' => $t->target()], $this->trackers->available()),
            'listed' => $this->trackers->listed(),
            'ticket' => $this->ticketInfo($change),
            // Offered as "also open a pull request" when this person may and the change has code.
            'pull_requests' => $this->alfred->canEdit($this->alfred->user($request)) && $this->opener()->eligible($change) ? $this->opener()->choices() : [],
            'pull_request' => $this->opener()->info($change),
        ]);
    }

    public function create(Request $request, Change $change, TicketBuilder $builder): JsonResponse
    {
        $this->authorizeChange($request, $change);

        $data = $request->validate([
            'tracker' => ['sometimes', 'nullable', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:250'],
            'description' => ['required', 'string', 'max:20000'],
            'include_diff' => ['sometimes', 'boolean'],
            'screenshots' => ['sometimes', 'array'],
            'screenshots.*' => [Rule::in(Screenshots::KINDS)],
            'pull_request' => ['sometimes', 'boolean'],
            'pull_request_provider' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        $user = $this->alfred->user($request);
        if ($user === null && ! config('alfred.guests.tickets', false)) {
            return response()->json(['message' => 'Sign in to send this to the tracker, or copy the ticket text instead.'], 403);
        }
        if ($limited = \Atoria\Alfred\Support\Limits::hit($request, 'ticket', 10, 3)) {
            return $limited;
        }

        $tracker = $this->trackers->find($data['tracker'] ?? null);
        if ($tracker === null) {
            return response()->json(['message' => isset($data['tracker'])
                ? "The tracker \"{$data['tracker']}\" is not set up here."
                : 'No ticket tracker is set up. Set ALFRED_TICKETS and its settings, or copy the ticket text instead.'], 422);
        }
        // One ticket per change, even when two people press "Create" at once:
        // claim it under a lock and re-check what's stored before creating.
        $lock = \Illuminate\Support\Facades\Cache::store()->getStore() instanceof \Illuminate\Contracts\Cache\LockProvider
            ? \Illuminate\Support\Facades\Cache::store()->getStore()->lock('alfred:ticket:'.$change->getKey(), 60)
            : null;
        if ($lock && ! $lock->get()) {
            return response()->json(['message' => 'A ticket for this is being created right now.'], 409);
        }

        try {
            $change->refresh();
            if ($change->ticket_key) {
                return response()->json(['message' => "A ticket already exists: {$change->ticket_key}."] + $this->ticketInfo($change), 409);
            }

            $kinds = array_values(array_intersect((array) ($data['screenshots'] ?? []), $this->screenshots->kinds($change)));
            $includeDiff = (bool) ($data['include_diff'] ?? config('alfred.tickets.include_diff', true));
            $ticket = $builder->ticket($change, $data['title'], $data['description'], $includeDiff, $kinds);

            try {
                $result = $tracker->create($ticket);
            } catch (TrackerException $e) {
                report($e);

                // The tracker's own error helps whoever set it up; others get a plain message.
                return response()->json(['message' => $this->alfred->canEdit($user) || $this->alfred->canVerify($user)
                    ? $e->getMessage()
                    : 'The ticket could not be created in the tracker. The details were logged for your team; you can copy the ticket text instead.'], 502);
            }

            $change->forceFill([
                'ticket_provider' => $tracker->name(),
                'ticket_key' => $result['key'],
                'ticket_url' => $result['url'],
                'ticketed_by' => $user ? (string) ($user->email ?? $user->getAuthIdentifier()) : null,
                'ticketed_at' => now(),
            ])->save();
            \Atoria\Alfred\Events\TicketCreated::dispatch($change, $change->ticketed_by);
        } finally {
            $lock?->release();
        }

        $warnings = array_values($result['warnings'] ?? []);

        // Ticket first, so the pull request can carry its key and link.
        $pr = null;
        if (! empty($data['pull_request']) && $this->alfred->canEdit($user) && $this->opener()->eligible($change)) {
            try {
                $pr = $this->opener()->open($change, $data['pull_request_provider'] ?? null, null, $change->ticketed_by);
                $warnings = array_merge($warnings, $pr['warnings']);
            } catch (PullRequestException $e) {
                report($e);
                $warnings[] = 'The ticket was created, but the pull request was not: '.$e->getMessage();
            }
        }

        return response()->json($this->ticketInfo($change) + ['warnings' => $warnings, 'pull_request' => $this->opener()->info($change->fresh())], 201);
    }

    /** @return array{provider: ?string, label: ?string, key: ?string, url: ?string} */
    protected function ticketInfo(Change $change): array
    {
        $label = null;
        if ($change->ticket_provider) {
            try {
                $label = $this->trackers->get($change->ticket_provider)->label();
            } catch (InvalidArgumentException) {
                $label = ucfirst($change->ticket_provider);
            }
        }

        return ['provider' => $change->ticket_provider, 'label' => $label, 'key' => $change->ticket_key, 'url' => $change->ticket_url];
    }

    protected function opener(): PullRequestOpener
    {
        return app(PullRequestOpener::class);
    }

    protected function authorizeChange(Request $request, Change $change): void
    {
        // Editors: any change. People who can only report: their own requests.
        abort_unless($this->alfred->canSeeChange($request, $change), 403);
    }
}
