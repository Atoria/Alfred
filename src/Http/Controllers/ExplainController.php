<?php

namespace Atoria\Alfred\Http\Controllers;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Context\ContextStore;
use Atoria\Alfred\Context\ElementDescriptor;
use Atoria\Alfred\Context\PageContext;
use Atoria\Alfred\Events\ExplanationVerified;
use Atoria\Alfred\Events\FollowUpAnswered;
use Atoria\Alfred\Explanations\Drilldown;
use Atoria\Alfred\Explanations\Explainer;
use Atoria\Alfred\Explanations\Explanation;
use Atoria\Alfred\Explanations\ExplanationKey;
use Atoria\Alfred\Explanations\ExplanationPresenter;
use Atoria\Alfred\Explanations\ExplanationVersion;
use Atoria\Alfred\Explanations\TooManyGenerations;
use Atoria\Alfred\FollowUps\FollowUpLimit;
use Atoria\Alfred\FollowUps\FollowUps;
use Atoria\Alfred\FollowUps\ThreadStore;
use Atoria\Alfred\Llm\LlmException;
use Atoria\Alfred\Security\PathGuard;
use Atoria\Alfred\Security\Screening;
use Atoria\Alfred\Tasks\Background;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Throwable;

class ExplainController extends Controller
{
    public function __construct(
        protected Alfred $alfred,
        protected Background $background,
        protected ContextStore $store,
    ) {}

