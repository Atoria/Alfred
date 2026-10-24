<?php

namespace Atoria\Alfred\Http\Controllers;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Changes\ChangeConflict;
use Atoria\Alfred\Changes\ChangeManager;
use Atoria\Alfred\Changes\ChangePresenter;
use Atoria\Alfred\Context\CodeLocator;
use Atoria\Alfred\Context\ContextStore;
use Atoria\Alfred\Context\ElementDescriptor;
use Atoria\Alfred\Explanations\TooManyGenerations;
use Atoria\Alfred\FollowUps\Known;
use Atoria\Alfred\Llm\LlmException;
use Atoria\Alfred\Preview\Preview;
use Atoria\Alfred\Security\AccessDenied;
use Atoria\Alfred\Security\Screening;
use Atoria\Alfred\Support\Limits;
use Atoria\Alfred\Tasks\Background;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ChangeController extends Controller
{
    public function __construct(
        protected Alfred $alfred,
        protected Background $background,
        protected ContextStore $store,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeEdit($request);

        $changes = Change::query()->latest('id')->limit(25)->get();

        return response()->json([
            'mode' => $this->alfred->editMode(),
            'changes' => $changes->map(fn (Change $c) => ChangePresenter::toArray($c, $this->alfred->editMode(), $this->codeVisible($request)))->all(),
        ]);
    }

    public function store(Request $request, ChangeManager $changes): JsonResponse
    {
        $this->authorizeEdit($request);

        $data = $request->validate([
            'token' => ['required', 'string'],
            'element' => ['required', 'array'],
            'instruction' => ['required', 'string', 'min:3', 'max:2000'],
            'explanation' => ['nullable', 'integer'],
            'thread' => ['nullable', 'string'],
        ]);

        $page = $this->store->get($data['token']);
        if (! $page || $page->userId !== $this->alfred->userKey($request)) {
            return response()->json(['message' => 'This page has expired for '.Alfred::name().'. Reload the page and try again.'], 410);
        }

        if ($refused = app(Screening::class)->refuse($request, $data['instruction'], 'change')) {
            return $refused;
        }

        $element = ElementDescriptor::fromArray($data['element']);
        $known = Known::from($data['explanation'] ?? null, $data['thread'] ?? null, $this->alfred->userKey($request), $page, $element);

        // A change takes many AI calls; run it after the response so proxies don't time out.
        return $this->background->run($request, function () use ($request, $changes, $page, $element, $data, $known): JsonResponse {
            try {
                $change = $changes->propose(
                    $page,
                    $element,
                    $data['instruction'],
                    $this->alfred->userKey($request),
                    $this->userLabel($request),
                    $known,
                );
            } catch (TooManyGenerations $e) {
                return response()->json(['message' => $e->getMessage()], 429)->header('Retry-After', (string) $e->retryAfter);
            } catch (LlmException $e) {
                report($e);

                return response()->json(['message' => $e->getMessage()], 502);
            }

            if ($change->status === Change::FAILED) {
                report(new LlmException("Alfred change #{$change->id} failed: {$change->error}"));
            }

            return response()->json(ChangePresenter::toArray($change, $this->alfred->editMode(), $this->codeVisible($request)), 201);
        });
    }

    /** "That didn't work": try the same request again with feedback (and any preview error). */
    public function refine(Request $request, Change $change, ChangeManager $changes): JsonResponse
    {
        $this->authorizeEdit($request);
        abort_unless($this->alfred->canSeeChange($request, $change), 404);

        $data = $request->validate([
            'token' => ['required', 'string'],
            'feedback' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        if ($change->status === Change::REQUESTED || $change->status === Change::DISCARDED) {
            return response()->json(['message' => "A {$change->status} change can't be refined. Ask for a new change instead."], 409);
        }

        if ($refused = app(Screening::class)->refuse($request, $data['feedback'], 'refine')) {
            return $refused;
        }

        $page = $this->store->get($data['token']);
        if (! $page || $page->userId !== $this->alfred->userKey($request)) {
            return response()->json(['message' => 'This page has expired for '.Alfred::name().'. Reload the page and try again.'], 410);
        }

        return $this->background->run($request, function () use ($request, $changes, $change, $page, $data): JsonResponse {
            try {
                $new = $changes->refine($change, $page, $data['feedback'], $this->alfred->userKey($request), $this->userLabel($request));
            } catch (TooManyGenerations $e) {
                return response()->json(['message' => $e->getMessage()], 429)->header('Retry-After', (string) $e->retryAfter);
            } catch (LlmException $e) {
                report($e);

                return response()->json(['message' => $e->getMessage()], 502);
            }

            return response()->json(ChangePresenter::toArray($new, $this->alfred->editMode(), $this->codeVisible($request)), 201);
        });
    }

    /** "Report / request": a note about an element, ready to become a ticket. No AI. */
    public function report(Request $request, CodeLocator $locator): JsonResponse
    {
        abort_unless($this->alfred->canUse($this->alfred->user($request)) && $this->alfred->canReport($this->alfred->user($request)), 403);

        $data = $request->validate([
            'token' => ['required', 'string'],
            'element' => ['required', 'array'],
            'text' => ['required', 'string', 'min:3', 'max:5000'],
            'explanation' => ['nullable', 'integer'],
            'thread' => ['nullable', 'string'],
        ]);

        $page = $this->store->get($data['token']);
        if (! $page || $page->userId !== $this->alfred->userKey($request)) {
            return response()->json(['message' => 'This page has expired for '.Alfred::name().'. Reload the page and try again.'], 410);
        }

        if ($limited = Limits::hit($request, 'report', 20, 5)) {
            return $limited;
        }

        $element = ElementDescriptor::fromArray($data['element']);
        $text = trim($data['text']);

        $change = Change::query()->create([
            'user_key' => $this->alfred->userKey($request),
            'requested_by' => $this->userLabel($request),
            'environment' => (string) app()->environment(),
            'route' => mb_substr($page->routeKey(), 0, 255),
            'url' => $page->url,
            'element' => [
                'kind' => $element->kind,
                'tag' => $element->tag,
                'labels' => $element->labels,
                'column' => $element->column,
                'components' => $element->components,
                'selector' => $element->selector,
            ],
            'location' => $locator->locate($page, $element) + array_filter(['explanation' => Known::forTicket(
                Known::from($data['explanation'] ?? null, $data['thread'] ?? null, $this->alfred->userKey($request), $page, $element)
            )]),
            'instruction' => $text,
            'summary' => Str::limit(Str::before($text, "\n"), 120, '…'),
            'edits' => [],
            'files' => [],
            'status' => Change::REQUESTED,
        ]);

        return response()->json(ChangePresenter::toArray($change, $this->alfred->editMode(), $this->codeVisible($request)), 201);
    }

    public function show(Request $request, Change $change): JsonResponse
    {
        abort_unless($this->alfred->canSeeChange($request, $change), 403);

        return response()->json(ChangePresenter::toArray($change, $this->alfred->editMode(), $this->codeVisible($request)));
    }

    public function apply(Request $request, Change $change, ChangeManager $changes): JsonResponse
    {
        $this->authorizeEdit($request);

        return $this->attempt($request, fn () => $changes->apply($change, $this->userLabel($request)));
    }

    public function revert(Request $request, Change $change, ChangeManager $changes): JsonResponse
    {
        $this->authorizeEdit($request);

        return $this->attempt($request, fn () => $changes->revert($change, $this->userLabel($request)));
    }

    public function discard(Request $request, Change $change, ChangeManager $changes): JsonResponse
    {
        $this->authorizeEdit($request);

        return $this->attempt($request, fn () => $changes->discard($change));
    }

    /** A signed token the browser stores as a cookie to see this change rendered, just for this user. */
    public function preview(Request $request, Change $change, Preview $preview): JsonResponse
    {
        $this->authorizeEdit($request);

        if (! $preview->enabled()) {
            return response()->json(['message' => Preview::longRunning()
                ? 'Preview is not available on long-running servers (Octane).'
                : 'Preview is turned off (ALFRED_EDIT_PREVIEW).'], 409);
        }
        if (! $change->hasEdits() || ! in_array($change->status, [Change::PROPOSED, Change::REVERTED], true)) {
            return response()->json(['message' => "A {$change->status} change can't be previewed."], 409);
        }

        if (! $request->hasSession()) {
            return response()->json(['message' => 'Preview needs a signed-in session.'], 409);
        }

        // Set by the server (HttpOnly): page scripts can neither read nor set it.
        return response()->json(['previewing' => $change->id, 'minutes' => (int) config('alfred.edit.preview_minutes', 120)])
            ->withCookie($preview->cookie($request, $preview->token($change, $this->alfred->userKey($request), $request->session()->getId())));
    }

    /** Clears the preview cookie and goes back. Harmless for anyone, so no permission check. */
    public function stopPreview(Request $request): RedirectResponse
    {
        $to = $request->query('to');
        if (! is_string($to) || ! str_starts_with($to, '/') || str_starts_with($to, '//') || str_contains($to, '\\')) {
            $to = '/';
        }

        return redirect()->to($to)->withCookie(app(Preview::class)->forgetCookie($request));
    }

    public function patch(Request $request, Change $change): Response
    {
        $this->authorizeEdit($request);
        abort_unless($this->codeVisible($request), 403);
        abort_if(! $change->diff, 404);

        return response((string) $change->diff, 200, [
            'Content-Type' => 'text/x-diff; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="alfred-change-'.$change->id.'.patch"',
        ]);
    }

    protected function attempt(Request $request, \Closure $action): JsonResponse
    {
        try {
            $change = $action();
        } catch (ChangeConflict|AccessDenied $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'Busy, try again.'], 503);
        } catch (Throwable $e) {
            report($e); // details go to the log, not the browser

            return response()->json(['message' => Alfred::name().' ran into a problem doing that. The details were logged for your team.'], 500);
        }

        return response()->json(ChangePresenter::toArray($change, $this->alfred->editMode(), $this->codeVisible($request)));
    }

    protected function codeVisible(Request $request): bool
    {
        return $this->alfred->canViewCode($this->alfred->user($request));
    }

    protected function userLabel(Request $request): ?string
    {
        $user = $this->alfred->user($request);

        return $user ? (string) ($user->email ?? $user->name ?? $user->getAuthIdentifier()) : 'A signed-out visitor';
    }

    protected function authorizeEdit(Request $request): void
    {
        abort_unless($this->alfred->canUse($this->alfred->user($request)) && $this->alfred->canEdit($this->alfred->user($request)), 403);
    }
}
