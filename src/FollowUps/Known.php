<?php

namespace Atoria\Alfred\FollowUps;

use Atoria\Alfred\Explanations\Explanation;

/**
 * What Alfred already found about an element (its explanation and the
 * person's follow-ups), carried into "Change this" and "Report or request"
 * started from an explanation. The change agent starts from it; tickets
 * show it.
 */
class Known
{
    /**
     * @return array{id: int, title: string, summary: string, developer: array<string, mixed>, followups: array<int, array{question: string, answer: string, developer: ?string}>}|null
     */
    public static function from(mixed $explanationId, mixed $threadId, string $owner, \Atoria\Alfred\Context\PageContext $page, \Atoria\Alfred\Context\ElementDescriptor $element): ?array
    {
        if (! is_numeric($explanationId)) {
            return null;
        }
        // Tenant-scoped (global scope), and only the explanation of this very
        // element on this page (shared, or the person's own private one): an id
        // can't be used to read any other.
        $explanation = Explanation::query()->find((int) $explanationId);
        if (! $explanation || ! \Atoria\Alfred\Explanations\ExplanationKey::accepts($explanation, $page, $element, $owner)) {
            return null;
        }

        $thread = app(ThreadStore::class)->find(is_string($threadId) ? $threadId : null, $owner, (int) $explanation->id);

        return [
            'id' => (int) $explanation->id,
            'title' => (string) $explanation->title,
            'summary' => (string) $explanation->summary,
            'developer' => (array) ($explanation->developer ?? []),
            'followups' => array_map(fn (array $t) => [
                'question' => $t['question'],
                'answer' => $t['answer'],
                'developer' => $t['developer'] ?? null,
            ], $thread !== null ? $thread->turns : []),
        ];
    }

    /** Stored on the change (location.explanation) for tickets: the business words plus the traced code path. */
    public static function forTicket(?array $known): ?array
    {
        if (! $known) {
            return null;
        }

        return [
            'id' => $known['id'],
            'title' => $known['title'],
            'summary' => $known['summary'],
            'flow' => array_values(array_slice(array_filter((array) ($known['developer']['flow'] ?? []), 'is_string'), 0, 6)),
            'followups' => array_map(fn ($t) => ['question' => $t['question'], 'answer' => $t['answer']], $known['followups']),
        ];
    }
}
