<?php

namespace Atoria\Alfred\Events;

use Atoria\Alfred\Explanations\Explanation;
use Illuminate\Foundation\Events\Dispatchable;

/** Someone asked a follow-up question under an explanation and got an answer. */
class FollowUpAnswered
{
    use Dispatchable;

    public function __construct(
        public readonly Explanation $explanation,
        public readonly string $question,
        public readonly string $answer,
        public readonly string $userKey,
    ) {}
}