    public function explain(Request $request, Explainer $explainer, PathGuard $guard): JsonResponse
    {
        $this->authorizeUse($request);

        $data = $request->validate([
            'token' => ['required', 'string'],
            'element' => ['required', 'array'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $force = (bool) ($data['force'] ?? false);
        if ($force && ! $this->alfred->canVerify($this->alfred->user($request))) {
            abort(403, 'Only verifiers can regenerate explanations.');
        }

        $page = $this->page($request, $data['token']);
        if (! $page) {
            return $this->expired();
        }

        $element = ElementDescriptor::fromArray($data['element']);
        $user = $this->alfred->user($request);
        // People who may not create shared explanations (guests, or anyone the
        // contribute gate refuses) get a private one only they see.
        $owner = $this->alfred->canContribute($user) ? null : $this->alfred->userKey($request);

        // Already explained: answer straight away.
        if (! $force && ($hit = $explainer->cached($page, $element, $owner))) {
            return $this->presentResult($request, $hit);
        }

        // Generating takes many AI calls; run it after the response so proxies don't time out.
        return $this->background->run($request, function () use ($request, $explainer, $page, $element, $force, $owner): JsonResponse {
            try {
                $result = $explainer->explain($page, $element, $this->alfred->userKey($request), $force, $owner, $this->label($request));
            } catch (TooManyGenerations $e) {
                return response()->json(['message' => $e->getMessage()], 429)->header('Retry-After', (string) $e->retryAfter);
            } catch (LockTimeoutException) {
                return response()->json(['message' => Alfred::name().' is still working on this explanation for someone else. Try again in a moment.'], 503);
            } catch (LlmException $e) {
                report($e);

                return response()->json(['message' => $this->alfred->canVerify($this->alfred->user($request)) ? $e->getMessage() : Alfred::name().' could not reach the AI service. Please try again later.'], 502);
            }

            return $this->presentResult($request, $result);
        });
    }

    /** @param  array{explanation: Explanation, cached: bool, stale: bool}  $result */
    protected function presentResult(Request $request, array $result): JsonResponse
    {
        return response()->json(ExplanationPresenter::toArray(
            $result['explanation'],
            $result['cached'],
            $result['stale'],
            $this->alfred->canVerify($this->alfred->user($request)),
            $this->alfred->canViewRecords($this->alfred->user($request)),
            $this->alfred->canViewCode($this->alfred->user($request)),
        ));
    }

    /** A follow-up question about an explanation, answered in the background. */
    public function ask(Request $request, Explanation $explanation, FollowUps $followUps, ThreadStore $threads): JsonResponse
    {
        $this->authorizeUse($request);
        abort_unless((bool) config('alfred.followups.enabled', true), 404);

        $data = $request->validate([
            'token' => ['required', 'string'],
            'element' => ['required', 'array'],
            'question' => ['required', 'string', 'min:3', 'max:1000'],
            'thread' => ['nullable', 'string'],
        ]);

        $page = $this->page($request, $data['token']);
        if (! $page) {
            return $this->expired();
        }

        $owner = $this->alfred->userKey($request);
        $thread = null;
        if (! empty($data['thread'])) {
            $thread = $threads->find($data['thread'], $owner, (int) $explanation->id);
            if (! $thread) {
                return response()->json(['message' => 'This conversation has expired. Ask again to start a new one.'], 410);
            }
        }

        if ($refused = app(Screening::class)->refuse($request, $data['question'], 'follow-up')) {
            return $refused;
        }

        $element = ElementDescriptor::fromArray($data['element']);
        $question = trim($data['question']);

        // Follow-ups only about the explanation of this element on this page (and theirs to see).
        if (! ExplanationKey::accepts($explanation, $page, $element, $owner)) {
            abort(404);
        }

        return $this->background->run($request, function () use ($request, $followUps, $page, $element, $explanation, $thread, $question, $owner): JsonResponse {
            try {
                $thread = $followUps->ask($page, $element, $explanation, $thread, $question, $owner);
            } catch (TooManyGenerations $e) {
                return response()->json(['message' => $e->getMessage()], 429)->header('Retry-After', (string) $e->retryAfter);
            } catch (FollowUpLimit $e) {
                return response()->json(['message' => $e->getMessage()], 429);
            } catch (LlmException $e) {
                report($e);

                return response()->json(['message' => $this->alfred->canVerify($this->alfred->user($request)) ? $e->getMessage() : Alfred::name().' could not reach the AI service. Please try again later.'], 502);
            }

            $last = end($thread->turns);
            if (is_array($last)) {
                FollowUpAnswered::dispatch($explanation, (string) $last['question'], (string) $last['answer'], $owner);
            }

            return response()->json($thread->present($this->alfred->canViewCode($this->alfred->user($request))));
        });
    }

    /** A verifier keeps a good follow-up answer on the shared explanation, so the next person sees it without asking. */
    public function keep(Request $request, Explanation $explanation, ThreadStore $threads): JsonResponse
    {
        $this->authorizeExplanation($request, $explanation);
        $this->authorizeVerify($request);

        $data = $request->validate(['thread' => ['required', 'string'], 'turn' => ['required', 'integer', 'min:0']]);
        $owner = $this->alfred->userKey($request);
        $thread = $threads->find($data['thread'], $owner, (int) $explanation->id);
        $turn = $thread?->turns[(int) $data['turn']] ?? null;
        if (! $thread || ! $turn) {
            return response()->json(['message' => 'This conversation has expired.'], 410);
        }

        if (empty($turn['kept'])) {
            $caveats = array_values((array) $explanation->caveats);
            $caveats[] = rtrim($turn['question'], ' ?').'? '.$turn['answer'];
            $explanation->caveats = $caveats;
            $explanation->edited = true;
            $explanation->save();
            ExplanationVersion::record($explanation, 'kept', $this->label($request));

            $thread->turns[(int) $data['turn']]['kept'] = true;
            $threads->save($thread);
        }

        return $this->present($request, $explanation->fresh());
    }

    public function records(Request $request, Explanation $explanation, Drilldown $drilldown): JsonResponse
    {
        $this->authorizeExplanation($request, $explanation);
        $this->authorizeUse($request);
        abort_unless($this->alfred->canViewRecords($this->alfred->user($request)), 403);

        $page = $this->page($request, (string) $request->input('token'));
        if (! $page) {
            return $this->expired();
        }

        try {
            $result = $drilldown->run($explanation, $page);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not load the records behind this value.'], 500);
        }

        if (! $this->alfred->canVerify($this->alfred->user($request))) {
            unset($result['sql']);
        }

        return response()->json($result);
    }

    public function verify(Request $request, Explanation $explanation, PathGuard $guard): JsonResponse
    {
        $this->authorizeExplanation($request, $explanation);
        $this->authorizeVerify($request);

        $this->markVerified($request, $explanation, $guard);
        ExplanationVersion::record($explanation, 'verified', $this->label($request));
        ExplanationVerified::dispatch($explanation, $this->label($request));

        return $this->present($request, $explanation);
    }

    public function update(Request $request, Explanation $explanation, PathGuard $guard): JsonResponse
    {
        $this->authorizeExplanation($request, $explanation);
        $this->authorizeVerify($request);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'summary' => ['sometimes', 'string', 'max:5000'],
            'formula' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'steps' => ['sometimes', 'array', 'max:30'],
            'steps.*' => ['string', 'max:1000'],
            'data_sources' => ['sometimes', 'array', 'max:20'],
            'data_sources.*' => ['string', 'max:500'],
            'caveats' => ['sometimes', 'array', 'max:20'],
            'caveats.*' => ['string', 'max:1000'],
            'example' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'glossary' => ['sometimes', 'array', 'max:30'],
            'glossary.*' => ['string', 'max:500'],
        ]);

        $explanation->fill($data);
        $explanation->edited = true;
        // A verifier wrote it, so it is verified as of the current code.
        $this->markVerified($request, $explanation, $guard);
        ExplanationVersion::record($explanation, 'edited', $this->label($request));
        ExplanationVerified::dispatch($explanation, $this->label($request));

        return $this->present($request, $explanation);
    }

    /** Every saved state of an explanation, newest first (verifiers). */
    public function history(Request $request, Explanation $explanation): JsonResponse
    {
        $this->authorizeExplanation($request, $explanation);
        $this->authorizeVerify($request);

        return response()->json([
            'versions' => ExplanationVersion::query()->where('explanation_id', $explanation->id)->orderByDesc('version')->get()
                ->map(fn (ExplanationVersion $v) => [
                    'version' => $v->version,
                    'action' => $v->action,
                    'by' => $v->by,
                    'at' => $v->created_at?->toIso8601String(),
                    'title' => $v->snapshot['title'] ?? null,
                    'snapshot' => array_intersect_key($v->snapshot, array_flip([...ExplanationVersion::FIELDS, 'status', 'model'])),
                ])->all(),
        ]);
    }

    /** Put an earlier version back (it becomes a new version; nothing is lost). */
    public function restore(Request $request, Explanation $explanation, int $version, PathGuard $guard): JsonResponse
    {
        $this->authorizeExplanation($request, $explanation);
        $this->authorizeVerify($request);

        $old = ExplanationVersion::query()->where('explanation_id', $explanation->id)->where('version', $version)->firstOrFail();
        $explanation->fill(array_intersect_key($old->snapshot, array_flip(ExplanationVersion::FIELDS)));
        $explanation->edited = true;
        $this->markVerified($request, $explanation, $guard);
        ExplanationVersion::record($explanation, 'restored', $this->label($request).' (from version '.$version.')');

        return $this->present($request, $explanation);
    }

    protected function label(Request $request): ?string
    {
        $user = $this->alfred->user($request);

        return $user ? (string) ($user->email ?? $user->name ?? $user->getAuthIdentifier()) : 'A signed-out visitor';
    }

    protected function markVerified(Request $request, Explanation $explanation, PathGuard $guard): void
    {
        $user = $this->alfred->user($request);
        $explanation->status = Explanation::VERIFIED;
        $explanation->verified_by = $user ? (string) ($user->email ?? $user->getAuthIdentifier()) : null;
        $explanation->verified_at = now();
        $explanation->files = $explanation->currentFileHashes($guard);
        $explanation->save();
    }

    protected function present(Request $request, Explanation $explanation): JsonResponse
    {
        return response()->json(ExplanationPresenter::toArray(
            $explanation,
            true,
            false,
            $this->alfred->canVerify($this->alfred->user($request)),
            $this->alfred->canViewRecords($this->alfred->user($request)),
            $this->alfred->canViewCode($this->alfred->user($request)),
        ));
    }

    protected function page(Request $request, string $token): ?PageContext
    {
        $page = $this->store->get($token);

        return $page && $page->userId === $this->alfred->userKey($request) ? $page : null;
    }

    protected function expired(): JsonResponse
    {
        return response()->json(['message' => 'This page has expired for '.Alfred::name().'. Reload the page and try again.'], 410);
    }

    protected function authorizeUse(Request $request): void
    {
        abort_unless($this->alfred->canUse($this->alfred->user($request)), 403);
    }

    /** A private explanation is its owner's alone: nobody else, verifiers included, can read or change it. */
    protected function authorizeExplanation(Request $request, Explanation $explanation): void
    {
        abort_unless($explanation->owner === null || hash_equals((string) $explanation->owner, $this->alfred->userKey($request)), 404);
    }

    protected function authorizeVerify(Request $request): void
    {
        abort_unless($this->alfred->canVerify($this->alfred->user($request)), 403);
    }
}
