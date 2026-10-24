<?php

namespace Atoria\Alfred\Agent\Tools;

use Atoria\Alfred\Agent\AgentState;

/** Terminal tool for a follow-up question: a plain answer, plus an optional note for developers. */
class SubmitAnswer implements Tool
{
    public const NAME = 'submit_answer';

    protected int $rejections = 0;

    public function __construct(protected int $maxRejections = 2) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Submit the answer to the follow-up question. Call once you can answer it (or have concluded the code does not tell).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string', 'description' => 'The answer for the person who asked, in plain business language, 1–5 sentences. No class, method, file, table or column names and no SQL.'],
                'developer' => ['type' => ['string', 'null'], 'description' => 'Optional note for engineers: where in the code this is decided (class::method, file:line, query details). Null if nothing to add.'],
                'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            ],
            'required' => ['answer', 'developer', 'confidence'],
            'additionalProperties' => false,
        ];
    }

    public function strict(): bool
    {
        return true;
    }

    public function handle(array $arguments, AgentState $state): mixed
    {
        $answer = trim((string) ($arguments['answer'] ?? ''));
        if ($answer === '') {
            return 'Not accepted: the answer is empty. Call submit_answer again with an answer.';
        }

        $jargon = SubmitExplanation::jargon(['answer' => $answer]);
        if ($jargon !== [] && $this->rejections < $this->maxRejections) {
            $this->rejections++;

            return 'Not accepted: "answer" must be readable by someone who has never seen the code, but it contains: '
                .implode(', ', array_map(fn ($j) => '"'.$j.'"', array_slice($jargon, 0, 12)))
                .'. Say it in plain business words and move code details into "developer". Call submit_answer again.';
        }

        $developer = isset($arguments['developer']) && trim((string) $arguments['developer']) !== '' ? trim((string) $arguments['developer']) : null;

        $state->submission = \Atoria\Alfred\Security\SecretScrubber::scrubAll([
            'answer' => mb_substr($answer, 0, 4000),
            'developer' => $developer !== null ? mb_substr($developer, 0, 4000) : null,
            'confidence' => in_array($arguments['confidence'] ?? null, ['high', 'medium', 'low'], true) ? $arguments['confidence'] : 'medium',
        ]);

        return 'Submitted.';
    }
}
