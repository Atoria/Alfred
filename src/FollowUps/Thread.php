<?php

namespace Atoria\Alfred\FollowUps;

/**
 * One person's follow-up questions about one explanation. Private to them,
 * kept as long as the page recording (it expires with it), never shared.
 */
class Thread
{
    /**
     * @param  array<int, array{question: string, answer: string, developer: ?string, confidence?: string, at?: string, kept?: bool}>  $turns
     */
    public function __construct(
        public string $id,
        public string $owner,
        public int $explanationId,
        public array $turns = [],
    ) {}

    public static function fromArray(array $d): self
    {
        return new self((string) $d['id'], (string) $d['owner'], (int) $d['explanation_id'], array_values((array) ($d['turns'] ?? [])));
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'owner' => $this->owner, 'explanation_id' => $this->explanationId, 'turns' => $this->turns];
    }

    /** For the overlay: the developer notes only for people who may see code. */
    public function present(bool $canViewCode): array
    {
        return [
            'thread' => $this->id,
            'turns' => array_map(fn (array $t, int $i) => [
                'index' => $i,
                'question' => $t['question'],
                'answer' => $t['answer'],
                'developer' => $canViewCode ? ($t['developer'] ?? null) : null,
                'confidence' => $t['confidence'] ?? 'medium',
                'kept' => (bool) ($t['kept'] ?? false),
            ], $this->turns, array_keys($this->turns)),
        ];
    }
}
