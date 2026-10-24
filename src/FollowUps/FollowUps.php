<?php

namespace Atoria\Alfred\FollowUps;

use Atoria\Alfred\Agent\Agent;
use Atoria\Alfred\Agent\AgentState;
use Atoria\Alfred\Agent\PromptBuilder;
use Atoria\Alfred\Agent\Tools\SubmitAnswer;
use Atoria\Alfred\Context\ElementDescriptor;
use Atoria\Alfred\Context\PageContext;
use Atoria\Alfred\Explanations\Explainer;
use Atoria\Alfred\Explanations\Explanation;
use Atoria\Alfred\Privacy\Redactor;
use Atoria\Alfred\Security\PathGuard;

/**
 * Follow-up questions about an explanation. Each one is a short agent run
 * that starts from what the explanation already found (and earlier
 * follow-ups), so it's quicker and cheaper than explaining from scratch.
 */
class FollowUps
{
    public function __construct(
        protected Agent $agent,
        protected PromptBuilder $prompts,
        protected PathGuard $guard,
        protected Redactor $redactor,
        protected Explainer $explainer,
        protected ThreadStore $threads,
    ) {}

    public function ask(PageContext $page, ElementDescriptor $element, Explanation $explanation, ?Thread $thread, string $question, string $userKey): Thread
    {
        $this->explainer->throttle($userKey);

        $thread ??= $this->threads->start($userKey, (int) $explanation->id);
        $maxTurns = max(1, (int) config('alfred.followups.max_turns', 10));
        if (count($thread->turns) >= $maxTurns) {
            throw new FollowUpLimit("That's {$maxTurns} follow-ups on this explanation. Reload the page to start a new conversation.");
        }

        $state = new AgentState($this->guard, $page, $this->redactor);
        $result = $this->agent->loop(
            $state,
            $this->prompts->followUpInstructions(),
            $this->prompts->followUpMessage($page, $element, self::parts($explanation), $thread->turns, $question, $this->redactor, $this->guard),
            [...$this->agent->readTools(), new SubmitAnswer],
            SubmitAnswer::NAME,
            (int) config('alfred.followups.max_steps', 8),
        );

        $thread->turns[] = [
            'question' => $question,
            'answer' => $result->explanation['answer'],
            'developer' => $result->explanation['developer'],
            'confidence' => $result->explanation['confidence'],
            'at' => now()->toIso8601String(),
        ];
        $this->threads->save($thread);

        return $thread;
    }

    /** @return array<string, mixed> */
    public static function parts(Explanation $e): array
    {
        return [
            'title' => $e->title,
            'summary' => $e->summary,
            'formula' => $e->formula,
            'example' => $e->example,
            'steps' => (array) $e->steps,
            'data_sources' => (array) $e->data_sources,
            'caveats' => (array) $e->caveats,
            'glossary' => (array) $e->glossary,
            'developer' => (array) ($e->developer ?? []),
        ];
    }
}
